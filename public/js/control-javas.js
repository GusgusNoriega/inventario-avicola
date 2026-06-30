import { apiRequest } from "./api-client.js";

const elements = {
  totalPending: document.getElementById("javaTotalPending"),
  clientsPending: document.getElementById("javaClientsPending"),
  receivedToday: document.getElementById("javaReceivedToday"),
  search: document.getElementById("javaClientSearch"),
  clientRows: document.getElementById("javaClientRows"),
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
  movementRows: document.getElementById("javaMovementRows"),
  historyMessage: document.getElementById("javaHistoryMessage")
};

const state = {
  clients: [],
  movements: [],
  loading: false
};

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
  const search = elements.search.value.trim().toLocaleLowerCase("es");
  const clients = state.clients.filter((client) =>
    `${client.name} ${client.document_number || ""}`.toLocaleLowerCase("es").includes(search)
  );

  if (!clients.length) {
    elements.clientRows.innerHTML = '<tr><td colspan="4" class="java-empty-cell">No hay clientes que coincidan con la búsqueda.</td></tr>';
    return;
  }

  elements.clientRows.innerHTML = clients.map((client) => {
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

function renderMovements() {
  if (!state.movements.length) {
    elements.movementRows.innerHTML = '<tr><td colspan="6" class="java-empty-cell">Todavía no hay movimientos para mostrar.</td></tr>';
    return;
  }

  elements.movementRows.innerHTML = state.movements.map((movement) => {
    const isReceipt = movement.type === "RECEPCION";
    const transport = [movement.truck?.plate, movement.driver?.name].filter(Boolean).join(" · ") || "—";
    const reference = movement.ticket?.code
      ? `Ticket ${escapeHtml(movement.ticket.code)}`
      : escapeHtml(movement.observations || "Devolución manual");
    return `
      <tr>
        <td data-label="Fecha">${formatDate(movement.occurred_at)}</td>
        <td data-label="Cliente"><strong>${escapeHtml(movement.client?.name)}</strong></td>
        <td data-label="Movimiento"><span class="java-movement-badge ${isReceipt ? "is-receipt" : "is-dispatch"}">${isReceipt ? "Devuelta por el cliente" : "Entregada al cliente"}</span></td>
        <td data-label="Cantidad"><strong class="java-quantity ${isReceipt ? "is-negative" : "is-positive"}">${isReceipt ? "−" : "+"}${movement.quantity}</strong></td>
        <td data-label="Camión / chofer">${escapeHtml(transport)}</td>
        <td data-label="Referencia">${reference}</td>
      </tr>`;
  }).join("");
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

async function loadControl({ keepMessages = false } = {}) {
  const historyClientId = elements.historyClient.value;
  const query = historyClientId ? `?client_id=${encodeURIComponent(historyClientId)}` : "";

  if (!keepMessages) {
    setMessage(elements.balanceMessage, "Cargando javas pendientes de devolución...");
    setMessage(elements.historyMessage, "Cargando movimientos...");
  }

  try {
    const response = await apiRequest(`/control-javas${query}`);
    const data = response.data;
    state.clients = data.clients || [];
    state.movements = data.movements || [];
    elements.totalPending.textContent = data.summary?.total_pending ?? 0;
    elements.totalPending.classList.toggle("has-balance", Number(data.summary?.total_pending ?? 0) > 0);
    elements.totalPending.classList.toggle("is-clear", Number(data.summary?.total_pending ?? 0) === 0);
    elements.clientsPending.textContent = data.summary?.clients_with_balance ?? 0;
    elements.receivedToday.textContent = data.summary?.received_today ?? 0;
    renderClientOptions();
    renderFleetOptions(data.trucks || [], data.drivers || []);
    renderClients();
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
    await loadControl({ keepMessages: true });
    setMessage(elements.receiptMessage, response.message);
  } catch (error) {
    setMessage(elements.receiptMessage, errorMessage(error), true);
  } finally {
    elements.submit.disabled = false;
  }
}

elements.search.addEventListener("input", renderClients);
elements.client.addEventListener("change", updateBalanceHint);
elements.historyClient.addEventListener("change", () => loadControl());
elements.clientRows.addEventListener("click", (event) => {
  const button = event.target.closest("[data-receive-client]");
  if (button) chooseClientForReceipt(button.dataset.receiveClient);
});
elements.form.addEventListener("submit", submitReceipt);

loadControl();
