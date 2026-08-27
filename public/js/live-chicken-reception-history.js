const integerFormatter = new Intl.NumberFormat("es-PE", {
  maximumFractionDigits: 0,
});

const dateTimeFormatter = new Intl.DateTimeFormat("es-PE", {
  day: "2-digit",
  month: "short",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
});

export function escapeHistoryHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

export function historySourceLabel(source) {
  return String(source || "").toUpperCase() === "TICKET"
    ? "Ticket de despacho"
    : "Entrada de recepción";
}

export function historyStatusLabel(status) {
  return String(status || "").toUpperCase() === "ANULADA" ? "Anulada" : "Activa";
}

export function historySexLabel(sex) {
  const normalized = String(sex || "").toUpperCase();
  if (normalized === "MACHO") return "Macho";
  if (normalized === "HEMBRA") return "Hembra";
  return sex ? String(sex) : "Sin sexo";
}

export function historyDestinationTypeLabel(type) {
  return String(type || "").toUpperCase() === "CLIENTE" ? "Cliente" : "Almacén";
}

export function formatHistoryNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? integerFormatter.format(number) : "—";
}

export function formatHistoryWeight(value) {
  if (value === null || value === undefined || value === "") return "—";
  const number = Number(value);
  return Number.isFinite(number) ? `${number.toFixed(3)} kg` : "—";
}

export function formatHistoryDateTime(value) {
  if (!value) return "Sin fecha";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : dateTimeFormatter.format(date);
}

export function normalizeHistoryTotals(value = {}) {
  return {
    weighings: Number(value.weighings || 0),
    cages: Number(value.cages || 0),
    birds: Number(value.birds || 0),
    gross_weight_kg: Number(value.gross_weight_kg || 0),
    tare_weight_kg: Number(value.tare_weight_kg || 0),
    net_weight_kg: Number(value.net_weight_kg || 0),
  };
}

export function normalizeHistoryPayload(payload = {}) {
  const data = payload?.data && typeof payload.data === "object" ? payload.data : payload;
  const summary = data?.summary || {};
  const pagination = data?.pagination || {};
  const appliedStatus = String(data?.applied_filters?.status || "").toUpperCase();
  const appliedSource = String(data?.applied_filters?.source || "").toUpperCase();

  return {
    ...data,
    branch: data?.branch || null,
    current_journey: data?.current_journey || null,
    current_journey_id: data?.current_journey_id ? Number(data.current_journey_id) : null,
    selected_journey: data?.selected_journey || null,
    is_current_journey: Boolean(data?.is_current_journey),
    catalog: {
      ...(data?.catalog || {}),
      journeys: Array.isArray(data?.catalog?.journeys) ? data.catalog.journeys : [],
    },
    applied_filters: {
      status: appliedStatus === "TODAS" ? "" : appliedStatus,
      source: appliedSource === "TODAS" ? "" : appliedSource,
    },
    summary: {
      active: normalizeHistoryTotals(summary.active),
      voided: normalizeHistoryTotals(summary.voided),
      total: normalizeHistoryTotals(summary.total),
    },
    records: Array.isArray(data?.records) ? data.records : [],
    pagination: {
      current_page: Math.max(1, Number(pagination.current_page || 1)),
      last_page: Math.max(1, Number(pagination.last_page || 1)),
      per_page: Math.max(1, Number(pagination.per_page || 30)),
      total: Math.max(0, Number(pagination.total || 0)),
      from: pagination.from === null || pagination.from === undefined ? null : Number(pagination.from),
      to: pagination.to === null || pagination.to === undefined ? null : Number(pagination.to),
    },
  };
}

export function buildHistoryQuery({
  journey_id = "",
  status = "",
  source = "",
  page = 1,
  per_page = 30,
} = {}) {
  const params = new URLSearchParams({
    page: String(Math.max(1, Number(page) || 1)),
    per_page: String(Math.max(1, Number(per_page) || 30)),
  });

  if (journey_id !== "" && journey_id !== null && journey_id !== undefined) {
    params.set("journey_id", String(journey_id));
  }
  if (status) params.set("status", String(status).toUpperCase());
  if (source) params.set("source", String(source).toUpperCase());

  return params.toString();
}

export function buildHistoryReportUrl(format, journeyId) {
  const normalizedFormat = String(format || "").toLowerCase();
  const normalizedJourneyId = String(journeyId ?? "").trim();
  const numericJourneyId = Number(normalizedJourneyId);
  const reportPath = normalizedFormat === "pdf"
    ? "pdf"
    : (normalizedFormat === "images" ? "imagenes" : "");

  if (
    !reportPath
    || !/^\d+$/.test(normalizedJourneyId)
    || !Number.isSafeInteger(numericJourneyId)
    || numericJourneyId < 1
  ) {
    return "";
  }

  return `/recepcion-pollo-vivo/historial/reporte/${reportPath}?journey_id=${numericJourneyId}`;
}

function sourceFor(record = {}) {
  if (record.source) return String(record.source).toUpperCase();
  return record.record_kind === "DISPATCH_TICKET_WEIGHING" ? "TICKET" : "RECEPCION";
}

function historyRecordReference(record, source) {
  const number = record.number === null || record.number === undefined
    ? "Sin número"
    : `Pesada #${formatHistoryNumber(record.number)}`;
  const ticketCode = record.ticket?.code;

  return source === "TICKET" && ticketCode
    ? `${escapeHistoryHtml(ticketCode)} · ${escapeHistoryHtml(number)}`
    : escapeHistoryHtml(number);
}

export function renderHistoryRow(record = {}) {
  const source = sourceFor(record);
  const voided = String(record.status || "").toUpperCase() === "ANULADA";
  const owner = record.owner || {};
  const destination = record.destination || {};
  const cageType = record.cage_type || {};
  const cageLabel = cageType.name || cageType.code || "Sin tipo de java";
  const cageMeta = [
    cageType.code && cageType.code !== cageLabel ? cageType.code : "",
    record.chicken_type?.name || record.chicken_type?.code || "",
    record.birds_per_cage !== null && record.birds_per_cage !== undefined
      ? `${formatHistoryNumber(record.birds_per_cage)} aves por java`
      : "",
    cageType.weight_kg !== null && cageType.weight_kg !== undefined
      ? `${formatHistoryWeight(cageType.weight_kg)} por java`
      : "",
  ].filter(Boolean).join(" · ");
  const laneMeta = record.source_lane && Number(record.source_lane) !== Number(record.lane)
    ? `Columna ${formatHistoryNumber(record.lane)} · origen histórico ${formatHistoryNumber(record.source_lane)}`
    : (record.lane ? `Columna ${formatHistoryNumber(record.lane)}` : "");
  const deliveryMeta = record.ticket?.delivery
    ? [
      record.ticket.delivery.vehicle?.plate
        ? `Camión ${record.ticket.delivery.vehicle.plate}`
        : "",
      record.ticket.delivery.driver?.name || "",
    ].filter(Boolean).join(" · ")
    : "";
  const sourceMeta = [
    record.weight_source || "Origen de peso no informado",
    laneMeta,
    record.reception?.origin || "",
    deliveryMeta,
  ].filter(Boolean).join(" · ");
  const idLabel = record.id ? `ID ${formatHistoryNumber(record.id)}` : "";
  const readWeight = Number(record.read_weight_kg);
  const grossWeight = Number(record.gross_weight_kg);
  const readWeightMeta = Number.isFinite(readWeight)
    && Number.isFinite(grossWeight)
    && Math.abs(readWeight - grossWeight) > 0.0005
    ? `Lectura: ${formatHistoryWeight(readWeight)}`
    : "";
  const birdsMeta = Number(record.average_weight_per_bird_kg) > 0
    ? `${formatHistoryWeight(record.average_weight_per_bird_kg)} por pollo`
    : "";
  const voidMeta = voided
    ? [record.voided_at ? formatHistoryDateTime(record.voided_at) : "", record.void_reason || ""]
      .filter(Boolean)
      .join(" · ")
    : "";
  const auditMeta = [
    record.created_by?.name ? `Registró: ${record.created_by.name}` : "",
    record.updated_at ? `Actualizado: ${formatHistoryDateTime(record.updated_at)}` : "",
  ].filter(Boolean).join(" · ");

  return `
    <tr class="${voided ? "is-voided" : "is-active"}">
      <td data-label="Peso bruto">
        <div class="live-history-cell-stack">
          <strong class="live-history-weight">${escapeHistoryHtml(formatHistoryWeight(record.gross_weight_kg))}</strong>
          <small>${escapeHistoryHtml([record.weight_source || "Sin origen de peso", readWeightMeta].filter(Boolean).join(" · "))}</small>
        </div>
      </td>
      <td data-label="Tara">${escapeHistoryHtml(formatHistoryWeight(record.tare_weight_kg))}</td>
      <td data-label="Peso neto"><strong class="live-history-net-weight">${escapeHistoryHtml(formatHistoryWeight(record.net_weight_kg))}</strong></td>
      <td data-label="Javas"><strong>${escapeHistoryHtml(formatHistoryNumber(record.cages))}</strong></td>
      <td data-label="Pollos">
        <div class="live-history-cell-stack">
          <strong>${escapeHistoryHtml(formatHistoryNumber(record.birds))}</strong>
          <small>${escapeHistoryHtml(birdsMeta)}</small>
        </div>
      </td>
      <td data-label="Sexo">${escapeHistoryHtml(historySexLabel(record.sex))}</td>
      <td data-label="Propietario">
        <div class="live-history-cell-stack">
          <strong>${escapeHistoryHtml(owner.name || "Sin propietario")}</strong>
          <small>${escapeHistoryHtml(owner.type === "EXTERNA" ? "Empresa externa" : "Mi empresa")}</small>
        </div>
      </td>
      <td data-label="Destino">
        <div class="live-history-cell-stack">
          <strong>${escapeHistoryHtml(destination.name || "Sin destino")}</strong>
          <small>${escapeHistoryHtml(historyDestinationTypeLabel(destination.type))}</small>
        </div>
      </td>
      <td data-label="Tipo de java">
        <div class="live-history-cell-stack">
          <strong>${escapeHistoryHtml(cageLabel)}</strong>
          <small>${escapeHistoryHtml(cageMeta || "Sin detalle")}</small>
        </div>
      </td>
      <td data-label="Origen">
        <div class="live-history-cell-stack">
          <span class="live-history-source-chip ${source === "TICKET" ? "is-ticket" : ""}">${escapeHistoryHtml(historySourceLabel(source))}</span>
          <small>${escapeHistoryHtml(sourceMeta)}</small>
        </div>
      </td>
      <td data-label="Estado">
        <div class="live-history-cell-stack">
          <span class="live-history-row-status ${voided ? "is-voided" : "is-active"}">${escapeHistoryHtml(historyStatusLabel(record.status))}</span>
          <small>${escapeHistoryHtml(voidMeta)}</small>
        </div>
      </td>
      <td data-label="Registro">
        <div class="live-history-cell-stack">
          <strong>${escapeHistoryHtml(formatHistoryDateTime(record.weighed_at))}</strong>
          <small>${historyRecordReference(record, source)}</small>
          <small>${escapeHistoryHtml([idLabel, auditMeta].filter(Boolean).join(" · "))}</small>
        </div>
      </td>
    </tr>
  `;
}
