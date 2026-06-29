# `/infra` — shared contract & infrastructure config (placeholder)

This directory holds configuration shared across **both** engines so the fight is
fair. Its centerpiece arrives in Phase 3:

- **`contract.env`** (CONTRACT-01) — the single source of truth for every
  parameter that MUST be identical on both sides: embedding model + dimension,
  LLM model + temperature + max_tokens, `TOP_K`, `CHUNK_SIZE`, `CHUNK_OVERLAP`,
  and similarity metric (cosine). Both engines **load** these values; neither
  hardcodes them. See [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) for the
  fairness rules this enforces.

It is intentionally empty in Phase 0 except for this note. Do not invent
parameter values here — the embedding dimension in particular depends on the
chosen Gemini model and is set, once, in CONTRACT-01 before the Phase 2
migrations (`php_chunks` / `py_chunks`) run.

> `infra/pgdata/` (the local Postgres volume) is git-ignored.
