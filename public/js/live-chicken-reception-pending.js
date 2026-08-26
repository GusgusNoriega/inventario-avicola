export function freezeDispatchClientCorrection(payload) {
  const frozen = {
    ...payload,
    requires_dispatch_client_reselection: true,
  };
  delete frozen.dispatch_client_id;
  delete frozen.dispatch_client_name;

  return frozen;
}

export function assignDispatchClientToPendingCapture(payload, client) {
  const assigned = {
    ...payload,
    dispatch_client_id: Number(client.id),
    dispatch_client_name: String(client.name),
  };
  delete assigned.requires_dispatch_client_reselection;

  return assigned;
}
