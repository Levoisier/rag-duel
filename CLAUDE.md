# CLAUDE.md — Working agreement for agents in this repo

This is the **operating contract** for any AI agent (Claude Code or otherwise)
working on **RAG Duel**. Read it before doing anything. Product context lives in
[`README.md`](README.md); the phased plan and acceptance criteria live in
[`BACKLOG.md`](BACKLOG.md); the architecture and fairness rules live in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). This file governs **how you
work**.

[`AGENTS.md`](AGENTS.md) exists to redirect other tools here and restates the two
git rules below. The two files must always agree; if you change one, change both.

---

## 0. Two rules about committing & pushing (non-negotiable)

These are owner-mandated and apply to **every** commit in this repo:

1. **No AI/agent signature, ever.** Do **not** add `Co-Authored-By: Claude`,
   `Generated with…`, session links, model identifiers, or any trailer that marks
   a commit, PR, or code comment as machine-made. Commits read as if the owner
   wrote them.
2. **Commit as the owner, and push directly to `main`.** Author every commit as
   **Cristian Cartagena `<c.zapata.iq@gmail.com>`** (set
   `git config user.name`/`user.email` locally if needed), and push straight to
   `main` unless the owner says otherwise. No feature-branch ceremony is required
   here.

> If a managed/remote session pre-assigns a different working branch, the owner's
> instruction to push to `main` still stands — follow it.

---

## 1. The workflow loop

For each unit of work (one backlog task at a time, top to bottom):

1. **Orient.** Read the relevant [`BACKLOG.md`](BACKLOG.md) task, this file, and
   [`LESSONS.md`](LESSONS.md) for traps already hit. Never invent a parameter that
   must match across engines — those live in the shared contract (Phase 3).
2. **Plan briefly.** State what you're about to do and the task's `AC:`
   (acceptance criterion) you're targeting. Ask before doing anything
   architecturally significant or ambiguous.
3. **Build the smallest correct thing** that satisfies the AC. Thin vertical slice
   over broad horizontal layer.
4. **Prove it.** The AC is only met when it's verifiable: a test passes, a command
   runs, an endpoint responds. The fairness properties (identical params, chunker
   parity, per-phase timings) are only real if a test demonstrates them.
5. **Document the _why_** in code comments at decision points, and update
   [`LESSONS.md`](LESSONS.md) if you learned something non-obvious.
6. **Commit** with the task ID in the subject (see §4), obeying §0.

Don't batch ten tasks into one commit. One coherent unit, then the next.

---

## 2. Architectural guardrails (do not cross without asking)

These come straight from the backlog. Crossing them invalidates the comparison —
the entire point of the project.

- **Fairness: parameters are locked and shared.** Embedding model + dimension, LLM
  model + temperature + max_tokens, `TOP_K`, `CHUNK_SIZE`, `CHUNK_OVERLAP`, and the
  cosine metric live in `infra/contract.env` (CONTRACT-01). **Both engines load
  them; neither hardcodes.** If you're typing a literal `1536` or a model name into
  engine code, stop.
- **Two chunk tables on purpose.** `php_chunks` and `py_chunks` are separate so
  each engine's ingestion is measured independently. Don't merge them.
- **Measure phases, never the HTTP envelope.** php-fpm (fresh per request) vs
  FastAPI (warm process) is not apples-to-apples. Compare the pipeline phases
  (`extract`/`chunk`/`embed`/`retrieve`/`ttft`/`total`), per CONTRACT-03.
- **Same provider, same fallback order, both engines.** Gemini primary → Groq
  fallback on 429/5xx/timeout, behind one `chat()`/`embed()` interface
  (CONTRACT-05). The order must be identical PHP vs Python.
- **Cost safety (CONTRACT-06).** Keep Gemini on the free tier with billing
  **disabled** (or hard-capped). Throttle app-side to stay under free RPM/RPD. No
  code path may silently incur charges.
- **Secrets stay out of git.** Keys live in `.env` (git-ignored); document them in
  `.env.example`. **When a task needs the Gemini key, STOP and ask the owner**
  (CONTRACT-04) — never invent a placeholder or switch providers.
- **The name is "RAG Duel"** — final and locked (BOOT-07). Use it everywhere; no
  variants.

If a task seems to require crossing one of these lines, ask the owner — don't
quietly cross it.

---

## 3. Commands — how to build, run, test, lint

The root `Makefile` (INFRA-06) is the front door. Targets:

| Command         | What it does                                                       |
|-----------------|-------------------------------------------------------------------|
| `make up`       | `docker compose up -d` — Postgres + pgvector for local dev        |
| `make migrate`  | `php artisan migrate` — schema against the container DB           |
| `make laravel`  | `php artisan serve` — UI + PHP engine on `:8000`                  |
| `make python`   | run the FastAPI service (uvicorn) on `:8001`                      |
| `make test`     | run **both** test suites (PHP + Python)                           |

Direct commands behind those targets (use these when iterating on one stack):

**PHP / Laravel (`/laravel`)**
- Run: `php artisan serve`
- Test: `php artisan test` (Pest/PHPUnit) — TEST-01
- Lint/format: `./vendor/bin/pint` ; static analysis: `./vendor/bin/phpstan analyse` — TEST-04
- Migrate: `php artisan migrate`

**Python / FastAPI (`/python`)**
- Setup: `uv sync`
- Run: `uv run uvicorn app.main:app --reload --port 8001`
- Test: `uv run pytest` — TEST-02
- Lint/format: `uv run ruff check .` / `uv run ruff format .` — TEST-04
- Health: `curl localhost:8001/health` → `200`

> These are the shapes the backlog converges on. A command is "real" once its
> phase lands — don't reference tooling no task introduces. CI (TEST-04) runs both
> suites + lint against a pgvector service.

---

## 4. Commit / branch / PR conventions

The git history is part of the record — keep it human-readable.

### Commits
- **Obey §0**: no AI signature; author as the owner; push to `main`.
- Put the **task ID** in the subject: e.g. `PHP-03: chunker with overlap`,
  `INFRA-01: pgvector compose service`.
- One logical change per commit. Concise imperative subject (≤ ~72 chars); a body
  that explains **why** when the diff doesn't make it obvious.
- Keep generated artifacts (lockfiles, etc.) consistent with their source **in the
  same commit**.

### Pull requests
- Do **not** open a PR unless the owner explicitly asks. Default flow is commit →
  push to `main`.
- If asked: imperative title; body states **what changed, why, and how it was
  verified** (which AC / tests hold). No AI-attribution footers. Link the backlog
  task(s) it closes.

---

## 5. Definition of done (per task)

A task is done when:

- [ ] Its `AC:` from [`BACKLOG.md`](BACKLOG.md) is **verifiably** met (test passes /
      command runs / endpoint responds).
- [ ] Any fairness property it claims (locked params, chunker parity, phase
      timings) is proven by a test.
- [ ] Code carries concise *why*-comments at the decision points.
- [ ] [`LESSONS.md`](LESSONS.md) is updated if anything non-obvious was learned.
- [ ] The box is checked in [`BACKLOG.md`](BACKLOG.md).
- [ ] It's committed per §0/§4 and pushed to `main`.

"It runs" is not done. "It runs, it's proven, and the comparison stays fair" is
done.
