#!/usr/bin/env sh
# Flujo: infra → API en erp_test → migrar/seed → PHPUnit → restaurar erp.
set -e

cd "$(dirname "$0")/.."

compose() {
  docker compose -f docker-compose.yml "$@"
}

compose_test() {
  docker compose -f docker-compose.yml -f docker-compose.test.yml "$@"
}

echo "==> Comprobando servicios (postgres, redis, nginx)"
compose up -d postgres redis nginx

echo "==> Activando API en modo test (DB erp_test)"
compose_test up -d --force-recreate php

echo "==> Preparando base de datos de tests (migrate + seed)"
compose_test exec -T php php bin/db.php migrate
compose_test exec -T php php bin/db.php seed

cleanup() {
  echo "==> Restaurando API a BD de desarrollo (erp)"
  compose up -d --force-recreate php
}

trap cleanup EXIT INT TERM

echo "==> Ejecutando PHPUnit"
compose_test exec -T php vendor/bin/phpunit "$@"
exit $?
