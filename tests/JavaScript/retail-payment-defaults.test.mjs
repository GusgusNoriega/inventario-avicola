import test from "node:test";
import assert from "node:assert/strict";

import {
  normalizeRetailPaymentDefaultId,
  resolveRetailPaymentDefaults
} from "../../public/js/retail-payment-defaults.js";

const methods = [
  { id: 1, name: "Efectivo" },
  { id: 2, name: "Transferencia" }
];
const accounts = [
  { id: 10, alias: "Caja principal" },
  { id: 20, alias: "Banco principal" }
];

test("cada estación resuelve su método y cuenta predeterminados de forma independiente", () => {
  assert.deepEqual(
    resolveRetailPaymentDefaults({
      methods,
      own_accounts: accounts,
      default_method_id: 1,
      default_account_id: 10
    }),
    { methodId: 1, accountId: 10 }
  );

  assert.deepEqual(
    resolveRetailPaymentDefaults({
      methods,
      own_accounts: accounts,
      default_method_id: 2,
      default_account_id: 20
    }),
    { methodId: 2, accountId: 20 }
  );
});

test("una preferencia inexistente o inactiva cae en la primera opción disponible", () => {
  assert.deepEqual(
    resolveRetailPaymentDefaults({
      methods,
      own_accounts: accounts,
      default_method_id: 999,
      default_account_id: 999
    }),
    { methodId: 1, accountId: 10 }
  );
});

test("sin catálogo financiero no inventa identificadores predeterminados", () => {
  assert.deepEqual(resolveRetailPaymentDefaults({}), {
    methodId: "",
    accountId: ""
  });
  assert.equal(normalizeRetailPaymentDefaultId("20"), 20);
  assert.equal(normalizeRetailPaymentDefaultId(0), null);
  assert.equal(normalizeRetailPaymentDefaultId("dato"), null);
});
