<?php

/*
|--------------------------------------------------------------------------
| Feature Tests: GET /api/ask/stream
|--------------------------------------------------------------------------
|
| Tests the streaming SSE endpoint. The endpoint returns a streamed response
| using the Vercel AI SDK data protocol.
|
| Testing streaming is tricky because the response body is a generator that
| yields tokens as the LLM produces them. In tests, the LLM is faked so
| the response resolves synchronously.
|
*/

use App\Models\Document;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

beforeEach(function (): void {
    Embeddings::fake()->preventStrayEmbeddings();
    Reranking::fake();
});

/**
 * Test that the stream endpoint requires authentication.
 */
it('requires authentication for streaming', function (): void {
    $this->get('/api/ask/stream?question=test')
         ->assertUnauthorized();
});

/**
 * Test that the stream endpoint validates the question parameter.
 */
it('validates the question query parameter', function (): void {
    $user  = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->get('/api/ask/stream', ['Authorization' => "Bearer {$token}"])
         ->assertUnprocessable();
});

/**
 * Test that a valid stream request returns a 200 response.
 *
 * The response is in SSE/Vercel data protocol format.
 * We assert the status code and that the response is received without error.
 */
it('returns a successful response for a valid streaming question', function (): void {
    $user  = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    Document::factory()->count(2)->create();

    $response = $this->get(
        '/api/ask/stream?question=What+is+the+PTO+policy',
        ['Authorization' => "Bearer {$token}"]
    );

    // The stream endpoint should return 200 (or start a stream)
    // In the test environment with faked providers, it resolves synchronously
    $response->assertStatus(200);
});
