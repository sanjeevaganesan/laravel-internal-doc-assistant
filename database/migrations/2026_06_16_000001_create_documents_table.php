<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Documents Table Migration
|--------------------------------------------------------------------------
|
| This migration creates the 'documents' table that powers the pgvector-based
| RAG (Retrieval-Augmented Generation) pipeline.
|
| Key design decisions:
|
| 1. CHUNKING STRATEGY: Each row stores a ~800-token chunk of a larger document
|    (not the whole document). Chunking is done by the `documents:ingest` command.
|    This allows vector similarity to find precise sections rather than returning
|    an entire 10-page policy document as context.
|
| 2. 1536 DIMENSIONS: Matches the output of OpenAI's text-embedding-3-small model.
|    This number MUST match the model's output size — mixing dimensions causes a
|    pgvector runtime error.
|
| 3. HNSW INDEX: Hierarchical Navigable Small World (HNSW) is an approximate
|    nearest-neighbor algorithm. Unlike exact search (sequential scan), HNSW
|    searches millions of vectors in milliseconds with minimal accuracy loss.
|    The `vector_cosine_ops` operator class uses cosine similarity, which
|    measures the angle between vectors rather than their magnitude — ideal
|    for semantic similarity where direction matters more than scale.
|
| 4. SOURCE COLUMN: Stores the original file path (e.g. 'knowledge/runbook.md').
|    The agent uses this for citations: "According to [source], ..."
|
*/

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Ensures the pgvector PostgreSQL extension is enabled.
         *
         * pgvector adds native vector column types and similarity search
         * operators to Postgres. It must be installed on the database server
         * before this migration can succeed.
         *
         * The docker-compose.yml uses pgvector/pgvector:pg16 which has pgvector
         * pre-compiled. For a manual Postgres install, run:
         *   CREATE EXTENSION IF NOT EXISTS vector;
         *
         * Throws RuntimeException if the DB connection is not PostgreSQL.
         */
        Schema::ensureVectorExtensionExists();

        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Human-readable identifier — taken from the markdown filename.
            // Used by the agent to cite sources: "According to [title], ..."
            $table->string('title');

            // The actual chunk text (~800 tokens). This is what gets embedded
            // and what the LLM receives as context during generation.
            $table->longText('content');

            // Original file path relative to storage/app/knowledge/.
            // Stored for provenance — lets you trace which file a chunk came from.
            $table->string('source');

            // ──────────────────────────────────────────────────────────────
            // Vector column: 1536-dimensional float array
            // ──────────────────────────────────────────────────────────────
            //
            // text-embedding-3-small (OpenAI) outputs exactly 1536 floats
            // per embedding. Each float is a 4-byte float32, so each row
            // stores 1536 × 4 = 6,144 bytes of embedding data.
            //
            // The Eloquent model casts this to/from a PHP float[] array.
            $table->vector('embedding', 1536);

            // ──────────────────────────────────────────────────────────────
            // HNSW index with cosine similarity
            // ──────────────────────────────────────────────────────────────
            //
            // vectorIndex() creates:
            //   CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops);
            //
            // Why HNSW?
            //   - Faster than IVFFlat for small-to-medium datasets (< 1M rows)
            //   - No training step required (unlike IVFFlat which needs representative
            //     data before indexing)
            //   - The index is rebuilt automatically as rows are inserted
            //
            // Why cosine_ops?
            //   - Cosine similarity measures the ANGLE between vectors, not distance
            //   - Two embeddings with the same semantic meaning will have cosine
            //     similarity ≈ 1.0 regardless of their magnitude
            //   - This matches how OpenAI's embedding model produces vectors
            //   - whereVectorSimilarTo() uses cosine similarity by default
            $table->vectorIndex('embedding');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
