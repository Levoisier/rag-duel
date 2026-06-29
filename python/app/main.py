"""FastAPI application entrypoint for the Python RAG engine.

Phase 1 surface only: a liveness probe (INFRA-03) and a DB connectivity ping
(INFRA-05). The real RAG contract — POST /ingest and POST /query (CONTRACT-02/03)
— lands in Phase 5; routes are kept thin here so those slot in without a rewrite.
"""

from fastapi import FastAPI

from app.db import fetch_server_version

app = FastAPI(title="RAG Duel — Python engine")


@app.get("/health")
def health() -> dict[str, str]:
    """Liveness probe (INFRA-03). No dependencies — answers even if the DB is down,
    so orchestration can tell 'process up' apart from 'process up but DB
    unreachable' (that distinction is what /db-ping covers)."""
    return {"status": "ok", "service": "ragduel-python"}


@app.get("/db-ping")
async def db_ping() -> dict[str, str]:
    """Connectivity check (INFRA-05): round-trips to the shared Postgres and returns
    its server version, proving the Python engine can reach the DB it shares with
    the PHP side."""
    version = await fetch_server_version()
    return {"status": "ok", "postgres_version": version}
