# Sistema Pollos

Aplicación Laravel con vistas Blade y API interna. Utiliza PHP 8.3, Laravel 13
y Laravel Sanctum para autenticación mediante tokens Bearer.

## Estructura principal

- `resources/views`: menú, operación y directorio.
- `public/css` y `public/js`: recursos del frontend actual.
- `routes/web.php`: rutas de las vistas.
- `routes/api.php`: API JSON versionada bajo `/api/v1`.
- `app`: controladores, modelos, validaciones y middleware.
- `database`: migraciones, factories y seeders.

## Instalación local

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
```

En Laragon, la raíz pública del sitio debe ser:

```text
C:\laragon\www\sistema-pollos\public
```

Dirección recomendada:

```text
http://sistema-pollos.test
```

## Rutas web

- `/`: menú principal.
- `/operacion`: recepción y despacho mayorista.
- `/directorio`: clientes y proveedores.
- `/directorio/clientes/{id}`: tickets, registros e histórico de precios del cliente.
- `/directorio/proveedores/{id}`: pesadas, destinos y camiones del proveedor.
- `/finanzas`: saldos, cartera y trazabilidad de depositos.
- `/finanzas/entidades`: empresas receptoras y cuentas propias/externas.
- `/finanzas/movimientos/nuevo`: registro de cobros, pagos y reembolsos.

La vista de operación todavía utiliza el almacenamiento local existente. El
directorio de clientes y proveedores ya consulta y modifica la base de datos
mediante la API `/api/v1/clientes` y `/api/v1/proveedores`.

## Verificación

```bash
vendor\bin\pint --test
php artisan test
php artisan route:list
composer audit
```

Documentación adicional:

- [Arquitectura de la API](docs/arquitectura-api.md)
- [Esquema de base de datos](docs/esquema-base-datos.md)
- [Migraciones y base local](docs/migraciones-base-datos.md)
- [Despliegue en cPanel sin Node ni npm](docs/despliegue-cpanel.md)
- [Modulo de finanzas y trazabilidad](docs/finanzas.md)
