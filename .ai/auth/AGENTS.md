# Autenticación y autorización

Flujo de tokens, rutas públicas y aislamiento multi-tenant.

---

## Convenciones

- Header: `Authorization: Bearer <token>` en endpoints privados
- Dos contextos de token:
  - **Clinic token**: flujo kiosk (`/api/v1/auth/clinic/login` → staff/PIN)
  - **User token**: sesión de usuario tras login normal o PIN
- Middleware: `AuthMiddleware` (validación) + `RoleMiddleware` (permisos por ruta)

Rutas públicas (sin token): login, recovery, listado de clínicas visibles, health/docs.  
Ver `AuthMiddleware` en `src/Application/Http/Middleware/AuthMiddleware.php` para la lista actual.

---

## Multi-tenant y permisos

- Todos los recursos de negocio deben resolverse por `clinic_id`.
- Si el `id` existe en otra clínica, responder `404` (no filtrar existencia).
- Definir permisos por rol en rutas y reflejarlos en tests.

Códigos relevantes:

- `401` — token ausente o inválido
- `403` — autenticado pero sin permiso para la acción
- `404` — recurso inexistente o fuera del tenant del usuario

---

## Tests de auth

Al tocar endpoints protegidos, cubrir en tests HTTP:

- petición sin token → `401`
- token válido sin rol suficiente → `403`
- recurso de otra clínica → `404`

Detalle del entorno de tests: [testing/AGENTS.md](../testing/AGENTS.md).
