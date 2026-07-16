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
  externalCompanyJavas: byId("javaExternalCompanyJavas"),
  externalCompanyTrays: byId("trayExternalCompanyQuantity"),
  externalClientsCount: byId("javaExternalClientsCount"),
  internalCompanyJavas: byId("javaInternalCompanyJavas"),
  internalCompanyTrays: byId("trayInternalCompanyQuantity"),
  internalClientsCount: byId("javaInternalClientsCount"),
  countJourneyTitle: byId("javaCountJourneyTitle"),
  countJourneyWindow: byId("javaCountJourneyWindow"),
  countJourneyState: byId("javaCountJourneyState"),
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
  dailyStatus: byId("javaDailyStatus"),
  dailyQuantity: byId("javaDailyQuantity"),
  trayDailyQuantity: byId("trayDailyQuantity"),
  dailyExpected: byId("javaDailyExpected"),
  trayDailyExpected: byId("trayDailyExpected"),
  dailyDifference: byId("javaDailyDifference"),
  trayDailyDifference: byId("trayDailyDifference"),
  dailyDifferenceLabel: byId("javaDailyDifferenceLabel"),
  dailyForm: byId("javaDailyForm"),
  dailyLocalQuantity: byId("javaDailyLocalQuantity"),
  trayDailyLocalQuantity: byId("trayDailyLocalQuantity"),
  dailyTruckInputs: byId("javaDailyTruckInputs"),
  dailyLocalTotal: byId("javaDailyLocalTotal"),
  trayDailyLocalTotal: byId("trayDailyLocalTotal"),
  dailyTruckTotal: byId("javaDailyTruckTotal"),
  trayDailyTruckTotal: byId("trayDailyTruckTotal"),
  dailyInternalTotal: byId("javaDailyInternalTotal"),
  trayDailyInternalTotal: byId("trayDailyInternalTotal"),
  dailyInsideTotal: byId("javaDailyInsideTotal"),
  trayDailyInsideTotal: byId("trayDailyInsideTotal"),
  dailyExternalTotal: byId("javaDailyExternalTotal"),
  trayDailyExternalTotal: byId("trayDailyExternalTotal"),
  dailyAccountedTotal: byId("javaDailyAccountedTotal"),
  trayDailyAccountedTotal: byId("trayDailyAccountedTotal"),
  dailyPropertyTotal: byId("javaDailyPropertyTotal"),
  trayDailyPropertyTotal: byId("trayDailyPropertyTotal"),
  dailySubmit: byId("javaDailySubmit"),
  dailyMessage: byId("javaDailyMessage"),
  externalHolderCount: byId("javaExternalHolderCount"),
  externalHolderJavas: byId("javaExternalHolderJavas"),
  externalHolderTrays: byId("trayExternalHolderQuantity"),
  externalHolderList: byId("javaExternalHolderList"),
  internalHolderCount: byId("javaInternalHolderCount"),
  internalHolderJavas: byId("javaInternalHolderJavas"),
  internalHolderTrays: byId("trayInternalHolderQuantity"),
  internalHolderList: byId("javaInternalHolderList"),
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
  clientHolders: null,
  inventory: null,
  countBreakdown: null,
  trucks: [],
  journeys: [],
  activeJourneyId: null,
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
    is_internal_client: Boolean(client?.is_internal_client ?? client?.internal_client),
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

function nullableNumber(value) {
  if (value === undefined || value === null || value === "") return null;
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : null;
}

function normalizeHolderGroup(group) {
  const clients = (group?.clients || []).map(normalizeClient);
  return {
    clients,
    clients_count: numericValue(group?.clients_count, clients.length),
    java_quantity: numericValue(group?.java_quantity),
    tray_quantity: numericValue(group?.tray_quantity)
  };
}

function normalizeClientHolders(rawHolders) {
  return {
    external: normalizeHolderGroup(rawHolders?.external),
    internal: normalizeHolderGroup(rawHolders?.internal),
    totals: rawHolders?.totals || {}
  };
}

function normalizeCountBreakdown(rawBreakdown, trucks = []) {
  const raw = rawBreakdown || {};
  const pair = (value) => ({
    javas: nullableNumber(value?.javas),
    trays: nullableNumber(value?.trays)
  });
  const rows = (raw.trucks || trucks || []).map((truck) => ({
    id: Number(truck.id),
    plate: truck.plate || truck.current_plate || `Camión #${truck.id}`,
    current_plate: truck.current_plate || truck.plate || null,
    active: truck.active !== false,
    recorded: Boolean(truck.recorded),
    java_quantity: numericValue(truck.java_quantity),
    tray_quantity: numericValue(truck.tray_quantity)
  }));

  return {
    configured: Boolean(raw.configured),
    detailed: Boolean(raw.detailed),
    legacy: Boolean(raw.legacy),
    stale: Boolean(raw.stale),
    fleet_changed: Boolean(raw.fleet_changed),
    journey_id: raw.journey_id ? Number(raw.journey_id) : null,
    counted_at: raw.counted_at || null,
    counted_by: raw.counted_by || null,
    local: pair(raw.local),
    trucks_total: pair(raw.trucks_total),
    direct_total: pair(raw.direct_total),
    expected_direct: pair(raw.expected_direct),
    difference: pair(raw.difference),
    external_clients: pair(raw.external_clients),
    internal_clients: pair(raw.internal_clients),
    inside_avicola: pair(raw.inside_avicola),
    accounted_total: pair(raw.accounted_total),
    property_total: pair(raw.property_total),
    trucks: rows
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
      assigned_external: numericValue(nested.assigned_external),
      assigned_internal: numericValue(nested.assigned_internal),
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

function renderCountJourney() {
  if (!elements.countJourneyTitle) return;
  const breakdown = state.countBreakdown || normalizeCountBreakdown(null, state.trucks);
  const journey = state.journeys.find(
    (item) => Number(item.id) === Number(state.activeJourneyId || breakdown.journey_id)
  );

  setText(
    elements.countJourneyTitle,
    journey ? `Jornada del ${formatOperatingDate(journey.operating_date)}` : "Jornada pendiente de apertura"
  );
  setText(
    elements.countJourneyWindow,
    journey
      ? `${formatDate(journey.starts_at)} a ${formatDate(journey.ends_at)} · Estado: ${journey.status}`
      : "La jornada vigente se creará automáticamente al guardar el primer conteo."
  );

  const stateLabel = breakdown.stale
    ? "Requiere recuento"
    : breakdown.configured
      ? "Conteo registrado"
      : "Sin conteo";
  setText(elements.countJourneyState, stateLabel);
  elements.countJourneyState?.classList.toggle("is-counted", breakdown.configured && !breakdown.stale);
  elements.countJourneyState?.classList.toggle("is-stale", breakdown.stale);
}

function renderHolderGroup(group, countElement, javaElement, trayElement, listElement) {
  setText(countElement, `${group.clients_count} ${group.clients_count === 1 ? "cliente" : "clientes"}`);
  setText(javaElement, group.java_quantity);
  setText(trayElement, group.tray_quantity);

  if (!listElement) return;
  if (!group.clients.length) {
    listElement.innerHTML = '<p class="java-holder-empty">No hay clientes con javas o bandejas pendientes.</p>';
    return;
  }

  listElement.innerHTML = group.clients.map((client) => {
    const inactive = client.status && client.status !== "ACTIVO";
    return `
      <div class="java-holder-row">
        <span class="java-holder-client">
          <strong>${escapeHtml(client.name)}</strong>
          <small>${escapeHtml(client.document_number || "Sin documento")}${inactive ? ' · <em>Inactivo con saldo</em>' : ""}</small>
        </span>
        <span class="java-holder-asset"><small>Javas</small><strong>${client.java_balance}</strong></span>
        <span class="java-holder-asset"><small>Bandejas</small><strong>${client.tray_balance}</strong></span>
      </div>`;
  }).join("");
}

function renderClientHolders() {
  const holders = state.clientHolders || normalizeClientHolders(null);
  const external = holders.external;
  const internal = holders.internal;

  setText(elements.externalCompanyJavas, external.java_quantity);
  setText(elements.externalCompanyTrays, external.tray_quantity);
  setText(elements.externalClientsCount, external.clients_count);
  setText(elements.internalCompanyJavas, internal.java_quantity);
  setText(elements.internalCompanyTrays, internal.tray_quantity);
  setText(elements.internalClientsCount, internal.clients_count);
  renderHolderGroup(
    external,
    elements.externalHolderCount,
    elements.externalHolderJavas,
    elements.externalHolderTrays,
    elements.externalHolderList
  );
  renderHolderGroup(
    internal,
    elements.internalHolderCount,
    elements.internalHolderJavas,
    elements.internalHolderTrays,
    elements.internalHolderList
  );
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
    : `Actualizado: ${formatDate(javas.updated_at || trays.updated_at)} · El conteo directo descuenta los activos asignados a clientes.`);
}

function renderDailyTruckInputs() {
  if (!elements.dailyTruckInputs) return;
  const breakdown = state.countBreakdown || normalizeCountBreakdown(null, state.trucks);
  const activeTrucks = breakdown.trucks.filter((truck) => truck.active);

  if (!activeTrucks.length) {
    elements.dailyTruckInputs.innerHTML = '<tr><td colspan="4" class="java-empty-cell">No hay camiones activos en la flota de la empresa.</td></tr>';
    return;
  }

  elements.dailyTruckInputs.innerHTML = activeTrucks.map((truck) => `
    <tr data-daily-truck-id="${truck.id}">
      <td data-label="Camión">
        <strong>${escapeHtml(truck.current_plate || truck.plate)}</strong>
        <small class="java-truck-company-label">Flota de la empresa</small>
      </td>
      <td data-label="Javas que quedan">
        <input class="java-truck-count-input" data-daily-truck-java type="number" min="0" step="1" inputmode="numeric" value="${breakdown.detailed ? truck.java_quantity : 0}" aria-label="Javas que quedan en el camión ${escapeHtml(truck.current_plate || truck.plate)}" required>
      </td>
      <td data-label="Bandejas que quedan">
        <input class="java-truck-count-input" data-daily-truck-tray type="number" min="0" step="1" inputmode="numeric" value="${breakdown.detailed ? truck.tray_quantity : 0}" aria-label="Bandejas que quedan en el camión ${escapeHtml(truck.current_plate || truck.plate)}" required>
      </td>
      <td data-label="Registro"><span class="java-truck-count-state ${breakdown.detailed && truck.recorded ? "is-recorded" : ""}">${breakdown.detailed && truck.recorded ? "Registrado" : "Por contar"}</span></td>
    </tr>`).join("");
}

function readNonNegativeInteger(input) {
  if (!input || input.value.trim() === "") return null;
  const value = Number(input.value);
  return Number.isInteger(value) && value >= 0 ? value : null;
}

function readDailyDraft() {
  const localJavas = readNonNegativeInteger(elements.dailyLocalQuantity);
  const localTrays = readNonNegativeInteger(elements.trayDailyLocalQuantity);
  const trucks = [...(elements.dailyTruckInputs?.querySelectorAll("[data-daily-truck-id]") || [])].map((row) => ({
    vehicle_id: Number(row.dataset.dailyTruckId),
    java_quantity: readNonNegativeInteger(row.querySelector("[data-daily-truck-java]")),
    tray_quantity: readNonNegativeInteger(row.querySelector("[data-daily-truck-tray]"))
  }));
  const valid = localJavas !== null
    && localTrays !== null
    && trucks.every((truck) => truck.java_quantity !== null && truck.tray_quantity !== null);

  return { valid, localJavas, localTrays, trucks };
}

function signedQuantity(value) {
  return value > 0 ? `+${value}` : String(value);
}

function differenceDescription(javaDifference, trayDifference) {
  if (javaDifference === 0 && trayDifference === 0) {
    return "Cuadre correcto: todas las javas y bandejas están explicadas.";
  }
  const describe = (value, asset) => value < 0
    ? `faltan ${Math.abs(value)} ${asset}`
    : value > 0
      ? `sobran ${value} ${asset}`
      : `${asset} cuadradas`;
  return `Revisa el conteo: ${describe(javaDifference, "javas")} y ${describe(trayDifference, "bandejas")}.`;
}

function updateDailyReconciliation() {
  if (!elements.dailyForm) return;
  const inventory = state.inventory || normalizeInventory(null);
  const holders = state.clientHolders || normalizeClientHolders(null);
  const draft = readDailyDraft();
  const ready = inventory.javas.configured && inventory.trays.configured;
  const localJavas = draft.localJavas ?? 0;
  const localTrays = draft.localTrays ?? 0;
  const truckJavas = draft.trucks.reduce((total, truck) => total + (truck.java_quantity ?? 0), 0);
  const truckTrays = draft.trucks.reduce((total, truck) => total + (truck.tray_quantity ?? 0), 0);
  const directJavas = localJavas + truckJavas;
  const directTrays = localTrays + truckTrays;
  const externalJavas = holders.external.java_quantity;
  const externalTrays = holders.external.tray_quantity;
  const internalJavas = holders.internal.java_quantity;
  const internalTrays = holders.internal.tray_quantity;
  const insideJavas = directJavas + internalJavas;
  const insideTrays = directTrays + internalTrays;
  const accountedJavas = insideJavas + externalJavas;
  const accountedTrays = insideTrays + externalTrays;
  const propertyJavas = ready ? inventory.javas.total : null;
  const propertyTrays = ready ? inventory.trays.total : null;
  const expectedJavas = ready ? inventory.javas.inside : null;
  const expectedTrays = ready ? inventory.trays.inside : null;
  const javaDifference = ready ? directJavas - expectedJavas : null;
  const trayDifference = ready ? directTrays - expectedTrays : null;

  setText(elements.dailyLocalTotal, localJavas);
  setText(elements.trayDailyLocalTotal, localTrays);
  setText(elements.dailyTruckTotal, truckJavas);
  setText(elements.trayDailyTruckTotal, truckTrays);
  setText(elements.dailyQuantity, directJavas);
  setText(elements.trayDailyQuantity, directTrays);
  setText(elements.dailyExpected, expectedJavas ?? "—");
  setText(elements.trayDailyExpected, expectedTrays ?? "—");
  setText(elements.dailyInternalTotal, internalJavas);
  setText(elements.trayDailyInternalTotal, internalTrays);
  setText(elements.dailyInsideTotal, insideJavas);
  setText(elements.trayDailyInsideTotal, insideTrays);
  setText(elements.dailyExternalTotal, externalJavas);
  setText(elements.trayDailyExternalTotal, externalTrays);
  setText(elements.dailyAccountedTotal, accountedJavas);
  setText(elements.trayDailyAccountedTotal, accountedTrays);
  setText(elements.dailyPropertyTotal, propertyJavas ?? "—");
  setText(elements.trayDailyPropertyTotal, propertyTrays ?? "—");
  setText(elements.dailyDifference, javaDifference === null ? "—" : signedQuantity(javaDifference));
  setText(elements.trayDailyDifference, trayDifference === null ? "—" : signedQuantity(trayDifference));

  [elements.dailyDifference, elements.trayDailyDifference].forEach((element, index) => {
    const difference = index === 0 ? javaDifference : trayDifference;
    element?.classList.toggle("is-negative-stock", difference !== null && difference !== 0);
    element?.classList.toggle("is-positive-stock", difference === 0);
  });

  if (!ready) {
    setText(elements.dailyDifferenceLabel, "Primero define los totales generales de javas y bandejas.");
  } else if (!draft.valid) {
    setText(elements.dailyDifferenceLabel, "Todas las cantidades deben ser enteros iguales o mayores que cero.");
  } else {
    setText(elements.dailyDifferenceLabel, differenceDescription(javaDifference, trayDifference));
  }
}

function handleDailyDraftInput(event) {
  updateDailyReconciliation();
  setMessage(elements.dailyMessage);
  setText(elements.countJourneyState, "Cambios sin guardar");
  elements.countJourneyState?.classList.remove("is-counted");
  elements.countJourneyState?.classList.add("is-stale");

  const row = event.target.closest("[data-daily-truck-id]");
  const rowState = row?.querySelector(".java-truck-count-state");
  if (rowState) {
    rowState.textContent = "Sin guardar";
    rowState.classList.remove("is-recorded");
  }
}

function renderDailyCount() {
  if (!elements.dailyForm) return;
  const inventory = state.inventory || normalizeInventory(null);
  const breakdown = state.countBreakdown || normalizeCountBreakdown(null, state.trucks);
  const ready = inventory.javas.configured && inventory.trays.configured;
  const activeJourney = state.journeys.find(
    (journey) => Number(journey.id) === Number(state.activeJourneyId)
  );
  const canEdit = ready && (!activeJourney || activeJourney.status === "ABIERTA");

  elements.dailyLocalQuantity.value = breakdown.detailed ? String(breakdown.local.javas ?? 0) : "0";
  elements.trayDailyLocalQuantity.value = breakdown.detailed ? String(breakdown.local.trays ?? 0) : "0";
  renderDailyTruckInputs();
  elements.dailyForm.querySelectorAll("input").forEach((input) => { input.disabled = !canEdit; });
  elements.dailySubmit.disabled = !canEdit;

  if (!ready) {
    setText(elements.dailyStatus, "El conteo se habilitará después de configurar ambos inventarios generales.");
  } else if (activeJourney && activeJourney.status !== "ABIERTA") {
    setText(elements.dailyStatus, "La jornada operativa está cerrada. El registro se conserva solo para consulta.");
  } else if (breakdown.legacy) {
    setText(elements.dailyStatus, "Existe un conteo agregado anterior. Completa local y camiones para convertirlo en un informe detallado.");
  } else if (breakdown.stale) {
    setText(elements.dailyStatus, "El inventario, los saldos o la flota cambiaron después del último conteo. Revisa y vuelve a guardar.");
  } else if (breakdown.configured) {
    const actor = breakdown.counted_by?.name ? ` · Registrado por ${breakdown.counted_by.name}` : "";
    setText(elements.dailyStatus, `Último conteo: ${formatDate(breakdown.counted_at)}${actor}`);
  } else {
    setText(elements.dailyStatus, "Aún no se ha registrado el conteo detallado de la jornada actual.");
  }

  renderCountJourney();
  updateDailyReconciliation();
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
    state.trucks = data.trucks || [];
    state.clientHolders = normalizeClientHolders(
      data.client_holders || data.inventory?.client_holders
    );
    state.inventory = normalizeInventory(data.inventory);
    state.countBreakdown = normalizeCountBreakdown(
      data.inventory?.count_breakdown,
      state.trucks
    );
    state.journeys = data.journeys || [];
    state.activeJourneyId = data.active_journey_id || state.countBreakdown.journey_id;
    state.truckActivity = (data.truck_activity || []).map(normalizeActivity);
    state.movements = (data.movements || []).map(normalizeMovement);
    state.pagination = data.clients_pagination || state.pagination;

    renderSummary(data);
    renderInventory();
    renderJourneys(data.selected_journey_id);
    renderClientHolders();
    renderDailyCount();
    renderClientOptions();
    renderFleetOptions(state.trucks, data.drivers || []);
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
  const draft = readDailyDraft();
  if (!draft.valid) {
    setMessage(elements.dailyMessage, "Completa el local y todos los camiones con números enteros iguales o mayores que cero.", true);
    return;
  }

  elements.dailySubmit.disabled = true;
  try {
    const response = await apiRequest("/control-javas/conteo-diario", {
      method: "POST",
      body: JSON.stringify({
        local_java_quantity: draft.localJavas,
        local_tray_quantity: draft.localTrays,
        truck_counts: draft.trucks
      })
    });
    await loadControl({ keepMessages: true });
    setMessage(elements.dailyMessage, response.message);
  } catch (error) {
    setMessage(elements.dailyMessage, errorMessage(error), true);
  } finally {
    const inventory = state.inventory || normalizeInventory(null);
    const activeJourney = state.journeys.find(
      (journey) => Number(journey.id) === Number(state.activeJourneyId)
    );
    elements.dailySubmit.disabled = !inventory.javas.configured
      || !inventory.trays.configured
      || Boolean(activeJourney && activeJourney.status !== "ABIERTA");
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
on(elements.dailyForm, "input", handleDailyDraftInput);
on(elements.dailyForm, "submit", submitDailyCount);
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
});

initializeTraceTabs();
loadControl();
