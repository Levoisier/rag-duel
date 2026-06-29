"""Postgres access for the Python engine.

Phase 1 scope: just enough to prove connectivity to the shared pgvector DB
(INFRA-05). The pool/ORM choices for real ingestion + retrieval (Phase 5) layer
on top of this without changing how the DSN is resolved.
"""

import os

import psycopg

# One source for the connection string. DATABASE_URL is the single knob (it's what
# Supabase/Railway hand you in prod, DEPLOY-*); the localhost default matches the
# docker-compose credentials so `uv run uvicorn ...` works with no env set.
DEFAULT_DSN = "postgresql://ragduel:ragduel@127.0.0.1:5432/ragduel"


def dsn() -> str:
    return os.environ.get("DATABASE_URL", DEFAULT_DSN)


async def fetch_server_version() -> str:
    """Round-trip to Postgres and return its server version — the connectivity
    proof behind /db-ping. A fresh short-lived connection is fine here; it's a
    health check, not a hot path."""
    async with await psycopg.AsyncConnection.connect(dsn()) as conn:
        async with conn.cursor() as cur:
            await cur.execute("SHOW server_version")
            row = await cur.fetchone()
            return row[0] if row else "unknown"
