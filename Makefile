# ERP — comandos Docker unificados (local y producción).
# El entorno se detecta por APP_ENV en .env (local) o .env.production (prod en el VPS).
#
# Uso:
#   make help
#   make up
#   make migrate

.DEFAULT_GOAL := help

SHELL := /bin/sh
SERVICE_PHP := php

# --- Detección de entorno ---
ifneq ($(wildcard .env),)
  ENV_FILE := .env
else ifneq ($(wildcard .env.production),)
  ENV_FILE := .env.production
else
  ENV_FILE := .env
endif

strip_env = $(shell echo "$(1)" | tr -d '\r' | sed 's/^["'\'']//;s/["'\'']$$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$$//')

APP_ENV := $(call strip_env,$(shell grep -E '^APP_ENV=' $(ENV_FILE) 2>/dev/null | head -1 | cut -d= -f2-))
APP_PUBLIC_URL := $(call strip_env,$(shell grep -E '^APP_PUBLIC_URL=' $(ENV_FILE) 2>/dev/null | head -1 | cut -d= -f2-))

ifeq ($(APP_ENV),prod)
  COMPOSE := docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml
  HEALTH_URL ?= http://127.0.0.1/up
else
  COMPOSE := docker compose --env-file .env -f docker-compose.yml -f docker-compose.dev.yml --profile dev
  HEALTH_URL ?= $(if $(APP_PUBLIC_URL),$(APP_PUBLIC_URL),http://127.0.0.1:8080)/up
endif

.PHONY: help check-env up down restart build logs ps composer-install migrate seed db-status test shell health

help: ## Lista los comandos disponibles
	@echo "Entorno detectado: APP_ENV=$(if $(APP_ENV),$(APP_ENV),<no definido>)  fichero=$(ENV_FILE)"
	@echo ""
	@echo "Comandos (mismo nombre en local y producción; el compose se elige por APP_ENV):"
	@grep -E '^[a-zA-Z0-9_-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

check-env: ## Valida que exista el fichero de entorno correcto
ifeq ($(APP_ENV),prod)
	@test -f .env.production || (echo "ERROR: APP_ENV=prod requiere .env.production en la raíz del proyecto." && exit 1)
	@grep -qE '^APP_ENV=prod' .env.production || (echo "ERROR: .env.production debe contener APP_ENV=prod." && exit 1)
else ifeq ($(APP_ENV),local)
	@test -f .env || (echo "ERROR: APP_ENV=local requiere .env (cp .env.example .env)." && exit 1)
else
	@echo "ERROR: APP_ENV debe ser 'local' o 'prod' (actual: '$(APP_ENV)'). Revisa $(ENV_FILE)."
	@exit 1
endif

up: check-env ## Levanta los contenedores en segundo plano
	$(COMPOSE) up -d

down: check-env ## Para y elimina contenedores
	$(COMPOSE) down

restart: check-env ## Reinicia el stack (down + up)
	$(COMPOSE) down
	$(COMPOSE) up -d

build: check-env ## Reconstruye imágenes y levanta el stack
	$(COMPOSE) up -d --build

logs: check-env ## Sigue los logs de todos los servicios
	$(COMPOSE) logs -f

ps: check-env ## Estado de los contenedores
	$(COMPOSE) ps

composer-install: check-env ## Instala dependencias PHP (sin dev en prod)
ifeq ($(APP_ENV),prod)
	$(COMPOSE) exec $(SERVICE_PHP) composer install --no-dev --optimize-autoloader
else
	$(COMPOSE) exec $(SERVICE_PHP) composer install
endif

migrate: check-env ## Aplica migraciones pendientes
	$(COMPOSE) exec $(SERVICE_PHP) composer db:migrate

seed: check-env ## Aplica seeders pendientes
	$(COMPOSE) exec $(SERVICE_PHP) composer db:seed

db-status: check-env ## Muestra estado de migraciones/seeders
	$(COMPOSE) exec $(SERVICE_PHP) composer db:status

test: check-env ## Ejecuta tests (integración vía bin/run-tests.php en local)
ifeq ($(APP_ENV),prod)
	@echo "ERROR: make test está pensado para desarrollo (APP_ENV=local)."
	@exit 1
else
	composer test:docker
endif

shell: check-env ## Abre bash en el contenedor PHP
	$(COMPOSE) exec $(SERVICE_PHP) bash

health: check-env ## Comprueba GET /up (health check)
	@echo "GET $(HEALTH_URL)"
	@curl -fsS "$(HEALTH_URL)" && echo ""
