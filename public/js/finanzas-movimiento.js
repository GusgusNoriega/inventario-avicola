import { apiRequest } from "./api-client.js";
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
    hint: "En un pago directo el importe puede aplicarse una vez a CXC y una vez a CXP; ambos lados se validan por separado."
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
    hint: "El importe no aplicado quedará como anticipo al proveedor."
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
  }
};

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
  date: document.getElementById("financeMovementDate"),
  method: document.getElementById("financeMovementMethod"),
  amount: document.getElementById("financeMovementAmount"),
  currency: document.getElementById("financeMovementCurrency"),
  reference: document.getElementById("financeMovementReference"),
  notes: document.getElementById("financeMovementNotes"),
  total: document.getElementById("financeMovementTotal"),
  cxcSummaryLine: document.getElementById("financeCxcSummaryLine"),
  cxcApplied: document.getElementById("financeMovementCxcApplied"),
  cxpSummaryLine: document.getElementById("financeCxpSummaryLine"),
  cxpApplied: document.getElementById("financeMovementCxpApplied"),
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
  cxpAvailable: document.getElementById("financeCxpAvailable")
};

const state = {
  clients: [],
  providers: [],
  methods: [],
  entities: [],
  accounts: [],
  debts: { CXC: [], CXP: [] },
  applications: { CXC: new Map(), CXP: new Map() },
  requestSequence: { CXC: 0, CXP: 0 },
  idempotencyKey: createIdempotencyKey(),
  saving: false
};

function currentModeKey() {
  return document.querySelector('[name="financeMovementType"]:checked')?.value || "COBRO_CLIENTE";
}

function currentMode() {
  return MODES[currentModeKey()];
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

  const destinationAccounts = mode.destination === "EXTERNA" ? externalAccounts : ownAccounts;
  fillSelect(elements.destination, destinationAccounts, {
    placeholder: destinationAccounts.length
      ? mode.destination === "EXTERNA" ? "Selecciona una cuenta del proveedor" : "Selecciona una cuenta propia"
      : mode.destination === "EXTERNA" ? "No hay cuentas externas para este proveedor" : "No hay cuentas propias activas",
    selected: currentDestination,
    label: accountName
  });
  elements.origin.disabled = mode.origin && !ownAccounts.length;
  elements.destination.disabled = !destinationAccounts.length;
}

function updateMethodConstraints() {
  const method = selectedMethod();
  const requiresReference = Boolean(firstDefined(method || {}, ["requiere_referencia", "requires_reference"], false));
  elements.reference.required = requiresReference;
  elements.reference.placeholder = requiresReference
    ? "Referencia obligatoria para este método"
    : "Ej: OP-384729";
  populateAccounts();
}

function updateMode() {
  const mode = currentMode();
  elements.flowBadge.textContent = mode.badge;
  elements.clientField.hidden = !mode.client;
  elements.client.required = mode.clientRequired;
  elements.clientLabel.innerHTML = currentModeKey() === "REEMBOLSO_CLIENTE"
    ? "Cliente que recibe <b>*</b>"
    : mode.clientRequired ? "Cliente que paga <b>*</b>" : "Cliente asignado (opcional)";
  elements.clientHelp.textContent = currentModeKey() === "COBRO_MINORISTA"
    ? "Déjalo vacío cuando la venta minorista no tenga cliente asignado."
    : "Se mostrarán sus documentos pendientes.";
  elements.providerField.hidden = !mode.provider;
  elements.provider.required = mode.provider;
  elements.originField.hidden = !mode.origin;
  elements.origin.required = mode.origin;
  elements.destinationField.hidden = !mode.destination;
  elements.destination.required = Boolean(mode.destination);
  elements.destinationLabel.innerHTML = `${mode.destinationLabel} <b>*</b>`;
  elements.destinationHelp.textContent = mode.destinationHelp;
  elements.cxcPanel.hidden = !mode.cxc;
  elements.cxcTitle.textContent = currentModeKey() === "REEMBOLSO_CLIENTE"
    ? "Abonos por devolver al cliente"
    : "Deudas del cliente";
  elements.cxpPanel.hidden = !mode.cxp;
  elements.cxcSummaryLine.hidden = !mode.cxc;
  elements.cxpSummaryLine.hidden = !mode.cxp;
  elements.applicationColumns.classList.toggle("is-single", !(mode.cxc && mode.cxp));
  elements.applicationHint.textContent = mode.hint;

  if (!mode.client) {
    elements.client.value = "";
    clearApplications("CXC");
  }
  if (!mode.provider) {
    elements.provider.value = "";
    clearApplications("CXP");
  }

  populateAccounts();
  renderDebt("CXC");
  renderDebt("CXP");
  updateSummary();
  void loadVisiblePortfolio();
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
        </span>
        <span class="fin-debt-amount">S/<input type="number" min="0.01" max="${debt.pending}" step="0.01" inputmode="decimal" data-debt-amount="${side}" data-debt-id="${escapeHtml(debt.id)}" value="${applied ? applied.toFixed(2) : ""}" ${checked ? "" : "disabled"} aria-label="Importe aplicado a ${escapeHtml(debt.number)}"></span>
      </label>`;
  }).join("");
}

function updateSummary() {
  const mode = currentMode();
  const amount = movementAmount();
  const cxc = appliedTotal("CXC");
  const cxp = appliedTotal("CXP");
  const consumed = Math.max(mode.cxc ? cxc : 0, mode.cxp ? cxp : 0);
  const unapplied = Math.max(0, amount - consumed);
  const currency = elements.currency.value;

  elements.total.textContent = formatMoney(amount, currency);
  elements.cxcApplied.textContent = formatMoney(cxc, currency);
  elements.cxpApplied.textContent = formatMoney(cxp, currency);
  elements.unapplied.textContent = formatMoney(unapplied, currency);
  elements.unapplied.classList.toggle("is-error", consumed > amount);
  elements.applicationsPanel.classList.toggle("is-amount-required", amount <= 0);
  elements.applicationsInstructions.textContent = amount > 0
    ? "Marca las ventas o compras que cancela este movimiento. Los abonos parciales son válidos."
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
    throw new Error("Selecciona el cliente que realizó el pago.");
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
    throw new Error("Indica la fecha y hora del movimiento.");
  }
  if (!elements.method.value) {
    elements.method.focus();
    throw new Error("Selecciona el método de pago.");
  }
  if (elements.reference.required && !elements.reference.value.trim()) {
    elements.reference.focus();
    throw new Error("Ingresa el número de operación o referencia requerido por el método de pago.");
  }
  if (amount <= 0) {
    elements.amount.focus();
    throw new Error("Ingresa un importe mayor a cero.");
  }
  validateApplications("CXC");
  validateApplications("CXP");
  if (mode.cxc && cxc > amount + .001) throw new Error("Lo aplicado a cuentas por cobrar supera el importe del movimiento.");
  if (mode.cxp && cxp > amount + .001) throw new Error("Lo aplicado a cuentas por pagar supera el importe del movimiento.");
  if (modeKey === "PAGO_DIRECTO" && (cxc <= 0 || cxp <= 0)) {
    throw new Error("Un pago directo debe aplicarse al menos a una deuda del cliente y a una deuda del proveedor.");
  }
  if (modeKey === "REEMBOLSO_CLIENTE" && (cxc <= 0 || Math.abs(cxc - amount) > .001)) {
    throw new Error("El reembolso debe aplicarse completamente a uno o mas abonos del cliente.");
  }

  return {
    idempotency_key: state.idempotencyKey,
    tipo: modeKey,
    fecha_hora: elements.date.value,
    cliente_id: mode.client && elements.client.value ? idValue(elements.client.value) : null,
    proveedor_id: mode.provider ? idValue(elements.provider.value) : null,
    cuenta_origen_id: mode.origin ? idValue(elements.origin.value) : null,
    cuenta_destino_id: mode.destination ? idValue(elements.destination.value) : null,
    metodo_pago_id: idValue(elements.method.value),
    moneda: elements.currency.value,
    importe: amount.toFixed(2),
    referencia: optionalString(elements.reference.value),
    observaciones: optionalString(elements.notes.value),
    aplicaciones: applicationsPayload()
  };
}

function resetMovement({ keepMessage = false } = {}) {
  elements.form.reset();
  elements.date.value = toLocalDateTimeValue();
  state.debts = { CXC: [], CXP: [] };
  state.applications.CXC.clear();
  state.applications.CXP.clear();
  state.idempotencyKey = createIdempotencyKey();
  if (!keepMessage) setMessage(elements.message);
  [elements.cxcMessage, elements.cxpMessage].forEach((message) => setMessage(message));
  updateMethodConstraints();
  updateMode();
}

async function saveMovement(event) {
  event.preventDefault();
  if (state.saving) return;
  setMessage(elements.message);

  try {
    const payload = movementPayload();
    state.saving = true;
    elements.save.disabled = true;
    elements.reset.disabled = true;
    elements.save.textContent = "Registrando...";

    const response = await apiRequest("/finanzas/movimientos", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    const movementNumber = firstDefined(response, ["data.numero", "data.id", "numero", "id"], null);
    resetMovement({ keepMessage: true });
    setMessage(elements.message, response?.message || `Movimiento${movementNumber ? ` #${movementNumber}` : ""} registrado correctamente.`, "success");
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudo registrar el movimiento."), "error");
  } finally {
    state.saving = false;
    elements.save.disabled = false;
    elements.reset.disabled = false;
    elements.save.textContent = "Registrar movimiento";
  }
}

elements.typeInputs.forEach((input) => input.addEventListener("change", updateMode));
elements.client.addEventListener("change", () => {
  clearApplications("CXC");
  void loadPortfolio("CXC");
});
elements.provider.addEventListener("change", () => {
  clearApplications("CXP");
  populateAccounts();
  void loadPortfolio("CXP");
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
  void loadVisiblePortfolio();
});
elements.refresh.addEventListener("click", loadVisiblePortfolio);
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

initFinanceAccess(loadCatalogData);
elements.date.value = toLocalDateTimeValue();
if (queryPrefill.type) {
  const requestedMode = document.querySelector(`[name="financeMovementType"][value="${queryPrefill.type}"]`);
  if (requestedMode) requestedMode.checked = true;
}
updateMode();
void loadCatalogData();
