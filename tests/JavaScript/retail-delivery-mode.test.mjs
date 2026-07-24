import assert from "node:assert/strict";
import test from "node:test";

import {
  buildRetailDeliveryPayload,
  resolveRetailDeliveryMode,
  RETAIL_DELIVERY_MODE_COMPANY_TRUCK,
  RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP
} from "../../public/js/retail-delivery-mode.js";

test("normaliza únicamente las dos modalidades de salida permitidas", () => {
  assert.equal(
    resolveRetailDeliveryMode(" customer_pickup "),
    RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP
  );
  assert.equal(
    resolveRetailDeliveryMode("company_truck"),
    RETAIL_DELIVERY_MODE_COMPANY_TRUCK
  );
  assert.equal(resolveRetailDeliveryMode("otro"), null);
  assert.equal(resolveRetailDeliveryMode(null), null);
});

test("el retiro directo nunca conserva un camión o chofer residual", () => {
  assert.deepEqual(
    buildRetailDeliveryPayload(RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP, 12, 34),
    { mode: RETAIL_DELIVERY_MODE_CUSTOMER_PICKUP }
  );
});

test("la entrega de empresa exige camión y chofer válidos", () => {
  assert.deepEqual(
    buildRetailDeliveryPayload(RETAIL_DELIVERY_MODE_COMPANY_TRUCK, "12", "34"),
    {
      mode: RETAIL_DELIVERY_MODE_COMPANY_TRUCK,
      vehicle_id: 12,
      driver_id: 34
    }
  );
  assert.equal(
    buildRetailDeliveryPayload(RETAIL_DELIVERY_MODE_COMPANY_TRUCK, "", "34"),
    null
  );
  assert.equal(
    buildRetailDeliveryPayload(RETAIL_DELIVERY_MODE_COMPANY_TRUCK, "12", ""),
    null
  );
});
