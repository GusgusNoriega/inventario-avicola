import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-provider-report]");

if (!root) {
  throw new Error("No se encontró la vista del reporte de proveedores.");
}

const elements = {
  form: document.getElementById("providerReportFilters"),
  journey: document.getElementById("providerJourneyFilter"),
  provider: document.getElementById("providerNameFilter"),
  truck: document.getElementById("providerTruckFilter"),
  submit: document.getElementById("providerFilterSubmit"),
  reset: document.getElementById("providerFilterReset"),
  returnCurrent: document.getElementById("providerReturnCurrent"),
  message: document.getElementById("providerReportMessage"),
  currentTitle: document.getElementById("providerCurrentJourneyTitle"),
  currentWindow: document.getElementById("providerCurrentJourneyWindow"),
  currentBranch: document.getElementById("providerCurrentBranch"),
  selectedBadge: document.getElementById("providerSelectedJourneyBadge"),
  statProviders: document.getElementById("providerStatProviders"),
  statTrucks: document.getElementById("providerStatTrucks"),
  statRecords: document.getElementById("providerStatRecords"),
  statTickets: document.getElementById("providerStatTickets"),
  statCages: document.getElementById("providerStatCages"),
  statBirds: document.getElementById("providerStatBirds"),
  statNetWeight: document.getElementById("providerStatNetWeight"),
  statAverage: document.getElementById("providerStatAverage"),
  truckRows: document.getElementById("providerTruckRows"),
  truckCount: document.getElementById("providerTruckCount"),
  destinationCards: document.getElementById("providerDestinationCards"),
  destinationCount: document.getElementById("providerDestinationCount"),
  detailRows: document.getElementById("providerDetailRows"),
  recordRange: document.getElementById("providerRecordRange"),
  pagination: document.getElementById("providerReportPagination"),
  pagePrevious: document.getElementById("providerPagePrevious"),
  pageNext: document.getElementById("providerPageNext"),
  pageStatus: document.getElementById("providerPageStatus")
};

const numberFormatter = new Intl.NumberFormat("es-PE");
const dateFormatter = new Intl.DateTimeFormat("es-PE", {
  day: "2-digit",
  month: "long",
  year: "numeric"
});
const shortDateFormatter = new Intl.DateTimeFormat("es-PE", {
  day: "2-digit",
  month: "short",
  year: "numeric"
});
const dateTimeFormatter = new Intl.DateTimeFormat("es-PE", {
  day: "2-digit",
  month: "short",
  hour: "2-digit",
  minute: "2-digit"
});

let reportData = null;
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

function parseDate(value) {
  if (!value) return null;

  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatJourneyDate(value) {
  const date = parseDate(value);
  return date ? dateFormatter.format(date) : String(value || "--");
}

function formatShortDate(value) {
  const date = parseDate(value);
  return date ? shortDateFormatter.format(date) : String(value || "--");
}

function formatDateTime(value) {
  if (!value) return "--";

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : dateTimeFormatter.format(date);
}

function formatWindowPoint(value) {
  if (!value) return "--";

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit"
  }).format(date);
}

function formatNumber(value) {
  return numberFormatter.format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function plural(value, singular, pluralLabel) {
  return `${formatNumber(value)} ${Number(value) === 1 ? singular : pluralLabel}`;
}

function destinationTypeLabel(type) {
  return type === "CLIENTE" ? "Cliente" : "Almacén";
}

function destinationClass(type) {
  return type === "CLIENTE" ? "" : "is-warehouse";
}

function setMessage(message, type = "") {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", type === "error");
  elements.message.classList.toggle("is-success", type === "success");
}

function setLoading(isLoading) {
  loading = isLoading;
  elements.submit.disabled = isLoading;
  elements.reset.disabled = isLoading;
  elements.returnCurrent.disabled = isLoading;
  elements.journey.disabled = isLoading;
  elements.provider.disabled = isLoading;

  if (isLoading) {
    elements.truck.disabled = true;
    elements.pagePrevious.disabled = true;
    elements.pageNext.disabled = true;
    setMessage("Cargando pesadas, camiones y destinos…");
    elements.truckRows.innerHTML = `
      <tr><td colspan="8" class="customer-history-empty-cell">Cargando resumen por camión…</td></tr>
    `;
    elements.detailRows.innerHTML = `
      <tr><td colspan="10" class="customer-history-empty-cell">Cargando detalle de pesadas…</td></tr>
    `;
    elements.destinationCards.innerHTML = `
      <article class="provider-destination-empty card">Cargando destinos…</article>
    `;
  } else {
    elements.truck.disabled = elements.truck.options.length <= 1;
    if (reportData) renderPagination(reportData.pagination);
  }
}

function optionLabelForJourney(journey, currentDate) {
  const current = journey.date === currentDate ? " · Actual" : "";
  const status = journey.status === "ABIERTA"
    ? " · Abierta"
    : journey.status === "CERRADA"
      ? " · Cerrada"
      : " · Sin movimientos";

  return `${formatJourneyDate(journey.date)}${current}${status}`;
}

function replaceOptions(select, options, selectedValue) {
  select.replaceChildren(...options);
  select.value = String(selectedValue ?? "");
}

function renderJourneyOptions(data) {
  const options = (data.catalog?.journeys || []).map((journey) => {
    const option = document.createElement("option");
    option.value = journey.date;
    option.textContent = optionLabelForJourney(journey, data.current_operating_date);
    return option;
  });

  replaceOptions(elements.journey, options, data.selected_operating_date);
}

function renderProviderOptions(data) {
  const selectedValue = String(data.applied_filters?.provider_id || "");
  const providers = data.catalog?.providers || [];
  const allOption = document.createElement("option");
  allOption.value = "";
  allOption.textContent = "Todos los proveedores";
  const options = [allOption, ...providers.map((provider) => {
    const option = document.createElement("option");
    option.value = String(provider.id);
    option.textContent = provider.document
      ? `${provider.name} · ${provider.document}`
      : provider.name;
    return option;
  })];

  if (selectedValue && !providers.some((provider) => String(provider.id) === selectedValue)) {
    const unavailableOption = document.createElement("option");
    unavailableOption.value = selectedValue;
    unavailableOption.textContent = "Proveedor no disponible en esta jornada";
    options.push(unavailableOption);
  }

  replaceOptions(elements.provider, options, selectedValue);
}

function renderTruckOptions(selectedValue = "") {
  const providerId = Number(elements.provider.value || 0);
  const trucks = (reportData?.catalog?.trucks || []).filter(
    (truck) => !providerId || Number(truck.provider_id) === providerId
  );
  const allOption = document.createElement("option");
  allOption.value = "";
  allOption.textContent = "Todos los camiones";
  const options = [allOption, ...trucks.map((truck) => {
    const option = document.createElement("option");
    option.value = truck.value;
    option.textContent = providerId
      ? truck.plate
      : `${truck.plate} · ${truck.provider_name}`;
    return option;
  })];

  if (selectedValue && !trucks.some((truck) => truck.value === selectedValue)) {
    const unavailableOption = document.createElement("option");
    unavailableOption.value = selectedValue;
    unavailableOption.textContent = "Camión no disponible en esta jornada";
    options.push(unavailableOption);
  }

  replaceOptions(elements.truck, options, selectedValue);
  elements.truck.disabled = loading || options.length <= 1;
  if (trucks.length === 0) {
    allOption.textContent = providerId
      ? "Este proveedor no tiene camiones en la jornada"
      : "No hay camiones en la jornada";
  }
}

function renderContext(data) {
  elements.currentTitle.textContent = formatJourneyDate(data.current_operating_date);
  elements.currentWindow.textContent = data.current_window
    ? `Desde ${formatWindowPoint(data.current_window.starts_at)} hasta ${formatWindowPoint(data.current_window.ends_at)} · corte ${data.current_window.cutoff}`
    : "Horario operativo no disponible.";
  elements.currentBranch.textContent = data.branch?.name || "--";
  elements.returnCurrent.hidden = data.is_current_journey;
  elements.selectedBadge.textContent = data.is_current_journey
    ? "Jornada actual"
    : `Consultando ${formatShortDate(data.selected_operating_date)}`;
  elements.selectedBadge.classList.toggle("is-historical", !data.is_current_journey);
}

function renderStats(summary = {}) {
  elements.statProviders.textContent = formatNumber(summary.providers);
  elements.statTrucks.textContent = formatNumber(summary.trucks);
  elements.statRecords.textContent = formatNumber(summary.records);
  elements.statTickets.textContent = plural(summary.tickets || 0, "ticket", "tickets");
  elements.statCages.textContent = formatNumber(summary.cages);
  elements.statBirds.textContent = formatNumber(summary.birds);
  elements.statNetWeight.textContent = formatWeight(summary.net_weight_kg);
  elements.statAverage.textContent = `${formatWeight(summary.average_weight_per_bird_kg)} por pollo`;
}

function destinationChips(destinations) {
  return (destinations || []).map((destination) => `
    <span class="provider-destination-chip ${destinationClass(destination.type)}" title="${escapeHtml(destinationTypeLabel(destination.type))}">
      ${escapeHtml(destination.name || "Sin destino")}
    </span>
  `).join("");
}

function renderTruckSummary(items = []) {
  elements.truckCount.textContent = plural(items.length, "camión", "camiones");

  if (!items.length) {
    elements.truckRows.innerHTML = `
      <tr><td colspan="8" class="customer-history-empty-cell">No hay camiones con pesadas para esta consulta.</td></tr>
    `;
    return;
  }

  elements.truckRows.innerHTML = items.map((item) => `
    <tr>
      <td data-label="Proveedor">
        <div class="provider-cell-stack">
          <strong>${escapeHtml(item.provider?.name || "Proveedor sin registrar")}</strong>
          <small>${plural(item.tickets || 0, "ticket", "tickets")}</small>
        </div>
      </td>
      <td data-label="Camión"><span class="provider-truck-plate">${escapeHtml(item.truck?.plate || "Sin placa")}</span></td>
      <td data-label="Pesadas">${formatNumber(item.records)}</td>
      <td data-label="Javas"><strong>${formatNumber(item.cages)}</strong></td>
      <td data-label="Pollos"><strong>${formatNumber(item.birds)}</strong></td>
      <td data-label="Peso neto"><strong>${formatWeight(item.net_weight_kg)}</strong></td>
      <td data-label="Promedio/pollo">${formatWeight(item.average_weight_per_bird_kg)}</td>
      <td data-label="Destinos"><div class="provider-destination-list">${destinationChips(item.destinations)}</div></td>
    </tr>
  `).join("");
}

function renderDestinations(items = []) {
  elements.destinationCount.textContent = plural(items.length, "destino", "destinos");

  if (!items.length) {
    elements.destinationCards.innerHTML = `
      <article class="provider-destination-empty card">No hay destinos asociados a las pesadas consultadas.</article>
    `;
    return;
  }

  elements.destinationCards.innerHTML = items.map((item) => {
    const destination = item.destination || {};
    const typeClass = destinationClass(destination.type);
    return `
      <article class="provider-destination-card ${typeClass} card">
        <div class="provider-destination-card-head">
          <h3 title="${escapeHtml(destination.name || "Sin destino")}">${escapeHtml(destination.name || "Sin destino")}</h3>
          <span class="provider-destination-type ${typeClass}">${escapeHtml(destinationTypeLabel(destination.type))}</span>
        </div>
        <div class="provider-destination-metrics">
          <div><span>Javas</span><strong>${formatNumber(item.cages)}</strong></div>
          <div><span>Pollos</span><strong>${formatNumber(item.birds)}</strong></div>
          <div><span>Peso neto</span><strong>${formatWeight(item.net_weight_kg)}</strong></div>
        </div>
        <div class="provider-destination-metrics">
          <div><span>Proveedores</span><strong>${formatNumber(item.providers)}</strong></div>
          <div><span>Camiones</span><strong>${formatNumber(item.trucks)}</strong></div>
          <div><span>Pesadas</span><strong>${formatNumber(item.records)}</strong></div>
        </div>
      </article>
    `;
  }).join("");
}

function renderDetails(records = []) {
  if (!records.length) {
    elements.detailRows.innerHTML = `
      <tr><td colspan="10" class="customer-history-empty-cell">No se encontraron pesadas de proveedores con estos filtros.</td></tr>
    `;
    return;
  }

  elements.detailRows.innerHTML = records.map((record) => {
    const destination = record.destination || {};
    const chickenMeta = [record.chicken_condition, record.chicken_sex]
      .filter(Boolean)
      .join(" · ");

    return `
      <tr>
        <td data-label="Hora / ticket">
          <div class="provider-cell-stack">
            <strong>${escapeHtml(formatDateTime(record.weighed_at))}</strong>
            <small class="provider-ticket-code">${escapeHtml(record.ticket?.code || "Sin ticket")} · Pesada #${formatNumber(record.number)}</small>
          </div>
        </td>
        <td data-label="Proveedor">
          <div class="provider-cell-stack">
            <strong>${escapeHtml(record.provider?.name || "Proveedor sin registrar")}</strong>
            <small>${escapeHtml(record.provider?.document || "Sin documento")}</small>
          </div>
        </td>
        <td data-label="Camión"><span class="provider-truck-plate">${escapeHtml(record.truck?.plate || "Sin placa")}</span></td>
        <td data-label="Destino">
          <div class="provider-cell-stack">
            <strong>${escapeHtml(destination.name || "Sin destino")}</strong>
            <small>${escapeHtml(destinationTypeLabel(destination.type))}</small>
          </div>
        </td>
        <td data-label="Pollo / java">
          <div class="provider-cell-stack">
            <strong>${escapeHtml(record.chicken_type?.name || "Sin tipo")}</strong>
            <small>${escapeHtml([chickenMeta, record.cage_type?.name].filter(Boolean).join(" · ") || "Sin detalle")}</small>
          </div>
        </td>
        <td data-label="Javas"><strong>${formatNumber(record.cages)}</strong></td>
        <td data-label="Pollos"><strong>${formatNumber(record.birds)}</strong></td>
        <td data-label="Peso bruto">${formatWeight(record.gross_weight_kg)}</td>
        <td data-label="Tara">${formatWeight(record.tare_weight_kg)}</td>
        <td data-label="Peso neto" class="provider-net-weight">${formatWeight(record.net_weight_kg)}</td>
      </tr>
    `;
  }).join("");
}

function renderPagination(pagination = {}) {
  const total = Number(pagination.total || 0);
  const lastPage = Number(pagination.last_page || 1);
  currentPage = Number(pagination.current_page || 1);
  elements.recordRange.textContent = total
    ? `${formatNumber(pagination.from)}–${formatNumber(pagination.to)} de ${formatNumber(total)} pesadas`
    : "0 pesadas";
  elements.pagination.hidden = lastPage <= 1;
  elements.pageStatus.textContent = `Página ${formatNumber(currentPage)} de ${formatNumber(lastPage)}`;
  elements.pagePrevious.disabled = currentPage <= 1 || loading;
  elements.pageNext.disabled = currentPage >= lastPage || loading;
}

function renderResultMessage(data) {
  const records = Number(data.summary?.records || 0);
  const hasFilters = Boolean(data.applied_filters?.provider_id || data.applied_filters?.truck);

  if (!records) {
    if (hasFilters) {
      setMessage("No se encontraron pesadas que coincidan con el proveedor y camión seleccionados.");
    } else if (data.is_current_journey) {
      setMessage("La jornada actual aún no tiene pesadas registradas de proveedores.");
    } else {
      setMessage("La jornada consultada no tiene pesadas registradas de proveedores.");
    }
    return;
  }

  const journeyLabel = data.is_current_journey
    ? "la jornada actual"
    : `la jornada del ${formatJourneyDate(data.selected_operating_date)}`;
  setMessage(
    `${plural(records, "pesada encontrada", "pesadas encontradas")} en ${journeyLabel}.`,
    "success"
  );
}

function renderReport(data) {
  reportData = data;
  renderJourneyOptions(data);
  renderProviderOptions(data);
  renderTruckOptions(data.applied_filters?.truck || "");
  renderContext(data);
  renderStats(data.summary);
  renderTruckSummary(data.summary?.by_truck || []);
  renderDestinations(data.summary?.by_destination || []);
  renderDetails(data.records || []);
  renderPagination(data.pagination);
  renderResultMessage(data);
}

function filtersFromForm() {
  return {
    jornada: elements.journey.value,
    proveedor_id: elements.provider.value,
    camion: elements.truck.value
  };
}

function initialFilters() {
  const params = new URLSearchParams(window.location.search);
  return {
    jornada: params.get("jornada") || "",
    proveedor_id: params.get("proveedor_id") || "",
    camion: params.get("camion") || ""
  };
}

function updateUrl(filters) {
  const params = new URLSearchParams();
  if (filters.jornada) params.set("jornada", filters.jornada);
  if (filters.proveedor_id) params.set("proveedor_id", filters.proveedor_id);
  if (filters.camion) params.set("camion", filters.camion);
  const query = params.toString();
  window.history.replaceState({}, "", `${window.location.pathname}${query ? `?${query}` : ""}`);
}

async function loadReport({ page = 1, filters = filtersFromForm() } = {}) {
  if (loading) return;

  setLoading(true);
  const params = new URLSearchParams({
    page: String(page),
    per_page: "30"
  });
  if (filters.jornada) params.set("jornada", filters.jornada);
  if (filters.proveedor_id) params.set("proveedor_id", filters.proveedor_id);
  if (filters.camion) params.set("camion", filters.camion);

  try {
    const response = await apiRequest(`/operacion/reporte-proveedores?${params.toString()}`);
    renderReport(response.data);
    updateUrl({
      jornada: response.data.selected_operating_date,
      proveedor_id: response.data.applied_filters?.provider_id || "",
      camion: response.data.applied_filters?.truck || ""
    });
  } catch (error) {
    console.error(error);
    setMessage(error.data?.message || error.message || "No se pudo cargar el reporte de proveedores.", "error");
    elements.truckRows.innerHTML = `
      <tr><td colspan="8" class="customer-history-empty-cell">No se pudo cargar el resumen. Intenta nuevamente.</td></tr>
    `;
    elements.detailRows.innerHTML = `
      <tr><td colspan="10" class="customer-history-empty-cell">No se pudo cargar el detalle. Intenta nuevamente.</td></tr>
    `;
    elements.destinationCards.innerHTML = `
      <article class="provider-destination-empty card">No se pudieron cargar los destinos.</article>
    `;
  } finally {
    setLoading(false);
  }
}

elements.form.addEventListener("submit", (event) => {
  event.preventDefault();
  loadReport({ page: 1 });
});

elements.provider.addEventListener("change", () => {
  renderTruckOptions("");
});

elements.journey.addEventListener("change", () => {
  const jornada = elements.journey.value;
  elements.provider.value = "";
  renderTruckOptions("");
  loadReport({
    page: 1,
    filters: { jornada, proveedor_id: "", camion: "" }
  });
});

elements.reset.addEventListener("click", () => {
  const currentDate = reportData?.current_operating_date || "";
  elements.journey.value = currentDate;
  elements.provider.value = "";
  renderTruckOptions("");
  loadReport({ page: 1 });
});

elements.returnCurrent.addEventListener("click", () => {
  elements.journey.value = reportData?.current_operating_date || "";
  elements.provider.value = "";
  renderTruckOptions("");
  loadReport({ page: 1 });
});

elements.pagePrevious.addEventListener("click", () => {
  if (currentPage > 1) loadReport({ page: currentPage - 1 });
});

elements.pageNext.addEventListener("click", () => {
  const lastPage = Number(reportData?.pagination?.last_page || 1);
  if (currentPage < lastPage) loadReport({ page: currentPage + 1 });
});

loadReport({ page: 1, filters: initialFilters() });
