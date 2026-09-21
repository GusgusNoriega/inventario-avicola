# Migraciones de la base de datos

La estructura aprobada está implementada incrementalmente en
`database/migrations`. Las migraciones iniciales crean las tablas base y las
posteriores amplían los módulos de despacho, javas, finanzas, compras y
sincronización de recepción.

## Orden de creación

| Rango | Módulo |
|---|---|
| `000001`–`000010` | Empresa, sucursal, usuarios, tokens, roles y permisos |
| `000011`–`000015` | Caché y colas técnicas de Laravel |
| `000016`–`000024` | Terceros, almacenes, catálogos, conductores y vehículos |
| `000025`–`000026` | Listas de precios e historial de precios |
| `000027`–`000033` | Programación, jornadas, tickets, lecturas y pesadas |
| `000034`–`000036` | Movimientos y existencias de inventario |
| `000037`–`000042` | Comprobantes y pagos |
| `000043` | Auditoría |
| `2026_06_26`–`2026_07_04` | Evolución de despacho, flota, javas y minorista |
| `2026_07_12` | Finanzas, cuentas y trazabilidad de pagos |
| `2026_07_14` | Clientes internos, compras y aplicación posterior de pagos a proveedores |
| `2026_07_15`–`2026_09_05` | Evolución de minoristas, mayorista 2, recepción, productos, caja y reportes |
| `2026_09_10` | Tokens, registros, descargas y vínculos financieros de recepción offline |

## Relaciones

Las migraciones definen las relaciones con claves foráneas. Como política general:

- las tablas históricas usan `restrictOnDelete`;
- las referencias opcionales de responsables usan `nullOnDelete`;
- los detalles y tablas pivote usan `cascadeOnDelete` desde su cabecera;
- clientes, proveedores, almacenes, vehículos y productos deben desactivarse,
  no eliminarse cuando ya tengan movimientos.

## Base local de Laragon

Configurar `.env` con la conexión de la instalación. El ejemplo del repositorio usa:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_pollos
DB_USERNAME=root
DB_PASSWORD=
```

Crear la base indicada si aún no existe y aplicar las migraciones pendientes.

## Comandos

Aplicar migraciones pendientes:

```bash
php artisan migrate
```

Crear la estructura desde cero y cargar catálogos iniciales:

```bash
php artisan migrate:fresh --seed
```

Este último comando elimina todos los datos y solo debe utilizarse durante el
desarrollo.

### Limpiar únicamente los datos de prueba

Para reiniciar compras, finanzas, despachos, recepción sincronizada, jornadas e inventarios sin borrar
los clientes, proveedores, camiones ni choferes, ejecutar:

```bash
php artisan db:seed --class=DevelopmentDataCleanupSeeder
```

El comando solicita confirmación y solo funciona con `APP_ENV=local` o
`APP_ENV=testing`. No forma parte de `DatabaseSeeder`, por lo que una ejecución
normal de `php artisan db:seed` nunca dispara esta limpieza.

Se conservan también los roles de clientes/proveedores, las asignaciones de
camiones a proveedores, usuarios, permisos y catálogos técnicos necesarios
para que la aplicación siga funcionando. Se conservan las entidades y cuentas
financieras, así como los cobradores. Las listas e historiales de precios se
eliminan junto con los movimientos y deben configurarse nuevamente.

También se vacían los registros, claves de pesadas, operaciones, vínculos
financieros y descargas de sincronización, incluidos sus detalles. Se eliminan
los tokens de dispositivos, sesiones, tokens de acceso, caché y colas pendientes;
será necesario iniciar sesión y conectar los dispositivos nuevamente. La limpieza
solo afecta al servidor: los datos locales de una aplicación externa deben
reiniciarse por separado antes de reconectarla para evitar reenviar pruebas antiguas.

Consultar el estado:

```bash
php artisan migrate:status
```

## Datos iniciales

El seeder registra:

- empresa y sucursal principal;
- pollo vivo, muerto, pelado y beneficiado;
- javas de 7.00 kg, 6.90 kg y 6.80 kg y bandeja estándar;
- almacenes 1 y 2;
- balanzas mayoristas, minoristas, de recepción y de despacho de productos;
- roles de administrador y operador;
- permisos iniciales.

El administrador inicial solo se crea cuando `ADMIN_EMAIL` y
`ADMIN_PASSWORD` están definidos en `.env`.
De forma independiente, `INSTALLATION_ADMIN_PASSWORD` habilita las cuentas
temporales del seeder de instalación; dejarlo vacío si no se necesitan.
