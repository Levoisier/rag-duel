<?php

use App\Support\ChunkTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * DB-03 — the PHP engine's chunk store. Shape lives in ChunkTables so DB-04's
 * py_chunks cannot drift from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChunkTables::create('php_chunks');
    }

    public function down(): void
    {
        Schema::dropIfExists('php_chunks');
    }
};
