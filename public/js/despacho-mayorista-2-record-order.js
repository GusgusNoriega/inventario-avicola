/**
 * Devuelve una copia en orden visual descendente sin alterar la secuencia
 * cronológica que se conserva para persistencia, impresión y envío al API.
 */
export function newestRecordsFirst(records) {
  return Array.isArray(records) ? [...records].reverse() : [];
}
