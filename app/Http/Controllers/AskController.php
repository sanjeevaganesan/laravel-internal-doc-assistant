<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Enums\Lab;

use function Laravel\Ai\agent;

/**
 * AskController — Core RAG Query Endpoints
 *
 * Implements the retrieval-augmented generation (RAG) pipeline for
 * answering natural-language questions about internal documentation.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RAG RETRIEVAL + GENERATION FLOW                                    │
 * │                                                                     │
 * │  User question (text)                                               │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  [EMBED] whereVectorSimilarTo() auto-embeds the question string    │
 * │          using OpenAI text-embedding-3-small (cached 30 days)      │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  [RETRIEVE] pgvector cosine similarity search                      │
 * │             minSimilarity: 0.4 → up to 15 candidate chunks        │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  [RERANK] Collection::rerank() with Cohere                         │
 * │           Cross-encoder model re-scores all 15 candidates          │
 * │           Returns top 5 ordered by relevance                       │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  [BUILD CONTEXT] Concatenate top-5 chunk texts                     │
 * │                  Prepend "[Source: title]" for citation            │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  [GENERATE] agent() helper with strict instructions                │
 * │             Provider: Anthropic → OpenAI failover                  │
 * │             Rules: answer from context only, cite sources,         │
 * │                    say "I don't know" if insufficient context      │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  { answer: "...", sources: ["runbook", "hr-policies"] }           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Two variations are exposed:
 *   POST /api/ask         — Synchronous JSON response (for REST clients)
 *   GET  /api/ask/stream  — SSE streaming response (for frontend streaming)
 *
 * Why vector search + reranking (not just reranking)?
 *   Reranking with a cross-encoder is expensive — you can't run Cohere's
 *   reranker over your entire document corpus. The two-stage approach:
 *     1. Fast ANN (approximate nearest neighbor) recall: retrieve 15 candidates
 *        in milliseconds using pgvector HNSW
 *     2. Precise reranking: Cohere scores all 15 and returns the top 5
 *   This gives you both speed (ANN) and precision (cross-encoder).
 */
class AskController extends Controller
{
    /**
     * POST /api/ask
     *
     * Runs the full RAG pipeline and returns a JSON response.
     * Best for REST API clients, mobile apps, or batch queries.
     *
     * Request body:
     *   { "question": "What is the PTO policy?" }
     *
     * Response:
     *   {
     *     "answer": "According to [hr-policies], employees receive ...",
     *     "sources": ["hr-policies"]
     *   }
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $question = $request->string('question');

        // ── Step 1 + 2: Embed the question and run vector similarity search ──
        //
        // whereVectorSimilarTo() detects that $question is a string and
        // auto-generates its embedding using the default embeddings provider
        // (OpenAI text-embedding-3-small, cached for 30 days).
        //
        // minSimilarity: 0.4 is deliberately permissive at this recall stage.
        // We cast a wide net here and let the reranker do the precision work.
        // If you tighten this to 0.7, you risk missing relevant but paraphrase-
        // worded chunks.
        //
        // We only select the columns we actually use — skipping 'embedding'
        // avoids deserializing 1536 floats per row when we don't need them.
        $candidates = Document::query()
            ->whereVectorSimilarTo('embedding', (string) $question, minSimilarity: 0.4)
            ->limit(15)
            ->get(['id', 'title', 'content', 'source']);

        // ── Step 3: Rerank 15 candidates → top 5 ──
        //
        // Collection::rerank() is a macro registered by AiServiceProvider.
        // It calls Cohere's Rerank API with all 15 candidate texts and returns
        // the same Collection, re-ordered by relevance score, truncated to limit.
        //
        // Why Cohere's reranker beats cosine similarity for precision:
        //   Cosine similarity compares the QUESTION embedding vs each CHUNK
        //   embedding independently. Cohere's cross-encoder sees the question
        //   AND the document together, modelling their interaction — much closer
        //   to how a human would judge relevance.
        //
        // Arguments:
        //   'content'     → the model property to use as the document text
        //   $question     → the query to rank against
        //   limit: 5      → return only the top 5
        //   provider:     → use Cohere's reranker (Lab::Cohere maps to 'cohere' driver)
        $topDocuments = $candidates->rerank(
            by: 'content',
            query: (string) $question,
            limit: 5,
            provider: Lab::Cohere,
        );

        // ── Step 4: Build context string with source attribution ──
        //
        // Format: "[Source: engineering-runbook]\n<chunk text>\n\n---\n\n"
        // The "[Source: ...]" prefix is what the agent uses for citations.
        // The agent's instructions tell it to reference sources by this label.
        $context = $topDocuments
            ->map(fn ($doc) => "[Source: {$doc->title}]\n{$doc->content}")
            ->implode("\n\n---\n\n");

        // ── Step 5: Generate the answer using the anonymous agent() helper ──
        //
        // agent() creates a one-shot, stateless agent with no conversation
        // memory. It's the right tool for this use case: each /ask request is
        // independent (no chat history needed).
        //
        // Contrast with named agent classes (created via php artisan make:agent):
        //   - Named agents are better for stateful conversations with memory,
        //     tool use, structured output, or complex multi-step reasoning.
        //   - Anonymous agent() is perfect for single-turn generation like this.
        //
        // 'use function Laravel\Ai\agent;' at the top of this file is required.
        // PHP will throw "Call to undefined function agent()" without it.
        //
        // provider: [Lab::Anthropic, Lab::OpenAI] enables automatic failover:
        //   - The SDK first calls Anthropic.
        //   - If Anthropic returns RateLimitedException, ProviderOverloadedException,
        //     or InsufficientCreditsException, it automatically retries with OpenAI.
        //   - This makes the endpoint resilient to provider outages.
        $response = agent(instructions: $this->buildInstructions($context))
            ->prompt(
                prompt: (string) $question,
                provider: [Lab::Anthropic, Lab::OpenAI],
            );

        return response()->json([
            'answer' => (string) $response,
            'sources' => $topDocuments->pluck('title')->unique()->values(),
        ]);
    }

    /**
     * GET /api/ask/stream
     *
     * Same RAG pipeline as ask(), but streams the generation output as
     * Server-Sent Events (SSE) using the Vercel AI SDK data protocol.
     *
     * Request query param:
     *   ?question=What+is+the+PTO+policy
     *
     * Response: SSE stream compatible with:
     *   - Browser EventSource API (native JS, no library needed)
     *   - Vercel AI SDK's useChat() hook (React/Next.js frontend)
     *
     * The ->usingVercelDataProtocol() call formats each SSE event in the
     * Vercel AI SDK's wire format: data: "0:<token>\n\n"
     * This lets the Vercel AI SDK's useChat() hook parse the stream
     * without any extra configuration on the frontend.
     *
     * The StreamableAgentResponse implements Responsable, so Laravel's
     * routing layer automatically calls toResponse($request) on it.
     */
    public function stream(Request $request): mixed
    {
        $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $question = $request->string('question');

        // Same retrieval pipeline as ask() — see comments there for details.
        $candidates = Document::query()
            ->whereVectorSimilarTo('embedding', (string) $question, minSimilarity: 0.4)
            ->limit(15)
            ->get(['id', 'title', 'content', 'source']);

        $topDocuments = $candidates->rerank(
            by: 'content',
            query: (string) $question,
            limit: 5,
            provider: Lab::Cohere,
        );

        $context = $topDocuments
            ->map(fn ($doc) => "[Source: {$doc->title}]\n{$doc->content}")
            ->implode("\n\n---\n\n");

        // stream() starts the response immediately while the LLM generates.
        // Each token is pushed to the client as an SSE event as it arrives,
        // creating the "typing" effect seen in ChatGPT-style interfaces.
        //
        // usingVercelDataProtocol() wraps each chunk in Vercel's format:
        //   data: "0:<escaped-token>\n\n"
        // Compatible with useChat() from the @ai-sdk/react package.
        return agent(instructions: $this->buildInstructions($context))
            ->stream(
                prompt: (string) $question,
                provider: [Lab::Anthropic, Lab::OpenAI],
            )
            ->usingVercelDataProtocol();
    }

    /**
     * Build the system instructions for the agent.
     *
     * These instructions implement the RAG constraint: the agent must ONLY
     * use the provided context, never its pre-training knowledge.
     *
     * Why strict grounding matters for internal documentation:
     *   An LLM trained on public internet data might "hallucinate" a plausible-
     *   sounding PTO policy, deployment procedure, or security guideline that
     *   sounds right but contradicts your company's actual policy. Strict
     *   grounding ensures answers are traceable to specific documents.
     */
    private function buildInstructions(string $context): string
    {
        return <<<INSTRUCTIONS
        You are an internal documentation assistant for a software company.
        Your sole job is to answer questions using the documentation context below.

        STRICT RULES:
        1. Answer ONLY using information found in the context below.
           Do not use any knowledge from your training data.
        2. For every fact you state, cite the source using this format:
           "According to [Source Name], ..."
           or inline as "(Source: [Source Name])"
        3. If the context does not contain enough information to answer the question,
           respond with exactly: "I don't know based on the available documentation."
        4. Do not speculate, extrapolate, or say "typically" or "usually".
           Only state what is explicitly in the context.
        5. Be concise. Do not pad your answer.

        CONTEXT:
        {$context}
        INSTRUCTIONS;
    }
}
