#!/usr/bin/env bash
# Despliegue remoto en el VPS (producción). Invocado por GitHub Actions o manualmente:
#   bash scripts/deploy-remote.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/root/erp-api}"
REPO_URL="${REPO_URL:-https://github.com/fergarcialopera/erp-api.git}"
REQUIRED_SERVICES="nginx php postgres redis mosquitto"

echo "==> Sincronizando código en ${APP_DIR}"
if [ -d "${APP_DIR}/.git" ]; then
  cd "${APP_DIR}"
  git fetch origin
  git pull origin main
else
  mkdir -p "$(dirname "${APP_DIR}")"
  git clone "${REPO_URL}" "${APP_DIR}"
  cd "${APP_DIR}"
fi

if [ ! -f .env.production ]; then
  echo "ERROR: falta .env.production en el servidor (${APP_DIR})"
  exit 1
fi

if ! grep -qE '^APP_ENV=prod' .env.production; then
  echo "ERROR: .env.production debe definir APP_ENV=prod"
  exit 1
fi

if ! grep -qE '^APP_PUBLIC_URL=' .env.production; then
  echo "ERROR: .env.production debe definir APP_PUBLIC_URL (p. ej. http://212.227.145.0:8080)"
  exit 1
fi
# Comprobar en localhost: en muchos VPS falla el curl a la IP pública desde el propio host (hairpin/NAT).
HEALTH_URL="http://127.0.0.1:8080/up"

COMPOSE=(docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml)

echo "==> Levantando contenedores"
"${COMPOSE[@]}" up -d --build

echo "==> Comprobando servicios en ejecución"
running_services="$("${COMPOSE[@]}" ps --services --status running)"
for svc in ${REQUIRED_SERVICES}; do
  if ! echo "${running_services}" | grep -qx "${svc}"; then
    echo "ERROR: servicio '${svc}' no está en ejecución"
    "${COMPOSE[@]}" ps
    exit 1
  fi
done

echo "==> Instalando dependencias PHP"
"${COMPOSE[@]}" exec -T php composer install --no-dev --optimize-autoloader

echo "==> Aplicando migraciones"
"${COMPOSE[@]}" exec -T php composer db:migrate

echo "==> Limpiando imágenes Docker sin uso"
docker image prune -f

echo "==> Health check API (${HEALTH_URL})"
if ! curl -fsS "${HEALTH_URL}"; then
  echo "ERROR: health check falló; revisar nginx:"
  "${COMPOSE[@]}" ps nginx
  "${COMPOSE[@]}" logs --tail=50 nginx
  exit 1
fi
echo ""
echo "Deploy completado."
