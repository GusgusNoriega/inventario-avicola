import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-client-history]");
const clientId = root?.dataset.clientId;
const PERU_TIME_ZONE = "America/Lima";
const OPERATION_LABELS = {
  DESPACHO: "Despacho",
  DEVOLUCION: "Devolucion"
};
const CHICKEN_CONDITION_LABELS = {
  VIVO: "Pollo vivo",
  MUERTO: "Pollo muerto"
};

const elements = {
  name: document.getElementById("customerName"),
  meta: document.getElementById("customerMeta"),
  message: document.getElementById("customerHistoryMessage"),
  filters: document.getElementById("customerHistoryFilters"),
  ticketSearch: document.getElementById("historyTicketSearch"),
  dateFrom: document.getElementById("historyDateFrom"),
  dateTo: document.getElementById("historyDateTo"),
  clearFilters: document.getElementById("clearHistoryFilters"),
  ticketCount: document.getElementById("historyTicketCount"),
  recordCount: document.getElementById("historyRecordCount"),
  cageCount: document.getElementById("historyCageCount"),
  birdCount: document.getElementById("historyBirdCount"),
  netWeight: document.getElementById("historyNetWeight"),
  amount: document.getElementById("historyAmount"),
  resultCount: document.getElementById("historyResultCount"),
  ticketList: document.getElementById("customerTicketList"),
  pagination: document.getElementById("customerTicketPagination"),
  priceHistory: document.getElementById("customerPriceHistory")
};

let currentPage = 1;
let loading = false;

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function formatCurrency(value) {
  return new Intl.NumberFormat("es-PE", {
    style: "currency",
    currency: "PEN"
  }).format(Number(value || 0));
}

function formatNumber(value) {
  return new Intl.NumberFormat("es-PE").format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatDate(value, includeTime = false) {
  if (!value) {
    return "—";
  }

  const date = new Date(includeTime ? value : `${value}T12:00:00`);
  return new Intl.DateTimeFormat("es-PE", includeTime
    ? { dateStyle: "short", timeStyle: "short", timeZone: PERU_TIME_ZONE }
    : { dateStyle: "medium", timeZone: PERU_TIME_ZONE }
  ).format(date);
}

function normalizeCode(value) {
  return String(value || "").trim().toUpperCase();
}

function operationLabel(value) {
  const code = normalizeCode(value);
  return OPERATION_LABELS[code] || code || "Despacho";
}

function chickenConditionLabel(value) {
  const code = normalizeCode(value);
  return CHICKEN_CONDITION_LABELS[code] || code || "Pollo vivo";
}

function getErrorMessage(error) {
  const firstValidationError = error?.data?.errors
    ? Object.values(error.data.errors).flat().find(Boolean)
    : null;

  return firstValidationError || error?.message || "No se pudo cargar el historial.";
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function buildQuery(page = 1) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: "20"
  });
  const ticket = elements.ticketSearch.value.trim();

  if (ticket) {
    params.set("ticket", ticket);
  }
  if (elements.dateFrom.value) {
    params.set("fecha_desde", elements.dateFrom.value);
  }
  if (elements.dateTo.value) {
    params.set("fecha_hasta", elements.dateTo.value);
  }

  return params.toString();
}

function renderClient(client) {
  elements.name.textContent = client.name;
  elements.meta.textContent = `${client.document_type} ${client.document_number} · ${client.address}`;
  document.title = `${client.name} | Sistema Pollos`;
}

function renderSummary(summary) {
  elements.ticketCount.textContent = formatNumber(summary.tickets);
  elements.recordCount.textContent = formatNumber(summary.records);
  elements.cageCount.textContent = formatNumber(summary.cages);
  elements.birdCount.textContent = formatNumber(summary.birds);
  elements.netWeight.textContent = formatWeight(summary.net_weight_kg);
  elements.amount.textContent = formatCurrency(summary.amount);
}

function renderTicket(ticket) {
  const operationType = normalizeCode(ticket.operation_type);
  const isReturn = operationType === "DEVOLUCION";
  const operationClass = isReturn
    ? "customer-operation-return"
    : "customer-operation-dispatch";
  const returnValueClass = isReturn ? "customer-return-value" : "";
  const records = ticket.records.length
    ? ticket.records.map((record) => {
        const condition = normalizeCode(record.chicken_condition);
        const typeContent = condition === "MUERTO"
          ? `
              <span class="customer-chicken-condition customer-chicken-condition-muerto">
                ${escapeHtml(chickenConditionLabel(record.chicken_condition))}
              </span>
            `
          : `<strong>${escapeHtml(record.chicken_type.name)}</strong>`;

        return `
        <tr class="${record.status === "ACTIVA" ? "" : "is-inactive"}">
          <td>#${escapeHtml(record.number)}</td>
          <td>${escapeHtml(formatDate(record.weighed_at, true))}</td>
          <td>
            <span class="customer-record-type">
              ${typeContent}
            </span>
          </td>
          <td>${formatNumber(record.cages)}</td>
          <td>${formatNumber(record.birds)}</td>
          <td>${formatWeight(record.gross_weight_kg)}</td>
          <td>${formatWeight(record.tare_weight_kg)}</td>
          <td><strong class="${returnValueClass}">${formatWeight(record.movement_net_weight_kg ?? record.net_weight_kg)}</strong></td>
          <td>${record.price_kg === null ? "—" : formatCurrency(record.price_kg)}</td>
          <td><strong class="${returnValueClass}">${formatCurrency(record.amount)}</strong></td>
          <td><span class="customer-status customer-status-${escapeHtml(record.status.toLowerCase())}">${escapeHtml(record.status)}</span></td>
        </tr>
      `;
      }).join("")
    : `
        <tr>
          <td colspan="11" class="customer-history-empty-cell">Este ticket todavía no tiene registros.</td>
        </tr>
      `;

  const prices = ticket.prices.length
    ? ticket.prices.map((price) => `
        <span>${escapeHtml(price.chicken_type)}: <strong>${formatCurrency(price.price_kg)}/kg</strong></span>
      `).join("")
    : "<span>Sin precios congelados</span>";

  return `
    <article class="customer-ticket card">
      <header class="customer-ticket-head">
        <div>
          <div class="customer-ticket-badges">
            <span class="directory-record-tag">${escapeHtml(ticket.status)}</span>
            <span class="customer-operation-tag ${operationClass}">${escapeHtml(operationLabel(ticket.operation_type))}</span>
          </div>
          <h3>${escapeHtml(ticket.code)}</h3>
          <p>${escapeHtml(formatDate(ticket.operating_date))} · Canal ${escapeHtml(ticket.channel)}</p>
        </div>
        <div class="customer-ticket-total">
          <span>Total del ticket</span>
          <strong class="${returnValueClass}">${formatCurrency(ticket.summary.amount)}</strong>
        </div>
      </header>
      <div class="customer-ticket-summary">
        <span><strong>${formatNumber(ticket.summary.records)}</strong> registros</span>
        <span><strong>${formatNumber(ticket.summary.cages)}</strong> javas</span>
        <span><strong>${formatNumber(ticket.summary.birds)}</strong> aves</span>
        <span><strong class="${returnValueClass}">${formatWeight(ticket.summary.net_weight_kg)}</strong> netos</span>
      </div>
      <div class="customer-ticket-prices">${prices}</div>
      <div class="customer-history-table-wrap">
        <table class="customer-history-table customer-ticket-records">
          <thead>
            <tr>
              <th>Registro</th>
              <th>Fecha/hora</th>
              <th>Tipo</th>
              <th>Javas</th>
              <th>Aves</th>
              <th>Bruto</th>
              <th>Tara</th>
              <th>Neto</th>
              <th>Precio/kg</th>
              <th>Importe</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>${records}</tbody>
        </table>
      </div>
    </article>
  `;
}

function renderTickets(tickets, meta) {
  elements.resultCount.textContent = `${formatNumber(meta.total)} resultado${meta.total === 1 ? "" : "s"}`;

  if (!tickets.length) {
    elements.ticketList.innerHTML = `
      <div class="directory-empty card">
        <strong>No se encontraron tickets.</strong>
        <span>Prueba con otro código o rango de fechas.</span>
      </div>
    `;
    return;
  }

  elements.ticketList.innerHTML = tickets.map(renderTicket).join("");
}

function renderPriceHistory(prices) {
  if (!prices.length) {
    elements.priceHistory.innerHTML = `
      <tr>
        <td colspan="6" class="customer-history-empty-cell">El cliente no tiene historial de precios.</td>
      </tr>
    `;
    return;
  }

  elements.priceHistory.innerHTML = prices.map((price) => `
    <tr>
      <td><strong>${escapeHtml(price.chicken_type.name)}</strong></td>
      <td>${formatCurrency(price.price_kg)}</td>
      <td>${escapeHtml(formatDate(price.valid_from, true))}</td>
      <td>${price.is_current ? "Actual" : escapeHtml(formatDate(price.valid_until, true))}</td>
      <td>${escapeHtml(price.reason || "—")}</td>
      <td><span class="customer-status ${price.is_current ? "customer-status-current" : ""}">${price.is_current ? "VIGENTE" : "HISTÓRICO"}</span></td>
    </tr>
  `).join("");
}

function renderPagination(meta) {
  if (meta.last_page <= 1) {
    elements.pagination.innerHTML = "";
    return;
  }

  elements.pagination.innerHTML = `
    <button class="btn btn-ghost directory-btn" type="button" data-page="${meta.current_page - 1}" ${meta.current_page <= 1 ? "disabled" : ""}>Anterior</button>
    <span>Página ${meta.current_page} de ${meta.last_page}</span>
    <button class="btn btn-ghost directory-btn" type="button" data-page="${meta.current_page + 1}" ${meta.current_page >= meta.last_page ? "disabled" : ""}>Siguiente</button>
  `;
}

async function loadHistory(page = 1) {
  if (!clientId || loading) {
    return;
  }

  loading = true;
  currentPage = page;
  setMessage("Cargando historial...");
  elements.ticketList.classList.add("is-loading");

  try {
    const response = await apiRequest(`/clientes/${clientId}/historial?${buildQuery(page)}`);
    renderClient(response.data.client);
    renderSummary(response.data.summary);
    renderTickets(response.data.tickets, response.meta);
    renderPriceHistory(response.data.price_history);
    renderPagination(response.meta);
    setMessage("");
  } catch (error) {
    setMessage(getErrorMessage(error), true);
    elements.ticketList.innerHTML = `
      <div class="directory-empty card">
        <strong>No fue posible cargar los tickets.</strong>
      </div>
    `;
  } finally {
    loading = false;
    elements.ticketList.classList.remove("is-loading");
  }
}

elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  void loadHistory(1);
});

elements.clearFilters.addEventListener("click", () => {
  elements.filters.reset();
  void loadHistory(1);
});

elements.pagination.addEventListener("click", (event) => {
  const button = event.target.closest("[data-page]");
  if (!button || button.disabled) {
    return;
  }

  const page = Number(button.dataset.page);
  if (Number.isInteger(page) && page > 0 && page !== currentPage) {
    void loadHistory(page);
  }
});

void loadHistory();
