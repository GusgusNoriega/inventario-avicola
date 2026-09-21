# Sistema Pollos

Sistema de gestión avícola con Laravel 13, vistas Blade y API JSON bajo
`/api/v1`. Incluye recepción de pollo vivo, despachos mayoristas y minoristas,
despacho de productos, directorio, flota, control de javas y bandejas, compras,
finanzas, caja, cobranzas y reportes PDF e imágenes.

El acceso web utiliza sesiones; la API admite sesiones del mismo sitio y tokens
Bearer mediante Sanctum. Los accesos se controlan por empresa, módulos, roles y
permisos. La sincronización de recepción utiliza tokens propios por dispositivo
y sucursal.

## Estructura principal

- `app/Http`: controladores, validaciones y middleware.
- `app/Services`: reglas de operación, finanzas, reportes y sincronización.
- `app/Models`: modelos y relaciones de la base de datos.
- `resources/views`: pantallas y plantillas de reportes Blade.
- `public/css` y `public/js`: recursos que cargan las pantallas actuales.
- `resources/css` y `resources/js`: entradas para Vite y Tailwind.
- `public/build`: recursos compilados versionados para el despliegue.
- `routes/web.php`: rutas de las vistas.
- `routes/api.php`: API JSON versionada bajo `/api/v1`.
- `routes/reception-sync-*.php`: sincronización, dispositivos y registros recibidos.
- `database`: migraciones, factories y seeders.
- `tests/Feature`, `tests/Unit` y `tests/JavaScript`: pruebas automatizadas.
- `docs`: contratos, operación y despliegue.

## Requisitos

- PHP 8.3 o superior y Composer, con las extensiones requeridas por las dependencias.
- MySQL o MariaDB para la instalación; SQLite para las pruebas aisladas.
- Para compilar y probar JavaScript localmente: Node 22.12 o superior, o Node
  20.19 dentro de la rama 20, y npm. Node y npm no son necesarios en producción.

## Instalación local

En una instalación nueva, desde la terminal de Laragon:

```powershell
composer install
Copy-Item .env.example .env
```

Configurar `.env` antes de continuar: `APP_URL`, `FRONTEND_URLS`, conexión a la
base de datos y `ADMIN_EMAIL`/`ADMIN_PASSWORD` para crear el administrador inicial.
Mantener `DIRECTORY_API_PUBLIC=false`. Crear la base configurada en MySQL.
Si ya existe un `.env`, conservarlo y ajustar únicamente los valores necesarios.

Para esta carpeta del proyecto, la raíz pública del sitio en Laragon debe ser:

```text
C:\laragon\www\avicola\public
```

Configurar el host local y las URL del entorno de forma consistente, por ejemplo:

```dotenv
APP_URL=http://avicola.test
FRONTEND_URLS=http://avicola.test,http://localhost,http://127.0.0.1
```

Completar la instalación nueva:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
```

En una instalación existente, aplicar las migraciones pendientes con
`php artisan migrate`. La carga de datos iniciales es un paso separado: volver a
ejecutar los seeders puede actualizar configuración y credenciales iniciales.

## Rutas web

- `/`: menú principal.
- `/login`: inicio de sesión.
- `/operacion`: despacho mayorista 1.
- `/despacho-mayorista-2`: despacho mayorista 2.
- `/despacho-minorista` y `/despacho-minorista-2`: estaciones minoristas.
- `/recepcion-pollo-vivo/menu`: recepción, historial y sincronización.
- `/recepcion-pollo-vivo/sincronizados`: registros enviados por dispositivos.
- `/recepcion-pollo-vivo/dispositivos`: tokens de sincronización (administradores).
- `/despacho-productos`: catálogo, despacho, pagos, tickets y estados de cuenta.
- `/directorio`: clientes y proveedores.
- `/directorio/clientes/{id}`: tickets, registros e histórico de precios del cliente.
- `/directorio/proveedores/{id}`: pesadas, destinos y camiones del proveedor.
- `/finanzas`: menú de Finanzas y tesorería.
- `/finanzas/saldos`: saldos, cartera y trazabilidad de depósitos.
- `/finanzas/entidades`: empresas receptoras y cuentas propias/externas.
- `/finanzas/movimientos/nuevo`: registro de cobros, pagos y reembolsos.
- `/compras`: compras a proveedores al contado o a crédito.
- `/control-javas`: inventario, devoluciones y trazabilidad de javas y bandejas.
- `/administracion/accesos`: usuarios, roles y permisos.
- `/instalar`: instalación PWA y configuración de impresión.

Las operaciones registradas se guardan en la base de datos mediante la API. El
despacho mayorista también conserva borradores y preferencias en el navegador.
La API de recepción offline permite descargar catálogos y sincronizar registros;
la aplicación externa que trabaja sin conexión se implementa por separado.

## Verificación

```bash
vendor\bin\pint --test
php artisan test
npm run test:js
npm run build
php artisan route:list
composer audit
```

`phpunit.xml` configura SQLite en memoria para las pruebas PHP. No es necesario
migrar ni limpiar la base de Laragon para ejecutarlas. `npm run build` regenera
`public/build`; revisar e incluir esos cambios cuando se actualicen los recursos.

Documentación adicional:

- [Arquitectura de la API](docs/arquitectura-api.md)
- [API offline de recepción de pollo vivo: tokens, sincronización y reportes](docs/recepcion-pollo-vivo-sync.md)
- [Contrato OpenAPI de recepción offline](docs/openapi-recepcion-pollo-vivo.json)
- [Esquema de base de datos](docs/esquema-base-datos.md)
- [Migraciones y base local](docs/migraciones-base-datos.md)
- [Despliegue en cPanel sin Node ni npm](docs/despliegue-cpanel.md)
- [Módulo de finanzas y trazabilidad](docs/finanzas.md)
- [Compras](docs/compras.md)
- [PWA y modo kiosco](docs/pwa-y-modo-kiosco.md)
