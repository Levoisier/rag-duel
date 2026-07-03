<?php

use App\Support\ChunkTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * DB-04 — the Python engine's chunk store. Identical to php_chunks by
 * construction (same ChunkTables builder); kept separate so each engine's
 * ingestion is measured independently. Do not merge the two tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChunkTables::create('py_chunks');
    }

    public function down(): void
    {
        Schema::dropIfExists('py_chunks');
    }
};
