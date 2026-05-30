# ERP API - Guia de inicio rapido

Backend API para gestion de inventario clinico (PHP + Nginx + PostgreSQL + Redis) con Docker.

## 1) Requisitos previos

Instala estas herramientas antes de empezar:

- **Git**
- **Docker Desktop** (Windows/macOS) o Docker Engine + Docker Compose (Linux)

Recomendado:

- Cliente API como Postman, Insomnia o Bruno

Notas:

- En Windows, usa Docker Desktop con WSL2 habilitado.
- Asegurate de que Docker este abierto antes de ejecutar comandos.

## 2) Clonar el proyecto

```bash
git clone https://github.com/fergarcialopera/erp-api.git
cd erp-api
```

Si ya tienes el codigo en otra carpeta, entra directamente en la raiz del proyecto.

## 3) Configurar variables de entorno

Crea tu `.env` a partir de `.env.example`.

En Linux/macOS:

```bash
cp .env.example .env
```

En PowerShell (Windows):

```powershell
Copy-Item .env.example .env
```

Valores por defecto del proyecto:

- APP URL: `http://localhost:8080`
- PostgreSQL: `erp/erp`
- Redis: `redis:6379`
- Mosquitto: credenciales en `MQTT_USERNAME` / `MQTT_PASSWORD` (ver seccion 13)
- MQTT en el backend: `MQTT_HOST`, `MQTT_PORT`, etc. (apertura de cerraduras; ver seccion 13). Si `MQTT_HOST` esta vacio o `MQTT_DISABLED=true`, el API no conecta al broker

## 4) Levantar contenedores

```bash
docker compose up -d --build
```

Servicios que se levantan:

- `nginx` (API expuesta en el puerto `8080`)
- `php` (FPM)
- `postgres` (puerto `5432`)
- `redis` (puerto `6379`)
- `mosquitto` (broker MQTT, puerto `1883`; usado por el backend para comandos de cerradura)

## 5) Ejecutar migraciones y seeders

Este proyecto usa un sistema simple de SQL versionado:

- Migraciones: `database/migrations`
- Seeders: `database/seeders`
- Script CLI: `bin/db.php`

Ejecuta:

```bash
docker compose exec php composer db:migrate
docker compose exec php composer db:seed
docker compose exec php composer db:status
```

Comandos disponibles:

- `composer db:migrate` -> aplica migraciones pendientes
- `composer db:seed` -> aplica seeders pendientes
- `composer db:status` -> muestra estado

## 6) Verificar que todo funciona

Health endpoint:

```bash
curl http://localhost:8080/up
```

Debe responder `200` con un JSON similar a:

```json
{"data":{"status":"up"},"meta":{}}
```

## 7) Login de prueba

### Kiosk (clínica + PIN)

1. `GET /api/v1/auth/clinics`
2. `POST /api/v1/auth/clinic/login` — `{ "clinic_id": "11111111-1111-1111-1111-111111111111", "password": "clinic123" }`
3. `GET /api/v1/auth/staff` — header `Authorization: Bearer <clinic_access_token>`
4. `POST /api/v1/auth/login/pin` — `{ "user_id": "...", "pin": "1234" }` con el mismo header de clínica

### Clásico (email + contraseña)

- `POST http://localhost:8080/api/v1/auth/login`

```json
{
  "email": "admin@clinic.local",
  "password": "admin123"
}
```

### Email local (recuperación)

- Mailpit UI: `http://localhost:8025` (SMTP en puerto 1025)

## 8) Documentacion API

- OpenAPI YAML: `http://localhost:8080/docs`
- Swagger UI: `http://localhost:8080/docs/ui`

## 9) Ejecutar tests

- La API en desarrollo usa siempre la BD **`erp`**.
- Los tests de integración pasan la API a **`erp_test`** temporalmente (HTTP vía `nginx`) y al terminar se restaura **`erp`**.

```bash
# Desde el HOST (raíz del repo): erp_test → migrate/seed → phpunit → restaura erp
composer test:docker
# equivalente:
php bin/run-tests.php
php bin/run-tests.php -- --filter LockersTreeEndpointTest

# Si la API quedó en modo test por error
composer test:docker:restore
# o: docker compose -f docker-compose.yml up -d --force-recreate php
```

Solo unitarios (no cambian el contenedor `php`):

```bash
docker compose exec php vendor/bin/phpunit --testsuite Unit
```

No uses `vendor/bin/phpunit` a pelo para integración: dejaría la API apuntando a `erp_test`.

## 10) Flujo diario recomendado

1. Levantar servicios:

   ```bash
   docker compose up -d
   ```

2. Comprobar estado DB:

   ```bash
   docker compose exec php composer db:status
   ```

3. Al traer cambios nuevos:

   ```bash
   docker compose exec php composer db:migrate
   docker compose exec php composer db:seed
   ```

4. Ejecutar tests (desde el host, ver sección 9):

   ```bash
   composer test:docker
   ```

## 11) Problemas comunes (y solucion)

### Error 500 en login

Primero confirma que estas usando el puerto correcto del backend:

- Correcto: `http://localhost:8080`
- Incorrecto en este proyecto: `http://localhost:8081` (suele ser frontend u otro servicio)

### Docker no arranca contenedores

- Verifica que Docker Desktop este iniciado.
- Reintenta:

  ```bash
  docker compose down
  docker compose up -d --build
  ```

## 12) Estructura del proyecto (resumen)

- `src/Application` -> HTTP core, dispatcher, middlewares
- `src/Modules` -> modulos funcionales (Auth, Inventory, EntryLogs, ExitLogs, etc.)
- `src/Infrastructure` -> DB, Redis, router, logger, OpenAPI controller
- `database/migrations` -> SQL de esquema versionado
- `database/seeders` -> datos iniciales
- `docs/openapi.yaml` -> contrato API
- `.ai/` -> guias para agentes de IA y convenciones del proyecto (ver seccion 15)
- `tests/Integration` -> tests HTTP/integracion

## 13) MQTT (Mosquitto) — desarrollo local con autenticacion

**Eclipse Mosquitto** esta integrado como servicio Docker. El backend publica comandos de apertura de cerradura en el topic `lockers/{deviceId}/cmd` (payload `open`) cuando se invoca `POST /api/v1/exit-logs/{id}/open-lock`. Si `MQTT_HOST` esta vacio o `MQTT_DISABLED=true`, el API usa un publicador no-op (util en tests o sin broker).

### Que hace la infraestructura

- `allow_anonymous false` y contrasenas en un fichero **`passwd`** generado **solo dentro del volumen** `mosquitto_data` (no se sube al repositorio).
- En el **primer arranque**, un script de entrada ejecuta `mosquitto_passwd` con los valores de `MQTT_USERNAME` y `MQTT_PASSWORD` que lee Docker Compose desde tu `.env`.
- Sin TLS en esta fase (solo red local / laboratorio).

### Archivos creados o relevantes

| Archivo | Rol |
|--------|-----|
| `docker/mosquitto/mosquitto.conf` | Broker: puerto `1883`, sin anonimos, `password_file` en el volumen de datos. |
| `docker/mosquitto/docker-entrypoint.sh` | Crea `/mosquitto/data/passwd` si no existe y arranca Mosquitto como usuario `mosquitto`. |
| `.gitattributes` | Fuerza finales de linea LF en `*.sh` de Mosquitto (evita fallos en Windows). |

### Archivos modificados

| Archivo | Motivo |
|--------|--------|
| `docker-compose.yml` | Servicio `mosquitto`: variables `MQTT_USERNAME` / `MQTT_PASSWORD`, entrypoint, montaje del script; usuario root solo para generar `passwd` y hacer `su` al usuario del broker. |
| `.env.example` | Plantilla `MQTT_*` para Compose y el servicio `php` (copiar a `.env` y personalizar). |

### Variables de entorno (obligatorio para arrancar Mosquitto)

En la raiz del proyecto, tu `.env` (creado desde `.env.example`) debe incluir al menos:

- `MQTT_USERNAME`
- `MQTT_PASSWORD`

Docker Compose las inyecta en los contenedores `mosquitto` y `php`. En `php` controlan la conexion del backend al broker; en `mosquitto`, la autenticacion del broker.

Valores orientativos para **otro contenedor** o **futuro Laravel**: `MQTT_HOST=mosquitto`, `MQTT_PORT=1883`. Para un ESP32 en la LAN: `MQTT_HOST` sera la IP del PC o del servidor Docker.

### Arrancar Mosquitto con el resto del entorno

```bash
docker compose up -d --build
```

Solo Mosquitto:

```bash
docker compose up -d mosquitto
```

Si faltan `MQTT_USERNAME` o `MQTT_PASSWORD`, el contenedor saldra con error y un mensaje en logs.

### Comprobacion manual

1. **Puerto 1883** (PowerShell):

   ```powershell
   Test-NetConnection -ComputerName localhost -Port 1883
   ```

2. **Logs:**

   ```bash
   docker compose logs -f mosquitto
   ```

3. **Publicar / suscribir con credenciales** (sustituye usuario y contrasena por los de tu `.env`):

   Terminal A:

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -u erp_mqtt -P 'tu_password' -t demo/test -C 1
   ```

   Terminal B:

   ```bash
   docker compose exec mosquitto mosquitto_pub -h 127.0.0.1 -p 1883 -u erp_mqtt -P 'tu_password' -t demo/test -m "hola"
   ```

   En A debe mostrarse `hola`.

4. **Probar que la autenticacion es obligatoria** (sin usuario/contrasena deberia fallar o no recibir nada de forma fiable):

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -t demo/test -C 1 -W 3
   ```

   Con **contrasena incorrecta**:

   ```bash
   docker compose exec mosquitto mosquitto_sub -h 127.0.0.1 -p 1883 -u erp_mqtt -P incorrecta -t demo/test -C 1 -W 3
   ```

   En ambos casos no deberia completarse una suscripcion valida como con las credenciales buenas (mensajes de error en la salida o timeout `-W`).

### Regenerar el fichero `passwd`

Si cambias `MQTT_PASSWORD` (o `MQTT_USERNAME`) en `.env`, el fichero antiguo sigue en el volumen hasta que lo borres.

```bash
docker compose exec mosquitto sh -c "rm -f /mosquitto/data/passwd"
docker compose restart mosquitto
```

En el siguiente arranque se vuelve a crear `passwd` con los valores actuales del entorno.

### Persistencia y reversibilidad

- Configuracion y script: en el repo, montados solo lectura.
- `passwd` y datos persistentes del broker: volumen `mosquitto_data`; logs: volumen `mosquitto_log`.

Para deshacer la integracion: quita el servicio y volumenes en `docker-compose.yml`, elimina `docker/mosquitto/` si quieres, y opcionalmente `docker volume rm` sobre los volumenes Mosquitto.

### Nota de seguridad

Usuario/contrasena sin TLS solo es aceptable en **desarrollo local**. En produccion usa TLS y politicas de red adecuadas.

**Opcional (contenedor en la misma red Compose):** red por defecto con carpeta del proyecto `erp`: `erp_erp_net`; cliente efimero con `-h mosquitto -p 1883` y las mismas credenciales.

## 14) Referencia del repositorio

- GitHub: [fergarcialopera/erp-api](https://github.com/fergarcialopera/erp-api.git)

## 15) Documentacion para agentes de IA (`.ai/`)

Convenciones de arquitectura, API, auth, IoT y tests para asistentes de codigo y contribuidores. Punto de entrada:

- [`.ai/AGENTS.md`](.ai/AGENTS.md) — indice, contexto del proyecto y workflow (analizar, aclarar, planificar; confirmacion antes de editar ficheros)

Guias tematicas:

| Guia | Contenido |
|------|-----------|
| [`.ai/architecture/AGENTS.md`](.ai/architecture/AGENTS.md) | Capas, DDD, dependencias, antipatrones |
| [`.ai/api/AGENTS.md`](.ai/api/AGENTS.md) | Endpoints, respuestas, OpenAPI, rendimiento |
| [`.ai/auth/AGENTS.md`](.ai/auth/AGENTS.md) | Tokens, roles, multi-tenant |
| [`.ai/iot/AGENTS.md`](.ai/iot/AGENTS.md) | Apertura de cerraduras via MQTT |
| [`.ai/testing/AGENTS.md`](.ai/testing/AGENTS.md) | PHPUnit, Docker, integracion HTTP |

Detalle ampliado de tests: seccion 9 de este README y [`.ai/testing/AGENTS.md`](.ai/testing/AGENTS.md).
