import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-provider-history]");
const providerId = root?.dataset.providerId;
const PERU_TIME_ZONE = "America/Lima";

const elements = {
  name: document.getElementById("providerName"),
  meta: document.getElementById("providerMeta"),
  message: document.getElementById("providerHistoryMessage"),
  recordCount: document.getElementById("providerRecordCount"),
  ticketCount: document.getElementById("providerTicketCount"),
  cageCount: document.getElementById("providerCageCount"),
  birdCount: document.getElementById("providerBirdCount"),
  netWeight: document.getElementById("providerNetWeight"),
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

function renderVehicles(vehicles) {
  elements.vehicleCount.textContent = `${formatNumber(vehicles.length)} ${vehicles.length === 1 ? "camión" : "camiones"}`;

  if (!vehicles.length) {
    elements.vehicleList.innerHTML = `
      <div class="directory-empty">
        <strong>Sin camiones asignados.</strong>
        <span>Agrega la primera placa de este proveedor.</span>
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
          <span>Asignado desde ${escapeHtml(vehicle.valid_from)}</span>
        </div>
        <button
          class="directory-record-action directory-record-action-danger ${isConfirming ? "is-confirming" : ""}"
          type="button"
          data-remove-vehicle="${escapeHtml(vehicle.id)}"
          aria-label="${isConfirming ? "Confirmar retiro de" : "Retirar"} ${escapeHtml(vehicle.plate)}"
          title="${isConfirming ? "Confirmar retiro" : "Retirar camión"}"
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

async function saveVehicle(event) {
  event.preventDefault();
  const plate = normalizePlate(elements.vehiclePlate.value);

  if (!plate) {
    setMessage(elements.vehicleMessage, "Ingresa la placa del camión.", true);
    return;
  }

  elements.saveVehicle.disabled = true;
  setMessage(elements.vehicleMessage, "Guardando camión...");

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
    setMessage(elements.vehicleMessage, "Pulsa retirar otra vez para confirmar.", true);
    elements.vehicleList.querySelectorAll("[data-remove-vehicle]").forEach((button) => {
      button.classList.toggle("is-confirming", button.dataset.removeVehicle === id);
      button.title = button.dataset.removeVehicle === id ? "Confirmar retiro" : "Retirar camión";
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
