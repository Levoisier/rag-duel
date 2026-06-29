# RAG Duel — developer front door (INFRA-06).
# Thin wrappers over the real commands so both stacks are driven the same way and
# the docs (README/SETUP/CLAUDE) can point at one vocabulary. See docs/SETUP.md.

# Override on the CLI, e.g. `make laravel LARAVEL_PORT=9000`.
LARAVEL_PORT ?= 8000
PYTHON_PORT  ?= 8001

.PHONY: help up down migrate laravel python test test-php test-python

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start the shared Postgres+pgvector DB (detached)
	docker compose up -d

down: ## Stop the DB (keeps the named volume / data)
	docker compose down

migrate: ## Run Laravel migrations against the container DB
	cd laravel && php artisan migrate

laravel: ## Serve the Laravel app (UI + PHP engine) on LARAVEL_PORT
	cd laravel && php artisan serve --host=127.0.0.1 --port=$(LARAVEL_PORT)

python: ## Run the FastAPI service (Python engine) on PYTHON_PORT
	cd python && uv run uvicorn app.main:app --reload --host 127.0.0.1 --port $(PYTHON_PORT)

test: test-php test-python ## Run both test suites

test-php: ## PHP/Pest tests
	cd laravel && php artisan test

test-python: ## Python/pytest tests
	cd python && uv run pytest -q
