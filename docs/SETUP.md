# Setup — RAG Duel (local, from scratch)

How to get RAG Duel running on a fresh machine: the shared Postgres + pgvector
database, the Laravel app (UI + PHP engine), and the Python FastAPI service. By
the end you'll have the two-column UI streaming answers from both engines.

> **Phase note.** RAG Duel is built phase by phase (see
> [`../BACKLOG.md`](../BACKLOG.md)). Some steps below depend on a phase having
> landed — e.g. the Laravel app is scaffolded in INFRA-02, the FastAPI service in
> INFRA-03, the `Makefile` in INFRA-06, the schema in Phase 2. This document is the
> install path the project converges on; where a step isn't wired yet, its task ID
> is noted so you know what unlocks it.

---

## 1. Prerequisites

| Tool                   | Why                              | Check                     |
|------------------------|----------------------------------|---------------------------|
| **Docker** + Compose   | runs Postgres + pgvector         | `docker --version`        |
| **PHP** (current LTS)  | Laravel app + PHP engine         | `php --version`           |
| **Composer**           | PHP dependencies                 | `composer --version`      |
| **`uv`**               | Python env + deps for FastAPI    | `uv --version`            |
| **Node.js** + npm      | builds Laravel/Livewire assets   | `node --version`          |
| **make**               | runs the root `Makefile` targets | `make --version`          |

> Exact PHP/Node majors get pinned as INFRA-02/03 land. Verify current majors
> before installing (the backlog calls this out — library majors drift).

---

## 2. Clone and configure

```bash
git clone https://github.com/Levoisier/rag-duel.git
cd rag-duel
cp .env.example .env
```

Open `.env` and set, at minimum:

- **`GEMINI_API_KEY`** — your Google AI Studio key. RAG Duel uses **Gemini for
  both chat and embeddings**. Per CONTRACT-04 this key is **human-supplied**;
  there is no placeholder fallback and no other provider is substituted. Keep
  Gemini billing **disabled / hard-capped** (CONTRACT-06) so a forgotten key can
  never start billing — the free tier simply rate-limits when exhausted.
- **`GROQ_API_KEY`** — *optional*. The Groq fallback (CONTRACT-05) only triggers on
  Gemini 429/5xx/timeout. Safe to leave blank to start.
- The **`POSTGRES_*` / `DB_*`** values default to the docker-compose database
  below; leave them as-is for a standard local run.
- **`PYTHON_SERVICE_URL`** — where Laravel reaches the Python engine; defaults to
  `http://127.0.0.1:8001`.

> The locked, fairness-critical model parameters (embedding model + dimension,
> `TOP_K`, `CHUNK_SIZE`, `CHUNK_OVERLAP`, …) do **not** go in `.env`. They live in
> `infra/contract.env` (CONTRACT-01) so both engines read one source of truth.

---

## 3. Start the database

```bash
make up        # docker compose up -d  → Postgres + pgvector (pgvector/pgvector:pg16)
```

This brings up the shared Postgres with the `vector` extension available
(INFRA-01). The container exposes port `5432` and persists to a named volume, so
data survives restarts. Confirm it's accepting connections:

```bash
docker compose ps                          # service healthy
docker compose exec db pg_isready          # "accepting connections"
```

---

## 4. Laravel app — UI + PHP engine (`/laravel`)

```bash
cd laravel
composer install
npm install && npm run build      # build Blade/Livewire assets
php artisan key:generate          # sets APP_KEY in .env
```

Create the schema against the container DB:

```bash
php artisan migrate               # or, from repo root: make migrate
```

This runs the Phase 2 migrations: enable `vector` (DB-01), then `documents`,
`php_chunks`, `py_chunks` (`vector(N)`, N from the contract), and `runs`. Run the
app:

```bash
php artisan serve                 # → http://127.0.0.1:8000   (or: make laravel)
```

If `QUEUE_CONNECTION` is `sync` (the `.env.example` default), the `EmbedDocument`
job runs inline — no separate worker needed to start. To run it out-of-process
later: `php artisan queue:work`.

---

## 5. Python FastAPI service — Python engine (`/python`)

In a second terminal:

```bash
cd python
uv sync                                            # resolve + install deps
uv run uvicorn app.main:app --reload --port 8001   # (or, from repo root: make python)
```

Verify it's up and can reach the database:

```bash
curl localhost:8001/health        # → 200
curl localhost:8001/db-ping       # → Postgres server version (INFRA-05)
```

The service reads the same Postgres via `DATABASE_URL` and loads the locked
parameters from `infra/contract.env` — not from literals (PY-04).

---

## 6. Use it

1. Open **http://127.0.0.1:8000** — the two-column UI ("RAG Duel", PHP | Python).
2. **Upload** a PDF or txt. One upload ingests on **both** engines: the PHP job
   fills `php_chunks`, and Laravel calls the Python `/ingest` which fills
   `py_chunks`. Per-side ingest status + timings appear.
3. **Ask** one question. It fans out to both `/query` streams; tokens render live
   in each column, and a metrics strip shows the per-phase timings (CONTRACT-03).

---

## 7. Tests & lint

The PHP suite runs against real Postgres (the schema is pgvector-specific —
vector columns, HNSW plans — none of which sqlite can fake), on a separate
`ragduel_test` database in the same instance. Create it once:

```bash
docker compose exec db psql -U ragduel -c "CREATE DATABASE ragduel_test OWNER ragduel;"
```

From the repo root:

```bash
make test          # runs both suites (PHP + Python)
```

Or per stack:

```bash
# PHP (/laravel)
php artisan test                       # Pest/PHPUnit (TEST-01)
./vendor/bin/pint                      # format
./vendor/bin/phpstan analyse           # static analysis (TEST-04)

# Python (/python)
uv run pytest                          # (TEST-02)
uv run ruff check .                    # lint (TEST-04)
uv run ruff format .                   # format
```

CI (TEST-04) spins up a pgvector service and runs both suites + lint on every PR.

---

## 8. Troubleshooting

- **`php artisan migrate` can't connect.** Is `make up` done and healthy
  (`docker compose ps`)? Do the `DB_*` values in `.env` match the compose service?
- **`/db-ping` fails but the DB is up.** Check `DATABASE_URL` in `.env` — host
  `127.0.0.1`, port `5432`, the `ragduel` db/user/password from `.env.example`.
- **pgvector / PHP binding errors writing a vector.** The `vector` extension must
  be enabled (DB-01 migration) *before* the vector columns exist — this is a known
  friction point (DB-07). See [`../LESSONS.md`](../LESSONS.md).
- **Gemini calls 429 / rate-limited.** Expected on the free tier under load — the
  app throttles and degrades gracefully (CONTRACT-06), and falls back to Groq if a
  `GROQ_API_KEY` is set (CONTRACT-05). This is the safe failure mode, not a bug.

---

## 9. Deploying

Local is covered here; production is **Supabase (DB) + Railway (both services)**,
detailed in Phase 9 of [`../BACKLOG.md`](../BACKLOG.md) and
[`ARCHITECTURE.md`](ARCHITECTURE.md) §8. **Netlify is not a valid target.**
