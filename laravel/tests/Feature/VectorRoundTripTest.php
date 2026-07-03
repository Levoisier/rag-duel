<?php

namespace Tests\Feature;

use App\Support\Contract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Vector;
use Tests\TestCase;

/**
 * DB-07 — the pgvector-php binding writes and reads a vector unchanged
 * through Eloquent's cast layer (the path PHP-04/PHP-05 will use).
 */
class VectorRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_vector_round_trips_unchanged_through_the_php_binding(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'filename' => 'fixture.txt',
            'mime' => 'text/plain',
            'byte_size' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Throwaway model: Phase 2 is schema-only, the real Chunk model lands
        // with the engine (Phase 4). The cast is what's under test here.
        $chunk = new class extends Model
        {
            protected $table = 'php_chunks';

            protected $guarded = [];

            protected $casts = ['embedding' => Vector::class];
        };

        // pgvector stores float4. Use values exactly representable in single
        // precision so "unchanged" can be asserted with strict equality
        // instead of a tolerance that could mask a real truncation bug.
        $dim = Contract::int('EMBEDDING_DIM');
        $embedding = array_map(
            fn (int $i): float => (($i % 7) - 3) * 0.25,
            range(1, $dim)
        );

        $chunk->create([
            'document_id' => $documentId,
            'content' => 'round-trip fixture',
            'embedding' => $embedding,
            'ordinal' => 0,
            'token_count' => 3,
        ]);

        $stored = $chunk->firstOrFail()->embedding;

        $this->assertInstanceOf(Vector::class, $stored);

        // pgvector-php hands integral components back as PHP ints (0, not
        // 0.0). Numerically identical, so normalize the types and keep the
        // strict value comparison.
        $this->assertSame($embedding, array_map(floatval(...), $stored->toArray()), 'Vector came back altered (DB-07).');
    }
}
