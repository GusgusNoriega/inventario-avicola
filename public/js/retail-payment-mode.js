export const RETAIL_PAYMENT_MODE_NOW = "PAY_NOW";
export const RETAIL_PAYMENT_MODE_CREDIT = "CREDIT";

export function resolveRetailPaymentMode(requestedMode, hasAssignedClient) {
  return requestedMode === RETAIL_PAYMENT_MODE_CREDIT && Boolean(hasAssignedClient)
    ? RETAIL_PAYMENT_MODE_CREDIT
    : RETAIL_PAYMENT_MODE_NOW;
}

export function paymentsForRetailPaymentMode(requestedMode, hasAssignedClient, payments = []) {
  return resolveRetailPaymentMode(requestedMode, hasAssignedClient) === RETAIL_PAYMENT_MODE_CREDIT
    ? []
    : payments;
}
