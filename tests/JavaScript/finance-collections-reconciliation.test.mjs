import assert from "node:assert/strict";
import test from "node:test";
import {
  collectionReconciliation,
  createPendingAssignmentRetrySnapshot,
  isDeterministicAssignmentErrorStatus,
  MAX_PENDING_ASSIGNMENT_DETAILS,
  pendingAssignmentReconciliation
} from "../../public/js/finanzas-cobranzas-calculos.js";

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
  const exceeded = collectionReconciliation({
    totalCents: 50000,
    detailCents: 50001,
    detailsComplete: true
  });
  assert.equal(exceeded.registrable, false);
  assert.equal(exceeded.status, "EXCEDIDA");
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

test("a pending balance can be assigned partially and preserves the remainder", () => {
  const result = pendingAssignmentReconciliation({
    availableCents: 30000,
    detailCents: 12500,
    detailsComplete: true
  });

  assert.equal(result.assignedCents, 12500);
  assert.equal(result.remainingCents, 17500);
  assert.equal(result.excessCents, 0);
  assert.equal(result.complete, false);
  assert.equal(result.registrable, true);
});

test("an exact pending assignment completes the balance", () => {
  const result = pendingAssignmentReconciliation({
    availableCents: 30000,
    detailCents: 10000 + 20000,
    detailsComplete: true
  });

  assert.equal(result.remainingCents, 0);
  assert.equal(result.complete, true);
  assert.equal(result.registrable, true);
});

test("an incomplete or excessive pending assignment cannot be submitted", () => {
  assert.equal(pendingAssignmentReconciliation({
    availableCents: 30000,
    detailCents: 30001,
    detailsComplete: true
  }).registrable, false);
  assert.equal(pendingAssignmentReconciliation({
    availableCents: 30000,
    detailCents: 10000,
    detailsComplete: false
  }).registrable, false);
});

test("pending assignments honor the API detail limit", () => {
  assert.equal(MAX_PENDING_ASSIGNMENT_DETAILS, 200);
});

test("only a 4xx response makes an assignment failure deterministic", () => {
  assert.equal(isDeterministicAssignmentErrorStatus(400), true);
  assert.equal(isDeterministicAssignmentErrorStatus(422), true);
  assert.equal(isDeterministicAssignmentErrorStatus(499), true);
  assert.equal(isDeterministicAssignmentErrorStatus(undefined), false);
  assert.equal(isDeterministicAssignmentErrorStatus(0), false);
  assert.equal(isDeterministicAssignmentErrorStatus(500), false);
});

test("an ambiguous assignment retry preserves an independent exact payload snapshot", () => {
  const payload = {
    idempotency_key: "12345678-1234-4234-8234-123456789abc",
    detalles: [{ cliente_id: 17, fecha_recepcion: "2026-08-01", importe: "45.20" }]
  };
  const snapshot = createPendingAssignmentRetrySnapshot({
    availableCents: 10000,
    message: "Respuesta no confirmada",
    payload
  });

  payload.detalles[0].importe = "99.99";
  assert.deepEqual(snapshot, {
    availableCents: 10000,
    message: "Respuesta no confirmada",
    payload: {
      idempotency_key: "12345678-1234-4234-8234-123456789abc",
      detalles: [{ cliente_id: 17, fecha_recepcion: "2026-08-01", importe: "45.20" }]
    }
  });
});
