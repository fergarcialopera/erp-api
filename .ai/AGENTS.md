# AGENTS.md — Backend Guidelines

## 🧠 Contexto del proyecto

Este proyecto es el backend de un ERP orientado a la gestión de lockers inteligentes en entornos clínicos.

El sistema permite:

- gestionar productos y stock
- controlar compartimentos físicos (lockers)
- registrar movimientos de stock
- interactuar con dispositivos IoT (ESP32)
- abrir cerraduras físicas mediante MQTT

El backend está diseñado como **API-first**, siendo el frontend su principal consumidor.

---

## 🎯 Principio clave

> La API debe estar orientada a **casos de uso reales del frontend**, no a entidades de base de datos.

Esto implica:

- evitar endpoints genéricos basados en tablas
- evitar respuestas con IDs que obliguen al frontend a hacer múltiples llamadas
- devolver datos listos para pintar en UI

---

## 🧭 Workflow obligatorio para implementar cambios

Antes de implementar cualquier cambio no trivial, el agente debe seguir este flujo:

1. **Investigar contexto y código actual**
   - revisar estructura existente
   - localizar código relacionado
   - identificar restricciones y dependencias

2. **Proponer plan de acción**
   - pasos concretos y verificables
   - impacto esperado por capa/módulo
   - riesgos o decisiones relevantes

3. **Esperar confirmación del usuario**
   - no iniciar implementación hasta recibir aprobación explícita del plan

Regla: la implementación comienza **solo después** de la confirmación del usuario.

---

## 🏗 Arquitectura

El proyecto sigue:

- Clean Code
- DDD (Domain Driven Design)
- Arquitectura Hexagonal (Ports & Adapters)

### Estructura real del repositorio (`src/`)

- `Application/`
- `Domain/`
- `Infrastructure/`
- `Modules/`

Además:

- `bootstrap/app.php` realiza el wiring de servicios, handlers, middlewares y rutas.
- `database/migrations` y `database/seeders` contienen migraciones y datos iniciales.

### Responsabilidades

- **Domain**
  - reglas de negocio
  - políticas de dominio
  - excepciones de dominio
  - contratos/puertos del dominio cuando aplique

- **Application**
  - casos de uso transversales (Actions)
  - orquestación entre dominio e infraestructura
  - capa HTTP base (Request/Response, dispatcher, middlewares)

- **Infrastructure**
  - acceso a datos con PDO/PostgreSQL
  - servicios externos (MQTT, etc.)
  - implementaciones concretas
  - utilidades transversales (router, redis, logging, openapi)

- **Modules**
  - organización por feature
  - `Handlers` (entrada HTTP por endpoint)
  - `Services` (lógica de aplicación por módulo)
  - `DTOs` y `Validators`

### Regla de dependencias (guía)

- Evitar lógica de negocio en `Handlers`.
- `Modules/*/Services` no deben depender de detalles HTTP.
- `Domain` no debe depender de `Infrastructure`.
- `Infrastructure` implementa puertos/contratos definidos en capas superiores.

---

## 🚫 Antipatrones a evitar

Los agentes deben evitar:

- diseñar endpoints basados en tablas
- crear endpoints duplicados como:
  - `/orders/table`
  - `/inventory/list`
- devolver solo IDs en relaciones
- forzar múltiples llamadas desde frontend
- introducir lógica de negocio en handlers
- acoplar lógica de dominio a detalles de PDO/SQL o Redis
- introducir conceptos obsoletos del dominio:
  - ❌ `open-orders`
  - ❌ `dispenses`
  - ❌ `stock-exits`

---

## 🔐 Apertura de cerradura (IoT)

El backend no abre cerraduras directamente.

### Flujo

1. Se invoca:
POST /exit-logs/{id}/open-lock

2. El backend:

- valida el `ExitLog`
- resuelve el `deviceId`
- publica en MQTT

### MQTT
Topic: lockers/{deviceId}/cmd
Payload: open


3. El ESP32:

- abre la cerradura
- gestiona el ciclo físico
- cierra cuando detecta puerta cerrada

---

## ⚡ Performance

Regla fundamental:

> Una vista del frontend debe poder resolverse con el menor número posible de llamadas.

Por tanto:

- usar joins en backend cuando sea necesario
- devolver datos enriquecidos
- evitar N+1 requests HTTP

---

## 🧪 Testing

### Resumen rápido (comandos)

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

### Flujo obligatorio

| Fase | Contenedor `php` (`DB_DATABASE`) | Tests de integración |
|------|----------------------------------|----------------------|
| Desarrollo normal | `erp` | — |
| Durante `composer test:docker` | `erp_test` | HTTP a `http://nginx` |
| Al finalizar (éxito o fallo) | `erp` (restaurado en `finally`) | — |

1. **Desarrollo:** `docker compose up` → API en `:8080` apunta a `erp`.
2. **Tests:** [`scripts/run-tests-docker.ps1`](scripts/run-tests-docker.ps1) (Windows/host) o [`scripts/run-tests-docker.sh`](scripts/run-tests-docker.sh) recrea `php` con [`docker-compose.test.yml`](docker-compose.test.yml), ejecuta phpunit por HTTP y restaura `erp`.

**Ejecutar `composer test:docker` desde el host**, no con `docker compose exec php composer ...` (el contenedor no tiene CLI de Docker).
3. El PDO auxiliar de `BaseApiTestCase` solo prepara fixtures en `erp_test`; la API es la que atiende las peticiones de test.

---

### Arquitectura

Un solo PDO en [`bootstrap/app.php`](bootstrap/app.php) inyectado en todos los servicios. Los endpoints no abren conexiones propias.

- **Integración:** peticiones HTTP reales a `TEST_BASE_URL` (nginx → php-fpm).
- **Fixtures:** PDO directo a `erp_test` vía `TEST_DB_*` (migraciones, usuarios).
- **Probe:** usuario solo en `erp_test`; login por HTTP debe devolver 200 o los tests abortan con instrucciones de `test:docker`.

---

### Prerrequisitos

```bash
docker compose up -d
```

Requiere `php`, `nginx`, `postgres` y `redis`.

---

### Comandos Composer

| Script | Acción |
|--------|--------|
| `composer test:docker` | Activa API test → phpunit → restaura API a `erp` |
| `composer test:docker:up` | Solo activa API en `erp_test` |
| `composer test:docker:restore` | Solo restaura API a `erp` |

Variantes:

```bash
docker compose exec php composer test:docker -- --testsuite Integration
docker compose exec php composer test:docker -- tests/Integration/Auth/LoginEndpointTest.php
```

---

### Ficheros relevantes

| Fichero | Rol |
|---------|-----|
| [`phpunit.xml`](phpunit.xml) | `TEST_BASE_URL`, `TEST_DB_*` |
| [`docker-compose.test.yml`](docker-compose.test.yml) | `DB_DATABASE=erp_test` en `php` durante tests |
| [`scripts/run-tests-docker.ps1`](scripts/run-tests-docker.ps1) | Ciclo activar / test / restaurar (host) |
| [`BaseApiTestCase.php`](tests/Integration/Support/BaseApiTestCase.php) | Fixtures + HTTP + probe |

En SQL auxiliar usar `BaseApiTestCase::testPdo()`. **No** abrir PDO contra `erp`.

---

### IDE y agentes de IA

Integración:

```bash
docker compose exec php composer test:docker
```

Tras cambios en API/tests: ejecutar lo anterior y, si hace falta, `composer test:docker:restore`.

---

### Cobertura al implementar funcionalidad

- añadir tests si existe infraestructura previa
- cubrir éxito, not found, estado inválido, errores externos (MQTT, etc.)

---

### Errores frecuentes

| Síntoma | Causa | Solución |
|---------|--------|----------|
| `La API HTTP no está usando la base de datos de tests` | `php` en `erp` | `composer test:docker` |
| API no ve datos de desarrollo tras tests | API quedó en `erp_test` | `composer test:docker:restore` |
| `Unsafe test DB` | `TEST_DB_DATABASE=erp` | `erp_test` en `phpunit.xml` |
| Datos basura en `erp` | Tests sin ciclo test/restore | Limpiar `erp`; usar `test:docker` |

---

## ⚙ Configuración

Nunca hardcodear valores.

Usar variables de entorno para:

- base de datos
- MQTT
- servicios externos

Ejemplo:
MQTT_HOST
MQTT_PORT
MQTT_USERNAME
MQTT_PASSWORD

---

## 🧠 Filosofía

Este backend no es un CRUD clásico.

Es:

- un sistema orientado a eventos físicos
- una capa de control para hardware
- un ERP con lógica de negocio real
- una API optimizada para frontend

---
