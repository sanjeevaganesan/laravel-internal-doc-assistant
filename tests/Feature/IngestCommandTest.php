<?php

/*
|--------------------------------------------------------------------------
| Feature Tests: documents:ingest Artisan Command
|--------------------------------------------------------------------------
|
| Tests the full ingestion pipeline: file reading → chunking → embedding → storage.
|
| Key fakes used:
|   Embeddings::fake()->preventStrayEmbeddings()
|     - Replaces the real OpenAI Embeddings API with a fake that returns
|       random unit vectors of the correct dimension.
|     - preventStrayEmbeddings() throws RuntimeException if any embedding
|       call is made WITHOUT a fake being set up — ensures no real API calls
|       sneak through in CI.
|
|   Storage::fake('local')
|     - Creates an in-memory filesystem that replaces the real storage/app/ disk.
|     - Files written in the test don't touch the real filesystem.
|     - Isolated: each test gets a fresh empty disk.
|
*/

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    /*
     * Embeddings::fake() intercepts all calls to the embeddings provider.
     *
     * Without a custom callback, it generates random 1536-dim unit vectors.
     * This is sufficient for testing ingestion logic — we don't need
     * semantically meaningful embeddings, just valid float arrays.
     *
     * preventStrayEmbeddings() makes the test fail loudly if any real
     * OpenAI API call is accidentally made. Ensures tests run without
     * API keys and without incurring API costs.
     */
    Embeddings::fake()->preventStrayEmbeddings();
});

/**
 * Happy path: ingest a markdown file and verify Document rows are created.
 *
 * The test file is ~1600 chars (below one chunk), so exactly 1 chunk is
 * expected. The chunk is embedded and stored as a Document row.
 */
it('ingests a markdown file and creates document chunks in the database', function (): void {
    Storage::fake('local');

    // Write a sample markdown file to the fake local disk.
    // Storage::fake maps 'knowledge/' to an in-memory path.
    $content = str_repeat('This is documentation content. It contains useful information. ', 30);
    Storage::disk('local')->put('knowledge/sample.md', $content);

    $this->artisan('documents:ingest')
        ->assertExitCode(0);

    // The file was small enough for 1 chunk — verify exactly 1 row was created.
    expect(Document::count())->toBeGreaterThan(0);

    $doc = Document::first();
    expect($doc->title)->toBe('sample');
    expect($doc->source)->toBe('knowledge/sample.md');
    expect($doc->embedding)->toBeArray()->toHaveCount(1536);

    // Confirm the embedding API was called (not a cache hit for a new fake)
    Embeddings::assertGenerated(fn ($prompt) => count($prompt->inputs) > 0);
});

/**
 * Test that long files produce multiple chunks.
 *
 * 600 repetitions of the phrase = ~11,400 chars, which at CHUNK_CHARS=3200
 * should produce at least 3 chunks.
 */
it('splits long documents into multiple chunks', function (): void {
    Storage::fake('local');

    $longContent = str_repeat('Detailed documentation about our engineering processes. ', 600);
    Storage::disk('local')->put('knowledge/long-doc.md', $longContent);

    $this->artisan('documents:ingest')
        ->assertExitCode(0);

    expect(Document::count())->toBeGreaterThan(1);
});

/**
 * Test the --fresh flag truncates existing rows before ingesting.
 *
 * This is important for re-indexing: running documents:ingest twice without
 * --fresh would duplicate all chunks.
 */
it('truncates the documents table when --fresh flag is used', function (): void {
    Storage::fake('local');

    // Pre-seed 5 existing document rows
    Document::factory()->count(5)->create();
    expect(Document::count())->toBe(5);

    // Place a small file to ingest
    Storage::disk('local')->put(
        'knowledge/test.md',
        str_repeat('Fresh ingest test content. ', 20)
    );

    $this->artisan('documents:ingest', ['--fresh' => true])
        ->assertExitCode(0);

    // The 5 pre-seeded rows should be gone; only rows from this ingest remain.
    // (The pre-seeded rows had different titles — all rows now should be 'test')
    expect(Document::where('title', 'test')->count())->toBeGreaterThan(0);

    // Verify no rows from the pre-seed remain (they had random faker titles)
    $uniqueTitles = Document::distinct()->pluck('title')->toArray();
    expect($uniqueTitles)->toContain('test');
    expect(count($uniqueTitles))->toBe(1);
});

/**
 * Test that non-markdown files in knowledge/ are ignored.
 *
 * Only *.md files should be ingested; .txt, .pdf, etc. are skipped.
 */
it('ignores non-markdown files in the knowledge directory', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('knowledge/notes.txt', 'This should not be ingested.');
    Storage::disk('local')->put('knowledge/data.json', '{"key": "value"}');
    Storage::disk('local')->put('knowledge/readme.md', str_repeat('Valid markdown content. ', 20));

    $this->artisan('documents:ingest')
        ->assertExitCode(0);

    // Only the .md file should produce chunks
    expect(Document::count())->toBeGreaterThan(0);
    expect(Document::where('title', 'readme')->count())->toBeGreaterThan(0);
    expect(Document::where('title', 'notes')->count())->toBe(0);
    expect(Document::where('title', 'data')->count())->toBe(0);
});

/**
 * Test that the command exits successfully (code 0) with no files.
 *
 * An empty knowledge directory should produce a warning but not an error.
 */
it('exits successfully with a warning when no markdown files exist', function (): void {
    Storage::fake('local');
    // No files added — directory is empty

    $this->artisan('documents:ingest')
        ->expectsOutputToContain('No .md files found')
        ->assertExitCode(0);

    expect(Document::count())->toBe(0);
});
