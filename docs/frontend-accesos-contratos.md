# Contratos frontend de autenticación y accesos

Este documento describe los contratos consumidos por las vistas `auth.login`,
`admin.access-control` y `account.profile`. Todas las rutas API tienen el prefijo
`/api/v1`, aceptan y devuelven JSON, y requieren una sesión autenticada salvo el
inicio de sesión.

## Autenticación web

- `GET /login`: muestra el formulario global.
- `POST /login`: recibe `login`, `password` y `_token`; crea la sesión web y
  redirige al destino solicitado, a `/mi-cuenta` si la contraseña es temporal o
  al menú principal.
- `POST /logout`: invalida la sesión web y redirige a `/login`.
- `POST /api/v1/auth/logout`: cierra la sesión actual cuando la acción se ejecuta
  desde JavaScript.
- `POST /api/v1/auth/logout-all`: revoca tokens y sesiones del usuario.
- `GET /api/v1/auth/me`: devuelve `{ "data": User }`.

El objeto `User` de autenticación contiene como mínimo:

```json
{
  "id": 7,
  "name": "Ana Pérez",
  "email": "ana@empresa.test",
  "status": "ACTIVO",
  "roles": ["ADMINISTRADOR"],
  "module_codes": ["MODULO_USUARIOS_ROLES"],
  "must_change_password": false,
  "last_login_at": "2026-07-16T14:00:00Z"
}
```

## Catálogo de accesos

`GET /api/v1/admin/modules`

```json
{
  "data": {
    "modules": [
      {
        "code": "MODULO_FINANZAS",
        "name": "Finanzas y tesorería",
        "description": "Saldos, compras, cobros, pagos y cuentas.",
        "path": "/finanzas"
      }
    ],
    "branches": [
      { "id": 1, "code": "PRINCIPAL", "name": "Principal", "status": "ACTIVO" }
    ]
  }
}
```

## Usuarios administrados

### Listado

`GET /api/v1/admin/users`

Parámetros opcionales: `search`, `status`, `role_id`, `branch_id`, `page` y
`per_page`. La respuesta usa paginación estándar de Laravel (`data`, `links` y
`meta`). Cada elemento de `data` tiene esta forma:

```json
{
  "id": 9,
  "name": "Carlos Ruiz",
  "email": "carlos@empresa.test",
  "status": "ACTIVO",
  "branch_id": 1,
  "branch": { "id": 1, "code": "PRINCIPAL", "name": "Principal", "status": "ACTIVO" },
  "role_ids": [3],
  "roles": [{ "id": 3, "code": "TESORERO", "name": "Tesorero", "protected": false }],
  "module_codes": ["MODULO_FINANZAS"],
  "must_change_password": false,
  "last_login_at": "2026-07-16T14:00:00Z"
}
```

### Crear y editar

- `POST /api/v1/admin/users`
- `PUT /api/v1/admin/users/{id}`

```json
{
  "name": "Carlos Ruiz",
  "email": "carlos@empresa.test",
  "branch_id": 1,
  "role_ids": [3],
  "status": "ACTIVO",
  "password": "contraseña inicial",
  "password_confirmation": "contraseña inicial",
  "must_change_password": true
}
```

`password`, `password_confirmation` y `must_change_password` se envían al crear;
la edición ordinaria solo envía los datos de cuenta, sucursal, roles y estado.

### Acciones

- `PATCH /api/v1/admin/users/{id}/status` con `{ "status": "ACTIVO|INACTIVO" }`.
- `POST /api/v1/admin/users/{id}/reset-password` con `password`,
  `password_confirmation` y `must_change_password`. Siempre revoca las sesiones
  existentes de la cuenta.
- `POST /api/v1/admin/users/{id}/revoke-sessions` sin cuerpo.

## Roles

`GET /api/v1/admin/roles?per_page=100` devuelve una colección paginada con:

```json
{
  "id": 3,
  "code": "TESORERO",
  "name": "Tesorero",
  "protected": false,
  "users_count": 4,
  "module_codes": ["MODULO_FINANZAS"]
}
```

- `POST /api/v1/admin/roles`
- `PUT /api/v1/admin/roles/{id}`

Ambos reciben `name`, `code` y `module_codes`. El código comienza con una letra y
solo usa letras mayúsculas, números o guion bajo. `DELETE
/api/v1/admin/roles/{id}` elimina un rol no protegido y sin usuarios. El rol
`ADMINISTRADOR` se entrega con `protected: true` y no se puede modificar ni
eliminar desde la interfaz.

## Mi cuenta

- `GET /api/v1/account`: `{ "data": AccessUser }`.
- `PUT /api/v1/account`: recibe `{ "name": "...", "email": "..." }` y devuelve
  el usuario actualizado.
- `PUT /api/v1/account/password`: recibe `current_password`, `password`,
  `password_confirmation` y `revoke_other_sessions`.

## Errores

Los errores de validación usan HTTP `422` con la estructura estándar:

```json
{
  "message": "Los datos proporcionados no son válidos.",
  "errors": { "email": ["El correo ya está registrado."] }
}
```

La interfaz también contempla `401` (sesión vencida), `403` (sin permiso), `404`,
`409` y `429`, usando siempre el campo `message` como mensaje principal.
