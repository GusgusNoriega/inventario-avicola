import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-daily-tickets]");
const DEFAULT_TIME_ZONE = "America/Lima";
const OPERATION_LABELS = {
  DESPACHO: "Despacho",
  DEVOLUCION: "Devolucion"
};
const CONDITION_LABELS = {
  VIVO: "Pollo vivo",
  MUERTO: "Pollo muerto"
};
const CHICKEN_TYPE_SHORT_LABELS = {
  POLLO_VIVO: "P V",
  POLLO_MUERTO: "P M",
  POLLO_PELADO: "P P",
  POLLO_BENEFICIADO: "P B"
};

const elements = {
  meta: document.getElementById("dailyTicketsMeta"),
  message: document.getElementById("dailyTicketsMessage"),
  filters: document.getElementById("dailyTicketsFilters"),
  fromDate: document.getElementById("dailyTicketsFromDate"),
  fromTime: document.getElementById("dailyTicketsFromTime"),
  toDate: document.getElementById("dailyTicketsToDate"),
  toTime: document.getElementById("dailyTicketsToTime"),
  search: document.getElementById("dailyTicketsSearch"),
  refresh: document.getElementById("dailyTicketsRefresh"),
  ticketCount: document.getElementById("dailyTicketCount"),
  recordCount: document.getElementById("dailyRecordCount"),
  cageCount: document.getElementById("dailyCageCount"),
  birdCount: document.getElementById("dailyBirdCount"),
  grossWeight: document.getElementById("dailyGrossWeight"),
  tareWeight: document.getElementById("dailyTareWeight"),
  netWeight: document.getElementById("dailyNetWeight"),
  operationSummary: document.getElementById("dailyOperationSummary"),
  clientTotals: document.getElementById("dailyClientTotals"),
  typeTotals: document.getElementById("dailyTypeTotals"),
  resultCount: document.getElementById("dailyTicketsResultCount"),
  ticketList: document.getElementById("dailyTicketList")
};

let loading = false;
let displayTimeZone = DEFAULT_TIME_ZONE;

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function formatNumber(value) {
  return new Intl.NumberFormat("es-PE").format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatDate(value, includeTime = false) {
  if (!value) {
    return "--";
  }

  const date = new Date(includeTime ? value : `${value}T12:00:00`);

  return new Intl.DateTimeFormat("es-PE", includeTime
    ? { dateStyle: "short", timeStyle: "short", timeZone: displayTimeZone }
    : { dateStyle: "medium", timeZone: displayTimeZone }
  ).format(date);
}

function normalizeCode(value) {
  return String(value || "").trim().toUpperCase();
}

function operationLabel(value) {
  const code = normalizeCode(value);
  return OPERATION_LABELS[code] || code || "Despacho";
}

function conditionLabel(value) {
  const code = normalizeCode(value);
  return CONDITION_LABELS[code] || code || "Pollo vivo";
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function getErrorMessage(error) {
  const firstValidationError = error?.data?.errors
    ? Object.values(error.data.errors).flat().find(Boolean)
    : null;

  return firstValidationError || error?.message || "No se pudo cargar el resumen.";
}

function buildQuery() {
  const params = new URLSearchParams();
  const ticket = elements.search.value.trim();
  const rangeFields = {
    from_date: elements.fromDate.value,
    from_time: elements.fromTime.value,
    to_date: elements.toDate.value,
    to_time: elements.toTime.value
  };

  Object.entries(rangeFields).forEach(([key, value]) => {
    if (value) {
      params.set(key, value);
    }
  });
  if (ticket) {
    params.set("ticket", ticket);
  }

  const query = params.toString();
  return query ? `?${query}` : "";
}

function renderMeta(data) {
  displayTimeZone = data.branch?.timezone || DEFAULT_TIME_ZONE;
  elements.fromDate.value = data.range?.from_date || elements.fromDate.value;
  elements.fromTime.value = data.range?.from_time || elements.fromTime.value;
  elements.toDate.value = data.range?.to_date || elements.toDate.value;
  elements.toTime.value = data.range?.to_time || elements.toTime.value;
  elements.meta.textContent = `${data.branch?.name || "Sucursal"} | Desde ${formatDate(data.range?.from, true)} hasta ${formatDate(data.range?.to, true)} | Corte ${data.range?.cutoff_time || "21:00"}`;
}

function renderSummary(summary) {
  elements.ticketCount.textContent = formatNumber(summary.tickets);
  elements.recordCount.textContent = formatNumber(summary.records);
  elements.cageCount.textContent = formatNumber(summary.cages);
  elements.birdCount.textContent = formatNumber(summary.birds);
  elements.grossWeight.textContent = formatWeight(summary.gross_weight_kg);
  elements.tareWeight.textContent = formatWeight(summary.tare_weight_kg);
  elements.netWeight.textContent = formatWeight(summary.net_weight_kg);
}

function renderOperationSummary(operations) {
  const items = Array.isArray(operations) ? operations : [];

  elements.operationSummary.innerHTML = items.map((item) => {
    const operationType = normalizeCode(item.operation_type);
    const toneClass = operationType === "DEVOLUCION"
      ? "daily-operation-return"
      : "daily-operation-dispatch";

    return `
      <article class="daily-operation-card card ${toneClass}">
        <div class="daily-operation-card-head">
          <span>${escapeHtml(item.label || operationLabel(item.operation_type))}</span>
          <strong>${formatWeight(item.net_weight_kg)}</strong>
        </div>
        <div class="daily-operation-metrics">
          <span><strong>${formatNumber(item.tickets)}</strong> tickets</span>
          <span><strong>${formatNumber(item.records)}</strong> pesadas</span>
          <span><strong>${formatNumber(item.cages)}</strong> javas</span>
          <span><strong>${formatNumber(item.birds)}</strong> aves</span>
        </div>
      </article>
    `;
  }).join("");
}

function renderClientTypes(types) {
  const items = Array.isArray(types) ? types : [];

  if (!items.length) {
    return "--";
  }

  return items.map((type) => {
    const code = normalizeCode(type.code);
    const label = CHICKEN_TYPE_SHORT_LABELS[code] || type.name || code || "--";

    return `<span class="daily-client-type" title="${escapeHtml(type.name || label)}">${escapeHtml(label)}</span>`;
  }).join("");
}

function renderClientTotals(clients) {
  const items = Array.isArray(clients) ? clients : [];

  if (!items.length) {
    elements.clientTotals.innerHTML = `
      <tr>
        <td colspan="8" class="customer-history-empty-cell">No hay movimientos de clientes para este rango.</td>
      </tr>
    `;
    return;
  }

  elements.clientTotals.innerHTML = items.map((item) => `
    <tr>
      <td class="daily-client-name"><strong>${escapeHtml(item.client?.name || "Cliente sin registrar")}</strong></td>
      <td><div class="daily-client-types">${renderClientTypes(item.chicken_types)}</div></td>
      <td>${formatNumber(item.cages)}</td>
      <td>${formatNumber(item.birds)}</td>
      <td>${formatWeight(item.gross_weight_kg)}</td>
      <td>${formatWeight(item.tare_weight_kg)}</td>
      <td class="daily-client-return"><strong>${formatWeight(item.return_net_weight_kg)}</strong></td>
      <td class="daily-client-net"><strong>${formatWeight(item.net_weight_kg)}</strong></td>
    </tr>
  `).join("");
}

function renderTypeTotals(types) {
  if (!types.length) {
    elements.typeTotals.innerHTML = `
      <tr>
        <td colspan="7" class="customer-history-empty-cell">No hay pesadas activas para esta jornada.</td>
      </tr>
    `;
    return;
  }

  elements.typeTotals.innerHTML = types.map((item) => `
    <tr>
      <td><strong>${escapeHtml(item.chicken_type?.name || "Sin tipo")}</strong></td>
      <td>${formatNumber(item.records)}</td>
      <td>${formatNumber(item.cages)}</td>
      <td>${formatNumber(item.birds)}</td>
      <td>${formatWeight(item.gross_weight_kg)}</td>
      <td>${formatWeight(item.tare_weight_kg)}</td>
      <td><strong>${formatWeight(item.net_weight_kg)}</strong></td>
    </tr>
  `).join("");
}

function renderRecordType(record) {
  const condition = normalizeCode(record.chicken_condition);

  if (condition === "MUERTO") {
    return `
      <span class="customer-record-type">
        <span class="customer-chicken-condition customer-chicken-condition-muerto">
          ${escapeHtml(conditionLabel(record.chicken_condition))}
        </span>
      </span>
    `;
  }

  return `<strong>${escapeHtml(record.chicken_type?.name || "Sin tipo")}</strong>`;
}

function renderTicketTypes(types) {
  if (!types.length) {
    return "";
  }

  return `
    <div class="customer-ticket-summary daily-ticket-type-summary">
      ${types.map((item) => `
        <span>
          <strong>${escapeHtml(item.chicken_type?.name || "Sin tipo")}</strong>
          ${formatNumber(item.birds)} aves | ${formatWeight(item.net_weight_kg)}
        </span>
      `).join("")}
    </div>
  `;
}

function renderTicket(ticket) {
  const operationType = normalizeCode(ticket.operation_type);
  const operationClass = operationType === "DEVOLUCION"
    ? "customer-operation-return"
    : "customer-operation-dispatch";
  const records = ticket.records.length
    ? ticket.records.map((record) => `
        <tr class="${record.status === "ACTIVA" ? "" : "is-inactive"}">
          <td>#${escapeHtml(record.number)}</td>
          <td>${escapeHtml(formatDate(record.weighed_at, true))}</td>
          <td>${renderRecordType(record)}</td>
          <td>
            <strong>${escapeHtml(record.origin?.name || "Sin origen")}</strong>
            <small>${escapeHtml(record.origin?.type || "--")} | ${escapeHtml(record.weight_source || "--")}</small>
          </td>
          <td>${escapeHtml(record.plate || "--")}</td>
          <td>${formatNumber(record.birds_per_cage)}</td>
          <td>${formatNumber(record.birds)}</td>
          <td>${formatNumber(record.cages)}</td>
          <td>${formatWeight(record.gross_weight_kg)}</td>
          <td>${formatWeight(record.tare_weight_kg)}</td>
          <td><strong>${formatWeight(record.net_weight_kg)}</strong></td>
          <td><span class="customer-status customer-status-${escapeHtml(String(record.status || "").toLowerCase())}">${escapeHtml(record.status)}</span></td>
        </tr>
      `).join("")
    : `
        <tr>
          <td colspan="12" class="customer-history-empty-cell">Este ticket todavia no tiene pesadas.</td>
        </tr>
      `;

  return `
    <article class="customer-ticket daily-ticket-card card">
      <header class="customer-ticket-head">
        <div>
          <div class="customer-ticket-badges">
            <span class="directory-record-tag">${escapeHtml(ticket.status)}</span>
            <span class="customer-operation-tag ${operationClass}">${escapeHtml(operationLabel(ticket.operation_type))}</span>
          </div>
          <h3>${escapeHtml(ticket.code)}</h3>
          <p>${escapeHtml(formatDate(ticket.operating_date))} | ${escapeHtml(ticket.destination?.type || "--")}: ${escapeHtml(ticket.destination?.name || "Sin destino")}</p>
        </div>
        <div class="customer-ticket-total daily-ticket-total">
          <span>Peso neto</span>
          <strong>${formatWeight(ticket.summary.net_weight_kg)}</strong>
        </div>
      </header>
      <div class="customer-ticket-summary">
        <span><strong>${formatNumber(ticket.summary.records)}</strong> pesadas</span>
        <span><strong>${formatNumber(ticket.summary.cages)}</strong> javas</span>
        <span><strong>${formatNumber(ticket.summary.birds)}</strong> aves</span>
        <span><strong>${formatWeight(ticket.summary.gross_weight_kg)}</strong> bruto</span>
        <span><strong>${formatWeight(ticket.summary.tare_weight_kg)}</strong> javas kg</span>
      </div>
      ${renderTicketTypes(ticket.summary.by_type || [])}
      <div class="customer-history-table-wrap">
        <table class="customer-history-table daily-ticket-records">
          <thead>
            <tr>
              <th>Registro</th>
              <th>Hora</th>
              <th>Tipo</th>
              <th>Origen</th>
              <th>Placa</th>
              <th>Aves/java</th>
              <th>Aves</th>
              <th>Javas</th>
              <th>Bruto</th>
              <th>Javas kg</th>
              <th>Neto</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>${records}</tbody>
        </table>
      </div>
    </article>
  `;
}

function renderTickets(tickets) {
  elements.resultCount.textContent = `${formatNumber(tickets.length)} resultado${tickets.length === 1 ? "" : "s"}`;

  if (!tickets.length) {
    elements.ticketList.innerHTML = `
      <div class="directory-empty card">
        <strong>No hay tickets registrados.</strong>
        <span>Revisa otra jornada o limpia el filtro de codigo.</span>
      </div>
    `;
    return;
  }

  elements.ticketList.innerHTML = tickets.map(renderTicket).join("");
}

async function loadDailyTickets() {
  if (loading) {
    return;
  }

  loading = true;
  elements.ticketList.classList.add("is-loading");
  setMessage("Cargando resumen...");

  try {
    const response = await apiRequest(`/operacion/tickets-dia${buildQuery()}`);
    renderMeta(response.data);
    renderSummary(response.data.summary);
    renderOperationSummary(response.data.summary.by_operation || []);
    renderClientTotals(response.data.summary.by_client || []);
    renderTypeTotals(response.data.summary.by_type || []);
    renderTickets(response.data.tickets || []);
    setMessage("");
  } catch (error) {
    setMessage(getErrorMessage(error), true);
    renderSummary({
      tickets: 0,
      records: 0,
      cages: 0,
      birds: 0,
      gross_weight_kg: 0,
      tare_weight_kg: 0,
      net_weight_kg: 0
    });
    renderOperationSummary([]);
    renderClientTotals([]);
    renderTypeTotals([]);
    renderTickets([]);
  } finally {
    elements.ticketList.classList.remove("is-loading");
    loading = false;
  }
}

if (root) {
  elements.filters.addEventListener("submit", (event) => {
    event.preventDefault();
    loadDailyTickets();
  });
  elements.refresh.addEventListener("click", loadDailyTickets);

  loadDailyTickets();
}
