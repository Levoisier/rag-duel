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
}
