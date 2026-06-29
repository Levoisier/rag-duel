# `/python` — Python engine (placeholder)

This directory will hold the **FastAPI** service that runs the **right column's**
RAG pipeline (Phase 5), writing to and reading from the `py_chunks` table. It
exposes the HTTP contract that the Laravel app calls:

- `GET /health` — liveness (INFRA-03)
- `GET /db-ping` — Postgres connectivity check (INFRA-05)
- `POST /ingest` — multipart upload → extract, chunk, embed (CONTRACT-02, PY-01)
- `POST /query` — SSE stream of LLM tokens + a final metrics frame (CONTRACT-02/03, PY-03)

It is intentionally empty in Phase 0. The service is scaffolded with **`uv`** in
**INFRA-03** (`GET /health` → 200) and Dockerized in **PY-05**. Until then this
README is a placeholder so the repo tree from **BOOT-01** is complete.

See [`../docs/SETUP.md`](../docs/SETUP.md) for environment setup and
[`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) for the full contract.
