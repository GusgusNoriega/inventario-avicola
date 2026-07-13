import { apiRequest } from "./api-client.js";

const page = document.body.dataset.javaPage || "dashboard";
const byId = (id) => document.getElementById(id);
const elements = {
  totalPending: byId("javaTotalPending"),
  trayTotalPending: byId("trayTotalPending"),
  clientsPending: byId("javaClientsPending"),
  receivedToday: byId("javaReceivedToday"),
  trayReceivedToday: byId("trayReceivedToday"),
  pendingSummary: byId("javaPendingSummary"),
  journey: byId("javaJourneyFilter"),
  journeyTitle: byId("javaJourneyTitle"),
  journeyWindow: byId("javaJourneyWindow"),
  journeyDispatched: byId("javaJourneyDispatched"),
  trayJourneyDispatched: byId("trayJourneyDispatched"),
  journeyReceived: byId("javaJourneyReceived"),
  trayJourneyReceived: byId("trayJourneyReceived"),
  journeyNet: byId("javaJourneyNet"),
  trayJourneyNet: byId("trayJourneyNet"),
  journeyTrucks: byId("javaJourneyTrucks"),
  companyTotal: byId("javaCompanyTotal"),
  trayCompanyTotal: byId("trayCompanyTotal"),
  companyInside: byId("javaCompanyInside"),
  trayCompanyInside: byId("trayCompanyInside"),
  companyOutside: byId("javaCompanyOutside"),
  trayCompanyOutside: byId("trayCompanyOutside"),
  inventoryStatus: byId("javaInventoryStatus"),
  inventoryOpen: byId("javaInventoryOpen"),
  inventoryModal: byId("javaInventoryModal"),
  inventoryClose: byId("javaInventoryClose"),
  inventoryCancel: byId("javaInventoryCancel"),
  inventoryForm: byId("javaInventoryForm"),
  inventoryQuantity: byId("javaInventoryQuantity"),
  trayInventoryQuantity: byId("trayInventoryQuantity"),
  inventoryOutsideHint: byId("javaInventoryOutsideHint"),
  trayInventoryOutsideHint: byId("trayInventoryOutsideHint"),
  inventorySubmit: byId("javaInventorySubmit"),
  inventoryMessage: byId("javaInventoryMessage"),
  dailyOpen: byId("javaDailyOpen"),
  dailyStatus: byId("javaDailyStatus"),
  dailyQuantity: byId("javaDailyQuantity"),
  trayDailyQuantity: byId("trayDailyQuantity"),
  dailyExpected: byId("javaDailyExpected"),
  trayDailyExpected: byId("trayDailyExpected"),
  dailyDifference: byId("javaDailyDifference"),
  trayDailyDifference: byId("trayDailyDifference"),
  dailyDifferenceLabel: byId("javaDailyDifferenceLabel"),
  trayDailyDifferenceLabel: byId("trayDailyDifferenceLabel"),
  dailyModal: byId("javaDailyModal"),
  dailyClose: byId("javaDailyClose"),
  dailyCancel: byId("javaDailyCancel"),
  dailyForm: byId("javaDailyForm"),
  dailyCountQuantity: byId("javaDailyCountQuantity"),
  trayDailyCountQuantity: byId("trayDailyCountQuantity"),
  dailyExpectedHint: byId("javaDailyExpectedHint"),
  trayDailyExpectedHint: byId("trayDailyExpectedHint"),
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
  trayQuantity: byId("trayReceiptQuantity"),
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

function numericValue(...values) {
  const value = values.find((candidate) => candidate !== undefined && candidate !== null && candidate !== "");
  const numeric = Number(value ?? 0);
  return Number.isFinite(numeric) ? numeric : 0;
}

function metricQuantity(source, metric, asset, legacyKey = metric) {
  const prefix = asset === "trays" ? "tray" : "java";
  const nested = source?.[metric];
  return numericValue(
    nested?.[`${prefix}_quantity`],
    nested?.[prefix],
    source?.[`${prefix}_${metric}`],
    source?.[`${prefix}_${metric}_quantity`],
    source?.[`${metric}_${prefix}_quantity`],
    asset === "javas" ? source?.[legacyKey] : undefined
  );
}

function normalizeClient(client) {
  return {
    ...client,
    java_balance: numericValue(client?.java_balance, client?.java_quantity, client?.balance),
    tray_balance: numericValue(client?.tray_balance, client?.tray_quantity)
  };
}

function normalizeMovement(movement) {
  return {
    ...movement,
    java_quantity: numericValue(movement?.java_quantity, movement?.quantity),
    tray_quantity: numericValue(movement?.tray_quantity)
  };
}

function normalizeActivity(activity) {
  return {
    ...activity,
    java_dispatched: metricQuantity(activity, "dispatched", "javas"),
    tray_dispatched: metricQuantity(activity, "dispatched", "trays"),
    java_received: metricQuantity(activity, "received", "javas"),
    tray_received: metricQuantity(activity, "received", "trays"),
    java_net: metricQuantity(activity, "net", "javas"),
    tray_net: metricQuantity(activity, "net", "trays")
  };
}

function normalizeDailyCount(rawDaily, asset, legacyDaily) {
  const prefix = asset === "trays" ? "tray" : "java";
  const daily = rawDaily?.[asset]
    || rawDaily?.[prefix]
    || (rawDaily?.configured !== undefined || rawDaily?.quantity !== undefined ? rawDaily : null)
    || (asset === "javas" ? legacyDaily : null)
    || {};

  return {
    configured: Boolean(
      daily.configured
      ?? rawDaily?.[`${prefix}_configured`]
      ?? (asset === "javas" ? legacyDaily?.configured : false)
    ),
    quantity: numericValue(daily.quantity, daily[`${prefix}_quantity`], rawDaily?.[`${prefix}_quantity`]),
    expected: numericValue(daily.expected, daily[`${prefix}_expected`], rawDaily?.[`${prefix}_expected`]),
    difference: numericValue(daily.difference, daily[`${prefix}_difference`], rawDaily?.[`${prefix}_difference`]),
    counted_at: daily.counted_at || rawDaily?.counted_at || legacyDaily?.counted_at || null
  };
}

function normalizeInventory(rawInventory) {
  const raw = rawInventory || {};
  const daily = raw.daily_count || {};

  const assetInventory = (asset) => {
    const prefix = asset === "trays" ? "tray" : "java";
    const nested = raw[asset] || raw[prefix] || {};
    const legacy = asset === "javas" ? raw : {};
    return {
      configured: Boolean(nested.configured ?? raw[`${prefix}_configured`] ?? legacy.configured ?? false),
      total: numericValue(nested.total, nested.quantity, raw[`${prefix}_total`], raw[`${prefix}_quantity`], legacy.total),
      inside: numericValue(nested.inside, raw[`${prefix}_inside`], legacy.inside),
      outside: numericValue(nested.outside, raw[`${prefix}_outside`], legacy.outside),
      updated_at: nested.updated_at || raw.updated_at || null,
      daily_count: normalizeDailyCount(
        nested.daily_count
          || (daily?.[asset] || daily?.[prefix] ? daily : (asset === "javas" ? daily : {})),
        asset,
        asset === "javas" ? daily : null
      )
    };
  };

  return {
    javas: assetInventory("javas"),
    trays: assetInventory("trays")
  };
}

function hasPendingAssets(client) {
  return Number(client?.java_balance || 0) > 0 || Number(client?.tray_balance || 0) > 0;
}

function assetPairText(javas, trays) {
  return `${javas} javas · ${trays} bandejas`;
}

function assetStack(javas, trays, toneClass = "") {
  return `
    <span class="java-asset-stack ${toneClass}">
      <span><small>Javas</small><strong>${javas}</strong></span>
      <span><small>Bandejas</small><strong>${trays}</strong></span>
    </span>`;
}

function renderSummary(data) {
  const summary = data.summary || {};
  const traceSummary = page === "traceability" ? summary : (data.current_summary || {});
  const pendingJavas = metricQuantity(summary, "total_pending", "javas");
  const pendingTrays = metricQuantity(summary, "total_pending", "trays");
  const receivedTodayJavas = metricQuantity(summary, "received_today", "javas");
  const receivedTodayTrays = metricQuantity(summary, "received_today", "trays");
  const dispatchedJavas = metricQuantity(traceSummary, "dispatched", "javas");
  const dispatchedTrays = metricQuantity(traceSummary, "dispatched", "trays");
  const receivedJavas = metricQuantity(traceSummary, "received", "javas");
  const receivedTrays = metricQuantity(traceSummary, "received", "trays");
  const netJavas = metricQuantity(traceSummary, "net", "javas");
  const netTrays = metricQuantity(traceSummary, "net", "trays");

  setText(elements.totalPending, pendingJavas);
  setText(elements.trayTotalPending, pendingTrays);
  setText(elements.clientsPending, summary.clients_with_balance ?? 0);
  setText(elements.receivedToday, receivedTodayJavas);
  setText(elements.trayReceivedToday, receivedTodayTrays);
  setText(
    elements.pendingSummary,
    Number(summary.clients_with_balance ?? 0) > 0
      ? `${assetPairText(pendingJavas, pendingTrays)} por recuperar`
      : "Sin saldos pendientes"
  );
  setText(elements.journeyDispatched, dispatchedJavas);
  setText(elements.trayJourneyDispatched, dispatchedTrays);
  setText(elements.journeyReceived, receivedJavas);
  setText(elements.trayJourneyReceived, receivedTrays);
  setText(elements.journeyNet, netJavas);
  setText(elements.trayJourneyNet, netTrays);
  setText(elements.journeyTrucks, traceSummary.trucks_count ?? 0);

  [[elements.journeyNet, netJavas], [elements.trayJourneyNet, netTrays]].forEach(([element, value]) => {
    element?.classList.toggle("has-balance", Number(value) > 0);
    element?.classList.toggle("is-clear", Number(value) <= 0);
  });
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
  const inventory = state.inventory || normalizeInventory(null);
  const javas = inventory.javas;
  const trays = inventory.trays;
  const assetElements = [
    [javas, elements.companyTotal, elements.companyInside, elements.companyOutside, elements.inventoryOutsideHint],
    [trays, elements.trayCompanyTotal, elements.trayCompanyInside, elements.trayCompanyOutside, elements.trayInventoryOutsideHint]
  ];

  assetElements.forEach(([asset, totalElement, insideElement, outsideElement, outsideHint]) => {
    setText(outsideElement, asset.outside);
    setText(outsideHint, asset.outside);
    setText(totalElement, asset.configured ? asset.total : "—");
    setText(insideElement, asset.configured ? asset.inside : "—");
    insideElement?.classList.toggle("is-negative-stock", asset.configured && asset.inside < 0);
  });

  if (!javas.configured || !trays.configured) {
    setText(elements.inventoryStatus, "Totales de javas y bandejas pendientes de configurar");
    return;
  }

  if (javas.inside < 0 || trays.inside < 0) {
    setText(elements.inventoryStatus, "Saldo negativo: revisa los totales generales o los movimientos.");
    return;
  }

  setText(elements.inventoryStatus, page === "dashboard"
    ? `${assetPairText(javas.total, trays.total)} en total`
    : `Actualizado ${formatDate(javas.updated_at || trays.updated_at)}. El disponible descuenta los activos que están fuera.`);
}

function renderDailyCount() {
  if (!elements.dailyOpen) return;
  const inventory = state.inventory || normalizeInventory(null);
  const ready = inventory.javas.configured && inventory.trays.configured;
  elements.dailyOpen.disabled = !ready;

  const renderAssetDaily = (asset, assetName, quantityElement, expectedElement, differenceElement, labelElement, hintElement) => {
    const daily = asset.daily_count;
    setText(hintElement, asset.inside);

    if (!asset.configured) {
      setText(quantityElement, "—");
      setText(expectedElement, "Esperadas: —");
      setText(differenceElement, "—");
      setText(labelElement, "Primero define el total general");
      return;
    }

    if (!daily.configured) {
      setText(quantityElement, "—");
      setText(expectedElement, `Esperadas: ${asset.inside}`);
      setText(differenceElement, "—");
      differenceElement?.classList.remove("is-negative-stock", "is-positive-stock");
      setText(labelElement, "Sin conteo para esta jornada");
      return;
    }

    const difference = Number(daily.difference);
    setText(quantityElement, daily.quantity);
    setText(expectedElement, `Esperadas al contar: ${daily.expected}`);
    setText(differenceElement, difference > 0 ? `+${difference}` : String(difference));
    differenceElement?.classList.toggle("is-negative-stock", difference !== 0);
    differenceElement?.classList.toggle("is-positive-stock", difference === 0);
    setText(labelElement, difference < 0
      ? `${Math.abs(difference)} ${assetName} faltantes`
      : difference > 0
        ? `${difference} ${assetName} sobrantes`
        : "Sin diferencias");
  };

  renderAssetDaily(inventory.javas, "javas", elements.dailyQuantity, elements.dailyExpected, elements.dailyDifference, elements.dailyDifferenceLabel, elements.dailyExpectedHint);
  renderAssetDaily(inventory.trays, "bandejas", elements.trayDailyQuantity, elements.trayDailyExpected, elements.trayDailyDifference, elements.trayDailyDifferenceLabel, elements.trayDailyExpectedHint);

  if (!ready) {
    setText(elements.dailyStatus, "El conteo se habilitará después de configurar ambos inventarios.");
    return;
  }

  const countedAt = inventory.javas.daily_count.counted_at || inventory.trays.daily_count.counted_at;
  setText(elements.dailyStatus, countedAt
    ? `Último conteo: ${formatDate(countedAt)}.`
    : "Aún no se ha registrado el conteo físico de la jornada actual.");
}

function openInventoryModal() {
  const inventory = state.inventory || normalizeInventory(null);
  const javaTotal = inventory.javas.configured ? Number(inventory.javas.total) : null;
  const trayTotal = inventory.trays.configured ? Number(inventory.trays.total) : null;
  elements.inventoryQuantity.value = Number.isInteger(javaTotal) && javaTotal >= 0 ? String(javaTotal) : "";
  elements.trayInventoryQuantity.value = Number.isInteger(trayTotal) && trayTotal >= 0 ? String(trayTotal) : "";
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
  const inventory = state.inventory || normalizeInventory(null);
  const javaDaily = inventory.javas.daily_count;
  const trayDaily = inventory.trays.daily_count;
  elements.dailyCountQuantity.value = javaDaily.configured ? String(javaDaily.quantity) : "";
  elements.trayDailyCountQuantity.value = trayDaily.configured ? String(trayDaily.quantity) : "";
  setText(elements.dailyExpectedHint, inventory.javas.inside);
  setText(elements.trayDailyExpectedHint, inventory.trays.inside);
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
    const pendingClients = state.clientOptions.filter(hasPendingAssets);
    elements.client.innerHTML = `<option value="">Seleccionar cliente</option>${pendingClients.map((client) => `
      <option value="${client.id}">${escapeHtml(client.name)} — ${assetPairText(client.java_balance, client.tray_balance)}</option>
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
    elements.clientRows.innerHTML = '<tr><td colspan="5" class="java-empty-cell">No hay clientes que coincidan con la búsqueda.</td></tr>';
    return;
  }

  elements.clientRows.innerHTML = state.clients.map((client) => {
    const hasJavaBalance = Number(client.java_balance) > 0;
    const hasTrayBalance = Number(client.tray_balance) > 0;
    const hasBalance = hasJavaBalance || hasTrayBalance;
    return `
      <tr>
        <td data-label="Cliente"><strong>${escapeHtml(client.name)}</strong></td>
        <td data-label="Documento">${escapeHtml(client.document_number || "Sin documento")}</td>
        <td data-label="Javas"><span class="java-balance ${hasJavaBalance ? "has-balance" : "is-clear"}">${hasJavaBalance ? `${client.java_balance} javas` : "Sin pendientes"}</span></td>
        <td data-label="Bandejas"><span class="java-balance ${hasTrayBalance ? "has-balance" : "is-clear"}">${hasTrayBalance ? `${client.tray_balance} bandejas` : "Sin pendientes"}</span></td>
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
        <td data-label="Javas / Bandejas">${assetStack(
          `${isReceipt ? "−" : "+"}${movement.java_quantity}`,
          `${isReceipt ? "−" : "+"}${movement.tray_quantity}`,
          isReceipt ? "is-receipt" : "is-dispatch"
        )}</td>
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
      <td data-label="Llevó">${assetStack(activity.java_dispatched, activity.tray_dispatched, "is-dispatch")}</td>
      <td data-label="Trajo">${assetStack(activity.java_received, activity.tray_received, "is-receipt")}</td>
      <td data-label="Balance">${assetStack(activity.java_net, activity.tray_net, activity.java_net > 0 || activity.tray_net > 0 ? "has-balance" : "is-clear")}</td>
    </tr>`).join("");
}

function updateBalanceHint() {
  if (!elements.balanceHint || !elements.quantity || !elements.trayQuantity) return;
  const client = selectedClient();
  if (!client) {
    elements.balanceHint.textContent = "Selecciona un cliente para consultar ambos saldos.";
    elements.quantity.removeAttribute("max");
    elements.trayQuantity.removeAttribute("max");
    return;
  }
  elements.balanceHint.textContent = `Pendiente: ${assetPairText(client.java_balance, client.tray_balance)}. Puedes registrar una o ambas cantidades.`;
  elements.quantity.max = String(client.java_balance);
  elements.trayQuantity.max = String(client.tray_balance);
}

function chooseClientForReceipt(clientId) {
  if (!elements.client) return;
  elements.client.value = String(clientId);
  updateBalanceHint();
  elements.quantity.value = "0";
  elements.trayQuantity.value = "0";
  const client = selectedClient();
  (Number(client?.java_balance) > 0 ? elements.quantity : elements.trayQuantity).focus();
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
    state.clients = (data.clients || []).map(normalizeClient);
    state.clientOptions = (data.client_options || data.clients || []).map(normalizeClient);
    state.inventory = normalizeInventory(data.inventory);
    state.journeys = data.journeys || [];
    state.truckActivity = (data.truck_activity || []).map(normalizeActivity);
    state.movements = (data.movements || []).map(normalizeMovement);
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
  const javaQuantity = Number(elements.inventoryQuantity.value);
  const trayQuantity = Number(elements.trayInventoryQuantity.value);
  if (![javaQuantity, trayQuantity].every((quantity) => Number.isInteger(quantity) && quantity >= 0)) {
    setMessage(elements.inventoryMessage, "Ambos totales deben ser números enteros iguales o mayores que cero.", true);
    return;
  }
  const javaOutside = Number(state.inventory?.javas.outside ?? 0);
  const trayOutside = Number(state.inventory?.trays.outside ?? 0);
  if (javaQuantity < javaOutside || trayQuantity < trayOutside) {
    setMessage(
      elements.inventoryMessage,
      `Los totales no pueden ser menores que lo entregado: ${assetPairText(javaOutside, trayOutside)}.`,
      true
    );
    return;
  }

  elements.inventorySubmit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/inventario", {
      method: "POST",
      body: JSON.stringify({
        total_quantity: javaQuantity,
        java_quantity: javaQuantity,
        tray_quantity: trayQuantity
      })
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
  const javaQuantity = Number(elements.dailyCountQuantity.value);
  const trayQuantity = Number(elements.trayDailyCountQuantity.value);
  if (![javaQuantity, trayQuantity].every((quantity) => Number.isInteger(quantity) && quantity >= 0)) {
    setMessage(elements.dailyMessage, "Ambos conteos deben ser números enteros iguales o mayores que cero.", true);
    return;
  }

  elements.dailySubmit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/conteo-diario", {
      method: "POST",
      body: JSON.stringify({
        quantity: javaQuantity,
        java_quantity: javaQuantity,
        tray_quantity: trayQuantity
      })
    });
    state.inventory = normalizeInventory(response.data);
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
  const javaQuantity = Number(elements.quantity.value);
  const trayQuantity = Number(elements.trayQuantity.value);
  if (!client || !elements.truck.value || !elements.driver.value) {
    setMessage(elements.receiptMessage, "Completa el cliente, camión, chofer y las cantidades.", true);
    return;
  }
  if (![javaQuantity, trayQuantity].every((quantity) => Number.isInteger(quantity) && quantity >= 0)) {
    setMessage(elements.receiptMessage, "Las cantidades deben ser números enteros iguales o mayores que cero.", true);
    return;
  }
  if (javaQuantity === 0 && trayQuantity === 0) {
    setMessage(elements.receiptMessage, "Registra al menos una java o una bandeja.", true);
    return;
  }
  if (javaQuantity > Number(client.java_balance) || trayQuantity > Number(client.tray_balance)) {
    setMessage(
      elements.receiptMessage,
      `El cliente solo tiene pendientes ${assetPairText(client.java_balance, client.tray_balance)}.`,
      true
    );
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
        quantity: javaQuantity,
        java_quantity: javaQuantity,
        tray_quantity: trayQuantity,
        observations: elements.observations.value.trim() || null
      })
    });
    elements.quantity.value = "0";
    elements.trayQuantity.value = "0";
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
