<?php

use Illuminate\Support\Facades\Route;

/*
 * Root route: serves the Alpine.js single-page frontend.
 *
 * The ask.blade.php view connects to the API via:
 *   - POST /api/ask          — synchronous RAG answer
 *   - GET  /api/ask/stream   — SSE streaming with Vercel data protocol
 *
 * See the 'feature/react-vercel-ai' branch for the React + Vercel AI SDK frontend.
 */
Route::get('/', fn () => view('ask'));
