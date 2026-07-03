<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-02 — uploaded source documents. One row per upload; BOTH engines chunk
 * from the same row (their chunks land in php_chunks / py_chunks separately),
 * so ingestion is measured per engine against identical input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('mime', 127);
            $table->unsignedBigInteger('byte_size');
            // Lifecycle: pending → ready | failed, flipped by the ingest jobs
            // (PHP-04 / PY-01). Indexed because the UI polls on it (UI-02).
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
