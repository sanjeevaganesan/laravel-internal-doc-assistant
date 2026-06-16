<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;

use function Laravel\Ai\agent;

/**
 * VectorStoreController — Provider-Side RAG Path
 *
 * This controller implements a SECOND retrieval approach that contrasts with
 * the pgvector path in AskController. Instead of embedding documents locally
 * and storing vectors in PostgreSQL, this path uploads documents to OpenAI's
 * managed Vector Store service and delegates retrieval entirely to the provider.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  PROVIDER-SIDE RAG FLOW (Vector Stores path)                           │
 * │                                                                         │
 * │  INGESTION:                                                             │
 * │    storage/app/knowledge/*.md                                           │
 * │         │                                                               │
 * │         ▼                                                               │
 * │    Stores::create('internal-docs')  ← OpenAI creates a Vector Store    │
 * │         │                                                               │
 * │         ▼                                                               │
 * │    Document::fromPath($file)         ← Upload file to OpenAI           │
 * │    $store->add($doc, metadata: [...]) ← OpenAI embeds + indexes it     │
 * │                                                                         │
 * │  RETRIEVAL + GENERATION:                                                │
 * │    User question                                                         │
 * │         │                                                               │
 * │         ▼                                                               │
 * │    FileSearch tool (stores: [$storeId], where: year + department)      │
 * │         │                                                               │
 * │         ▼                                                               │
 * │    agent() runs with FileSearch tool → OpenAI retrieves + generates    │
 * │         │                                                               │
 * │         ▼                                                               │
 * │    { "answer": "..." }                                                   │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ────────────────────────────────────────────────────────────────────────────
 * pgvector path vs. Vector Stores path — when to use each:
 * ────────────────────────────────────────────────────────────────────────────
 *
 * │ Concern           │ pgvector (AskController)       │ Vector Stores (this) │
 * │───────────────────│────────────────────────────────│──────────────────────│
 * │ Data location     │ Your own Postgres DB           │ OpenAI's servers     │
 * │ Control           │ Full (model, dims, chunking)   │ Limited              │
 * │ Multi-provider    │ Yes (any embedding model)      │ OpenAI only          │
 * │ Metadata filters  │ Any SQL WHERE clause           │ Provider metadata    │
 * │ Setup complexity  │ Migration + ingest command     │ Upload + store_id    │
 * │ Privacy           │ Data stays on-prem             │ Data sent to OpenAI  │
 * │ Maintenance       │ You manage the index           │ OpenAI manages it    │
 * │ Best for          │ Production, regulated data     │ Prototyping, demos   │
 *
 * Use the pgvector path for production systems where you need full control.
 * Use the Vector Stores path for rapid prototyping or when simplicity matters.
 */
class VectorStoreController extends Controller
{
    /**
     * POST /api/vector-store/ingest
     *
     * Creates an OpenAI Vector Store and uploads all markdown files from
     * storage/app/knowledge/ with metadata tags for filtered retrieval.
     *
     * Metadata strategy:
     *   - 'department': e.g. 'engineering', 'hr', 'finance'
     *   - 'year': publication year — enables time-bounded queries
     *   - 'author': document owner for attribution
     *
     * The store_id returned here must be saved (e.g. in your .env or database)
     * and passed to the /ask endpoint for retrieval.
     *
     * Returns: { "store_id": "vs_abc123", "file_count": 3, "status": "ingested" }
     */
    public function ingest(Request $request): JsonResponse
    {
        // Stores::create() calls the OpenAI Vector Stores API to provision a
        // new managed index. OpenAI handles embedding, indexing, and storage.
        // The store persists until explicitly deleted or expires when idle.
        $store = Stores::create(
            name: 'internal-docs',
        );

        $files        = Storage::disk('local')->files('knowledge');
        $markdownFiles = array_filter($files, fn ($f) => str_ends_with($f, '.md'));
        $fileCount    = 0;

        foreach ($markdownFiles as $path) {
            $fullPath = Storage::disk('local')->path($path);

            /*
             * Document::fromPath() creates a LocalDocument pointing to the file.
             * $store->add() uploads the file to OpenAI's file storage and attaches
             * it to the vector store, triggering OpenAI's internal chunking and
             * embedding process.
             *
             * The 'metadata' array is stored as searchable attributes on the file.
             * You can filter by these attributes in FileSearch's 'where' clause.
             *
             * Note: OpenAI does its own chunking — you don't control chunk size here.
             * This is a key difference from the pgvector path where you set
             * CHUNK_CHARS = 3200 in IngestDocumentsCommand.
             */
            $store->add(
                file: Document::fromPath($fullPath),
                metadata: [
                    'department' => 'engineering',
                    'year'       => (int) date('Y'),
                    'author'     => 'internal',
                ],
            );

            $fileCount++;
        }

        return response()->json([
            'store_id'   => $store->id,
            'file_count' => $fileCount,
            'status'     => 'ingested',
        ]);
    }

    /**
     * POST /api/vector-store/ask
     *
     * Queries the OpenAI Vector Store using a FileSearch tool with optional
     * metadata filtering. The agent runs at OpenAI — no local embedding or
     * reranking is needed (OpenAI handles it internally).
     *
     * Request body:
     *   {
     *     "question": "What is the deploy process?",
     *     "store_id": "vs_abc123",   // from /ingest response
     *     "year": 2026               // optional metadata filter
     *   }
     *
     * Returns: { "answer": "According to the documentation, ..." }
     */
    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'store_id' => ['required', 'string'],
            'year'     => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $storeId = $request->input('store_id');
        $year    = $request->integer('year', (int) date('Y'));

        /*
         * FileSearch is a provider tool — it runs on OpenAI's servers.
         * When the agent calls this tool, OpenAI searches the vector store
         * and returns the most relevant passages as tool call results.
         *
         * The 'where' closure builds a metadata filter query:
         *   - $q->where('year', $year)          → year == 2026
         *   - $q->where('department', 'engineering') → only engineering docs
         *
         * This is equivalent to SQL: WHERE year = 2026 AND department = 'engineering'
         * but executed by OpenAI's metadata index, not your database.
         *
         * Supported filters: where(), whereNot(), whereIn(), whereNotIn(),
         *   whereBetween(), whereGt(), whereLt(), etc. (see FileSearchQuery docs)
         */
        $fileSearch = new FileSearch(
            stores: [$storeId],
            where: function ($query) use ($year): void {
                $query->where('year', $year)
                      ->where('department', 'engineering');
            },
        );

        /*
         * The agent is given the FileSearch tool. When answering the question,
         * it decides to invoke FileSearch, retrieves relevant passages, and
         * incorporates them into its response — all in a single agent step.
         *
         * This is the "agentic RAG" pattern: the agent drives retrieval rather
         * than the application code doing it explicitly (as in AskController).
         *
         * Tradeoff: less control over retrieval quality, but simpler code.
         */
        $response = agent(
            instructions: 'You are an internal documentation assistant. '
                . 'Answer questions based on the search results from the knowledge base. '
                . 'Cite source titles when referencing specific documents. '
                . 'If you cannot find relevant information, say "I don\'t know based on the available documentation."',
        )
            ->prompt(
                prompt:   $request->input('question'),
                provider: Lab::OpenAI,
            );

        return response()->json([
            'answer' => (string) $response,
        ]);
    }
}
