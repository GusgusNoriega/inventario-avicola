export const RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP = "CUSTOMER_PICKUP";
export const RETAIL_DELIVERY_MODE_COMPANY_TRUCK = "COMPANY_TRUCK";

const RETAIL_DELIVERY_MODES = new Set([
  RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP,
  RETAIL_DELIVERY_MODE_COMPANY_TRUCK
]);

export function resolveRetailDeliveryMode(requestedMode) {
  const normalized = String(requestedMode || "").trim().toUpperCase();

  return RETAIL_DELIVERY_MODES.has(normalized) ? normalized : null;
}

export function buildRetailDeliveryPayload(requestedMode, vehicleId = null, driverId = null) {
  const mode = resolveRetailDeliveryMode(requestedMode);

  if (mode === RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP) {
    return { mode };
  }

  const normalizedVehicleId = Number(vehicleId);
  const normalizedDriverId = Number(driverId);
  if (
    mode !== RETAIL_DELIVERY_MODE_COMPANY_TRUCK
    || !Number.isInteger(normalizedVehicleId)
    || normalizedVehicleId < 1
    || !Number.isInteger(normalizedDriverId)
    || normalizedDriverId < 1
  ) {
    return null;
  }

  return {
    mode,
    vehicle_id: normalizedVehicleId,
    driver_id: normalizedDriverId
  };
}
