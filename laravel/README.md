# `/laravel` — PHP engine + UI (placeholder)

This directory will hold the **Laravel** application that:

- serves the two-column comparison UI (Phase 6, Blade + Livewire), and
- runs the **left column's** RAG pipeline natively in PHP, in-process
  (Phase 4 — extract, chunk, embed, retrieve, answer), writing to and reading
  from the `php_chunks` table.

It is intentionally empty in Phase 0. The Laravel app is scaffolded in
**INFRA-02** (`php artisan serve` shows the welcome page). Until then this README
is a placeholder so the repo tree from **BOOT-01** is complete.

See [`../docs/SETUP.md`](../docs/SETUP.md) for how the app will be installed and
run, and [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) for where this side
sits in the system.
