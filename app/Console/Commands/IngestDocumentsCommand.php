<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingestion Pipeline — Phase 1 of the RAG system
 *
 * This command reads markdown files from storage/app/knowledge/, splits
 * them into overlapping chunks, generates an embedding for each chunk,
 * and stores the chunks as Document rows in PostgreSQL.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RAG INGESTION FLOW                                                 │
 * │                                                                     │
 * │  storage/app/knowledge/*.md                                         │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  Read raw markdown text                                             │
 * │         │                                                           │
 * │         ▼                                                           │
 * │  Chunk (~800 tokens, 100-token overlap)                             │
 * │         │                                                           │
 * │         ├─────────────────────┐                                     │
 * │         ▼                     ▼                                     │
 * │    Chunk 1             Chunk 2  ...  Chunk N                        │
 * │         │                     │                                     │
 * │         ▼                     ▼                                     │
 * │  Str::toEmbeddings()   Str::toEmbeddings()  ← OpenAI API call      │
 * │  (cache: true)         (cache: true)         ← or cache hit        │
 * │         │                     │                                     │
 * │         ▼                     ▼                                     │
 * │  Document::create()    Document::create()                           │
 * │  (pgvector HNSW)       (pgvector HNSW)                             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Why chunk documents?
 *   LLMs have a context window limit (~200k tokens for Claude, but costs
 *   scale with tokens). Including an entire 10-page policy document in
 *   the prompt is wasteful. Chunking lets vector search retrieve only the
 *   most relevant sections (~800 tokens each), keeping prompts lean.
 *
 * Why 800 tokens with 100-token overlap?
 *   800 tokens is a sweet spot: large enough to contain a full topic
 *   (a procedure, a policy clause, a config section), small enough that
 *   the embedding captures focused semantics rather than a mix of topics.
 *   The 100-token overlap ensures that sentences split at chunk boundaries
 *   still appear in full in at least one chunk — preventing information loss.
 *
 * Run:
 *   php artisan documents:ingest           # Ingest new files (additive)
 *   php artisan documents:ingest --fresh   # Truncate first, then ingest
 */
class IngestDocumentsCommand extends Command
{
    protected $signature = 'documents:ingest
                            {--fresh : Truncate the documents table before ingesting (re-indexes everything)}';

    protected $description = 'Ingest markdown files from storage/app/knowledge/ into the pgvector document store';

    /**
     * Target chunk size in characters.
     * Approximation: 1 English token ≈ 4 characters.
     * 800 tokens × 4 chars/token = 3,200 chars per chunk.
     */
    private const CHUNK_CHARS = 3_200;

    /**
     * Overlap between consecutive chunks in characters.
     * 100 tokens × 4 chars/token = 400 chars of overlap.
     * Ensures sentences that straddle a chunk boundary appear in full
     * in at least one chunk.
     */
    private const OVERLAP_CHARS = 400;

    public function handle(): int
    {
        if ($this->option('fresh')) {
            Document::truncate();
            $this->info('Documents table truncated. Starting fresh ingest.');
        }

        // List all files in the local disk's knowledge/ directory.
        // The 'local' disk maps to storage/app/ — so 'knowledge/' → storage/app/knowledge/.
        $files = Storage::disk('local')->files('knowledge');

        $markdownFiles = array_filter($files, fn ($f) => str_ends_with($f, '.md'));

        if (empty($markdownFiles)) {
            $this->warn('No .md files found in storage/app/knowledge/.');
            $this->warn('Add markdown files and re-run: php artisan documents:ingest');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d markdown file(s). Starting ingestion...', count($markdownFiles)));

        foreach ($markdownFiles as $path) {
            $this->processFile($path);
        }

        $totalChunks = Document::count();
        $this->newLine();
        $this->info("✓ Ingestion complete. Total chunks in database: {$totalChunks}");

        return self::SUCCESS;
    }

    /**
     * Process a single markdown file: chunk it, embed each chunk, and persist.
     */
    private function processFile(string $path): void
    {
        $rawContent = Storage::disk('local')->get($path);

        // Use the filename (without extension) as the document title.
        // e.g. 'knowledge/engineering-runbook.md' → 'engineering-runbook'
        $title = pathinfo($path, PATHINFO_FILENAME);
        $chunks = $this->chunk($rawContent);

        $this->info("\n→ [{$title}] — {$path}");
        $this->info('  Split into '.count($chunks).' chunk(s)');

        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $index => $chunk) {
            /*
             * Str::of($chunk)->toEmbeddings(cache: true)
             *
             * This is a Stringable macro registered by AiServiceProvider.
             * It calls Embeddings::for([$chunk])->cache()->generate() internally,
             * using the provider configured under 'default_for_embeddings' in
             * config/ai.php (i.e., OpenAI text-embedding-3-small, 1536 dims).
             *
             * cache: true means:
             *   - The first call sends the text to the OpenAI Embeddings API.
             *   - The vector is stored in the Laravel cache (database driver, 30 days).
             *   - Subsequent calls with the same text return the cached vector
             *     instantly without an API call — saving cost and time on re-ingestion.
             *
             * Returns: float[] with exactly 1536 elements.
             */
            $embedding = Str::of($chunk)->toEmbeddings(cache: true);

            Document::create([
                'title' => $title,
                'content' => $chunk,
                'source' => $path,
                'embedding' => $embedding,
            ]);

            $bar->advance();
        }

        $bar->finish();
    }

    /**
     * Split a long text into overlapping fixed-size chunks.
     *
     * Algorithm:
     *   1. Start at position 0
     *   2. Take CHUNK_CHARS characters
     *   3. Walk backwards from the end of the slice to find the nearest
     *      sentence boundary ('. ') — prevents cutting mid-sentence
     *   4. Store that slice as a chunk
     *   5. Advance start by (chunk_length - OVERLAP_CHARS) to create overlap
     *   6. Repeat until end of text
     *
     * The overlap means the last ~100 tokens of chunk N also appear at
     * the start of chunk N+1. This prevents a question whose answer spans
     * a chunk boundary from being missed by vector search.
     *
     * @return string[] Non-empty chunks in document order.
     */
    public function chunk(string $text): array
    {
        $chunks = [];
        $length = strlen($text);
        $start = 0;

        while ($start < $length) {
            // Calculate the tentative end position for this chunk.
            $end = min($start + self::CHUNK_CHARS, $length);

            // If we're not at the end of the document, try to snap the chunk
            // boundary to the nearest preceding sentence end ('. ').
            // This avoids cutting words or sentences in half.
            if ($end < $length) {
                $slice = substr($text, $start, $end - $start);
                $boundary = strrpos($slice, '. ');

                if ($boundary !== false && $boundary > self::CHUNK_CHARS / 2) {
                    // Found a sentence boundary in the latter half of the slice.
                    // Include the period and space so the next chunk starts cleanly.
                    $end = $start + $boundary + 2;
                }
            }

            $chunk = trim(substr($text, $start, $end - $start));

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            // Advance start, subtracting the overlap so consecutive chunks share
            // OVERLAP_CHARS characters at their boundary.
            $start = max($start + 1, $end - self::OVERLAP_CHARS);
        }

        return $chunks;
    }
}
