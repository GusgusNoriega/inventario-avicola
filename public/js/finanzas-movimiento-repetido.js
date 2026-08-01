const REPEAT_CONTEXT_FIELDS = Object.freeze({
  COBRO_CLIENTE: Object.freeze([
    "currency",
    "client",
    "method",
    "date",
    "destination"
  ]),
  PAGO_DIRECTO: Object.freeze([
    "currency",
    "client",
    "provider",
    "method",
    "date",
    "destination"
  ])
});

export function movementRepeatContext(modeKey, values = {}) {
  const fields = REPEAT_CONTEXT_FIELDS[String(modeKey || "").toUpperCase()];
  if (!fields) return null;

  return Object.fromEntries(fields.map((field) => [field, values[field] ?? ""]));
}
