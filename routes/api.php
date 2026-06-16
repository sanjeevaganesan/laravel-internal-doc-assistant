<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api automatically (configured in
| bootstrap/app.php). They are stateless by default in Laravel 13.
|
| Route structure:
|
|   POST /api/ask                → Synchronous RAG answer (JSON)
|   GET  /api/ask/stream         → Streaming RAG answer (SSE / Vercel protocol)
|   POST /api/vector-store/ingest → Upload docs to OpenAI Vector Store
|   POST /api/vector-store/ask   → Query via OpenAI FileSearch tool
|   POST /api/tokens/create      → Issue Sanctum API token (public)
|
| All /ask and /vector-store routes are protected by Sanctum API token auth.
| Use POST /api/tokens/create to get a token, then pass it as:
|   Authorization: Bearer <token>
|
*/

use App\Http\Controllers\AskController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\VectorStoreController;
use Illuminate\Support\Facades\Route;

/*
 * Public endpoint — issue an API token for testing.
 *
 * In a real application, this would be protected by user authentication.
 * For local development and demos, it's left open so you can quickly
 * obtain a token without setting up a full auth flow.
 *
 * Usage:
 *   curl -X POST http://localhost:8000/api/tokens/create \
 *        -H 'Content-Type: application/json' \
 *        -d '{"name": "dev-token"}'
 *
 * Returns: { "token": "1|abc123..." }
 */
Route::post('/tokens/create', [TokenController::class, 'create']);

/*
 * Protected routes — require a valid Sanctum API token.
 *
 * Include the token in every request:
 *   -H 'Authorization: Bearer <token>'
 *
 * For the streaming endpoint, you can pass the token as a query param
 * since EventSource doesn't support custom headers:
 *   GET /api/ask/stream?question=...&api_token=<token>
 */
Route::middleware('auth:sanctum')->group(function () {

    // ── pgvector RAG path ──────────────────────────────────────────────
    //
    // Pipeline: embed → vector search → Cohere rerank → Anthropic/OpenAI generate

    /*
     * POST /api/ask
     *
     * Full synchronous RAG query. Returns a JSON object with the answer
     * and the list of source document titles used to generate it.
     *
     * Body: { "question": "What is the on-call rotation?" }
     * Returns: { "answer": "According to [runbook], ...", "sources": ["runbook"] }
     */
    Route::post('/ask', [AskController::class, 'ask']);

    /*
     * GET /api/ask/stream
     *
     * Streaming RAG query. Streams the answer token-by-token as Server-Sent
     * Events using the Vercel AI SDK data protocol. Compatible with:
     *   - Browser EventSource API (used by the Blade+Alpine.js frontend on main)
     *
     * Note: EventSource cannot send the Authorization header, so this endpoint
     * relies on the api_token query param for auth in the Alpine.js frontend.
     * For React, use POST /api/ask/chat instead (fetch supports headers).
     *
     * Query params: ?question=What+is+the+deploy+process
     */
    Route::get('/ask/stream', [AskController::class, 'stream']);

    /*
     * POST /api/ask/chat
     *
     * Streaming endpoint designed for the Vercel AI SDK's useChat() hook.
     * Accepts the useChat() wire format: { messages: [{role, content}, ...] }
     * Extracts the last user message as the question, runs the same RAG pipeline,
     * and returns a stream in Vercel data protocol format.
     *
     * Why POST instead of GET?
     *   useChat() uses fetch() which supports POST + custom headers.
     *   This allows Authorization: Bearer <token> to work natively — no
     *   token-in-query-string workaround needed (unlike EventSource).
     *
     * Body: { "messages": [{"role": "user", "content": "What is PTO?"}] }
     * Streams: SSE in Vercel data protocol (same format as /ask/stream)
     */
    Route::post('/ask/chat', [AskController::class, 'chat']);

    // ── Provider-side RAG path ─────────────────────────────────────────
    //
    // Pipeline: upload to OpenAI Vector Store → agent + FileSearch tool
    // Contrast with pgvector path: simpler code, but data leaves your infrastructure

    /*
     * POST /api/vector-store/ingest
     *
     * Creates an OpenAI Vector Store and uploads all markdown files from
     * storage/app/knowledge/ with metadata (author, department, year).
     *
     * Returns: { "store_id": "vs_abc123", "status": "ingested" }
     */
    Route::post('/vector-store/ingest', [VectorStoreController::class, 'ingest']);

    /*
     * POST /api/vector-store/ask
     *
     * Queries the OpenAI Vector Store using a FileSearch tool with optional
     * metadata filtering. The agent runs server-side at OpenAI.
     *
     * Body: { "question": "...", "store_id": "vs_abc123", "year": 2026 }
     * Returns: { "answer": "..." }
     */
    Route::post('/vector-store/ask', [VectorStoreController::class, 'ask']);
});
