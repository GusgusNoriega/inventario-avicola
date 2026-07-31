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

La vista de caja de efectivo agrega una capa operativa en
`movimientos_caja_efectivo`. Cada registro apunta al asiento vigente en
`pagos`, por lo que no mantiene un saldo paralelo. Si se corrigen el importe,
la dirección, la caja o la contraparte, el asiento anterior se reversa y la fila
operativa pasa a apuntar al reemplazo.

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
| `PAGO_DIRECTO` | Cliente | Cuenta externa del proveedor | CXC y CXP opcionales |
| `PAGO_PROVEEDOR` | Cuenta propia | Cuenta externa del proveedor | CXP |
| `SALDO_FAVOR_PROVEEDOR` | Registro manual histórico | Proveedor | CXP posterior |
| `COBRO_MINORISTA` | Comprador, identificado o anónimo | Cuenta/caja propia | CXC |
| `REEMBOLSO_CLIENTE` | Cuenta propia | Cliente | Abono CXC por devolución |
| `SALDO_INICIAL` | Apertura | Cuenta propia | Sin cartera |
| `AJUSTE` | Entrada o salida autorizada | Cuenta propia | Sin cartera |
| `TRANSFERENCIA_INTERNA` | Cuenta propia | Otra cuenta propia | Sin cartera |

Los registros de `/finanzas/caja-efectivo` resuelven siempre el método
`EFECTIVO` en el servidor y nunca solicitan una referencia o número de
operación. Un ingreso de cliente se registra como `COBRO_CLIENTE`; un traslado
entre cajas como `TRANSFERENCIA_INTERNA`; y un ingreso de otro origen o un gasto
administrativo, de transporte o depósito como `AJUSTE`. Una transferencia se
muestra como gasto en la caja de origen e ingreso en la caja de destino.

Un `PAGO_PROVEEDOR` puede registrarse sin aplicarlo de inmediato. En ese caso
el dinero sale una sola vez de la cuenta propia y el importe pendiente de
aplicar queda como saldo a nuestro favor con ese proveedor. Cuando se usa en
una compra, solo se crea la aplicación CXP: no se vuelve a descontar caja o
banco.

`SALDO_FAVOR_PROVEEDOR` incorpora un saldo que ya existía antes de usar el
sistema. Exige proveedor, moneda, importe y una observación que justifique el
origen; la fecha puede indicarse y, si se omite por API, se registra la hora
actual. No admite cuentas ni método de pago porque no representa un movimiento
de dinero actual. Su alta requiere `SALDOS_AJUSTAR`. Los depósitos,
transferencias y cargas manuales se mantienen separados en la trazabilidad,
aunque todos pueden utilizarse posteriormente contra CXP del mismo proveedor y
moneda.

El saldo a favor nunca se edita como un campo del proveedor. Se deriva siempre
de los movimientos activos elegibles menos sus aplicaciones CXP. Por eso una
anulación restaura las deudas aplicadas y elimina el crédito sin dejar saldos
huérfanos.

`PAGO_DIRECTO` puede registrarse aunque todavía no se hayan cargado las CXC o
CXP históricas. Las aplicaciones conocidas son opcionales e independientes; lo
no aplicado queda identificado en los resúmenes de cliente y proveedor para que
el saldo global siga considerando el pago. Si el cliente deposita a una cuenta
propia, se registra un `COBRO_CLIENTE`: disminuye la CXC y aumenta el saldo
propio, pero la CXP no cambia.

Los gastos ordinarios también pueden registrarse antes de cargar el saldo
inicial. En ese caso la cuenta propia mostrará temporalmente un saldo negativo,
que puede regularizarse después con `SALDO_INICIAL` o `AJUSTE`. La anulación de
un ingreso cuyos fondos ya fueron utilizados conserva su control específico.
Los importes se calculan con BCMath y cada petición de alta requiere una clave
UUID de idempotencia.

## Vistas

- `/finanzas`: menú del módulo con acceso a saldos, compras, cuentas y movimientos.
- `/finanzas/saldos`: saldos por cuenta, cartera, pagos a proveedores y trazabilidad.
- `/finanzas/entidades`: entidades propias/externas y sus cuentas.
- `/finanzas/caja-efectivo`: lista diaria por caja, ingresos, gastos, neto,
  transferencias, clientes, eliminación mediante reversa y caja predeterminada
  guardada en el navegador. Los destinos de gasto disponibles son
  Administrativo, Transporte, Depósito y Otra caja.
- `/finanzas/movimientos/nuevo`: cobros, pagos directos, pagos a proveedor,
  cargas manuales de saldo a favor, minorista y reembolsos.
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
- `GET /caja-efectivo/catalogo`, `GET /caja-efectivo`,
  `POST /caja-efectivo`, `PUT /caja-efectivo/{id}` y
  `DELETE /caja-efectivo/{id}`.
- `POST /movimientos`, `POST /movimientos/{id}/aplicaciones` y
  `POST /movimientos/{id}/anular`.
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
