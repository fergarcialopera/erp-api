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

### Regla crítica: base de datos aislada

Los tests de integración **no deben usar nunca** la base de datos principal (`erp`).

| Componente | Base de datos |
|------------|---------------|
| Desarrollo normal (`docker compose up`) | `erp` |
| Tests de integración | `erp_test` |

Los tests hacen dos cosas a la vez:

1. **PDO directo** — migraciones, seed de usuarios de prueba (`BaseApiTestCase`).
2. **Peticiones HTTP** a `http://nginx` — pasan por el contenedor `php`.

Si `php` sigue con `DB_DATABASE=erp`, las peticiones HTTP contaminan la BD principal aunque phpunit use `erp_test` en PDO. Por eso es obligatorio levantar PHP con `docker-compose.test.yml` antes de ejecutar la suite de integración.

Al arrancar, `BaseApiTestCase` comprueba que la API responde con un usuario probe que solo existe en `erp_test`. Si falla, verás un error explícito pidiendo el override de compose.

### Prerrequisitos

Servicios Docker en marcha (desde la raíz del repo):

```bash
docker compose up -d
```

La BD `erp_test` se crea en el init de Postgres (`docker/postgres/init.sql`) o la crea `BaseApiTestCase` si no existe.

### Ejecutar tests (comando canónico)

**Usar siempre este flujo** para la suite completa (unitarios + integración):

```bash
docker compose exec php composer test:docker
```

Equivale a:

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d php
docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit
```

Solo tests unitarios (sin HTTP, no requieren `docker-compose.test.yml`):

```bash
docker compose exec php vendor/bin/phpunit --testsuite Unit
```

Suite concreta o archivo:

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit --testsuite Integration
docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit tests/Integration/Auth/LoginEndpointTest.php
```

### Tras ejecutar tests

Volver a la BD de desarrollo:

```bash
docker compose up -d php
```

### Configuración relevante

| Fichero | Rol |
|---------|-----|
| `phpunit.xml` | Suites `Unit` / `Integration`, variables `TEST_*` y `APP_ENV=testing` |
| `docker-compose.test.yml` | Override: `APP_ENV=testing`, `DB_DATABASE=erp_test`, `MQTT_DISABLED=true` en `php` |
| `bootstrap/app.php` | Con `APP_ENV=testing`, fuerza `DB_DATABASE` con sufijo `_test` |
| `tests/Integration/Support/BaseApiTestCase.php` | Bootstrap de BD de tests, migraciones, usuarios fixture, probe HTTP |

Variables de entorno en `phpunit.xml` (ya definidas; no cambiar a `erp`):

- `TEST_BASE_URL=http://nginx`
- `TEST_DB_DATABASE=erp_test`
- `TEST_DB_HOST=postgres`, `TEST_DB_PORT=5432`, `TEST_DB_USERNAME=erp`, `TEST_DB_PASSWORD=erp`

Para consultas auxiliares en tests de integración, usar `BaseApiTestCase::testPdo()`. **No** abrir PDO contra `dbname=erp`.

### Configuración para IDEs y agentes

Los tests de integración **deben ejecutarse dentro del contenedor `php`**, con la red Docker (`nginx`, `postgres`). Ejecutar `phpunit` en el host contra `localhost:5432` suele fallar o usar la BD equivocada.

#### Cursor / VS Code / PhpStorm

1. **PHPUnit / configuración**
   - Fichero de config: `phpunit.xml` (raíz del repo).
   - Bootstrap: `vendor/autoload.php` (definido en `phpunit.xml`).

2. **Ejecución recomendada (integración + unitarios)**  
   Configurar el comando de test del IDE como ejecución remota en Docker, no como PHP local:

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.test.yml exec -T php vendor/bin/phpunit
   ```

   O el atajo de Composer (levanta PHP en modo test y ejecuta phpunit):

   ```bash
   docker compose exec php composer test:docker
   ```

3. **Solo unitarios desde el IDE** (si el IDE lanza PHP en el contenedor sin override de test):

   ```bash
   docker compose exec -T php vendor/bin/phpunit --testsuite Unit
   ```

4. **Antes de la primera ejecución de integración en la sesión**, asegurar PHP en modo test:

   ```bash
   docker compose -f docker-compose.yml -f docker-compose.test.yml up -d php
   ```

5. **No** configurar el IDE para usar `DB_DATABASE=erp` al correr tests de integración.

#### Agentes de IA

Al terminar cambios que afecten a tests:

1. Ejecutar `docker compose exec php composer test:docker` (o el equivalente con `-f docker-compose.test.yml`).
2. No dar por válida una pasada de integración si PHP no se recreó con `docker-compose.test.yml`.
3. Tras validar, opcionalmente restaurar: `docker compose up -d php`.

### Cobertura al implementar funcionalidad

Cuando se implemente funcionalidad:

- añadir tests si existe infraestructura previa
- cubrir casos:
  - éxito
  - entidad no encontrada
  - estado inválido
  - errores externos (MQTT, etc.)

### Errores frecuentes

| Síntoma | Causa | Solución |
|---------|--------|----------|
| `La API HTTP no está usando la base de datos de tests` | `php` con `DB_DATABASE=erp` | `docker compose -f docker-compose.yml -f docker-compose.test.yml up -d php` y volver a ejecutar phpunit |
| `Unsafe test DB` / sufijo `_test` | `TEST_DB_DATABASE` apunta a `erp` | Usar `erp_test` en `phpunit.xml` |
| Login 401 en todos los tests de integración | API y PDO en BDs distintas | Mismo override de compose para `php` |
| Datos basura en producción/desarrollo | Tests ejecutados sin aislamiento | Limpiar `erp` manualmente; usar siempre `test:docker` |

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
