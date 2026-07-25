# API

Reglas de contrato HTTP/OpenAPI y diseño orientado al frontend.

---

## Principio clave

> La API debe estar orientada a **casos de uso reales del frontend**, no a entidades de base de datos.

Esto implica:

- evitar endpoints genéricos basados en tablas
- evitar respuestas con IDs que obliguen al frontend a hacer múltiples llamadas
- devolver datos listos para pintar en UI

Para autenticación, roles y aislamiento por clínica, ver [auth/AGENTS.md](../auth/AGENTS.md).

---

## Convenciones base

- Base path: `/api/v1`
- Formato: `application/json`
- Fuente de verdad del contrato: `docs/openapi.yaml`
- Autenticación y permisos: [auth/AGENTS.md](../auth/AGENTS.md)

---

## Diseño de endpoints

- Usar nombres de dominio claros: `/inventory`, `/entry-logs`, `/exit-logs`, `/ambientes`, etc.
- Evitar sufijos artificiales: `/table`, `/list`, `/detail`.
- Permitir subrutas de acción cuando representen una acción real de negocio (ej.: `/exit-logs/{id}/open-lock`).
- Mantener consistencia de nombres entre rutas, OpenAPI y tests.

---

## Reglas funcionales del dominio API actual

- `Inventory` es **solo lectura**.
- El stock se modifica **solo** mediante:
  - `POST /entry-logs`
  - `POST /exit-logs`

### ProductImports (SUPER_ADMIN)

Importación CSV de productos (export Odoo) con preview, resolución de conflictos y confirmación.

- Endpoints bajo `/api/v1/product-imports` (ver OpenAPI tag `ProductImports`).
- CSV **delimitado por punto y coma (`;`)**. Si llega delimitado por coma → error estructural `wrong_delimiter` (fallo de exportación).
- Decimales en formato español (`10,49`). Barcode `0`/vacío → sin barcode.
- Clave de conflicto: `Referencia interna` (permite `create_new` duplicando).
- Productos nuevos: `clinic_products.visible = FALSE`.
- Update: merge no destructivo (vacíos del CSV no borran).
- Códigos de error/warning por fila y estructurales: schema `ProductImportIssue` / `ProductImportIssueCode` en `docs/openapi.yaml` (el frontend traduce por `code`).

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
- `422` validación/estado inválido
- `401`/`403`/tenant `404`: ver [auth/AGENTS.md](../auth/AGENTS.md)

---

## Performance

Regla fundamental:

> Una vista del frontend debe poder resolverse con el menor número posible de llamadas.

Por tanto:

- usar joins en backend cuando sea necesario
- devolver datos enriquecidos
- evitar N+1 requests HTTP

---

## OpenAPI y tests (obligatorio)

Cada cambio de endpoint debe incluir en el mismo bloque de trabajo:

1. Implementación del endpoint
2. Actualización de `docs/openapi.yaml`
3. Tests de integración HTTP (ver [testing/AGENTS.md](../testing/AGENTS.md))

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
