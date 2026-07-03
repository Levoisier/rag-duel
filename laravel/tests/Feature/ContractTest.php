<?php

namespace Tests\Feature;

use App\Support\Contract;
use RuntimeException;
use Tests\TestCase;

/**
 * CONTRACT-01 — the shared contract file exists, carries every locked key, and
 * the PHP side reads it through the one sanctioned loader.
 */
class ContractTest extends TestCase
{
    /** Every parameter the backlog says must be identical across engines. */
    private const REQUIRED_KEYS = [
        'AI_PROVIDER_PRIMARY',
        'AI_PROVIDER_FALLBACK',
        'EMBEDDING_MODEL',
        'EMBEDDING_DIM',
        'LLM_MODEL',
        'LLM_TEMPERATURE',
        'LLM_MAX_TOKENS',
        'TOP_K',
        'CHUNK_SIZE',
        'CHUNK_OVERLAP',
        'SIMILARITY_METRIC',
    ];

    public function test_contract_file_defines_every_locked_parameter(): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertNotSame('', Contract::get($key), "Contract key [{$key}] is empty.");
        }
    }

    public function test_embedding_dimension_is_indexable_by_pgvector(): void
    {
        // pgvector's HNSW/IVFFlat indexes refuse >2000 dims; a dim above that
        // would silently break DB-05. Guard the ceiling here, at the source.
        $dim = Contract::int('EMBEDDING_DIM');

        $this->assertGreaterThan(0, $dim);
        $this->assertLessThanOrEqual(2000, $dim);
    }

    public function test_locked_invariants_hold(): void
    {
        // These specific values are decisions the backlog locks project-wide.
        $this->assertSame('cosine', Contract::get('SIMILARITY_METRIC'));
        $this->assertSame('gemini', Contract::get('AI_PROVIDER_PRIMARY'));
        $this->assertSame('groq', Contract::get('AI_PROVIDER_FALLBACK'));

        // Overlap must be smaller than the chunk itself or chunking can't advance.
        $this->assertLessThan(Contract::int('CHUNK_SIZE'), Contract::int('CHUNK_OVERLAP'));

        $this->assertIsFloat(Contract::float('LLM_TEMPERATURE'));
    }

    public function test_missing_key_fails_hard_instead_of_defaulting(): void
    {
        // Silent defaults are how engines drift apart — the loader must throw.
        $this->expectException(RuntimeException::class);

        Contract::get('NOPE_NOT_A_CONTRACT_KEY');
    }
}
