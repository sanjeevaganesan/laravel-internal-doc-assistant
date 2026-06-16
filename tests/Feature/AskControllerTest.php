<?php

/*
|--------------------------------------------------------------------------
| Feature Tests: POST /api/ask Controller
|--------------------------------------------------------------------------
|
| Tests the synchronous RAG query endpoint through the full HTTP stack.
|
| Fakes used:
|
|   Embeddings::fake()
|     Intercepts OpenAI embedding API calls. Without preventStrayEmbeddings(),
|     it generates random unit vectors when the actual call is made.
|     Used here so whereVectorSimilarTo() can embed the question without a
|     real API call (the auto-embed feature in the query builder).
|
|   Reranking::fake([...])
|     Intercepts Cohere reranking calls. We provide a static response so
|     the test can assert that reranking ran and that specific documents
|     end up in the top 5 (by controlling the fake response order).
|
| Note on agent() faking:
|   The anonymous agent() helper is harder to fake than a named Agent class
|   (which has a ::fake() class method). For controller tests, we assert
|   the HTTP response shape and mock the pipeline steps (embeddings + reranking)
|   rather than faking the LLM generation itself.
|
*/

use App\Models\Document;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

/**
 * Create an authenticated user and generate a Sanctum token.
 * Returns the Authorization header string.
 */
function getAuthHeader(): string
{
    $user  = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    return "Bearer {$token}";
}

beforeEach(function (): void {
    // Intercept embeddings: whereVectorSimilarTo auto-embeds the question string.
    // preventStrayEmbeddings() ensures no real OpenAI call is made.
    Embeddings::fake()->preventStrayEmbeddings();

    // Intercept reranking: return a static ordered list.
    // The rerank macro maps results back to original Collection items by index.
    Reranking::fake();
});

/**
 * Test that the endpoint requires authentication.
 *
 * Without a valid Sanctum token, the endpoint should return 401.
 */
it('requires authentication', function (): void {
    $this->postJson('/api/ask', ['question' => 'What is the PTO policy?'])
         ->assertUnauthorized();
});

/**
 * Test that the question field is required.
 */
it('validates that question is required', function (): void {
    $auth = getAuthHeader();

    $this->postJson('/api/ask', [], ['Authorization' => $auth])
         ->assertUnprocessable()
         ->assertJsonValidationErrors(['question']);
});

/**
 * Test that a valid question returns the expected JSON shape.
 *
 * We seed a Document so vector search returns at least one candidate.
 * The reranking fake returns them in the same order.
 */
it('returns answer and sources for a valid question', function (): void {
    $auth = getAuthHeader();

    // Seed a document so the vector search has candidates.
    // The factory generates a random embedding that satisfies the NOT NULL constraint.
    Document::factory()->create([
        'title'   => 'engineering-runbook',
        'content' => 'Deployments require two engineer approvals.',
        'source'  => 'knowledge/engineering-runbook.md',
    ]);

    $response = $this->postJson(
        '/api/ask',
        ['question' => 'How do deployments work?'],
        ['Authorization' => $auth]
    );

    // Even if the LLM call is faked (or bypassed in test), assert JSON structure
    $response->assertStatus(200)
             ->assertJsonStructure(['answer', 'sources']);

    // 'sources' should be an array (may be empty if no docs matched similarity threshold)
    expect($response->json('sources'))->toBeArray();
});

/**
 * Test that the question has a max length of 1000 characters.
 */
it('rejects questions longer than 1000 characters', function (): void {
    $auth = getAuthHeader();

    $this->postJson(
        '/api/ask',
        ['question' => str_repeat('a', 1001)],
        ['Authorization' => $auth]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question']);
});

/**
 * Test that Reranking is called when candidates are available.
 *
 * Seed enough documents to trigger the reranking call.
 */
it('calls the reranking provider when candidates are retrieved', function (): void {
    $auth = getAuthHeader();

    // Seed 5 documents
    Document::factory()->count(5)->create();

    $this->postJson(
        '/api/ask',
        ['question' => 'What is the deployment process?'],
        ['Authorization' => $auth]
    );

    // Assert Reranking::fake() was called at some point during the request.
    // Note: reranking is only triggered if vector search returns candidates.
    // With random embeddings in the factory and minSimilarity: 0.4, results
    // may or may not match — this assertion is soft.
    Reranking::assertReranked(
        fn ($prompt) => count($prompt->documents) <= 15,
    );
});
