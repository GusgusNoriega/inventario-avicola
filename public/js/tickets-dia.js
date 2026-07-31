import { apiRequest } from "./api-client.js";
import { printDailySummary } from "./daily-summary-printer.js";

const root = document.querySelector("[data-daily-tickets]");
const clientTotals = document.getElementById("dailyClientTotals");
const clientTable = document.querySelector(".daily-client-table");
const journeyFilter = document.getElementById("dailyJourneyFilter");
const journeyDate = document.getElementById("dailyJourneyDate");
const journeySubmit = document.getElementById("dailyJourneySubmit");
const journeyPrint = document.getElementById("dailyJourneyPrint");
const journeyMeta = document.getElementById("dailyJourneyMeta");
const journeyWindow = document.getElementById("dailyJourneyWindow");
const adminSection = document.getElementById("dailyTicketAdmin");
const ticketFilters = document.getElementById("dailyTicketFilters");
const ticketSearch = document.getElementById("dailyTicketSearch");
const ticketStatus = document.getElementById("dailyTicketStatus");
const ticketRows = document.getElementById("dailyTicketRows");
const ticketFeedback = document.getElementById("dailyTicketFeedback");
const voidModal = document.getElementById("dailyTicketVoidModal");
const voidForm = document.getElementById("dailyTicketVoidForm");
const voidCode = document.getElementById("dailyTicketVoidCode");
const voidReason = document.getElementById("dailyTicketVoidReason");
const voidError = document.getElementById("dailyTicketVoidError");
const voidSubmit = document.getElementById("dailyTicketVoidSubmit");
const voidClose = document.getElementById("dailyTicketVoidClose");
const voidCancel = document.getElementById("dailyTicketVoidCancel");
const CHICKEN_TYPE_SHORT_LABELS = {
  POLLO_VIVO: "P V",
  POLLO_MUERTO: "P M",
  POLLO_PELADO: "P P",
  POLLO_BENEFICIADO: "P B"
};
let tickets = [];
let selectedTicket = null;
let loadedJourneyDate = "";
let loadedJourneyWindow = "";

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

function formatDate(value) {
  if (!value) return "--";

  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? escapeHtml(value)
    : new Intl.DateTimeFormat("es-PE").format(date);
}

function journeyDateLabel(value) {
  if (!value) return "--";

  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? String(value)
    : new Intl.DateTimeFormat("es-PE", {
      day: "2-digit",
      month: "long",
      year: "numeric"
    }).format(date);
}

function journeyWindowLabel(range) {
  const fromDate = range?.from_date;
  const fromTime = range?.from_time;
  const toDate = range?.to_date;
  const toTime = range?.to_time;

  if (!fromDate || !fromTime || !toDate || !toTime) {
    return "Horario operativo no disponible.";
  }

  return `Desde ${formatDate(fromDate)} a las ${fromTime} hasta ${formatDate(toDate)} a las ${toTime} (hora final no incluida).`;
}

function normalizeCode(value) {
  return String(value || "").trim().toUpperCase();
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

function renderMessage(message) {
  clientTotals.innerHTML = `
    <tr>
      <td colspan="8" class="customer-history-empty-cell">${escapeHtml(message)}</td>
    </tr>
  `;
}

function renderClientTotals(clients) {
  const items = Array.isArray(clients) ? clients : [];

  if (!items.length) {
    renderMessage("No hay movimientos de clientes para el día consultado.");
    return;
  }

  clientTotals.innerHTML = items.map((item) => `
    <tr>
      <td class="daily-client-name"><strong>${escapeHtml(item.client?.name || "Cliente sin registrar")}</strong></td>
      <td><div class="daily-client-types">${renderClientTypes(item.chicken_types)}</div></td>
      <td>${formatNumber(item.cages)}</td>
      <td>${formatNumber(item.birds)}</td>
      <td data-print-weight="${Number(item.gross_weight_kg || 0)}">${formatWeight(item.gross_weight_kg)}</td>
      <td data-print-weight="${Number(item.tare_weight_kg || 0)}">${formatWeight(item.tare_weight_kg)}</td>
      <td class="daily-client-return" data-print-weight="${Number(item.return_net_weight_kg || 0)}"><strong>${formatWeight(item.return_net_weight_kg)}</strong></td>
      <td class="daily-client-net" data-print-weight="${Number(item.net_weight_kg || 0)}"><strong>${formatWeight(item.net_weight_kg)}</strong></td>
    </tr>
  `).join("");
}

function statusLabel(status) {
  return status === "ANULADO"
    ? "Anulado"
    : status === "CERRADO"
      ? "Vigente"
      : status || "--";
}

function renderTicketRows() {
  if (!ticketRows) return;

  const selectedStatus = String(ticketStatus?.value || "");
  const search = normalizeCode(ticketSearch?.value);
  const items = tickets.filter((ticket) => {
    const matchesStatus = !selectedStatus || ticket.status === selectedStatus;
    const matchesSearch = !search || normalizeCode(ticket.code).includes(search);

    return matchesStatus && matchesSearch;
  });

  if (!items.length) {
    ticketRows.innerHTML = `
      <tr>
        <td colspan="8" class="customer-history-empty-cell">No hay tickets que coincidan con la consulta.</td>
      </tr>
    `;
    if (ticketFeedback) {
      ticketFeedback.textContent = `0 de ${tickets.length} ticket(s) coinciden con el filtro.`;
    }
    return;
  }

  ticketRows.innerHTML = items.map((ticket) => {
    const isVoided = ticket.status === "ANULADO";
    const historical = ticket.historical_summary || ticket.summary || {};
    const voidDetails = isVoided
      ? `<small class="daily-ticket-void-details">${escapeHtml(ticket.void_reason || "Sin motivo registrado")} · ${escapeHtml(ticket.voided_by?.name || "Administrador")}</small>`
      : "";
    const action = ticket.can_void
      ? `<button class="btn btn-danger daily-ticket-void-button" type="button" data-ticket-id="${Number(ticket.id)}">Anular</button>`
      : `<span class="daily-ticket-readonly">Solo consulta</span>`;

    return `
      <tr class="${isVoided ? "is-voided" : ""}">
        <td><strong>${escapeHtml(ticket.code)}</strong></td>
        <td>${formatDate(ticket.operating_date)}</td>
        <td>${escapeHtml(ticket.channel)}</td>
        <td>${escapeHtml(ticket.operation_type)}</td>
        <td>${escapeHtml(ticket.destination?.name || "Sin destino")}</td>
        <td>${formatWeight(historical.net_weight_kg)}</td>
        <td>
          <span class="daily-ticket-status ${isVoided ? "is-voided" : "is-active"}">${escapeHtml(statusLabel(ticket.status))}</span>
          ${voidDetails}
        </td>
        <td>${action}</td>
      </tr>
    `;
  }).join("");

  if (ticketFeedback) {
    const voided = items.filter((ticket) => ticket.status === "ANULADO").length;
    ticketFeedback.textContent = `${items.length} de ${tickets.length} ticket(s) · ${voided} anulado(s).`;
  }
}

function renderAdminTickets(data) {
  const canManage = data?.access?.is_administrator === true;

  if (!canManage || !adminSection) {
    if (adminSection) adminSection.hidden = true;
    return;
  }

  adminSection.hidden = false;
  tickets = Array.isArray(data.tickets) ? data.tickets : [];
  renderTicketRows();
}

function ticketEndpoint() {
  const params = new URLSearchParams({ include_voided: "1" });
  if (journeyDate?.value) params.set("date", journeyDate.value);

  return `/operacion/tickets-dia?${params.toString()}`;
}

function updateJourneyContext(data) {
  const operatingDate = String(data?.operating_date || journeyDate?.value || "");
  const label = journeyDateLabel(operatingDate);

  loadedJourneyDate = operatingDate;
  loadedJourneyWindow = journeyWindowLabel(data?.range);
  if (journeyDate) journeyDate.value = operatingDate;
  if (journeyMeta) journeyMeta.textContent = `Jornada operativa del ${label}.`;
  if (journeyWindow) journeyWindow.textContent = loadedJourneyWindow;

  if (operatingDate && window.history?.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.set("date", operatingDate);
    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }
}

function setJourneyLoading(loading) {
  if (journeyDate) journeyDate.disabled = loading;
  if (journeySubmit) {
    journeySubmit.disabled = loading;
    journeySubmit.textContent = loading ? "Consultando..." : "Ver jornada";
  }
  if (journeyPrint) journeyPrint.disabled = loading || !loadedJourneyDate;
}

function errorMessage(error) {
  const errors = error?.data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }

  return error?.message || "No se pudo completar la operación.";
}

function openVoidModal(ticket) {
  selectedTicket = ticket;
  if (voidCode) voidCode.textContent = ticket.code || "--";
  if (voidReason) voidReason.value = "";
  if (voidError) voidError.textContent = "";
  if (voidModal) voidModal.hidden = false;
  document.body.classList.add("daily-ticket-modal-open");
  window.setTimeout(() => voidReason?.focus(), 0);
}

function closeVoidModal() {
  selectedTicket = null;
  if (voidModal) voidModal.hidden = true;
  document.body.classList.remove("daily-ticket-modal-open");
}

async function loadDailyClientTotals() {
  loadedJourneyDate = "";
  loadedJourneyWindow = "";
  renderMessage("Cargando resultados del día...");
  if (ticketFeedback) ticketFeedback.textContent = "Cargando tickets...";
  setJourneyLoading(true);

  try {
    const response = await apiRequest(ticketEndpoint());
    const data = response.data || {};
    updateJourneyContext(data);
    renderClientTotals(data.summary?.by_client || []);
    renderAdminTickets(data);
    return true;
  } catch (error) {
    renderMessage(error?.message || "No se pudo cargar el resumen por cliente.");
    if (ticketFeedback) ticketFeedback.textContent = errorMessage(error);
    return false;
  } finally {
    setJourneyLoading(false);
  }
}

if (root && clientTotals) {
  const requestedDate = new URLSearchParams(window.location.search).get("date");
  if (journeyDate && /^\d{4}-\d{2}-\d{2}$/.test(requestedDate || "")) {
    journeyDate.value = requestedDate;
  }
  loadDailyClientTotals();
}

journeyFilter?.addEventListener("submit", (event) => {
  event.preventDefault();
  loadDailyClientTotals();
});

journeyPrint?.addEventListener("click", async () => {
  if (!loadedJourneyDate || journeyDate?.value !== loadedJourneyDate) {
    const loaded = await loadDailyClientTotals();
    if (!loaded) return;
  }

  printDailySummary({
    dateLabel: journeyDateLabel(loadedJourneyDate),
    windowLabel: loadedJourneyWindow,
    table: clientTable,
    onError: () => renderMessage("No se pudo preparar la impresión de la jornada.")
  });
});

ticketFilters?.addEventListener("submit", (event) => {
  event.preventDefault();
  renderTicketRows();
});

ticketStatus?.addEventListener("change", renderTicketRows);

ticketRows?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-ticket-id]");
  if (!button) return;

  const ticket = tickets.find((item) => Number(item.id) === Number(button.dataset.ticketId));
  if (ticket?.can_void) openVoidModal(ticket);
});

voidForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!selectedTicket || !voidReason || !voidSubmit || !voidError) return;

  const reason = voidReason.value.trim();
  if (reason.length < 3) {
    voidError.textContent = "Escribe un motivo de al menos 3 caracteres.";
    voidReason.focus();
    return;
  }

  voidSubmit.disabled = true;
  voidSubmit.textContent = "Anulando...";
  voidError.textContent = "";

  try {
    const response = await apiRequest(`/operacion/tickets/${Number(selectedTicket.id)}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    closeVoidModal();
    if (ticketFeedback) ticketFeedback.textContent = response.message || "Ticket anulado correctamente.";
    await loadDailyClientTotals();
  } catch (error) {
    voidError.textContent = errorMessage(error);
  } finally {
    voidSubmit.disabled = false;
    voidSubmit.textContent = "Confirmar anulación";
  }
});

voidClose?.addEventListener("click", closeVoidModal);
voidCancel?.addEventListener("click", closeVoidModal);
voidModal?.addEventListener("click", (event) => {
  if (event.target === voidModal) closeVoidModal();
});
window.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && voidModal && !voidModal.hidden) closeVoidModal();
});
