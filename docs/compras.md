# Módulo de compras a proveedores

## Separación entre compra y despacho

Una compra se registra desde el módulo de Compras y es la única operación nueva
que origina una cuenta por pagar (CXP). El despacho mayorista no crea deuda al
proveedor.

El despacho conserva su función operativa:

- el ticket identifica al cliente o almacén de destino;
- cada pesada conserva el proveedor, camión, placa, producto, aves y peso de
  origen;
- el cierre del ticket genera la cuenta por cobrar (CXC) del cliente;
- la trazabilidad proveedor-cliente se consulta desde tickets y pesadas, sin
  convertir el despacho en una compra.

Los comprobantes de compra históricos que se originaron desde despachos se
conservan y se incorporan al área de Compras como registros `LEGADO`. La
transición no vuelve a crear el comprobante: vincula la compra histórica al
mismo comprobante y copia sus detalles para consulta, sin cambiar saldos,
aplicaciones ni pagos.

## Registro de una compra

La cabecera se guarda en `compras` y sus productos en `compra_detalles`. Al
confirmarla se crea un comprobante financiero de operación `COMPRA`, naturaleza
`CARGO`, enlazado a la compra. Los pesos, cantidades y precios quedan congelados
como fueron registrados; las listas de precios del proveedor no revalorizan una
compra confirmada.

### Compra a crédito

La compra y su comprobante se crean en una sola transacción. El total queda como
saldo pendiente de la CXP y puede cancelarse posteriormente mediante pagos de
la empresa o depósitos directos de clientes al proveedor.

### Compra al contado

La compra, el comprobante y un movimiento `PAGO_PROVEEDOR` por el total se crean
en una sola transacción. El pago se aplica completamente a la CXP, por lo que el
comprobante queda pagado y el saldo de la cuenta propia seleccionada disminuye.
Si alguna validación falla, no se conserva ninguna de las tres operaciones.

### Compra histórica sin clasificar

La condición `LEGADO` identifica CXP creadas por la lógica anterior desde un
despacho, reconocibles por una `origen_clave` con prefijo `COMPRA:TICKET:`. La
migración de transición:

- crea una cabecera de compra con código `LEG-CXP-*`;
- la enlaza al comprobante CXP que ya existía;
- copia sus detalles a `compra_detalles`;
- conserva el estado, total, saldo pendiente y aplicaciones del comprobante;
- no crea un pago inicial ni intenta deducir si la operación original fue al
  contado o a crédito.

Por eso `LEGADO` se muestra como **Histórica sin clasificar** y no debe
interpretarse como una nueva deuda ni como una compra registrada por el flujo
actual. Es un registro de transición de solo lectura: no se anula desde Compras
y cualquier corrección excepcional debe conservar el comprobante financiero
original y resolverse como un ajuste auditado.

## Efecto de los cobros y pagos

| Flujo | Cuenta por cobrar del cliente | Saldo propio | Cuenta por pagar al proveedor |
| --- | --- | --- | --- |
| Cliente deposita a la avícola | Disminuye | Aumenta | No cambia |
| Cliente deposita al proveedor | Disminuye | No cambia | Disminuye por el mismo importe |
| La avícola paga al proveedor | No cambia | Disminuye | Disminuye |
| Compra a crédito | No cambia | No cambia | Aumenta |
| Compra al contado | No cambia | Disminuye | Se crea y cancela en la misma transacción |

Un pago directo debe aplicarse por el mismo importe tanto a CXC como a CXP. De
esta manera nunca se reduce la deuda del cliente y la del proveedor por valores
distintos.

## API y permisos

Las rutas requieren Sanctum, usuario activo y el permiso indicado:

| Método | Ruta | Permiso |
| --- | --- | --- |
| `GET` | `/api/v1/compras/catalogo` | `COMPRAS_VER` |
| `GET` | `/api/v1/compras` | `COMPRAS_VER` |
| `GET` | `/api/v1/compras/{id}` | `COMPRAS_VER` |
| `POST` | `/api/v1/compras` | `COMPRAS_REGISTRAR` |
| `POST` | `/api/v1/compras/{id}/anular` | `COMPRAS_ANULAR` |

Una compra al contado también requiere autorización para registrar el pago; su
anulación requiere autorización para anularlo. El listado permite distinguir
`CONTADO`, `CREDITO` y `LEGADO` sin mezclar una compra histórica sin clasificar
con las compras explícitas del modelo actual. Los totales y el estado financiero
del proveedor se consultan por moneda para no sumar PEN y USD.

`COMPRAS_VER` es el permiso base de consulta. Los permisos de registrar y anular
son capacidades adicionales y deben asignarse junto con el permiso de consulta
al rol que operará la interfaz.

## Inmutabilidad y anulación

Las compras confirmadas no se editan. Una compra a crédito solo puede anularse
si no tiene movimientos financieros activos; primero deben anularse sus pagos o
depositos aplicados. Al anular una compra al contado se crea la reversa de su
pago inicial y luego se anulan el comprobante y la compra dentro de la misma
transacción.

El pago inicial de una compra al contado no puede anularse de forma aislada
desde Finanzas; debe anularse la compra completa. Una compra anulada se presenta
con saldo efectivo cero, aunque el comprobante conserve internamente su saldo
histórico para auditoría.

El número del documento queda reservado mientras la compra está activa. Al
anularla se conserva el número en el historial, pero puede registrarse otra
compra corregida con el mismo documento. La consulta muestra el nombre y número
del proveedor congelados al registrar la compra, aunque el directorio cambie.

Cada alta usa un UUID de idempotencia. Repetir exactamente la misma solicitud
devuelve la compra existente y no duplica documentos, pagos ni saldos.
