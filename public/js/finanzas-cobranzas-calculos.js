function safeCents(value) {
  return Number.isSafeInteger(value) && value >= 0 ? value : 0;
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
    status: pendingCents > 0 ? "PENDIENTE" : "COMPLETA",
    requiresConfirmation,
    pendingConfirmed,
    registrable: total > 0
      && detailsComplete
      && excessCents === 0
      && pendingConfirmed
  };
}
