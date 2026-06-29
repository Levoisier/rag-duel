# AGENTS.md

This file exists to point other AI tools at the single source of truth.

➡️ **Read [`CLAUDE.md`](CLAUDE.md) before doing anything.** It is the operating
contract for all agents working on **RAG Duel** — workflow, architectural
guardrails (fairness rules), the real build/run/test/lint commands, and the
definition of done. The two files are kept in agreement; `CLAUDE.md` governs.

Supporting docs: [`README.md`](README.md) (what/why),
[`BACKLOG.md`](BACKLOG.md) (the phased plan + acceptance criteria),
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) (design + fairness + contracts),
[`docs/SETUP.md`](docs/SETUP.md) (from-scratch local install),
[`LESSONS.md`](LESSONS.md) (traps already hit).

## Two git rules every agent must follow (verbatim from CLAUDE.md §0)

1. **No AI/agent signature, ever** — no `Co-Authored-By: Claude`, no
   "Generated with…", no session links or model identifiers in commits, PRs, or
   code comments. Commits read as if the owner wrote them.
2. **Commit as the owner, push directly to `main`** — author every commit as
   **Cristian Cartagena `<c.zapata.iq@gmail.com>`** and push straight to `main`
   unless the owner says otherwise.

Everything else — guardrails, commands, definition of done — is in
[`CLAUDE.md`](CLAUDE.md).
