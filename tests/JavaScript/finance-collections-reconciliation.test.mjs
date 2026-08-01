import assert from "node:assert/strict";
import test from "node:test";
import { collectionReconciliation } from "../../public/js/finanzas-cobranzas-calculos.js";

test("a partially itemized deposit requires confirmation and preserves its pending cents", () => {
  const before = collectionReconciliation({
    totalCents: 50000,
    detailCents: 20000,
    detailsComplete: true
  });

  assert.equal(before.pendingCents, 30000);
  assert.equal(before.status, "PENDIENTE");
  assert.equal(before.registrable, false);

  const confirmed = collectionReconciliation({
    totalCents: 50000,
    detailCents: 20000,
    detailsComplete: true,
    confirmedPendingCents: 30000
  });
  assert.equal(confirmed.registrable, true);
});

test("an exact itemization is complete without confirmation", () => {
  const result = collectionReconciliation({
    totalCents: 3,
    detailCents: 1 + 2,
    detailsComplete: true
  });

  assert.equal(result.pendingCents, 0);
  assert.equal(result.status, "COMPLETA");
  assert.equal(result.registrable, true);
});

test("a breakdown over the voucher or an incomplete row is never registrable", () => {
  assert.equal(collectionReconciliation({
    totalCents: 50000,
    detailCents: 50001,
    detailsComplete: true
  }).registrable, false);
  assert.equal(collectionReconciliation({
    totalCents: 50000,
    detailCents: 20000,
    detailsComplete: false,
    confirmedPendingCents: 30000
  }).registrable, false);
});

test("a confirmation is valid only for the exact current pending amount", () => {
  const result = collectionReconciliation({
    totalCents: 50000,
    detailCents: 25000,
    detailsComplete: true,
    confirmedPendingCents: 30000
  });

  assert.equal(result.pendingCents, 25000);
  assert.equal(result.pendingConfirmed, false);
  assert.equal(result.registrable, false);
});
