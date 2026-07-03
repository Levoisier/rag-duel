<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-01 — enable pgvector. Must run before any migration that declares a
 * vector(N) column: the type simply doesn't exist until the extension does.
 * The pgvector/pgvector:pg16 image (and the postgresql-16-pgvector package)
 * ship the extension prebuilt, so this is pure CREATE EXTENSION.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // CASCADE would silently drop every vector column with it; stay strict
        // so a rollback ordering mistake surfaces instead of eating data.
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
