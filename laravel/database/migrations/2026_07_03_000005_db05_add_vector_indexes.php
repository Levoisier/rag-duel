<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-05 — ANN indexes for cosine retrieval on both chunk tables.
 *
 * HNSW over IVFFlat: IVFFlat trains its lists from rows present at CREATE
 * INDEX time, and these tables are empty at migration time — an IVFFlat index
 * built now would have degenerate recall. HNSW builds incrementally with no
 * training step (pgvector ≥ 0.5), which fits migrate-then-ingest. Same index
 * type on both tables, obviously, or retrieval timings aren't comparable.
 *
 * vector_cosine_ops matches SIMILARITY_METRIC=cosine in the shared contract:
 * the planner only uses the index when the query's operator (`<=>`) belongs
 * to the index's opclass.
 */
return new class extends Migration
{
    private const TABLES = ['php_chunks', 'py_chunks'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement(
                "CREATE INDEX {$table}_embedding_hnsw_cosine_idx
                 ON {$table} USING hnsw (embedding vector_cosine_ops)"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_embedding_hnsw_cosine_idx");
        }
    }
};
