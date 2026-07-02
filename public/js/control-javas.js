import { apiRequest } from "./api-client.js";

const elements = {
  totalPending: document.getElementById("javaTotalPending"),
  clientsPending: document.getElementById("javaClientsPending"),
  journey: document.getElementById("javaJourneyFilter"),
  journeyTitle: document.getElementById("javaJourneyTitle"),
  journeyWindow: document.getElementById("javaJourneyWindow"),
  journeyDispatched: document.getElementById("javaJourneyDispatched"),
  journeyReceived: document.getElementById("javaJourneyReceived"),
  journeyNet: document.getElementById("javaJourneyNet"),
  journeyTrucks: document.getElementById("javaJourneyTrucks"),
  search: document.getElementById("javaClientSearch"),
  clientRows: document.getElementById("javaClientRows"),
  clientPagination: document.getElementById("javaClientPagination"),
  balanceMessage: document.getElementById("javaBalanceMessage"),
  form: document.getElementById("javaReceiptForm"),
  client: document.getElementById("javaReceiptClient"),
  truck: document.getElementById("javaReceiptTruck"),
  driver: document.getElementById("javaReceiptDriver"),
  quantity: document.getElementById("javaReceiptQuantity"),
  observations: document.getElementById("javaReceiptObservations"),
  balanceHint: document.getElementById("javaClientBalanceHint"),
  submit: document.getElementById("javaReceiptSubmit"),
  receiptMessage: document.getElementById("javaReceiptMessage"),
  historyClient: document.getElementById("javaHistoryClient"),
  truckActivityRows: document.getElementById("javaTruckActivityRows"),
  movementRows: document.getElementById("javaMovementRows"),
  historyMessage: document.getElementById("javaHistoryMessage")
};

const state = {
  clients: [],
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
  element.textContent = message;
  element.classList.toggle("is-error", isError);
  element.classList.toggle("is-success", Boolean(message) && !isError);
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

function renderJourneys(selectedJourneyId) {
  if (!state.journeys.length) {
    elements.journey.innerHTML = '<option value="">Sin jornadas registradas</option>';
    elements.journey.disabled = true;
    elements.journeyTitle.textContent = "Sin jornada operativa";
    elements.journeyWindow.textContent = "Al registrar el primer movimiento se creará la jornada actual.";
    return;
  }

  elements.journey.disabled = false;
  elements.journey.innerHTML = state.journeys.map((journey) => `
    <option value="${journey.id}">${escapeHtml(formatOperatingDate(journey.operating_date))} · ${escapeHtml(journey.status)}</option>
  `).join("");
  elements.journey.value = String(selectedJourneyId || state.journeys[0].id);
  const selected = state.journeys.find((journey) => Number(journey.id) === Number(elements.journey.value));
  elements.journeyTitle.textContent = selected
    ? `Jornada del ${formatOperatingDate(selected.operating_date)}`
    : "Jornada seleccionada";
  elements.journeyWindow.textContent = selected
    ? `${formatDate(selected.starts_at)} a ${formatDate(selected.ends_at)} · Estado: ${selected.status} · Solo cambia las tablas inferiores.`
    : "Este filtro solo cambia el consolidado y el detalle que aparecen debajo.";
}

function selectedClient() {
  return state.clients.find((client) => Number(client.id) === Number(elements.client.value));
}

function renderClientOptions() {
  const receiptValue = elements.client.value;
  const historyValue = elements.historyClient.value;
  const options = state.clients.map((client) => `
    <option value="${client.id}">${escapeHtml(client.name)} — ${client.balance} por devolver</option>
  `).join("");

  elements.client.innerHTML = `<option value="">Seleccionar cliente</option>${options}`;
  elements.historyClient.innerHTML = `<option value="">Todos los clientes</option>${state.clients.map((client) => `
    <option value="${client.id}">${escapeHtml(client.name)}</option>
  `).join("")}`;
  elements.client.value = receiptValue;
  elements.historyClient.value = historyValue;
  updateBalanceHint();
}

function renderFleetOptions(trucks, drivers) {
  elements.truck.innerHTML = trucks.length
    ? `<option value="">Seleccionar camión</option>${trucks.map((truck) => `<option value="${truck.id}">${escapeHtml(truck.plate)}</option>`).join("")}`
    : '<option value="">No hay camiones propios activos</option>';
  elements.driver.innerHTML = drivers.length
    ? `<option value="">Seleccionar chofer</option>${drivers.map((driver) => `<option value="${driver.id}">${escapeHtml(driver.name)}</option>`).join("")}`
    : '<option value="">No hay choferes activos</option>';
}

function renderClients() {
  if (!state.clients.length) {
    elements.clientRows.innerHTML = '<tr><td colspan="4" class="java-empty-cell">No hay clientes que coincidan con la búsqueda.</td></tr>';
    return;
  }

  elements.clientRows.innerHTML = state.clients.map((client) => {
    const hasBalance = client.balance > 0;
    return `
      <tr>
        <td data-label="Cliente"><strong>${escapeHtml(client.name)}</strong></td>
        <td data-label="Documento">${escapeHtml(client.document_number || "Sin documento")}</td>
        <td data-label="Javas que debe devolver">
          <span class="java-balance ${hasBalance ? "has-balance" : "is-clear"}">
            ${hasBalance ? `${client.balance} javas` : "0 javas · Sin pendientes"}
          </span>
        </td>
        <td class="java-action-cell">
          <button class="java-receive-btn" type="button" data-receive-client="${client.id}" ${hasBalance ? "" : "disabled"}>
            Registrar devolución
          </button>
        </td>
      </tr>`;
  }).join("");
}

function renderClientPagination() {
  const pagination = state.pagination;
  const hasPrevious = pagination.current_page > 1;
  const hasNext = pagination.current_page < pagination.last_page;
  const range = pagination.total > 0
    ? `${pagination.from}–${pagination.to} de ${pagination.total} clientes`
    : "0 clientes";

  elements.clientPagination.innerHTML = `
    <span class="java-pagination-total">${range}</span>
    <div class="java-pagination-actions">
      <button type="button" data-java-page="${pagination.current_page - 1}" ${hasPrevious ? "" : "disabled"}>Anterior</button>
      <span>Página ${pagination.current_page} de ${pagination.last_page}</span>
      <button type="button" data-java-page="${pagination.current_page + 1}" ${hasNext ? "" : "disabled"}>Siguiente</button>
    </div>
  `;
}

function renderMovements() {
  if (!state.movements.length) {
    elements.movementRows.innerHTML = '<tr><td colspan="7" class="java-empty-cell">No hay entradas ni salidas de javas en esta jornada.</td></tr>';
    return;
  }

  elements.movementRows.innerHTML = state.movements.map((movement) => {
    const isReceipt = movement.type === "RECEPCION";
    const reference = movement.ticket?.code
      ? `Ticket ${escapeHtml(movement.ticket.code)}`
      : escapeHtml(movement.observations || "Entrada manual");
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
  if (!state.truckActivity.length) {
    elements.truckActivityRows.innerHTML = '<tr><td colspan="6" class="java-empty-cell">No hay actividad de camiones en esta jornada.</td></tr>';
    return;
  }

  elements.truckActivityRows.innerHTML = state.truckActivity.map((activity) => `
    <tr>
      <td data-label="Camión"><strong>${escapeHtml(activity.truck?.plate || "Sin camión")}</strong></td>
      <td data-label="Chofer">${escapeHtml(activity.driver?.name || "Sin chofer")}</td>
      <td data-label="Cliente">${escapeHtml(activity.client?.name || "—")}</td>
      <td data-label="Javas que llevó"><strong class="java-quantity is-positive">${activity.dispatched}</strong></td>
      <td data-label="Javas que trajo"><strong class="java-quantity is-negative">${activity.received}</strong></td>
      <td data-label="Balance"><span class="java-balance ${activity.net > 0 ? "has-balance" : "is-clear"}">${activity.net}</span></td>
    </tr>
  `).join("");
}

function updateBalanceHint() {
  const client = selectedClient();
  if (!client) {
    elements.balanceHint.textContent = "Selecciona un cliente para consultar cuántas javas debe devolver.";
    elements.quantity.removeAttribute("max");
    return;
  }

  elements.balanceHint.textContent = client.balance > 0
    ? `El cliente tiene ${client.balance} javas de la empresa y debe devolverlas. Puedes registrar como máximo esa cantidad.`
    : "El cliente no tiene ninguna java de la empresa pendiente de devolución.";
  elements.quantity.max = String(client.balance);
}

function chooseClientForReceipt(clientId) {
  elements.client.value = String(clientId);
  updateBalanceHint();
  elements.quantity.value = "";
  elements.quantity.focus();
  elements.form.scrollIntoView({ behavior: "smooth", block: "center" });
}

async function loadControl({
  keepMessages = false,
  page = state.pagination.current_page,
  journeyId = elements.journey.value
} = {}) {
  const requestId = ++state.requestId;
  const historyClientId = elements.historyClient.value;
  const query = new URLSearchParams({ page: String(page) });
  const search = elements.search.value.trim();
  if (journeyId) query.set("journey_id", journeyId);
  if (historyClientId) query.set("client_id", historyClientId);
  if (search) query.set("search", search);

  if (!keepMessages) {
    setMessage(elements.balanceMessage, "Cargando javas pendientes de devolución...");
    setMessage(elements.historyMessage, "Cargando movimientos...");
  }

  try {
    const response = await apiRequest(`/control-javas?${query.toString()}`);
    if (requestId !== state.requestId) return;
    const data = response.data;
    state.clients = data.clients || [];
    state.journeys = data.journeys || [];
    state.truckActivity = data.truck_activity || [];
    state.movements = data.movements || [];
    state.pagination = data.clients_pagination || state.pagination;
    elements.totalPending.textContent = data.summary?.total_pending ?? 0;
    elements.clientsPending.textContent = data.summary?.clients_with_balance ?? 0;
    const currentSummary = data.current_summary || {};
    elements.journeyDispatched.textContent = currentSummary.dispatched ?? 0;
    elements.journeyReceived.textContent = currentSummary.received ?? 0;
    elements.journeyNet.textContent = currentSummary.net ?? 0;
    elements.journeyNet.classList.toggle("has-balance", Number(currentSummary.net ?? 0) > 0);
    elements.journeyNet.classList.toggle("is-clear", Number(currentSummary.net ?? 0) <= 0);
    elements.journeyTrucks.textContent = currentSummary.trucks_count ?? 0;
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
  }
}

async function submitReceipt(event) {
  event.preventDefault();
  setMessage(elements.receiptMessage);
  const client = selectedClient();
  const quantity = Number(elements.quantity.value);

  if (!client || !elements.truck.value || !elements.driver.value) {
    setMessage(elements.receiptMessage, "Completa el cliente, camión, chofer y cantidad devuelta.", true);
    return;
  }
  if (!Number.isInteger(quantity) || quantity < 1) {
    setMessage(elements.receiptMessage, "La cantidad debe ser un número entero mayor que cero.", true);
    return;
  }
  if (quantity > client.balance) {
    setMessage(elements.receiptMessage, `El cliente solo tiene ${client.balance} javas de la empresa pendientes de devolución.`, true);
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
    await loadControl({
      keepMessages: true,
      journeyId: response.data?.journey?.id || elements.journey.value
    });
    setMessage(elements.receiptMessage, response.message);
  } catch (error) {
    setMessage(elements.receiptMessage, errorMessage(error), true);
  } finally {
    elements.submit.disabled = false;
  }
}

elements.search.addEventListener("input", () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadControl({ page: 1 }), 300);
});
elements.clientPagination.addEventListener("click", (event) => {
  const button = event.target.closest("[data-java-page]");
  if (!button || button.disabled) return;
  loadControl({ page: Number(button.dataset.javaPage) });
});
elements.client.addEventListener("change", updateBalanceHint);
elements.journey.addEventListener("change", () => loadControl({ page: 1 }));
elements.historyClient.addEventListener("change", () => loadControl());
elements.clientRows.addEventListener("click", (event) => {
  const button = event.target.closest("[data-receive-client]");
  if (button) chooseClientForReceipt(button.dataset.receiveClient);
});
elements.form.addEventListener("submit", submitReceipt);

loadControl();
