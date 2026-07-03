<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-06 — append-only log of every query run, one row per engine per question.
 * Phase 7 (METRIC-02) writes here; the runs history page (EXT-04) reads it.
 * Model fields are denormalized copies of the contract values at run time so
 * old rows stay honest if the contract ever changes (EXT-06 re-benchmarks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 8);
            $table->text('question');
            // CONTRACT-03 payload as-is: {extract_ms, chunk_ms, embed_ms,
            // retrieve_ms, ttft_ms, total_ms, loc}. jsonb, not json — Phase 7
            // aggregates (medians per phase) need the binary operators.
            $table->jsonb('timings');
            $table->unsignedSmallInteger('top_k');
            $table->string('embedding_model');
            $table->string('llm_model');
            // Append-only log: created_at only, no updated_at to lie with.
            $table->timestamp('created_at')->useCurrent();
        });

        // Only the two duelists may write here; a typo'd engine label would
        // silently vanish from every per-engine aggregate.
        DB::statement("ALTER TABLE runs ADD CONSTRAINT runs_engine_check CHECK (engine IN ('php', 'py'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
