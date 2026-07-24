export const RETAIL_CUSTOMER_DISPLAY_PAYLOAD_TYPE = "retail-customer-display-state";
export const RETAIL_CUSTOMER_DISPLAY_REQUEST_TYPE = "retail-customer-display-request";
export const RETAIL_CUSTOMER_DISPLAY_RESET_TYPE = "retail-customer-display-reset";

function normalizeStation(station) {
  const normalized = String(station || "").trim();
  return normalized || "1";
}

function normalizeCount(value) {
  const count = Math.trunc(Number(value));
  return Number.isFinite(count) && count >= 0 ? count : 0;
}

function normalizeNullableNumber(value, decimals) {
  if (value === null || value === undefined || String(value).trim() === "") {
    return null;
  }

  const numericValue = Number(value);
  if (!Number.isFinite(numericValue)) {
    return null;
  }

  const factor = 10 ** decimals;
  return Math.round((numericValue + Number.EPSILON) * factor) / factor;
}

export function buildRetailCustomerDisplayChannelName(station) {
  return `sistema-pollos-pantalla-cliente-minorista-${normalizeStation(station)}-v1`;
}

export function buildRetailCustomerDisplayStorageKey(station, producerId = "") {
  const prefix = `sistema-pollos-pantalla-cliente-minorista-${normalizeStation(station)}-estado-v1`;
  const normalizedProducerId = String(producerId || "").trim();
  return normalizedProducerId ? `${prefix}:${normalizedProducerId}` : prefix;
}

export function retailCustomerDisplayPayloadMatches(payload, {
  station,
  producerId
}) {
  const normalizedProducerId = String(producerId || "").trim();
  const normalizedStation = String(station || "").trim();

  return Boolean(
    normalizedProducerId
    && normalizedStation
    && payload
    && payload.type === RETAIL_CUSTOMER_DISPLAY_PAYLOAD_TYPE
    && String(payload.station || "") === normalizedStation
    && payload.producerId === normalizedProducerId
  );
}

export function resolveRetailCustomerDisplayWeights({
  hasReading,
  readWeightKg,
  displayWeightKg,
  isPhysical = false,
  isFresh = false,
  connectionMatches = true,
  isExpired = false
}) {
  const canShowWeight = Boolean(hasReading)
    && (
      !isPhysical
      || (Boolean(isFresh) && Boolean(connectionMatches) && !Boolean(isExpired))
    );

  return {
    readWeightKg: canShowWeight ? readWeightKg : null,
    displayWeightKg: canShowWeight ? displayWeightKg : null
  };
}

export function buildRetailCustomerDisplayPayload({
  station,
  producerId,
  producerInstance,
  revision,
  updatedAt,
  customerName = "",
  ticketLabel = "",
  listNumber = 1,
  operationType = "DESPACHO",
  presentation = "",
  totals = {},
  pricingComplete = true,
  readWeightKg = null,
  displayWeightKg = null,
  weightSource = null,
  weightStatus = "unknown",
  isStable = false,
  isFresh = false
}) {
  const normalizedStation = normalizeStation(station);
  const normalizedCustomerName = String(customerName || "").trim() || "Venta sin cliente";
  const normalizedPricingComplete = Boolean(pricingComplete);

  return {
    type: RETAIL_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
    station: normalizedStation,
    producerId: String(producerId || ""),
    producerInstance: Number(producerInstance) || 0,
    revision: Number(revision) || 0,
    customerName: normalizedCustomerName,
    ticket: {
      label: String(ticketLabel || "").trim() || `Lista ${normalizeCount(listNumber) || 1}`,
      listNumber: normalizeCount(listNumber) || 1,
      operationType: operationType === "DEVOLUCION" ? "DEVOLUCION" : "DESPACHO",
      presentation: String(presentation || ""),
      weighings: normalizeCount(totals.weighings),
      trays: normalizeCount(totals.trays),
      birds: normalizeCount(totals.birds),
      netWeightKg: normalizeNullableNumber(totals.net, 3) ?? 0,
      amount: normalizedPricingComplete
        ? (normalizeNullableNumber(totals.amount, 2) ?? 0)
        : null,
      pricingComplete: normalizedPricingComplete,
      currency: "PEN"
    },
    scale: {
      readWeightKg: normalizeNullableNumber(readWeightKg, 3),
      displayWeightKg: normalizeNullableNumber(displayWeightKg, 3),
      source: weightSource ? String(weightSource) : null,
      status: String(weightStatus || "unknown"),
      isStable: Boolean(isStable),
      isFresh: Boolean(isFresh)
    },
    updatedAt: String(updatedAt || new Date().toISOString())
  };
}
