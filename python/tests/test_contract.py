"""CONTRACT-01/03 — the Python side reads the shared contract through one loader
and agrees with PHP on every locked value.

The parity that matters: both engines parse the *same* infra/contract.env. The
canonical expectations asserted here are byte-for-byte the ones asserted in
laravel/tests/Feature/ContractTest.php, so a drift on either side turns a test
red rather than silently unfairening the comparison.
"""

import pytest

from app import contract

# Every parameter the backlog says must be identical across engines (CONTRACT-01).
REQUIRED_KEYS = [
    "AI_PROVIDER_PRIMARY",
    "AI_PROVIDER_FALLBACK",
    "EMBEDDING_MODEL",
    "EMBEDDING_DIM",
    "LLM_MODEL",
    "LLM_TEMPERATURE",
    "LLM_MAX_TOKENS",
    "TOP_K",
    "CHUNK_SIZE",
    "CHUNK_OVERLAP",
    "SIMILARITY_METRIC",
]


def test_contract_defines_every_locked_parameter():
    for key in REQUIRED_KEYS:
        assert contract.get(key) != "", f"Contract key [{key}] is empty."


def test_embedding_dimension_is_indexable_by_pgvector():
    # pgvector's HNSW/IVFFlat indexes refuse >2000 dims; guard the ceiling at the
    # source, mirroring the PHP test.
    dim = contract.get_int("EMBEDDING_DIM")
    assert 0 < dim <= 2000


def test_locked_invariants_hold():
    assert contract.get("SIMILARITY_METRIC") == "cosine"
    assert contract.get("AI_PROVIDER_PRIMARY") == "gemini"
    assert contract.get("AI_PROVIDER_FALLBACK") == "groq"
    # Overlap must be smaller than the chunk or chunking can't advance.
    assert contract.get_int("CHUNK_OVERLAP") < contract.get_int("CHUNK_SIZE")


def test_timings_keys_match_the_canonical_contract03_set():
    # CONTRACT-03: identical keys/units on both sides — same file, same order.
    assert contract.get_list("TIMINGS_KEYS") == [
        "extract_ms",
        "chunk_ms",
        "embed_ms",
        "retrieve_ms",
        "ttft_ms",
        "total_ms",
        "loc",
    ]


def test_missing_key_fails_hard_instead_of_defaulting():
    # Silent defaults are how engines drift apart — the loader must raise.
    with pytest.raises(RuntimeError):
        contract.get("NOPE_NOT_A_CONTRACT_KEY")
