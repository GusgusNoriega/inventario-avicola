import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-daily-tickets]");
const clientTotals = document.getElementById("dailyClientTotals");
const CHICKEN_TYPE_SHORT_LABELS = {
  POLLO_VIVO: "P V",
  POLLO_MUERTO: "P M",
  POLLO_PELADO: "P P",
  POLLO_BENEFICIADO: "P B"
};

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
    renderMessage("No hay movimientos de clientes para el día de hoy.");
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

async function loadDailyClientTotals() {
  renderMessage("Cargando resultados del día...");

  try {
    const response = await apiRequest("/operacion/tickets-dia");
    renderClientTotals(response.data?.summary?.by_client || []);
  } catch (error) {
    renderMessage(error?.message || "No se pudo cargar el resumen por cliente.");
  }
}

if (root && clientTotals) {
  loadDailyClientTotals();
}
