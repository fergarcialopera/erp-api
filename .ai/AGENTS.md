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

Backend de un ERP orientado a ambientes inteligentes en entornos clínicos.

El sistema permite:

- gestionar productos y stock
- controlar compartimentos físicos (ambientes)
- registrar movimientos de stock
- interactuar con dispositivos IoT (ESP32)
- abrir cerraduras físicas mediante MQTT

El backend está diseñado como **API-first**, siendo el frontend su principal consumidor.

---

## Workflow obligatorio para implementar cambios

**Regla general:** no modificar ningún fichero sin confirmación explícita del usuario.

Cada cambio debe **analizarse**, **aclararse** (dudas, alcance, criterios de éxito) y **planificarse** antes de tocar código. Solo después de esa planificación y la aprobación del usuario se implementa.

### Flujo estándar

1. **Analizar**
   - revisar contexto y código existente
   - localizar código relacionado
   - identificar restricciones y dependencias

2. **Aclarar**
   - resolver ambigüedades con el usuario si hace falta
   - confirmar alcance y criterios de éxito

3. **Planificar**
   - proponer pasos concretos y verificables
   - indicar impacto por capa/módulo
   - señalar riesgos o decisiones relevantes

4. **Esperar confirmación**
   - no editar ficheros hasta recibir aprobación explícita del plan

### Excepción: cambios claros y pequeños

Si el cambio es **evidente, acotado y de bajo riesgo** (p. ej. typo, ajuste menor acordado en la conversación, corrección obvia de un error señalado), el agente puede implementarlo directamente sin plan formal. En caso de duda, aplicar el flujo estándar.

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

## Calidad de código

**Norma general:** implementar código limpio, coherente con el proyecto y mantenible por cualquier desarrollador.

Principios:

- Aplicar **SOLID** y **Clean Code** de forma **pragmática**, no dogmática.
- Mantener **coherencia técnica** con patrones, capas y convenciones ya presentes en el repo.
- Priorizar claridad y mantenibilidad sobre sofisticación innecesaria.

Evitar:

- código espagueti (lógica mezclada, responsabilidades difusas, acoplamiento alto)
- sobreingeniería (abstracciones prematuras, capas de más, patrones “de libro” sin beneficio real)
- complejidad extrema orientada a un sistema ideal inalcanzable

Regla práctica: la solución debe ser la **más simple que respete la arquitectura del proyecto**. Si un cambio exige explicación larga para justificar su complejidad, simplificarlo.

Detalle de capas y antipatrones: [architecture/AGENTS.md](architecture/AGENTS.md).

---

## Filosofía

Este backend no es un CRUD clásico.

Es:

- un sistema orientado a eventos físicos
- una capa de control para hardware
- un ERP con lógica de negocio real
- una API optimizada para frontend
