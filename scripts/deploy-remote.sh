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

# Tras git pull, el proceso actual sigue con el script antiguo en memoria (p. ej. HEALTH_URL en :8080).
if [ "${DEPLOY_REEXEC:-}" != "1" ]; then
  export DEPLOY_REEXEC=1
  exec env DEPLOY_REEXEC=1 APP_DIR="${APP_DIR}" REPO_URL="${REPO_URL}" bash "${APP_DIR}/scripts/deploy-remote.sh"
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
  echo "ERROR: .env.production debe definir APP_PUBLIC_URL (p. ej. http://212.227.145.0)"
  exit 1
fi

FRONTEND_DIST_PATH="$(grep -E '^FRONTEND_DIST_PATH=' .env.production 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r' | sed 's/^["'\'']//;s/["'\'']$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
FRONTEND_DIST_PATH="${FRONTEND_DIST_PATH:-/root/erp-frontend/dist}"
export FRONTEND_DIST_PATH

if [ ! -f "${FRONTEND_DIST_PATH}/index.html" ]; then
  echo "ERROR: no se encuentra ${FRONTEND_DIST_PATH}/index.html (build del frontend en erp-frontend)"
  exit 1
fi

# Comprobar en localhost: en muchos VPS falla el curl a la IP pública desde el propio host (hairpin/NAT).
API_HEALTH_URL="http://127.0.0.1/up"
SPA_HEALTH_URL="http://127.0.0.1/"

COMPOSE=(docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml)

echo "==> Levantando contenedores (nginx en :80, frontend en ${FRONTEND_DIST_PATH})"
"${COMPOSE[@]}" up -d --build --force-recreate nginx
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

echo "==> Health check API (${API_HEALTH_URL})"
if ! curl -fsS "${API_HEALTH_URL}"; then
  echo "ERROR: health check API falló; revisar nginx:"
  "${COMPOSE[@]}" ps nginx
  "${COMPOSE[@]}" logs --tail=50 nginx
  exit 1
fi

echo "==> Health check SPA (${SPA_HEALTH_URL})"
if ! curl -fsS -o /dev/null "${SPA_HEALTH_URL}"; then
  echo "ERROR: health check SPA falló; revisar montaje de ${FRONTEND_DIST_PATH}:"
  "${COMPOSE[@]}" ps nginx
  "${COMPOSE[@]}" logs --tail=50 nginx
  exit 1
fi

echo ""
echo "Deploy completado."
