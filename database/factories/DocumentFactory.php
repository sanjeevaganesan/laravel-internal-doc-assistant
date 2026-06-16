<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Document model instances in tests.
 *
 * Usage in Pest tests:
 *   Document::factory()->create()                        // one document
 *   Document::factory()->count(5)->create()              // five documents
 *   Document::factory()->create(['title' => 'runbook'])  // specific attribute
 *
 * The 'embedding' attribute generates a random 1536-dimensional unit vector.
 * In tests that use Embeddings::fake(), the actual values don't matter —
 * whereVectorSimilarTo() is evaluated by pgvector, not by fake logic.
 * The factory ensures the DB constraint (NOT NULL on embedding) is satisfied.
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title'     => $this->faker->words(3, true),
            'content'   => $this->faker->paragraphs(3, true),
            'source'    => 'knowledge/' . $this->faker->slug() . '.md',

            // Generate a random 1536-dimensional unit vector.
            // A real embedding is a meaningful float[] from OpenAI; this is just
            // random noise sufficient for satisfying the NOT NULL DB constraint.
            // Tests that actually exercise vector search should seed real embeddings
            // or use Embeddings::fakeEmbedding(1536).
            'embedding' => $this->fakeEmbedding(1536),
        ];
    }

    /**
     * Generate a random normalized 1536-dimensional float vector.
     *
     * @return float[]
     */
    private function fakeEmbedding(int $dimensions): array
    {
        $vector = array_map(fn () => $this->faker->randomFloat(6, -1, 1), range(1, $dimensions));

        // Normalize to unit length so cosine similarity values are in [-1, 1].
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v ** 2, $vector)));

        return array_map(fn ($v) => $magnitude > 0 ? $v / $magnitude : 0.0, $vector);
    }
}
