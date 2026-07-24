import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-daily-tickets]");
const clientTotals = document.getElementById("dailyClientTotals");
const adminSection = document.getElementById("dailyTicketAdmin");
const ticketFilters = document.getElementById("dailyTicketFilters");
const ticketDate = document.getElementById("dailyTicketDate");
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
      <td>${formatWeight(item.gross_weight_kg)}</td>
      <td>${formatWeight(item.tare_weight_kg)}</td>
      <td class="daily-client-return"><strong>${formatWeight(item.return_net_weight_kg)}</strong></td>
      <td class="daily-client-net"><strong>${formatWeight(item.net_weight_kg)}</strong></td>
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
  const items = tickets.filter((ticket) => !selectedStatus || ticket.status === selectedStatus);

  if (!items.length) {
    ticketRows.innerHTML = `
      <tr>
        <td colspan="8" class="customer-history-empty-cell">No hay tickets que coincidan con la consulta.</td>
      </tr>
    `;
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
}

function renderAdminTickets(data) {
  const canManage = data?.access?.is_administrator === true;

  if (!canManage || !adminSection) {
    if (adminSection) adminSection.hidden = true;
    return;
  }

  adminSection.hidden = false;
  tickets = Array.isArray(data.tickets) ? data.tickets : [];
  if (ticketDate && !ticketDate.value) ticketDate.value = data.operating_date || "";
  renderTicketRows();
}

function ticketEndpoint() {
  const params = new URLSearchParams({ include_voided: "1" });
  if (ticketDate?.value) params.set("date", ticketDate.value);
  if (ticketSearch?.value.trim()) params.set("ticket", ticketSearch.value.trim());

  return `/operacion/tickets-dia?${params.toString()}`;
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
  renderMessage("Cargando resultados del día...");
  if (ticketFeedback) ticketFeedback.textContent = "Cargando tickets...";

  try {
    const response = await apiRequest(ticketEndpoint());
    const data = response.data || {};
    renderClientTotals(data.summary?.by_client || []);
    renderAdminTickets(data);
    if (ticketFeedback && data.access?.is_administrator) {
      const voided = tickets.filter((ticket) => ticket.status === "ANULADO").length;
      ticketFeedback.textContent = `${tickets.length} ticket(s) encontrados · ${voided} anulado(s).`;
    }
  } catch (error) {
    renderMessage(error?.message || "No se pudo cargar el resumen por cliente.");
    if (ticketFeedback) ticketFeedback.textContent = errorMessage(error);
  }
}

if (root && clientTotals) {
  loadDailyClientTotals();
}

ticketFilters?.addEventListener("submit", (event) => {
  event.preventDefault();
  loadDailyClientTotals();
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
