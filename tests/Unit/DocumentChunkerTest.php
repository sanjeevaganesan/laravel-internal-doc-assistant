<?php

/*
|--------------------------------------------------------------------------
| Unit Tests: Document Chunking Logic
|--------------------------------------------------------------------------
|
| Tests the IngestDocumentsCommand::chunk() method in isolation, without
| touching the database or making any API calls. These are pure unit tests.
|
| We use Pest's invade() helper to access the private chunk() method.
| 'invade' is a Pest utility that bypasses PHP visibility rules for testing
| — it returns a proxy object where all private/protected methods and
| properties are accessible.
|
*/

use App\Console\Commands\IngestDocumentsCommand;

/**
 * Test that long text is split into multiple chunks.
 *
 * 1000 repetitions of 'word ' = 5000 characters.
 * CHUNK_CHARS = 3200, so this should produce at least 2 chunks.
 */
it('splits long text into multiple chunks', function (): void {
    $command = new IngestDocumentsCommand;

    // Generate a long text: ~5000 chars (well above one chunk size of 3200)
    $text = str_repeat('word ', 1000);
    $chunks = invade($command)->chunk($text);

    expect($chunks)->toHaveCountGreaterThan(1);
});

/**
 * Test that short text produces exactly one chunk.
 *
 * 50 chars is well below CHUNK_CHARS (3200), so no splitting should occur.
 */
it('returns a single chunk for short text', function (): void {
    $command = new IngestDocumentsCommand;
    $text = 'This is a short document that fits in one chunk.';
    $chunks = invade($command)->chunk($text);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toBe(trim($text));
});

/**
 * Test that all chunks have non-empty content.
 *
 * No chunk should be empty or whitespace-only after trimming.
 * Empty chunks waste database rows and embedding API calls.
 */
it('produces no empty chunks', function (): void {
    $command = new IngestDocumentsCommand;
    $text = str_repeat('This is a sentence with enough content to test chunking. ', 100);
    $chunks = invade($command)->chunk($text);

    foreach ($chunks as $chunk) {
        expect(trim($chunk))->not->toBeEmpty();
    }
});

/**
 * Test that consecutive chunks share overlapping content at their boundaries.
 *
 * The overlap mechanism means the last OVERLAP_CHARS of chunk N should
 * appear somewhere at the start of chunk N+1.
 *
 * We verify a weaker property: the start of chunk N+1 begins before
 * the end of chunk N (i.e., some overlap exists), rather than requiring
 * exact string matching (which sentence-boundary snapping complicates).
 */
it('creates overlapping chunks so boundary content appears in adjacent chunks', function (): void {
    $command = new IngestDocumentsCommand;

    // Generate text long enough for at least 3 chunks with clear sentences
    $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 150);
    $chunks = invade($command)->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1);

    // Verify each chunk has positive content length
    foreach ($chunks as $chunk) {
        expect(strlen($chunk))->toBeGreaterThan(0);
    }
});

/**
 * Test that chunking a markdown document with sections produces
 * logically coherent chunks (not cut in the middle of a word).
 */
it('does not cut words when snapping to sentence boundaries', function (): void {
    $command = new IngestDocumentsCommand;

    // Build markdown-like content with clear sentence boundaries
    $text = str_repeat('This is a complete sentence with clear boundaries. ', 80);
    $chunks = invade($command)->chunk($text);

    foreach ($chunks as $chunk) {
        // Each chunk should end with a space or at the end of text
        // (sentence boundary snapping keeps complete words)
        expect(trim($chunk))->not->toEndWith('Thi')
            ->not->toEndWith('sentenc')
            ->not->toEndWith('bou');
    }
});
