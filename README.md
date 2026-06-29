# RAG Duel

> **PHP (Laravel) vs Python (FastAPI) RAG, side by side.**

RAG Duel is a single Laravel app that serves a **two-column web UI**. You upload a
document and ask a question once; the **left column** answers using a
retrieval-augmented-generation pipeline written natively in **PHP**, and the
**right column** answers using the *same* pipeline running in a **Python
FastAPI** service. Both columns hit **one shared Postgres + pgvector database**
with **identical, locked parameters** (same embedding model, same LLM, same
`TOP_K` / `CHUNK_SIZE` / `CHUNK_OVERLAP`, same similarity metric).

## Why this exists

Most "language X is faster than Y" demos cheat by changing the workload. RAG Duel
does the opposite: it **locks the workload** and measures honestly.

- **Honest DX comparison** — the same pipeline, built idiomatically in each
  language, so you can feel the developer-experience difference.
- **Honest per-phase performance** — every run reports timings for each phase
  (`extract`, `chunk`, `embed`, `retrieve`, plus `ttft` and `total`), never just
  the HTTP envelope. A `loc` (lines-of-code) badge per engine is the DX metric.
- **No contrived winner.** The pipeline is I/O-bound and dominated by identical
  API/DB calls, so totals are expected to land within noise — that's a legitimate
  finding, presented as-is. The real per-language delta lives in the CPU-bound
  phases (`extract` + `chunk`); a later stress mode surfaces it deliberately.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the design and the fairness
rules, and [`BACKLOG.md`](BACKLOG.md) for the full phased plan.

## Stack

| Concern            | Choice                                                            |
|--------------------|------------------------------------------------------------------|
| UI + PHP engine    | **Laravel** (Blade + Livewire) — `/laravel`                      |
| Python engine      | **FastAPI**, managed with **`uv`** — `/python`                   |
| Database           | **Postgres + pgvector** (`pgvector/pgvector:pg16`), one shared DB |
| Embeddings + LLM   | **Google Gemini** (AI Studio) for both, **Groq** fallback        |
| Local orchestration| `docker compose` (DB) + a root `Makefile`                        |
| Deploy target      | **Supabase** (DB) + **Railway** (both services)                 |

> ❌ **Not Netlify** — it runs no PHP, no long-lived Python process, and no
> Postgres. It is not a valid target for this project.

## Repository layout

```
rag-duel/
├─ laravel/            # Laravel app: two-column UI + native PHP RAG engine
├─ python/             # FastAPI service: Python RAG engine
├─ infra/              # shared contract (contract.env) + infra config
├─ docs/               # ARCHITECTURE.md, SETUP.md
├─ docker-compose.yml  # Postgres+pgvector for local dev
├─ Makefile            # make up / laravel / python / migrate / test
└─ BACKLOG.md          # the phased plan (source of truth)
```

## 60-second quickstart

> Full, from-scratch instructions live in [`docs/SETUP.md`](docs/SETUP.md). This
> is the short path once prerequisites (Docker, PHP/Composer, `uv`) are present.
> Some steps land in later backlog phases — the commands below are the shape the
> project converges on.

```bash
# 1. Clone and configure
git clone https://github.com/Levoisier/rag-duel.git
cd rag-duel
cp .env.example .env          # then paste your Gemini API key into .env

# 2. Bring up the shared Postgres + pgvector database
make up                       # docker compose up -d (Postgres with pgvector)

# 3. Create the schema (php_chunks, py_chunks, documents, runs, ...)
make migrate                  # php artisan migrate against the container DB

# 4. Run both engines (two terminals)
make laravel                  # php artisan serve  → http://127.0.0.1:8000  (UI + PHP engine)
make python                   # uvicorn FastAPI    → http://127.0.0.1:8001  (Python engine)

# 5. Open the UI, upload a doc, ask a question — watch both columns stream.
open http://127.0.0.1:8000
```

Sanity checks: the Python service answers `GET /health` → `200`, and
`php artisan migrate` reports success against the container DB.

## Demo

> _Screenshots / GIF placeholder._ The two-column UI and a live demo URL land in
> Phase 9 (`DEPLOY-07`). Until then, run it locally with the quickstart above.

<!-- ![RAG Duel — two-column comparison](docs/img/demo.png) -->
<!-- Live demo: (added in DEPLOY-07) -->

## Status

Early. This repo currently contains the **Phase 0** skeleton and documentation.
Implementation proceeds top-to-bottom through the phases in
[`BACKLOG.md`](BACKLOG.md). Contributor conventions and the definition of done are
in [`CLAUDE.md`](CLAUDE.md) / [`AGENTS.md`](AGENTS.md).

## License

See [`LICENSE`](LICENSE).
