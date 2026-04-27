# API_GUIDELINES.md

## Objetivo

Reglas **solo de contrato API** (HTTP/OpenAPI).  
Las reglas generales de arquitectura, capas y antipatrones globales viven en `AGENTS.md`.

---

## Convenciones base

- Base path: `/api/v1`
- Formato: `application/json`
- Autenticación: `Authorization: Bearer <token>` en endpoints privados
- Fuente de verdad del contrato: `docs/openapi.yaml`

---

## Diseño de endpoints

- Usar nombres de dominio claros: `/inventory`, `/entry-logs`, `/exit-logs`, `/lockers`, etc.
- Evitar sufijos artificiales: `/table`, `/list`, `/detail`.
- Permitir subrutas de acción cuando representen una acción real de negocio (ej.: `/exit-logs/{id}/open-lock`).
- Mantener consistencia de nombres entre rutas, OpenAPI y tests.

---

## Reglas funcionales del dominio API actual

- `Inventory` es **solo lectura**.
- El stock se modifica **solo** mediante:
  - `POST /entry-logs`
  - `POST /exit-logs`

---

## Respuestas y errores

Para cualquier endpoint nuevo (y refactors de endpoints existentes), los handlers deben usar la clase común `App\Application\Http\ApiResponse` y sus métodos:

- `ApiResponse::success(...)`
- `ApiResponse::error(...)`

- Respuesta de éxito:
  - `{ "data": ..., "meta": {} }`
- Respuesta de error:
  - `{ "status": int, "title": string, "detail": string, "instance": string, "request_id": string }`

Usar códigos HTTP coherentes:

- `200`/`201` éxito
- `401` sin autenticación/token inválido
- `403` sin permisos
- `404` no existe o no pertenece al tenant (`clinic_id`)
- `422` validación/estado inválido

---

## Multi-tenant y permisos

- Todos los recursos de negocio deben resolverse por `clinic_id`.
- Si el `id` existe en otra clínica, responder `404` (no filtrar existencia).
- Definir permisos por rol en rutas y reflejarlos en tests.

---

## OpenAPI y tests (obligatorio)

Cada cambio de endpoint debe incluir en el mismo bloque de trabajo:

1. Implementación del endpoint
2. Actualización de `docs/openapi.yaml`
3. Tests de integración HTTP

Cobertura mínima:

- caso éxito
- validación (`422`)
- autenticación/autorización (`401`/`403`)
- aislamiento tenant (`404` cross-clinic)

---

## Checklist rápido antes de cerrar un cambio API

- [ ] Ruta y naming consistentes
- [ ] Handler usa `ApiResponse::success/error` y respeta envelope oficial
- [ ] OpenAPI actualizado
- [ ] Tests HTTP actualizados
- [ ] Reglas de `clinic_id` y roles cubiertas