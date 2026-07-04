import { apiRequest } from "./api-client.js";

const page = document.body.dataset.javaPage || "dashboard";
const byId = (id) => document.getElementById(id);
const elements = {
  totalPending: byId("javaTotalPending"),
  clientsPending: byId("javaClientsPending"),
  receivedToday: byId("javaReceivedToday"),
  pendingSummary: byId("javaPendingSummary"),
  journey: byId("javaJourneyFilter"),
  journeyTitle: byId("javaJourneyTitle"),
  journeyWindow: byId("javaJourneyWindow"),
  journeyDispatched: byId("javaJourneyDispatched"),
  journeyReceived: byId("javaJourneyReceived"),
  journeyNet: byId("javaJourneyNet"),
  journeyTrucks: byId("javaJourneyTrucks"),
  companyTotal: byId("javaCompanyTotal"),
  companyInside: byId("javaCompanyInside"),
  companyOutside: byId("javaCompanyOutside"),
  inventoryStatus: byId("javaInventoryStatus"),
  inventoryOpen: byId("javaInventoryOpen"),
  inventoryModal: byId("javaInventoryModal"),
  inventoryClose: byId("javaInventoryClose"),
  inventoryCancel: byId("javaInventoryCancel"),
  inventoryForm: byId("javaInventoryForm"),
  inventoryQuantity: byId("javaInventoryQuantity"),
  inventoryOutsideHint: byId("javaInventoryOutsideHint"),
  inventorySubmit: byId("javaInventorySubmit"),
  inventoryMessage: byId("javaInventoryMessage"),
  dailyOpen: byId("javaDailyOpen"),
  dailyStatus: byId("javaDailyStatus"),
  dailyQuantity: byId("javaDailyQuantity"),
  dailyExpected: byId("javaDailyExpected"),
  dailyDifference: byId("javaDailyDifference"),
  dailyDifferenceLabel: byId("javaDailyDifferenceLabel"),
  dailyModal: byId("javaDailyModal"),
  dailyClose: byId("javaDailyClose"),
  dailyCancel: byId("javaDailyCancel"),
  dailyForm: byId("javaDailyForm"),
  dailyCountQuantity: byId("javaDailyCountQuantity"),
  dailyExpectedHint: byId("javaDailyExpectedHint"),
  dailySubmit: byId("javaDailySubmit"),
  dailyMessage: byId("javaDailyMessage"),
  search: byId("javaClientSearch"),
  clientRows: byId("javaClientRows"),
  clientPagination: byId("javaClientPagination"),
  balanceMessage: byId("javaBalanceMessage"),
  form: byId("javaReceiptForm"),
  client: byId("javaReceiptClient"),
  truck: byId("javaReceiptTruck"),
  driver: byId("javaReceiptDriver"),
  quantity: byId("javaReceiptQuantity"),
  observations: byId("javaReceiptObservations"),
  balanceHint: byId("javaClientBalanceHint"),
  submit: byId("javaReceiptSubmit"),
  receiptMessage: byId("javaReceiptMessage"),
  historyClient: byId("javaHistoryClient"),
  truckActivityRows: byId("javaTruckActivityRows"),
  movementRows: byId("javaMovementRows"),
  historyMessage: byId("javaHistoryMessage")
};

const state = {
  clients: [],
  clientOptions: [],
  inventory: null,
  journeys: [],
  truckActivity: [],
  movements: [],
  pagination: {
    current_page: 1,
    last_page: 1,
    per_page: 12,
    total: 0,
    from: null,
    to: null
  },
  requestId: 0
};

let searchTimer;

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function errorMessage(error) {
  const firstValidationError = Object.values(error?.data?.errors || {}).flat()[0];
  return firstValidationError || error?.message || "No se pudo completar la operación.";
}

function setMessage(element, message = "", isError = false) {
  if (!element) return;
  element.textContent = message;
  element.classList.toggle("is-error", isError);
  element.classList.toggle("is-success", Boolean(message) && !isError);
}

function setText(element, value) {
  if (element) element.textContent = value;
}

function formatDate(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("es-CO", {
    dateStyle: "short",
    timeStyle: "short"
  }).format(new Date(value));
}

function formatOperatingDate(value) {
  if (!value) return "Sin fecha";
  return new Intl.DateTimeFormat("es-CO", {
    dateStyle: "full",
    timeZone: "UTC"
  }).format(new Date(`${value}T00:00:00Z`));
}

function renderSummary(data) {
  const summary = data.summary || {};
  const traceSummary = page === "traceability" ? summary : (data.current_summary || {});

  setText(elements.totalPending, summary.total_pending ?? 0);
  setText(elements.clientsPending, summary.clients_with_balance ?? 0);
  setText(elements.receivedToday, summary.received_today ?? 0);
  setText(
    elements.pendingSummary,
    Number(summary.clients_with_balance ?? 0) > 0
      ? `${summary.total_pending ?? 0} javas por recuperar`
      : "Sin saldos pendientes"
  );
  setText(elements.journeyDispatched, traceSummary.dispatched ?? 0);
  setText(elements.journeyReceived, traceSummary.received ?? 0);
  setText(elements.journeyNet, traceSummary.net ?? 0);
  setText(elements.journeyTrucks, traceSummary.trucks_count ?? 0);

  if (elements.journeyNet) {
    elements.journeyNet.classList.toggle("has-balance", Number(traceSummary.net ?? 0) > 0);
    elements.journeyNet.classList.toggle("is-clear", Number(traceSummary.net ?? 0) <= 0);
  }
}

function renderJourneys(selectedJourneyId) {
  if (!elements.journey) return;

  if (!state.journeys.length) {
    elements.journey.innerHTML = '<option value="">Sin jornadas registradas</option>';
    elements.journey.disabled = true;
    setText(elements.journeyTitle, "Sin jornada operativa");
    setText(elements.journeyWindow, "Los movimientos aparecerán cuando exista una jornada operativa.");
    return;
  }

  elements.journey.disabled = false;
  elements.journey.innerHTML = state.journeys.map((journey) => `
    <option value="${journey.id}">${escapeHtml(formatOperatingDate(journey.operating_date))} · ${escapeHtml(journey.status)}</option>
  `).join("");
  elements.journey.value = String(selectedJourneyId || state.journeys[0].id);
  const selected = state.journeys.find((journey) => Number(journey.id) === Number(elements.journey.value));
  setText(elements.journeyTitle, selected
    ? `Jornada del ${formatOperatingDate(selected.operating_date)}`
    : "Jornada seleccionada");
  setText(elements.journeyWindow, selected
    ? `${formatDate(selected.starts_at)} a ${formatDate(selected.ends_at)} · Estado: ${selected.status}`
    : "Selecciona la jornada que deseas consultar.");
}

function renderInventory() {
  const inventory = state.inventory || {};
  const outside = Number(inventory.outside ?? 0);
  setText(elements.companyOutside, outside);
  setText(elements.inventoryOutsideHint, outside);

  if (!inventory.configured) {
    setText(elements.companyTotal, "—");
    setText(elements.companyInside, "—");
    elements.companyInside?.classList.remove("is-negative-stock");
    setText(elements.inventoryStatus, "Total general pendiente de configurar");
    return;
  }

  const inside = Number(inventory.inside ?? 0);
  setText(elements.companyTotal, inventory.total ?? 0);
  setText(elements.companyInside, inside);
  elements.companyInside?.classList.toggle("is-negative-stock", inside < 0);
  setText(elements.inventoryStatus, inside < 0
    ? "Saldo negativo: revisa el total general o los movimientos."
    : page === "dashboard"
      ? `${inventory.total ?? 0} javas en total`
      : `Actualizado ${formatDate(inventory.updated_at)}. El disponible descuenta las javas que están fuera.`);
}

function renderDailyCount() {
  if (!elements.dailyOpen) return;
  const inventory = state.inventory || {};
  const daily = inventory.daily_count || {};
  elements.dailyOpen.disabled = !inventory.configured;

  if (!inventory.configured) {
    setText(elements.dailyQuantity, "—");
    setText(elements.dailyExpected, "Esperadas: —");
    setText(elements.dailyDifference, "—");
    setText(elements.dailyDifferenceLabel, "Primero define el total general");
    setText(elements.dailyStatus, "El conteo se habilitará después de configurar el inventario general.");
    return;
  }

  setText(elements.dailyExpectedHint, inventory.inside ?? 0);
  if (!daily.configured) {
    setText(elements.dailyQuantity, "—");
    setText(elements.dailyExpected, `Esperadas: ${inventory.inside ?? 0}`);
    setText(elements.dailyDifference, "—");
    elements.dailyDifference?.classList.remove("is-negative-stock", "is-positive-stock");
    setText(elements.dailyDifferenceLabel, "Sin conteo para esta jornada");
    setText(elements.dailyStatus, "Aún no se ha registrado el conteo físico de la jornada actual.");
    return;
  }

  const difference = Number(daily.difference ?? 0);
  setText(elements.dailyQuantity, daily.quantity ?? 0);
  setText(elements.dailyExpected, `Esperadas al contar: ${daily.expected ?? 0}`);
  setText(elements.dailyDifference, difference > 0 ? `+${difference}` : String(difference));
  elements.dailyDifference?.classList.toggle("is-negative-stock", difference !== 0);
  elements.dailyDifference?.classList.toggle("is-positive-stock", difference === 0);
  setText(elements.dailyDifferenceLabel, difference < 0
    ? `${Math.abs(difference)} javas faltantes`
    : difference > 0
      ? `${difference} javas sobrantes`
      : "Sin diferencias");
  setText(elements.dailyStatus, `Último conteo: ${formatDate(daily.counted_at)}.`);
}

function openInventoryModal() {
  const currentTotal = state.inventory?.configured ? Number(state.inventory.total) : null;
  elements.inventoryQuantity.value = Number.isInteger(currentTotal) && currentTotal >= 0 ? String(currentTotal) : "";
  setMessage(elements.inventoryMessage);
  elements.inventoryModal.hidden = false;
  document.body.classList.add("java-inventory-modal-open");
  elements.inventoryQuantity.focus();
}

function closeInventoryModal() {
  elements.inventoryModal.hidden = true;
  document.body.classList.remove("java-inventory-modal-open");
  elements.inventoryOpen.focus();
}

function openDailyModal() {
  const daily = state.inventory?.daily_count || {};
  elements.dailyCountQuantity.value = daily.configured ? String(daily.quantity) : "";
  setText(elements.dailyExpectedHint, state.inventory?.inside ?? 0);
  setMessage(elements.dailyMessage);
  elements.dailyModal.hidden = false;
  document.body.classList.add("java-inventory-modal-open");
  elements.dailyCountQuantity.focus();
}

function closeDailyModal() {
  elements.dailyModal.hidden = true;
  document.body.classList.remove("java-inventory-modal-open");
  elements.dailyOpen.focus();
}

function selectedClient() {
  return state.clientOptions.find((client) => Number(client.id) === Number(elements.client?.value));
}

function renderClientOptions() {
  const receiptValue = elements.client?.value || "";
  const historyValue = elements.historyClient?.value || "";

  if (elements.client) {
    const pendingClients = state.clientOptions.filter((client) => Number(client.balance) > 0);
    elements.client.innerHTML = `<option value="">Seleccionar cliente</option>${pendingClients.map((client) => `
      <option value="${client.id}">${escapeHtml(client.name)} — ${client.balance} por devolver</option>
    `).join("")}`;
    elements.client.value = receiptValue;
    updateBalanceHint();
  }

  if (elements.historyClient) {
    elements.historyClient.innerHTML = `<option value="">Todos los clientes</option>${state.clientOptions.map((client) => `
      <option value="${client.id}">${escapeHtml(client.name)}</option>
    `).join("")}`;
    elements.historyClient.value = historyValue;
  }
}

function renderFleetOptions(trucks, drivers) {
  if (elements.truck) {
    elements.truck.innerHTML = trucks.length
      ? `<option value="">Seleccionar camión</option>${trucks.map((truck) => `<option value="${truck.id}">${escapeHtml(truck.plate)}</option>`).join("")}`
      : '<option value="">No hay camiones propios activos</option>';
  }
  if (elements.driver) {
    elements.driver.innerHTML = drivers.length
      ? `<option value="">Seleccionar chofer</option>${drivers.map((driver) => `<option value="${driver.id}">${escapeHtml(driver.name)}</option>`).join("")}`
      : '<option value="">No hay choferes activos</option>';
  }
}

function renderClients() {
  if (!elements.clientRows) return;
  if (!state.clients.length) {
    elements.clientRows.innerHTML = '<tr><td colspan="4" class="java-empty-cell">No hay clientes que coincidan con la búsqueda.</td></tr>';
    return;
  }

  elements.clientRows.innerHTML = state.clients.map((client) => {
    const hasBalance = Number(client.balance) > 0;
    return `
      <tr>
        <td data-label="Cliente"><strong>${escapeHtml(client.name)}</strong></td>
        <td data-label="Documento">${escapeHtml(client.document_number || "Sin documento")}</td>
        <td data-label="Pendientes"><span class="java-balance ${hasBalance ? "has-balance" : "is-clear"}">${hasBalance ? `${client.balance} javas` : "Sin pendientes"}</span></td>
        <td class="java-action-cell"><button class="java-receive-btn" type="button" data-receive-client="${client.id}" ${hasBalance ? "" : "disabled"}>Recibir</button></td>
      </tr>`;
  }).join("");
}

function renderClientPagination() {
  if (!elements.clientPagination) return;
  const pagination = state.pagination;
  const range = pagination.total > 0 ? `${pagination.from}–${pagination.to} de ${pagination.total}` : "0 clientes";
  elements.clientPagination.innerHTML = `
    <span class="java-pagination-total">${range}</span>
    <div class="java-pagination-actions">
      <button type="button" data-java-page="${pagination.current_page - 1}" ${pagination.current_page > 1 ? "" : "disabled"}>Anterior</button>
      <span>${pagination.current_page} de ${pagination.last_page}</span>
      <button type="button" data-java-page="${pagination.current_page + 1}" ${pagination.current_page < pagination.last_page ? "" : "disabled"}>Siguiente</button>
    </div>`;
}

function renderMovements() {
  if (!elements.movementRows) return;
  if (!state.movements.length) {
    elements.movementRows.innerHTML = '<tr><td colspan="7" class="java-empty-cell">No hay movimientos en esta jornada.</td></tr>';
    return;
  }

  elements.movementRows.innerHTML = state.movements.map((movement) => {
    const isReceipt = movement.type === "RECEPCION";
    const reference = movement.ticket?.code ? `Ticket ${escapeHtml(movement.ticket.code)}` : escapeHtml(movement.observations || "Entrada manual");
    return `
      <tr>
        <td data-label="Fecha">${formatDate(movement.occurred_at)}</td>
        <td data-label="Camión"><strong>${escapeHtml(movement.truck?.plate || "—")}</strong></td>
        <td data-label="Cliente"><strong>${escapeHtml(movement.client?.name)}</strong></td>
        <td data-label="Movimiento"><span class="java-movement-badge ${isReceipt ? "is-receipt" : "is-dispatch"}">${isReceipt ? "Entrada" : "Salida"}</span></td>
        <td data-label="Cantidad"><strong class="java-quantity ${isReceipt ? "is-negative" : "is-positive"}">${isReceipt ? "−" : "+"}${movement.quantity}</strong></td>
        <td data-label="Chofer">${escapeHtml(movement.driver?.name || "—")}</td>
        <td data-label="Referencia">${reference}</td>
      </tr>`;
  }).join("");
}

function renderTruckActivity() {
  if (!elements.truckActivityRows) return;
  if (!state.truckActivity.length) {
    elements.truckActivityRows.innerHTML = '<tr><td colspan="6" class="java-empty-cell">No hay actividad de camiones en esta jornada.</td></tr>';
    return;
  }

  elements.truckActivityRows.innerHTML = state.truckActivity.map((activity) => `
    <tr>
      <td data-label="Camión"><strong>${escapeHtml(activity.truck?.plate || "Sin camión")}</strong></td>
      <td data-label="Chofer">${escapeHtml(activity.driver?.name || "Sin chofer")}</td>
      <td data-label="Cliente">${escapeHtml(activity.client?.name || "—")}</td>
      <td data-label="Llevó"><strong class="java-quantity is-positive">${activity.dispatched}</strong></td>
      <td data-label="Trajo"><strong class="java-quantity is-negative">${activity.received}</strong></td>
      <td data-label="Balance"><span class="java-balance ${activity.net > 0 ? "has-balance" : "is-clear"}">${activity.net}</span></td>
    </tr>`).join("");
}

function updateBalanceHint() {
  if (!elements.balanceHint || !elements.quantity) return;
  const client = selectedClient();
  if (!client) {
    elements.balanceHint.textContent = "Selecciona un cliente para consultar su saldo.";
    elements.quantity.removeAttribute("max");
    return;
  }
  elements.balanceHint.textContent = `El cliente debe devolver ${client.balance} javas. Esa es la cantidad máxima que puedes registrar.`;
  elements.quantity.max = String(client.balance);
}

function chooseClientForReceipt(clientId) {
  if (!elements.client) return;
  elements.client.value = String(clientId);
  updateBalanceHint();
  elements.quantity.value = "";
  elements.quantity.focus();
  if (window.matchMedia("(max-width: 1050px)").matches) {
    elements.form.scrollIntoView({ behavior: "smooth", block: "start" });
  }
}

async function loadControl({
  keepMessages = false,
  pageNumber = state.pagination.current_page,
  journeyId = elements.journey?.value || ""
} = {}) {
  const requestId = ++state.requestId;
  const query = new URLSearchParams({ page: String(pageNumber) });
  const search = elements.search?.value.trim() || "";
  const historyClientId = elements.historyClient?.value || "";
  if (journeyId) query.set("journey_id", journeyId);
  if (historyClientId) query.set("client_id", historyClientId);
  if (search) query.set("search", search);

  if (!keepMessages) {
    setMessage(elements.balanceMessage, "Cargando saldos...");
    setMessage(elements.historyMessage, "Cargando movimientos...");
  }

  try {
    const response = await apiRequest(`/control-javas?${query.toString()}`);
    if (requestId !== state.requestId) return;
    const data = response.data;
    state.clients = data.clients || [];
    state.clientOptions = data.client_options || state.clients;
    state.inventory = data.inventory || null;
    state.journeys = data.journeys || [];
    state.truckActivity = data.truck_activity || [];
    state.movements = data.movements || [];
    state.pagination = data.clients_pagination || state.pagination;

    renderSummary(data);
    renderInventory();
    renderDailyCount();
    renderJourneys(data.selected_journey_id);
    renderClientOptions();
    renderFleetOptions(data.trucks || [], data.drivers || []);
    renderClients();
    renderClientPagination();
    renderTruckActivity();
    renderMovements();
    setMessage(elements.balanceMessage);
    setMessage(elements.historyMessage);
  } catch (error) {
    setMessage(elements.balanceMessage, errorMessage(error), true);
    setMessage(elements.historyMessage, errorMessage(error), true);
    setText(elements.inventoryStatus, errorMessage(error));
  }
}

async function submitInventory(event) {
  event.preventDefault();
  setMessage(elements.inventoryMessage);
  const quantity = Number(elements.inventoryQuantity.value);
  if (!Number.isInteger(quantity) || quantity < 0) {
    setMessage(elements.inventoryMessage, "El total debe ser un número entero igual o mayor que cero.", true);
    return;
  }
  const outside = Number(state.inventory?.outside ?? 0);
  if (quantity < outside) {
    setMessage(elements.inventoryMessage, `El total no puede ser menor que las ${outside} javas que están fuera.`, true);
    return;
  }

  elements.inventorySubmit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/inventario", {
      method: "POST",
      body: JSON.stringify({ total_quantity: quantity })
    });
    await loadControl({ keepMessages: true });
    closeInventoryModal();
    setText(elements.inventoryStatus, response.message);
  } catch (error) {
    setMessage(elements.inventoryMessage, errorMessage(error), true);
  } finally {
    elements.inventorySubmit.disabled = false;
  }
}

async function submitDailyCount(event) {
  event.preventDefault();
  setMessage(elements.dailyMessage);
  const quantity = Number(elements.dailyCountQuantity.value);
  if (!Number.isInteger(quantity) || quantity < 0) {
    setMessage(elements.dailyMessage, "El conteo debe ser un número entero igual o mayor que cero.", true);
    return;
  }

  elements.dailySubmit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/conteo-diario", {
      method: "POST",
      body: JSON.stringify({ quantity })
    });
    state.inventory = response.data;
    renderInventory();
    renderDailyCount();
    closeDailyModal();
    setText(elements.dailyStatus, response.message);
  } catch (error) {
    setMessage(elements.dailyMessage, errorMessage(error), true);
  } finally {
    elements.dailySubmit.disabled = false;
  }
}

async function submitReceipt(event) {
  event.preventDefault();
  setMessage(elements.receiptMessage);
  const client = selectedClient();
  const quantity = Number(elements.quantity.value);
  if (!client || !elements.truck.value || !elements.driver.value) {
    setMessage(elements.receiptMessage, "Completa el cliente, camión, chofer y cantidad.", true);
    return;
  }
  if (!Number.isInteger(quantity) || quantity < 1) {
    setMessage(elements.receiptMessage, "La cantidad debe ser un número entero mayor que cero.", true);
    return;
  }
  if (quantity > Number(client.balance)) {
    setMessage(elements.receiptMessage, `El cliente solo tiene ${client.balance} javas pendientes.`, true);
    return;
  }

  elements.submit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/recepciones", {
      method: "POST",
      body: JSON.stringify({
        client_id: Number(elements.client.value),
        vehicle_id: Number(elements.truck.value),
        driver_id: Number(elements.driver.value),
        quantity,
        observations: elements.observations.value.trim() || null
      })
    });
    elements.quantity.value = "";
    elements.observations.value = "";
    await loadControl({ keepMessages: true, pageNumber: 1 });
    setMessage(elements.receiptMessage, response.message);
  } catch (error) {
    setMessage(elements.receiptMessage, errorMessage(error), true);
  } finally {
    elements.submit.disabled = false;
  }
}

function initializeTraceTabs() {
  const buttons = [...document.querySelectorAll("[data-java-trace-tab]")];
  const panels = [...document.querySelectorAll("[data-java-trace-panel]")];
  const historyFilter = document.querySelector("[data-java-history-filter]");
  buttons.forEach((button) => button.addEventListener("click", () => {
    const selectedTab = button.dataset.javaTraceTab;
    buttons.forEach((item) => item.setAttribute("aria-selected", String(item === button)));
    panels.forEach((panel) => { panel.hidden = panel.dataset.javaTracePanel !== selectedTab; });
    if (historyFilter) historyFilter.hidden = selectedTab !== "movements";
  }));
}

function on(element, eventName, handler) {
  element?.addEventListener(eventName, handler);
}

on(elements.search, "input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadControl({ pageNumber: 1 }), 300);
});
on(elements.clientPagination, "click", (event) => {
  const button = event.target.closest("[data-java-page]");
  if (button && !button.disabled) loadControl({ pageNumber: Number(button.dataset.javaPage) });
});
on(elements.client, "change", updateBalanceHint);
on(elements.inventoryOpen, "click", openInventoryModal);
on(elements.inventoryClose, "click", closeInventoryModal);
on(elements.inventoryCancel, "click", closeInventoryModal);
on(elements.inventoryForm, "submit", submitInventory);
on(elements.inventoryModal, "click", (event) => {
  if (event.target === elements.inventoryModal) closeInventoryModal();
});
on(elements.dailyOpen, "click", openDailyModal);
on(elements.dailyClose, "click", closeDailyModal);
on(elements.dailyCancel, "click", closeDailyModal);
on(elements.dailyForm, "submit", submitDailyCount);
on(elements.dailyModal, "click", (event) => {
  if (event.target === elements.dailyModal) closeDailyModal();
});
on(elements.journey, "change", () => loadControl({ pageNumber: 1 }));
on(elements.historyClient, "change", () => loadControl());
on(elements.clientRows, "click", (event) => {
  const button = event.target.closest("[data-receive-client]");
  if (button) chooseClientForReceipt(button.dataset.receiveClient);
});
on(elements.form, "submit", submitReceipt);

document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape") return;
  if (elements.inventoryModal && !elements.inventoryModal.hidden) closeInventoryModal();
  if (elements.dailyModal && !elements.dailyModal.hidden) closeDailyModal();
});

initializeTraceTabs();
loadControl();
