<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;

/**
 * AI Observability Listener
 *
 * Logs structured data for every laravel/ai SDK lifecycle event to the
 * dedicated 'ai' log channel (storage/logs/ai-YYYY-MM-DD.log).
 *
 * Why observe AI operations?
 *
 *   1. COST TRACKING: Each embedding, reranking, and generation call has a price.
 *      Logging token counts lets you calculate daily/monthly API costs and identify
 *      expensive queries worth caching.
 *
 *   2. QUALITY DEBUGGING: If the agent gives a bad answer, the logs show exactly
 *      which chunks were retrieved and how they were reranked — letting you tune
 *      minSimilarity, chunk size, or reranking limit.
 *
 *   3. LATENCY PROFILING: By correlating invocation_id timestamps across the
 *      before/after event pairs, you can measure time spent in each pipeline
 *      stage: embedding → retrieval → reranking → generation.
 *
 *   4. AUDIT TRAIL: For regulated industries, logs provide a trace of what
 *      information was retrieved and used to generate each answer.
 *
 * Event pairs (before → after):
 *   GeneratingEmbeddings  → EmbeddingsGenerated  (embedding stage)
 *   Reranking             → Reranked             (reranking stage)
 *   PromptingAgent        → AgentPrompted        (generation stage)
 *
 * The 'invocation_id' field ties before/after events for a single API call
 * together — useful for correlating logs across the pipeline.
 *
 * Registration: see AppServiceProvider::boot() which wires these listeners
 * using Event::listen() without requiring a full EventServiceProvider.
 */
class AiObservabilityListener
{
    /**
     * Log channel name — must match a key in config/logging.php.
     * Writing to a separate 'ai' channel keeps AI logs out of the main
     * application log and makes them easy to ship to a separate sink.
     */
    private string $channel = 'ai';

    // ──────────────────────────────────────────────────────────────────────
    // Embedding Events
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fires BEFORE the embedding API call is made.
     * Logs the number of inputs being embedded — useful for detecting
     * batch calls vs single-chunk calls.
     */
    public function onGeneratingEmbeddings(GeneratingEmbeddings $event): void
    {
        Log::channel($this->channel)->debug('AI: generating embeddings', [
            'invocation_id' => $event->invocationId,
            'input_count' => count($event->prompt->inputs),
        ]);
    }

    /**
     * Fires AFTER the embedding API call completes.
     * The response may include usage data (prompt tokens consumed).
     * Costing: text-embedding-3-small costs $0.02 / 1M tokens.
     */
    public function onEmbeddingsGenerated(EmbeddingsGenerated $event): void
    {
        Log::channel($this->channel)->info('AI: embeddings generated', [
            'invocation_id' => $event->invocationId,
            'embedding_count' => count($event->response->embeddings),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Reranking Events
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fires BEFORE the reranking API call is made.
     * Logs how many candidate documents are being sent to Cohere.
     * We expect document_count ≤ 15 (the limit in AskController).
     */
    public function onReranking(Reranking $event): void
    {
        Log::channel($this->channel)->debug('AI: reranking documents', [
            'invocation_id' => $event->invocationId,
            'document_count' => count($event->prompt->documents),
            'query_preview' => substr($event->prompt->query, 0, 80),
        ]);
    }

    /**
     * Fires AFTER the reranking API call completes.
     * result_count should equal the 'limit' parameter (5 in our case).
     * The top result's score indicates how well the best chunk matched.
     */
    public function onReranked(Reranked $event): void
    {
        $results = $event->response->results;
        $topScore = ! empty($results) ? round($results[0]->score, 4) : null;

        Log::channel($this->channel)->info('AI: reranking complete', [
            'invocation_id' => $event->invocationId,
            'result_count' => count($results),
            'top_score' => $topScore,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Agent Generation Events
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fires BEFORE the agent sends its prompt to the LLM provider.
     * At this point the context has been injected into the instructions.
     */
    public function onPromptingAgent(PromptingAgent $event): void
    {
        Log::channel($this->channel)->debug('AI: prompting agent', [
            'invocation_id' => $event->invocationId,
        ]);
    }

    /**
     * Fires AFTER the agent receives a response from the LLM provider.
     *
     * Token usage for cost estimation:
     *   Anthropic claude-sonnet-4-6: $3/M input tokens, $15/M output tokens
     *   OpenAI gpt-4o: $2.50/M input tokens, $10/M output tokens
     *
     * A typical RAG query uses ~2000–4000 prompt tokens (context + instructions)
     * and ~200–500 completion tokens (the answer).
     */
    public function onAgentPrompted(AgentPrompted $event): void
    {
        $usage = $event->response->usage ?? null;

        Log::channel($this->channel)->info('AI: agent responded', [
            'invocation_id' => $event->invocationId,
            'prompt_tokens' => $usage?->promptTokens,
            'completion_tokens' => $usage?->completionTokens,
            'total_tokens' => $usage
                ? ($usage->promptTokens + $usage->completionTokens)
                : null,
        ]);
    }
}
