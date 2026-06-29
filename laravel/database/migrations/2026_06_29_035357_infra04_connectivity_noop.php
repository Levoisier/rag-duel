<?php

use Illuminate\Database\Migrations\Migration;

/**
 * INFRA-04 — deliberately no-op.
 *
 * Its only job is to prove the migration pipeline runs end-to-end against the
 * shared pgvector Postgres (pgsql driver + container DB), independent of the
 * schema the real Phase 2 migrations will add. Empty up()/down() means it
 * records cleanly and reverses with no side effects.
 */
return new class extends Migration
{
    public function up(): void
    {
        // intentionally empty — connectivity proof only.
    }

    public function down(): void
    {
        // intentionally empty.
    }
};
