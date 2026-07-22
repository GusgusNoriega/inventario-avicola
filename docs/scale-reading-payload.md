# Metadata auditable de lecturas de balanza

Los endpoints de despacho mayorista y minorista aceptan un objeto opcional `scale_reading` dentro de cada elemento de `weighings`:

```json
{
  "weight_source": "BALANZA_1",
  "read_weight_kg": 30.125,
  "weighed_at": "2026-07-22T10:15:30-05:00",
  "scale_reading": {
    "raw_frame": "ST,GS,+00030.125kg",
    "connection_mode": "SERIAL",
    "device_name": "Puerto COM7",
    "captured_at": "2026-07-22T10:15:29.850-05:00"
  }
}
```

Campos:

- `raw_frame`: trama original, hasta 500 caracteres.
- `connection_mode`: `SERIAL`, `BLE` o `BLUETOOTH`.
- `device_name`: nombre local del dispositivo o puerto, hasta 180 caracteres.
- `captured_at`: fecha ISO 8601 en la que el controlador aceptó la lectura.

El objeto completo y todos sus campos son opcionales para mantener compatibilidad. Cuando `weight_source` identifica una balanza, el backend siempre crea una fila en `lecturas_balanza`: usa `read_weight_kg` como peso y, si falta `scale_reading.captured_at`, usa `weighed_at`. Una fuente `MANUAL` nunca crea una lectura física.

Fuentes permitidas por endpoint:

- Mayorista: `MANUAL`, `BALANZA_1`, `BALANZA_2`.
- Minorista 1: `MANUAL`, `BALANZA_MINORISTA`.
- Minorista 2: `MANUAL`, `BALANZA_MINORISTA_2`.

Los parámetros físicos del navegador son locales al equipo. `PUT /api/v1/despacho-minorista[-2]/configuracion` permite omitir `scale` y enviar solo `default_adjustment_code` más `adjustments`; así el frontend puede guardar reglas de peso sin sincronizar baudios, puerto o dispositivo entre computadoras.
