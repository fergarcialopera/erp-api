# Arquitectura

El proyecto sigue:

- Clean Code
- SOLID (aplicados con criterio pragmático)
- DDD (Domain Driven Design)
- Arquitectura Hexagonal (Ports & Adapters)

---

## Criterio de implementación

El objetivo no es un diseño perfecto en abstracto, sino un código **legible, coherente y mantenible**.

- **Sí:** responsabilidades claras, nombres expresivos, dependencias en la dirección correcta, reutilizar lo que ya existe en el repo.
- **No:** capas o interfaces que solo añaden indirección, clases “dios”, métodos kilométricos, lógica duplicada o acoplada a detalles de infraestructura.

Cuando SOLID y la simplicidad entren en conflicto, **prevalece la simplicidad** siempre que se respeten las reglas de capas de este proyecto.

---

## Estructura real del repositorio (`src/`)

- `Application/`
- `Domain/`
- `Infrastructure/`
- `Modules/`

Además:

- `bootstrap/app.php` realiza el wiring de servicios, handlers, middlewares y rutas.
- `database/migrations` y `database/seeders` contienen migraciones y datos iniciales.

---

## Responsabilidades

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

---

## Regla de dependencias (guía)

- Evitar lógica de negocio en `Handlers`.
- `Modules/*/Services` no deben depender de detalles HTTP.
- `Domain` no debe depender de `Infrastructure`.
- `Infrastructure` implementa puertos/contratos definidos en capas superiores.

---

## Antipatrones a evitar

Los agentes deben evitar:

**Arquitectura y código**

- diseñar endpoints basados en tablas
- crear endpoints duplicados como:
  - `/orders/table`
  - `/inventory/list`
- devolver solo IDs en relaciones
- forzar múltiples llamadas desde frontend
- introducir lógica de negocio en handlers
- acoplar lógica de dominio a detalles de PDO/SQL o Redis
- código espagueti o clases con demasiadas responsabilidades
- abstracciones o capas innecesarias “por si acaso”
- introducir conceptos obsoletos del dominio:
  - ❌ `open-orders`
  - ❌ `dispenses`
  - ❌ `stock-exits`
