# Modulo de finanzas y trazabilidad de pagos

## Modelo operativo

La tabla `empresas` sigue representando al tenant operativo. Las empresas y
cuentas que reciben o envian dinero se administran por separado:

Este catalogo no reclasifica ni modifica las filas existentes de `empresas`;
los titulares y destinos bancarios se registran en la vista financiera.

- `entidades_financieras`: entidad `PROPIA` o `EXTERNA`.
- `cuentas_financieras`: banco, caja, billetera u otra cuenta de la entidad.
- Una entidad `EXTERNA` debe estar vinculada a un proveedor.
- `metodos_pago`: deposito, transferencia, efectivo, Yape, Plin, cheque u otro.

Los saldos no se editan directamente. Siempre se derivan del libro inmutable de
`pagos`: entradas a una cuenta menos salidas de esa cuenta. Una anulacion crea
una reversa y restaura las aplicaciones CXC/CXP.

## Obligaciones automaticas

Al cerrar un ticket se generan documentos internos idempotentes:

- Venta o devolucion: una CXC por ticket para el cliente, o para venta minorista
  anonima.
- Compra: una CXP por proveedor presente en las pesadas del ticket.
- El costo de compra congela peso, precio y referencia al historial del
  proveedor en `costos_compra_pesadas`.
- Si falta precio de compra, el costo queda `PENDIENTE`; al registrar el precio
  del proveedor se reintenta la valorizacion y se genera la CXP.

Una pesada con cobros o pagos aplicados ya no puede editarse ni anularse hasta
anular primero los movimientos financieros relacionados.

## Flujos de dinero

| Tipo | Origen | Destino | Aplicacion |
| --- | --- | --- | --- |
| `COBRO_CLIENTE` | Cliente | Cuenta propia | CXC |
| `PAGO_DIRECTO` | Cliente | Cuenta externa del proveedor | CXC y CXP |
| `PAGO_PROVEEDOR` | Cuenta propia | Cuenta externa del proveedor | CXP |
| `COBRO_MINORISTA` | Comprador, identificado o anonimo | Cuenta/caja propia | CXC |
| `REEMBOLSO_CLIENTE` | Cuenta propia | Cliente | Abono CXC por devolucion |
| `SALDO_INICIAL` | Apertura | Cuenta propia | Sin cartera |
| `AJUSTE` | Entrada o salida autorizada | Cuenta propia | Sin cartera |
| `TRANSFERENCIA_INTERNA` | Cuenta propia | Otra cuenta propia | Sin cartera |

No se permiten sobregiros en egresos ordinarios ni al anular un ingreso cuyos
fondos ya fueron utilizados. Los importes se calculan con BCMath y cada
peticion de alta requiere una clave UUID de idempotencia.

## Vistas

- `/finanzas`: saldos por cuenta, cartera, pagos a proveedores y trazabilidad.
- `/finanzas/entidades`: entidades propias/externas y sus cuentas.
- `/finanzas/movimientos/nuevo`: cobros, pagos directos, pagos a proveedor,
  minorista y reembolsos.

Las fichas de cliente y proveedor muestran su resumen financiero solo cuando el
usuario tiene `FINANZAS_VER`. En el proveedor se listan los depositos directos
recientes, incluyendo cliente, cuenta destino y referencia.

## API protegida

Todas las rutas bajo `/api/v1/finanzas` exigen Sanctum, usuario activo y el
permiso correspondiente. Las consultas usan `FINANZAS_VER`; las mutaciones usan
`CUENTAS_FINANCIERAS_GESTIONAR`, `PAGOS_REGISTRAR`, `PAGOS_ANULAR` o
`SALDOS_AJUSTAR`.

Los recursos principales son:

- `GET /catalogo`, `/cartera`, `/saldos`, `/trazabilidad` y `/movimientos`.
- CRUD por desactivacion de `/entidades` y `/cuentas`.
- `POST /movimientos` y `POST /movimientos/{id}/anular`.
- `GET /clientes/{id}/resumen` y `/proveedores/{id}/resumen`.

## Reconstruccion historica

Primero se recomienda simular:

```bash
php artisan finanzas:reconstruir-obligaciones --dry-run
```

Si el reporte es correcto:

```bash
php artisan finanzas:reconstruir-obligaciones
```

El comando procesa cada ticket en una transaccion independiente, continua ante
errores aislados y puede repetirse sin duplicar documentos.
