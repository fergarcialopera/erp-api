# Testing

## Resumen rápido (comandos)

La API en desarrollo usa **siempre** la BD `erp`. Durante los tests, el contenedor `php` pasa temporalmente a `erp_test` y los tests de integración llaman a la API por HTTP (`nginx`).

```bash
docker compose up -d
docker compose exec php composer test:docker
```

**No ejecutar** solo `docker compose exec php vendor/bin/phpunit` para integración: dejaría la API en `erp_test` o fallaría el probe si `php` sigue en `erp`.

Solo unitarios (no cambian el contenedor `php`):

```bash
docker compose exec php vendor/bin/phpunit --testsuite Unit
```

Si la API quedó en modo test:

```bash
docker compose exec php composer test:docker:restore
```

---

## Flujo obligatorio

| Fase | Contenedor `php` (`DB_DATABASE`) | Tests de integración |
|------|----------------------------------|----------------------|
| Desarrollo normal | `erp` | — |
| Durante `composer test:docker` | `erp_test` | HTTP a `http://nginx` |
| Al finalizar (éxito o fallo) | `erp` (restaurado en `finally`) | — |

1. **Desarrollo:** `make up` → API en `:8080` apunta a `erp`. **Producción:** Nginx unificado con TLS en `:443` (API + SPA); `:80` solo para ACME, `/up` y redirección a HTTPS.
2. **Tests:** [`bin/run-tests.php`](bin/run-tests.php) → [`scripts/run-tests-docker.ps1`](scripts/run-tests-docker.ps1) / [`.sh`](scripts/run-tests-docker.sh): levanta infra, recrea `php` con [`docker-compose.test.yml`](docker-compose.test.yml), `migrate`+`seed` en `erp_test`, phpunit por HTTP y restaura `erp`.

**Ejecutar `composer test:docker` desde el host**, no con `docker compose exec php composer ...` (el contenedor no tiene CLI de Docker).
3. El PDO auxiliar de `BaseApiTestCase` solo prepara fixtures en `erp_test`; la API es la que atiende las peticiones de test.

---

## Arquitectura

Un solo PDO en [`bootstrap/app.php`](bootstrap/app.php) inyectado en todos los servicios. Los endpoints no abren conexiones propias.

- **Integración:** peticiones HTTP reales a `TEST_BASE_URL` (nginx → php-fpm).
- **Fixtures:** PDO directo a `erp_test` vía `TEST_DB_*` (migraciones, usuarios).
- **Probe:** usuario solo en `erp_test`; login por HTTP debe devolver 200 o los tests abortan con instrucciones de `test:docker`.

---

## Prerrequisitos

```bash
docker compose up -d
```

Requiere `php`, `nginx`, `postgres` y `redis`.

---

## Comandos Composer

| Script | Acción |
|--------|--------|
| `composer test:docker` | Host: `bin/run-tests.php` — infra, `erp_test`, migrate/seed, phpunit, restaura `erp` |
| `composer test:docker:up` | Solo activa API en `erp_test` |
| `composer test:docker:restore` | Solo restaura API a `erp` |

Variantes:

```bash
docker compose exec php composer test:docker -- --testsuite Integration
docker compose exec php composer test:docker -- tests/Integration/Auth/LoginEndpointTest.php
```

---

## Ficheros relevantes

| Fichero | Rol |
|---------|-----|
| [`phpunit.xml`](phpunit.xml) | `TEST_BASE_URL`, `TEST_DB_*` |
| [`docker-compose.test.yml`](docker-compose.test.yml) | `DB_DATABASE=erp_test` en `php` durante tests |
| [`scripts/run-tests-docker.ps1`](scripts/run-tests-docker.ps1) | Ciclo activar / test / restaurar (host) |
| [`BaseApiTestCase.php`](tests/Integration/Support/BaseApiTestCase.php) | Fixtures + HTTP + probe |

En SQL auxiliar usar `BaseApiTestCase::testPdo()`. **No** abrir PDO contra `erp`.

---

## IDE y agentes de IA

Integración:

```bash
docker compose exec php composer test:docker
```

Tras cambios en API/tests: ejecutar lo anterior y, si hace falta, `composer test:docker:restore`.

---

## Cobertura al implementar funcionalidad

- añadir tests si existe infraestructura previa
- cubrir éxito, not found, estado inválido, errores externos (MQTT, etc.)

---

## Errores frecuentes

| Síntoma | Causa | Solución |
|---------|--------|----------|
| `La API HTTP no está usando la base de datos de tests` | `php` en `erp` | `composer test:docker` |
| API no ve datos de desarrollo tras tests | API quedó en `erp_test` | `composer test:docker:restore` |
| `Unsafe test DB` | `TEST_DB_DATABASE=erp` | `erp_test` en `phpunit.xml` |
| Datos basura en `erp` | Tests sin ciclo test/restore | Limpiar `erp`; usar `test:docker` |
