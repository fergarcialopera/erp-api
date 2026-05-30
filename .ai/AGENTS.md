# AGENTS.md — Guías para agentes de IA

Consulta solo la guía relevante al cambio. Índice:
| Guía | Cuándo leerla |
|------|---------------|
| [architecture/AGENTS.md](architecture/AGENTS.md) | Capas, DDD, dependencias, antipatrones |
| [api/AGENTS.md](api/AGENTS.md) | Diseño de endpoints, respuestas, OpenAPI, rendimiento |
| [auth/AGENTS.md](auth/AGENTS.md) | Tokens, roles, multi-tenant (`clinic_id`) |
| [iot/AGENTS.md](iot/AGENTS.md) | Apertura de cerraduras vía MQTT |
| [testing/AGENTS.md](testing/AGENTS.md) | PHPUnit, Docker, integración HTTP |

---

## Contexto del proyecto

Backend de un ERP orientado a lockers inteligentes en entornos clínicos.

El sistema permite:

- gestionar productos y stock
- controlar compartimentos físicos (lockers)
- registrar movimientos de stock
- interactuar con dispositivos IoT (ESP32)
- abrir cerraduras físicas mediante MQTT

El backend está diseñado como **API-first**, siendo el frontend su principal consumidor.

---

## Workflow obligatorio para implementar cambios

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

## Configuración

Nunca hardcodear valores.

Usar variables de entorno para:

- base de datos
- MQTT
- servicios externos

Ejemplo:

```
MQTT_HOST
MQTT_PORT
MQTT_USERNAME
MQTT_PASSWORD
```

---

## Filosofía

Este backend no es un CRUD clásico.

Es:

- un sistema orientado a eventos físicos
- una capa de control para hardware
- un ERP con lógica de negocio real
- una API optimizada para frontend
