import { apiRequest } from "./api-client.js";
import {
  financeDateRangeContains,
  financeDateRangePhrase,
  isValidFinanceFilterDate
} from "./finanzas-date-range.js";
import { movementRepeatContext } from "./finanzas-movimiento-repetido.js";
import {
  accountListFromEntities,
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  fillSelect,
  firstDefined,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  isActiveRecord,
  markFinanceAccessReady,
  numericValue,
  optionalString,
  optionLabel,
  responseCollection,
  responseMeta,
  setMessage,
  toLocalDateTimeValue
} from "./finanzas-common.js";

const MODES = {
  COBRO_CLIENTE: {
    badge: "Ingreso propio",
    client: true,
    clientRequired: true,
    provider: false,
    origin: false,
    destination: "PROPIA",
    cxc: true,
    cxp: false,
    destinationLabel: "Cuenta propia receptora",
    destinationHelp: "El importe aumentará el saldo disponible de esta cuenta.",
    hint: "Puedes dejar parte del cobro sin aplicar como saldo a favor del cliente."
  },
  PAGO_DIRECTO: {
    badge: "Pago directo",
    client: true,
    clientRequired: true,
    provider: true,
    origin: false,
    destination: "EXTERNA",
    cxc: true,
    cxp: true,
    destinationLabel: "Cuenta externa del proveedor",
    destinationHelp: "El dinero no ingresará a nuestro saldo: quedará trazado como pago directo.",
    hint: "Puedes registrarlo aunque todavía no hayas cargado las deudas. Las aplicaciones a CXC y CXP son opcionales y se controlan por separado."
  },
  PAGO_PROVEEDOR: {
    badge: "Salida propia",
    client: false,
    clientRequired: false,
    provider: true,
    origin: true,
    destination: "EXTERNA",
    cxc: false,
    cxp: true,
    destinationLabel: "Cuenta receptora del proveedor",
    destinationHelp: "El pago quedará vinculado al destino externo y al proveedor beneficiario.",
    hint: "El importe no aplicado quedará como saldo a nuestro favor con el proveedor."
  },
  COBRO_MINORISTA: {
    badge: "Venta minorista",
    client: true,
    clientRequired: false,
    provider: false,
    origin: false,
    destination: "PROPIA",
    cxc: true,
    cxp: false,
    destinationLabel: "Cuenta o caja receptora",
    destinationHelp: "Para efectivo selecciona una cuenta tipo CAJA.",
    hint: "Si la venta no tiene cliente, el cobro puede registrarse sin aplicaciones."
  },
  REEMBOLSO_CLIENTE: {
    badge: "Salida por devolucion",
    client: true,
    clientRequired: true,
    provider: false,
    origin: true,
    destination: false,
    cxc: true,
    cxp: false,
    destinationLabel: "Sin cuenta destino",
    destinationHelp: "",
    hint: "El importe debe aplicarse por completo a uno o mas abonos pendientes del cliente.",
    nature: "ABONO"
  },
  DEUDA_ANTERIOR_CLIENTE: {
    badge: "Cuenta por cobrar",
    client: true,
    clientRequired: true,
    provider: false,
    origin: false,
    destination: false,
    method: false,
    reference: false,
    cxc: false,
    cxp: false,
    applications: false,
    notesRequired: true,
    destinationLabel: "Sin cuenta destino",
    destinationHelp: "",
    hint: "El monto quedará como deuda pendiente del cliente. No ingresa dinero a ninguna caja o cuenta."
  },
  SALDO_FAVOR_PROVEEDOR: {
    badge: "Carga manual",
    client: false,
    clientRequired: false,
    provider: true,
    origin: false,
    destination: false,
    method: false,
    cxc: false,
    cxp: false,
    applications: false,
    referenceRequired: true,
    notesRequired: true,
    destinationLabel: "Sin cuenta destino",
    destinationHelp: "",
    hint: "Registra un saldo anterior a favor de nuestra empresa. No mueve dinero de ninguna cuenta ni representa un depósito nuevo."
  }
};

const PROVIDER_CREDIT_TYPES = new Set(["PAGO_DIRECTO", "PAGO_PROVEEDOR", "SALDO_FAVOR_PROVEEDOR"]);
const DAILY_REFRESH_INTERVAL = 10000;
const DAILY_REFRESH_MAX_INTERVAL = 60000;
const MODE_LABELS = {
  COBRO_CLIENTE: "Cliente → nuestra empresa",
  PAGO_DIRECTO: "Cliente → proveedor",
  PAGO_PROVEEDOR: "Nuestra empresa → proveedor",
  COBRO_MINORISTA: "Cobro minorista",
  REEMBOLSO_CLIENTE: "Nuestra empresa → cliente",
  DEUDA_ANTERIOR_CLIENTE: "Deuda anterior de cliente",
  SALDO_FAVOR_PROVEEDOR: "Saldo anterior con proveedor"
};
const dailyChannel = "BroadcastChannel" in window
  ? new BroadcastChannel("sistema-pollos-finanzas-movimientos")
  : null;

const queryParameters = new URLSearchParams(window.location.search);
const requestedType = String(queryParameters.get("tipo") || "").toUpperCase();
const queryPrefill = {
  type: MODES[requestedType] ? requestedType : null,
  clientId: queryParameters.get("cliente_id"),
  providerId: queryParameters.get("proveedor_id"),
  applied: false
};

const elements = {
  form: document.getElementById("financeMovementForm"),
  typeInputs: document.querySelectorAll('[name="financeMovementType"]'),
  detailsTitle: document.getElementById("financeMovementDetailsTitle"),
  flowBadge: document.getElementById("financeMovementFlowBadge"),
  clientField: document.getElementById("financeMovementClientField"),
  clientLabel: document.getElementById("financeMovementClientLabel"),
  clientHelp: document.getElementById("financeMovementClientHelp"),
  client: document.getElementById("financeMovementClient"),
  providerField: document.getElementById("financeMovementProviderField"),
  provider: document.getElementById("financeMovementProvider"),
  originField: document.getElementById("financeMovementOriginField"),
  origin: document.getElementById("financeMovementOrigin"),
  destinationField: document.getElementById("financeMovementDestinationField"),
  destinationLabel: document.getElementById("financeMovementDestinationLabel"),
  destinationHelp: document.getElementById("financeMovementDestinationHelp"),
  destination: document.getElementById("financeMovementDestination"),
  providerPaymentSourcePanel: document.getElementById("financeProviderPaymentSourcePanel"),
  providerPaymentSourceInputs: document.querySelectorAll('[name="financeProviderPaymentSource"]'),
  providerCreditSourceField: document.getElementById("financeProviderCreditSourceField"),
  providerCreditSource: document.getElementById("financeProviderCreditSource"),
  providerCreditSourceHelp: document.getElementById("financeProviderCreditSourceHelp"),
  dateField: document.getElementById("financeMovementDateField"),
  dateLabel: document.getElementById("financeMovementDateLabel"),
  date: document.getElementById("financeMovementDate"),
  methodField: document.getElementById("financeMovementMethodField"),
  method: document.getElementById("financeMovementMethod"),
  amountLabel: document.getElementById("financeMovementAmountLabel"),
  currencyPrefix: document.getElementById("financeMovementCurrencyPrefix"),
  amount: document.getElementById("financeMovementAmount"),
  currency: document.getElementById("financeMovementCurrency"),
  referenceField: document.getElementById("financeMovementReferenceField"),
  referenceLabel: document.getElementById("financeMovementReferenceLabel"),
  reference: document.getElementById("financeMovementReference"),
  notesField: document.getElementById("financeMovementNotesField"),
  notesLabel: document.getElementById("financeMovementNotesLabel"),
  notes: document.getElementById("financeMovementNotes"),
  providerCreditAvailableLine: document.getElementById("financeProviderCreditAvailableLine"),
  providerCreditAvailable: document.getElementById("financeProviderCreditAvailable"),
  summaryTitle: document.getElementById("financeApplicationSummaryTitle"),
  totalLabel: document.getElementById("financeMovementTotalLabel"),
  total: document.getElementById("financeMovementTotal"),
  cxcSummaryLine: document.getElementById("financeCxcSummaryLine"),
  cxcApplied: document.getElementById("financeMovementCxcApplied"),
  cxpSummaryLine: document.getElementById("financeCxpSummaryLine"),
  cxpApplied: document.getElementById("financeMovementCxpApplied"),
  unappliedLabel: document.getElementById("financeMovementUnappliedLabel"),
  unapplied: document.getElementById("financeMovementUnapplied"),
  applicationHint: document.getElementById("financeApplicationHint"),
  message: document.getElementById("financeMovementMessage"),
  save: document.getElementById("financeMovementSave"),
  reset: document.getElementById("financeMovementReset"),
  applicationsPanel: document.getElementById("financeApplicationsPanel"),
  applicationsInstructions: document.getElementById("financeApplicationsInstructions"),
  applicationColumns: document.querySelector(".fin-application-columns"),
  refresh: document.getElementById("financeApplicationsRefresh"),
  cxcPanel: document.getElementById("financeCxcPanel"),
  cxcTitle: document.getElementById("financeCxcTitle"),
  cxcMessage: document.getElementById("financeCxcMessage"),
  cxcList: document.getElementById("financeCxcList"),
  cxcAvailable: document.getElementById("financeCxcAvailable"),
  cxpPanel: document.getElementById("financeCxpPanel"),
  cxpMessage: document.getElementById("financeCxpMessage"),
  cxpList: document.getElementById("financeCxpList"),
  cxpAvailable: document.getElementById("financeCxpAvailable"),
  dailyPanel: document.getElementById("financeDailyPanel"),
  dailyTitle: document.getElementById("financeDailyTitle"),
  dailyDescription: document.getElementById("financeDailyDescription"),
  dailyLive: document.getElementById("financeDailyLive"),
  dailyLiveText: document.getElementById("financeDailyLiveText"),
  dailyFilters: document.getElementById("financeDailyFilters"),
  dailyFrom: document.getElementById("financeDailyFrom"),
  dailyTo: document.getElementById("financeDailyTo"),
  dailyApply: document.getElementById("financeDailyApply"),
  dailyToday: document.getElementById("financeDailyToday"),
  dailyMessage: document.getElementById("financeDailyMessage"),
  dailyRefresh: document.getElementById("financeDailyRefresh"),
  dailyRows: document.getElementById("financeDailyRows")
};

const state = {
  clients: [],
  providers: [],
  methods: [],
  entities: [],
  accounts: [],
  providerCredits: [],
  debts: { CXC: [], CXP: [] },
  applications: { CXC: new Map(), CXP: new Map() },
  requestSequence: { CXC: 0, CXP: 0 },
  providerCreditRequestSequence: 0,
  dailyRequestSequence: 0,
  dailyLoadPromise: null,
  dailyLoadSignature: null,
  dailyRefreshTimer: null,
  dailyResumeTimer: null,
  dailyRefreshDelay: DAILY_REFRESH_INTERVAL,
  dailyRenderedSignature: null,
  dailyAnnouncementSignature: null,
  dailyRecords: [],
  dailyRecordsSignature: null,
  dailyFrom: null,
  dailyTo: null,
  dailyFollowsToday: true,
  dailyLiveActive: null,
  idempotencyKey: createIdempotencyKey(),
  saving: false
};

function currentModeKey() {
  return document.querySelector('[name="financeMovementType"]:checked')?.value || "COBRO_CLIENTE";
}

function currentMode() {
  return MODES[currentModeKey()];
}

function isManualCustomerDebt() {
  return currentModeKey() === "DEUDA_ANTERIOR_CLIENTE";
}

function providerPaymentSource() {
  return document.querySelector('[name="financeProviderPaymentSource"]:checked')?.value || "CUENTA";
}

function usesProviderCredit() {
  return currentModeKey() === "PAGO_PROVEEDOR" && providerPaymentSource() === "SALDO_FAVOR";
}

function currencyInputPrefix(currency = elements.currency.value) {
  return ({ PEN: "S/", USD: "US$", EUR: "€" })[String(currency).toUpperCase()] || String(currency);
}

function movementType(record) {
  return String(firstDefined(record, ["tipo", "type", "movimiento.tipo"], "")).toUpperCase();
}

function movementCurrency(record) {
  return String(firstDefined(record, ["moneda", "currency", "movimiento.moneda"], "PEN")).toUpperCase();
}

function movementApplication(record) {
  return firstDefined(record, ["aplicacion", "application", "resumen_aplicacion"], null);
}

function movementUnapplied(record) {
  return numericValue(firstDefined(movementApplication(record) || {}, [
    "importe_sin_aplicar",
    "unapplied",
    "available"
  ], firstDefined(record, ["saldo_favor", "saldo_disponible"], 0)));
}

function selectedProviderCredit() {
  return state.providerCredits.find((record) => String(firstDefined(record, ["id", "movimiento.id"], "")) === String(elements.providerCreditSource.value)) || null;
}

function selectedProviderCreditAvailable() {
  return movementUnapplied(selectedProviderCredit() || {});
}

function normalizeEntity(entity) {
  const accounts = firstDefined(entity, ["cuentas", "accounts"], []);
  return {
    ...entity,
    id: firstDefined(entity, ["id", "entidad_id"]),
    tipo: String(firstDefined(entity, ["tipo", "type"], "PROPIA")).toUpperCase(),
    razon_social: String(firstDefined(entity, ["razon_social", "nombre", "name"], "Empresa sin nombre")),
    proveedor_id: firstDefined(entity, ["proveedor_id", "provider_id", "proveedor.id", "provider.id"], null),
    cuentas: (Array.isArray(accounts) ? accounts : accounts?.data || []).filter(isActiveRecord)
  };
}

function accountType(account) {
  return String(firstDefined(account, ["tipo", "type"], "OTRA")).toUpperCase();
}

function accountName(account) {
  const entity = account.entidad || {};
  const name = optionLabel(account, firstDefined(account, ["alias", "numero_cuenta", "numero"], "Cuenta"));
  const detail = firstDefined(account, ["banco", "bank", "numero_cuenta", "numero", "alias"], "");
  return `${optionLabel(entity, "Empresa")} — ${name}${detail && detail !== name ? ` · ${detail}` : ""}`;
}

function financeTimeZone() {
  return document.body.dataset.financeTimeZone
    || Intl.DateTimeFormat().resolvedOptions().timeZone
    || "America/Lima";
}

function financeBusinessDate(date = new Date()) {
  try {
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: financeTimeZone(),
      year: "numeric",
      month: "2-digit",
      day: "2-digit"
    }).formatToParts(date);
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}`;
  } catch {
    return toLocalDateTimeValue(date).slice(0, 10);
  }
}

function dailyUpdatedTime(date = new Date()) {
  try {
    return new Intl.DateTimeFormat("es-PE", {
      timeZone: financeTimeZone(),
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    }).format(date);
  } catch {
    return date.toLocaleTimeString("es-PE");
  }
}

function appliedDailyRange() {
  const today = financeBusinessDate();
  return {
    from: state.dailyFrom || today,
    to: state.dailyTo || today
  };
}

function dailyRangePhrase(from = appliedDailyRange().from, to = appliedDailyRange().to) {
  return financeDateRangePhrase(from, to, financeBusinessDate());
}

function dailyRangeContains(date, from = appliedDailyRange().from, to = appliedDailyRange().to) {
  return financeDateRangeContains(date, from, to);
}

function dailyRangeIncludesToday() {
  return dailyRangeContains(financeBusinessDate());
}

function updateDailyLiveStatus(updatedAt = null, { failed = false } = {}) {
  const live = dailyRangeIncludesToday();
  const previousLive = state.dailyLiveActive;
  state.dailyLiveActive = live;
  elements.dailyLive.classList.toggle("is-manual", !live);

  if (previousLive !== null && previousLive !== live) {
    setMessage(
      elements.dailyMessage,
      live
        ? "El rango vuelve a incluir hoy; la actualización automática está activa."
        : "El rango ya no incluye hoy; usa «Actualizar ahora» para volver a consultar el historial."
    );
    state.dailyAnnouncementSignature = `live-mode:${live}:${state.dailyFrom}:${state.dailyTo}`;
  }

  if (failed) {
    elements.dailyLiveText.textContent = live
      ? "Intentando recuperar la actualización automática..."
      : "Rango histórico · no se pudo actualizar";
    return;
  }

  if (live) {
    elements.dailyLiveText.textContent = updatedAt
      ? `Actualización automática activa · ${updatedAt}`
      : "Actualización automática cada 10 s";
    return;
  }

  elements.dailyLiveText.textContent = updatedAt
    ? `Rango histórico · actualización manual · ${updatedAt}`
    : "Rango histórico · actualización manual";
}

function updateDailyRangeCopy(modeKey = currentModeKey()) {
  const { from, to } = appliedDailyRange();
  const phrase = dailyRangePhrase(from, to);
  const label = MODE_LABELS[modeKey] || "Operación seleccionada";
  const useCredit = modeKey === "PAGO_PROVEEDOR" && usesProviderCredit();

  elements.dailyTitle.textContent = `${label}: registros ${phrase}`;
  elements.dailyDescription.textContent = useCredit
    ? `La lista muestra los pagos ${phrase}. Aplicar un saldo anterior distribuye un registro existente y no crea un movimiento nuevo.`
    : modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? `Se muestran todas las deudas anteriores ${phrase}.`
      : `Se muestran todas las transacciones ${phrase} que corresponden a la operación seleccionada.`;
  updateDailyLiveStatus();
}

function initializeDailyRange() {
  const today = financeBusinessDate();
  state.dailyFrom = today;
  state.dailyTo = today;
  state.dailyFollowsToday = true;
  elements.dailyFrom.value = today;
  elements.dailyTo.value = today;
  updateDailyRangeCopy();
}

function syncDailyTodayPreset() {
  if (!state.dailyFollowsToday) return false;
  const today = financeBusinessDate();
  if (state.dailyFrom === today && state.dailyTo === today) return false;

  state.dailyFrom = today;
  state.dailyTo = today;
  elements.dailyFrom.value = today;
  elements.dailyTo.value = today;
  updateDailyRangeCopy();
  return true;
}

function dailyCounterpart(record, modeKey) {
  const clientName = String(firstDefined(record, ["cliente.nombre", "cliente.nombre_razon_social"], "Cliente sin nombre"));
  const clientDocument = String(firstDefined(record, ["cliente.numero_documento"], "Sin documento"));
  const providerName = String(firstDefined(record, ["proveedor.nombre", "proveedor.nombre_razon_social"], "Proveedor sin nombre"));
  const providerDocument = String(firstDefined(record, ["proveedor.numero_documento"], "Sin documento"));

  if (modeKey === "PAGO_DIRECTO") {
    return {
      primary: `${clientName} → ${providerName}`,
      secondary: `${clientDocument} → ${providerDocument}`
    };
  }
  if (["PAGO_PROVEEDOR", "SALDO_FAVOR_PROVEEDOR"].includes(modeKey)) {
    return { primary: providerName, secondary: providerDocument };
  }
  if (modeKey === "COBRO_MINORISTA" && !record.cliente) {
    return { primary: "Sin cliente asignado", secondary: "Cobro minorista" };
  }
  return { primary: clientName, secondary: clientDocument };
}

function dailyAccountAndMethod(record, modeKey) {
  const manualCustomerDebt = modeKey === "DEUDA_ANTERIOR_CLIENTE";
  if (manualCustomerDebt) {
    return { primary: "Cuenta por cobrar", secondary: "Sin movimiento de dinero" };
  }
  if (modeKey === "SALDO_FAVOR_PROVEEDOR") {
    return { primary: "Saldo anterior", secondary: "Sin movimiento de dinero" };
  }

  const route = [record.cuenta_origen, record.cuenta_destino]
    .filter(Boolean)
    .map((account) => accountName(account))
    .join(" → ");
  const method = String(firstDefined(record, [
    "metodo_pago.nombre",
    "metodo_pago.codigo",
    "metodo_snapshot"
  ], "Sin método de pago"));

  return {
    primary: route || "Sin cuenta vinculada",
    secondary: method
  };
}

function dailyReference(record, manualCustomerDebt) {
  if (manualCustomerDebt) {
    return {
      primary: String(record.detalle || "Sin detalle"),
      secondary: `Pendiente: ${formatMoney(record.saldo_pendiente, record.moneda)}`
    };
  }

  return {
    primary: String(record.referencia || "Sin referencia"),
    secondary: String(record.observaciones || "Sin observaciones")
  };
}

function dailyStatus(record) {
  const normalized = String(record.reversa_de_pago_id ? "REVERSA" : record.estado || "REGISTRADO").toUpperCase();
  const label = {
    REGISTRADO: "Vigente",
    PENDIENTE: "Pendiente",
    PARCIAL: "Parcial",
    PAGADO: "Pagado",
    ANULADO: "Anulado",
    REVERSA: "Reversa"
  }[normalized] || normalized;
  const statusClass = normalized.toLowerCase().replace(/[^a-z0-9_-]/g, "");
  return {
    normalized,
    html: `<span class="fin-management-status is-${escapeHtml(statusClass)}">${escapeHtml(label)}</span>`
  };
}

function renderDailyRecords(records, modeKey, highlightedId = null, from = appliedDailyRange().from, to = appliedDailyRange().to) {
  const manualCustomerDebt = modeKey === "DEUDA_ANTERIOR_CLIENTE";
  const label = MODE_LABELS[modeKey] || "esta operación";

  if (!records.length) {
    elements.dailyRows.innerHTML = `<tr><td class="fin-empty-cell" colspan="6">Todavía no hay registros de ${escapeHtml(label.toLowerCase())} ${escapeHtml(dailyRangePhrase(from, to))}.</td></tr>`;
    return;
  }

  elements.dailyRows.innerHTML = records.map((record) => {
    const counterpart = dailyCounterpart(record, modeKey);
    const accountAndMethod = dailyAccountAndMethod(record, modeKey);
    const reference = dailyReference(record, manualCustomerDebt);
    const status = dailyStatus(record);
    const recordId = String(record.id ?? "");
    const classes = ["ANULADO", "REVERSA"].includes(status.normalized) ? ["is-muted"] : [];
    if (highlightedId !== null && recordId === String(highlightedId)) classes.push("is-new");
    const date = manualCustomerDebt ? record.fecha_emision : formatDateTime(record.fecha_hora);
    const amount = manualCustomerDebt ? record.total : record.importe;

    return `<tr class="${classes.join(" ")}" data-daily-record-id="${escapeHtml(recordId)}">
      <td><strong>${escapeHtml(date || "Sin fecha")}</strong><small>${escapeHtml(record.codigo || `#${recordId}`)}</small></td>
      <td><strong>${escapeHtml(counterpart.primary)}</strong><small>${escapeHtml(counterpart.secondary)}</small></td>
      <td><strong>${escapeHtml(accountAndMethod.primary)}</strong><small>${escapeHtml(accountAndMethod.secondary)}</small></td>
      <td><strong>${escapeHtml(reference.primary)}</strong><small>${escapeHtml(reference.secondary)}</small></td>
      <td>${status.html}</td>
      <td class="fin-text-right"><strong class="fin-table-amount">${escapeHtml(formatMoney(amount, record.moneda))}</strong></td>
    </tr>`;
  }).join("");
}

function dailyEndpoint(modeKey, from, to, page) {
  const manualCustomerDebt = modeKey === "DEUDA_ANTERIOR_CLIENTE";
  const params = new URLSearchParams({
    desde: from,
    hasta: to,
    per_page: "100",
    page: String(page)
  });
  if (!manualCustomerDebt) params.set("tipo", modeKey);
  const endpoint = manualCustomerDebt ? "/finanzas/deudas-clientes" : "/finanzas/movimientos";
  return `${endpoint}?${params}`;
}

function dailyRecordDate(record, modeKey) {
  return String(modeKey === "DEUDA_ANTERIOR_CLIENTE" ? record.fecha_emision : record.fecha_hora || "");
}

function sortDailyRecords(records, modeKey) {
  return [...records].sort((left, right) => {
    const byDate = dailyRecordDate(right, modeKey).localeCompare(dailyRecordDate(left, modeKey));
    if (byDate !== 0) return byDate;
    return String(right.id ?? "").localeCompare(String(left.id ?? ""), undefined, { numeric: true });
  });
}

function mergeDailyLiveRecords(currentRecords, updatedRecords, modeKey, liveDate, completeLiveDate) {
  const retainedRecords = completeLiveDate
    ? currentRecords.filter((record) => dailyRecordDate(record, modeKey).slice(0, 10) !== liveDate)
    : currentRecords;
  const records = [...new Map(
    [...updatedRecords, ...retainedRecords]
      .map((record, index) => [String(record.id ?? `record-${index}`), record])
  ).values()];
  return sortDailyRecords(records, modeKey);
}

async function fetchDailyRecords(modeKey, from, to, sequence, { allPages = true } = {}) {
  const firstResponse = await apiRequest(dailyEndpoint(modeKey, from, to, 1));
  if (sequence !== state.dailyRequestSequence) return null;

  const records = responseCollection(firstResponse, ["movimientos", "deudas", "items", "records"]);
  const lastPage = Math.max(1, Number(responseMeta(firstResponse).last_page || 1));

  for (let page = 2; allPages && page <= lastPage; page += 1) {
    const response = await apiRequest(dailyEndpoint(modeKey, from, to, page));
    if (sequence !== state.dailyRequestSequence) return null;
    records.push(...responseCollection(response, ["movimientos", "deudas", "items", "records"]));
  }

  return {
    complete: allPages || lastPage === 1,
    records: [...new Map(records.map((record, index) => [String(record.id ?? `record-${index}`), record])).values()]
  };
}

async function loadDailyRecords({
  silent = false,
  force = false,
  liveOnly = false,
  highlightedId = null,
  modeKey: requestedMode = null
} = {}) {
  syncDailyTodayPreset();
  const modeKey = requestedMode && MODES[requestedMode] ? requestedMode : currentModeKey();
  const { from, to } = appliedDailyRange();
  const phrase = dailyRangePhrase(from, to);
  const signature = `${modeKey}:${from}:${to}`;
  const canUseLiveOnly = liveOnly
    && state.dailyRecordsSignature === signature
    && dailyRangeIncludesToday();
  const loadSignature = `${signature}:${canUseLiveOnly ? "live" : "full"}`;
  if (!force && state.dailyLoadPromise && state.dailyLoadSignature === loadSignature) {
    return state.dailyLoadPromise;
  }

  const sequence = ++state.dailyRequestSequence;
  state.dailyLoadSignature = loadSignature;
  updateDailyRangeCopy(modeKey);
  elements.dailyPanel.setAttribute("aria-busy", "true");
  if (!silent) {
    elements.dailyRefresh.disabled = true;
    elements.dailyApply.disabled = true;
  }
  if (!silent) {
    setMessage(elements.dailyMessage, `Cargando los registros ${phrase}...`);
    if (!state.dailyRenderedSignature?.startsWith(`${signature}:`)) {
      elements.dailyRows.innerHTML = `<tr><td class="fin-empty-cell" colspan="6">Cargando los registros ${escapeHtml(phrase)}...</td></tr>`;
      state.dailyRenderedSignature = null;
    }
  }

  const loadPromise = (async () => {
    try {
      const liveDate = financeBusinessDate();
      const result = await fetchDailyRecords(
        modeKey,
        canUseLiveOnly ? liveDate : from,
        canUseLiveOnly ? liveDate : to,
        sequence,
        { allPages: !canUseLiveOnly }
      );
      if (sequence !== state.dailyRequestSequence || result === null) return null;

      const records = canUseLiveOnly
        ? mergeDailyLiveRecords(state.dailyRecords, result.records, modeKey, liveDate, result.complete)
        : result.records;
      state.dailyRecords = records;
      state.dailyRecordsSignature = signature;

      const renderedSignature = `${signature}:${JSON.stringify(records)}`;
      if (renderedSignature !== state.dailyRenderedSignature || highlightedId !== null) {
        renderDailyRecords(records, modeKey, highlightedId, from, to);
        state.dailyRenderedSignature = highlightedId === null
          ? renderedSignature
          : `${renderedSignature}:highlight:${highlightedId}`;
      }
      updateDailyLiveStatus(dailyUpdatedTime());
      const announcementSignature = `${signature}:${records.length}`;
      if (!silent || announcementSignature !== state.dailyAnnouncementSignature) {
        setMessage(
          elements.dailyMessage,
          `${records.length} registro${records.length === 1 ? "" : "s"} ${phrase} para la operación seleccionada.`
        );
        state.dailyAnnouncementSignature = announcementSignature;
      }
      state.dailyRefreshDelay = DAILY_REFRESH_INTERVAL;
      markFinanceAccessReady();
      return records;
    } catch (error) {
      if (sequence !== state.dailyRequestSequence) return null;
      updateDailyLiveStatus(null, { failed: true });
      const failureMessage = errorMessage(error, `No se pudieron cargar los registros ${phrase}.`);
      const failureSignature = `error:${signature}:${failureMessage}`;
      if (!silent || state.dailyAnnouncementSignature !== failureSignature) {
        setMessage(elements.dailyMessage, failureMessage, "error");
        state.dailyAnnouncementSignature = failureSignature;
      }
      return null;
    } finally {
      if (sequence === state.dailyRequestSequence) {
        state.dailyLoadPromise = null;
        state.dailyLoadSignature = null;
        elements.dailyPanel.setAttribute("aria-busy", "false");
        elements.dailyRefresh.disabled = false;
        elements.dailyApply.disabled = false;
      }
    }
  })();

  state.dailyLoadPromise = loadPromise;
  return loadPromise;
}

function scheduleDailyRefresh() {
  window.clearTimeout(state.dailyRefreshTimer);
  state.dailyRefreshTimer = window.setTimeout(async () => {
    syncDailyTodayPreset();
    const includesToday = dailyRangeIncludesToday();
    if (state.dailyLiveActive !== includesToday) updateDailyLiveStatus();
    if (!document.hidden && includesToday) {
      const records = await loadDailyRecords({ silent: true, liveOnly: true });
      if (records === null) {
        state.dailyRefreshDelay = Math.min(
          DAILY_REFRESH_MAX_INTERVAL,
          Math.max(DAILY_REFRESH_INTERVAL, state.dailyRefreshDelay * 2)
        );
      }
    }
    scheduleDailyRefresh();
  }, state.dailyRefreshDelay);
}

function scheduleDailyResumeRefresh() {
  window.clearTimeout(state.dailyResumeTimer);
  state.dailyResumeTimer = window.setTimeout(() => {
    syncDailyTodayPreset();
    const includesToday = dailyRangeIncludesToday();
    if (state.dailyLiveActive !== includesToday) updateDailyLiveStatus();
    if (!document.hidden && includesToday) void loadDailyRecords({ silent: true, liveOnly: true });
  }, 150);
}

function dailyFilterError(input, message) {
  input.setCustomValidity(message);
  setMessage(elements.dailyMessage, message, "error");
  input.focus();
  input.reportValidity();
  return null;
}

function readDailyFilterRange() {
  const from = elements.dailyFrom.value;
  const to = elements.dailyTo.value;
  elements.dailyFrom.setCustomValidity("");
  elements.dailyTo.setCustomValidity("");

  if (!isValidFinanceFilterDate(from)) {
    return dailyFilterError(elements.dailyFrom, "Selecciona una fecha válida en Desde.");
  }
  if (!isValidFinanceFilterDate(to)) {
    return dailyFilterError(elements.dailyTo, "Selecciona una fecha válida en Hasta.");
  }
  if (from > to) {
    return dailyFilterError(elements.dailyTo, "La fecha Hasta debe ser igual o posterior a Desde.");
  }

  return { from, to };
}

function clearDailyFilterError() {
  elements.dailyFrom.setCustomValidity("");
  elements.dailyTo.setCustomValidity("");
  if (elements.dailyMessage.classList.contains("is-error")) {
    setMessage(
      elements.dailyMessage,
      `El filtro actual muestra los registros ${dailyRangePhrase()}. Presiona «Aplicar filtro» para consultar las nuevas fechas.`
    );
  }
}

function applyDailyFilters(event) {
  event?.preventDefault();
  const range = readDailyFilterRange();
  if (!range) return;

  const today = financeBusinessDate();
  state.dailyFrom = range.from;
  state.dailyTo = range.to;
  state.dailyFollowsToday = range.from === today && range.to === today;
  state.dailyRefreshDelay = DAILY_REFRESH_INTERVAL;
  updateDailyRangeCopy();
  void loadDailyRecords({ force: true });
}

function showTodayDailyRecords() {
  const today = financeBusinessDate();
  elements.dailyFrom.value = today;
  elements.dailyTo.value = today;
  elements.dailyFrom.setCustomValidity("");
  elements.dailyTo.setCustomValidity("");
  state.dailyFrom = today;
  state.dailyTo = today;
  state.dailyFollowsToday = true;
  state.dailyRefreshDelay = DAILY_REFRESH_INTERVAL;
  updateDailyRangeCopy();
  void loadDailyRecords({ force: true });
}

function idValue(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) && String(value).trim() !== "" ? number : value;
}

function selectedMethod() {
  return state.methods.find((method) => String(method.id) === String(elements.method.value)) || null;
}

function selectedMethodCode() {
  return String(firstDefined(selectedMethod() || {}, ["codigo", "code"], "")).toUpperCase();
}

function populateAccounts() {
  const mode = currentMode();
  const useCredit = usesProviderCredit();
  const providerId = elements.provider.value;
  const cashOnly = selectedMethodCode() === "EFECTIVO";
  const currency = elements.currency.value;
  const ownAccounts = state.accounts.filter((account) => {
    const type = String(firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "")).toUpperCase();
    const accountCurrency = String(firstDefined(account, ["moneda", "currency"], "PEN")).toUpperCase();
    return type === "PROPIA"
      && accountCurrency === currency
      && isActiveRecord(account)
      && (!cashOnly || accountType(account) === "CAJA");
  });
  const externalAccounts = state.accounts.filter((account) => {
    const type = String(firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "")).toUpperCase();
    const accountProviderId = firstDefined(account, ["proveedor_id", "entidad.proveedor_id", "entity.provider_id"], null);
    const accountCurrency = String(firstDefined(account, ["moneda", "currency"], "PEN")).toUpperCase();
    return type === "EXTERNA"
      && accountCurrency === currency
      && isActiveRecord(account)
      && (!cashOnly || accountType(account) === "CAJA")
      && (!providerId || String(accountProviderId) === String(providerId));
  });

  const currentOrigin = elements.origin.value;
  const currentDestination = elements.destination.value;
  fillSelect(elements.origin, ownAccounts, {
    placeholder: ownAccounts.length ? "Selecciona una cuenta propia" : "No hay cuentas propias activas",
    selected: currentOrigin,
    label: accountName
  });

  const effectiveDestination = useCredit ? false : mode.destination;
  const destinationAccounts = effectiveDestination === "EXTERNA" ? externalAccounts : ownAccounts;
  fillSelect(elements.destination, destinationAccounts, {
    placeholder: destinationAccounts.length
      ? effectiveDestination === "EXTERNA" ? "Selecciona una cuenta del proveedor" : "Selecciona una cuenta propia"
      : effectiveDestination === "EXTERNA" ? "No hay cuentas externas para este proveedor" : "No hay cuentas propias activas",
    selected: currentDestination,
    label: accountName
  });
  elements.origin.disabled = !mode.origin || useCredit || !ownAccounts.length;
  elements.destination.disabled = !effectiveDestination || !destinationAccounts.length;
}

function updateMethodConstraints() {
  const mode = currentMode();
  const useCredit = usesProviderCredit();
  const method = selectedMethod();
  const requiresReference = mode.reference !== false && (
    Boolean(mode.referenceRequired)
    || (!useCredit && mode.method !== false && Boolean(firstDefined(method || {}, ["requiere_referencia", "requires_reference"], false)))
  );
  elements.reference.required = requiresReference;
  elements.notes.required = Boolean(mode.notesRequired);
  elements.referenceLabel.innerHTML = requiresReference
    ? `${currentModeKey() === "SALDO_FAVOR_PROVEEDOR" ? "Referencia del saldo anterior" : "Número de operación / referencia"} <b>*</b>`
    : "Número de operación / referencia";
  elements.notesLabel.innerHTML = isManualCustomerDebt()
    ? "Detalle breve <b>*</b>"
    : mode.notesRequired ? "Observaciones <b>*</b>" : "Observaciones";
  elements.reference.placeholder = requiresReference
    ? currentModeKey() === "SALDO_FAVOR_PROVEEDOR"
      ? "Ej: SALDO ANTERIOR JULIO 2026"
      : "Referencia obligatoria para este método"
    : "Ej: OP-384729";
  elements.notes.placeholder = isManualCustomerDebt()
    ? "Ej: Saldo anterior pendiente, origen no identificado"
    : currentModeKey() === "SALDO_FAVOR_PROVEEDOR"
      ? "Explica el origen del saldo que ya se tenía con el proveedor"
      : useCredit
        ? "Detalle de la aplicación del saldo a favor"
        : "Detalle adicional del pago";
  elements.notes.maxLength = isManualCustomerDebt() ? 250 : 500;
  populateAccounts();
}

function providerCreditLabel(record) {
  const code = firstDefined(record, ["codigo", "code"], `Movimiento ${firstDefined(record, ["id"], "")}`);
  const reference = firstDefined(record, ["referencia", "reference"], "Sin referencia");
  const available = movementUnapplied(record);
  return `${code} · ${reference} · disponible ${formatMoney(available, movementCurrency(record))}`;
}

function renderProviderCreditSources(selected = "") {
  elements.providerCreditSourceHelp.classList.remove("is-error");
  fillSelect(elements.providerCreditSource, state.providerCredits, {
    placeholder: state.providerCredits.length ? "Selecciona una fuente de saldo" : "No hay saldo a favor disponible",
    selected,
    label: providerCreditLabel,
    value: (record) => firstDefined(record, ["id", "movimiento.id"], "")
  });
  elements.providerCreditSource.disabled = !state.providerCredits.length || !usesProviderCredit();

  const total = state.providerCredits.reduce((sum, record) => sum + movementUnapplied(record), 0);
  elements.providerCreditSourceHelp.textContent = state.providerCredits.length
    ? `${state.providerCredits.length} fuente${state.providerCredits.length === 1 ? "" : "s"} · saldo total ${formatMoney(total, elements.currency.value)}. Usa una fuente por operación.`
    : elements.provider.value
      ? "Este proveedor no tiene saldo a favor disponible en la moneda seleccionada."
      : "Selecciona un proveedor para consultar su saldo a favor.";
  updateSummary();
}

async function loadProviderCredits() {
  const providerId = elements.provider.value;
  const currency = elements.currency.value;
  const enabled = currentModeKey() === "PAGO_PROVEEDOR";
  const selected = elements.providerCreditSource.value;
  const sequence = ++state.providerCreditRequestSequence;

  if (!enabled || !providerId) {
    state.providerCredits = [];
    renderProviderCreditSources();
    return;
  }

  elements.providerCreditSource.disabled = true;
  elements.providerCreditSourceHelp.textContent = "Consultando saldo a favor...";
  const params = new URLSearchParams({
    proveedor_id: providerId,
    moneda: currency,
    aplicacion_estado: "CON_SALDO",
    per_page: "100"
  });

  try {
    const records = [];
    let page = 1;
    let lastPage = 1;

    do {
      params.set("page", String(page));
      const response = await apiRequest(`/finanzas/movimientos?${params.toString()}`);
      if (sequence !== state.providerCreditRequestSequence) return;
      records.push(...responseCollection(response, ["movimientos", "items", "records"]));
      lastPage = Math.max(1, numericValue(firstDefined(responseMeta(response), ["last_page"], 1), 1));
      page += 1;
    } while (page <= lastPage);

    state.providerCredits = records
      .filter((record) => PROVIDER_CREDIT_TYPES.has(movementType(record)))
      .filter((record) => movementCurrency(record) === currency)
      .filter((record) => movementUnapplied(record) > 0);
    renderProviderCreditSources(selected);
    markFinanceAccessReady();
  } catch (error) {
    if (sequence !== state.providerCreditRequestSequence) return;
    state.providerCredits = [];
    renderProviderCreditSources();
    elements.providerCreditSourceHelp.textContent = errorMessage(error, "No se pudo consultar el saldo a favor.");
    elements.providerCreditSourceHelp.classList.add("is-error");
  }
}

function updateMode({ refreshDaily = true } = {}) {
  const mode = currentMode();
  const modeKey = currentModeKey();
  const useCredit = usesProviderCredit();
  const hasOrigin = Boolean(mode.origin) && !useCredit;
  const hasDestination = Boolean(mode.destination) && !useCredit;
  const hasMethod = mode.method !== false && !useCredit;
  const hasReference = mode.reference !== false && !useCredit;
  const hasApplications = mode.applications !== false;
  updateDailyRangeCopy(modeKey);
  elements.flowBadge.textContent = mode.badge;
  elements.detailsTitle.textContent = ["SALDO_FAVOR_PROVEEDOR", "DEUDA_ANTERIOR_CLIENTE"].includes(modeKey)
    ? "Datos del saldo anterior"
    : "Datos del movimiento";
  elements.summaryTitle.textContent = useCredit
    ? "Uso del saldo a favor"
    : modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Deuda que se registrará"
      : modeKey === "SALDO_FAVOR_PROVEEDOR" ? "Saldo que se registrará" : "Distribución del importe";
  elements.clientField.hidden = !mode.client;
  elements.client.required = mode.clientRequired;
  elements.clientLabel.innerHTML = modeKey === "DEUDA_ANTERIOR_CLIENTE"
    ? "Cliente con la deuda <b>*</b>"
    : modeKey === "REEMBOLSO_CLIENTE"
      ? "Cliente que recibe <b>*</b>"
      : mode.clientRequired ? "Cliente que paga <b>*</b>" : "Cliente asignado (opcional)";
  elements.clientHelp.textContent = modeKey === "DEUDA_ANTERIOR_CLIENTE"
    ? "El monto se sumará a la cuenta por cobrar de este cliente."
    : modeKey === "COBRO_MINORISTA"
      ? "Déjalo vacío cuando la venta minorista no tenga cliente asignado."
      : "Se mostrarán sus documentos pendientes.";
  elements.providerField.hidden = !mode.provider;
  elements.provider.required = mode.provider;
  elements.providerPaymentSourcePanel.hidden = modeKey !== "PAGO_PROVEEDOR";
  elements.providerCreditSourceField.hidden = !useCredit;
  elements.providerCreditSource.required = useCredit;
  elements.originField.hidden = !hasOrigin;
  elements.origin.required = hasOrigin;
  elements.destinationField.hidden = !hasDestination;
  elements.destination.required = hasDestination;
  elements.destinationLabel.innerHTML = `${mode.destinationLabel} <b>*</b>`;
  elements.destinationHelp.textContent = mode.destinationHelp;
  elements.dateField.hidden = useCredit;
  elements.date.required = !useCredit;
  elements.dateLabel.innerHTML = modeKey === "DEUDA_ANTERIOR_CLIENTE"
    ? "Fecha de la deuda <b>*</b>"
    : "Fecha y hora <b>*</b>";
  elements.methodField.hidden = !hasMethod;
  elements.method.required = hasMethod;
  elements.referenceField.hidden = !hasReference;
  elements.cxcPanel.hidden = !mode.cxc;
  elements.cxcTitle.textContent = modeKey === "REEMBOLSO_CLIENTE"
    ? "Abonos por devolver al cliente"
    : "Deudas del cliente";
  elements.cxpPanel.hidden = !mode.cxp;
  elements.cxcSummaryLine.hidden = !mode.cxc;
  elements.cxpSummaryLine.hidden = !mode.cxp;
  elements.applicationsPanel.hidden = !hasApplications;
  elements.applicationColumns.classList.toggle("is-single", !(mode.cxc && mode.cxp));
  elements.applicationHint.textContent = useCredit
    ? "Esta operación solo distribuye un saldo ya existente; no genera una nueva salida de dinero."
    : mode.hint;
  elements.providerCreditAvailableLine.hidden = !useCredit;
  if (useCredit && selectedProviderCreditAvailable() > 0) {
    elements.amount.max = selectedProviderCreditAvailable().toFixed(2);
  } else {
    elements.amount.removeAttribute("max");
  }
  elements.amountLabel.innerHTML = modeKey === "DEUDA_ANTERIOR_CLIENTE"
    ? "Monto adeudado <b>*</b>"
    : useCredit ? "Importe a usar <b>*</b>" : "Importe <b>*</b>";
  elements.totalLabel.textContent = useCredit
    ? "Importe indicado"
    : modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Deuda pendiente"
      : modeKey === "SALDO_FAVOR_PROVEEDOR" ? "Saldo anterior" : "Importe del movimiento";
  elements.unappliedLabel.textContent = useCredit
    ? "Quedará a favor"
    : modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Saldo pendiente"
      : modeKey === "PAGO_DIRECTO"
        ? "Pendiente de conciliar"
        : PROVIDER_CREDIT_TYPES.has(modeKey) ? "Quedará a nuestro favor" : "Sin aplicar";
  elements.save.textContent = useCredit
    ? "Aplicar saldo a favor"
    : modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Registrar deuda anterior"
      : modeKey === "SALDO_FAVOR_PROVEEDOR" ? "Registrar saldo anterior" : "Registrar movimiento";
  elements.currencyPrefix.textContent = currencyInputPrefix();

  if (!mode.client) {
    elements.client.value = "";
    clearApplications("CXC");
  }
  if (!mode.provider) {
    elements.provider.value = "";
    clearApplications("CXP");
  }
  if (!hasApplications) {
    clearApplications("CXC");
    clearApplications("CXP");
  }

  updateMethodConstraints();
  renderDebt("CXC");
  renderDebt("CXP");
  updateSummary();
  void loadVisiblePortfolio();
  void loadProviderCredits();
  if (refreshDaily) void loadDailyRecords();
}

function normalizeDebt(rawDebt, side) {
  const document = rawDebt.comprobante || rawDebt.document || rawDebt;
  const serie = firstDefined(document, ["serie", "series"], "");
  const number = firstDefined(document, ["numero", "number", "correlativo"], "");
  const displayNumber = firstDefined(rawDebt, ["codigo", "numero_comprobante", "document_number"],
    [serie, number].filter(Boolean).join("-") || `Documento ${firstDefined(document, ["id"], "")}`);
  const pending = numericValue(firstDefined(rawDebt, [
    "saldo_pendiente",
    "importe_pendiente",
    "saldo",
    "balance",
    "pendiente",
    "comprobante.saldo_pendiente"
  ], firstDefined(document, ["saldo_pendiente", "importe_total", "total"], 0)));

  return {
    raw: rawDebt,
    id: firstDefined(rawDebt, ["comprobante_id", "document_id", "comprobante.id", "document.id", "id"]),
    side,
    number: String(displayNumber),
    pending,
    total: numericValue(firstDefined(rawDebt, ["importe_total", "total", "comprobante.importe_total", "document.total"], pending)),
    date: firstDefined(rawDebt, ["fecha_emision", "fecha", "created_at", "comprobante.fecha_emision"], null),
    ticket: String(firstDefined(rawDebt, ["ticket.numero", "ticket_number", "referencia", "origen_referencia", "tickets.0.numero", "tickets.0.codigo", "tickets.0.id", "tickets.0"], "")),
    detail: String(firstDefined(rawDebt, ["detalle", "detail", "descripcion", "description"], "")),
    status: String(firstDefined(rawDebt, ["estado", "status", "comprobante.estado"], "PENDIENTE"))
  };
}

function sideElements(side) {
  return side === "CXC"
    ? { list: elements.cxcList, message: elements.cxcMessage, total: elements.cxcAvailable }
    : { list: elements.cxpList, message: elements.cxpMessage, total: elements.cxpAvailable };
}

function clearApplications(side) {
  state.applications[side].clear();
  state.debts[side] = [];
}

function appliedTotal(side) {
  return [...state.applications[side].values()].reduce((total, amount) => total + numericValue(amount), 0);
}

function movementAmount() {
  return Math.max(0, numericValue(elements.amount.value));
}

function reconcileApplicationsWithAmount(side) {
  let available = movementAmount();

  for (const [id, applied] of state.applications[side].entries()) {
    const debt = state.debts[side].find((item) => String(item.id) === String(id));
    const adjusted = Math.min(numericValue(applied), debt?.pending || 0, available);

    if (adjusted > 0) {
      state.applications[side].set(id, adjusted);
      available -= adjusted;
    } else {
      state.applications[side].delete(id);
    }
  }
}

function renderDebt(side) {
  const refs = sideElements(side);
  const records = state.debts[side];
  const selected = state.applications[side];
  const canApply = movementAmount() > 0;
  const totalPending = records.reduce((total, debt) => total + debt.pending, 0);
  refs.total.textContent = `Pendiente ${formatMoney(totalPending, elements.currency.value)}`;

  if (!records.length) {
    refs.list.innerHTML = "";
    return;
  }

  refs.list.innerHTML = records.map((debt) => {
    const checked = selected.has(String(debt.id));
    const applied = checked ? numericValue(selected.get(String(debt.id))) : 0;
    return `
      <label class="fin-debt-item ${checked ? "is-selected" : ""}">
        <input class="fin-debt-check" type="checkbox" data-debt-toggle="${side}" data-debt-id="${escapeHtml(debt.id)}" ${checked ? "checked" : ""} ${canApply ? "" : "disabled"} title="${canApply ? "" : "Ingresa primero el importe del movimiento"}">
        <span class="fin-debt-copy">
          <strong>${escapeHtml(debt.number)}${debt.ticket ? ` · Ticket ${escapeHtml(debt.ticket)}` : ""}</strong>
          <small>${escapeHtml(formatDateTime(debt.date))} · ${escapeHtml(debt.status)} · saldo ${escapeHtml(formatMoney(debt.pending, elements.currency.value))}</small>
          ${debt.detail ? `<small>${escapeHtml(debt.detail)}</small>` : ""}
        </span>
        <span class="fin-debt-amount">${escapeHtml(currencyInputPrefix())}<input type="number" min="0.01" max="${debt.pending}" step="0.01" inputmode="decimal" data-debt-amount="${side}" data-debt-id="${escapeHtml(debt.id)}" value="${applied ? applied.toFixed(2) : ""}" ${checked ? "" : "disabled"} aria-label="Importe aplicado a ${escapeHtml(debt.number)}"></span>
      </label>`;
  }).join("");
}

function updateSummary() {
  const mode = currentMode();
  const modeKey = currentModeKey();
  const useCredit = usesProviderCredit();
  const amount = movementAmount();
  const cxc = appliedTotal("CXC");
  const cxp = appliedTotal("CXP");
  const appliedMaximum = Math.max(mode.cxc ? cxc : 0, mode.cxp ? cxp : 0);
  const reconciled = modeKey === "PAGO_DIRECTO"
    ? Math.min(cxc, cxp)
    : appliedMaximum;
  const creditAvailable = selectedProviderCreditAvailable();
  const unapplied = useCredit
    ? Math.max(0, creditAvailable - cxp)
    : Math.max(0, amount - reconciled);
  const currency = elements.currency.value;

  elements.providerCreditAvailable.textContent = formatMoney(creditAvailable, currency);
  elements.total.textContent = formatMoney(amount, currency);
  elements.cxcApplied.textContent = formatMoney(cxc, currency);
  elements.cxpApplied.textContent = formatMoney(cxp, currency);
  elements.unapplied.textContent = formatMoney(unapplied, currency);
  elements.unapplied.classList.toggle("is-error", appliedMaximum > amount || (useCredit && amount > creditAvailable));
  elements.applicationsPanel.classList.toggle("is-amount-required", amount <= 0);
  elements.applicationsInstructions.textContent = amount > 0
    ? useCredit
      ? "Marca las compras que deseas cancelar con esta fuente. El total aplicado debe coincidir con el importe a usar."
      : modeKey === "PAGO_DIRECTO"
        ? "Opcional: marca las ventas o compras conocidas. Si todavía no cargaste las deudas, registra el pago sin seleccionar documentos."
        : "Marca las ventas o compras que cancela este movimiento. Los abonos parciales son válidos."
    : "Primero ingresa un importe mayor a cero para poder seleccionar las deudas. Luego podrás registrar abonos parciales.";
}

function toggleDebt(side, id, checked) {
  const key = String(id);
  if (!checked) {
    state.applications[side].delete(key);
  } else {
    const amount = movementAmount();
    if (amount <= 0) {
      elements.amount.focus();
      setMessage(elements.message, "Ingresa primero el importe del movimiento antes de seleccionar una deuda.", "error");
      renderDebt(side);
      return;
    }
    const debt = state.debts[side].find((item) => String(item.id) === key);
    const remaining = Math.max(0, amount - appliedTotal(side));
    if (remaining <= 0) {
      setMessage(elements.message, "El importe del movimiento ya está aplicado por completo.", "error");
      renderDebt(side);
      return;
    }
    state.applications[side].set(key, Math.min(debt?.pending || 0, remaining));
    setMessage(elements.message);
  }
  renderDebt(side);
  updateSummary();
}

function updateDebtAmount(side, id, value, input) {
  const key = String(id);
  if (!state.applications[side].has(key)) return;
  const debt = state.debts[side].find((item) => String(item.id) === key);
  const otherApplied = appliedTotal(side) - numericValue(state.applications[side].get(key));
  const maximum = Math.max(0, Math.min(debt?.pending || 0, movementAmount() - otherApplied));
  const requested = Math.max(0, numericValue(value));
  const adjusted = Math.min(requested, maximum);
  state.applications[side].set(key, adjusted);
  input.max = maximum.toFixed(2);
  if (requested > maximum) input.value = maximum.toFixed(2);
  updateSummary();
}

async function loadPortfolio(side) {
  const mode = currentMode();
  const refs = sideElements(side);
  const partyId = side === "CXC" ? elements.client.value : elements.provider.value;
  const enabled = side === "CXC" ? mode.cxc : mode.cxp;
  const partyLabel = side === "CXC" ? "cliente" : "proveedor";

  if (!enabled || !partyId) {
    clearApplications(side);
    renderDebt(side);
    setMessage(refs.message, enabled ? `Selecciona un ${partyLabel} para consultar su cartera.` : "");
    updateSummary();
    return;
  }

  const sequence = ++state.requestSequence[side];
  setMessage(refs.message, "Consultando documentos pendientes...");
  const params = new URLSearchParams({
    lado: side,
    solo_pendientes: "true",
    moneda: elements.currency.value,
    per_page: "100"
  });
  params.set(side === "CXC" ? "cliente_id" : "proveedor_id", partyId);
  if (mode.nature) params.set("naturaleza", mode.nature);

  try {
    const response = await apiRequest(`/finanzas/cartera?${params.toString()}`);
    if (sequence !== state.requestSequence[side]) return;
    state.applications[side].clear();
    state.debts[side] = responseCollection(response, [
      "cartera",
      "comprobantes",
      "documentos",
      "items",
      side,
      side.toLowerCase()
    ]).map((record) => normalizeDebt(record, side)).filter((record) => record.id && record.pending > 0);
    renderDebt(side);
    setMessage(refs.message, state.debts[side].length
      ? `${state.debts[side].length} documento${state.debts[side].length === 1 ? "" : "s"} pendiente${state.debts[side].length === 1 ? "" : "s"}`
      : currentModeKey() === "PAGO_DIRECTO"
        ? `Este ${partyLabel} no tiene documentos pendientes. Puedes registrar el pago igualmente.`
        : `Este ${partyLabel} no tiene documentos pendientes.`);
    updateSummary();
    markFinanceAccessReady();
  } catch (error) {
    if (sequence !== state.requestSequence[side]) return;
    clearApplications(side);
    renderDebt(side);
    setMessage(refs.message, errorMessage(error, "No se pudo consultar la cartera."), "error");
    updateSummary();
  }
}

async function loadVisiblePortfolio() {
  const mode = currentMode();
  const tasks = [];
  if (mode.cxc) tasks.push(loadPortfolio("CXC"));
  if (mode.cxp) tasks.push(loadPortfolio("CXP"));
  await Promise.allSettled(tasks);
}

async function loadCatalogData() {
  try {
    const [catalogResponse, entityResponse] = await Promise.all([
      apiRequest("/finanzas/catalogo"),
      apiRequest("/finanzas/entidades?include=cuentas&estado=ACTIVO&per_page=100")
    ]);

    const selected = {
      client: elements.client.value || (!queryPrefill.applied ? queryPrefill.clientId : ""),
      provider: elements.provider.value || (!queryPrefill.applied ? queryPrefill.providerId : ""),
      method: elements.method.value
    };
    state.clients = responseCollection(catalogResponse, ["clientes", "clients", "catalogo.clientes"]);
    state.providers = responseCollection(catalogResponse, ["proveedores", "providers", "catalogo.proveedores"]);
    state.methods = responseCollection(catalogResponse, ["metodos_pago", "metodos", "payment_methods", "catalogo.metodos_pago"]);
    state.entities = responseCollection(entityResponse, ["entidades", "entities", "items"]).map(normalizeEntity);
    state.accounts = accountListFromEntities(state.entities).filter(isActiveRecord);

    fillSelect(elements.client, state.clients, { placeholder: "Selecciona un cliente", selected: selected.client });
    fillSelect(elements.provider, state.providers, { placeholder: "Selecciona un proveedor", selected: selected.provider });
    fillSelect(elements.method, state.methods, { placeholder: "Selecciona un método", selected: selected.method });
    queryPrefill.applied = true;
    updateMethodConstraints();
    updateMode();
    markFinanceAccessReady();

    if (!state.methods.length) setMessage(elements.message, "No hay métodos de pago activos. Revisa el catálogo financiero.", "error");
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudieron cargar los datos del formulario."), "error");
  }
}

function applicationsPayload() {
  return ["CXC", "CXP"].flatMap((side) => [...state.applications[side].entries()]
    .filter(([, amount]) => numericValue(amount) > 0)
    .map(([documentId, amount]) => ({
      lado: side,
      comprobante_id: idValue(documentId),
      importe_aplicado: numericValue(amount).toFixed(2)
    })));
}

function validateApplications(side) {
  for (const [documentId, applied] of state.applications[side].entries()) {
    const debt = state.debts[side].find((item) => String(item.id) === String(documentId));
    const amount = numericValue(applied);

    if (amount <= 0) throw new Error(`Ingresa un importe válido para el documento ${debt?.number || documentId}.`);
    if (debt && amount > debt.pending + .001) {
      throw new Error(`El abono a ${debt.number} supera su saldo pendiente.`);
    }
  }
}

function movementPayload() {
  const modeKey = currentModeKey();
  const mode = currentMode();
  const amount = numericValue(elements.amount.value);
  const cxc = appliedTotal("CXC");
  const cxp = appliedTotal("CXP");

  if (mode.clientRequired && !elements.client.value) {
    elements.client.focus();
    throw new Error(modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Selecciona el cliente que tiene la deuda."
      : "Selecciona el cliente que realizó el pago.");
  }
  if (mode.provider && !elements.provider.value) {
    elements.provider.focus();
    throw new Error("Selecciona el proveedor beneficiario.");
  }
  if (mode.origin && !elements.origin.value) {
    elements.origin.focus();
    throw new Error("Selecciona la cuenta propia desde donde salió el dinero.");
  }
  if (mode.destination && !elements.destination.value) {
    elements.destination.focus();
    throw new Error("Selecciona la cuenta que recibió el dinero.");
  }
  if (!elements.date.value) {
    elements.date.focus();
    throw new Error(modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Indica la fecha de la deuda."
      : "Indica la fecha y hora del movimiento.");
  }
  if (mode.method !== false && !elements.method.value) {
    elements.method.focus();
    throw new Error("Selecciona el método de pago.");
  }
  if (elements.reference.required && !elements.reference.value.trim()) {
    elements.reference.focus();
    throw new Error(modeKey === "SALDO_FAVOR_PROVEEDOR"
      ? "Ingresa una referencia para identificar el saldo anterior."
      : "Ingresa el número de operación o referencia requerido por el método de pago.");
  }
  if (mode.notesRequired && !elements.notes.value.trim()) {
    elements.notes.focus();
    throw new Error(modeKey === "DEUDA_ANTERIOR_CLIENTE"
      ? "Escribe un detalle breve para identificar la deuda anterior."
      : "Explica en observaciones el origen del saldo anterior.");
  }
  if (amount <= 0) {
    elements.amount.focus();
    throw new Error("Ingresa un importe mayor a cero.");
  }
  validateApplications("CXC");
  validateApplications("CXP");
  if (mode.cxc && cxc > amount + .001) throw new Error("Lo aplicado a cuentas por cobrar supera el importe del movimiento.");
  if (mode.cxp && cxp > amount + .001) throw new Error("Lo aplicado a cuentas por pagar supera el importe del movimiento.");
  if (modeKey === "REEMBOLSO_CLIENTE" && (cxc <= 0 || Math.abs(cxc - amount) > .001)) {
    throw new Error("El reembolso debe aplicarse completamente a uno o mas abonos del cliente.");
  }

  if (modeKey === "DEUDA_ANTERIOR_CLIENTE") {
    return {
      idempotency_key: state.idempotencyKey,
      cliente_id: idValue(elements.client.value),
      fecha_emision: elements.date.value.slice(0, 10),
      moneda: elements.currency.value,
      importe: amount.toFixed(2),
      detalle: elements.notes.value.trim()
    };
  }

  const payload = {
    idempotency_key: state.idempotencyKey,
    tipo: modeKey,
    fecha_hora: elements.date.value,
    proveedor_id: mode.provider ? idValue(elements.provider.value) : null,
    moneda: elements.currency.value,
    importe: amount.toFixed(2),
    referencia: optionalString(elements.reference.value),
    observaciones: optionalString(elements.notes.value)
  };

  if (modeKey === "SALDO_FAVOR_PROVEEDOR") return payload;

  return {
    ...payload,
    cliente_id: mode.client && elements.client.value ? idValue(elements.client.value) : null,
    cuenta_origen_id: mode.origin ? idValue(elements.origin.value) : null,
    cuenta_destino_id: mode.destination ? idValue(elements.destination.value) : null,
    metodo_pago_id: idValue(elements.method.value),
    aplicaciones: applicationsPayload()
  };
}

function providerCreditApplicationPayload() {
  const providerId = elements.provider.value;
  const source = selectedProviderCredit();
  const amount = movementAmount();
  const applied = appliedTotal("CXP");
  const available = selectedProviderCreditAvailable();

  if (!providerId) {
    elements.provider.focus();
    throw new Error("Selecciona el proveedor cuyo saldo deseas usar.");
  }
  if (!source) {
    elements.providerCreditSource.focus();
    throw new Error("Selecciona una fuente de saldo a favor disponible.");
  }
  if (amount <= 0) {
    elements.amount.focus();
    throw new Error("Ingresa el importe de saldo que deseas usar.");
  }
  if (amount > available + .001) {
    elements.amount.focus();
    throw new Error("El importe supera el saldo disponible de la fuente seleccionada.");
  }
  validateApplications("CXP");
  if (applied <= 0) throw new Error("Selecciona al menos una compra pendiente para usar el saldo a favor.");
  if (Math.abs(applied - amount) > .001) {
    throw new Error("El importe aplicado a compras debe coincidir con el importe de saldo que deseas usar.");
  }

  return {
    paymentId: firstDefined(source, ["id", "movimiento.id"], null),
    body: {
      idempotency_key: state.idempotencyKey,
      aplicaciones: [...state.applications.CXP.entries()].map(([documentId, applicationAmount]) => ({
        comprobante_id: idValue(documentId),
        importe_aplicado: numericValue(applicationAmount).toFixed(2)
      })),
      observaciones: optionalString(elements.notes.value) || "Aplicación de saldo a favor desde Pago a proveedores"
    }
  };
}

function resetMovement({
  keepMessage = false,
  repeatContext = null,
  refreshDaily = true,
  selectedMode = currentModeKey()
} = {}) {
  elements.form.reset();
  const selectedModeInput = document.querySelector(`[name="financeMovementType"][value="${selectedMode}"]`);
  if (selectedModeInput) selectedModeInput.checked = true;
  if (repeatContext) {
    ["currency", "client", "provider", "method", "date"].forEach((field) => {
      if (field in repeatContext) elements[field].value = repeatContext[field];
    });
  } else {
    elements.date.value = toLocalDateTimeValue();
  }
  state.debts = { CXC: [], CXP: [] };
  state.providerCredits = [];
  state.applications.CXC.clear();
  state.applications.CXP.clear();
  state.idempotencyKey = createIdempotencyKey();
  if (!keepMessage) setMessage(elements.message);
  [elements.cxcMessage, elements.cxpMessage].forEach((message) => setMessage(message));
  updateMethodConstraints();
  if (repeatContext && "destination" in repeatContext) {
    elements.destination.value = repeatContext.destination;
  }
  updateMode({ refreshDaily });
}

async function saveMovement(event) {
  event.preventDefault();
  if (state.saving) return;
  setMessage(elements.message);
  const savedMode = currentModeKey();
  const manualCustomerDebt = isManualCustomerDebt();
  let savedRecordId = null;
  let savedRecordDate = String(elements.date.value || "").slice(0, 10);
  let repeatContext = null;
  let refreshDailyAfterSave = false;

  try {
    const creditApplication = usesProviderCredit() ? providerCreditApplicationPayload() : null;
    const payload = creditApplication ? null : movementPayload();
    repeatContext = movementRepeatContext(savedMode, {
      currency: elements.currency.value,
      client: elements.client.value,
      provider: elements.provider.value,
      method: elements.method.value,
      date: elements.date.value,
      destination: elements.destination.value
    });
    state.saving = true;
    elements.save.disabled = true;
    elements.reset.disabled = true;
    elements.typeInputs.forEach((input) => { input.disabled = true; });
    elements.save.textContent = creditApplication
      ? "Aplicando saldo..."
      : manualCustomerDebt
        ? "Registrando deuda..."
        : currentModeKey() === "SALDO_FAVOR_PROVEEDOR"
        ? "Registrando saldo..."
        : "Registrando...";

    const endpoint = creditApplication
      ? `/finanzas/movimientos/${encodeURIComponent(creditApplication.paymentId)}/aplicaciones`
      : manualCustomerDebt ? "/finanzas/deudas-clientes" : "/finanzas/movimientos";
    const response = await apiRequest(endpoint, {
      method: "POST",
      body: JSON.stringify(creditApplication?.body || payload)
    });
    const movementNumber = firstDefined(response, ["data.numero", "data.id", "numero", "id"], null);
    savedRecordId = firstDefined(response, ["data.id", "id"], null);
    savedRecordDate = String(firstDefined(response, [
      "data.fecha_hora",
      "data.fecha_emision",
      "fecha_hora",
      "fecha_emision"
    ], savedRecordDate) || "").slice(0, 10);
    resetMovement({
      keepMessage: true,
      repeatContext,
      refreshDaily: false,
      selectedMode: savedMode
    });
    setMessage(
      elements.message,
      response?.message || `${manualCustomerDebt ? "Deuda" : "Movimiento"}${movementNumber ? ` #${movementNumber}` : ""} registrado correctamente.`,
      "success"
    );
    dailyChannel?.postMessage({ type: "financial-movement-updated", mode: savedMode, date: savedRecordDate });
    refreshDailyAfterSave = true;
  } catch (error) {
    setMessage(
      elements.message,
      errorMessage(error, manualCustomerDebt ? "No se pudo registrar la deuda anterior." : "No se pudo registrar el movimiento."),
      "error"
    );
  } finally {
    state.saving = false;
    elements.save.disabled = false;
    elements.reset.disabled = false;
    elements.typeInputs.forEach((input) => { input.disabled = false; });
    updateMode({ refreshDaily: false });
  }

  if (refreshDailyAfterSave) {
    void loadDailyRecords({
      force: true,
      highlightedId: dailyRangeContains(savedRecordDate) ? savedRecordId : null,
      modeKey: savedMode
    });
  }
}

elements.typeInputs.forEach((input) => input.addEventListener("change", updateMode));
elements.providerPaymentSourceInputs.forEach((input) => input.addEventListener("change", () => {
  clearApplications("CXP");
  setMessage(elements.message);
  updateMode({ refreshDaily: false });
}));
elements.client.addEventListener("change", () => {
  clearApplications("CXC");
  void loadPortfolio("CXC");
});
elements.provider.addEventListener("change", () => {
  clearApplications("CXP");
  populateAccounts();
  void loadPortfolio("CXP");
  void loadProviderCredits();
});
elements.providerCreditSource.addEventListener("change", () => {
  clearApplications("CXP");
  const available = selectedProviderCreditAvailable();
  elements.amount.max = available > 0 ? available.toFixed(2) : "";
  if (movementAmount() > available) elements.amount.value = available > 0 ? available.toFixed(2) : "";
  renderDebt("CXP");
  setMessage(elements.message);
  updateSummary();
});
elements.amount.addEventListener("input", () => {
  reconcileApplicationsWithAmount("CXC");
  reconcileApplicationsWithAmount("CXP");
  renderDebt("CXC");
  renderDebt("CXP");
  setMessage(elements.message);
  updateSummary();
});
elements.method.addEventListener("change", updateMethodConstraints);
elements.currency.addEventListener("change", () => {
  clearApplications("CXC");
  clearApplications("CXP");
  populateAccounts();
  elements.currencyPrefix.textContent = currencyInputPrefix();
  void loadVisiblePortfolio();
  void loadProviderCredits();
});
elements.refresh.addEventListener("click", loadVisiblePortfolio);
elements.dailyRefresh.addEventListener("click", () => void loadDailyRecords({ force: true }));
elements.dailyFilters.addEventListener("submit", applyDailyFilters);
elements.dailyFrom.addEventListener("input", clearDailyFilterError);
elements.dailyTo.addEventListener("input", clearDailyFilterError);
elements.dailyToday.addEventListener("click", showTodayDailyRecords);
elements.form.addEventListener("submit", saveMovement);
elements.reset.addEventListener("click", () => resetMovement());

elements.applicationsPanel.addEventListener("change", (event) => {
  const toggle = event.target.closest("[data-debt-toggle]");
  if (toggle) toggleDebt(toggle.dataset.debtToggle, toggle.dataset.debtId, toggle.checked);
});

elements.applicationsPanel.addEventListener("input", (event) => {
  const input = event.target.closest("[data-debt-amount]");
  if (input) updateDebtAmount(input.dataset.debtAmount, input.dataset.debtId, input.value, input);
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) scheduleDailyResumeRefresh();
});
window.addEventListener("focus", scheduleDailyResumeRefresh);
dailyChannel?.addEventListener("message", (event) => {
  if (
    event.data?.type === "financial-movement-updated"
    && (!event.data.mode || event.data.mode === currentModeKey())
    && (!event.data.date || dailyRangeContains(event.data.date))
  ) {
    void loadDailyRecords({ silent: true, force: true });
  }
});
window.addEventListener("beforeunload", () => {
  window.clearTimeout(state.dailyRefreshTimer);
  window.clearTimeout(state.dailyResumeTimer);
  dailyChannel?.close();
});

initFinanceAccess(loadCatalogData);
elements.date.value = toLocalDateTimeValue();
initializeDailyRange();
if (queryPrefill.type) {
  const requestedMode = document.querySelector(`[name="financeMovementType"][value="${queryPrefill.type}"]`);
  if (requestedMode) requestedMode.checked = true;
}
updateMode();
scheduleDailyRefresh();
void loadCatalogData();
