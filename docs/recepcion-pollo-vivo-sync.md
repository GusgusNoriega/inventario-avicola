# API de sincronización de Recepción de pollo vivo

Versión del contrato: **1**. Base: `/api/v1/recepcion-pollo-vivo/sync`.

Esta API prepara una futura aplicación para descargar datos, trabajar con su propia base local y enviar recepciones y tickets cuando vuelva la conexión. La aplicación móvil o de escritorio y su base local se implementan por separado. El contrato importable está en [openapi-recepcion-pollo-vivo.json](openapi-recepcion-pollo-vivo.json).

## Alcance e independencia

Los registros enviados por esta API se guardan en tablas dedicadas de sincronización y conservan el conteo operativo de recepciones y despachos. Las recepciones a almacén (`kind: "reception"`, carriles 1–4) no generan deuda. Los despachos directos a clientes (`kind: "ticket"`, carriles 5/6) sí generan una venta y una cuenta por cobrar en el servidor al aceptarse la sincronización. La deuda se consulta y gestiona desde finanzas en la web.

La aplicación trabaja únicamente con la recepción y el despacho: no recibe ni envía precios, importes, saldos, deudas ni cobros. La API no genera movimientos de inventario, caja, javas o bandejas, ni registra cobros automáticos. La cantidad y tara de javas forman parte de cada pesada para calcular pollos y peso neto; no representan un préstamo ni una devolución de envases.

La descarga inicial incluye los catálogos necesarios y el historial de este módulo. Los registros existentes capturados en la web se exportan como `source: "web"`, `editable: false`; la aplicación debe conservarlos solo para consulta y reportes. Los registros sincronizados tienen identidad UUID propia y se consultan en la web desde **Recepción de pollo vivo → Registros sincronizados**. Esta vista tiene filtros, detalle de pesadas y una impresión de la página consultada; sus totales corresponden a todos los resultados del filtro.

Los catálogos compartidos son de solo lectura. El alta o cambio de clientes, proveedores, vehículos, conductores, almacenes o tipos de java se realiza en la web, seguido de una nueva descarga. Se incluyen catálogos inactivos para poder interpretar y subir hechos anteriores a una desactivación; la app debe ofrecer solo opciones activas para nuevas capturas. Cada token limita los datos a una empresa y sucursal; cambiar parámetros en la petición no amplía ese alcance.

## Activación y token

1. Instalar las migraciones de esta versión con `php artisan migrate`. En un despliegue automatizado usar `php artisan migrate --force` según el procedimiento habitual del proyecto.
2. Entrar con un administrador autorizado y abrir **Recepción de pollo vivo → Conectar dispositivos** (`/recepcion-pollo-vivo/dispositivos`).
3. Elegir sucursal, nombre del equipo y vigencia (1 a 365 días; valor inicial 90). El formulario puede generar el UUID del dispositivo o recibir uno existente.
4. Copiar el token que se muestra una sola vez e ingresarlo en la aplicación. No se puede recuperar después; el servidor conserva únicamente su hash.
5. Configurar la URL HTTPS del sistema y verificar `GET /status` antes de descargar los datos.
6. Antes de sincronizar despachos a clientes, configurar en la web un precio positivo de venta de pollo vivo para el cliente o en la lista general. El servidor usará el precio vigente cuando acepte el despacho.

Todas las peticiones usan:

```http
Authorization: Bearer rpv_<token_copiado>
Accept: application/json
Content-Type: application/json
```

No se envía `device_id`, `company_id` ni `branch_id` para elegir el ámbito de una petición. Esos valores quedan fijados al emitir el token. No se usa el token normal de inicio de sesión ni cookies del navegador. Guardar el token en el almacenamiento seguro del dispositivo; no incluirlo en URLs, reportes, capturas de pantalla ni registros de diagnóstico.

Para rotar la credencial sin cambiar la identidad del equipo, emitir otra con el mismo UUID de dispositivo y revocar la anterior. Revocación, vencimiento o cambio de contraseña del emisor invalidan la credencial. La autorización también exige que el usuario, empresa, sucursal y acceso al módulo sigan vigentes. La app debe conservar sus datos pendientes si recibe 401 o 403 y solicitar una nueva credencial, sin borrar la cola local.

## Flujo de trabajo de la aplicación

### Primera descarga y actualización completa

1. Comprobar conectividad/autorización con `GET /status` y validar `schema_version`.
2. Ejecutar `POST /snapshots` con cuerpo `{}`. Se genera una descarga materializada estable durante 24 horas.
3. Guardar el identificador y solicitar `GET /snapshots/{id}?after=0&limit=200`.
4. Guardar cada página en tablas temporales locales junto con el cursor confirmado. Para la siguiente página usar exactamente `next_after`. Continuar hasta `has_more: false` y `next_after: null`.
5. Aplicar la descarga completa en una transacción local. Mantener los borradores y operaciones locales pendientes, usando UUID para enlazar los registros ya conocidos.
6. Si se corta internet, reintentar la misma página o retomar el último cursor confirmado. Si venció la descarga, descartar solamente sus tablas temporales y crear otra.
7. Después de importar y confirmar localmente todos los datos, liberar la descarga con `DELETE /snapshots/{id}`. Devuelve 204 sin cuerpo. Un reintento después de liberarla devuelve 404 y puede tratarse como liberación ya completada.

Cada dispositivo puede mantener hasta tres snapshots vigentes. Si se pierde la respuesta de creación, consultar `GET /snapshots`: devuelve `data.snapshots` con los metadatos de las descargas vigentes para recuperar su ID. Reanudar o liberar los anteriores para evitar agotar el cupo. La identidad de la descarga queda ligada al UUID de dispositivo; una credencial renovada del mismo equipo puede retomarla. La limpieza de descargas vencidas está disponible mediante `php artisan reception-sync:prune-snapshots`; también se limpian las vencidas del dispositivo al crear una descarga nueva. Una descarga vencida que ya fue limpiada devuelve 404 en vez de 410.

El snapshot no cambia cuando otros dispositivos registran información durante la descarga. Un snapshot completo nuevo es el mecanismo de actualización de catálogos e historial. **`GET /records?after=...` no es un flujo de cambios:** pagina por ID y una corrección de un ID ya leído no aparecerá avanzando el cursor. Para convergencia completa descargar otro snapshot; para resolver un conflicto puntual consultar `GET /records/{uuid}`.

### Operar sin internet

- Guardar localmente catálogos, contexto de empresa y sucursal, zona horaria, hora de corte, historial y versiones confirmadas por el servidor.
- Asignar un UUID a cada recepción/ticket y a cada pesada desde su creación; no sustituirlos al sincronizar ni al reimprimir.
- Guardar fecha/hora de captura con offset de zona horaria y la hora de corte empleada. Los reportes se agrupan por `operating_date`, no por el día de subida.
- Conservar la tara y pollos por java elegidos en cada pesada como datos históricos. Una modificación posterior del catálogo no debe recalcular documentos existentes.
- Construir tickets y reportes con la base local; no depender de una consulta online para imprimir.
- Mantener una cola persistente de operaciones con su UUID `operation_id`, cuerpo exacto y estado. Un guardado local no equivale a confirmación del servidor.

### Enviar al recuperar conexión

Enviar hasta 50 operaciones por `POST /push`. Cada operación se confirma en su propia transacción: un conflicto o error de validación de un registro no cancela los demás. Procesar cada resultado del lote, incluso cuando HTTP sea 200.

| Resultado | Acción local |
|---|---|
| `applied` | Guardar documento y revisión devueltos; retirar la operación de la cola pendiente. |
| `replayed` | La misma operación ya se procesó; aceptar la respuesta sin duplicar el registro. |
| `conflict` | Conservar cambios locales; comparar con el documento actual y solicitar una decisión explícita del operador. |
| `rejected` | Conservar operación y mostrar errores de campo; corregir el contenido o resolver la causa en la web y crear otro `operation_id`. |

Si hay timeout, pérdida de conexión o error de servidor, reintentar el **mismo cuerpo con los mismos IDs de operación**. Algunas operaciones anteriores del lote podrían haberse confirmado. No generar UUID nuevos para un reintento de transporte. Usar espera progresiva para 429/503 y respetar `Retry-After` cuando esté presente. Enviar en orden las operaciones pendientes de una misma entidad; una corrección debe usar la revisión confirmada de la operación anterior.

Después de confirmar la cola, descargar un snapshot nuevo para recibir cambios de otros equipos y catálogos. Si quedan conflictos, conservar sus cambios locales mientras se reemplazan los datos confirmados. La app no debe sustituir silenciosamente información pendiente por la versión remota.

## Endpoints

Todos los ejemplos se refieren a la base `/api/v1/recepcion-pollo-vivo/sync`. Las respuestas exitosas con contenido se envuelven en `{"data": ...}`; la liberación de una descarga responde 204 sin cuerpo.

| Método y ruta | Propósito |
|---|---|
| `GET /status` | Estado del contrato, hora de servidor, empresa, sucursal, dispositivo, zona horaria y límites. |
| `POST /snapshots` | Crear una descarga completa estable. Cuerpo `{}`. |
| `GET /snapshots` | Recuperar metadatos de las descargas vigentes del dispositivo. |
| `GET /snapshots/{uuid}?after=0&limit=200` | Leer una página de esa descarga. `after` empieza en 0. |
| `DELETE /snapshots/{uuid}` | Liberar una descarga ya importada para recuperar su cupo. 204 sin cuerpo. |
| `POST /push` | Enviar un lote de operaciones idempotentes. |
| `GET /records?after=0&limit=100` | Consultar registros sincronizados, paginados por ID. Admite `date_from`, `date_to`, `kind` y `status`. |
| `GET /records/{uuid}` | Leer un registro sincronizado con su revisión actual. |
| `GET /reports?date_from=2026-09-01&date_to=2026-09-30` | Totales de registros sincronizados activos agrupados para reportes. |

### Estado

La respuesta de `/status` incluye `schema_version`, `server_time`, `company_id`, `branch_id`, `device_id`, `timezone`, `limits` y `capabilities`. La app debe rechazar versiones de contrato que no soporte. Los límites incluyen 50 operaciones por lote, 200 pesadas por registro, 2 MiB por petición y página de snapshot de 200 elementos.

Las capacidades `financial_movements: true` y `server_client_debt: true` indican que el servidor genera cuentas por cobrar por despachos directos. `client_financial_data: false` indica que los datos económicos permanecen en la web; no habilita pantallas de deudas ni descarga de precios en la aplicación. `inventory_movements` sigue siendo `false`.

### Descargas materializadas

Respuesta al crear:

```json
{
  "data": {
    "id": "fe0756c0-bbc5-4b4b-bfbd-623b4b449d4c",
    "schema_version": 1,
    "mode": "full",
    "created_at": "2026-09-10T13:00:00.000000Z",
    "expires_at": "2026-09-11T13:00:00.000000Z",
    "total_items": 320,
    "page_size": 200,
    "after": 0
  }
}
```

Ejemplo abreviado de página con `limit=1`:

```json
{
  "data": {
    "snapshot": {
      "id": "fe0756c0-bbc5-4b4b-bfbd-623b4b449d4c",
      "schema_version": 1,
      "mode": "full",
      "created_at": "2026-09-10T13:00:00.000000Z",
      "expires_at": "2026-09-11T13:00:00.000000Z",
      "total_items": 320
    },
    "items": [{"sequence": 1, "entity": "company", "key": "1", "data": {"id": 1, "name": "Avícola"}}],
    "next_after": 1,
    "has_more": true
  }
}
```

`sequence` es un cursor exclusivo de esa descarga, no un identificador de entidad. Usar la pareja `entity` + `key` como clave de importación y conservar el contenido de `data`. El contenido de empresa del ejemplo está abreviado. Las entidades son:

| `entity` | Contenido de `data` |
|---|---|
| `company` | Nombre comercial/razón social, identificación fiscal, país, moneda, zona horaria, hora de corte, título y mensaje del ticket. |
| `branch` | ID, empresa, código, nombre, dirección y zona horaria de sucursal. |
| `configuration` | Valores por defecto para captura y configuración de los seis carriles. |
| `client` | Clientes de la empresa, datos de identificación/contacto, indicador de cliente interno y estado; sin precios, saldos ni deudas. |
| `external_owner` | Proveedores que pueden ser propietarios externos; identificación, contacto y estado. |
| `warehouse` | Almacenes de la sucursal y estado. |
| `cage_type` | Tipos de java, nombre, tara `weight_kg` y estado. |
| `vehicle`, `driver` | Vehículos y conductores de la empresa y estado. |
| `chicken_type` | Tipo de pollo vivo. |
| `scale` | Balanza lógica y modos de conexión admitidos; la app implementa su conexión física. |
| `journey` | Jornadas existentes del módulo web, de solo lectura. |
| `record` | Recepciones/tickets web de consulta y registros offline sincronizados. |

Las claves de registros son `web:reception:{id}`, `web:ticket:{id}` u `offline:{uuid}`. Los IDs numéricos se pueden repetir entre fuentes: no usarlos solos como clave local. Todas las variantes ofrecen `payload.weighings`, `payload.destination`, `payload.owner` y `payload.totals`. Los registros web pueden tener UUID nulo y conservan campos adicionales propios del historial; sus pesadas pueden tener UUID nulo y una `key` estable en su lugar. Los pesos heredados se exportan como cadenas decimales y los de capturas offline como números: convertir ambos a unidades enteras de gramos al calcular. Los catálogos contienen `active` y `status`; una importación completa reemplaza los catálogos confirmados para retirar elementos ausentes, preservando la cola y las capturas locales pendientes.

### Crear una recepción

Los IDs de catálogo del siguiente ejemplo son ilustrativos: sustituirlos por IDs de la descarga correspondiente a la sucursal.

```json
{
  "operations": [
    {
      "operation_id": "3ea645ef-43c6-4d20-93b4-a9fd9baebbf7",
      "entity_id": "91c518f8-0d42-4363-b818-2f7970c81ca8",
      "kind": "reception",
      "action": "upsert",
      "expected_revision": 0,
      "payload": {
        "operating_date": "2026-09-09",
        "operating_cutoff": "21:00:00",
        "lane": 1,
        "origin": "Camión del día",
        "destination_id": 1,
        "local_number": "EQ01-R-000042",
        "notes": "Capturado sin conexión",
        "weighings": [
          {
            "uuid": "08cbcc90-0545-4658-9dc3-0a0d33e327d7",
            "sex": "MACHO",
            "cage_type_id": 1,
            "cage_weight_kg": 2.5,
            "birds_per_cage": 8,
            "cage_count": 10,
            "read_weight_kg": 225.75,
            "weight_source": "BALANZA",
            "weighed_at": "2026-09-09T08:15:00-05:00",
            "status": "active"
          }
        ]
      }
    }
  ]
}
```

Los totales de esa pesada son 80 pollos, 25 kg de tara y 200.750 kg netos. Se calculan en el servidor con precisión de gramos, usando la tara capturada. No enviar totales, nombres ni campos calculados en `payload`: el servidor valida y devuelve el documento normalizado. Para actualizar, reconstruir el cuerpo únicamente con los campos de entrada definidos en OpenAPI.

### Crear un ticket

Usar el mismo formato con `kind: "ticket"`, `lane: 5` o `6` y `destination_id` del cliente descargado. Puede contener pesadas MACHO y HEMBRA. Opcionalmente enviar juntos `delivery_vehicle_id` y `delivery_driver_id`; pertenecen a la empresa del token. El comprobante se identifica por UUID y `local_number`; el correlativo local es informativo y no debe usarse como clave de deduplicación.

### Cuenta por cobrar por despacho directo

Al aceptar por primera vez un ticket, el servidor calcula el importe a partir del peso neto de sus pesadas activas y el precio de venta de pollo vivo vigente en ese momento. Usa primero la lista del cliente y, si no hay precio aplicable, la lista general de la empresa. Se usa la fecha de aceptación en el servidor, incluso cuando la captura corresponde a una jornada anterior; la fecha operativa y el conteo de la captura se conservan.

El servidor vincula una única venta y su cuenta por cobrar al registro sincronizado. La captura y su efecto financiero se confirman en una misma transacción. No se crean pesadas adicionales ni se duplican los pollos o kilogramos en el conteo de recepción. Reenviar una operación ya confirmada no duplica ni recalcula la deuda. Los importes y el saldo se ven en la cartera/cuentas por cobrar de la web; el comprobante operativo continúa en **Registros sincronizados**.

Si no existe un precio positivo aplicable, la operación devuelve `status: "rejected"` y `http_status: 422`; no se guarda parcialmente el ticket ni su deuda. La app debe conservar la captura pendiente, mostrar el error y permitir reintentar después de configurar el precio en la web. Ese nuevo intento usa **otro `operation_id`**, el mismo `entity_id` y la revisión esperada sin cambiar, porque el rechazo anterior queda registrado para idempotencia. Los demás registros válidos del lote pueden confirmarse.

No enviar campos económicos en el payload: son rechazados. Las respuestas de `/push`, `/records`, snapshots y `/reports` incluyen datos operativos, sin precios, importes, saldos, deudas ni identificadores del documento financiero.

### Carriles, propietarios y jornada

| Carril | Tipo | Propietario | Sexo de las pesadas | Destino |
|---|---|---|---|---|
| 1 | `reception` | Propia | MACHO | Almacén de la sucursal |
| 2 | `reception` | Propia | HEMBRA | Almacén de la sucursal |
| 3 | `reception` | Externa | MACHO | Almacén de la sucursal |
| 4 | `reception` | Externa | HEMBRA | Almacén de la sucursal |
| 5 o 6 | `ticket` | Propia | MACHO y/o HEMBRA | Cliente de la empresa |

Los carriles 3 y 4 requieren `external_owner_id` de un proveedor de la empresa. Los demás no admiten propietario externo. Se aceptan capturas históricas desde 2000-01-01 y se conserva la hora de corte informada. La pesada, convertida a la zona horaria de la sucursal, debe corresponder a la jornada: si su hora es mayor o igual al corte, `operating_date` es el día siguiente; en caso contrario es el mismo día. Ejemplo: con corte 21:00, `2026-09-08T21:10:00-05:00` pertenece a `operating_date: "2026-09-09"`. El servidor rechaza fechas de captura futuras con tolerancia máxima de cinco minutos por desfase del reloj.

### Correcciones y anulaciones

Para corregir un registro, usar `action: "upsert"`, su UUID, `expected_revision` actual y un `operation_id` nuevo. Enviar `reason` explicando el cambio y el payload completo, incluyendo todas las pesadas anteriores. No se permite cambiar su fecha operativa. Para corregir la jornada, anular el registro y crear otro con UUID nuevo.

En tickets sin cobros aplicados, una corrección de peso conserva el precio aceptado originalmente y actualiza la cuenta por cobrar; un cambio de cliente toma el precio vigente del nuevo cliente al aceptar la corrección y reasigna la deuda. Si el nuevo cliente no tiene precio positivo aplicable, se rechaza la corrección completa y se conserva el ticket anterior. El precio queda congelado para las siguientes correcciones del mismo cliente aunque luego cambien las listas de precios.

Si el despacho tiene pagos aplicados en estado `REGISTRADO`, el servidor rechaza cualquier corrección o anulación con `http_status: 422`, conservando el registro, su revisión y la deuda. La app debe mantener la operación pendiente y mostrar el error para su revisión en la web. No se crean ni se deshacen cobros desde esta API.

Para retirar una pesada, mantenerla en `weighings` con `status: "voided"` y `void_reason`; omitirla es un error. Las pesadas anuladas quedan conservadas y no se pueden modificar o reactivar. Si todas las pesadas debieran anularse, usar la anulación completa:

```json
{
  "operations": [
    {
      "operation_id": "866a8c79-5690-4a76-ae57-53d7b26a23a7",
      "entity_id": "91c518f8-0d42-4363-b818-2f7970c81ca8",
      "kind": "reception",
      "action": "void",
      "expected_revision": 1,
      "reason": "Registro duplicado por error de captura"
    }
  ]
}
```

La anulación conserva el contenido para auditoría y marca el documento como `voided`; no se elimina. En tickets sin cobros aplicados, también invalida la venta y la deuda vinculadas, conservando su historial. Los reportes activos deben excluir documentos anulados completos y pesadas anuladas dentro de los documentos activos. Un documento anulado no se reactiva.

Dos dispositivos de la misma sucursal pueden consultar y corregir registros sincronizados. Si envían correcciones sobre la misma revisión, solo una se aplica y la otra recibe conflicto. **No resolverlo reemplazando automáticamente `expected_revision` y reenviando**: se debe comparar y decidir qué cambios conservar. Una decisión posterior produce una operación nueva.

### Resultados de un lote

```json
{
  "data": {
    "results": [
      {
        "operation_id": "3ea645ef-43c6-4d20-93b4-a9fd9baebbf7",
        "entity_id": "91c518f8-0d42-4363-b818-2f7970c81ca8",
        "status": "applied",
        "http_status": 201,
        "record": {
          "id": 1,
          "uuid": "91c518f8-0d42-4363-b818-2f7970c81ca8",
          "kind": "reception",
          "operating_date": "2026-09-09",
          "status": "active",
          "revision": 1,
          "device_id": "2a987a42-85f5-44ac-9fd4-98177eaf08a7",
          "payload": {},
          "created_at": "2026-09-10T13:00:00.000000Z",
          "updated_at": "2026-09-10T13:00:00.000000Z"
        }
      }
    ],
    "has_errors": false
  }
}
```

Aquí se abrevia `payload`. En la respuesta real contiene la captura validada, `destination`, `owner`, `delivery`, pesadas con nombres históricos y totales. Los totales son `weighings`, `cages`, `birds`, `male_birds`, `female_birds`, `gross_weight_kg`, `tare_weight_kg` y `net_weight_kg`. Cada pesada añade `number`, `cage_type_name`, `birds`, `gross_weight_kg`, `tare_weight_kg` y `net_weight_kg`.

El servidor registra los resultados asociados a la identidad de operación para tolerar retransmisiones. Reutilizar un `operation_id` para un cuerpo diferente produce conflicto; una corrección siempre usa otro UUID. Tratar `http_status` de cada resultado como estado de esa operación, distinto del HTTP 200 del lote.

Una respuesta repetida conserva el documento y la revisión del momento en que se ejecutó la operación. No reemplazar una revisión local confirmada más reciente con una revisión menor recibida en un reintento. Consultar `/records/{uuid}` o descargar otro snapshot para obtener el estado actual. Los UUID de pesadas tampoco pueden reutilizarse en otro registro de la misma sucursal, incluso después de anularlas.

## Reportes locales y del servidor

La aplicación debe poder calcular sus reportes sin conexión con los registros confirmados y las capturas locales, evitando contar dos veces una misma entidad UUID después de subirla. Separar recepciones de tickets para no interpretar entrada y salida como un único saldo. Mostrar claramente si el reporte incluye operaciones pendientes de sincronización.

Usar únicamente documentos activos y sus pesadas activas para sumar cantidades y pesos. Agrupar por fecha operativa, tipo de documento, destino/cliente, propietario y sexo. Los reportes del endpoint `/reports` consultan registros sincronizados de la sucursal; el historial web descargado permite que la futura aplicación reproduzca el reporte ampliado localmente. Los totales históricos se basan en los valores guardados en cada pesada, no en los catálogos actuales.

`/reports` requiere ambas fechas y admite hasta 366 días de diferencia entre ellas, además de filtros `kind` y `status`. Devuelve `scope: "offline_records"`, `record_counts`, `totals`, `by_day`, `by_client`, `by_owner`, `by_sex` y `by_lane`. `by_client` incluye solo tickets. `by_lane` conserva el carril real de captura: 1–4 para recepción y 5/6 para tickets. Un filtro de solo anulados devuelve sus cantidades de documentos pero cero totales activos.

Los reportes de la aplicación y de esta API siguen siendo operativos: cantidades, pesos y destinos. La venta vinculada existe para finanzas y no añade otra recepción o ticket a estos reportes.

## Errores y límites

| HTTP | Significado y recuperación |
|---|---|
| 401 | Token ausente, inválido, revocado o vencido (`SYNC_UNAUTHENTICATED`). Conservar pendientes y configurar una credencial válida. |
| 403 | El emisor o su ámbito ya no tienen acceso (`SYNC_ACCESS_DENIED`). Revisar autorización desde administración. |
| 404 | Registro o snapshot no encontrado dentro del ámbito autorizado. |
| 410 | Snapshot vencido. Crear otro y reiniciar esa descarga. |
| 413 | La petición supera 2 MiB. Dividir el lote sin cambiar sus operaciones. |
| 422 | Cuerpo o filtros inválidos, precio de venta no configurado o intento de modificar un despacho con cobros aplicados. Revisar `errors`. Los errores de una operación aparecen dentro del lote. |
| 429 | Demasiadas solicitudes. Esperar y reintentar. |
| 500/503 | Error temporal del servidor. Reintentar con los mismos UUID y contenido; puede haber operaciones anteriores confirmadas. |

Usar `message` y `errors` para mostrar la causa concreta. No tratar un cuerpo HTML de un proxy o un error de red como confirmación exitosa. No eliminar datos locales hasta haber confirmado la persistencia y contar con el respaldo establecido para el dispositivo.

## Verificación de integración de la futura app

1. Descargar varias páginas, interrumpir conexión y reanudar sin duplicar entidades.
2. Capturar recepción y ticket sin internet; imprimirlos y generar el reporte local.
3. Reenviar el mismo lote varias veces y confirmar que existe una sola entidad por UUID.
4. Cortar la conexión durante la subida y reintentar sin cambiar IDs.
5. Corregir desde dos dispositivos con la misma revisión y comprobar que se requiere resolver el conflicto.
6. Anular una pesada y un documento; revisar su conservación y exclusión de totales activos.
7. Cambiar la tara del catálogo en la web y comprobar que las pesadas anteriores conservan la tara capturada.
8. Revocar el token, verificar rechazo de acceso y comprobar que los pendientes siguen en la base local.
9. Probar un token de otra empresa/sucursal y verificar que no puede leer ni modificar esos datos.
10. Verificar que una recepción a almacén conserva su conteo sin generar venta o deuda, y que un ticket de despacho directo genera una sola cuenta por cobrar en finanzas web.
11. Subir un ticket histórico y comprobar que utiliza el precio vigente al aceptarse en el servidor; cambiar el precio y reenviar la misma operación para confirmar que no se duplica ni revaloriza la deuda.
12. Corregir el peso de un ticket sin cobros y comprobar que se actualiza la deuda con el precio congelado; cambiar el cliente y comprobar que se usa el precio vigente del nuevo cliente.
13. Subir un ticket sin precio positivo aplicable: debe rechazarse sin guardar ticket o deuda parcial; configurar el precio en la web y reintentar con otro `operation_id` y el mismo `entity_id`.
14. Anular un ticket sin cobros y comprobar que se invalida su deuda conservando el historial. Aplicarle un cobro a otro ticket desde la web y comprobar que su corrección y anulación son rechazadas sin cambios.
15. Revisar que snapshots, consultas, resultados de subida y reportes no entregan datos financieros, y que enviar precios o importes en el payload es rechazado.
16. Verificar que la sincronización no genera movimientos de inventario, caja, cobros automáticos ni control de javas/bandejas, y que el documento financiero no duplica el conteo operativo.
