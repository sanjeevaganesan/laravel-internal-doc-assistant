<?php

/*
|--------------------------------------------------------------------------
| Laravel AI SDK — Configuration
|--------------------------------------------------------------------------
|
| This file configures the laravel/ai SDK (https://laravel.com/docs/13.x/ai-sdk).
|
| Architecture overview for this app:
|   • Text generation  → Anthropic (claude-sonnet-4-6 by default)
|                        with OpenAI as automatic failover provider
|   • Embeddings       → OpenAI (text-embedding-3-small, 1536 dimensions)
|                        Results are cached for 30 days to cut API costs
|   • Reranking        → Cohere (rerank-english-v3.0)
|                        Narrows 15 vector-search candidates to top 5
|
| All three API keys must be set in .env for the app to function.
| See .env.example for all required variables.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Which provider handles each operation type when none is specified
    | explicitly in a call. Each value must match a key under 'providers'.
    |
    | Rationale for these choices:
    |   - anthropic  → Best instruction-following; ideal for RAG answers
    |   - openai     → text-embedding-3-small is cost-efficient & accurate
    |   - cohere     → Rerank API is purpose-built for RAG precision
    |
    */

    'default'                  => 'anthropic',   // TEXT: Anthropic (OpenAI as failover)
    'default_for_images'       => 'gemini',
    'default_for_audio'        => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings'   => 'openai',      // EMBEDDINGS: OpenAI text-embedding-3-small
    'default_for_reranking'    => 'cohere',      // RERANKING: Cohere rerank-english-v3.0

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Embedding caching dramatically reduces OpenAI API costs and latency for
    | frequently-asked questions. When a chunk is embedded once, the vector is
    | stored in the cache; repeated ingestion or identical questions reuse it.
    |
    | cache   : true  → enabled  (recommended for production)
    | store   : which Laravel cache driver to use ('database', 'redis', etc.)
    | seconds : 2592000 = 60 * 60 * 24 * 30 = 30 days
    |
    | To purge the embedding cache: php artisan cache:clear
    |
    */

    'caching' => [
        'embeddings' => [
            'cache'   => true,
            'store'   => env('CACHE_STORE', 'database'),
            'seconds' => 60 * 60 * 24 * 30,   // 30 days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Each entry configures one AI provider. The 'driver' key must match a
    | registered driver in the laravel/ai service container.
    |
    | Custom 'url' fields enable proxy routing for observability or cost
    | management tools such as LiteLLM, Helicone, or OpenRouter.
    | Set ANTHROPIC_URL / OPENAI_URL in .env to redirect traffic.
    |
    */

    'providers' => [

        /*
         * Anthropic — primary text generation provider.
         * Models: claude-sonnet-4-6, claude-opus-4-8, claude-haiku-4-5-20251001
         *
         * The SDK automatically picks the model based on #[Provider] / #[Model]
         * attributes on agent classes, or defaults to the provider's current
         * recommended model when none is specified.
         */
        'anthropic' => [
            'driver' => 'anthropic',
            'key'    => env('ANTHROPIC_API_KEY'),
            // Proxy-routing: override to send traffic through LiteLLM, Helicone, etc.
            'url'    => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver'               => 'azure',
            'key'                  => env('AZURE_OPENAI_API_KEY'),
            'url'                  => env('AZURE_OPENAI_URL'),
            'api_version'          => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment'           => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment'     => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store'                => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver'                          => 'bedrock',
            'region'                          => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key'                             => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id'                   => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key'               => env('AWS_SECRET_ACCESS_KEY'),
            'session_token'                   => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        /*
         * Cohere — used exclusively for reranking.
         * Model: rerank-english-v3.0 (default when provider: Lab::Cohere)
         *
         * Reranking takes 15 vector-search candidates and re-scores them using
         * a cross-encoder model, returning the top 5 most semantically relevant
         * documents. This improves answer quality significantly over pure cosine
         * similarity, which can be noisy at the retrieval boundary.
         */
        'cohere' => [
            'driver' => 'cohere',
            'key'    => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key'    => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key'    => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key'    => env('GEMINI_API_KEY'),
            'url'    => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key'    => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key'    => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key'    => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key'    => env('OLLAMA_API_KEY', ''),
            'url'    => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        /*
         * OpenAI — used for:
         *   1. Embeddings (text-embedding-3-small, 1536 dimensions)
         *   2. Failover text generation (when Anthropic is unavailable)
         *   3. Vector Stores path (provider-side RAG via FileSearch tool)
         *
         * 'store' = true enables OpenAI's response storage (required for
         * Vector Stores / FileSearch functionality).
         *
         * Proxy routing: set OPENAI_URL to redirect through LiteLLM, etc.
         */
        'openai' => [
            'driver' => 'openai',
            'key'    => env('OPENAI_API_KEY'),
            // Proxy-routing: override to send traffic through LiteLLM, Helicone, etc.
            'url'    => env('OPENAI_URL', 'https://api.openai.com/v1'),
            // Required for Vector Stores and FileSearch tool
            'store'  => env('OPENAI_STORE', true),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key'    => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key'    => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key'    => env('XAI_API_KEY'),
        ],

    ],

];
