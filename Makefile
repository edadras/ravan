# Ravan developer entry points. Production installs use ./install.sh.
#
# The two Python services each have a top-level `app` package, so they are
# tested in separate pytest processes; one process cannot import both.

SHELL := /bin/bash
PY ?= python3
COMPOSE ?= docker compose
DEV := $(COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml

.PHONY: help catalog test test-backend test-analysis test-asr accuracy lint up down logs shell fmt vendor

help:
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

catalog: ## Rebuild the signal catalog and the feature dictionary
	$(PY) catalog/build_catalog.py

test: test-analysis test-asr test-backend accuracy ## Everything

test-backend: ## Laravel feature and unit tests
	cd backend && RAVAN_CATALOG_PATH=$(CURDIR)/catalog/signal_catalog.json php artisan test

test-analysis: ## Analysis service tests
	cd analysis-service && $(PY) -m pytest -q

test-asr: ## Speech service tests
	cd asr-service && $(PY) -m pytest -q

accuracy: ## Feature-extraction accuracy benchmark, failing if a budget is missed
	node tools/accuracy/run.mjs --check

vendor: ## Download the MediaPipe runtime and models for local hosting
	./deploy/fetch-vendor.sh flutter_app/web/vendor

up: ## Start the development stack
	$(DEV) up --build

down: ## Stop everything, keeping volumes
	$(COMPOSE) down

logs: ## Follow the backend log
	$(COMPOSE) logs -f backend

shell: ## A shell in the backend container
	$(COMPOSE) exec backend bash

fmt: ## Format PHP with Pint
	cd backend && ./vendor/bin/pint
