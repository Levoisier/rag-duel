# Architecture — RAG Duel

How RAG Duel is built, how a request flows, and the rules that keep the PHP-vs-
Python comparison **honest**. This is the design reference; the phased plan and
acceptance criteria live in [`../BACKLOG.md`](../BACKLOG.md).

---

## 1. The big picture

One Laravel app owns the browser experience and the upload/question inputs. It
runs the **left** column's RAG pipeline in-process (PHP). For the **right**
column it makes an HTTP call to a separate **Python FastAPI** service that runs
the *same* pipeline. Both engines read and write **one shared Postgres + pgvector
database**, but into **separate chunk tables** so each side's ingestion is
measured on its own.

```
Browser
  └─ Laravel (Blade + Livewire) ── serves 2-col UI, owns upload + question
       ├─ LEFT  → PHP RAG in-process ............ writes/reads php_chunks
       └─ RIGHT → HTTP → FastAPI service → Python RAG  writes/reads py_chunks

Shared: one Postgres+pgvector · same embedding model · same LLM · same TOP_K / CHUNK_SIZE / OVERLAP
Honest framing: pipeline is I/O-bound; the real per-language delta lives in extract + chunk (CPU).
```

### Components

- **Laravel app (`/laravel`)** — serves the two-column UI (Blade + Livewire,
  Phase 6), owns the single upload and single question box, and contains the
  **native PHP RAG engine** (Phase 4): upload → extract (`smalot/pdfparser`) →
  chunk → embed → retrieve (pgvector `<=>`) → answer (streamed). It also
  orchestrates the right column by calling the Python service.
- **FastAPI service (`/python`)** — the **Python RAG engine** (Phase 5):
  `POST /ingest` and `POST /query`, plus `GET /health` and `GET /db-ping`. Same
  pipeline, idiomatic Python (`pypdf`, `psycopg`/`asyncpg`).
- **Postgres + pgvector (`pgvector/pgvector:pg16`)** — one database shared by both
  engines. Holds `documents`, `php_chunks`, `py_chunks`, and `runs`.
- **Shared contract (`infra/contract.env`)** — the locked parameters both engines
  load (Phase 3). The mechanism that makes the fight fair (§4).
- **Provider layer** — Google Gemini for chat + embeddings, with Groq fallback,
  behind one small `chat()`/`embed()` interface implemented identically in both
  engines (CONTRACT-05).

---

## 2. Data flow

### Ingest (one upload → both engines)

1. The user uploads one file (PDF or txt) in the UI.
2. **Left (PHP):** Laravel stores the file, creates a `documents` row, and
   dispatches the `EmbedDocument` queued job — extract → chunk → embed → write
   rows to **`php_chunks`**, then flip the document `status` to `ready`. Each
   phase is timed (`extract_ms`, `chunk_ms`, `embed_ms`).
3. **Right (Python):** Laravel calls `POST /ingest` on the FastAPI service with the
   same file; Python extracts → chunks → embeds → writes rows to **`py_chunks`**
   and returns its ingest timings.
4. The UI shows per-side ingest status + timings. Both `*_chunks` tables now hold
   the document, embedded with the **same** model and chunked with the **same**
   `CHUNK_SIZE`/`CHUNK_OVERLAP`.

### Query (one question → both engines stream)

1. The user asks one question. The UI fans it out to **both** engines
   concurrently.
2. Each engine: embed the question → pgvector cosine top-`TOP_K` against **its own**
   chunk table (`php_chunks` for PHP, `py_chunks` for Python) → build the prompt
   (system + retrieved chunks + question) → call the provider (Gemini→Groq) → SSE
   stream tokens back.
3. Each engine captures `retrieve_ms`, `ttft_ms` (time to first token) and
   `total_ms`, and emits the full timings payload (§6) on a **final metrics frame**
   after the token stream.
4. The UI renders tokens live in each column and a metrics strip per side. Each
   query, per engine, is persisted to the `runs` table (Phase 7) for trustworthy,
   multi-run medians.

---

## 3. Why two chunk tables

`php_chunks` and `py_chunks` are **identical in shape** but kept **separate on
purpose**:

- **Independent ingestion measurement.** Each engine writes only its own table, so
  embed/write time and row counts are attributable to one language without
  contention or shared-cache confounds.
- **No accidental cross-reads at retrieval.** The PHP engine retrieves only from
  `php_chunks`, Python only from `py_chunks`. Neither side can "borrow" the other's
  work, which would make a timing meaningless.
- **Parity stays checkable.** Because the chunker logic and contract values are
  shared, the two tables should contain equivalent chunk boundaries for the same
  input — a property a parity test asserts directly (TEST-03).

Merging them into one table would couple the two pipelines and destroy the
independence the whole comparison depends on.

Both embedding columns are `vector(N)`, where **N is the Gemini embedding model's
output dimension** taken from the contract (CONTRACT-01) — explicitly *not* a
guessed 1536. Each gets a cosine vector index (IVFFlat/HNSW, DB-05).

---

## 4. Fairness rules (the locked fight)

The comparison is only valid if the workload is identical. These rules are
enforced, not aspirational:

1. **One source of truth for parameters.** `infra/contract.env` (CONTRACT-01)
   defines the embedding model + dimension, the LLM model + temperature +
   max_tokens, `TOP_K`, `CHUNK_SIZE`, `CHUNK_OVERLAP`, and the similarity metric
   (cosine). **Both engines load these; neither hardcodes.** Changing the contract
   must change both engines' behavior with no code edit (CONTRACT-01, PY-04).
2. **Same provider, same fallback order.** Gemini primary → Groq on
   429/5xx/timeout, behind one `chat()`/`embed()` interface, identical order on
   both sides (CONTRACT-05).
3. **Same chunk boundaries.** Both chunkers split on the same paragraph/sentence
   boundaries to the same size/overlap; a parity test proves equivalence on a
   fixture (TEST-03).
4. **Measure phases, never the HTTP envelope.** php-fpm (fresh process per request)
   vs FastAPI (warm process) is *not* apples-to-apples. We compare the pipeline
   **phases** (§6), so the language delta isn't swamped by runtime startup model
   differences.
5. **Honest expected result.** The pipeline is I/O-bound and dominated by identical
   API/DB calls, so totals should land **within noise**. That's a legitimate
   finding — present it, don't fake a winner. The real per-language delta lives in
   the CPU-bound phases (`extract` + `chunk`); a later stress mode (EXT-03) surfaces
   it deliberately by doing cosine search in-app or embedding a large batch.
6. **DX is measured too.** A static `loc` (lines-of-code) badge per engine
   (METRIC-04), counted at build, is the honest developer-experience metric and
   travels in the timings payload.

---

## 5. The Python service API contract (CONTRACT-02)

The FastAPI service exposes a small, stable surface. The Laravel side codes
against exactly this; both sides agree on it here.

### `GET /health`
Liveness probe. → `200` with a small JSON body. (INFRA-03)

### `GET /db-ping`
Connectivity check. Returns the Postgres server version. (INFRA-05)

### `POST /ingest` — multipart
- **Request:** `multipart/form-data` with the document file (PDF or txt) and a
  document identifier so both engines key to the same `documents` row.
- **Behavior:** extract → chunk → embed → write to `py_chunks`, using contract
  values.
- **Response:** JSON including the ingest-phase timings
  (`extract_ms`, `chunk_ms`, `embed_ms`) and chunk count. (PY-01)

### `POST /query` — SSE
- **Request:** JSON with the question and the target document.
- **Behavior:** embed question → cosine top-`TOP_K` against `py_chunks` → build
  prompt → call provider (Gemini→Groq) → stream tokens.
- **Response:** an **SSE stream** of token events, followed by a **final metrics
  frame** carrying the full timings payload (§6). The PHP engine streams the same
  way via `response()->stream()` (PHP-06/07), so both columns behave identically
  from the UI's point of view. (PY-03)

> The transport detail that matters for fairness: tokens stream first, then exactly
> one final frame carries the metrics. The UI reads tokens as they arrive and the
> metrics strip from that last frame.

---

## 6. The timings payload (CONTRACT-03)

Both engines emit **the same keys with the same units** on the final metrics
frame. This is the comparison's currency:

| Key            | Unit | Meaning                                                        |
|----------------|------|----------------------------------------------------------------|
| `extract_ms`   | ms   | text extraction (PDF/txt → text)                              |
| `chunk_ms`     | ms   | splitting text into chunks (boundaries + overlap)            |
| `embed_ms`     | ms   | embedding the chunks (ingest) / the question (query)        |
| `retrieve_ms`  | ms   | pgvector cosine top-K lookup                                 |
| `ttft_ms`      | ms   | time to first token from the LLM                            |
| `total_ms`     | ms   | end-to-end for the measured operation                       |
| `loc`          | int  | static lines-of-code for that engine (the DX metric)        |

Rules:
- **Identical keys and units on both sides** — this is the AC of CONTRACT-03. A
  contract-shape test on the Python `/query` response (TEST-02) and the PHP side
  guard against drift.
- **Wall-clock via the right primitive per language:** `hrtime(true)` in PHP,
  `time.perf_counter()` in Python, both reported in milliseconds (METRIC-01).
- Each run is persisted to `runs` (DB-06 / METRIC-02) with engine + timings +
  params; the UI's "run N times" mode reports the **median** per phase, not a
  single noisy run (METRIC-03).

---

## 7. Persistence model (Phase 2 summary)

| Table        | Purpose                                                                 |
|--------------|-------------------------------------------------------------------------|
| `documents`  | id, filename, mime, byte_size, status, timestamps                       |
| `php_chunks` | id, document_id (fk cascade), content, `embedding vector(N)`, ordinal, token_count, timestamps |
| `py_chunks`  | identical shape to `php_chunks` (separate table — §3)                   |
| `runs`       | id, document_id, engine (`php`\|`py`), question, phase timings (jsonb), top_k, model fields, created_at |

`N` = Gemini embedding dimension from CONTRACT-01. The `vector` extension is
enabled by migration (DB-01) before any vector column is created.

---

## 8. Deployment shape (Phase 9)

- **Database:** Supabase (Postgres + pgvector). Enable `vector`, run migrations
  against it (DEPLOY-01).
- **Python service:** Railway, from the `/python` Dockerfile (DEPLOY-02).
- **Laravel app:** Railway, DB pointed at Supabase, `PYTHON_SERVICE_URL` pointed at
  the deployed Python service, `migrate --force`, assets built (DEPLOY-03).
- **Queue:** a Railway worker for `EmbedDocument`, or `QUEUE_CONNECTION=sync` to
  start (DEPLOY-04).

> ❌ **Netlify is not a valid target** — no PHP runtime, no long-lived Python
> process, no Postgres. Do not attempt it.

---

## 9. Decision record

Notable decisions live here (or in [`../LESSONS.md`](../LESSONS.md) when they came
from hitting a wall). Current load-bearing decisions:

- **Provider: Google Gemini for both chat and embeddings**, Groq as fallback —
  one key, permanent free tier, identical endpoints on both engines (fairness).
  Locked. The key is human-supplied (CONTRACT-04); billing stays off/capped
  (CONTRACT-06).
- **Two separate chunk tables** rather than one shared table — §3.
- **Embedding dimension is contract-derived, not assumed** — set `vector(N)` from
  Gemini's actual output dim (CONTRACT-01), reconciled before Phase 2 migrations.
