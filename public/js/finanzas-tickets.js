import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  firstDefined,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  responseCollection,
  responseMeta,
  setMessage
} from "./finanzas-common.js";

const elements = {
  filters: document.getElementById("financeTicketFilters"),
  ticket: document.getElementById("financeTicketNumber"),
  client: document.getElementById("financeTicketClient"),
  clientCombobox: document.getElementById("financeTicketClientCombobox"),
  clientSuggestions: document.getElementById("financeTicketClientSuggestions"),
  from: document.getElementById("financeTicketFrom"),
  until: document.getElementById("financeTicketUntil"),
  clear: document.getElementById("financeTicketClear"),
  message: document.getElementById("financeTicketMessage"),
  rows: document.getElementById("financeTicketRows"),
  previous: document.getElementById("financeTicketPrevious"),
  next: document.getElementById("financeTicketNext"),
  page: document.getElementById("financeTicketPage"),
  bulkOpen: document.getElementById("financeTicketBulkOpen"),
  priceDialog: document.getElementById("financeTicketPriceDialog"),
  priceForm: document.getElementById("financeTicketPriceForm"),
  priceTitle: document.getElementById("financeTicketPriceTitle"),
  priceDescription: document.getElementById("financeTicketPriceDescription"),
  priceFields: document.getElementById("financeTicketPriceFields"),
  priceMessage: document.getElementById("financeTicketPriceMessage"),
  clientDialog: document.getElementById("financeTicketClientDialog"),
  clientForm: document.getElementById("financeTicketClientForm"),
  clientTitle: document.getElementById("financeTicketClientTitle"),
  clientDescription: document.getElementById("financeTicketClientDescription"),
  clientSearch: document.getElementById("financeTicketClientSearch"),
  clientOptions: document.getElementById("financeTicketClientOptions"),
  clientSelection: document.getElementById("financeTicketClientSelection"),
  clientMessage: document.getElementById("financeTicketClientMessage"),
  clientSave: document.getElementById("financeTicketClientSave"),
  bulkDialog: document.getElementById("financeTicketBulkDialog"),
  bulkForm: document.getElementById("financeTicketBulkForm"),
  bulkScope: document.getElementById("financeTicketBulkScope"),
  bulkTypes: document.getElementById("financeTicketBulkTypes"),
  bulkAmount: document.getElementById("financeTicketBulkAmount"),
  bulkMessage: document.getElementById("financeTicketBulkMessage")
};

const state = {
  page: 1,
  lastPage: 1,
  total: 0,
  timezone: null,
  records: new Map(),
  appliedFilters: null,
  priceTypes: [],
  clients: [],
  clientsLoaded: false,
  clientsPromise: null,
  filterClientId: null,
  filterClientMatches: [],
  filterClientActiveIndex: -1,
  filterClientOpenRequested: false,
  filterClientPendingMove: 0,
  filterClientRequestGeneration: 0,
  filterClientRequestController: null,
  filterClientDebounceTimer: null,
  filterClientLoading: false,
  editingPriceTicketId: null,
  editingClientTicketId: null,
  selectedClientId: null,
  selectedBulkTypeId: null,
  pendingFilters: null,
  pendingPage: 1,
  requestGeneration: 0,
  requestController: null,
  bulkIdempotencyKey: null,
  bulkAttemptFingerprint: null,
  bulkSaving: false,
  loading: false,
  saving: false
};

const canManage = document.body.dataset.canManageTickets === "1";
const FILTER_CLIENT_RESULT_LIMIT = 8;
const FILTER_CLIENT_DEBOUNCE_MS = 180;

function normalizeSearch(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es")
    .trim();
}

function currentFilters() {
  const filters = {
    ticket: elements.ticket.value.trim(),
    desde: elements.from.value,
    hasta: elements.until.value
  };

  if (state.filterClientId !== null) {
    filters.cliente_id = String(state.filterClientId);
  } else {
    filters.cliente = elements.client.value.trim();
  }

  return filters;
}

function activeFilterEntries(filters) {
  return Object.entries(filters).filter(([, value]) => String(value || "").trim() !== "");
}

function validateFilters(filters) {
  const hasRangeValue = Boolean(filters.desde || filters.hasta);
  if (hasRangeValue && (!filters.desde || !filters.hasta)) {
    return "Completa tanto la fecha y hora inicial como la final.";
  }
  if (filters.desde && filters.hasta && Date.parse(filters.hasta) < Date.parse(filters.desde)) {
    return "La fecha y hora final debe ser igual o posterior a la inicial.";
  }
  if (!filters.ticket && !filters.cliente && !filters.cliente_id && !hasRangeValue) {
    return "Debes filtrar por número de ticket, cliente o un rango de fecha y hora.";
  }
  return "";
}

function filtersEqual(first, second) {
  if (!first || !second) return false;
  return ["ticket", "cliente", "cliente_id", "desde", "hasta"]
    .every((key) => String(first[key] || "") === String(second[key] || ""));
}

function updateBulkAvailability() {
  elements.bulkOpen.disabled = !canManage
    || state.loading
    || state.saving
    || state.total < 1
    || state.priceTypes.length < 1
    || !filtersEqual(currentFilters(), state.appliedFilters);
}

function queryFor(filters, page) {
  const params = new URLSearchParams({ page: String(page) });
  activeFilterEntries(filters).forEach(([key, value]) => params.set(key, String(value)));
  return params;
}

function filterSnapshot(filters) {
  const snapshot = {
    ticket: String(filters?.ticket || "").trim(),
    desde: String(filters?.desde || ""),
    hasta: String(filters?.hasta || "")
  };

  const clientId = String(filters?.cliente_id || "").trim();
  if (clientId) {
    snapshot.cliente_id = clientId;
  } else {
    snapshot.cliente = String(filters?.cliente || "").trim();
  }

  return snapshot;
}

function invalidateTicketRequests({ clearPending = true } = {}) {
  state.requestGeneration += 1;
  state.requestController?.abort();
  state.requestController = null;
  state.loading = false;
  if (clearPending) {
    state.pendingFilters = null;
    state.pendingPage = 1;
  }
  updateBulkAvailability();
}

function requestWasCancelled(error, requestId, controller) {
  return requestId !== state.requestGeneration
    || controller.signal.aborted
    || error?.name === "AbortError";
}

function formatUnitPrice(value, currency = "PEN") {
  const number = Number(value || 0);
  try {
    return new Intl.NumberFormat("es-PE", {
      style: "currency",
      currency: currency || "PEN",
      minimumFractionDigits: 2,
      maximumFractionDigits: 4
    }).format(number);
  } catch {
    return `S/ ${number.toFixed(4)}`;
  }
}

function formatTicketDateTime(value) {
  if (!value) return "Sin fecha";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  try {
    return new Intl.DateTimeFormat("es-PE", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: state.timezone || undefined
    }).format(date);
  } catch {
    return formatDateTime(value);
  }
}

function operationLabel(record) {
  const operation = String(record.operation_type || "").toUpperCase();
  const channel = String(record.channel || "").toUpperCase();
  const operationText = operation === "DEVOLUCION" ? "Devolución" : "Despacho";
  const channelText = channel === "MINORISTA" ? "minorista" : "mayorista";
  return `${operationText} ${channelText}`;
}

function pricesHtml(record) {
  if (!record.prices?.length) {
    return '<span class="fin-management-muted">Sin precio asignado</span>';
  }

  return `<div class="fin-ticket-prices">${record.prices.map((price) => `
    <span class="fin-ticket-price-line">
      <strong>${escapeHtml(price.chicken_type?.name || "Tipo de pollo")}</strong>
      <small>${escapeHtml(formatUnitPrice(price.price_kg, record.currency))} / kg</small>
    </span>
  `).join("")}</div>`;
}

function actionsHtml(record) {
  if (!canManage) {
    return '<span class="fin-management-muted">Solo consulta</span>';
  }

  const priceButton = record.can_edit_prices
    ? `<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-prices="${record.id}">Editar precio</button>`
    : '<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" disabled>Sin precios</button>';
  const clientButton = record.can_change_client
    ? `<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-change-client="${record.id}">Cambiar cliente</button>`
    : '<button class="fin-btn fin-btn-primary fin-btn-small" type="button" disabled>Sin venta</button>';

  return `<div class="fin-management-actions fin-ticket-actions">${priceButton}${clientButton}</div>`;
}

function renderTickets(records) {
  state.records = new Map(records.map((record) => [String(record.id), record]));

  if (!records.length) {
    elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="6">No hay tickets que coincidan con los filtros.</td></tr>';
    return;
  }

  elements.rows.innerHTML = records.map((record) => `
    <tr>
      <td>
        <strong>${escapeHtml(record.code || `#${record.id}`)}</strong>
        <small>${escapeHtml(operationLabel(record))}</small>
      </td>
      <td>
        <strong>${escapeHtml(record.client?.name || "Sin cliente registrado")}</strong>
        <small>${escapeHtml(record.client?.document_number || "Sin documento")}</small>
      </td>
      <td>${pricesHtml(record)}</td>
      <td class="fin-text-right">
        <strong class="fin-table-amount ${Number(record.amount) < 0 ? "is-negative" : ""}">
          ${escapeHtml(formatMoney(record.amount, record.currency))}
        </strong>
      </td>
      <td>
        <strong>${escapeHtml(formatTicketDateTime(record.registered_at))}</strong>
        <small>${escapeHtml(record.status || "Sin estado")}</small>
      </td>
      <td>${actionsHtml(record)}</td>
    </tr>
  `).join("");
}

async function loadTickets(
  filters = state.appliedFilters,
  requestedPage = state.page,
  { loadingMessage = "Consultando tickets..." } = {}
) {
  if (!filters) return { ok: false, skipped: true };

  const filtersToApply = filterSnapshot(filters);
  const pageToLoad = Math.max(1, Number(requestedPage) || 1);
  state.requestController?.abort();

  const requestId = state.requestGeneration + 1;
  const controller = new AbortController();
  state.requestGeneration = requestId;
  state.requestController = controller;
  state.pendingFilters = { ...filtersToApply };
  state.pendingPage = pageToLoad;
  state.loading = true;
  updateBulkAvailability();
  setMessage(elements.message, loadingMessage);

  try {
    const response = await apiRequest(
      `/finanzas/tickets?${queryFor(filtersToApply, pageToLoad)}`,
      { signal: controller.signal }
    );
    if (requestId !== state.requestGeneration) {
      return { ok: false, stale: true };
    }

    const records = responseCollection(response, ["tickets", "items", "records"]);
    const meta = responseMeta(response);
    const lastPage = Math.max(1, Number(meta.last_page || 1));

    if (pageToLoad > lastPage) {
      return await loadTickets(filtersToApply, lastPage, { loadingMessage });
    }

    state.appliedFilters = { ...filtersToApply };
    state.page = Math.min(lastPage, Math.max(1, Number(meta.current_page || pageToLoad)));
    state.lastPage = lastPage;
    state.total = Number(meta.total ?? records.length);
    state.timezone = typeof meta.timezone === "string" && meta.timezone
      ? meta.timezone
      : state.timezone;
    state.priceTypes = Array.isArray(meta.price_types) ? meta.price_types : [];

    renderTickets(records);
    elements.page.textContent = `Página ${state.page} de ${state.lastPage} · máximo 30 por página`;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
    setMessage(
      elements.message,
      `${state.total} ticket${state.total === 1 ? "" : "s"} encontrado${state.total === 1 ? "" : "s"}.`
    );
    markFinanceAccessReady();

    return { ok: true, records, meta };
  } catch (error) {
    if (requestWasCancelled(error, requestId, controller)) {
      return { ok: false, aborted: true };
    }
    setMessage(elements.message, errorMessage(error, "No se pudieron consultar los tickets."), "error");
    return { ok: false, error };
  } finally {
    if (requestId === state.requestGeneration) {
      if (state.requestController === controller) state.requestController = null;
      state.loading = false;
      updateBulkAvailability();
    }
  }
}

async function refreshAfterMutation(successMessage) {
  const filters = state.appliedFilters ? { ...state.appliedFilters } : null;
  if (!filters) {
    setMessage(elements.message, successMessage, "success");
    return { ok: true, skipped: true };
  }

  const result = await loadTickets(filters, state.page, {
    loadingMessage: `${successMessage} Actualizando la lista...`
  });
  if (result.ok) {
    setMessage(elements.message, successMessage, "success");
  } else if (!result.aborted && !result.stale) {
    setMessage(
      elements.message,
      `${successMessage} Sin embargo, no se pudo actualizar la lista; vuelve a consultar los tickets.`,
      "error"
    );
  }
  return result;
}

function resetResults({ invalidateRequest = true } = {}) {
  if (invalidateRequest) invalidateTicketRequests();
  state.filterClientId = null;
  closeFilterClientSuggestions();
  state.page = 1;
  state.lastPage = 1;
  state.total = 0;
  state.timezone = null;
  state.records.clear();
  state.appliedFilters = null;
  state.priceTypes = [];
  elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="6">Los tickets aparecerán aquí después de aplicar un filtro.</td></tr>';
  elements.page.textContent = "Sin consulta";
  elements.previous.disabled = true;
  elements.next.disabled = true;
  setMessage(elements.message, "Aplica un filtro para mostrar los tickets.");
  updateBulkAvailability();
}

function openPriceDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!record?.prices?.length) return;

  state.editingPriceTicketId = record.id;
  elements.priceTitle.textContent = `Editar precios · ${record.code}`;
  elements.priceDescription.textContent = `Cliente: ${record.client?.name || "Sin cliente"}. El monto del ticket se recalculará al guardar.`;
  elements.priceFields.innerHTML = record.prices.map((price) => `
    <label class="fin-field fin-ticket-price-field">
      <span>${escapeHtml(price.chicken_type?.name || "Tipo de pollo")} <b>*</b></span>
      <span class="fin-ticket-price-input">
        <input
          type="number"
          min="0.0001"
          max="99999999.9999"
          step="0.0001"
          inputmode="decimal"
          value="${escapeHtml(price.price_kg)}"
          data-ticket-price-id="${price.id}"
          required
        >
        <small>por kg</small>
      </span>
    </label>
  `).join("");
  setMessage(elements.priceMessage, "");
  elements.priceDialog.showModal();
  elements.priceFields.querySelector("input")?.focus();
}

async function savePrices(event) {
  event.preventDefault();
  if (state.saving || !state.editingPriceTicketId) return;

  if (!elements.priceForm.checkValidity()) {
    elements.priceForm.reportValidity();
    setMessage(
      elements.priceMessage,
      "Revisa que todos los precios respeten el mínimo, máximo y cuatro decimales permitidos.",
      "error"
    );
    return;
  }

  const inputs = [...elements.priceFields.querySelectorAll("[data-ticket-price-id]")];
  const invalid = inputs.find((input) => {
    const value = Number(input.value);
    return !input.value || !Number.isFinite(value) || value <= 0;
  });
  if (invalid) {
    setMessage(elements.priceMessage, "Todos los precios deben ser mayores que cero.", "error");
    invalid.focus();
    return;
  }

  state.saving = true;
  setMessage(elements.priceMessage, "Guardando precios...");
  try {
    await apiRequest(`/finanzas/tickets/${encodeURIComponent(state.editingPriceTicketId)}/precios`, {
      method: "PUT",
      body: JSON.stringify({
        precios: inputs.map((input) => ({
          id: Number(input.dataset.ticketPriceId),
          precio_kg: input.value
        }))
      })
    });
    elements.priceDialog.close();
    state.editingPriceTicketId = null;
    await refreshAfterMutation("Precios actualizados y monto del ticket recalculado.");
  } catch (error) {
    setMessage(elements.priceMessage, errorMessage(error, "No se pudieron actualizar los precios."), "error");
  } finally {
    state.saving = false;
    updateBulkAvailability();
  }
}

async function loadClients() {
  if (state.clientsLoaded) return state.clients;

  if (!state.clientsPromise) {
    state.clientsPromise = apiRequest("/finanzas/catalogo")
      .then((response) => {
        state.clients = responseCollection(response, ["clientes", "catalogo.clientes"]);
        state.clientsLoaded = true;
        return state.clients;
      })
      .finally(() => {
        state.clientsPromise = null;
      });
  }

  return state.clientsPromise;
}

function clientLabel(client) {
  return String(firstDefined(client, ["nombre", "nombre_razon_social", "name"], "Cliente"));
}

function clientDocument(client) {
  return String(firstDefined(client, ["numero_documento", "document_number"], "") || "");
}

function clientStatus(client) {
  return String(firstDefined(client, ["estado", "status"], "ACTIVO")).toUpperCase();
}

function invalidateFilterClientLookup() {
  state.filterClientRequestGeneration += 1;
  if (state.filterClientDebounceTimer !== null) {
    window.clearTimeout(state.filterClientDebounceTimer);
    state.filterClientDebounceTimer = null;
  }
  state.filterClientRequestController?.abort();
  state.filterClientRequestController = null;
  state.filterClientLoading = false;
}

function closeFilterClientSuggestions() {
  invalidateFilterClientLookup();
  state.filterClientOpenRequested = false;
  state.filterClientActiveIndex = -1;
  state.filterClientPendingMove = 0;
  state.filterClientMatches = [];
  elements.clientSuggestions.hidden = true;
  elements.clientSuggestions.setAttribute("aria-busy", "false");
  elements.client.setAttribute("aria-expanded", "false");
  elements.client.removeAttribute("aria-activedescendant");
}

function filterClientRequestWasCancelled(error, requestId, controller) {
  return requestId !== state.filterClientRequestGeneration
    || controller.signal.aborted
    || error?.name === "AbortError";
}

function syncFilterClientActiveOption() {
  const options = [...elements.clientSuggestions.querySelectorAll("[data-filter-client-index]")];

  options.forEach((option, index) => {
    option.classList.toggle("is-active", index === state.filterClientActiveIndex);
  });

  const active = options[state.filterClientActiveIndex];
  if (active) {
    elements.client.setAttribute("aria-activedescendant", active.id);
    active.scrollIntoView({ block: "nearest" });
  } else {
    elements.client.removeAttribute("aria-activedescendant");
  }
}

function setFilterClientActiveIndex(index) {
  if (!state.filterClientMatches.length) {
    state.filterClientActiveIndex = -1;
  } else {
    state.filterClientActiveIndex = Math.max(
      0,
      Math.min(index, state.filterClientMatches.length - 1),
    );
  }
  syncFilterClientActiveOption();
}

function renderFilterClientSuggestions({ resetActive = true } = {}) {
  if (!state.filterClientOpenRequested) return;

  if (resetActive) state.filterClientActiveIndex = -1;
  if (state.filterClientActiveIndex >= state.filterClientMatches.length) {
    state.filterClientActiveIndex = -1;
  }

  elements.clientSuggestions.innerHTML = state.filterClientMatches.length
    ? state.filterClientMatches.map((client, index) => {
      const selected = String(client.id) === String(state.filterClientId);
      const inactiveBadge = clientStatus(client) === "ACTIVO"
        ? ""
        : '<span class="fin-ticket-client-suggestion-status">Inactivo</span>';
      return `
        <button
          id="financeTicketClientSuggestion${index}"
          class="fin-ticket-client-suggestion"
          type="button"
          role="option"
          tabindex="-1"
          data-filter-client-index="${index}"
          aria-selected="${selected}"
        >
          <strong>${escapeHtml(clientLabel(client))}</strong>
          <span class="fin-ticket-client-suggestion-meta">
            <small>${escapeHtml(clientDocument(client) || "Sin documento")}</small>
            ${inactiveBadge}
          </span>
        </button>
      `;
    }).join("")
    : '<span class="fin-ticket-client-suggestion-empty" role="status">No hay clientes que coincidan.</span>';

  elements.clientSuggestions.hidden = false;
  elements.clientSuggestions.setAttribute("aria-busy", "false");
  elements.client.setAttribute("aria-expanded", "true");

  if (state.filterClientPendingMove !== 0 && state.filterClientMatches.length) {
    const pendingMove = state.filterClientPendingMove;
    state.filterClientPendingMove = 0;
    setFilterClientActiveIndex(pendingMove > 0 ? 0 : state.filterClientMatches.length - 1);
    return;
  }

  state.filterClientPendingMove = 0;
  syncFilterClientActiveOption();
}

function renderFilterClientLoading(message = "Buscando clientes...") {
  elements.clientSuggestions.innerHTML = `<span class="fin-ticket-client-suggestion-empty" role="status">${escapeHtml(message)}</span>`;
  elements.clientSuggestions.hidden = false;
  elements.clientSuggestions.setAttribute("aria-busy", "true");
  elements.client.setAttribute("aria-expanded", "true");
  elements.client.removeAttribute("aria-activedescendant");
}

async function fetchFilterClientSuggestions(query, requestId, resetActive) {
  if (
    requestId !== state.filterClientRequestGeneration
    || !state.filterClientOpenRequested
  ) return;

  const controller = new AbortController();
  state.filterClientRequestController = controller;
  try {
    const params = new URLSearchParams({ buscar: query });
    const response = await apiRequest(`/finanzas/tickets/clientes?${params}`, {
      signal: controller.signal
    });
    if (
      requestId === state.filterClientRequestGeneration
      && state.filterClientOpenRequested
      && document.activeElement === elements.client
    ) {
      state.filterClientMatches = responseCollection(
        response,
        ["clientes", "items", "records"],
      )
        .filter((client) => client?.id !== undefined && client?.id !== null)
        .slice(0, FILTER_CLIENT_RESULT_LIMIT);
      state.filterClientLoading = false;
      renderFilterClientSuggestions({ resetActive });
    }
  } catch (error) {
    if (filterClientRequestWasCancelled(error, requestId, controller)) return;
    if (
      requestId !== state.filterClientRequestGeneration
      || !state.filterClientOpenRequested
    ) return;
    state.filterClientMatches = [];
    state.filterClientActiveIndex = -1;
    elements.clientSuggestions.innerHTML = '<span class="fin-ticket-client-suggestion-empty is-error" role="status">No se pudieron buscar los clientes. Intenta nuevamente.</span>';
    elements.clientSuggestions.setAttribute("aria-busy", "false");
  } finally {
    if (
      requestId === state.filterClientRequestGeneration
      && state.filterClientRequestController === controller
    ) {
      state.filterClientRequestController = null;
      state.filterClientLoading = false;
    }
  }
}

function openFilterClientSuggestions({
  resetActive = true,
  debounce = false
} = {}) {
  invalidateFilterClientLookup();
  const requestId = state.filterClientRequestGeneration;
  const query = elements.client.value.trim();
  state.filterClientOpenRequested = true;
  state.filterClientLoading = true;
  state.filterClientMatches = [];
  if (resetActive) state.filterClientActiveIndex = -1;

  renderFilterClientLoading(debounce ? "Buscando clientes..." : "Cargando clientes...");

  if (debounce) {
    state.filterClientDebounceTimer = window.setTimeout(() => {
      state.filterClientDebounceTimer = null;
      void fetchFilterClientSuggestions(query, requestId, resetActive);
    }, FILTER_CLIENT_DEBOUNCE_MS);
    return;
  }

  void fetchFilterClientSuggestions(query, requestId, resetActive);
}

function selectFilterClient(index) {
  const client = state.filterClientMatches[index];
  if (!client) return;

  state.filterClientId = String(client.id);
  elements.client.value = clientLabel(client);
  closeFilterClientSuggestions();
  updateBulkAvailability();
}

function selectedClient() {
  return state.clients.find((client) => String(client.id) === String(state.selectedClientId));
}

function renderClientOptions() {
  const query = normalizeSearch(elements.clientSearch.value);
  const clients = state.clients.filter((client) => {
    const haystack = normalizeSearch(`${clientLabel(client)} ${clientDocument(client)}`);
    return !query || haystack.includes(query);
  });

  elements.clientOptions.innerHTML = clients.length
    ? clients.map((client) => {
      const selected = String(client.id) === String(state.selectedClientId);
      return `
        <label
          class="fin-ticket-client-option ${selected ? "is-selected" : ""}"
        >
          <input
            class="fin-sr-only"
            type="radio"
            name="finance-ticket-client-choice"
            value="${escapeHtml(client.id)}"
            data-client-id="${escapeHtml(client.id)}"
            ${selected ? "checked" : ""}
          >
          <strong>${escapeHtml(clientLabel(client))}</strong>
          <span>${escapeHtml(clientDocument(client) || "Sin documento")}</span>
        </label>
      `;
    }).join("")
    : '<p class="fin-ticket-option-empty">No hay clientes que coincidan con la búsqueda.</p>';
}

function syncClientOptionState() {
  elements.clientOptions.querySelectorAll("[data-client-id]").forEach((input) => {
    const selected = String(input.dataset.clientId) === String(state.selectedClientId);
    input.checked = selected;
    input.closest(".fin-ticket-client-option")?.classList.toggle("is-selected", selected);
  });
}

function updateClientSelection() {
  const client = selectedClient();
  elements.clientSelection.textContent = client
    ? `Seleccionado: ${clientLabel(client)} · ${clientDocument(client) || "sin documento"}`
    : "Selecciona un cliente.";
  elements.clientSave.disabled = !client || state.saving;
}

async function openClientDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!record?.can_change_client) return;

  state.editingClientTicketId = record.id;
  state.selectedClientId = record.client?.id || null;
  elements.clientTitle.textContent = `Cambiar cliente · ${record.code}`;
  elements.clientDescription.textContent = `Cliente actual: ${record.client?.name || "Sin cliente registrado"}.`;
  elements.clientSearch.value = "";
  elements.clientOptions.innerHTML = '<p class="fin-ticket-option-empty">Cargando clientes...</p>';
  setMessage(elements.clientMessage, "");
  updateClientSelection();
  elements.clientDialog.showModal();

  try {
    await loadClients();
    renderClientOptions();
    updateClientSelection();
    elements.clientSearch.focus();
  } catch (error) {
    setMessage(elements.clientMessage, errorMessage(error, "No se pudieron cargar los clientes."), "error");
  }
}

async function saveClient(event) {
  event.preventDefault();
  if (state.saving || !state.editingClientTicketId || !state.selectedClientId) return;

  state.saving = true;
  updateClientSelection();
  setMessage(elements.clientMessage, "Guardando cliente...");
  try {
    await apiRequest(`/finanzas/tickets/${encodeURIComponent(state.editingClientTicketId)}/cliente`, {
      method: "PUT",
      body: JSON.stringify({ cliente_id: Number(state.selectedClientId) })
    });
    elements.clientDialog.close();
    state.editingClientTicketId = null;
    state.selectedClientId = null;
    await refreshAfterMutation("Cliente del ticket actualizado correctamente.");
  } catch (error) {
    setMessage(elements.clientMessage, errorMessage(error, "No se pudo cambiar el cliente."), "error");
  } finally {
    state.saving = false;
    updateClientSelection();
    updateBulkAvailability();
  }
}

function renderBulkTypes() {
  elements.bulkTypes.innerHTML = state.priceTypes.map((type) => {
    const selected = String(type.id) === String(state.selectedBulkTypeId);
    return `
      <label
        class="fin-ticket-type-option ${selected ? "is-selected" : ""}"
      >
        <input
          class="fin-sr-only"
          type="radio"
          name="finance-ticket-bulk-type"
          value="${escapeHtml(type.id)}"
          data-bulk-type-id="${escapeHtml(type.id)}"
          ${selected ? "checked" : ""}
        >
        <strong>${escapeHtml(type.name)}</strong>
        <small>${Number(type.ticket_count)} ticket${Number(type.ticket_count) === 1 ? "" : "s"}</small>
      </label>
    `;
  }).join("");
}

function syncBulkTypeSelection() {
  elements.bulkTypes.querySelectorAll("[data-bulk-type-id]").forEach((input) => {
    const selected = String(input.dataset.bulkTypeId) === String(state.selectedBulkTypeId);
    input.checked = selected;
    input.closest(".fin-ticket-type-option")?.classList.toggle("is-selected", selected);
  });
}

function setBulkSaving(isSaving) {
  state.bulkSaving = isSaving;
  elements.bulkForm.setAttribute("aria-busy", String(isSaving));
  elements.bulkAmount.disabled = isSaving;
  elements.bulkForm.querySelectorAll(
    "[data-bulk-type-id], [data-bulk-operation], [data-bulk-dialog-close]"
  ).forEach((control) => {
    control.disabled = isSaving;
  });
}

function bulkAttemptFingerprint(operation, amount) {
  return JSON.stringify({
    filters: filterSnapshot(state.appliedFilters),
    tipo_pollo_id: Number(state.selectedBulkTypeId),
    monto: String(amount),
    operacion: operation
  });
}

function idempotencyKeyForBulkAttempt(fingerprint) {
  if (
    !state.bulkIdempotencyKey
    || (state.bulkAttemptFingerprint && state.bulkAttemptFingerprint !== fingerprint)
  ) {
    state.bulkIdempotencyKey = createIdempotencyKey();
  }
  state.bulkAttemptFingerprint = fingerprint;
  return state.bulkIdempotencyKey;
}

function openBulkDialog() {
  if (elements.bulkOpen.disabled || !state.appliedFilters) return;

  state.selectedBulkTypeId = null;
  elements.bulkAmount.value = "";
  elements.bulkScope.textContent = `El filtro actual contiene ${state.total} ticket${state.total === 1 ? "" : "s"} en ${state.lastPage} página${state.lastPage === 1 ? "" : "s"}.`;
  setMessage(elements.bulkMessage, "");
  renderBulkTypes();
  elements.bulkDialog.showModal();
  window.requestAnimationFrame(() => {
    elements.bulkTypes.querySelector("[data-bulk-type-id]")?.focus();
  });
}

async function adjustBulkPrices(operation) {
  if (state.saving || !state.appliedFilters) return;
  if (!["AUMENTAR", "DISMINUIR"].includes(operation)) return;
  if (!state.selectedBulkTypeId) {
    setMessage(elements.bulkMessage, "Selecciona primero el tipo de pollo.", "error");
    return;
  }

  const amount = elements.bulkAmount.value;
  const numericAmount = Number(amount);
  if (
    !elements.bulkAmount.checkValidity()
    || !amount
    || !Number.isFinite(numericAmount)
    || numericAmount <= 0
  ) {
    elements.bulkAmount.reportValidity();
    setMessage(
      elements.bulkMessage,
      "Ingresa un monto válido, mayor que cero y con hasta cuatro decimales.",
      "error"
    );
    elements.bulkAmount.focus();
    return;
  }
  const normalizedAmount = numericAmount.toFixed(4);

  const type = state.priceTypes.find((item) => String(item.id) === String(state.selectedBulkTypeId));
  const verb = operation === "AUMENTAR" ? "Aumentando" : "Disminuyendo";
  const fingerprint = bulkAttemptFingerprint(operation, normalizedAmount);
  const idempotencyKey = idempotencyKeyForBulkAttempt(fingerprint);
  state.saving = true;
  setBulkSaving(true);
  updateBulkAvailability();
  setMessage(elements.bulkMessage, `${verb} el precio de ${type?.name || "ese tipo"}...`);

  try {
    const response = await apiRequest("/finanzas/tickets/ajustar-precios", {
      method: "POST",
      body: JSON.stringify({
        ...state.appliedFilters,
        tipo_pollo_id: Number(state.selectedBulkTypeId),
        monto: normalizedAmount,
        operacion: operation,
        idempotency_key: idempotencyKey
      })
    });
    const result = response?.data || {};
    state.bulkIdempotencyKey = null;
    state.bulkAttemptFingerprint = null;
    elements.bulkDialog.close();
    const skipped = Number(result.tickets_without_type || 0);
    const suffix = skipped > 0
      ? ` ${skipped} ticket${skipped === 1 ? "" : "s"} no tenía${skipped === 1 ? "" : "n"} ese tipo y no se modificó${skipped === 1 ? "" : "n"}.`
      : "";
    await refreshAfterMutation(
      `${Number(result.updated_tickets || 0)} ticket${Number(result.updated_tickets) === 1 ? "" : "s"} actualizado${Number(result.updated_tickets) === 1 ? "" : "s"} en todas las páginas.${suffix}`,
    );
  } catch (error) {
    setMessage(elements.bulkMessage, errorMessage(error, "No se pudo aplicar el ajuste masivo."), "error");
  } finally {
    state.saving = false;
    setBulkSaving(false);
    updateBulkAvailability();
  }
}

elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  closeFilterClientSuggestions();
  const filters = currentFilters();
  const validationMessage = validateFilters(filters);
  if (validationMessage) {
    setMessage(elements.message, validationMessage, "error");
    return;
  }
  void loadTickets(filters, 1);
});

elements.filters.addEventListener("input", updateBulkAvailability);
elements.client.addEventListener("focus", () => {
  void openFilterClientSuggestions();
});
elements.client.addEventListener("input", () => {
  state.filterClientId = null;
  state.filterClientPendingMove = 0;
  openFilterClientSuggestions({ debounce: true });
});
elements.client.addEventListener("keydown", (event) => {
  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();
    const direction = event.key === "ArrowDown" ? 1 : -1;

    if (elements.clientSuggestions.hidden) {
      state.filterClientPendingMove = direction;
      openFilterClientSuggestions({ resetActive: true });
      return;
    }

    if (!state.filterClientMatches.length) {
      state.filterClientPendingMove = direction;
      if (!state.filterClientLoading) {
        openFilterClientSuggestions({ resetActive: true });
      }
      return;
    }

    const nextIndex = state.filterClientActiveIndex < 0
      ? (direction > 0 ? 0 : state.filterClientMatches.length - 1)
      : state.filterClientActiveIndex + direction;
    setFilterClientActiveIndex(nextIndex);
    return;
  }

  if (event.key === "Enter" && state.filterClientActiveIndex >= 0) {
    event.preventDefault();
    selectFilterClient(state.filterClientActiveIndex);
    return;
  }

  if (event.key === "Escape" && !elements.clientSuggestions.hidden) {
    event.preventDefault();
    closeFilterClientSuggestions();
  }
});
elements.client.addEventListener("blur", () => {
  window.setTimeout(() => {
    if (!elements.clientCombobox.contains(document.activeElement)) {
      closeFilterClientSuggestions();
    }
  }, 0);
});
elements.clientSuggestions.addEventListener("pointerdown", (event) => {
  if (event.target.closest("[data-filter-client-index]")) event.preventDefault();
});
elements.clientSuggestions.addEventListener("pointermove", (event) => {
  const option = event.target.closest("[data-filter-client-index]");
  if (!option) return;
  setFilterClientActiveIndex(Number(option.dataset.filterClientIndex));
});
elements.clientSuggestions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-filter-client-index]");
  if (!option) return;
  selectFilterClient(Number(option.dataset.filterClientIndex));
});
document.addEventListener("pointerdown", (event) => {
  if (!elements.clientCombobox.contains(event.target)) closeFilterClientSuggestions();
});
elements.clear.addEventListener("click", () => {
  elements.filters.reset();
  resetResults();
});
elements.previous.addEventListener("click", () => {
  if (!state.appliedFilters || state.page <= 1) return;
  void loadTickets(state.appliedFilters, state.page - 1);
});
elements.next.addEventListener("click", () => {
  if (!state.appliedFilters || state.page >= state.lastPage) return;
  void loadTickets(state.appliedFilters, state.page + 1);
});
elements.rows.addEventListener("click", (event) => {
  const editPrices = event.target.closest("[data-edit-prices]");
  const changeClient = event.target.closest("[data-change-client]");
  if (editPrices) openPriceDialog(editPrices.dataset.editPrices);
  if (changeClient) void openClientDialog(changeClient.dataset.changeClient);
});
elements.priceForm.addEventListener("submit", savePrices);
elements.clientForm.addEventListener("submit", saveClient);
elements.clientSearch.addEventListener("input", renderClientOptions);
elements.clientOptions.addEventListener("change", (event) => {
  const option = event.target.closest("[data-client-id]");
  if (!option) return;
  state.selectedClientId = Number(option.dataset.clientId);
  syncClientOptionState();
  updateClientSelection();
});
elements.bulkOpen.addEventListener("click", openBulkDialog);
elements.bulkTypes.addEventListener("change", (event) => {
  const option = event.target.closest("[data-bulk-type-id]");
  if (!option) return;
  state.selectedBulkTypeId = Number(option.dataset.bulkTypeId);
  syncBulkTypeSelection();
  setMessage(elements.bulkMessage, "");
});
elements.bulkForm.querySelectorAll("[data-bulk-operation]").forEach((button) => {
  button.addEventListener("click", () => void adjustBulkPrices(button.dataset.bulkOperation));
});
elements.bulkForm.addEventListener("submit", (event) => event.preventDefault());
document.querySelectorAll("[data-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => {
    const dialog = button.closest("dialog");
    if (dialog === elements.bulkDialog && state.bulkSaving) {
      setMessage(elements.bulkMessage, "Espera a que termine el ajuste antes de cerrar.", "error");
      return;
    }
    dialog?.close();
  });
});
elements.bulkDialog.addEventListener("cancel", (event) => {
  if (!state.bulkSaving) return;
  event.preventDefault();
  setMessage(elements.bulkMessage, "Espera a que termine el ajuste antes de cerrar.", "error");
});

initFinanceAccess(() => {
  const filters = state.pendingFilters || state.appliedFilters;
  const page = state.pendingFilters ? state.pendingPage : state.page;
  return filters ? loadTickets(filters, page) : undefined;
});
resetResults();
