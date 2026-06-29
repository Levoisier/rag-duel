# LESSONS.md — RAG Duel

A running log of **non-obvious** things learned the hard way: setup friction,
gotchas, surprising library behavior, and decisions made under ambiguity. The
point is that the next person (or the next agent) doesn't re-hit the same wall.

## How to use this file

- Append an entry whenever something **surprised** you or cost you time — not for
  routine work. If a task's `AC:` was harder to meet than expected, that's a
  lesson.
- **Newest entries at the top**, under the log.
- One entry per lesson. Keep it tight: what happened, why, and the takeaway.

### Entry format

```
### YYYY-MM-DD — <short title>  [TASK-ID]
**Context:** what you were doing.
**Surprise / problem:** what bit you.
**Resolution:** what fixed it (command, version, config, decision).
**Takeaway:** the durable rule so it doesn't happen again.
```

---

## Log

### 2026-06-29 — Phase 0 ambiguity calls  [BOOT-01…BOOT-07]
**Context:** Bootstrapping the repo skeleton and doc set from `BACKLOG.md` alone.
**Surprise / problem:** A few things weren't spelled out and had to be decided
without contradicting the backlog:
- The root `Makefile` is INFRA-06 (Phase 1), but BOOT-01's skeleton list doesn't
  include it. Docs reference `make up` / `make migrate` etc.
- The embedding **dimension** is fairness-critical and Gemini-specific, but the
  exact Gemini model/dim isn't fixed until CONTRACT-01.
- BOOT-01 lists `docker-compose.yml` as a *placeholder*; the real pgvector service
  is INFRA-01.
**Resolution:**
- Did **not** create the `Makefile` (it's Phase 1, and not in the BOOT-01 list).
  Docs describe the `make` targets as the shape the project converges on — the
  kickoff prompt itself sanctions referencing `make up` as a command that "will
  exist per the backlog."
- Did **not** hardcode any embedding dimension anywhere. Docs say the `vector(N)`
  columns get `N` from CONTRACT-01 (explicitly *not* OpenAI's 1536). `infra/` ships
  with a README placeholder, not a `contract.env` with invented numbers.
- Shipped `docker-compose.yml` as an explicit stub (`services: {}`) with a comment
  pointing at INFRA-01, so the tree is complete without pre-empting Phase 1.
- Set local git author to **Cristian Cartagena `<c.zapata.iq@gmail.com>`** and
  push to `main` per the owner's explicit instruction; recorded those two git
  rules in `CLAUDE.md` §0 and `AGENTS.md` so all agents inherit them.
**Takeaway:** When the backlog defers a value or a tool to a later phase, **point
at that phase** in the docs instead of inventing a concrete value now. Skeleton
files that belong to Phase 1 stay as labeled placeholders.

---

<!--
### YYYY-MM-DD — Example: pgvector PHP binding needs the extension first  [DB-07]
**Context:** Wiring pgvector reads/writes from Laravel via pgvector/pgvector-php.
**Surprise / problem:** Inserting a vector failed until `CREATE EXTENSION vector`
had actually run — the PHP binding assumes the column type already exists.
**Resolution:** Ran the DB-01 migration (enable `vector`) before the DB-03/04
table migrations; confirmed a round-trip in tinker.
**Takeaway:** Extension first, then vector columns, then the binding. Order the
migrations accordingly.
(Delete this example once there are two or three real entries above.)
-->
