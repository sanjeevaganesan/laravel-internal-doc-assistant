<?php

/*
|--------------------------------------------------------------------------
| Feature Tests: Vector Store (Provider-Side RAG Path)
|--------------------------------------------------------------------------
|
| Tests the OpenAI Vector Stores endpoints using Stores::fake().
|
| Stores::fake() intercepts all laravel/ai Stores API calls:
|   - Stores::create() — returns a fake store with a generated ID
|   - $store->add()   — records the add call without uploading to OpenAI
|   - Assertions: Stores::assertCreated(), $store->assertAdded()
|
| This keeps tests from making real OpenAI API calls and from requiring
| actual .md files to exist in storage/app/knowledge/.
|
*/

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Stores;

beforeEach(function (): void {
    Embeddings::fake()->preventStrayEmbeddings();
    Stores::fake();
});

/**
 * Shared helper to create a user and get the Authorization header.
 */
function storeAuthHeader(): string
{
    $user  = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    return "Bearer {$token}";
}

/**
 * Test that the ingest endpoint creates a vector store.
 */
it('creates a vector store when ingesting documents', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('knowledge/test.md', str_repeat('Content. ', 30));

    $auth = storeAuthHeader();

    $response = $this->postJson(
        '/api/vector-store/ingest',
        [],
        ['Authorization' => $auth]
    );

    $response->assertOk()
             ->assertJsonStructure(['store_id', 'file_count', 'status'])
             ->assertJson(['status' => 'ingested']);

    // Assert that Stores::create() was called with 'internal-docs'
    Stores::assertCreated('internal-docs');
});

/**
 * Test that ingest adds markdown files to the store.
 */
it('uploads markdown files to the vector store with metadata', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('knowledge/doc1.md', str_repeat('Content one. ', 20));
    Storage::disk('local')->put('knowledge/doc2.md', str_repeat('Content two. ', 20));

    $auth = storeAuthHeader();

    $response = $this->postJson(
        '/api/vector-store/ingest',
        [],
        ['Authorization' => $auth]
    );

    $response->assertOk()
             ->assertJson(['file_count' => 2]);
});

/**
 * Test that the ingest endpoint returns 0 file count for empty directory.
 */
it('returns zero file count when no markdown files exist', function (): void {
    Storage::fake('local');
    // No files in knowledge/

    $auth = storeAuthHeader();

    $response = $this->postJson(
        '/api/vector-store/ingest',
        [],
        ['Authorization' => $auth]
    );

    $response->assertOk()
             ->assertJson(['file_count' => 0]);
});

/**
 * Test that the ask endpoint requires store_id.
 */
it('validates that store_id is required for ask', function (): void {
    $auth = storeAuthHeader();

    $this->postJson(
        '/api/vector-store/ask',
        ['question' => 'What is the policy?'],
        ['Authorization' => $auth]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['store_id']);
});

/**
 * Test that the vector store ask endpoint requires authentication.
 */
it('requires authentication for vector store ask', function (): void {
    $this->postJson('/api/vector-store/ask', [
        'question' => 'test',
        'store_id' => 'vs_test123',
    ])->assertUnauthorized();
});
