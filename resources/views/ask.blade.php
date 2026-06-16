<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Doc Assistant</title>

    {{--
        Alpine.js — lightweight reactive framework via CDN.
        No build step required. x-data, x-model, x-show, @click
        are all you need for this simple UI.
        CDN: https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js
    --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        /* ─── Reset & base ─── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;   /* Slate 900 */
            color: #e2e8f0;        /* Slate 200 */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
        }

        /* ─── Layout ─── */
        .container { width: 100%; max-width: 760px; }
        header { text-align: center; margin-bottom: 2.5rem; }
        header h1 { font-size: 1.75rem; font-weight: 700; color: #f8fafc; }
        header p  { color: #94a3b8; margin-top: 0.5rem; font-size: 0.95rem; }

        /* ─── Token setup box ─── */
        .setup-box {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.75rem;
        }
        .setup-box label { font-size: 0.85rem; color: #94a3b8; display: block; margin-bottom: 0.4rem; }
        .setup-box input {
            width: 100%;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 0.5rem;
            color: #e2e8f0;
            font-size: 0.9rem;
            padding: 0.6rem 0.85rem;
            outline: none;
            font-family: monospace;
        }
        .setup-box input:focus { border-color: #6366f1; }

        /* ─── Question form ─── */
        .question-row {
            display: flex;
            gap: 0.6rem;
            margin-bottom: 1rem;
        }
        .question-row input {
            flex: 1;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.75rem;
            color: #e2e8f0;
            font-size: 1rem;
            padding: 0.75rem 1rem;
            outline: none;
        }
        .question-row input::placeholder { color: #64748b; }
        .question-row input:focus { border-color: #6366f1; }

        .btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: opacity 0.15s;
        }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary  { background: #6366f1; color: #fff; }
        .btn-stream   { background: #0f9467; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #4f46e5; }
        .btn-stream:hover:not(:disabled)  { background: #0d7a56; }

        /* ─── Answer card ─── */
        .answer-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            min-height: 5rem;
            line-height: 1.7;
            font-size: 0.95rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .answer-card.streaming { border-color: #0f9467; }
        .answer-card.empty     { color: #64748b; font-style: italic; }

        /* ─── Sources ─── */
        .sources-row {
            margin-top: 0.85rem;
            font-size: 0.82rem;
            color: #64748b;
        }
        .source-tag {
            display: inline-block;
            background: #1e3a5f;
            color: #93c5fd;
            border-radius: 9999px;
            padding: 0.2rem 0.65rem;
            margin: 0.2rem 0.2rem 0 0;
            font-family: monospace;
        }

        /* ─── Error ─── */
        .error { color: #f87171; font-size: 0.9rem; margin-top: 0.5rem; }

        /* ─── Cursor blink during streaming ─── */
        @keyframes blink { 50% { opacity: 0; } }
        .cursor::after {
            content: '▋';
            animation: blink 1s step-end infinite;
            color: #0f9467;
            margin-left: 1px;
        }

        /* ─── Mode badge ─── */
        .mode-badge {
            display: inline-block;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            margin-bottom: 0.75rem;
        }
        .mode-badge.sync   { background: #312e81; color: #a5b4fc; }
        .mode-badge.stream { background: #064e3b; color: #6ee7b7; }
    </style>
</head>
<body>
    {{--
        x-data="docAssistant()" initialises Alpine's reactive scope.
        All state (token, question, answer, sources, loading) is managed
        here and referenced with x-model / x-text / x-show / @click.
    --}}
    <div class="container" x-data="docAssistant()">

        <header>
            <h1>Internal Doc Assistant</h1>
            <p>Ask anything about our engineering runbook, HR policies, or onboarding guide.</p>
        </header>

        {{-- ── Step 1: Paste your Sanctum token ── --}}
        <div class="setup-box">
            <label for="token">
                🔑 Sanctum API Token
                <span style="color:#475569">
                    — get one: <code>POST /api/tokens/create</code>
                </span>
            </label>
            <input
                id="token"
                type="text"
                x-model="token"
                placeholder="1|abc123..."
            />
        </div>

        {{-- ── Step 2: Type a question ── --}}
        <div class="question-row">
            <input
                type="text"
                x-model="question"
                placeholder="What is the PTO policy? How do I deploy? Who is the on-call?"
                @keydown.enter="ask()"
            />

            {{--
                Two buttons: one for synchronous JSON (POST /api/ask),
                one for streaming SSE (GET /api/ask/stream).
            --}}
            <button
                class="btn btn-primary"
                @click="ask()"
                :disabled="loading || !token || !question"
                title="POST /api/ask — synchronous JSON response"
            >
                Ask
            </button>

            <button
                class="btn btn-stream"
                @click="stream()"
                :disabled="loading || !token || !question"
                title="GET /api/ask/stream — Server-Sent Events (live tokens)"
            >
                Stream
            </button>
        </div>

        {{-- ── Mode badge (shows which endpoint was used) ── --}}
        <div x-show="mode" style="margin-bottom: 0.5rem;">
            <span
                class="mode-badge"
                :class="mode === 'stream' ? 'stream' : 'sync'"
                x-text="mode === 'stream' ? '⚡ SSE streaming' : '⬡ Sync JSON'"
            ></span>
        </div>

        {{-- ── Answer area ── --}}
        <div
            class="answer-card"
            :class="{
                'streaming': loading && mode === 'stream',
                'cursor':    loading && mode === 'stream',
                'empty':     !answer && !loading
            }"
            x-text="answer || (loading ? 'Thinking...' : 'Your answer will appear here.')"
        ></div>

        {{-- ── Source citations ── --}}
        <div class="sources-row" x-show="sources.length > 0">
            Sources:
            <template x-for="src in sources" :key="src">
                <span class="source-tag" x-text="src"></span>
            </template>
        </div>

        {{-- ── Error message ── --}}
        <p class="error" x-show="error" x-text="error"></p>

    </div>

    <script>
    /**
     * Alpine.js component — Internal Doc Assistant
     *
     * State:
     *   token    — Sanctum API token (stored in component state, not localStorage for security)
     *   question — current question text
     *   answer   — the generated answer (built up progressively during streaming)
     *   sources  — array of source document titles cited by the LLM
     *   loading  — true while a request is in flight
     *   mode     — 'sync' | 'stream' — which endpoint was last used
     *   error    — error message string or null
     *   eventSource — holds the EventSource instance during streaming
     */
    function docAssistant() {
        return {
            token:       '',
            question:    '',
            answer:      '',
            sources:     [],
            loading:     false,
            mode:        null,
            error:       null,
            eventSource: null,

            /**
             * POST /api/ask — synchronous RAG response.
             *
             * Full pipeline runs server-side:
             *   embed → vector search → Cohere rerank → Anthropic generate
             *
             * Returns JSON: { answer: "...", sources: ["..."] }
             * No streaming — the entire answer arrives at once.
             */
            async ask() {
                this.reset();
                this.mode = 'sync';

                try {
                    const res = await fetch('/api/ask', {
                        method: 'POST',
                        headers: {
                            'Content-Type':  'application/json',
                            'Authorization': 'Bearer ' + this.token,
                            'Accept':        'application/json',
                        },
                        body: JSON.stringify({ question: this.question }),
                    });

                    if (!res.ok) {
                        const err = await res.json().catch(() => ({}));
                        this.error = err.message || `Error ${res.status}`;
                        return;
                    }

                    const data  = await res.json();
                    this.answer  = data.answer;
                    this.sources = data.sources || [];

                } catch (e) {
                    this.error = 'Network error: ' + e.message;
                } finally {
                    this.loading = false;
                }
            },

            /**
             * GET /api/ask/stream — Server-Sent Events streaming.
             *
             * Uses the native EventSource API to connect to the SSE endpoint.
             * The server sends tokens in Vercel AI SDK data protocol format:
             *   data: "0:\"token\"\n\n"
             *
             * Each event's data field is parsed and appended to this.answer,
             * creating the "typing" effect in real time.
             *
             * Note: EventSource doesn't support custom headers in the
             * browser. The token is passed as an Authorization: Bearer header
             * via the server's token guard — for local dev, the session cookie
             * auth would also work. For production SSE with tokens, consider
             * a short-lived signed URL or a token in the query param.
             *
             * This demo includes the token in the URL for simplicity.
             * For production: implement a signed short-lived SSE token.
             */
            stream() {
                this.reset();
                this.mode = 'stream';

                const url = '/api/ask/stream'
                    + '?question=' + encodeURIComponent(this.question)
                    + '&api_token=' + encodeURIComponent(this.token);

                /*
                 * EventSource opens a persistent HTTP connection.
                 * The server pushes events as it generates each token.
                 * onmessage fires for each 'data:' line received.
                 */
                this.eventSource = new EventSource(url);

                this.eventSource.onmessage = (event) => {
                    /*
                     * Vercel AI SDK data protocol format:
                     *   data: "0:\"<token>\"\n\n"
                     *   data: "0:\" more text\"\n\n"
                     *   data: "d:{\"finishReason\":\"stop\",...}\n\n"  ← stream end
                     *
                     * "0:" prefix = text chunk
                     * "d:" prefix = metadata / finish event
                     */
                    const raw = event.data;

                    if (raw.startsWith('d:')) {
                        // Stream finished — close the connection
                        this.eventSource.close();
                        this.loading = false;
                        return;
                    }

                    // Strip the "0:" prefix and parse the JSON-encoded string
                    const jsonStr = raw.replace(/^0:/, '');
                    try {
                        this.answer += JSON.parse(jsonStr);
                    } catch {
                        // Fallback: append raw if JSON parse fails
                        this.answer += jsonStr;
                    }
                };

                this.eventSource.onerror = () => {
                    this.eventSource.close();
                    this.loading = false;
                    if (!this.answer) {
                        this.error = 'Stream failed. Check your token and question.';
                    }
                };
            },

            reset() {
                // Cancel any in-flight SSE stream before starting a new request
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
                this.answer  = '';
                this.sources = [];
                this.error   = null;
                this.loading = true;
            },
        };
    }
    </script>
</body>
</html>
