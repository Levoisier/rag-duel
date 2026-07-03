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
