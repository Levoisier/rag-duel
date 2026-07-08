# BACKLOG — RAG Duel

> **PHP (Laravel) vs Python (FastAPI) RAG, side by side.** One Laravel app serves a two‑column UI: the **left** column runs a RAG pipeline natively in PHP, the **right** column calls a Python FastAPI service running the *same* pipeline. Both hit one shared Postgres+pgvector DB with locked, identical parameters. The point is an honest **DX + per‑phase performance** comparison, not a contrived perf win.

---

## How to use this file (agents read this first)

- Work **top to bottom**. Phases are ordered by dependency; don't start a phase until the one before it is green.
- Each task has an **ID**, a one‑line goal, and an **`AC:` acceptance criterion** that defines "done." A task is only done when its AC is verifiably met (test passes / command runs / endpoint responds).
- Keep tasks atomic. If a task balloons, split it and add the new IDs here.
- After finishing a task: check the box, append anything surprising to `LESSONS.md`, commit with the task ID in the message (e.g. `PHP-03: chunker with overlap`).
- **Never** invent parameter values that must match across both engines — they live in the shared contract (Phase 3). Read it, don't guess.

**Status legend:** `[ ]` todo · `[~]` in progress · `[x]` done · `[!]` blocked (note why inline)

**Project name:** **RAG Duel** — final, locked. Use it everywhere; do not second‑guess or rename it.

**Chosen AI provider:** **Google Gemini (AI Studio)** for *both* chat and embeddings — one key, permanent free tier, identical endpoints on both engines (keeps the comparison fair). This decision is locked; when a task needs the key, the agent must **stop and ask the human for it** (see CONTRACT‑04) rather than inventing a placeholder or silently switching providers.

---

## Deployment reality check (read before Phase 9)

❌ **Netlify is not a valid target** — it runs no PHP, no long‑lived Python process, and no Postgres. Don't attempt it.
✅ **Target:** Supabase (Postgres+pgvector) · Railway (Laravel service + FastAPI service). Alternatives: Fly.io (Docker) for both, or Laravel Cloud for the PHP side.

---

## Phase 0 — Bootstrap & Docs

> Goal: a coherent repo skeleton and the full doc set, generated from this backlog + the project idea. This phase is driven by the kickoff prompt.

- [x] **BOOT-01** — Initialize repo structure: `/laravel`, `/python`, `/docs`, `/infra`, root `docker-compose.yml` placeholder, `.gitignore`, `.editorconfig`. _AC: tree exists and is committed._
- [x] **BOOT-02** — Generate `README.md` (what/why, quickstart, stack, screenshots placeholder). _AC: a new dev can understand the project in 60s._
- [x] **BOOT-03** — Generate `CLAUDE.md` and `AGENTS.md` (project conventions, commands, guardrails, "definition of done", how to run tests/lint for both stacks). _AC: both files agree and reference real commands from this repo._
- [x] **BOOT-04** — Generate `LESSONS.md` (empty template with a dated‑entry format). _AC: file exists with a usage note and one example entry._
- [x] **BOOT-05** — Generate `docs/ARCHITECTURE.md` (the diagram below, data flow, fairness rules, why two tables). _AC: matches the design in this backlog._
- [x] **BOOT-06** — Generate `docs/SETUP.md` (local install for Laravel + Python + Postgres/pgvector, env vars, how to run both services + the UI). _AC: following it from scratch yields a running app._
- [x] **BOOT-07** — Confirm project name **RAG Duel** is used consistently across all docs, repo metadata, and UI title. _AC: name is "RAG Duel" everywhere; no placeholder variants._

---

## Phase 1 — Local dev environment

> Goal: one command brings up Postgres+pgvector; both services run locally and can reach it.

- [x] **INFRA-01** — `docker-compose.yml` with a `pgvector/pgvector:pg16` Postgres service, healthcheck, named volume, exposed port. _AC: `docker compose up -d` → DB accepts connections._
- [x] **INFRA-02** — Scaffold Laravel app in `/laravel` (latest LTS), confirm it boots. _AC: `php artisan serve` shows welcome page._
- [x] **INFRA-03** — Scaffold FastAPI app in `/python` with `uv` (or `poetry`), confirm it boots. _AC: `GET /health` → 200._
- [x] **INFRA-04** — Wire Laravel → Postgres (pgsql driver) and run a no‑op migration. _AC: `php artisan migrate` succeeds against the container DB._
- [x] **INFRA-05** — Wire FastAPI → Postgres (e.g. `asyncpg`/`psycopg`) and a connectivity ping. _AC: a `/db-ping` route returns server version._
- [x] **INFRA-06** — Root `Makefile` (or `justfile`): `make up`, `make laravel`, `make python`, `make migrate`, `make test`. _AC: each target works._

---

## Phase 2 — Database & schema

> Goal: shared schema with separate per‑engine chunk tables so ingestion is measured independently.

- [x] **DB-01** — Enable `vector` extension via migration. _AC: `\dx` lists `vector`._
- [x] **DB-02** — `documents` table: id, filename, mime, byte_size, status, timestamps. _AC: migrates._
- [x] **DB-03** — `php_chunks` table: id, document_id (fk, cascade), content (text), embedding (`vector(N)` where **N = Gemini embedding dim** from CONTRACT‑01, not a guessed 1536), ordinal, token_count, timestamps. _AC: migrates; vector dim matches the chosen Gemini model._
- [x] **DB-04** — `py_chunks` table: identical shape to `php_chunks`. _AC: migrates._
- [x] **DB-05** — IVFFlat (or HNSW) index on each embedding column for cosine. _AC: `EXPLAIN` on a similarity query uses the index._ (HNSW — IVFFlat can't train on empty tables.)
- [x] **DB-06** — `runs` table to log every query: id, document_id, engine (`php`|`py`), question, phase timings (jsonb), top_k, model fields, created_at. _AC: migrates; ready for Phase 7 to write to._
- [x] **DB-07** — Verify pgvector binding for **PHP** (e.g. `pgvector/pgvector-php`) and confirm read/write of a vector round‑trips. _AC: a tinker snippet stores and retrieves a vector unchanged._ ⚠️ community package — verify current version, this is where setup friction lives.

---

## Phase 3 — Shared contract (lock the fight)

> Goal: a single source of truth for every parameter that MUST be identical across engines. If it differs, the comparison is invalid.

- [~] **CONTRACT-01** — *(contract.env locked & loaded by the PHP side — Phase 2 migrations take `vector(N)` from it; Python wiring lands with PY-04)* `infra/contract.env` (or `contract.yaml`) defining: embedding model + dimension, LLM model + temperature + max_tokens, `TOP_K`, `CHUNK_SIZE`, `CHUNK_OVERLAP`, similarity metric (cosine). Provider is **Gemini** (primary). _AC: both engines load values from here, neither hardcodes._ ⚠️ Gemini embedding dimension differs from OpenAI's 1536 — set the `vector(N)` columns (DB-03/04) to match Gemini's actual output dim; reconcile before Phase 2 migrations run.
- [x] **CONTRACT-02** — Document the API contract for the Python service: `POST /ingest` (multipart) and `POST /query` (SSE stream of tokens + a final metrics frame). _AC: written in `docs/ARCHITECTURE.md`; both sides agree._
- [ ] **CONTRACT-03** — Define the **timings payload** shape both engines emit: `{extract_ms, chunk_ms, embed_ms, retrieve_ms, ttft_ms, total_ms, loc}`. _AC: identical keys/units on both sides._
- [ ] **CONTRACT-04** — Wire **Gemini** keys. **STOP and ask the human for the Gemini (AI Studio) API key** — we chose Gemini for both chat + embeddings; do not substitute another provider or use a fake placeholder. Put the key in `.env`, list it in `.env.example`, confirm one key works for both engines. _AC: a live Gemini call succeeds from both PHP and Python using the human‑supplied key._
- [ ] **CONTRACT-05** — **Provider abstraction + fallback chain.** Behind a small interface (`chat()` / `embed()`), implement Gemini primary → **Groq** fallback on 429/5xx/timeout, in *both* engines. Same interface, same fallback order, configured from the contract. _AC: simulating a Gemini 429 transparently routes to Groq and the request still completes; fallback order is identical PHP vs Python._ (Asking for the Groq key is optional now — fallback can be added later, but build the interface seam now so it's not a rewrite.)
- [ ] **CONTRACT-06** — **Cost guardrail (so a forgotten key never starts billing me).** Keep Gemini on the **free tier with billing DISABLED** in Google AI Studio / Cloud — the free tier does not auto‑charge, it just rate‑limits/4xx's when exhausted, which is the safe failure mode we want. If billing ever gets enabled, set a **hard budget cap + $0‑ish alert** and per‑model quota limits. Also add **app‑side request throttling** in both engines to stay under the free RPM/RPD ceilings (Gemini ~15 RPM / ~1,500 RPD) and surface a friendly "rate limit hit, try later" instead of hammering the API. _AC: billing is off (or hard‑capped); app throttles and degrades gracefully when the free quota is exhausted; no path exists that silently incurs charges._

---

## Phase 4 — PHP / Laravel RAG engine

> Goal: native in‑process pipeline. No Python involved on this side.

- [ ] **PHP-01** — `Storage`‑backed upload controller: accept PDF/txt, create `documents` row, dispatch ingest job. _AC: upload returns a document id; file persisted._
- [ ] **PHP-02** — Text extraction: `smalot/pdfparser` for PDF, passthrough for txt. Time it (`extract_ms`). _AC: extracts a known PDF to expected text._
- [ ] **PHP-03** — Chunker: split on paragraph/sentence boundaries to `CHUNK_SIZE` with `CHUNK_OVERLAP`. Time it (`chunk_ms`). _AC: chunk count + overlap match contract on a fixture._
- [ ] **PHP-04** — `EmbedDocument` queued job: embed each chunk via HTTP client, write to `php_chunks`. Time it (`embed_ms`); set document status `ready`. _AC: job fills `php_chunks` for a doc; status flips._
- [ ] **PHP-05** — Retrieval service: embed question, run pgvector cosine (`<=>`) top‑K against `php_chunks`. Time it (`retrieve_ms`). _AC: returns K ordered chunks for a question._
- [ ] **PHP-06** — Answer service: build prompt (system + retrieved chunks + question), call the provider abstraction (CONTRACT‑05, Gemini→Groq), stream LLM tokens via `response()->stream()`/SSE. Capture `ttft_ms` + `total_ms`. _AC: endpoint streams a grounded answer; survives a forced Gemini failure via fallback._
- [ ] **PHP-07** — Emit the timings payload (CONTRACT-03) on the final SSE frame. _AC: client receives all phase timings._

---

## Phase 5 — Python / FastAPI RAG engine

> Goal: same pipeline, idiomatic Python, exposed over HTTP. You know this stack — keep it lean, don't gold‑plate.

- [ ] **PY-01** — `POST /ingest`: accept file, extract (`pypdf`), chunk (same boundary logic + contract values), embed, write to `py_chunks`. _AC: fills `py_chunks`; returns extract/chunk/embed timings._
- [ ] **PY-02** — Retrieval: embed question, pgvector cosine top‑K against `py_chunks`. _AC: returns K ordered chunks; emits `retrieve_ms`._
- [ ] **PY-03** — `POST /query`: call the provider abstraction (CONTRACT‑05, Gemini→Groq), SSE stream of LLM tokens + final metrics frame matching CONTRACT‑03. _AC: streams a grounded answer with timings; survives a forced Gemini failure via fallback._
- [ ] **PY-04** — Load all locked params from the shared contract, not literals. _AC: changing the contract changes Python behavior with no code edit._
- [ ] **PY-05** — Dockerfile for the service. _AC: `docker build` + run → `/health` 200._

---

## Phase 6 — Frontend (Laravel, two columns)

> Goal: the demo. Blade + Livewire, no React. Left = PHP engine, right = Python engine, fed the same input.

- [ ] **UI-01** — Layout: header, upload zone, single question box, two equal columns labeled PHP / Python. _AC: responsive 2‑col, collapses on mobile._
- [ ] **UI-02** — Upload flow: one upload → ingest on **both** engines (PHP job + call to Python `/ingest`); show per‑side ingest status + ingest timings. _AC: both `*_chunks` tables fill for one upload._
- [ ] **UI-03** — Ask flow: one question fans out to both `/query` streams; tokens render live in each column independently. _AC: both columns stream concurrently._
- [ ] **UI-04** — Metrics strip under each column rendering CONTRACT‑03 fields. _AC: all phases shown with units._
- [ ] **UI-05** — Livewire wiring for streaming + loading/empty/error states. _AC: no dead UI on error; clear states._

---

## Phase 7 — Metrics & timing harness

> Goal: trustworthy numbers, not single‑run noise.

- [ ] **METRIC-01** — Wall‑clock helpers: `hrtime(true)` (PHP) / `time.perf_counter()` (Python), ms output. _AC: unit‑tested to expected precision._
- [ ] **METRIC-02** — Persist each run to `runs` (DB-06) with engine + timings + params. _AC: a row written per query per engine._
- [ ] **METRIC-03** — "Run N times" mode: execute the same question K times, display **median** per phase. _AC: UI shows median, not last run._
- [ ] **METRIC-04** — Static `loc` badge per engine (counted at build, displayed in metrics strip) — the honest DX metric. _AC: badge reflects real counts._

---

## Phase 8 — Testing & CI

- [ ] **TEST-01** — PHP: Pest/PHPUnit for chunker (boundaries/overlap) + retrieval ordering. _AC: green._
- [ ] **TEST-02** — Python: pytest for chunker parity + `/query` contract shape. _AC: green._
- [ ] **TEST-03** — Chunker **parity** test: same input → both engines produce equivalent chunk boundaries. _AC: parity asserted on a fixture._
- [ ] **TEST-04** — GitHub Actions: spin pgvector service, run both test suites + lint (`pint`/`phpstan`, `ruff`). _AC: CI green on PR._

---

## Phase 9 — Deployment (Supabase + Railway)

> ❌ Not Netlify. ✅ Supabase DB + Railway services.

- [ ] **DEPLOY-01** — Provision Supabase project; enable `vector`; run migrations against it. _AC: remote DB has all tables + extension._
- [ ] **DEPLOY-02** — Deploy FastAPI service to Railway from `/python` Dockerfile; set env from contract. _AC: public `/health` 200._
- [ ] **DEPLOY-03** — Deploy Laravel to Railway (`/laravel`); set DB to Supabase, point Python base‑URL to the deployed service, run `migrate --force`, build assets. _AC: app loads publicly._
- [ ] **DEPLOY-04** — Configure queue worker for `EmbedDocument` in prod (Railway process / Horizon, or `QUEUE_CONNECTION=sync` to start). _AC: uploads ingest in prod._
- [ ] **DEPLOY-05** — Secrets management: all keys in Railway/Supabase env, none in repo; `.env.example` current. **Re‑ask the human for the Gemini key** for the prod environment (don't reuse a local one blindly), and re‑confirm Gemini billing is still OFF / hard‑capped (CONTRACT‑06) before going public. _AC: fresh clone deploys with only documented secrets; no billing exposure in prod._
- [ ] **DEPLOY-06** — Smoke test prod: upload → both ingest → ask → both stream + metrics. _AC: end‑to‑end works on the live URL._
- [ ] **DEPLOY-07** — Add live URL + screenshots/GIF to `README.md`. _AC: README shows the working demo._

---

## Phase 10 — Stretch (after it's live)

- [ ] **EXT-01** — Citations: show which chunk(s) each answer came from (easy + impressive).
- [ ] **EXT-02** — Multi‑document support + per‑doc scoping.
- [ ] **EXT-03** — **CPU stress mode**: do the cosine search in‑app instead of pgvector, or embed a large batch — surfaces a *real* language perf delta where I/O no longer dominates.
- [ ] **EXT-04** — Runs history table/page reading from `runs` (trends over time).
- [ ] **EXT-05** — Conversation memory (multi‑turn).
- [ ] **EXT-06** — Swap embedding/LLM provider via contract only; re‑benchmark.

---

## Architecture (reference)

```
Browser
  └─ Laravel (Blade + Livewire) ── serves 2‑col UI, owns upload + question
       ├─ LEFT  → PHP RAG in‑process ............ writes/reads php_chunks
       └─ RIGHT → HTTP → FastAPI service → Python RAG  writes/reads py_chunks

Shared: one Postgres+pgvector · same embedding model · same LLM · same TOP_K / CHUNK_SIZE / OVERLAP
Honest framing: pipeline is I/O‑bound; the real per‑language delta lives in extract + chunk (CPU).
```

## Honesty notes baked into the design

- **php‑fpm (fresh per request) vs FastAPI (warm process) is not apples‑to‑apples.** Measure pipeline *phases*, never the HTTP envelope, when comparing languages.
- **Expected result:** totals are within noise, dominated by identical API/DB calls. That's a legitimate finding — present it, don't fake a winner. Use stress mode (EXT‑03) for a real CPU fight.
- **You know Python cold**, so Phase 5 is fast and Phase 4 (PHP) is the actual learning. Scope accordingly.
