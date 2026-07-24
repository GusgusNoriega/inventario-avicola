import assert from "node:assert/strict";
import test from "node:test";
import {
  paymentsForRetailPaymentMode,
  resolveRetailPaymentMode,
  RETAIL_PAYMENT_MODE_CREDIT,
  RETAIL_PAYMENT_MODE_NOW
} from "../../public/js/retail-payment-mode.js";

test("permite venta a crédito únicamente cuando existe un cliente asignado", () => {
  assert.equal(
    resolveRetailPaymentMode(RETAIL_PAYMENT_MODE_CREDIT, true),
    RETAIL_PAYMENT_MODE_CREDIT
  );
  assert.equal(
    resolveRetailPaymentMode(RETAIL_PAYMENT_MODE_CREDIT, false),
    RETAIL_PAYMENT_MODE_NOW
  );
});

test("una venta a crédito continúa sin registrar pagos", () => {
  const payments = [{ importe: "125.50" }];

  assert.deepEqual(
    paymentsForRetailPaymentMode(RETAIL_PAYMENT_MODE_CREDIT, true, payments),
    []
  );
  assert.deepEqual(
    paymentsForRetailPaymentMode(RETAIL_PAYMENT_MODE_CREDIT, false, payments),
    payments
  );
});

test("el pago inmediato conserva las formas de pago elegidas", () => {
  const payments = [
    { importe: "100.00" },
    { importe: "25.50" }
  ];

  assert.deepEqual(
    paymentsForRetailPaymentMode(RETAIL_PAYMENT_MODE_NOW, true, payments),
    payments
  );
});
