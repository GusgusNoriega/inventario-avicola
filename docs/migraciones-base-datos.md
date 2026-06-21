# Migraciones de la base de datos

La estructura aprobada está implementada en `database/migrations` mediante
43 archivos. Cada archivo crea exactamente una tabla.

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

## Relaciones

Las migraciones incluyen 89 claves foráneas. Como política general:

- las tablas históricas usan `restrictOnDelete`;
- las referencias opcionales de responsables usan `nullOnDelete`;
- los detalles y tablas pivote usan `cascadeOnDelete` desde su cabecera;
- clientes, proveedores, almacenes, vehículos y productos deben desactivarse,
  no eliminarse cuando ya tengan movimientos.

## Base local de Laragon

El archivo `.env` está configurado para:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_pollos
DB_USERNAME=root
DB_PASSWORD=
```

La base `sistema_pollos` ya fue creada y migrada en MySQL 8.

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

Consultar el estado:

```bash
php artisan migrate:status
```

## Datos iniciales

El seeder registra:

- empresa y sucursal principal;
- pollo vivo, pelado y beneficiado;
- javas de 7.00 kg y 6.90 kg;
- almacenes 1 y 2;
- balanzas 1 y 2;
- roles de administrador y operador;
- permisos iniciales.

El administrador inicial solo se crea cuando `ADMIN_EMAIL` y
`ADMIN_PASSWORD` están definidos en `.env`.
