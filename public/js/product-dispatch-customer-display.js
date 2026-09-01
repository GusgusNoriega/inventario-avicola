export const PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE =
  "product-dispatch-customer-display-state";
export const PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE =
  "product-dispatch-customer-display-request";
export const PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE =
  "product-dispatch-customer-display-reset";

const MAX_SCOPE_LENGTH = 80;
const MAX_TITLE_LENGTH = 120;
const MAX_CUSTOMER_LENGTH = 100;
const MAX_PRODUCT_NAME_LENGTH = 120;
const MAX_STATUS_LENGTH = 24;
const MAX_ROWS = 100;

function normalizeString(value, maxLength, fallback = "") {
  const normalized = String(value ?? "").trim().slice(0, maxLength);
  return normalized || fallback;
}

function normalizeScopeValue(value) {
  return normalizeString(value, MAX_SCOPE_LENGTH);
}

function scopeToken(value) {
  return encodeURIComponent(normalizeScopeValue(value) || "sin-id");
}

function normalizeInteger(value, fallback = 0) {
  const numericValue = Math.trunc(Number(value));
  return Number.isFinite(numericValue) && numericValue >= 0
    ? numericValue
    : fallback;
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

function normalizeWeight(value, fallback = null) {
  const normalized = normalizeNullableNumber(value, 3);
  return normalized !== null && normalized >= 0 ? normalized : fallback;
}

function normalizeAmount(value, fallback = null) {
  return normalizeNullableNumber(value, 2) ?? fallback;
}

function normalizeCurrency(value) {
  const normalized = normalizeString(value, 8).toUpperCase();
  return /^[A-Z]{3}$/.test(normalized) ? normalized : "PEN";
}

function normalizeRows(rows) {
  if (!Array.isArray(rows)) {
    return [];
  }

  return rows.slice(0, MAX_ROWS).map((row) => ({
    name: normalizeString(row?.name, MAX_PRODUCT_NAME_LENGTH, "Producto"),
    quantity: normalizeInteger(row?.quantity),
    netWeightKg: normalizeWeight(row?.netWeightKg, 0),
    amount: normalizeAmount(row?.amount)
  }));
}

function sumRows(rows, field, decimals) {
  const sum = rows.reduce((total, row) => {
    const value = Number(row[field]);
    return Number.isFinite(value) ? total + value : total;
  }, 0);

  return normalizeNullableNumber(sum, decimals) ?? 0;
}

function normalizeActiveList(activeList = {}) {
  const rows = normalizeRows(activeList.rows);
  const totals = activeList.totals && typeof activeList.totals === "object"
    ? activeList.totals
    : {};

  return {
    number: Math.max(1, normalizeInteger(activeList.number, 1)),
    customer: normalizeString(
      activeList.customer,
      MAX_CUSTOMER_LENGTH,
      "Venta al público"
    ),
    rows,
    totals: {
      quantity: normalizeInteger(
        totals.quantity,
        sumRows(rows, "quantity", 0)
      ),
      netWeightKg: normalizeWeight(
        totals.netWeightKg,
        sumRows(rows, "netWeightKg", 3)
      ),
      amount: normalizeAmount(
        totals.amount,
        sumRows(rows, "amount", 2)
      )
    }
  };
}

function normalizePreview(preview = {}) {
  const netWeightKg = normalizeWeight(preview.netWeightKg);

  return {
    netWeightKg: netWeightKg !== null && netWeightKg > 0
      ? netWeightKg
      : null,
    amount: netWeightKg !== null && netWeightKg > 0
      ? normalizeAmount(preview.amount)
      : null,
    status: normalizeString(preview.status, MAX_STATUS_LENGTH, "waiting")
  };
}

export function buildProductDispatchCustomerDisplayChannelName(
  branchId,
  userId,
  producerId
) {
  return [
    "sistema-pollos-pantalla-cliente-productos",
    scopeToken(branchId),
    scopeToken(userId),
    scopeToken(producerId),
    "v1"
  ].join("-");
}

export function buildProductDispatchCustomerDisplayStorageKey(
  branchId,
  userId,
  producerId
) {
  return [
    "sistema-pollos-pantalla-cliente-productos",
    scopeToken(branchId),
    scopeToken(userId),
    "estado-v1",
    scopeToken(producerId)
  ].join(":");
}

export function productDispatchCustomerDisplayPayloadMatches(payload, {
  branchId,
  userId,
  producerId
}) {
  const expectedBranchId = normalizeScopeValue(branchId);
  const expectedUserId = normalizeScopeValue(userId);
  const expectedProducerId = normalizeScopeValue(producerId);

  return Boolean(
    expectedBranchId
    && expectedUserId
    && expectedProducerId
    && payload
    && payload.type === PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE
    && normalizeScopeValue(payload.branchId) === expectedBranchId
    && normalizeScopeValue(payload.userId) === expectedUserId
    && normalizeScopeValue(payload.producerId) === expectedProducerId
  );
}

export function resolveProductDispatchCustomerDisplayPreview({
  hasReading,
  netWeightKg,
  amount,
  calculationAvailable = false,
  amountAvailable = calculationAvailable,
  isPhysical = false,
  isFresh = false,
  connectionMatches = true,
  isExpired = false,
  status = "ready"
}) {
  if (!Boolean(hasReading)) {
    return {
      netWeightKg: null,
      amount: null,
      status: "waiting"
    };
  }

  if (
    Boolean(isPhysical)
    && (!Boolean(isFresh) || !Boolean(connectionMatches) || Boolean(isExpired))
  ) {
    return {
      netWeightKg: null,
      amount: null,
      status: "unavailable"
    };
  }

  const normalizedNetWeight = normalizeWeight(netWeightKg);
  if (
    !Boolean(calculationAvailable)
    || normalizedNetWeight === null
    || normalizedNetWeight <= 0
  ) {
    return {
      netWeightKg: null,
      amount: null,
      status: "calculating"
    };
  }

  return {
    netWeightKg: normalizedNetWeight,
    amount: Boolean(amountAvailable) ? normalizeAmount(amount) : null,
    status: normalizeString(status, MAX_STATUS_LENGTH, "ready")
  };
}

export function buildProductDispatchCustomerDisplayPayload({
  branchId,
  userId,
  producerId,
  producerInstance,
  revision,
  updatedAt,
  companyTitle = "",
  activeList = {},
  preview = {},
  currency = "PEN"
}) {
  return {
    type: PRODUCT_DISPATCH_CUSTOMER_DISPLAY_PAYLOAD_TYPE,
    branchId: normalizeScopeValue(branchId),
    userId: normalizeScopeValue(userId),
    producerId: normalizeScopeValue(producerId),
    producerInstance: normalizeInteger(producerInstance),
    revision: normalizeInteger(revision),
    companyTitle: normalizeString(
      companyTitle,
      MAX_TITLE_LENGTH,
      "Despacho de productos"
    ),
    activeList: normalizeActiveList(activeList),
    preview: normalizePreview(preview),
    currency: normalizeCurrency(currency),
    updatedAt: normalizeString(
      updatedAt,
      40,
      new Date().toISOString()
    )
  };
}
