import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-provider-history]");
const providerId = root?.dataset.providerId;
const PERU_TIME_ZONE = "America/Lima";

const elements = {
  name: document.getElementById("providerName"),
  meta: document.getElementById("providerMeta"),
  message: document.getElementById("providerHistoryMessage"),
  financeSection: document.getElementById("providerFinanceSection"),
  directDepositsSection: document.getElementById("providerDirectDepositsSection"),
  recordCount: document.getElementById("providerRecordCount"),
  ticketCount: document.getElementById("providerTicketCount"),
  cageCount: document.getElementById("providerCageCount"),
  birdCount: document.getElementById("providerBirdCount"),
  netWeight: document.getElementById("providerNetWeight"),
  financeDocumented: document.getElementById("providerFinanceDocumented"),
  financeDirect: document.getElementById("providerFinanceDirect"),
  financeOwn: document.getElementById("providerFinanceOwn"),
  financePending: document.getElementById("providerFinancePending"),
  financePendingCosts: document.getElementById("providerFinancePendingCosts"),
  financeHelp: document.getElementById("providerFinanceHelp"),
  financeCurrency: document.getElementById("providerFinanceCurrency"),
  directDepositList: document.getElementById("providerDirectDepositList"),
  vehicleCount: document.getElementById("providerVehicleCount"),
  vehicleForm: document.getElementById("providerVehicleForm"),
  vehiclePlate: document.getElementById("providerVehiclePlate"),
  saveVehicle: document.getElementById("saveProviderVehicle"),
  vehicleMessage: document.getElementById("providerVehicleMessage"),
  vehicleList: document.getElementById("providerVehicleList"),
  filters: document.getElementById("providerHistoryFilters"),
  ticketSearch: document.getElementById("providerTicketSearch"),
  plateSearch: document.getElementById("providerPlateSearch"),
  dateFrom: document.getElementById("providerDateFrom"),
  dateTo: document.getElementById("providerDateTo"),
  clearFilters: document.getElementById("clearProviderFilters"),
  resultCount: document.getElementById("providerResultCount"),
  recordList: document.getElementById("providerRecordList"),
  pagination: document.getElementById("providerRecordPagination")
};

let currentPage = 1;
let loading = false;
let pendingVehicleRemoval = null;
let financeSequence = 0;

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

function formatCurrency(value, currency = "PEN") {
  return new Intl.NumberFormat("es-PE", {
    style: "currency",
    currency
  }).format(Number(value || 0));
}

function formatDateTime(value) {
  if (!value) {
    return "—";
  }

  return new Intl.DateTimeFormat("es-PE", {
    dateStyle: "short",
    timeStyle: "short",
    timeZone: PERU_TIME_ZONE
  }).format(new Date(value));
}

function getErrorMessage(error) {
  const firstValidationError = error?.data?.errors
    ? Object.values(error.data.errors).flat().find(Boolean)
    : null;

  return firstValidationError || error?.message || "No se pudo completar la operación.";
}

function setMessage(element, text, isError = false) {
  element.textContent = text || "";
  element.classList.toggle("is-error", Boolean(isError));
}

function normalizePlate(value) {
  return String(value || "").replace(/\s+/g, "").toLocaleUpperCase("es-PE");
}

function buildQuery(page = 1) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: "30"
  });
  const filters = {
    ticket: elements.ticketSearch.value.trim(),
    placa: normalizePlate(elements.plateSearch.value),
    fecha_desde: elements.dateFrom.value,
    fecha_hasta: elements.dateTo.value
  };

  Object.entries(filters).forEach(([key, value]) => {
    if (value) {
      params.set(key, value);
    }
  });

  return params.toString();
}

function renderProvider(provider) {
  elements.name.textContent = provider.name;
  elements.meta.textContent = `${provider.document_type} ${provider.document_number} · ${provider.address}`;
  document.title = `${provider.name} | Sistema Pollos`;
}

function renderSummary(summary) {
  elements.recordCount.textContent = formatNumber(summary.records);
  elements.ticketCount.textContent = formatNumber(summary.tickets);
  elements.cageCount.textContent = formatNumber(summary.cages);
  elements.birdCount.textContent = formatNumber(summary.birds);
  elements.netWeight.textContent = formatWeight(summary.net_weight_kg);
}

function renderFinance(finance) {
  elements.financeSection.hidden = !finance;
  elements.directDepositsSection.hidden = !finance;
  if (!finance) {
    return;
  }

  const currency = finance.currency || "PEN";
  elements.financeDocumented.textContent = formatCurrency(finance.documented, currency);
  elements.financeDirect.textContent = formatCurrency(finance.paid_directly_by_clients, currency);
  elements.financeOwn.textContent = formatCurrency(finance.paid_by_company, currency);
  elements.financePending.textContent = formatCurrency(finance.pending, currency);
  elements.financePendingCosts.textContent = formatNumber(finance.pending_costs.count);

  const pendingWeight = Number(finance.pending_costs.weight_kg || 0);
  const unapplied = Number(finance.unapplied || 0);
  const notes = [];
  if (pendingWeight > 0) {
    notes.push(`${formatWeight(pendingWeight)} aun no tienen precio de compra y no forman parte del total valorizado`);
  }
  if (unapplied > 0) {
    notes.push(`${formatCurrency(unapplied, currency)} esta registrado como adelanto sin aplicar`);
  }
  elements.financeHelp.textContent = notes.length
    ? `${notes.join(". ")}.`
    : "El saldo descuenta los pagos directos de clientes y los pagos realizados desde nuestras cuentas.";

  renderDirectDeposits(finance.recent_direct_deposits || []);
}

function renderDirectDeposits(deposits) {
  if (!deposits.length) {
    elements.directDepositList.innerHTML = `
      <tr>
        <td colspan="6" class="customer-history-empty-cell">Este proveedor aun no tiene depositos directos registrados.</td>
      </tr>
    `;
    return;
  }

  elements.directDepositList.innerHTML = deposits.map((deposit) => `
    <tr>
      <td>${escapeHtml(formatDateTime(deposit.paid_at))}</td>
      <td><strong>${escapeHtml(deposit.code || `#${deposit.id}`)}</strong></td>
      <td>${escapeHtml(deposit.client.name)}</td>
      <td>${escapeHtml(deposit.destination || "Cuenta del proveedor")}</td>
      <td>
        <strong>${escapeHtml(deposit.method)}</strong>
        <small>${escapeHtml(deposit.reference || "Sin referencia")}</small>
      </td>
      <td><strong>${formatCurrency(deposit.amount, deposit.currency || "PEN")}</strong></td>
    </tr>
  `).join("");
}

function renderVehicles(vehicles) {
  elements.vehicleCount.textContent = `${formatNumber(vehicles.length)} ${vehicles.length === 1 ? "camión" : "camiones"}`;

  if (!vehicles.length) {
    elements.vehicleList.innerHTML = `
      <div class="directory-empty">
        <strong>Sin camiones asignados.</strong>
        <span>Crea en Mi flota la primera placa asignada a este proveedor.</span>
      </div>
    `;
    return;
  }

  elements.vehicleList.innerHTML = vehicles.map((vehicle) => {
    const isConfirming = pendingVehicleRemoval === String(vehicle.id);

    return `
      <article class="provider-vehicle-card">
        <span class="provider-vehicle-icon">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 7h11v9H3z"></path>
            <path d="M14 10h3l3 3v3h-6z"></path>
            <circle cx="7" cy="18" r="2"></circle>
            <circle cx="17" cy="18" r="2"></circle>
          </svg>
        </span>
        <div>
          <strong>${escapeHtml(vehicle.plate)}</strong>
          <span>Camión de mi empresa · Asignado desde ${escapeHtml(vehicle.valid_from)}</span>
        </div>
        <button
          class="directory-record-action directory-record-action-danger ${isConfirming ? "is-confirming" : ""}"
          type="button"
          data-remove-vehicle="${escapeHtml(vehicle.id)}"
          aria-label="${isConfirming ? "Confirmar retiro de la asignación de" : "Retirar asignación de"} ${escapeHtml(vehicle.plate)}"
          title="${isConfirming ? "Confirmar retiro de la asignación" : "Retirar asignación del proveedor"}"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 7h16"></path>
            <path d="M9 7V5h6v2"></path>
            <path d="M8 11v7"></path>
            <path d="M16 11v7"></path>
            <path d="M6 7l1 13h10l1-13"></path>
          </svg>
        </button>
      </article>
    `;
  }).join("");
}

function renderRecords(records, meta) {
  elements.resultCount.textContent = `${formatNumber(meta.total)} resultado${meta.total === 1 ? "" : "s"}`;

  if (!records.length) {
    elements.recordList.innerHTML = `
      <tr>
        <td colspan="12" class="customer-history-empty-cell">
          No se encontraron pesadas para este proveedor.
        </td>
      </tr>
    `;
    return;
  }

  elements.recordList.innerHTML = records.map((record) => `
    <tr class="${record.status === "ACTIVA" ? "" : "is-inactive"}">
      <td>${escapeHtml(formatDateTime(record.weighed_at))}</td>
      <td>
        <strong>${escapeHtml(record.ticket.code)}</strong>
        <small>${escapeHtml(record.ticket.operating_date)}</small>
      </td>
      <td><strong>${escapeHtml(record.destination.name)}</strong></td>
      <td><span class="customer-status provider-destination-${escapeHtml(record.destination.type.toLowerCase())}">${escapeHtml(record.destination.type)}</span></td>
      <td>${escapeHtml(record.plate || "—")}</td>
      <td>${escapeHtml(record.chicken_type)}</td>
      <td>${formatNumber(record.cages)}</td>
      <td>${formatNumber(record.birds)}</td>
      <td>${formatWeight(record.gross_weight_kg)}</td>
      <td>${formatWeight(record.tare_weight_kg)}</td>
      <td><strong>${formatWeight(record.net_weight_kg)}</strong></td>
      <td><span class="customer-status customer-status-${escapeHtml(record.status.toLowerCase())}">${escapeHtml(record.status)}</span></td>
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

async function loadProviderHistory(page = 1) {
  if (!providerId || loading) {
    return;
  }

  loading = true;
  currentPage = page;
  setMessage(elements.message, "Cargando información...");

  try {
    const response = await apiRequest(`/proveedores/${providerId}/historial?${buildQuery(page)}`);
    renderProvider(response.data.provider);
    renderSummary(response.data.summary);
    if (elements.financeSection) void loadFinance();
    renderVehicles(response.data.vehicles);
    renderRecords(response.data.records, response.meta);
    renderPagination(response.meta);
    setMessage(elements.message, "");
  } catch (error) {
    setMessage(elements.message, getErrorMessage(error), true);
    elements.recordList.innerHTML = `
      <tr><td colspan="12" class="customer-history-empty-cell">No fue posible cargar las pesadas.</td></tr>
    `;
  } finally {
    loading = false;
  }
}

async function loadFinance() {
  if (!elements.financeSection || !elements.financeCurrency) return;

  const sequence = ++financeSequence;
  const params = new URLSearchParams();
  if (elements.financeCurrency.value) {
    params.set("moneda", elements.financeCurrency.value);
  }
  const query = params.toString();

  try {
    const response = await apiRequest(`/finanzas/proveedores/${providerId}/resumen${query ? `?${query}` : ""}`);
    if (sequence !== financeSequence) return;
    renderFinance(response.data);
  } catch (error) {
    if (sequence !== financeSequence) return;
    renderFinance(null);
    if (![401, 403].includes(error?.status)) {
      setMessage(elements.message, "No fue posible cargar el resumen financiero del proveedor.", true);
    }
  }
}

elements.financeCurrency?.addEventListener("change", () => void loadFinance());

async function saveVehicle(event) {
  event.preventDefault();
  const plate = normalizePlate(elements.vehiclePlate.value);

  if (!plate) {
    setMessage(elements.vehicleMessage, "Ingresa la placa del camión.", true);
    return;
  }

  elements.saveVehicle.disabled = true;
  setMessage(elements.vehicleMessage, "Creando el camión en Mi flota y asignándolo al proveedor...");

  try {
    const response = await apiRequest(`/proveedores/${providerId}/vehiculos`, {
      method: "POST",
      body: JSON.stringify({ placa: plate })
    });
    elements.vehicleForm.reset();
    pendingVehicleRemoval = null;
    setMessage(elements.vehicleMessage, response.message);
    await loadProviderHistory(currentPage);
  } catch (error) {
    setMessage(elements.vehicleMessage, getErrorMessage(error), true);
  } finally {
    elements.saveVehicle.disabled = false;
  }
}

async function removeVehicle(associationId) {
  const id = String(associationId);

  if (pendingVehicleRemoval !== id) {
    pendingVehicleRemoval = id;
    setMessage(elements.vehicleMessage, "Pulsa retirar otra vez para confirmar. El camión seguirá en Mi flota.", true);
    elements.vehicleList.querySelectorAll("[data-remove-vehicle]").forEach((button) => {
      button.classList.toggle("is-confirming", button.dataset.removeVehicle === id);
      button.title = button.dataset.removeVehicle === id ? "Confirmar retiro de la asignación" : "Retirar asignación del proveedor";
    });
    return;
  }

  try {
    const response = await apiRequest(`/proveedores/${providerId}/vehiculos/${id}`, {
      method: "DELETE"
    });
    pendingVehicleRemoval = null;
    setMessage(elements.vehicleMessage, response.message);
    await loadProviderHistory(currentPage);
  } catch (error) {
    setMessage(elements.vehicleMessage, getErrorMessage(error), true);
  }
}

elements.vehiclePlate.addEventListener("input", () => {
  const selectionStart = elements.vehiclePlate.selectionStart;
  elements.vehiclePlate.value = normalizePlate(elements.vehiclePlate.value);
  elements.vehiclePlate.setSelectionRange(selectionStart, selectionStart);
  setMessage(elements.vehicleMessage, "");
});
elements.vehicleForm.addEventListener("submit", saveVehicle);
elements.vehicleList.addEventListener("click", (event) => {
  const button = event.target.closest("[data-remove-vehicle]");
  if (button) {
    void removeVehicle(button.dataset.removeVehicle);
  }
});
elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  void loadProviderHistory(1);
});
elements.clearFilters.addEventListener("click", () => {
  elements.filters.reset();
  void loadProviderHistory(1);
});
elements.pagination.addEventListener("click", (event) => {
  const button = event.target.closest("[data-page]");
  if (!button || button.disabled) {
    return;
  }

  const page = Number(button.dataset.page);
  if (Number.isInteger(page) && page > 0 && page !== currentPage) {
    void loadProviderHistory(page);
  }
});

void loadProviderHistory();
