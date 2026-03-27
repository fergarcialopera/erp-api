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

## 4) Levantar contenedores

```bash
docker compose up -d --build
```

Servicios que se levantan:

- `nginx` (API expuesta en el puerto `8080`)
- `php` (FPM)
- `postgres` (puerto `5432`)
- `redis` (puerto `6379`)

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

Endpoint:

- `POST http://localhost:8080/api/v1/auth/login`

Payload:

```json
{
  "email": "admin@clinic.local",
  "password": "admin123"
}
```

## 8) Documentacion API

- OpenAPI YAML: `http://localhost:8080/docs`
- Swagger UI: `http://localhost:8080/docs/ui`

## 9) Ejecutar tests

```bash
docker compose exec php vendor/bin/phpunit
```

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

4. Ejecutar tests:

   ```bash
   docker compose exec php vendor/bin/phpunit
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
- `tests/Integration` -> tests HTTP/integracion

## 13) Referencia del repositorio

- GitHub: [fergarcialopera/erp-api](https://github.com/fergarcialopera/erp-api.git)

