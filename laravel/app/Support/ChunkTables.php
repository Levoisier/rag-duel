<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One definition for both per-engine chunk tables (DB-03 / DB-04). php_chunks
 * and py_chunks are separate ON PURPOSE — each engine's ingestion is measured
 * independently — but their shape must be byte-for-byte identical or the
 * retrieval comparison is skewed. Sharing the builder makes drift impossible
 * instead of merely unlikely; SchemaParityTest asserts it against the live DB.
 */
final class ChunkTables
{
    public static function create(string $table): void
    {
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('document_id')->constrained()->cascadeOnDelete();
            $blueprint->text('content');
            // Dimension comes from the shared contract (CONTRACT-01), never a
            // literal — Gemini's dim is NOT OpenAI's 1536, per the backlog.
            $blueprint->vector('embedding', dimensions: Contract::int('EMBEDDING_DIM'));
            // Position of the chunk within its document; unique per document so
            // a re-run ingest bug can't interleave duplicate context.
            $blueprint->unsignedInteger('ordinal');
            // Informational estimate only — chunk boundaries are defined in
            // characters (see contract.env), not tokens.
            $blueprint->unsignedInteger('token_count');
            $blueprint->timestamps();

            $blueprint->unique(['document_id', 'ordinal']);
        });
    }
}
