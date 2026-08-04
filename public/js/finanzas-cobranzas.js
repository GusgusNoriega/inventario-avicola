import { apiRequest } from "./api-client.js";
import {
  collectionReconciliation,
  createPendingAssignmentRetrySnapshot,
  isDeterministicAssignmentErrorStatus,
  MAX_PENDING_ASSIGNMENT_DETAILS,
  pendingAssignmentReconciliation
} from "./finanzas-cobranzas-calculos.js";
import {
  createIdempotencyKey,
  dataRoot,
  errorMessage,
  escapeHtml,
  firstDefined,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  responseCollection,
  responseMeta,
  setMessage,
  toLocalDateTimeValue
} from "./finanzas-common.js";

const byId = (id) => document.getElementById(id);
const elements = {
  form: byId("collectionForm"),
  collector: byId("collectionCollector"),
  manageCollectors: byId("collectionManageCollectors"),
  dateTime: byId("collectionDateTime"),
  destination: byId("collectionDestination"),
  destinationHelp: byId("collectionDestinationHelp"),
  destinationNotice: byId("collectionDestinationNotice"),
  total: byId("collectionTotal"),
  currency: byId("collectionCurrency"),
  currencyPrefix: byId("collectionCurrencyPrefix"),
  reference: byId("collectionReference"),
  notes: byId("collectionNotes"),
  addDetail: byId("collectionAddDetail"),
  details: byId("collectionDetails"),
  detailsMessage: byId("collectionDetailsMessage"),
  summaryTotal: byId("collectionSummaryTotal"),
  summaryDetails: byId("collectionSummaryDetails"),
  summaryCount: byId("collectionSummaryCount"),
  differenceLine: byId("collectionDifferenceLine"),
  differenceLabel: byId("collectionDifferenceLabel"),
  summaryDifference: byId("collectionSummaryDifference"),
  pendingConfirmationWrap: byId("collectionPendingConfirmationWrap"),
  pendingConfirmation: byId("collectionPendingConfirmation"),
  pendingConfirmationText: byId("collectionPendingConfirmationText"),
  summaryHint: byId("collectionSummaryHint"),
  message: byId("collectionMessage"),
  save: byId("collectionSave"),
  reset: byId("collectionReset"),
  refresh: byId("collectionRefresh"),
  filters: byId("collectionFilters"),
  filterFrom: byId("collectionFilterFrom"),
  filterTo: byId("collectionFilterTo"),
  filterCollector: byId("collectionFilterCollector"),
  filterStatus: byId("collectionFilterStatus"),
  filterReconciliation: byId("collectionFilterReconciliation"),
  filterSearch: byId("collectionFilterSearch"),
  clearFilters: byId("collectionClearFilters"),
  listMessage: byId("collectionListMessage"),
  rows: byId("collectionRows"),
  previous: byId("collectionPrevious"),
  next: byId("collectionNext"),
  page: byId("collectionPage"),
  collectorDialog: byId("collectorDialog"),
  collectorForm: byId("collectorForm"),
  collectorId: byId("collectorId"),
  collectorName: byId("collectorName"),
  collectorFormTitle: byId("collectorFormTitle"),
  collectorEditBadge: byId("collectorEditBadge"),
  collectorFormMessage: byId("collectorFormMessage"),
  collectorSave: byId("collectorSave"),
  collectorCancel: byId("collectorCancel"),
  collectorListMessage: byId("collectorListMessage"),
  collectorList: byId("collectorList"),
  collectorCount: byId("collectorCount"),
  detailDialog: byId("collectionDetailDialog"),
  detailTitle: byId("collectionDetailTitle"),
  detailMessage: byId("collectionDetailMessage"),
  detailContent: byId("collectionDetailContent"),
  assignDialog: byId("collectionAssignDialog"),
  assignForm: byId("collectionAssignForm"),
  assignTitle: byId("collectionAssignTitle"),
  assignIntro: byId("collectionAssignIntro"),
  assignFacts: byId("collectionAssignFacts"),
  assignAddDetail: byId("collectionAssignAddDetail"),
  assignDetails: byId("collectionAssignDetails"),
  assignAvailable: byId("collectionAssignAvailable"),
  assignTotal: byId("collectionAssignTotal"),
  assignRemainingLine: byId("collectionAssignRemainingLine"),
  assignRemaining: byId("collectionAssignRemaining"),
  assignHint: byId("collectionAssignHint"),
  assignMessage: byId("collectionAssignMessage"),
  assignSubmit: byId("collectionAssignSubmit"),
  voidDialog: byId("collectionVoidDialog"),
  voidForm: byId("collectionVoidForm"),
  voidDescription: byId("collectionVoidDescription"),
  voidReason: byId("collectionVoidReason"),
  voidMessage: byId("collectionVoidMessage"),
  voidSubmit: byId("collectionVoidSubmit")
};

const permissions = {
  manage: document.body.dataset.canManageCollections === "1",
  void: document.body.dataset.canVoidCollections === "1"
};

const state = {
  timezone: "America/Bogota",
  defaultCurrency: "PEN",
  collectors: [],
  clients: [],
  accounts: [],
  details: [],
  nextDetailKey: 1,
  idempotencyKey: createIdempotencyKey(),
  collections: new Map(),
  collectionRevision: 0,
  page: 1,
  lastPage: 1,
  loadingList: false,
  listLoadPromise: null,
  listReloadPromise: null,
  listReloadRequested: false,
  listReloadSilent: true,
  saving: false,
  collectorSaving: false,
  collectorUpdatingId: null,
  assignId: null,
  assignRecord: null,
  assignAvailableCents: 0,
  assignDetails: [],
  nextAssignDetailKey: 1,
  assignIdempotencyKey: null,
  assignRetryLocked: false,
  assignRetryAttempts: new Map(),
  assigning: false,
  voidId: null,
  voiding: false
};

function normalizedStatus(value, fallback = "ACTIVO") {
  return String(value || fallback).trim().toUpperCase();
}

function isActiveCollector(collector) {
  return !["INACTIVO", "INACTIVA", "ANULADO", "ANULADA", "0", "FALSE"].includes(
    normalizedStatus(collector?.estado, "ACTIVO")
  );
}

function normalizeCollector(record) {
  return {
    ...record,
    id: Number(firstDefined(record, ["id", "cobrador_id"], 0)),
    nombre: String(firstDefined(record, ["nombre", "name"], "Cobrador sin nombre")),
    estado: normalizedStatus(firstDefined(record, ["estado", "status"], "ACTIVO"))
  };
}

function normalizeClient(record) {
  return {
    ...record,
    id: Number(firstDefined(record, ["id", "cliente_id"], 0)),
    nombre: String(firstDefined(record, ["nombre", "nombre_razon_social", "name"], "Cliente sin nombre")),
    documento: String(firstDefined(record, ["numero_documento", "documento", "document"], "") || "")
  };
}

function normalizeAccount(record) {
  const entityType = normalizedStatus(firstDefined(record, [
    "entidad_tipo",
    "tipo_entidad",
    "entidad.tipo",
    "entity_type"
  ], "PROPIA"), "PROPIA");

  return {
    ...record,
    id: Number(firstDefined(record, ["id", "cuenta_id", "cuenta_destino_id"], 0)),
    alias: String(firstDefined(record, ["alias", "nombre", "name"], "Cuenta sin alias")),
    tipo: normalizedStatus(firstDefined(record, ["tipo", "tipo_cuenta", "account_type"], "OTRA"), "OTRA"),
    moneda: normalizedStatus(firstDefined(record, ["moneda", "currency"], state.defaultCurrency), state.defaultCurrency),
    entidadTipo: entityType,
    entidadNombre: String(firstDefined(record, [
      "entidad_nombre",
      "entidad.nombre_comercial",
      "entidad.razon_social",
      "entity_name"
    ], "") || ""),
    proveedorId: firstDefined(record, ["proveedor_id", "entidad.proveedor_id", "provider_id"], null),
    proveedorNombre: String(firstDefined(record, [
      "proveedor_nombre",
      "proveedor.nombre",
      "proveedor.nombre_razon_social",
      "provider_name"
    ], "") || ""),
    banco: String(firstDefined(record, ["banco", "bank"], "") || ""),
    numero: String(firstDefined(record, ["numero_cuenta", "numero", "account_number"], "") || "")
  };
}

function accountIsExternal(account) {
  return account?.entidadTipo === "EXTERNA" || Boolean(account?.proveedorId);
}

function parseMoneyCents(value) {
  const normalized = String(value ?? "").trim().replace(",", ".");
  const match = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(normalized);
  if (!match) return null;

  const whole = Number(match[1]);
  const decimal = Number(String(match[2] || "").padEnd(2, "0"));
  const cents = whole * 100 + decimal;
  return Number.isSafeInteger(cents) ? cents : null;
}

function centsToDecimal(cents) {
  const safeCents = Number.isSafeInteger(cents) ? cents : 0;
  return `${Math.trunc(safeCents / 100)}.${String(Math.abs(safeCents % 100)).padStart(2, "0")}`;
}

function moneyFromCents(cents, currency = elements.currency.value || state.defaultCurrency) {
  return formatMoney((Number(cents) || 0) / 100, currency);
}

function amountCents(value) {
  return parseMoneyCents(value) ?? 0;
}

function currencyPrefix(currency = elements.currency.value) {
  return String(currency).toUpperCase() === "USD" ? "$" : "S/";
}

function datePartsInTimezone(date = new Date()) {
  try {
    return new Intl.DateTimeFormat("en-CA", {
      timeZone: state.timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23"
    }).formatToParts(date).reduce((parts, part) => ({ ...parts, [part.type]: part.value }), {});
  } catch {
    return null;
  }
}

function dateTimeNow() {
  const parts = datePartsInTimezone();
  return parts?.year
    ? `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
    : toLocalDateTimeValue();
}

function todayValue() {
  return dateTimeNow().slice(0, 10);
}

function currentMonthStart() {
  const today = todayValue();
  return `${today.slice(0, 7)}-01`;
}

function formatCompanyDateTime(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  try {
    return new Intl.DateTimeFormat("es-PE", {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: state.timezone
    }).format(date);
  } catch {
    return formatDateTime(value);
  }
}

function selectedAccount() {
  return state.accounts.find((account) => String(account.id) === String(elements.destination.value)) || null;
}

function accountOptionLabel(account) {
  const entity = account.entidadNombre || (accountIsExternal(account) ? account.proveedorNombre : "Empresa propia");
  const detail = [account.banco, account.numero].filter(Boolean).join(" · ");
  return `${entity} — ${account.alias}${detail ? ` · ${detail}` : ""}`;
}

function clientOptionLabel(client) {
  return `${client.nombre}${client.documento ? ` · ${client.documento}` : ""}`;
}

function collectionDate() {
  return String(elements.dateTime.value || "").slice(0, 10) || todayValue();
}

function newDetail() {
  return {
    key: state.nextDetailKey++,
    cliente_id: "",
    fecha_recepcion: collectionDate(),
    importe: ""
  };
}

function clientOptions(selected) {
  return state.clients.map((client) => {
    const id = String(client.id);
    const isSelected = id === String(selected || "");
    return `<option value="${escapeHtml(id)}" ${isSelected ? "selected" : ""}>${escapeHtml(clientOptionLabel(client))}</option>`;
  }).join("");
}

function renderDetails() {
  if (!state.details.length) state.details.push(newDetail());
  const maxDate = collectionDate();
  const disabled = permissions.manage ? "" : "disabled";

  elements.details.innerHTML = state.details.map((detail, index) => `
    <article class="fin-purchase-line fin-collection-line" data-detail-key="${detail.key}">
      <header>
        <div>
          <span class="fin-purchase-line-number">${String(index + 1).padStart(2, "0")}</span>
          <strong>Abono del cliente ${index + 1}</strong>
        </div>
        <button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-detail-remove="${detail.key}" ${state.details.length === 1 || !permissions.manage ? "disabled" : ""}>Quitar</button>
      </header>
      <div class="fin-collection-line-grid">
        <label class="fin-field">
          <span>Cliente que entregó el efectivo <b>*</b></span>
          <select data-detail-field="cliente_id" required ${disabled}>
            <option value="">Selecciona un cliente</option>
            ${clientOptions(detail.cliente_id)}
          </select>
        </label>
        <label class="fin-field">
          <span>Fecha de recepción <b>*</b></span>
          <input data-detail-field="fecha_recepcion" type="date" min="1970-01-01" max="${escapeHtml(maxDate)}" value="${escapeHtml(detail.fecha_recepcion)}" required ${disabled}>
        </label>
        <label class="fin-field">
          <span>Importe recibido <b>*</b></span>
          <div class="fin-money-input">
            <span>${escapeHtml(currencyPrefix())}</span>
            <input data-detail-field="importe" type="number" min="0.01" step="0.01" inputmode="decimal" value="${escapeHtml(detail.importe)}" required placeholder="0.00" ${disabled}>
          </div>
        </label>
        <div class="fin-collection-line-result">
          <span>Aplicación</span>
          <strong>FIFO automático</strong>
          <small>Primero se cancelan las deudas más antiguas.</small>
        </div>
      </div>
    </article>
  `).join("");

  elements.addDetail.disabled = !permissions.manage;
  updateSummary();
}

function detailState() {
  const detailCents = state.details.map((detail) => parseMoneyCents(detail.importe));
  const complete = state.details.length > 0
    && state.details.every((detail, index) => Boolean(detail.cliente_id)
      && Boolean(detail.fecha_recepcion)
      && detail.fecha_recepcion <= collectionDate()
      && detailCents[index] !== null
      && detailCents[index] > 0);

  return {
    complete,
    sumCents: detailCents.reduce((sum, cents) => sum + (cents || 0), 0)
  };
}

function requiredHeaderReady() {
  return Boolean(
    elements.collector.value
    && elements.dateTime.value
    && elements.destination.value
    && elements.currency.value
    && elements.reference.value.trim()
  );
}

function pendingConfirmationCents() {
  if (!elements.pendingConfirmation.checked) return null;
  const value = Number(elements.pendingConfirmation.dataset.amount || "");
  return Number.isSafeInteger(value) && value > 0 ? value : null;
}

function invalidatePendingConfirmation() {
  elements.pendingConfirmation.checked = false;
  delete elements.pendingConfirmation.dataset.amount;
}

function updateSummary() {
  const totalCents = parseMoneyCents(elements.total.value) ?? 0;
  const details = detailState();
  let reconciliation = collectionReconciliation({
    totalCents,
    detailCents: details.sumCents,
    detailsComplete: details.complete,
    confirmedPendingCents: pendingConfirmationCents()
  });
  if (elements.pendingConfirmation.checked && !reconciliation.pendingConfirmed) {
    invalidatePendingConfirmation();
    reconciliation = collectionReconciliation({
      totalCents,
      detailCents: details.sumCents,
      detailsComplete: details.complete
    });
  }
  const balanced = reconciliation.differenceCents === 0
    && totalCents > 0
    && details.complete;

  elements.currencyPrefix.textContent = currencyPrefix();
  elements.summaryTotal.textContent = moneyFromCents(totalCents);
  elements.summaryDetails.textContent = moneyFromCents(details.sumCents);
  elements.summaryCount.textContent = String(state.details.filter((detail) => detail.cliente_id).length);
  elements.summaryDifference.textContent = moneyFromCents(
    reconciliation.pendingCents || reconciliation.excessCents
  );
  elements.differenceLine.classList.toggle("is-balanced", balanced);
  elements.differenceLine.classList.toggle("is-short", reconciliation.pendingCents > 0);
  elements.differenceLine.classList.toggle("is-over", reconciliation.excessCents > 0);
  elements.differenceLine.classList.toggle("is-pending", totalCents <= 0 || (!balanced
    && reconciliation.pendingCents === 0
    && reconciliation.excessCents === 0));
  elements.pendingConfirmationWrap.hidden = reconciliation.pendingCents <= 0
    || reconciliation.excessCents > 0
    || !details.complete;
  elements.pendingConfirmationText.textContent = `Registrar ${moneyFromCents(reconciliation.pendingCents)} como pendiente por identificar. Este importe no se aplicará a ningún cliente.`;

  if (reconciliation.excessCents > 0) {
    elements.differenceLabel.textContent = "Excede el voucher";
  } else if (balanced) {
    elements.differenceLabel.textContent = "Diferencia";
  } else {
    elements.differenceLabel.textContent = "Pendiente por identificar";
  }

  if (totalCents <= 0) {
    elements.summaryHint.textContent = "Ingresa el total del voucher y agrega al menos un cliente.";
  } else if (reconciliation.excessCents > 0) {
    elements.summaryHint.textContent = `El desglose supera el voucher por ${moneyFromCents(reconciliation.excessCents)}.`;
  } else if (!details.complete) {
    elements.summaryHint.textContent = "Completa el cliente, la fecha y el importe de cada fila.";
  } else if (reconciliation.requiresConfirmation && !reconciliation.pendingConfirmed) {
    elements.summaryHint.textContent = `Confirma que ${moneyFromCents(reconciliation.pendingCents)} quedará pendiente por identificar.`;
  } else if (!requiredHeaderReady()) {
    elements.summaryHint.textContent = "Completa los datos obligatorios del depósito.";
  } else if (reconciliation.pendingCents > 0) {
    elements.summaryHint.textContent = "El depósito se registrará completo y el monto no desglosado quedará identificado como pendiente.";
  } else {
    elements.summaryHint.textContent = "El depósito y el desglose coinciden exactamente. Ya puedes registrar la cobranza.";
  }

  elements.save.disabled = !permissions.manage
    || state.saving
    || !reconciliation.registrable
    || !requiredHeaderReady();
}

function populateCollectorSelects(selectedMain = elements.collector.value, selectedFilter = elements.filterCollector.value) {
  const activeCollectors = state.collectors.filter(isActiveCollector);
  elements.collector.innerHTML = `<option value="">Selecciona un cobrador</option>${activeCollectors
    .map((collector) => `<option value="${escapeHtml(collector.id)}">${escapeHtml(collector.nombre)}</option>`)
    .join("")}`;
  elements.filterCollector.innerHTML = `<option value="">Todos</option>${state.collectors
    .map((collector) => `<option value="${escapeHtml(collector.id)}">${escapeHtml(collector.nombre)}${isActiveCollector(collector) ? "" : " · Inactivo"}</option>`)
    .join("")}`;

  if (activeCollectors.some((collector) => String(collector.id) === String(selectedMain))) {
    elements.collector.value = String(selectedMain);
  }
  if (state.collectors.some((collector) => String(collector.id) === String(selectedFilter))) {
    elements.filterCollector.value = String(selectedFilter);
  }
}

function populateAccounts(selected = elements.destination.value) {
  const own = state.accounts.filter((account) => !accountIsExternal(account));
  const external = state.accounts.filter(accountIsExternal);
  const options = (records) => records
    .map((account) => `<option value="${escapeHtml(account.id)}">${escapeHtml(accountOptionLabel(account))}</option>`)
    .join("");

  elements.destination.innerHTML = `
    <option value="">Selecciona una cuenta de destino</option>
    ${own.length ? `<optgroup label="Cuentas propias">${options(own)}</optgroup>` : ""}
    ${external.length ? `<optgroup label="Cuentas de proveedores">${options(external)}</optgroup>` : ""}
  `;

  if (state.accounts.some((account) => String(account.id) === String(selected))) {
    elements.destination.value = String(selected);
  }
  updateDestination();
}

function updateDestination() {
  const account = selectedAccount();
  if (!account) {
    elements.destinationHelp.textContent = "Selecciona una cuenta propia o una cuenta externa asociada a un proveedor.";
    elements.destinationNotice.className = "fin-collection-destination-note";
    elements.destinationNotice.textContent = "Selecciona una cuenta para conocer cómo se aplicará el depósito.";
    elements.currency.disabled = !permissions.manage;
    updateSummary();
    return;
  }

  const previousCurrency = elements.currency.value;
  if (account.moneda) elements.currency.value = account.moneda;
  if (elements.currency.value !== previousCurrency) invalidatePendingConfirmation();
  elements.currency.disabled = Boolean(account.moneda) || !permissions.manage;
  elements.destinationHelp.textContent = `${account.tipo} · ${account.moneda}${account.numero ? ` · ${account.numero}` : ""}`;

  if (accountIsExternal(account)) {
    const provider = account.proveedorNombre || account.entidadNombre || "el proveedor relacionado";
    elements.destinationNotice.className = "fin-collection-destination-note is-external";
    elements.destinationNotice.innerHTML = `<strong>Depósito directo a proveedor:</strong> ${escapeHtml(provider)}. Los abonos reducirán las deudas de cada cliente y el total se aplicará automáticamente a las cuentas por pagar más antiguas de este proveedor.`;
  } else {
    elements.destinationNotice.className = "fin-collection-destination-note is-own";
    elements.destinationNotice.innerHTML = `<strong>Ingreso a cuenta propia:</strong> el saldo de ${escapeHtml(account.alias)} aumentará y cada abono se aplicará a las deudas más antiguas de su cliente.`;
  }

  renderDetails();
}

function resetCollectorForm() {
  elements.collectorForm.reset();
  elements.collectorId.value = "";
  elements.collectorFormTitle.textContent = "Agregar cobrador";
  elements.collectorEditBadge.hidden = true;
  elements.collectorSave.textContent = "Guardar cobrador";
  setMessage(elements.collectorFormMessage, "");
}

function renderCollectorList() {
  elements.collectorCount.textContent = String(state.collectors.length);
  if (!state.collectors.length) {
    elements.collectorList.innerHTML = '<p class="fin-collector-empty">Todavía no hay cobradores registrados.</p>';
    return;
  }

  elements.collectorList.innerHTML = state.collectors.map((collector) => {
    const active = isActiveCollector(collector);
    const updating = String(state.collectorUpdatingId) === String(collector.id);
    return `
      <article class="fin-collector-item ${active ? "" : "is-inactive"}">
        <div>
          <strong>${escapeHtml(collector.nombre)}</strong>
          <span class="fin-collector-status ${active ? "is-active" : "is-inactive"}">${active ? "Activo" : "Inactivo"}</span>
        </div>
        <div class="fin-collector-actions">
          <button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-collector-edit="${collector.id}" ${!permissions.manage || updating ? "disabled" : ""}>Editar</button>
          <button class="fin-btn ${active ? "fin-btn-danger" : "fin-btn-primary"} fin-btn-small" type="button" data-collector-toggle="${collector.id}" ${!permissions.manage || updating ? "disabled" : ""}>${updating ? "Guardando..." : active ? "Desactivar" : "Activar"}</button>
        </div>
      </article>
    `;
  }).join("");
}

async function loadCatalog({ preserveSelections = true } = {}) {
  const selectedCollector = preserveSelections ? elements.collector.value : "";
  const selectedFilter = preserveSelections ? elements.filterCollector.value : "";
  const selectedDestination = preserveSelections ? elements.destination.value : "";
  const response = await apiRequest("/finanzas/cobranzas/catalogo");
  const root = dataRoot(response);

  state.timezone = String(firstDefined(root, ["timezone", "zona_horaria"], state.timezone));
  state.defaultCurrency = normalizedStatus(firstDefined(root, ["moneda", "currency"], state.defaultCurrency), "PEN");
  state.collectors = responseCollection(response, ["cobradores", "collectors"])
    .map(normalizeCollector)
    .filter((collector) => collector.id)
    .sort((a, b) => a.nombre.localeCompare(b.nombre, "es"));
  state.clients = responseCollection(response, ["clientes", "clients"])
    .map(normalizeClient)
    .filter((client) => client.id)
    .sort((a, b) => a.nombre.localeCompare(b.nombre, "es"));
  state.accounts = responseCollection(response, ["cuentas_destino", "cuentas", "destination_accounts"])
    .map(normalizeAccount)
    .filter((account) => account.id)
    .sort((a, b) => accountOptionLabel(a).localeCompare(accountOptionLabel(b), "es"));

  if (!elements.currency.value) elements.currency.value = state.defaultCurrency;
  populateCollectorSelects(selectedCollector, selectedFilter);
  populateAccounts(selectedDestination);
  renderCollectorList();
  renderDetails();
  markFinanceAccessReady();
}

function resetCollection({ preserveMessage = false } = {}) {
  elements.form.reset();
  invalidatePendingConfirmation();
  elements.dateTime.value = dateTimeNow();
  elements.currency.value = state.defaultCurrency;
  elements.currency.disabled = false;
  state.details = [newDetail()];
  state.idempotencyKey = createIdempotencyKey();
  populateCollectorSelects("", elements.filterCollector.value);
  populateAccounts("");
  renderDetails();
  if (!preserveMessage) setMessage(elements.message, "");
  setMessage(elements.detailsMessage, "");
  updateSummary();
}

function validationIssue() {
  const totalCents = parseMoneyCents(elements.total.value);
  const details = detailState();

  if (!permissions.manage) return ["No tienes permiso para registrar cobranzas.", null];
  if (!elements.collector.value) return ["Selecciona el cobrador responsable.", elements.collector];
  if (!elements.dateTime.value) return ["Indica la fecha y hora del depósito.", elements.dateTime];
  if (!elements.destination.value) return ["Selecciona la cuenta donde se depositó el dinero.", elements.destination];
  if (totalCents === null || totalCents <= 0) return ["Ingresa un importe total válido mayor a cero.", elements.total];
  if (!elements.reference.value.trim()) return ["Ingresa el número de operación o voucher.", elements.reference];
  if (!details.complete) return ["Completa correctamente el cliente, la fecha y el importe de cada fila.", elements.details.querySelector("select, input")];
  if (details.sumCents > totalCents) return ["La suma de los clientes no puede superar el importe del voucher.", elements.total];
  const pendingCents = totalCents - details.sumCents;
  if (pendingCents > 0 && pendingConfirmationCents() !== pendingCents) {
    return [`Confirma que ${moneyFromCents(pendingCents)} quedará pendiente por identificar.`, elements.pendingConfirmation];
  }
  return null;
}

async function saveCollection(event) {
  event.preventDefault();
  const issue = validationIssue();
  if (issue) {
    setMessage(elements.message, issue[0], "error");
    issue[1]?.focus();
    return;
  }

  const totalCents = parseMoneyCents(elements.total.value);
  const pendingCents = totalCents - detailState().sumCents;
  state.saving = true;
  updateSummary();
  setMessage(elements.message, "Registrando el depósito y aplicando los abonos...");

  try {
    await apiRequest("/finanzas/cobranzas", {
      method: "POST",
      body: JSON.stringify({
        idempotency_key: state.idempotencyKey,
        cobrador_id: Number(elements.collector.value),
        fecha_hora: elements.dateTime.value,
        cuenta_destino_id: Number(elements.destination.value),
        moneda: elements.currency.value,
        importe_total: centsToDecimal(totalCents),
        referencia: elements.reference.value.trim(),
        observaciones: elements.notes.value.trim() || null,
        detalles: state.details.map((detail) => ({
          cliente_id: Number(detail.cliente_id),
          fecha_recepcion: detail.fecha_recepcion,
          importe: centsToDecimal(parseMoneyCents(detail.importe))
        }))
      })
    });

    resetCollection({ preserveMessage: true });
    state.page = 1;
    await loadCollections();
    setMessage(
      elements.message,
      pendingCents > 0
        ? `Cobranza registrada con ${moneyFromCents(pendingCents)} pendiente por identificar.`
        : "Cobranza registrada. El depósito y todos sus abonos quedaron trazados correctamente.",
      "success"
    );
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudo registrar la cobranza."), "error");
  } finally {
    state.saving = false;
    updateSummary();
  }
}

function editCollector(id) {
  const collector = state.collectors.find((item) => String(item.id) === String(id));
  if (!collector || !permissions.manage) return;
  elements.collectorId.value = String(collector.id);
  elements.collectorName.value = collector.nombre;
  elements.collectorFormTitle.textContent = "Editar cobrador";
  elements.collectorEditBadge.hidden = false;
  elements.collectorSave.textContent = "Guardar cambios";
  setMessage(elements.collectorFormMessage, "");
  elements.collectorName.focus();
}

async function saveCollector(event) {
  event.preventDefault();
  if (!permissions.manage || state.collectorSaving) return;
  const name = elements.collectorName.value.trim();
  if (name.length < 2) {
    setMessage(elements.collectorFormMessage, "Escribe un nombre de al menos 2 caracteres.", "error");
    elements.collectorName.focus();
    return;
  }

  const id = elements.collectorId.value;
  const collector = state.collectors.find((item) => String(item.id) === String(id));
  const path = id ? `/finanzas/cobradores/${encodeURIComponent(id)}` : "/finanzas/cobradores";
  const payload = id ? { nombre: name, estado: collector?.estado || "ACTIVO" } : { nombre: name };
  state.collectorSaving = true;
  elements.collectorSave.disabled = true;
  setMessage(elements.collectorFormMessage, id ? "Guardando cambios..." : "Agregando cobrador...");

  try {
    await apiRequest(path, { method: id ? "PUT" : "POST", body: JSON.stringify(payload) });
    await loadCatalog();
    resetCollectorForm();
    setMessage(elements.collectorFormMessage, id ? "Cobrador actualizado." : "Cobrador agregado.", "success");
  } catch (error) {
    setMessage(elements.collectorFormMessage, errorMessage(error, "No se pudo guardar el cobrador."), "error");
  } finally {
    state.collectorSaving = false;
    elements.collectorSave.disabled = !permissions.manage;
  }
}

async function toggleCollector(id) {
  const collector = state.collectors.find((item) => String(item.id) === String(id));
  if (!collector || !permissions.manage || state.collectorUpdatingId) return;
  const activate = !isActiveCollector(collector);
  state.collectorUpdatingId = collector.id;
  renderCollectorList();
  setMessage(elements.collectorListMessage, `${activate ? "Activando" : "Desactivando"} cobrador...`);

  try {
    await apiRequest(`/finanzas/cobradores/${encodeURIComponent(collector.id)}`, {
      method: "PUT",
      body: JSON.stringify({ nombre: collector.nombre, estado: activate ? "ACTIVO" : "INACTIVO" })
    });
    await loadCatalog();
    setMessage(elements.collectorListMessage, `Cobrador ${activate ? "activado" : "desactivado"}.`, "success");
  } catch (error) {
    setMessage(elements.collectorListMessage, errorMessage(error, "No se pudo cambiar el estado del cobrador."), "error");
  } finally {
    state.collectorUpdatingId = null;
    renderCollectorList();
  }
}

function collectionStatus(record) {
  return normalizedStatus(firstDefined(record, ["estado", "status"], "REGISTRADO"), "REGISTRADO");
}

function normalizeCollection(record) {
  const details = firstDefined(record, ["detalles", "details"], []);
  const account = firstDefined(record, ["cuenta_destino", "cuenta", "destination_account"], {});
  const collector = firstDefined(record, ["cobrador", "collector"], {});
  const provider = firstDefined(record, ["proveedor", "provider", "cuenta_destino.proveedor"], {});
  return {
    ...record,
    id: Number(firstDefined(record, ["id", "cobranza_id"], 0)),
    codigo: String(firstDefined(record, ["codigo", "code"], "Cobranza")),
    fechaHora: firstDefined(record, ["fecha_hora", "fecha", "occurred_at", "created_at"], null),
    referencia: String(firstDefined(record, ["referencia", "numero_operacion", "reference"], "") || ""),
    moneda: normalizedStatus(firstDefined(record, ["moneda", "currency"], state.defaultCurrency), state.defaultCurrency),
    importe: firstDefined(record, ["importe_total", "importe", "total", "amount"], "0.00"),
    importeAsignado: firstDefined(record, ["importe_asignado", "importe_desglosado", "assigned_amount"], "0.00"),
    importePendiente: firstDefined(record, ["importe_pendiente", "pending_amount"], "0.00"),
    conciliacion: normalizedStatus(firstDefined(record, [
      "conciliacion",
      "estado_conciliacion",
      "reconciliation_status"
    ], "COMPLETA"), "COMPLETA"),
    pendiente: firstDefined(record, ["pendiente", "pendiente_identificar", "unassigned"], null),
    estado: collectionStatus(record),
    cobradorNombre: String(firstDefined(record, [
      "cobrador_nombre_snapshot",
      "cobrador_nombre",
      "cobrador.nombre",
      "collector.name"
    ], firstDefined(collector, ["nombre", "name"], "Sin cobrador"))),
    cuentaNombre: String(firstDefined(record, [
      "cuenta_destino_nombre_snapshot",
      "cuenta_destino.alias",
      "cuenta.alias",
      "destination_account.name"
    ], firstDefined(account, ["alias", "nombre", "name"], "Sin cuenta"))),
    entidadNombre: String(firstDefined(record, [
      "entidad_destino_nombre_snapshot",
      "cuenta_destino.entidad_nombre",
      "cuenta_destino.entidad.nombre_comercial",
      "cuenta_destino.entidad.razon_social"
    ], "") || ""),
    proveedorNombre: String(firstDefined(record, [
      "proveedor_nombre_snapshot",
      "proveedor_nombre",
      "proveedor.nombre",
      "proveedor.nombre_razon_social"
    ], firstDefined(provider, ["nombre", "nombre_razon_social", "name"], "")) || ""),
    detailCount: Number(firstDefined(record, [
      "detalles_count",
      "cantidad_clientes",
      "clientes_count",
      "detail_count"
    ], Array.isArray(details) ? details.length : 0)),
    observaciones: String(firstDefined(record, ["observaciones", "notas", "notes"], "") || ""),
    motivoAnulacion: String(firstDefined(record, ["motivo_anulacion", "void_reason"], "") || "")
  };
}

function statusLabel(status) {
  return {
    REGISTRADO: "Vigente",
    ANULADO: "Anulado",
    ANULADA: "Anulada",
    PENDIENTE: "Pendiente por identificar",
    COMPLETA: "Completa"
  }[status] || status;
}

function statusTag(status) {
  return `<span class="fin-collection-status is-${escapeHtml(String(status).toLowerCase())}">${escapeHtml(statusLabel(status))}</span>`;
}

function assignmentRetryAttempt(id = state.assignId) {
  if (id === null || id === undefined || id === "") return null;
  return state.assignRetryAttempts.get(String(id)) || null;
}

function assignmentPayloadFromState() {
  return {
    idempotency_key: state.assignIdempotencyKey,
    detalles: state.assignDetails.map((detail) => ({
      cliente_id: Number(detail.cliente_id),
      fecha_recepcion: detail.fecha_recepcion,
      importe: centsToDecimal(parseMoneyCents(detail.importe))
    }))
  };
}

function assignmentDetailsFromPayload(payload) {
  return (payload?.detalles || []).map((detail) => ({
    key: state.nextAssignDetailKey++,
    cliente_id: String(detail.cliente_id),
    fecha_recepcion: String(detail.fecha_recepcion),
    importe: String(detail.importe)
  }));
}

function rememberAssignmentRetry(collectionId, payload, message) {
  const attempt = createPendingAssignmentRetrySnapshot({
    availableCents: state.assignAvailableCents,
    message,
    payload
  });
  state.assignRetryAttempts.set(String(collectionId), attempt);
  state.assignRetryLocked = true;
  return attempt;
}

function clearAssignmentRetry(collectionId = state.assignId) {
  if (collectionId !== null && collectionId !== undefined && collectionId !== "") {
    state.assignRetryAttempts.delete(String(collectionId));
  }
  state.assignRetryLocked = false;
}

function canAssignCollection(record) {
  const retryPending = Boolean(record?.id && assignmentRetryAttempt(record.id));
  const apiPermission = firstDefined(record || {}, [
    "puede_asignar_pendiente",
    "can_assign_pending"
  ], null);
  const apiAllowsAssignment = apiPermission === null
    || apiPermission === undefined
    || apiPermission === true
    || apiPermission === 1
    || ["1", "TRUE", "SI", "SÍ"].includes(String(apiPermission).trim().toUpperCase());

  return permissions.manage && (retryPending || (
    apiAllowsAssignment
    && record?.estado === "REGISTRADO"
    && amountCents(record.importePendiente) > 0
  ));
}

function assignmentMaximumDate() {
  const value = String(state.assignRecord?.fechaHora || "").slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : todayValue();
}

function newAssignmentDetail() {
  return {
    key: state.nextAssignDetailKey++,
    cliente_id: "",
    fecha_recepcion: assignmentMaximumDate(),
    importe: ""
  };
}

function assignmentDetailState() {
  const detailCents = state.assignDetails.map((detail) => parseMoneyCents(detail.importe));
  const maximumDate = assignmentMaximumDate();
  const complete = state.assignDetails.length > 0
    && state.assignDetails.every((detail, index) => Boolean(detail.cliente_id)
      && Boolean(detail.fecha_recepcion)
      && detail.fecha_recepcion <= maximumDate
      && detailCents[index] !== null
      && detailCents[index] > 0);

  return {
    complete,
    sumCents: detailCents.reduce((sum, cents) => sum + (cents || 0), 0)
  };
}

function renderAssignmentFacts() {
  const record = state.assignRecord;
  if (!record) {
    elements.assignFacts.innerHTML = "";
    return;
  }
  const destination = [record.entidadNombre, record.cuentaNombre].filter(Boolean).join(" · ") || "Sin destino";

  elements.assignFacts.innerHTML = `
    <article><span>Cobranza</span><strong>${escapeHtml(record.codigo)}</strong></article>
    <article><span>Número de operación</span><strong>${escapeHtml(record.referencia || "Sin referencia")}</strong></article>
    <article><span>Cuenta de destino</span><strong>${escapeHtml(destination)}</strong></article>
    <article class="is-total"><span>Saldo disponible</span><strong>${escapeHtml(moneyFromCents(state.assignAvailableCents, record.moneda))}</strong></article>
  `;
}

function renderAssignmentDetails() {
  if (!state.assignDetails.length) state.assignDetails = [newAssignmentDetail()];
  const record = state.assignRecord;
  const disabled = !permissions.manage || state.assigning || state.assignRetryLocked ? "disabled" : "";
  const maximumDate = assignmentMaximumDate();
  const currency = record?.moneda || state.defaultCurrency;

  elements.assignDetails.innerHTML = state.assignDetails.map((detail, index) => `
    <article class="fin-purchase-line fin-collection-line" data-assignment-key="${detail.key}">
      <header>
        <div>
          <span class="fin-purchase-line-number">${String(index + 1).padStart(2, "0")}</span>
          <strong>Asignación ${index + 1}</strong>
        </div>
        <button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-assignment-remove="${detail.key}" aria-label="Quitar asignación ${index + 1}" ${state.assignDetails.length === 1 || disabled ? "disabled" : ""}>Quitar</button>
      </header>
      <div class="fin-collection-assign-line-grid">
        <label class="fin-field">
          <span>Cliente identificado <b>*</b></span>
          <select data-assignment-field="cliente_id" required ${disabled}>
            <option value="">Selecciona un cliente</option>
            ${clientOptions(detail.cliente_id)}
          </select>
        </label>
        <label class="fin-field">
          <span>Fecha de recepción <b>*</b></span>
          <input data-assignment-field="fecha_recepcion" type="date" min="1970-01-01" max="${escapeHtml(maximumDate)}" value="${escapeHtml(detail.fecha_recepcion)}" required ${disabled}>
        </label>
        <label class="fin-field">
          <span>Importe a identificar <b>*</b></span>
          <div class="fin-money-input">
            <span>${escapeHtml(currencyPrefix(currency))}</span>
            <input data-assignment-field="importe" type="number" min="0.01" step="0.01" inputmode="decimal" value="${escapeHtml(detail.importe)}" required placeholder="0.00" ${disabled}>
          </div>
        </label>
      </div>
    </article>
  `).join("");

  updateAssignmentSummary();
}

function updateAssignmentSummary() {
  const record = state.assignRecord;
  const details = assignmentDetailState();
  const retryAttempt = assignmentRetryAttempt();
  const atDetailLimit = state.assignDetails.length >= MAX_PENDING_ASSIGNMENT_DETAILS;
  const reconciliation = pendingAssignmentReconciliation({
    availableCents: state.assignAvailableCents,
    detailCents: details.sumCents,
    detailsComplete: details.complete
  });
  const currency = record?.moneda || state.defaultCurrency;

  elements.assignAvailable.textContent = moneyFromCents(reconciliation.availableCents, currency);
  elements.assignTotal.textContent = moneyFromCents(reconciliation.assignedCents, currency);
  elements.assignRemaining.textContent = moneyFromCents(
    reconciliation.excessCents || reconciliation.remainingCents,
    currency
  );
  elements.assignRemainingLine.classList.toggle("is-over", reconciliation.excessCents > 0);
  elements.assignRemainingLine.classList.toggle("is-complete", reconciliation.complete);
  elements.assignRemainingLine.querySelector("span").textContent = reconciliation.excessCents > 0
    ? "Excede el saldo"
    : reconciliation.complete
      ? "Saldo restante"
      : "Quedará pendiente";

  let hint;
  if (!record) {
    hint = "Selecciona una cobranza vigente con saldo pendiente.";
  } else if (state.assignRetryLocked) {
    hint = "El resultado anterior no pudo confirmarse. Reintenta exactamente la misma asignación; los campos permanecen bloqueados para evitar duplicados.";
  } else if (reconciliation.excessCents > 0) {
    hint = `La asignación supera el saldo disponible por ${moneyFromCents(reconciliation.excessCents, currency)}.`;
  } else if (!details.complete) {
    hint = "Completa el cliente, la fecha y el importe de cada fila.";
  } else if (reconciliation.complete) {
    hint = "El saldo pendiente quedará completamente identificado.";
  } else if (reconciliation.registrable) {
    hint = `Después de guardar quedarán ${moneyFromCents(reconciliation.remainingCents, currency)} pendientes por identificar.`;
  } else {
    hint = "Agrega un importe mayor a cero para continuar.";
  }
  if (atDetailLimit && !state.assignRetryLocked) {
    hint += ` Alcanzaste el máximo de ${MAX_PENDING_ASSIGNMENT_DETAILS} clientes por operación.`;
  }
  elements.assignHint.textContent = hint;

  elements.assignAddDetail.disabled = !permissions.manage
    || state.assigning
    || state.assignRetryLocked
    || !record
    || atDetailLimit;
  elements.assignSubmit.disabled = !permissions.manage
    || state.assigning
    || !record
    || (state.assignRetryLocked ? !retryAttempt : !reconciliation.registrable);
  elements.assignSubmit.textContent = state.assigning
    ? state.assignRetryLocked ? "Reintentando..." : "Asignando..."
    : state.assignRetryLocked ? "Reintentar asignación" : "Asignar saldo";
  elements.assignForm.querySelectorAll("[data-collection-dialog-close]").forEach((button) => {
    button.disabled = state.assigning;
  });
}

function resetAssignmentState() {
  state.assignId = null;
  state.assignRecord = null;
  state.assignAvailableCents = 0;
  state.assignDetails = [];
  state.assignIdempotencyKey = null;
  state.assignRetryLocked = false;
  state.assigning = false;
  elements.assignFacts.innerHTML = "";
  elements.assignDetails.innerHTML = "";
  setMessage(elements.assignMessage, "");
}

function openAssignmentDialog(id) {
  const record = state.collections.get(String(id));
  if (!canAssignCollection(record)) return;
  const retryAttempt = assignmentRetryAttempt(record.id);

  state.assignId = record.id;
  state.assignRecord = record;
  state.assignAvailableCents = retryAttempt?.availableCents ?? amountCents(record.importePendiente);
  state.assignIdempotencyKey = retryAttempt?.payload?.idempotency_key || createIdempotencyKey();
  state.assignRetryLocked = Boolean(retryAttempt);
  state.assigning = false;
  state.assignDetails = retryAttempt
    ? assignmentDetailsFromPayload(retryAttempt.payload)
    : [newAssignmentDetail()];

  elements.assignTitle.textContent = retryAttempt ? "Reintentar asignación pendiente" : "Asignar saldo pendiente";
  elements.assignIntro.textContent = retryAttempt
    ? `Confirma la operación pendiente de ${record.codigo} sin cambiar sus clientes ni importes.`
    : `Identifica el saldo restante de ${record.codigo} sin modificar los abonos ya aplicados.`;
  setMessage(elements.assignMessage, retryAttempt?.message || "", retryAttempt ? "error" : "");
  renderAssignmentFacts();
  renderAssignmentDetails();

  if (elements.detailDialog.open) elements.detailDialog.close();
  if (!elements.assignDialog.open) elements.assignDialog.showModal();
  window.setTimeout(() => {
    (retryAttempt ? elements.assignSubmit : elements.assignDetails.querySelector("select"))?.focus();
  }, 40);
}

function assignmentValidationIssue() {
  if (!permissions.manage) return ["No tienes permiso para asignar el saldo pendiente.", null];
  if (state.assignRetryLocked) {
    return assignmentRetryAttempt()
      ? null
      : ["La operación pendiente ya no está disponible para reintentar.", null];
  }
  if (!canAssignCollection(state.assignRecord)) {
    return ["La cobranza ya no está vigente o no tiene saldo pendiente.", null];
  }
  if (state.assignDetails.length > MAX_PENDING_ASSIGNMENT_DETAILS) {
    return [`Solo se permiten ${MAX_PENDING_ASSIGNMENT_DETAILS} clientes por asignación.`, null];
  }

  const maximumDate = assignmentMaximumDate();
  for (const detail of state.assignDetails) {
    const article = elements.assignDetails.querySelector(`[data-assignment-key="${detail.key}"]`);
    if (!detail.cliente_id) {
      return ["Selecciona el cliente de cada asignación.", article?.querySelector('[data-assignment-field="cliente_id"]')];
    }
    if (!detail.fecha_recepcion) {
      return ["Indica la fecha de recepción de cada asignación.", article?.querySelector('[data-assignment-field="fecha_recepcion"]')];
    }
    if (detail.fecha_recepcion > maximumDate) {
      return ["La recepción del efectivo no puede ser posterior al depósito.", article?.querySelector('[data-assignment-field="fecha_recepcion"]')];
    }
    const cents = parseMoneyCents(detail.importe);
    if (cents === null || cents <= 0) {
      return ["Ingresa un importe válido mayor a cero en cada asignación.", article?.querySelector('[data-assignment-field="importe"]')];
    }
  }

  const details = assignmentDetailState();
  if (details.sumCents > state.assignAvailableCents) {
    const amountFields = elements.assignDetails.querySelectorAll('[data-assignment-field="importe"]');
    return [
      "La suma de las asignaciones no puede superar el saldo pendiente.",
      amountFields[amountFields.length - 1] || null
    ];
  }
  return null;
}

async function saveAssignment(event) {
  event.preventDefault();
  if (state.assigning) return;
  const issue = assignmentValidationIssue();
  if (issue) {
    setMessage(elements.assignMessage, issue[0], "error");
    issue[1]?.focus();
    return;
  }

  const collectionId = state.assignId;
  const record = state.assignRecord;
  const retryAttempt = state.assignRetryLocked ? assignmentRetryAttempt(collectionId) : null;
  const payload = retryAttempt?.payload || assignmentPayloadFromState();
  const assignedCents = payload.detalles.reduce(
    (sum, detail) => sum + (parseMoneyCents(detail.importe) || 0),
    0
  );

  state.assigning = true;
  renderAssignmentDetails();
  setMessage(elements.assignMessage, "Asignando el saldo a los clientes...");

  let response;
  try {
    response = await apiRequest(`/finanzas/cobranzas/${encodeURIComponent(collectionId)}/asignaciones`, {
      method: "POST",
      body: JSON.stringify(payload)
    });
  } catch (error) {
    state.assigning = false;
    const message = errorMessage(error, "No se pudo asignar el saldo pendiente.");
    if (isDeterministicAssignmentErrorStatus(error?.status)) {
      clearAssignmentRetry(collectionId);
      state.assignIdempotencyKey = createIdempotencyKey();
      renderAssignmentDetails();
      setMessage(elements.assignMessage, message, "error");
      return;
    }

    const retryMessage = `${message} El resultado no se pudo confirmar; reintenta esta misma operación sin cambiar sus datos.`;
    rememberAssignmentRetry(collectionId, payload, retryMessage);
    renderAssignmentDetails();
    setMessage(elements.assignMessage, retryMessage, "error");
    return;
  }

  state.assigning = false;
  clearAssignmentRetry(collectionId);
  const savedRecord = normalizeCollection(detailCollectionFromResponse(response));
  const remainingCents = amountCents(savedRecord.importePendiente);
  const savedCurrency = savedRecord.moneda || record.moneda;
  const successMessage = remainingCents > 0
    ? `${moneyFromCents(assignedCents, savedCurrency)} asignados. Quedan ${moneyFromCents(remainingCents, savedCurrency)} por identificar.`
    : `Saldo pendiente de ${moneyFromCents(assignedCents, savedCurrency)} asignado completamente.`;
  state.collectionRevision += 1;
  elements.assignDialog.close();
  renderCollectionDetailResponse(response, { successMessage });
  void loadCollections({ silent: true, force: true });
}

function renderCollections(records) {
  state.collections = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="8">No hay cobranzas que coincidan con los filtros.</td></tr>';
    return;
  }

  elements.rows.innerHTML = records.map((record) => {
    const canVoid = permissions.void && record.estado === "REGISTRADO";
    const canAssign = canAssignCollection(record);
    const retryPending = Boolean(assignmentRetryAttempt(record.id));
    const destination = [record.entidadNombre, record.cuentaNombre].filter(Boolean).join(" · ") || "Sin destino";
    return `
      <tr class="${record.estado === "ANULADO" ? "is-muted" : ""}">
        <td><strong>${escapeHtml(record.codigo)}</strong><small>${escapeHtml(formatCompanyDateTime(record.fechaHora))}</small></td>
        <td><strong>${escapeHtml(record.cobradorNombre)}</strong></td>
        <td><strong>${escapeHtml(destination)}</strong>${record.proveedorNombre ? `<small>Proveedor: ${escapeHtml(record.proveedorNombre)}</small>` : ""}</td>
        <td><strong>${escapeHtml(record.referencia || "Sin referencia")}</strong></td>
        <td>
          <strong>${escapeHtml(record.detailCount)} ${record.detailCount === 1 ? "abono" : "abonos"}</strong>
          <small>${escapeHtml(formatMoney(record.importeAsignado, record.moneda))} desglosado</small>
          ${Number(record.importePendiente) > 0 ? `<small>${escapeHtml(formatMoney(record.importePendiente, record.moneda))} por identificar</small>` : ""}
          ${statusTag(record.conciliacion)}
        </td>
        <td>${statusTag(record.estado)}</td>
        <td class="fin-text-right fin-table-amount">${escapeHtml(formatMoney(record.importe, record.moneda))}</td>
        <td>
          <div class="fin-collection-row-actions">
            <button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-collection-detail="${record.id}">Ver detalle</button>
            ${canAssign ? `<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-collection-assign="${record.id}">${retryPending ? "Reintentar asignación" : "Asignar saldo"}</button>` : ""}
            ${canVoid ? `<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-collection-void="${record.id}">Anular</button>` : ""}
          </div>
        </td>
      </tr>
    `;
  }).join("");
}

function filterParams() {
  return new URLSearchParams({
    desde: elements.filterFrom.value,
    hasta: elements.filterTo.value,
    cobrador_id: elements.filterCollector.value,
    estado: elements.filterStatus.value,
    conciliacion: elements.filterReconciliation.value,
    buscar: elements.filterSearch.value.trim(),
    per_page: "20",
    page: String(state.page)
  });
}

function updatePagination(currentPage = state.page, lastPage = state.lastPage) {
  state.page = Math.max(1, Number(currentPage) || 1);
  state.lastPage = Math.max(1, Number(lastPage) || 1);
  elements.page.textContent = `Página ${state.page} de ${state.lastPage}`;
  elements.previous.disabled = state.loadingList || state.page <= 1;
  elements.next.disabled = state.loadingList || state.page >= state.lastPage;
}

function startCollectionsLoad({ silent = false } = {}) {
  let request;
  request = performCollectionsLoad({ silent }).finally(() => {
    if (state.listLoadPromise === request) state.listLoadPromise = null;
  });
  state.listLoadPromise = request;
  return request;
}

function loadCollections({ silent = false, force = false } = {}) {
  if (!state.listLoadPromise) return startCollectionsLoad({ silent });
  if (!force) return state.listLoadPromise;

  state.listReloadSilent = state.listReloadRequested
    ? state.listReloadSilent && silent
    : silent;
  state.listReloadRequested = true;

  if (!state.listReloadPromise) {
    let queuedReload;
    queuedReload = (async () => {
      while (state.listLoadPromise) {
        await state.listLoadPromise.catch(() => undefined);
      }
      while (state.listReloadRequested) {
        const queuedSilent = state.listReloadSilent;
        state.listReloadRequested = false;
        state.listReloadSilent = true;
        await startCollectionsLoad({ silent: queuedSilent });
        while (state.listLoadPromise) {
          await state.listLoadPromise.catch(() => undefined);
        }
      }
    })().finally(() => {
      if (state.listReloadPromise === queuedReload) state.listReloadPromise = null;
    });
    state.listReloadPromise = queuedReload;
  }

  return state.listReloadPromise;
}

async function performCollectionsLoad({ silent = false } = {}) {
  if (elements.filterFrom.value && elements.filterTo.value && elements.filterFrom.value > elements.filterTo.value) {
    setMessage(elements.listMessage, "La fecha inicial no puede ser posterior a la fecha final.", "error");
    return;
  }

  const requestedRevision = state.collectionRevision;
  state.loadingList = true;
  updatePagination();
  if (!silent) setMessage(elements.listMessage, "Cargando cobranzas...");

  try {
    const response = await apiRequest(`/finanzas/cobranzas?${filterParams().toString()}`);
    if (requestedRevision !== state.collectionRevision) return;
    const records = responseCollection(response, ["cobranzas", "records", "items"])
      .map(normalizeCollection)
      .filter((record) => record.id);
    const meta = responseMeta(response);
    const currentPage = firstDefined(meta, ["current_page", "currentPage"], firstDefined(response, [
      "current_page",
      "data.current_page",
      "data.data.current_page"
    ], state.page));
    const lastPage = firstDefined(meta, ["last_page", "lastPage"], firstDefined(response, [
      "last_page",
      "data.last_page",
      "data.data.last_page"
    ], 1));

    renderCollections(records);
    updatePagination(currentPage, lastPage);
    setMessage(elements.listMessage, records.length ? `${records.length} cobranza${records.length === 1 ? "" : "s"} en esta página.` : "");
    markFinanceAccessReady();
  } catch (error) {
    if (!silent && state.collections.size === 0) renderCollections([]);
    setMessage(
      elements.listMessage,
      silent
        ? "La asignación se guardó, pero no se pudo actualizar el historial. Usa Actualizar para volver a intentarlo."
        : errorMessage(error, "No se pudo cargar el historial de cobranzas."),
      "error"
    );
  } finally {
    state.loadingList = false;
    updatePagination();
  }
}

function detailCollectionFromResponse(response) {
  const root = dataRoot(response);
  return firstDefined(root, ["cobranza", "collection"], root);
}

function normalizeDetail(record) {
  return {
    ...record,
    clienteNombre: String(firstDefined(record, [
      "cliente_nombre_snapshot",
      "cliente_nombre",
      "cliente.nombre",
      "cliente.nombre_razon_social",
      "customer.name"
    ], "Cliente")),
    clienteDocumento: String(firstDefined(record, [
      "cliente_documento_snapshot",
      "cliente_documento",
      "cliente.numero_documento",
      "customer.document"
    ], "") || ""),
    fechaRecepcion: firstDefined(record, ["fecha_recepcion", "received_at", "fecha"], null),
    importe: firstDefined(record, ["importe", "amount"], "0.00"),
    aplicadoCxc: firstDefined(record, ["importe_aplicado_cxc", "aplicado_cxc", "cxc_aplicado", "applied_receivable"], null),
    saldoFavor: firstDefined(record, ["saldo_favor_cliente", "saldo_favor", "customer_credit"], null),
    movimientoCodigo: String(firstDefined(record, ["movimiento_codigo", "pago.codigo", "movement_code"], "") || "")
  };
}

function detailMoney(value, currency) {
  return value === null || value === undefined || value === "" ? "—" : formatMoney(value, currency);
}

function renderCollectionDetail(source, details) {
  const record = normalizeCollection(source);
  if (record.id) state.collections.set(String(record.id), record);
  const retryAttempt = assignmentRetryAttempt(record.id);
  const normalizedDetails = details.map(normalizeDetail);
  const destination = [record.entidadNombre, record.cuentaNombre].filter(Boolean).join(" · ") || "Sin destino";
  const providerApplied = firstDefined(source, ["importe_aplicado_cxp", "aplicado_cxp", "cxp_aplicado"], null);
  const providerCredit = firstDefined(source, ["saldo_favor_proveedor", "provider_credit"], null);
  const pendingPaymentCode = String(firstDefined(record.pendiente || {}, [
    "pago.codigo",
    "payment.code",
    "movimiento_codigo"
  ], "") || "");

  elements.detailTitle.textContent = record.codigo || "Detalle de cobranza";
  elements.detailContent.innerHTML = `
    <div class="fin-collection-detail-facts">
      <article><span>Fecha del depósito</span><strong>${escapeHtml(formatCompanyDateTime(record.fechaHora))}</strong></article>
      <article><span>Cobrador</span><strong>${escapeHtml(record.cobradorNombre)}</strong></article>
      <article><span>Cuenta de destino</span><strong>${escapeHtml(destination)}</strong></article>
      <article><span>Número de operación</span><strong>${escapeHtml(record.referencia || "Sin referencia")}</strong></article>
      <article><span>Estado</span><strong>${statusTag(record.estado)}</strong></article>
      <article><span>Conciliación</span><strong>${statusTag(record.conciliacion)}</strong></article>
      <article><span>Importe desglosado</span><strong>${escapeHtml(formatMoney(record.importeAsignado, record.moneda))}</strong></article>
      <article><span>Pendiente por identificar</span><strong>${escapeHtml(formatMoney(record.importePendiente, record.moneda))}</strong></article>
      <article class="is-total"><span>Total depositado</span><strong>${escapeHtml(formatMoney(record.importe, record.moneda))}</strong></article>
    </div>
    ${retryAttempt ? `
      <div class="fin-collection-detail-note is-pending">
        <strong>Asignación pendiente de confirmar</strong>
        <p>El servidor no confirmó la respuesta anterior. Reintenta exactamente la misma operación para comprobarla sin duplicarla.</p>
        <div class="fin-collection-detail-note-actions"><button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-collection-assign="${record.id}">Reintentar asignación</button></div>
      </div>
    ` : Number(record.importePendiente) > 0 ? `
      <div class="fin-collection-detail-note is-pending">
        <strong>${escapeHtml(formatMoney(record.importePendiente, record.moneda))} pendiente por identificar</strong>
        <p>Este importe forma parte del voucher y de su efecto financiero, pero no está atribuido a ningún cliente.${pendingPaymentCode ? ` Movimiento asociado: ${escapeHtml(pendingPaymentCode)}.` : ""}</p>
        ${canAssignCollection(record) ? `<div class="fin-collection-detail-note-actions"><button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-collection-assign="${record.id}">Asignar saldo</button></div>` : ""}
      </div>
    ` : ""}
    ${record.proveedorNombre ? `
      <div class="fin-collection-provider-result">
        <div><span>Proveedor beneficiario</span><strong>${escapeHtml(record.proveedorNombre)}</strong></div>
        <div><span>Aplicado a cuentas por pagar</span><strong>${escapeHtml(detailMoney(providerApplied, record.moneda))}</strong></div>
        <div><span>Saldo a favor con proveedor</span><strong>${escapeHtml(detailMoney(providerCredit, record.moneda))}</strong></div>
      </div>
    ` : ""}
    <section class="fin-collection-detail-breakdown" aria-labelledby="collectionDetailBreakdownTitle">
      <div class="fin-section-head"><div><p class="fin-eyebrow">Origen del efectivo</p><h2 id="collectionDetailBreakdownTitle">Abonos incluidos</h2></div><span class="fin-badge">${normalizedDetails.length} abono${normalizedDetails.length === 1 ? "" : "s"}</span></div>
      <div class="fin-table-wrap">
        <table class="fin-table fin-collection-detail-table">
          <thead><tr><th>Cliente</th><th>Fecha de recepción</th><th>Movimiento</th><th class="fin-text-right">Entregado</th><th class="fin-text-right">Aplicado a deuda</th><th class="fin-text-right">Saldo a favor</th></tr></thead>
          <tbody>
            ${normalizedDetails.length ? normalizedDetails.map((detail) => `
              <tr>
                <td><strong>${escapeHtml(detail.clienteNombre)}</strong>${detail.clienteDocumento ? `<small>${escapeHtml(detail.clienteDocumento)}</small>` : ""}</td>
                <td>${escapeHtml(String(detail.fechaRecepcion || "Sin fecha"))}</td>
                <td>${escapeHtml(detail.movimientoCodigo || "—")}</td>
                <td class="fin-text-right fin-table-amount">${escapeHtml(formatMoney(detail.importe, record.moneda))}</td>
                <td class="fin-text-right">${escapeHtml(detailMoney(detail.aplicadoCxc, record.moneda))}</td>
                <td class="fin-text-right">${escapeHtml(detailMoney(detail.saldoFavor, record.moneda))}</td>
              </tr>
            `).join("") : '<tr><td class="fin-empty-cell" colspan="6">El detalle no contiene abonos.</td></tr>'}
          </tbody>
        </table>
      </div>
    </section>
    ${record.observaciones ? `<div class="fin-collection-detail-note"><strong>Observaciones</strong><p>${escapeHtml(record.observaciones)}</p></div>` : ""}
    ${record.estado === "ANULADO" && record.motivoAnulacion ? `<div class="fin-collection-detail-note is-void"><strong>Motivo de anulación</strong><p>${escapeHtml(record.motivoAnulacion)}</p></div>` : ""}
  `;
  elements.detailContent.hidden = false;
}

function renderCollectionDetailResponse(response, { successMessage = "" } = {}) {
  const source = detailCollectionFromResponse(response);
  const details = responseCollection(response, ["detalles", "details", "cobranza.detalles", "collection.details"]);
  renderCollectionDetail(source, details);
  setMessage(elements.detailMessage, successMessage, successMessage ? "success" : undefined);
  if (!elements.detailDialog.open) elements.detailDialog.showModal();
  markFinanceAccessReady();
}

async function openCollectionDetail(id, { successMessage = "" } = {}) {
  elements.detailTitle.textContent = "Detalle de cobranza";
  elements.detailContent.hidden = true;
  elements.detailContent.innerHTML = "";
  setMessage(elements.detailMessage, "Cargando detalle...");
  if (!elements.detailDialog.open) elements.detailDialog.showModal();

  try {
    const response = await apiRequest(`/finanzas/cobranzas/${encodeURIComponent(id)}`);
    renderCollectionDetailResponse(response, { successMessage });
    return true;
  } catch (error) {
    setMessage(elements.detailMessage, errorMessage(error, "No se pudo cargar el detalle de la cobranza."), "error");
    return false;
  }
}

function openVoidDialog(id) {
  const record = state.collections.get(String(id));
  if (!record || !permissions.void || record.estado !== "REGISTRADO") return;
  state.voidId = record.id;
  elements.voidDescription.textContent = `Se anulará ${record.codigo} por ${formatMoney(record.importe, record.moneda)}. El sistema reversará los abonos de los clientes y el efecto en la cuenta o proveedor, conservando el historial.`;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage, "");
  elements.voidDialog.showModal();
  window.setTimeout(() => elements.voidReason.focus(), 40);
}

async function voidCollection(event) {
  event.preventDefault();
  if (!state.voidId || !permissions.void || state.voiding) return;
  const reason = elements.voidReason.value.trim();
  if (reason.length < 5) {
    setMessage(elements.voidMessage, "Escribe un motivo de al menos 5 caracteres.", "error");
    elements.voidReason.focus();
    return;
  }

  state.voiding = true;
  elements.voidSubmit.disabled = true;
  setMessage(elements.voidMessage, "Anulando y restaurando los saldos...");
  try {
    await apiRequest(`/finanzas/cobranzas/${encodeURIComponent(state.voidId)}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    state.voidId = null;
    elements.voidDialog.close();
    await loadCollections();
    setMessage(elements.listMessage, "Cobranza anulada. Los saldos fueron reversados y la trazabilidad se conservó.", "success");
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular la cobranza."), "error");
  } finally {
    state.voiding = false;
    elements.voidSubmit.disabled = !permissions.void;
  }
}

function clearFilters() {
  elements.filters.reset();
  elements.filterFrom.value = currentMonthStart();
  elements.filterTo.value = todayValue();
  populateCollectorSelects(elements.collector.value, "");
  state.page = 1;
  void loadCollections();
}

function applyPermissions() {
  elements.manageCollectors.disabled = !permissions.manage;
  elements.addDetail.disabled = !permissions.manage;
  elements.reset.disabled = !permissions.manage;
  elements.assignAddDetail.disabled = !permissions.manage;
  elements.assignSubmit.disabled = true;
  elements.collectorForm.querySelectorAll("input, button").forEach((field) => { field.disabled = !permissions.manage; });
  if (!permissions.manage) {
    elements.form.querySelectorAll("input, select, textarea, button").forEach((field) => { field.disabled = true; });
    setMessage(elements.message, "Tienes acceso de consulta. Se requiere PAGOS_REGISTRAR para crear cobranzas o administrar cobradores.", "error");
  }
}

elements.total.addEventListener("input", () => {
  invalidatePendingConfirmation();
  updateSummary();
});
elements.collector.addEventListener("change", updateSummary);
elements.reference.addEventListener("input", updateSummary);
elements.dateTime.addEventListener("change", () => {
  renderDetails();
  updateSummary();
});
elements.destination.addEventListener("change", updateDestination);
elements.currency.addEventListener("change", () => {
  invalidatePendingConfirmation();
  renderDetails();
});
elements.pendingConfirmation.addEventListener("change", () => {
  if (elements.pendingConfirmation.checked) {
    const totalCents = parseMoneyCents(elements.total.value) ?? 0;
    const pendingCents = totalCents - detailState().sumCents;
    elements.pendingConfirmation.dataset.amount = String(Math.max(0, pendingCents));
  } else {
    delete elements.pendingConfirmation.dataset.amount;
  }
  updateSummary();
});
elements.addDetail.addEventListener("click", () => {
  invalidatePendingConfirmation();
  state.details.push(newDetail());
  renderDetails();
  elements.details.querySelector("[data-detail-key]:last-child select")?.focus();
});
elements.details.addEventListener("input", (event) => {
  const input = event.target.closest("[data-detail-field]");
  if (!input) return;
  const article = input.closest("[data-detail-key]");
  const detail = state.details.find((item) => String(item.key) === String(article?.dataset.detailKey));
  if (!detail) return;
  invalidatePendingConfirmation();
  detail[input.dataset.detailField] = input.value;
  updateSummary();
});
elements.details.addEventListener("change", (event) => {
  const input = event.target.closest("[data-detail-field]");
  if (!input) return;
  const article = input.closest("[data-detail-key]");
  const detail = state.details.find((item) => String(item.key) === String(article?.dataset.detailKey));
  if (!detail) return;
  invalidatePendingConfirmation();
  detail[input.dataset.detailField] = input.value;
  if (input.dataset.detailField === "cliente_id") renderDetails();
  else updateSummary();
});
elements.details.addEventListener("click", (event) => {
  const button = event.target.closest("[data-detail-remove]");
  if (!button || state.details.length === 1 || !permissions.manage) return;
  invalidatePendingConfirmation();
  state.details = state.details.filter((detail) => String(detail.key) !== String(button.dataset.detailRemove));
  renderDetails();
});
elements.form.addEventListener("submit", saveCollection);
elements.reset.addEventListener("click", () => resetCollection());
elements.manageCollectors.addEventListener("click", () => {
  if (!permissions.manage) return;
  resetCollectorForm();
  renderCollectorList();
  elements.collectorDialog.showModal();
  window.setTimeout(() => elements.collectorName.focus(), 40);
});
elements.collectorForm.addEventListener("submit", saveCollector);
elements.collectorCancel.addEventListener("click", resetCollectorForm);
elements.collectorList.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-collector-edit]");
  const toggle = event.target.closest("[data-collector-toggle]");
  if (edit) editCollector(edit.dataset.collectorEdit);
  if (toggle) void toggleCollector(toggle.dataset.collectorToggle);
});
elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  void loadCollections();
});
elements.clearFilters.addEventListener("click", clearFilters);
elements.refresh.addEventListener("click", () => void loadCollections());
elements.previous.addEventListener("click", () => {
  if (state.page <= 1) return;
  state.page -= 1;
  void loadCollections();
});
elements.next.addEventListener("click", () => {
  if (state.page >= state.lastPage) return;
  state.page += 1;
  void loadCollections();
});
elements.rows.addEventListener("click", (event) => {
  const detail = event.target.closest("[data-collection-detail]");
  const assign = event.target.closest("[data-collection-assign]");
  const voidButton = event.target.closest("[data-collection-void]");
  if (detail) void openCollectionDetail(detail.dataset.collectionDetail);
  if (assign) openAssignmentDialog(assign.dataset.collectionAssign);
  if (voidButton) openVoidDialog(voidButton.dataset.collectionVoid);
});
elements.detailContent.addEventListener("click", (event) => {
  const assign = event.target.closest("[data-collection-assign]");
  if (assign) openAssignmentDialog(assign.dataset.collectionAssign);
});
elements.assignAddDetail.addEventListener("click", () => {
  if (!permissions.manage
    || state.assigning
    || state.assignRetryLocked
    || !state.assignRecord
    || state.assignDetails.length >= MAX_PENDING_ASSIGNMENT_DETAILS) return;
  state.assignDetails.push(newAssignmentDetail());
  renderAssignmentDetails();
  elements.assignDetails.querySelector("[data-assignment-key]:last-child select")?.focus();
});
elements.assignDetails.addEventListener("input", (event) => {
  const input = event.target.closest("[data-assignment-field]");
  if (!input || state.assigning || state.assignRetryLocked) return;
  const article = input.closest("[data-assignment-key]");
  const detail = state.assignDetails.find((item) => String(item.key) === String(article?.dataset.assignmentKey));
  if (!detail) return;
  detail[input.dataset.assignmentField] = input.value;
  setMessage(elements.assignMessage, "");
  updateAssignmentSummary();
});
elements.assignDetails.addEventListener("change", (event) => {
  const input = event.target.closest("[data-assignment-field]");
  if (!input || state.assigning || state.assignRetryLocked) return;
  const article = input.closest("[data-assignment-key]");
  const detail = state.assignDetails.find((item) => String(item.key) === String(article?.dataset.assignmentKey));
  if (!detail) return;
  detail[input.dataset.assignmentField] = input.value;
  setMessage(elements.assignMessage, "");
  updateAssignmentSummary();
});
elements.assignDetails.addEventListener("click", (event) => {
  const button = event.target.closest("[data-assignment-remove]");
  if (!button
    || state.assignDetails.length === 1
    || state.assigning
    || state.assignRetryLocked
    || !permissions.manage) return;
  state.assignDetails = state.assignDetails.filter((detail) => String(detail.key) !== String(button.dataset.assignmentRemove));
  setMessage(elements.assignMessage, "");
  renderAssignmentDetails();
});
elements.assignForm.addEventListener("submit", saveAssignment);
elements.assignDialog.addEventListener("cancel", (event) => {
  if (state.assigning) event.preventDefault();
});
elements.assignDialog.addEventListener("close", () => {
  if (!state.assigning) resetAssignmentState();
});
elements.voidForm.addEventListener("submit", voidCollection);
document.querySelectorAll("[data-collection-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => button.closest("dialog")?.close());
});

async function initialize() {
  elements.filterFrom.value = currentMonthStart();
  elements.filterTo.value = todayValue();
  elements.dateTime.value = dateTimeNow();
  if (!state.details.length) state.details = [newDetail()];
  applyPermissions();
  renderDetails();

  try {
    await loadCatalog();
    elements.dateTime.value = dateTimeNow();
    elements.filterFrom.value = currentMonthStart();
    elements.filterTo.value = todayValue();
    renderDetails();
    await loadCollections();
  } catch (error) {
    setMessage(elements.listMessage, errorMessage(error, "No se pudo iniciar la vista de cobranzas."), "error");
  }
}

initFinanceAccess(initialize);
void initialize();
