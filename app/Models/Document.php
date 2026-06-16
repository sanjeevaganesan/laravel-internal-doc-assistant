<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a single chunk of an ingested documentation file.
 *
 * The `documents:ingest` artisan command reads markdown files from
 * storage/app/knowledge/, splits them into ~800-token chunks, generates
 * an embedding for each chunk via OpenAI's text-embedding-3-small model,
 * and stores each chunk as a separate Document row.
 *
 * Why one row per chunk (not per document)?
 *   Vector similarity search returns the single most relevant section,
 *   not an entire document. Chunking ensures the retrieved context is
 *   precisely targeted rather than dumping a 10-page runbook into the
 *   LLM prompt, which wastes tokens and degrades answer quality.
 *
 * Retrieval flow:
 *   1. User question → embed with OpenAI → query vector
 *   2. Document::whereVectorSimilarTo('embedding', $question, minSimilarity: 0.4)
 *      returns up to 15 candidates using pgvector cosine similarity
 *   3. Collection::rerank() with Cohere narrows to top 5
 *   4. Top 5 chunk texts are injected into the Anthropic agent prompt as context
 *
 * @property int    $id
 * @property string $title      Filename without extension (e.g. "engineering-runbook")
 * @property string $content    The raw chunk text (~800 tokens) sent to the LLM as context
 * @property string $source     Original file path (e.g. "knowledge/engineering-runbook.md")
 * @property array  $embedding  1536-dimensional float vector (cast from pgvector binary)
 */
class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * All four content fields are filled by IngestDocumentsCommand::handle().
     * The 'embedding' is populated with the float[] returned by
     * Str::of($chunk)->toEmbeddings(cache: true).
     */
    protected $fillable = [
        'title',
        'content',
        'source',
        'embedding',
    ];

    /**
     * Attribute casts.
     *
     * The 'embedding' column is a native pgvector type in PostgreSQL.
     * Laravel reads it as a string representation ("[0.123,0.456,...]").
     * Casting to 'array' converts that string to a PHP float[] on retrieval
     * and back to a JSON-encoded string on storage.
     *
     * This cast is required for:
     *   - Storing embeddings via Document::create(['embedding' => $floatArray])
     *   - Reading embeddings back with $document->embedding (returns float[])
     */
    protected $casts = [
        'embedding' => 'array',
    ];
}
