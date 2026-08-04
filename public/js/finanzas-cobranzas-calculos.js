function safeCents(value) {
  return Number.isSafeInteger(value) && value >= 0 ? value : 0;
}

export const MAX_PENDING_ASSIGNMENT_DETAILS = 200;

export function isDeterministicAssignmentErrorStatus(value) {
  const status = Number(value);
  return Number.isInteger(status) && status >= 400 && status < 500;
}

export function createPendingAssignmentRetrySnapshot({ availableCents, message, payload }) {
  return {
    availableCents: safeCents(availableCents),
    message: String(message || ""),
    payload: {
      idempotency_key: payload?.idempotency_key,
      detalles: Array.isArray(payload?.detalles)
        ? payload.detalles.map((detail) => ({ ...detail }))
        : []
    }
  };
}

export function collectionReconciliation({
  totalCents,
  detailCents,
  detailsComplete,
  confirmedPendingCents = null
}) {
  const total = safeCents(totalCents);
  const assigned = safeCents(detailCents);
  const difference = total - assigned;
  const pendingCents = Math.max(0, difference);
  const excessCents = Math.max(0, -difference);
  const requiresConfirmation = total > 0 && detailsComplete && pendingCents > 0;
  const pendingConfirmed = !requiresConfirmation || confirmedPendingCents === pendingCents;

  return {
    totalCents: total,
    assignedCents: assigned,
    pendingCents,
    excessCents,
    differenceCents: difference,
    status: excessCents > 0 ? "EXCEDIDA" : pendingCents > 0 ? "PENDIENTE" : "COMPLETA",
    requiresConfirmation,
    pendingConfirmed,
    registrable: total > 0
      && detailsComplete
      && excessCents === 0
      && pendingConfirmed
  };
}

export function pendingAssignmentReconciliation({
  availableCents,
  detailCents,
  detailsComplete
}) {
  const available = safeCents(availableCents);
  const assigned = safeCents(detailCents);
  const difference = available - assigned;
  const remainingCents = Math.max(0, difference);
  const excessCents = Math.max(0, -difference);

  return {
    availableCents: available,
    assignedCents: assigned,
    remainingCents,
    excessCents,
    complete: available > 0 && assigned === available && Boolean(detailsComplete),
    registrable: available > 0
      && assigned > 0
      && Boolean(detailsComplete)
      && excessCents === 0
  };
}
