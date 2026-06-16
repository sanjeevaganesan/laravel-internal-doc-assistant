<?php

namespace App\Providers;

use App\Listeners\AiObservabilityListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\Reranking;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * This is where we wire up all cross-cutting concerns that need to run
     * regardless of which route or command is active.
     *
     * Why register event listeners here instead of in EventServiceProvider?
     *   For an internal tool with a small set of listeners, keeping them in
     *   AppServiceProvider::boot() is simpler — no extra file to maintain.
     *   If this grows to 10+ event types, extract to a dedicated provider.
     */
    public function boot(): void
    {
        $this->registerAiObservability();
    }

    /**
     * Register listeners for laravel/ai SDK lifecycle events.
     *
     * The SDK fires events in pairs (before/after) for each AI operation:
     *
     *   GeneratingEmbeddings  → EmbeddingsGenerated   (OpenAI embeddings API)
     *   Reranking             → Reranked              (Cohere reranking API)
     *   PromptingAgent        → AgentPrompted         (Anthropic/OpenAI generation)
     *
     * Each listener method logs structured data to storage/logs/ai-YYYY-MM-DD.log.
     * See AiObservabilityListener for the full list of logged fields.
     *
     * Additional events you can listen to (not wired here but available):
     *   - StoringFile / FileStored         (file upload to provider)
     *   - CreatingStore / StoreCreated     (vector store creation)
     *   - AddingFileToStore / FileAddedToStore
     *   - GeneratingImage / ImageGenerated
     */
    private function registerAiObservability(): void
    {
        $listener = new AiObservabilityListener;

        // Embedding stage: question embedding + chunk embedding during ingest
        Event::listen(GeneratingEmbeddings::class, [$listener, 'onGeneratingEmbeddings']);
        Event::listen(EmbeddingsGenerated::class, [$listener, 'onEmbeddingsGenerated']);

        // Reranking stage: Cohere rerank of 15 candidates → top 5
        Event::listen(Reranking::class, [$listener, 'onReranking']);
        Event::listen(Reranked::class, [$listener, 'onReranked']);

        // Generation stage: Anthropic/OpenAI LLM call
        Event::listen(PromptingAgent::class, [$listener, 'onPromptingAgent']);
        Event::listen(AgentPrompted::class, [$listener, 'onAgentPrompted']);
    }
}
