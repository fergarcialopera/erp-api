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
  echo "ERROR: .env.production debe definir APP_PUBLIC_URL (p. ej. https://erp.midominio.com)"
  exit 1
fi

if ! grep -qE '^SERVER_NAME=' .env.production; then
  echo "ERROR: .env.production debe definir SERVER_NAME (dominio con certificado, p. ej. erp.midominio.com)"
  echo "  Sin él nginx no puede renderizar prod.conf.template. Ver README §10 (HTTPS)."
  exit 1
fi

strip_env() {
  echo "$1" | tr -d '\r' | sed 's/^["'\'']//;s/["'\'']$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
}

SERVER_NAME="$(strip_env "$(grep -E '^SERVER_NAME=' .env.production | head -1 | cut -d= -f2-)")"
if [ ! -f "/etc/letsencrypt/live/${SERVER_NAME}/fullchain.pem" ]; then
  echo "ERROR: no existe /etc/letsencrypt/live/${SERVER_NAME}/fullchain.pem"
  echo "  Emite el certificado primero (README §10): certbot certonly --standalone -d ${SERVER_NAME}"
  exit 1
fi

FRONTEND_DIR="$(strip_env "$(grep -E '^FRONTEND_DIR=' .env.production 2>/dev/null | head -1 | cut -d= -f2-)")"
FRONTEND_DIR="${FRONTEND_DIR:-$(dirname "${APP_DIR}")/erp-frontend}"

FRONTEND_DIST_PATH="$(strip_env "$(grep -E '^FRONTEND_DIST_PATH=' .env.production 2>/dev/null | head -1 | cut -d= -f2-)")"

resolve_frontend_dist() {
  local candidate
  for candidate in \
    "${FRONTEND_DIST_PATH}" \
    "${FRONTEND_DIR}/dist" \
    "${FRONTEND_DIR}"; do
    if [ -n "${candidate}" ] && [ -f "${candidate}/index.html" ]; then
      echo "${candidate}"
      return 0
    fi
  done
  return 1
}

if ! FRONTEND_DIST_PATH="$(resolve_frontend_dist)"; then
  echo "ERROR: no se encuentra index.html del frontend."
  echo "  APP_DIR=${APP_DIR}"
  echo "  FRONTEND_DIR=${FRONTEND_DIR} (hermano de erp-api por defecto)"
  echo "  Rutas comprobadas:"
  echo "    - FRONTEND_DIST_PATH en .env.production (si está definido)"
  echo "    - ${FRONTEND_DIR}/dist"
  echo "    - ${FRONTEND_DIR}"
  if [ -d "${FRONTEND_DIR}" ]; then
    echo "  Contenido de ${FRONTEND_DIR}:"
    ls -la "${FRONTEND_DIR}" || true
    if [ -d "${FRONTEND_DIR}/dist" ]; then
      echo "  Contenido de ${FRONTEND_DIR}/dist:"
      ls -la "${FRONTEND_DIR}/dist" || true
    fi
  else
    echo "  El directorio ${FRONTEND_DIR} no existe."
  fi
  echo "  Genera el build en el VPS: cd ${FRONTEND_DIR} && npm ci && npm run build"
  echo "  O define FRONTEND_DIST_PATH=/ruta/al/dist en .env.production"
  exit 1
fi

export FRONTEND_DIST_PATH
echo "==> Frontend estático: ${FRONTEND_DIST_PATH}"

# Comprobar en localhost: en muchos VPS falla el curl a la IP pública desde el propio host (hairpin/NAT).
# /up se sirve por :80 (excepción a la redirección HTTPS); el SPA solo por :443, de ahí el
# curl -k (el certificado es del dominio, no de 127.0.0.1).
API_HEALTH_URL="http://127.0.0.1/up"
SPA_HEALTH_URL="https://127.0.0.1/"

COMPOSE=(docker compose --env-file .env.production -f docker-compose.yml -f docker-compose.prod.yml)

echo "==> Levantando contenedores (nginx en :80/:443, frontend en ${FRONTEND_DIST_PATH})"
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
if ! curl -fsSk -o /dev/null "${SPA_HEALTH_URL}"; then
  echo "ERROR: health check SPA falló; revisar montaje de ${FRONTEND_DIST_PATH}:"
  "${COMPOSE[@]}" ps nginx
  "${COMPOSE[@]}" logs --tail=50 nginx
  exit 1
fi

echo ""
echo "Deploy completado."
