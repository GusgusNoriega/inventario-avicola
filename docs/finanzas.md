# Módulo de finanzas y trazabilidad de pagos

## Modelo operativo

La tabla `empresas` sigue representando al tenant operativo. Las empresas y
cuentas que reciben o envían dinero se administran por separado:

Este catálogo no reclasifica ni modifica las filas existentes de `empresas`;
los titulares y destinos bancarios se registran en la vista financiera.

- `entidades_financieras`: entidad `PROPIA` o `EXTERNA`.
- `cuentas_financieras`: banco, caja, billetera u otra cuenta de la entidad.
- Una entidad `EXTERNA` debe estar vinculada a un proveedor.
- `metodos_pago`: depósito, transferencia, efectivo, Yape, Plin, cheque u otro.

Los saldos no se editan directamente. Siempre se derivan del libro inmutable de
`pagos`: entradas a una cuenta menos salidas de esa cuenta. Una anulación crea
una reversa y restaura las aplicaciones CXC/CXP.

## Origen de las obligaciones

Al cerrar un ticket se genera un documento interno de venta idempotente:

- Venta o devolución: una CXC por ticket para el cliente, o para venta minorista
  anónima.
- El proveedor de origen permanece en cada pesada para conservar la trazabilidad
  logística, pero el despacho no genera una CXP.

Las CXP nuevas nacen exclusivamente de una compra confirmada en `/compras`:

- una compra a crédito deja el total pendiente;
- una compra al contado registra y aplica también el pago al proveedor, dejando
  el documento pagado y descontando la cuenta propia;
- los detalles congelan producto, aves, peso y precio ingresados en la compra.

Los documentos históricos de compra cuya `origen_clave` comienza con
`COMPRA:TICKET:` se incorporan al área de Compras con condición `LEGADO`. Cada
registro se vincula al mismo comprobante CXP y conserva sus detalles, saldos,
aplicaciones y pagos; la transición no crea otra obligación ni intenta
clasificarla como contado o crédito. Consulta `docs/compras.md` para el modelo
completo.

Una pesada cuyo documento de venta ya tiene cobros aplicados no puede editarse
ni anularse hasta anular primero esos movimientos financieros.

## Flujos de dinero

| Tipo | Origen | Destino | Aplicación |
| --- | --- | --- | --- |
| `COBRO_CLIENTE` | Cliente | Cuenta propia | CXC |
| `PAGO_DIRECTO` | Cliente | Cuenta externa del proveedor | CXC y CXP |
| `PAGO_PROVEEDOR` | Cuenta propia | Cuenta externa del proveedor | CXP |
| `COBRO_MINORISTA` | Comprador, identificado o anónimo | Cuenta/caja propia | CXC |
| `REEMBOLSO_CLIENTE` | Cuenta propia | Cliente | Abono CXC por devolución |
| `SALDO_INICIAL` | Apertura | Cuenta propia | Sin cartera |
| `AJUSTE` | Entrada o salida autorizada | Cuenta propia | Sin cartera |
| `TRANSFERENCIA_INTERNA` | Cuenta propia | Otra cuenta propia | Sin cartera |

Para `PAGO_DIRECTO`, el importe aplicado a CXC debe ser igual al importe
aplicado a CXP. Así, un depósito del cliente al proveedor disminuye por el mismo
valor la deuda del cliente con la avícola y la deuda de la avícola con ese
proveedor. Si el cliente deposita a una cuenta propia, se registra un
`COBRO_CLIENTE`: disminuye la CXC y aumenta el saldo propio, pero la CXP no
cambia.

No se permiten sobregiros en egresos ordinarios ni al anular un ingreso cuyos
fondos ya fueron utilizados. Los importes se calculan con BCMath y cada
petición de alta requiere una clave UUID de idempotencia.

## Vistas

- `/finanzas`: menú del módulo con acceso a saldos, compras, cuentas y movimientos.
- `/finanzas/saldos`: saldos por cuenta, cartera, pagos a proveedores y trazabilidad.
- `/finanzas/entidades`: entidades propias/externas y sus cuentas.
- `/finanzas/movimientos/nuevo`: cobros, pagos directos, pagos a proveedor,
  minorista y reembolsos.
- `/compras`: compras al contado y a crédito, deuda pendiente y documentos.
- `/compras/nueva`: registro transaccional de una compra a proveedor.

Las fichas de cliente y proveedor muestran su resumen financiero solo cuando el
usuario tiene `FINANZAS_VER`. En el proveedor se listan los depósitos directos
recientes, incluyendo cliente, cuenta destino y referencia. Su resumen acepta
`?moneda=PEN` o `?moneda=USD` para mantener separadas las carteras.

## API protegida

Todas las rutas bajo `/api/v1/finanzas` exigen Sanctum, usuario activo y el
permiso correspondiente. Las consultas usan `FINANZAS_VER`; las mutaciones usan
`CUENTAS_FINANCIERAS_GESTIONAR`, `PAGOS_REGISTRAR`, `PAGOS_ANULAR` o
`SALDOS_AJUSTAR`.

Los recursos principales son:

- `GET /catalogo`, `/cartera`, `/saldos`, `/trazabilidad` y `/movimientos`.
- CRUD por desactivación de `/entidades` y `/cuentas`.
- `POST /movimientos` y `POST /movimientos/{id}/anular`.
- `GET /clientes/{id}/resumen` y `/proveedores/{id}/resumen`.

El pago inicial generado por una compra al contado no admite anulación aislada
desde `/movimientos/{id}/anular`; se anula la compra completa para revertir de
forma atómica pago, comprobante y saldo.

## Reconstrucción de ventas

Primero se recomienda simular:

```bash
php artisan finanzas:reconstruir-obligaciones --dry-run
```

Si el reporte es correcto:

```bash
php artisan finanzas:reconstruir-obligaciones
```

El comando procesa cada ticket en una transacción independiente, continúa ante
errores aislados y puede repetirse sin duplicar documentos de venta. No crea ni
revaloriza compras a partir de pesadas.
