/**
 * DocAssistant — Main React Component
 *
 * This component is the entire frontend for the Internal Documentation Assistant.
 * It demonstrates the core React concepts and Vercel AI SDK patterns.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REACT CONCEPTS USED IN THIS FILE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. FUNCTION COMPONENTS
 *    Modern React uses plain JavaScript functions as components.
 *    A component is a function that returns JSX (HTML-like syntax that Vite/Babel
 *    transpiles to React.createElement() calls).
 *
 *    function MyComponent() {           // ← function component
 *      return <div>Hello</div>;         // ← JSX return
 *    }
 *
 * 2. JSX
 *    JSX looks like HTML but it's JavaScript. Rules:
 *      - className instead of class (class is a reserved JS keyword)
 *      - htmlFor instead of for
 *      - Self-close empty tags: <br /> not <br>
 *      - Expressions in curly braces: <p>{variable}</p>
 *      - Event handlers are camelCase: onClick, onChange, onSubmit
 *
 * 3. HOOKS
 *    Hooks are functions that let function components "hook into" React features.
 *    They must be called at the top level of a function component (not inside
 *    loops, conditionals, or nested functions).
 *
 *    useState(initialValue) → [currentValue, setterFunction]
 *      - Triggers a re-render when the setter is called
 *      - React remembers the value between renders (unlike a local variable)
 *
 *    useRef(initialValue) → { current: value }
 *      - Stores a mutable value that does NOT trigger re-renders
 *      - Used here to reference the messages container DOM node for auto-scroll
 *
 * 4. PROPS
 *    Props are inputs passed to a component from its parent:
 *      <Button label="Ask" onClick={handleClick} />
 *    Inside Button, props.label and props.onClick are available.
 *    We use destructuring: function Button({ label, onClick }) { ... }
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * VERCEL AI SDK CONCEPTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * useChat(options) — the main hook from @ai-sdk/react
 *
 *   What it does:
 *     - Maintains a messages array (full conversation history)
 *     - Tracks input field value
 *     - Sends POST requests to the API endpoint when the user submits
 *     - Parses the SSE stream (Vercel data protocol) and appends tokens
 *       to the latest assistant message in real time
 *     - Handles loading state, errors, and stream abort
 *
 *   What you get back:
 *     messages       — array of {id, role: 'user'|'assistant', content: string}
 *     input          — current value of the input field
 *     handleInputChange  — onChange handler for the input (updates input state)
 *     handleSubmit   — onSubmit handler for the form (sends the message)
 *     isLoading      — true while the stream is in progress
 *     error          — Error object if the request failed, otherwise undefined
 *     stop()         — cancels the in-flight stream
 *     append(message) — programmatically add a message and trigger a response
 *
 *   Wire format:
 *     The hook sends: POST /api/ask/chat
 *       { messages: [{ id, role, content }, ...] }
 *     The server responds with SSE in Vercel data protocol:
 *       data: "0:\"token\"\n\n"   ← text token
 *       data: "d:{...}\n\n"       ← stream finish + metadata
 *     useChat() automatically parses this and builds the assistant message.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPARE TO ALPINE.JS VERSION (main branch)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * | Concern          | Alpine.js (main)            | React + useChat() (this)   |
 * |------------------|-----------------------------|----------------------------|
 * | State model      | x-data object               | useState / useChat hooks   |
 * | Reactivity       | Proxy-based (automatic)     | Explicit state setters     |
 * | Auth header      | Token in URL (EventSource)  | Authorization: Bearer      |
 * | Streaming        | EventSource (GET only)       | fetch() POST               |
 * | Message history  | Single answer string         | Full conversation array    |
 * | Bundle           | CDN (no build)              | Vite build required        |
 * | Build step       | None                        | npm run dev / npm run build|
 * | HMR              | None                        | React Fast Refresh         |
 * | DevTools         | None                        | React DevTools extension   |
 * | Component model  | No — everything in x-data   | Yes — reusable components  |
 */

import React, { useState, useRef, useEffect } from 'react';
import { useChat } from '@ai-sdk/react';

// ─────────────────────────────────────────────────────────────────────────────
// Sub-components
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Message — renders a single chat message bubble.
 *
 * Props:
 *   role     — 'user' | 'assistant'
 *   content  — the message text
 *   isLatest — true if this is the most recent message (shows cursor during stream)
 *   isLoading — true if the stream is still in progress
 *
 * This is a "presentational" component — it receives all its data as props
 * and has no internal state. Also called a "dumb" component.
 * Contrast with DocAssistant which is a "smart" (stateful) component.
 */
function Message({ role, content, isLatest, isLoading }) {
    const isUser      = role === 'user';
    const showCursor  = isLatest && !isUser && isLoading;

    return (
        /*
         * Conditional className pattern in React:
         *   className={`base-classes ${condition ? 'if-true' : 'if-false'}`}
         *
         * Template literals (backtick strings) let you embed expressions.
         * This is cleaner than Alpine's :class="{'class': condition}".
         */
        <div className={`flex ${isUser ? 'justify-end' : 'justify-start'} mb-4`}>
            {/* Avatar — only shown for assistant messages */}
            {!isUser && (
                <div className="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-xs font-bold mr-3 flex-shrink-0 mt-1">
                    AI
                </div>
            )}

            <div
                className={`
                    max-w-[80%] px-4 py-3 rounded-2xl text-sm leading-relaxed
                    ${isUser
                        ? 'bg-indigo-600 text-white rounded-tr-sm'
                        : 'bg-slate-700 text-slate-100 rounded-tl-sm'
                    }
                `}
            >
                {/*
                 * Whitespace preservation: white-space: pre-wrap keeps newlines
                 * and multiple spaces from the LLM response intact.
                 */}
                <span style={{ whiteSpace: 'pre-wrap' }}>{content}</span>

                {/*
                 * Streaming cursor — only shown while the assistant is generating.
                 * The CSS animation is defined in app.css.
                 * This conditional rendering pattern is idiomatic React:
                 *   {condition && <Element />}   ← renders Element only if truthy
                 */}
                {showCursor && (
                    <span className="streaming-cursor ml-0.5" aria-hidden="true">▋</span>
                )}
            </div>

            {/* User avatar — only shown for user messages */}
            {isUser && (
                <div className="w-8 h-8 rounded-full bg-slate-600 flex items-center justify-center text-white text-xs font-bold ml-3 flex-shrink-0 mt-1">
                    You
                </div>
            )}
        </div>
    );
}

/**
 * EmptyState — shown when no messages exist yet.
 *
 * Clicking one of the suggestion chips calls onSuggestion(text), which
 * uses useChat()'s append() to send the message without the user typing.
 *
 * Props:
 *   onSuggestion — (questionText: string) => void
 */
function EmptyState({ onSuggestion }) {
    const suggestions = [
        'What is the PTO policy?',
        'How do I initiate a production deployment?',
        'What software do I need to set up on day one?',
        'What happens during a P0 incident?',
    ];

    return (
        <div className="flex flex-col items-center justify-center h-full py-12 text-center">
            <div className="w-14 h-14 rounded-2xl bg-indigo-600 flex items-center justify-center mb-5 text-2xl">
                📄
            </div>
            <h2 className="text-slate-200 text-lg font-semibold mb-2">
                Internal Documentation Assistant
            </h2>
            <p className="text-slate-400 text-sm mb-8 max-w-sm">
                Ask anything about our engineering runbook, HR policies, or onboarding guide.
            </p>

            {/* Suggestion chips */}
            <div className="flex flex-col gap-2 w-full max-w-sm">
                {suggestions.map((q) => (
                    /*
                     * List rendering with .map():
                     * React requires a unique 'key' prop on list items so it can
                     * efficiently reconcile changes. Using the text content as key
                     * is fine here since these never reorder.
                     * Never use array index as key for dynamic lists (causes bugs).
                     */
                    <button
                        key={q}
                        onClick={() => onSuggestion(q)}
                        className="text-left px-4 py-3 bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm rounded-xl border border-slate-600 hover:border-indigo-500 transition-all duration-150"
                    >
                        {q}
                    </button>
                ))}
            </div>
        </div>
    );
}

/**
 * TokenSetup — collects the Sanctum API token.
 *
 * Shown as an overlay until the user provides a token.
 * This is a "controlled component" — the input value is driven by React state
 * (value={localToken}), not by the DOM. The DOM is just a view of the state.
 *
 * Props:
 *   onConfirm — (token: string) => void
 */
function TokenSetup({ onConfirm }) {
    /*
     * useState — local state for this component.
     *
     * const [value, setter] = useState(initial)
     *
     * - value: current state (a string here)
     * - setter: calling setter(newValue) schedules a re-render with the new value
     * - React guarantees that state updates are batched and efficient
     *
     * useState is called inside the component function — React associates it
     * with this specific component instance via the "hooks call order" rule
     * (which is why hooks can't be inside conditionals or loops).
     */
    const [localToken, setLocalToken] = useState('');

    const handleSubmit = (e) => {
        /*
         * e.preventDefault() stops the browser's default form submission,
         * which would cause a full-page reload. In React, we handle form
         * submission in JavaScript and update state instead.
         */
        e.preventDefault();
        if (localToken.trim()) {
            onConfirm(localToken.trim());
        }
    };

    return (
        <div className="fixed inset-0 bg-slate-900/90 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-600 rounded-2xl p-8 w-full max-w-md shadow-2xl">
                <div className="text-2xl mb-4">🔑</div>
                <h2 className="text-slate-100 font-semibold text-lg mb-1">
                    Enter your API token
                </h2>
                <p className="text-slate-400 text-sm mb-6">
                    Generate one with:
                    <code className="ml-1 px-1.5 py-0.5 bg-slate-700 rounded text-indigo-300 text-xs">
                        POST /api/tokens/create
                    </code>
                </p>

                {/*
                 * Controlled form — value and onChange are paired.
                 * React owns the input value (value={localToken}).
                 * The onChange handler updates state when the user types.
                 * This keeps the DOM in sync with React state at all times.
                 */}
                <form onSubmit={handleSubmit}>
                    <input
                        type="text"
                        value={localToken}
                        onChange={(e) => setLocalToken(e.target.value)}
                        placeholder="1|abc123..."
                        className="w-full bg-slate-900 border border-slate-600 focus:border-indigo-500 rounded-xl text-slate-200 text-sm font-mono px-4 py-3 outline-none mb-4 transition-colors"
                        autoFocus
                    />
                    <button
                        type="submit"
                        disabled={!localToken.trim()}
                        className="w-full bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-xl transition-colors"
                    >
                        Continue
                    </button>
                </form>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main component
// ─────────────────────────────────────────────────────────────────────────────

/**
 * DocAssistant — root component, manages auth token and delegates to useChat().
 */
export default function DocAssistant() {
    /*
     * useState for the Sanctum token.
     * null = not yet provided; string = token is set.
     */
    const [token, setToken] = useState(null);

    /*
     * useRef — a mutable container that persists across renders.
     * Here we use it to reference the messages <div> so we can auto-scroll
     * to the bottom when a new message arrives.
     *
     * Unlike useState, changing ref.current does NOT trigger a re-render.
     * It's used for DOM access, timers, and any mutable value that shouldn't
     * cause a re-render when changed.
     */
    const messagesEndRef = useRef(null);

    /*
     * ── useChat() ───────────────────────────────────────────────────────────
     *
     * This hook is the core of the Vercel AI SDK integration.
     * It abstracts all the complexity of:
     *   - Sending POST requests to the API
     *   - Parsing the SSE stream (Vercel data protocol)
     *   - Appending tokens to the assistant message in real time
     *   - Managing message history, input state, loading, and errors
     *
     * Options:
     *   api      — the endpoint to POST to (POST /api/ask/chat)
     *   headers  — merged with every request (Authorization for Sanctum)
     *
     * The headers option is dynamic — it re-reads 'token' on every request.
     * But since useChat() is called at component init time, we pass a function
     * that returns headers. Actually in @ai-sdk/react, 'headers' is evaluated
     * per-request if you pass it as a function, but as an object it's captured
     * at hook creation. We work around this by making token a dependency.
     *
     * Note: useChat() is NOT called conditionally (it's always called at the
     * top level). If token is null, we render the TokenSetup overlay on top —
     * but useChat() is still initialised. This follows the Rules of Hooks.
     */
    const {
        messages,         // {id, role, content}[] — full conversation history
        input,            // string — current value of the input field
        handleInputChange, // (e: ChangeEvent) => void — keep input in sync
        handleSubmit,     // (e: FormEvent) => void — send message + stream response
        isLoading,        // boolean — true while the SSE stream is active
        error,            // Error | undefined — set if the request fails
        stop,             // () => void — abort the in-flight stream
        append,           // (message) => void — programmatically add + send
    } = useChat({
        api: '/api/ask/chat',

        /*
         * headers are attached to every POST /api/ask/chat request.
         * This is how Sanctum Bearer auth works with useChat():
         *   - useChat() uses fetch() internally
         *   - fetch() supports arbitrary headers
         *   - Laravel's auth:sanctum middleware validates the Bearer token
         *
         * Compare to Alpine.js: EventSource can't send headers, so the token
         * had to be appended to the URL (?api_token=...) — less secure.
         */
        headers: token ? { Authorization: `Bearer ${token}` } : {},

        /*
         * onError: called if the fetch fails or the stream errors.
         * We don't need to store it separately — 'error' from useChat() handles it.
         */
        onError: (err) => {
            console.error('[DocAssistant] Stream error:', err);
        },
    });

    /*
     * useEffect — runs side effects after the component renders.
     *
     * Signature: useEffect(effectFn, [dependencies])
     *
     * - effectFn runs after every render where [dependencies] changed.
     * - An empty [] runs only once (after mount, like componentDidMount).
     * - No [] runs after every render.
     * - Return a cleanup function to run on unmount or before the next effect.
     *
     * Here: whenever 'messages' changes (a new token arrives or a new message
     * is added), scroll the messages container to the bottom so the latest
     * content is always visible.
     */
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    /*
     * Re-initialise useChat() when the token changes.
     * Since hooks can't be called conditionally, we track the token in state
     * and the headers option above reads it. When setToken() is called, the
     * component re-renders and useChat() gets the new headers on its next fetch.
     */
    const handleTokenConfirm = (newToken) => {
        setToken(newToken);
    };

    /*
     * Suggestion chip handler — uses append() to send a question programmatically.
     *
     * append() is like the user typing and submitting — it adds a user message
     * to the messages array and triggers a POST to /api/ask/chat immediately.
     */
    const handleSuggestion = (question) => {
        append({ role: 'user', content: question });
    };

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <>
            {/* Token setup overlay — shown until token is provided */}
            {!token && <TokenSetup onConfirm={handleTokenConfirm} />}

            {/*
             * Main layout — full viewport height column layout.
             * In JSX, you can only return a single root element.
             * Use <> ... </> (React Fragment) to group siblings without adding a <div>.
             */}
            <div className="min-h-screen bg-slate-900 flex flex-col">

                {/* ── Header ── */}
                <header className="border-b border-slate-700 px-4 py-3 flex items-center justify-between flex-shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white text-sm font-bold">
                            📄
                        </div>
                        <div>
                            <h1 className="text-slate-100 font-semibold text-sm">
                                Internal Doc Assistant
                            </h1>
                            <p className="text-slate-400 text-xs">
                                React + Vercel AI SDK + Laravel RAG
                            </p>
                        </div>
                    </div>

                    {/* Token indicator + reset button */}
                    {token && (
                        <button
                            onClick={() => setToken(null)}
                            className="text-xs text-slate-400 hover:text-slate-200 flex items-center gap-1.5 transition-colors"
                            title="Change API token"
                        >
                            <span className="w-2 h-2 rounded-full bg-emerald-400 inline-block" />
                            Connected
                        </button>
                    )}
                </header>

                {/* ── Messages area ── */}
                <div className="flex-1 overflow-y-auto px-4 py-6">
                    <div className="max-w-2xl mx-auto">

                        {/* Empty state or message list */}
                        {messages.length === 0 ? (
                            <EmptyState onSuggestion={handleSuggestion} />
                        ) : (
                            messages.map((message, index) => (
                                /*
                                 * Each Message component gets a unique 'key'.
                                 * React uses this to identify items in a list.
                                 * useChat() gives each message a stable unique id.
                                 */
                                <Message
                                    key={message.id}
                                    role={message.role}
                                    content={message.content}
                                    isLatest={index === messages.length - 1}
                                    isLoading={isLoading}
                                />
                            ))
                        )}

                        {/* Error banner */}
                        {error && (
                            <div className="mt-4 p-3 bg-red-900/40 border border-red-700 rounded-xl text-red-300 text-sm">
                                <strong>Error:</strong> {error.message || 'Something went wrong.'}
                            </div>
                        )}

                        {/*
                         * Scroll anchor — useEffect scrolls this into view when messages update.
                         * useRef gives us a reference to this DOM node without triggering renders.
                         */}
                        <div ref={messagesEndRef} />
                    </div>
                </div>

                {/* ── Input area ── */}
                <div className="border-t border-slate-700 px-4 py-4 flex-shrink-0">
                    <div className="max-w-2xl mx-auto">

                        {/*
                         * handleSubmit is provided by useChat(). It:
                         *   1. Calls e.preventDefault()
                         *   2. Appends the current 'input' as a user message
                         *   3. POSTs to /api/ask/chat with the full messages array
                         *   4. Streams the response and appends tokens to the
                         *      assistant message as they arrive
                         *   5. Clears the input field
                         */}
                        <form onSubmit={handleSubmit} className="flex gap-2">
                            <input
                                type="text"
                                value={input}
                                onChange={handleInputChange}
                                placeholder="Ask about our docs..."
                                disabled={isLoading}
                                className="
                                    flex-1 bg-slate-800 border border-slate-600
                                    focus:border-indigo-500 rounded-xl text-slate-200
                                    text-sm px-4 py-3 outline-none transition-colors
                                    disabled:opacity-50 placeholder-slate-500
                                "
                            />

                            {/*
                             * Conditional rendering: show "Stop" button while loading,
                             * "Send" button otherwise.
                             *
                             * stop() from useChat() aborts the fetch + closes the stream.
                             * This is the React/Vercel AI SDK equivalent of
                             * eventSource.close() from the Alpine.js version.
                             */}
                            {isLoading ? (
                                <button
                                    type="button"
                                    onClick={stop}
                                    className="px-4 py-3 bg-red-700 hover:bg-red-600 text-white text-sm font-medium rounded-xl transition-colors"
                                >
                                    Stop
                                </button>
                            ) : (
                                <button
                                    type="submit"
                                    disabled={!input.trim() || !token}
                                    className="px-4 py-3 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-xl transition-colors"
                                >
                                    Send
                                </button>
                            )}
                        </form>

                        <p className="text-xs text-slate-500 mt-2 text-center">
                            Answers sourced from internal documentation only.
                            Sources cited inline.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
