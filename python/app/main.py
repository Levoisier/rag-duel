"""FastAPI application entrypoint for the Python RAG engine.

Phase 1 surface only: a liveness probe (INFRA-03). The DB ping (INFRA-05) and the
real RAG contract — POST /ingest and POST /query (CONTRACT-02/03) — land in later
tasks; routes are kept thin here so those slot in without a rewrite.
"""

from fastapi import FastAPI

app = FastAPI(title="RAG Duel — Python engine")


@app.get("/health")
def health() -> dict[str, str]:
    """Liveness probe (INFRA-03). No dependencies — answers even if the DB is down,
    so orchestration can tell 'process up' apart from 'process up but DB
    unreachable' (that distinction is what /db-ping will cover in INFRA-05)."""
    return {"status": "ok", "service": "ragduel-python"}
