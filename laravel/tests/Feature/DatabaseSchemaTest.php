<?php

namespace Tests\Feature;

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
}
