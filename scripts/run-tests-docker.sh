#!/usr/bin/env sh
set -e

cd "$(dirname "$0")/.."

echo "==> Activando API en modo test (DB erp_test)"
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d --force-recreate php

cleanup() {
  echo "==> Restaurando API a BD de desarrollo (erp)"
  docker compose -f docker-compose.yml up -d --force-recreate php
}

trap cleanup EXIT INT TERM

docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit "$@"
