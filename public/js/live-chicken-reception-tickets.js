const DISPATCH_LANES = [5, 6];
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function firstDefined(...values) {
  return values.find((value) => value !== undefined && value !== null);
}

function numberValue(...values) {
  const value = Number(firstDefined(...values));
  return Number.isFinite(value) ? value : 0;
}

function positiveId(...values) {
  const value = Number(firstDefined(...values));
  return Number.isInteger(value) && value > 0 ? value : null;
}

function fallbackUuid() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    const value = character === "x" ? random : (random & 0x3) | 0x8;
    return value.toString(16);
  });
}

function validOrNewUuid(value, createUuid = fallbackUuid) {
  const current = String(value || "");
  if (UUID_PATTERN.test(current)) return current;
  const created = String(createUuid?.() || "");
  return UUID_PATTERN.test(created) ? created : fallbackUuid();
}

function firstValidUuid(...values) {
  return values.map((value) => String(value || "")).find((value) => UUID_PATTERN.test(value));
}

export function normalizeReceptionSex(value) {
  const normalized = String(value || "").trim().toUpperCase();
  if (["H", "HEMBRA", "F", "FEMALE"].includes(normalized)) return "HEMBRA";
  return "MACHO";
}

export function isDispatchTicketRecord(record) {
  const kind = String(record?.record_kind || record?.kind || "").trim().toLowerCase();
  return ["dispatch_ticket", "ticket_sex_summary"].includes(kind)
    || String(record?.editable_scope || "").toUpperCase() === "TICKET";
}

export function ticketRecordId(record) {
  return positiveId(
    record?.ticket_id,
    record?.dispatch_ticket_id,
    record?.ticket?.id,
    record?.dispatch_ticket?.id,
    isDispatchTicketRecord(record) ? record?.id : null,
  );
}

export function receptionRecordLane(record) {
  if (!isDispatchTicketRecord(record)) {
    return Number(record?.lane) || 1;
  }

  const explicitLane = Number(firstDefined(record?.display_lane, record?.reception_lane));
  if ([1, 2].includes(explicitLane)) return explicitLane;
  return normalizeReceptionSex(firstDefined(record?.sex, record?.chicken_sex)) === "HEMBRA" ? 2 : 1;
}

export function newestReceptionRowsFirst(rows) {
  if (!Array.isArray(rows)) return [];

  return rows
    .map((row, index) => {
      const timestamp = Date.parse(String(firstDefined(row?.weighed_at, row?.captured_at, "")));
      const sortTie = Number(row?.sort_tie);
      return {
        row,
        index,
        timestamp: Number.isFinite(timestamp) ? timestamp : null,
        sortTie: Number.isFinite(sortTie) ? sortTie : index,
      };
    })
    .sort((left, right) => {
      if (left.timestamp === null && right.timestamp !== null) return 1;
      if (left.timestamp !== null && right.timestamp === null) return -1;
      if (left.timestamp !== right.timestamp) return (right.timestamp ?? 0) - (left.timestamp ?? 0);
      return right.sortTie - left.sortTie || right.index - left.index;
    })
    .map(({ row }) => row);
}

export function receptionSummaryRows(records = [], scope = "daily") {
  const normalizedScope = ["own", "external"].includes(String(scope))
    ? String(scope)
    : "daily";
  const requiredOwnerType = normalizedScope === "own"
    ? "PROPIA"
    : (normalizedScope === "external" ? "EXTERNA" : null);
  const rows = [];

  (Array.isArray(records) ? records : []).forEach((record, recordIndex) => {
    const ownerType = String(record?.owner?.type || "").toUpperCase();
    if (requiredOwnerType && ownerType !== requiredOwnerType) return;

    const sharedContext = {
      owner: record?.owner || null,
      destination: record?.destination || null,
      lane: receptionRecordLane(record),
      source_lane: Number(record?.source_lane ?? record?.lane) || null,
      dispatched: Boolean(record?.dispatched),
      ticket_id: ticketRecordId(record),
      ticket_code: String(firstDefined(record?.ticket_code, record?.ticket?.code, "")),
      editable_mode: String(firstDefined(
        record?.editable_mode,
        isDispatchTicketRecord(record)
          ? "ticket"
          : (String(record?.record_kind || "").toLowerCase() === "legacy_direct_weighing" ? "readonly" : "weighing"),
      )).toLowerCase(),
    };
    const ticketWeighings = isDispatchTicketRecord(record) && Array.isArray(record?.weighings)
      ? record.weighings
      : [];

    if (ticketWeighings.length) {
      ticketWeighings.forEach((weighing, weighingIndex) => {
        const normalized = normalizeDraftWeighing(weighing, weighingIndex);
        rows.push({
          ...normalized,
          ...sharedContext,
          record_kind: "dispatch_ticket_weighing",
          row_key: `${record?.row_key || `ticket:${sharedContext.ticket_id || recordIndex}`}:weighing:${normalized.id || weighingIndex}`,
          summary_focus_key: `ticket-weighing:${normalized.id || `${sharedContext.ticket_id || recordIndex}:${weighingIndex}`}`,
          cage_type: weighing?.cage_type || record?.cage_type || null,
          sort_tie: (-recordIndex * 1000) + weighingIndex,
        });
      });
      return;
    }

    const normalized = normalizeDraftWeighing(record, recordIndex);
    rows.push({
      ...normalized,
      ...sharedContext,
      record_kind: record?.record_kind || "reception_weighing",
      row_key: record?.row_key || `reception:${normalized.id || recordIndex}`,
      summary_focus_key: `reception-weighing:${normalized.id || recordIndex}`,
      cage_type: record?.cage_type || null,
      ticket_id: sharedContext.ticket_id,
      sort_tie: -recordIndex,
    });
  });

  return newestReceptionRowsFirst(rows);
}

export function normalizeDraftWeighing(weighing = {}, index = 0) {
  const cages = numberValue(weighing.cage_count, weighing.cages, weighing.java_count, weighing.javas);
  const birdsPerCage = numberValue(
    weighing.birds_per_cage,
    weighing.birds_per_java,
    weighing.cantidad_aves_por_java,
  );
  const tare = numberValue(weighing.tare_weight_kg, weighing.tare_kg);
  const readWeight = numberValue(
    weighing.read_weight_kg,
    weighing.gross_weight_kg,
    weighing.weight_kg,
    weighing.peso_kg,
  );

  return {
    ...weighing,
    id: firstDefined(weighing.id, weighing.weighing_id, null),
    local_id: String(firstDefined(
      weighing.local_id,
      weighing.temp_id,
      weighing.idempotency_key,
      weighing.id,
      `draft-${index + 1}`,
    )),
    number: numberValue(weighing.number, weighing.numero, index + 1),
    sex: normalizeReceptionSex(firstDefined(weighing.sex, weighing.chicken_sex)),
    cage_type_id: positiveId(weighing.cage_type_id, weighing.cage_type?.id),
    cage_type_code: firstDefined(weighing.cage_type_code, weighing.cage_type?.code, null),
    cage_type_name: String(firstDefined(
      weighing.cage_type_name,
      weighing.cage_type?.name,
      weighing.java_type?.name,
      "Java",
    )),
    birds_per_cage: birdsPerCage,
    cage_count: cages,
    cages,
    birds: numberValue(weighing.birds, weighing.cantidad_aves, birdsPerCage * cages),
    read_weight_kg: readWeight,
    gross_weight_kg: numberValue(weighing.gross_weight_kg, readWeight),
    tare_weight_kg: tare,
    net_weight_kg: numberValue(weighing.net_weight_kg, readWeight - tare),
    weight_source: String(firstDefined(weighing.weight_source, "MANUAL")),
    weighed_at: String(firstDefined(weighing.weighed_at, weighing.captured_at, new Date().toISOString())),
    scale_reading: weighing.scale_reading || null,
  };
}

export function nextDraftWeighingNumber(weighings) {
  if (!Array.isArray(weighings) || !weighings.length) return 1;

  const highestNumber = weighings.reduce((highest, weighing, index) => {
    const candidate = Number(firstDefined(weighing?.number, weighing?.numero, index + 1));
    return Number.isInteger(candidate) && candidate > highest ? candidate : highest;
  }, 0);

  return highestNumber + 1;
}

export function createEmptyDispatchDraft(lane, draftId) {
  const normalizedLane = DISPATCH_LANES.includes(Number(lane)) ? Number(lane) : 5;
  return {
    lane: normalizedLane,
    draft_id: String(draftId || ""),
    dispatch_client_id: null,
    dispatch_client_name: "",
    delivery_vehicle_id: null,
    delivery_driver_id: null,
    weighings: [],
  };
}

export function normalizeDispatchDraft(draft, lane, createDraftId = () => "") {
  const source = draft && typeof draft === "object" ? draft : {};
  const normalizedLane = DISPATCH_LANES.includes(Number(lane ?? source.lane))
    ? Number(lane ?? source.lane)
    : 5;
  const client = source.client || source.destination || {};
  const weighings = Array.isArray(source.weighings)
    ? source.weighings
    : (Array.isArray(source.pesadas) ? source.pesadas : []);
  const draftId = validOrNewUuid(firstValidUuid(source.draft_id, source.idempotency_key), createDraftId);

  return {
    ...createEmptyDispatchDraft(normalizedLane, draftId),
    ...source,
    lane: normalizedLane,
    draft_id: draftId,
    dispatch_client_id: positiveId(source.dispatch_client_id, source.client_id, client.id),
    dispatch_client_name: String(firstDefined(source.dispatch_client_name, client.name, "")),
    delivery_vehicle_id: positiveId(source.delivery_vehicle_id, source.delivery?.vehicle_id, source.delivery?.vehicle?.id),
    delivery_driver_id: positiveId(source.delivery_driver_id, source.delivery?.driver_id, source.delivery?.driver?.id),
    weighings: weighings.map((weighing, index) => {
      const localId = validOrNewUuid(firstValidUuid(
        weighing.local_id,
        weighing.temp_id,
        weighing.idempotency_key,
      ), createDraftId);
      const idempotencyKey = firstValidUuid(weighing.idempotency_key, localId) || localId;
      return normalizeDraftWeighing({
        ...weighing,
        local_id: localId,
        idempotency_key: idempotencyKey,
      }, index);
    }),
  };
}

export function dispatchDraftFingerprint(draft) {
  return JSON.stringify(buildReceptionTicketPayload(draft));
}

export function dispatchDraftWeighingFingerprint(weighing) {
  const normalized = normalizeDraftWeighing(weighing);
  return JSON.stringify({
    local_id: normalized.local_id,
    idempotency_key: normalized.idempotency_key || normalized.local_id,
    sex: normalized.sex,
    cage_type_id: normalized.cage_type_id,
    cage_weight_kg: numberValue(normalized.cage_weight_kg, normalized.cage_type?.weight_kg),
    birds_per_cage: normalized.birds_per_cage,
    cage_count: normalized.cage_count,
    read_weight_kg: normalized.read_weight_kg,
    tare_weight_kg: normalized.tare_weight_kg,
    net_weight_kg: normalized.net_weight_kg,
    weight_source: normalized.weight_source,
    weighed_at: normalized.weighed_at,
    scale_reading: normalized.scale_reading,
  });
}

export function remainingDispatchDraftAfterRegistration(currentDraft, submittedDraft, createDraftId = fallbackUuid) {
  const current = normalizeDispatchDraft(currentDraft, currentDraft?.lane, createDraftId);
  const submitted = normalizeDispatchDraft(submittedDraft, submittedDraft?.lane, createDraftId);
  if (current.draft_id !== submitted.draft_id) {
    return { handled: false, draft: current, preservedWeighings: current.weighings.length };
  }

  const submittedIds = new Set(submitted.weighings.map((item) => String(item.idempotency_key || item.local_id)));
  const remaining = current.weighings.filter(
    (item) => !submittedIds.has(String(item.idempotency_key || item.local_id)),
  );
  if (!remaining.length) return { handled: true, draft: null, preservedWeighings: 0 };

  return {
    handled: true,
    draft: normalizeDispatchDraft({
      ...current,
      draft_id: validOrNewUuid(null, createDraftId),
      registration_attempt: null,
      delivery_vehicle_id: null,
      delivery_driver_id: null,
      weighings: remaining,
    }, current.lane, createDraftId),
    preservedWeighings: remaining.length,
  };
}

export function calculateDraftTotals(weighings = []) {
  return weighings.map(normalizeDraftWeighing).reduce((totals, weighing) => {
    totals.weighings += 1;
    totals.cages += weighing.cage_count;
    totals.birds += weighing.birds;
    totals.gross_weight_kg += weighing.gross_weight_kg;
    totals.tare_weight_kg += weighing.tare_weight_kg;
    totals.net_weight_kg += weighing.net_weight_kg;
    if (weighing.sex === "HEMBRA") totals.female_birds += weighing.birds;
    else totals.male_birds += weighing.birds;
    return totals;
  }, {
    weighings: 0,
    cages: 0,
    birds: 0,
    gross_weight_kg: 0,
    tare_weight_kg: 0,
    net_weight_kg: 0,
    male_birds: 0,
    female_birds: 0,
  });
}

function ticketWeighings(ticket = {}) {
  const weighings = ticket.weighings || ticket.pesadas || ticket.records || [];
  return Array.isArray(weighings) ? weighings.map(normalizeDraftWeighing) : [];
}

export function buildReceptionTicketPayload(draft, delivery = null) {
  const normalized = normalizeDispatchDraft(draft, draft?.lane);
  const vehicleId = positiveId(delivery?.vehicleId, delivery?.vehicle_id, normalized.delivery_vehicle_id);
  const driverId = positiveId(delivery?.driverId, delivery?.driver_id, normalized.delivery_driver_id);

  return {
    layout_version: 4,
    draft_id: normalized.draft_id,
    lane: normalized.lane,
    dispatch_client_id: normalized.dispatch_client_id,
    ...(vehicleId && driverId ? {
      delivery_vehicle_id: vehicleId,
      delivery_driver_id: driverId,
    } : {}),
    weighings: normalized.weighings.map((weighing) => ({
      idempotency_key: weighing.idempotency_key || weighing.local_id,
      sex: weighing.sex,
      cage_type_id: weighing.cage_type_id,
      birds_per_cage: weighing.birds_per_cage,
      cage_count: weighing.cage_count,
      weight_source: weighing.weight_source,
      read_weight_kg: weighing.read_weight_kg,
      weighed_at: weighing.weighed_at,
      ...(weighing.scale_reading ? { scale_reading: weighing.scale_reading } : {}),
    })),
  };
}

export function normalizeFullTicket(ticket = {}) {
  const source = ticket?.ticket && !ticket?.weighings ? ticket.ticket : ticket;
  const client = source.client || source.destination || {};
  return {
    ...source,
    id: positiveId(source.id, source.ticket_id),
    code: String(firstDefined(source.code, source.ticket_code, "Ticket")),
    revision: numberValue(source.link_revision, source.revision, source.version),
    link_revision: numberValue(source.link_revision, source.revision, source.version),
    client: {
      ...client,
      id: positiveId(client.id, source.dispatch_client_id, source.client_id),
      name: String(firstDefined(client.name, source.client_name, source.destination_name, "Sin cliente")),
    },
    delivery: source.delivery || null,
    weighings: ticketWeighings(source),
  };
}

export function buildTicketUpdatePayload(ticket, weighings, correctionReason = "") {
  const normalizedTicket = normalizeFullTicket(ticket);
  const normalizedWeighings = (weighings || normalizedTicket.weighings).map(normalizeDraftWeighing);
  return {
    layout_version: 4,
    expected_revision: normalizedTicket.link_revision,
    correction_reason: String(correctionReason).trim(),
    weighings: normalizedWeighings.map((weighing) => ({
      id: positiveId(weighing.id, weighing.weighing_id),
      ...(weighing.updated_at ? { expected_updated_at: weighing.updated_at } : {}),
      sex: weighing.sex,
      cage_type_id: weighing.cage_type_id,
      birds_per_cage: weighing.birds_per_cage,
      cage_count: weighing.cage_count,
      ...(weighing.preserve_weight_source ? {} : {
        weight_source: String(weighing.weight_source || "MANUAL"),
      }),
      read_weight_kg: weighing.read_weight_kg,
      weighed_at: weighing.weighed_at,
    })),
  };
}

export function buildReceptionTicketPrintData(ticket, presentation = {}) {
  const normalized = normalizeFullTicket(ticket);
  return {
    code: normalized.code,
    operationType: "DESPACHO",
    destinationName: normalized.client.name,
    ticketTitle: String(firstDefined(presentation.ticketTitle, normalized.ticket_title, "")),
    ticketMessage: String(firstDefined(presentation.ticketMessage, normalized.ticket_message, "")),
    emittedAt: firstDefined(normalized.registered_at, normalized.emitted_at, normalized.created_at, null),
    delivery: normalized.delivery,
    records: normalized.weighings.map((weighing) => ({
      typeCode: "PV",
      birds: weighing.birds,
      birdsPerCage: weighing.birds_per_cage,
      cages: weighing.cage_count,
      grossWeight: weighing.gross_weight_kg,
      tareWeight: weighing.tare_weight_kg,
      netWeight: weighing.net_weight_kg,
    })),
  };
}
