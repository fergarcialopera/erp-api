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

Cuando se implemente funcionalidad:

- añadir tests si existe infraestructura previa
- cubrir casos:
  - éxito
  - entidad no encontrada
  - estado inválido
  - errores externos (MQTT, etc.)

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
