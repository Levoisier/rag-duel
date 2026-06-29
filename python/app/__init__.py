"""RAG Duel — Python RAG engine package.

The right column of the duel: a FastAPI service running the same RAG pipeline as
the native PHP engine, against the same shared Postgres+pgvector DB but its own
`py_chunks` table. See docs/ARCHITECTURE.md for the API contract this exposes.
"""
