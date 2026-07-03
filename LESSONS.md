# LESSONS.md — RAG Duel

A running log of **non-obvious** things learned the hard way: setup friction,
gotchas, surprising library behavior, and decisions made under ambiguity. The
point is that the next person (or the next agent) doesn't re-hit the same wall.

## How to use this file

- Append an entry whenever something **surprised** you or cost you time — not for
  routine work. If a task's `AC:` was harder to meet than expected, that's a
  lesson.
- **Newest entries at the top**, under the log.
- One entry per lesson. Keep it tight: what happened, why, and the takeaway.

### Entry format

```
### YYYY-MM-DD — <short title>  [TASK-ID]
**Context:** what you were doing.
**Surprise / problem:** what bit you.
**Resolution:** what fixed it (command, version, config, decision).
**Takeaway:** the durable rule so it doesn't happen again.
```

---

## Log

### 2026-07-03 — Gemini's native embedding dim doesn't fit pgvector indexes  [CONTRACT-01]
**Context:** Locking the shared contract before the Phase 2 migrations, per the
DB-03 warning ("not a guessed 1536").
**Surprise / problem:** `gemini-embedding-001` (the current model —
`text-embedding-004` was retired in Jan 2026) natively emits **3072** dims, but
pgvector refuses to build HNSW/IVFFlat indexes on columns above **2000** dims.
Storing the native dim would have made DB-05 impossible.
**Resolution:** Locked `EMBEDDING_DIM=768` — a Google-recommended Matryoshka
truncation point that indexes fine. Two riders documented in `contract.env`:
truncated vectors are **not re-normalized** by the API (cosine ordering is
unaffected, but normalize before storing), and chunk sizes are defined in
**characters**, not tokens, so chunker parity never depends on a tokenizer.
A test guards the ≤2000 ceiling at the source.
**Takeaway:** When a model's "default" output doesn't fit the index tech,
resolve it in the contract with the reasoning written down — not in whichever
engine happens to hit the error first.

### 2026-07-03 — Phase 2 schema notes  [DB-01…DB-07]
**Context:** Migrating the shared schema and proving the fairness properties.
**Surprise / problem:**
- This sandbox's egress policy blocks Docker Hub's blob CDN
  (`production.cloudfront.docker.com` → 403), so `make up` can't pull the
  pgvector image here. Not a compose bug — the same file works where Docker Hub
  is reachable.
- The `pgvector/pgvector` composer package auto-registers its own
  `2022_08_03_000000_create_vector_extension` migration from vendor/, which
  runs *before* ours. Harmless duplication (both are `IF NOT EXISTS`), but
  don't be confused by two extension migrations in `migrate` output.
- `pgvector-php` hands integral vector components back as PHP **ints** (`0`,
  not `0.0`) — a strict `assertSame` on the round-tripped array fails on type.
  Normalize with `floatval` and keep strict value equality; a tolerance-based
  compare could mask real float4 truncation.
- IVFFlat trains its lists from rows present at `CREATE INDEX` time; on the
  empty tables migrations produce, recall would be degenerate. HNSW builds
  incrementally — that's why DB-05 went HNSW.
**Resolution:** Verified the phase against an apt-installed Postgres 16 +
pgvector 0.6 (`postgresql-16-pgvector`) with the same creds as compose; the
test suite now runs on pgsql (`ragduel_test` DB) because vector columns, HNSW
plans, and CHECKs don't exist on sqlite. `docker-compose.yml` stays canonical
for normal local dev.
**Takeaway:** Anything pgvector-specific is unprovable on sqlite — point the
test suite at real Postgres early, and keep planner-dependent assertions
honest with `SET LOCAL enable_seqscan = off`.

### 2026-06-29 — Phase 1 local environment notes  [INFRA-01…INFRA-06]
**Context:** Standing up the DB + both engines locally.
**Surprise / problem:**
- The dev container had Docker installed but **no daemon running** —
  `docker compose up` failed with a missing `/var/run/docker.sock`.
- `composer create-project laravel/laravel` resolved to **Laravel 13**, not a
  separately-numbered "LTS". Laravel ships yearly majors; "latest LTS" in the
  backlog just means current stable. The scaffold defaults to **SQLite**.
- FastAPI's `TestClient` prints a Starlette deprecation warning ("use httpx2").
  Cosmetic — tests pass.
**Resolution:**
- Start the daemon (`dockerd &`) before `make up`. The `pgvector/pgvector:pg16`
  image ships the `vector` extension prebuilt, so DB-01 will only need
  `CREATE EXTENSION`.
- Repointed Laravel at the shared Postgres by editing the DB block in `.env`
  **and** `.env.example` (driver `pgsql`, container creds), then cleared config
  cache so the change took effect before `migrate`.
- Left the `TestClient` warning alone; revisit if it becomes an error on a future
  httpx major.
**Takeaway:** In a fresh sandbox, confirm the Docker daemon is up before anything
DB-dependent. When editing Laravel env, change `.env.example` in lockstep and run
`php artisan config:clear` or the old cached config wins.

### 2026-06-29 — Phase 0 ambiguity calls  [BOOT-01…BOOT-07]
**Context:** Bootstrapping the repo skeleton and doc set from `BACKLOG.md` alone.
**Surprise / problem:** A few things weren't spelled out and had to be decided
without contradicting the backlog:
- The root `Makefile` is INFRA-06 (Phase 1), but BOOT-01's skeleton list doesn't
  include it. Docs reference `make up` / `make migrate` etc.
- The embedding **dimension** is fairness-critical and Gemini-specific, but the
  exact Gemini model/dim isn't fixed until CONTRACT-01.
- BOOT-01 lists `docker-compose.yml` as a *placeholder*; the real pgvector service
  is INFRA-01.
**Resolution:**
- Did **not** create the `Makefile` (it's Phase 1, and not in the BOOT-01 list).
  Docs describe the `make` targets as the shape the project converges on — the
  kickoff prompt itself sanctions referencing `make up` as a command that "will
  exist per the backlog."
- Did **not** hardcode any embedding dimension anywhere. Docs say the `vector(N)`
  columns get `N` from CONTRACT-01 (explicitly *not* OpenAI's 1536). `infra/` ships
  with a README placeholder, not a `contract.env` with invented numbers.
- Shipped `docker-compose.yml` as an explicit stub (`services: {}`) with a comment
  pointing at INFRA-01, so the tree is complete without pre-empting Phase 1.
- Set local git author to **Cristian Cartagena `<c.zapata.iq@gmail.com>`** and
  push to `main` per the owner's explicit instruction; recorded those two git
  rules in `CLAUDE.md` §0 and `AGENTS.md` so all agents inherit them.
**Takeaway:** When the backlog defers a value or a tool to a later phase, **point
at that phase** in the docs instead of inventing a concrete value now. Skeleton
files that belong to Phase 1 stay as labeled placeholders.

---

<!--
### YYYY-MM-DD — Example: pgvector PHP binding needs the extension first  [DB-07]
**Context:** Wiring pgvector reads/writes from Laravel via pgvector/pgvector-php.
**Surprise / problem:** Inserting a vector failed until `CREATE EXTENSION vector`
had actually run — the PHP binding assumes the column type already exists.
**Resolution:** Ran the DB-01 migration (enable `vector`) before the DB-03/04
table migrations; confirmed a round-trip in tinker.
**Takeaway:** Extension first, then vector columns, then the binding. Order the
migrations accordingly.
(Delete this example once there are two or three real entries above.)
-->
