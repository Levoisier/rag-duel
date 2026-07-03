<?php

namespace Tests\Feature;

use App\Support\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 ACs, proven against a real Postgres (the suite runs on pgsql —
 * see phpunit.xml — because none of this is observable on sqlite).
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_db01_vector_extension_is_enabled(): void
    {
        $ext = DB::select("SELECT extname FROM pg_extension WHERE extname = 'vector'");

        $this->assertCount(1, $ext, 'pgvector extension is not enabled (DB-01).');
    }

    public function test_db02_documents_table_exists_with_lifecycle_default(): void
    {
        $id = DB::table('documents')->insertGetId([
            'filename' => 'fixture.pdf',
            'mime' => 'application/pdf',
            'byte_size' => 1234,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // status must default to 'pending' — the ingest jobs flip it later.
        $this->assertSame('pending', DB::table('documents')->find($id)->status);
    }

    public function test_db03_chunk_embedding_dimension_matches_contract(): void
    {
        // Fairness: the column dim must be the contract's Gemini dim, not a
        // guessed 1536. format_type renders e.g. "vector(768)".
        $expected = sprintf('vector(%d)', Contract::int('EMBEDDING_DIM'));

        foreach (['php_chunks', 'py_chunks'] as $table) {
            $type = DB::selectOne(
                "SELECT format_type(atttypid, atttypmod) AS type
                 FROM pg_attribute
                 WHERE attrelid = ?::regclass AND attname = 'embedding'",
                [$table]
            )->type;

            $this->assertSame($expected, $type, "{$table}.embedding dim drifted from the contract.");
        }
    }

    public function test_db04_chunk_tables_have_identical_shape(): void
    {
        // Fairness: py_chunks must equal php_chunks column-for-column. Compare
        // live catalog data, not our own migration code, so any manual ALTER
        // also gets caught.
        $this->assertSame(
            $this->normalizedShape('php_chunks'),
            $this->normalizedShape('py_chunks'),
            'php_chunks and py_chunks shapes drifted apart (DB-04).'
        );
    }

    public function test_db05_cosine_query_uses_hnsw_index(): void
    {
        // Tiny tables make the planner prefer a seq scan even with a perfectly
        // good index, so force its hand for the plan inspection only.
        DB::statement('SET LOCAL enable_seqscan = off');

        $zero = '['.implode(',', array_fill(0, Contract::int('EMBEDDING_DIM'), 0)).']';

        foreach (['php_chunks', 'py_chunks'] as $table) {
            $plan = collect(DB::select(
                "EXPLAIN SELECT id FROM {$table} ORDER BY embedding <=> ?::vector LIMIT ?",
                [$zero, Contract::int('TOP_K')]
            ))->pluck('QUERY PLAN')->implode("\n");

            $this->assertStringContainsString(
                "{$table}_embedding_hnsw_cosine_idx",
                $plan,
                "Cosine top-K on {$table} does not use the HNSW index (DB-05)."
            );
        }
    }

    public function test_db06_runs_table_accepts_timings_and_rejects_unknown_engines(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'filename' => 'fixture.txt',
            'mime' => 'text/plain',
            'byte_size' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = [
            'document_id' => $documentId,
            'engine' => 'php',
            'question' => 'What is this document about?',
            // CONTRACT-03 shape — the exact keys Phase 7 will write.
            'timings' => json_encode([
                'extract_ms' => 12.3, 'chunk_ms' => 4.5, 'embed_ms' => 210.0,
                'retrieve_ms' => 8.9, 'ttft_ms' => 350.1, 'total_ms' => 585.8,
                'loc' => 812,
            ]),
            'top_k' => Contract::int('TOP_K'),
            'embedding_model' => Contract::get('EMBEDDING_MODEL'),
            'llm_model' => Contract::get('LLM_MODEL'),
        ];

        DB::table('runs')->insert($row);
        $this->assertSame(1, DB::table('runs')->count());

        // Anything but php|py must bounce off the check constraint.
        $this->expectExceptionMessage('runs_engine_check');
        DB::table('runs')->insert(['engine' => 'node'] + $row);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizedShape(string $table): array
    {
        $columns = DB::select(
            'SELECT column_name, data_type, udt_name, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
             ORDER BY ordinal_position',
            [$table]
        );

        return array_map(function (object $column) use ($table): array {
            $shape = (array) $column;
            // The id default embeds the table-specific sequence name
            // (php_chunks_id_seq vs py_chunks_id_seq) — neutralize it so only
            // real shape differences can fail the comparison.
            $shape['column_default'] = $shape['column_default'] === null
                ? null
                : str_replace($table, '<chunks>', $shape['column_default']);

            return $shape;
        }, $columns);
    }
}
