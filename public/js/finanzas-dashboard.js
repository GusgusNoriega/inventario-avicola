import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  fillSelect,
  firstDefined,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  numericValue,
  optionLabel,
  responseCollection,
  responseMeta,
  setMessage
} from "./finanzas-common.js";

const MOVEMENT_LABELS = {
  COBRO_CLIENTE: "Cliente → empresa",
  PAGO_DIRECTO: "Cliente → proveedor",
  PAGO_PROVEEDOR: "Empresa → proveedor",
  SALDO_FAVOR_PROVEEDOR: "Saldo anterior con proveedor",
  COBRO_MINORISTA: "Cobro minorista",
  REEMBOLSO_CLIENTE: "Empresa → cliente",
  SALDO_INICIAL: "Saldo inicial",
  TRANSFERENCIA_INTERNA: "Transferencia interna",
  AJUSTE: "Ajuste de saldo",
  AJUSTE_ENTRADA: "Ajuste de entrada",
  AJUSTE_SALIDA: "Ajuste de salida",
  REVERSO: "Reverso"
};

const PROVIDER_CREDIT_TYPES = new Set(["PAGO_PROVEEDOR", "SALDO_FAVOR_PROVEEDOR"]);
const requestedProviderId = new URLSearchParams(window.location.search).get("proveedor_id") || "";

const elements = {
  available: document.getElementById("financeAvailableBalance"),
  receivable: document.getElementById("financeReceivableBalance"),
  payable: document.getElementById("financePayableBalance"),
  directPaid: document.getElementById("financeDirectPaid"),
  providerCredit: document.getElementById("financeProviderCreditBalance"),
  balanceMessage: document.getElementById("financeBalanceMessage"),
  balances: document.getElementById("financeAccountBalances"),
  filters: document.getElementById("financeTraceFilters"),
  filterFrom: document.getElementById("financeFilterFrom"),
  filterTo: document.getElementById("financeFilterTo"),
  filterClient: document.getElementById("financeFilterClient"),
  filterProvider: document.getElementById("financeFilterProvider"),
  filterEntity: document.getElementById("financeFilterEntity"),
  filterMethod: document.getElementById("financeFilterMethod"),
  filterSearch: document.getElementById("financeFilterSearch"),
  clearFilters: document.getElementById("financeClearFilters"),
  traceMessage: document.getElementById("financeTraceMessage"),
  traceRows: document.getElementById("financeTraceRows"),
  tracePrevious: document.getElementById("financeTracePrevious"),
  traceNext: document.getElementById("financeTraceNext"),
  tracePage: document.getElementById("financeTracePage"),
  movementMessage: document.getElementById("financeMovementMessage"),
  recentMovements: document.getElementById("financeRecentMovements"),
  advanceMessage: document.getElementById("financeAdvanceMessage"),
  advanceList: document.getElementById("financeAdvanceList"),
  advanceDialog: document.getElementById("financeAdvanceDialog"),
  advanceForm: document.getElementById("financeAdvanceForm"),
  advanceClose: document.getElementById("financeAdvanceClose"),
  advanceCancel: document.getElementById("financeAdvanceCancel"),
  advanceSubmit: document.getElementById("financeAdvanceSubmit"),
  advanceCode: document.getElementById("financeAdvanceCode"),
  advanceProvider: document.getElementById("financeAdvanceProvider"),
  advanceReference: document.getElementById("financeAdvanceReference"),
  advanceDate: document.getElementById("financeAdvanceDate"),
  advanceTotal: document.getElementById("financeAdvanceTotal"),
  advanceAvailable: document.getElementById("financeAdvanceAvailable"),
  advanceSelected: document.getElementById("financeAdvanceSelected"),
  advanceRemaining: document.getElementById("financeAdvanceRemaining"),
  advanceDebtTotal: document.getElementById("financeAdvanceDebtTotal"),
  advanceDebtMessage: document.getElementById("financeAdvanceDebtMessage"),
  advanceDebtList: document.getElementById("financeAdvanceDebtList"),
  advanceFormMessage: document.getElementById("financeAdvanceFormMessage")
};

const state = {
  page: 1,
  lastPage: 1,
  perPage: 25,
  loadingTrace: false,
  advances: [],
  advancePayment: null,
  advanceDebts: [],
  advanceApplications: new Map(),
  advanceIdempotencyKey: createIdempotencyKey(),
  advanceRequestSequence: 0,
  savingAdvance: false
};

function partyLabel(record, keys, fallback = "—") {
  const party = firstDefined(record, keys, null);
  if (party && typeof party === "object") return optionLabel(party, fallback);
  return party ? String(party) : fallback;
}

function movementType(record) {
  return String(firstDefined(record, ["tipo", "type", "movimiento.tipo", "movement.type"], "MOVIMIENTO")).toUpperCase();
}

function movementAmount(record) {
  return numericValue(firstDefined(record, ["importe", "monto", "amount", "movimiento.importe", "movement.amount"], 0));
}

function movementCurrency(record) {
  return String(firstDefined(record, ["moneda", "currency", "movimiento.moneda"], "PEN"));
}

function movementApplication(record) {
  return firstDefined(record, ["aplicacion", "application", "resumen_aplicacion"], null);
}

function movementUnapplied(record) {
  return numericValue(firstDefined(movementApplication(record) || {}, [
    "importe_sin_aplicar",
    "unapplied",
    "available"
  ], 0));
}

function movementApplicationStatus(record) {
  return String(firstDefined(movementApplication(record) || {}, ["estado", "status"], "")).toUpperCase();
}

function formatAdvanceDate(value) {
  const match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) return formatDateTime(value);

  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "short",
    year: "numeric"
  }).format(date);
}

function currencyInputPrefix(currency) {
  return ({ PEN: "S/", USD: "US$", EUR: "€" })[String(currency).toUpperCase()] || currency;
}

async function allPaginatedRecords(endpoint, keys) {
  const records = [];
  let page = 1;
  let lastPage = 1;

  do {
    const separator = endpoint.includes("?") ? "&" : "?";
    const response = await apiRequest(`${endpoint}${separator}page=${page}`);
    records.push(...responseCollection(response, keys));
    lastPage = Math.max(1, numericValue(firstDefined(responseMeta(response), ["last_page"], 1), 1));
    page += 1;
  } while (page <= lastPage);

  return records;
}

function isOutgoing(record, type = movementType(record)) {
  if (type === "SALDO_FAVOR_PROVEEDOR") return false;
  const direction = String(firstDefined(record, ["direccion", "direction", "movimiento.direccion"], "")).toUpperCase();
  if (direction === "EGRESO") return true;
  if (direction === "INGRESO") return false;
  return ["PAGO_PROVEEDOR", "REEMBOLSO_CLIENTE", "AJUSTE_SALIDA"].includes(type);
}

function monetaryMetric(value, currency = "PEN") {
  if (Array.isArray(value)) {
    const row = value.find((item) => String(firstDefined(item, ["moneda", "currency"], "PEN")) === currency) || value[0];
    return numericValue(firstDefined(row || {}, ["saldo", "importe", "total", "amount"], 0));
  }
  if (value && typeof value === "object") {
    return numericValue(firstDefined(value, ["saldo", "importe", "total", "amount", currency], 0));
  }
  return numericValue(value);
}

function renderBalances(accounts) {
  if (!accounts.length) {
    elements.balances.innerHTML = `
      <div class="fin-account-balance-card">
        <header><strong>Sin cuentas propias</strong><span>Pendiente</span></header>
        <small>Registra una empresa propia y al menos una cuenta para empezar a controlar saldos.</small>
        <strong>S/ 0.00</strong>
      </div>`;
    return;
  }

  elements.balances.innerHTML = accounts.map((rawAccount) => {
    const account = rawAccount.cuenta || rawAccount.account || rawAccount;
    const entity = rawAccount.entidad || rawAccount.entity || account.entidad || {};
    const currency = String(firstDefined(account, ["moneda", "currency"], "PEN"));
    const balance = firstDefined(rawAccount, ["saldo_actual", "saldo", "balance", "importe"],
      firstDefined(account, ["saldo_actual", "saldo", "balance"], 0));
    const type = String(firstDefined(account, ["tipo", "type"], "CUENTA"));
    const name = String(firstDefined(account, ["alias", "nombre", "name"], "Cuenta sin nombre"));
    const institution = firstDefined(account, ["banco", "bank", "alias", "numero"], "Sin dato adicional");

    return `
      <article class="fin-account-balance-card">
        <header>
          <strong>${escapeHtml(name)}</strong>
          <span>${escapeHtml(type)}</span>
        </header>
        <small>${escapeHtml(optionLabel(entity, String(institution)))}</small>
        <strong>${escapeHtml(formatMoney(balance, currency))}</strong>
      </article>`;
  }).join("");
}

async function loadBalances() {
  setMessage(elements.balanceMessage, "Cargando saldos...");

  try {
    const response = await apiRequest("/finanzas/saldos");
    const accounts = responseCollection(response, ["cuentas", "accounts", "saldos", "balances", "detalle"]);
    const totalsByCurrency = firstDefined(response, ["totales_por_moneda", "data.totales_por_moneda"], []);
    const penTotal = Array.isArray(totalsByCurrency)
      ? totalsByCurrency.find((row) => String(firstDefined(row, ["moneda", "currency"], "")) === "PEN")
      : null;
    const calculatedBalance = accounts.reduce((total, account) => {
      const currency = String(firstDefined(account, ["moneda", "currency", "cuenta.moneda"], "PEN"));
      return currency === "PEN"
        ? total + numericValue(firstDefined(account, ["saldo_actual", "saldo", "balance", "importe", "cuenta.saldo_actual"], 0))
        : total;
    }, 0);
    const portfolio = firstDefined(response, ["cartera", "data.cartera"], {});
    const providerPayments = firstDefined(response, ["pagos_proveedores", "data.pagos_proveedores"], {});
    const providerCredit = firstDefined(response, ["saldo_favor_proveedores", "data.saldo_favor_proveedores"], {});

    elements.available.textContent = formatMoney(firstDefined(penTotal || {}, ["saldo"], calculatedBalance));
    elements.receivable.textContent = formatMoney(monetaryMetric(firstDefined(portfolio, ["por_cobrar", "cxc"], 0)));
    elements.payable.textContent = formatMoney(monetaryMetric(firstDefined(portfolio, ["por_pagar", "cxp"], 0)));
    elements.directPaid.textContent = formatMoney(monetaryMetric(firstDefined(providerPayments, ["directos_clientes", "pagos_directos"], 0)));
    elements.providerCredit.textContent = formatMoney(monetaryMetric(firstDefined(providerCredit, ["disponible", "available"], 0)));

    renderBalances(accounts);
    setMessage(elements.balanceMessage, accounts.length ? `Actualizado · ${accounts.length} cuenta${accounts.length === 1 ? "" : "s"}` : "");
    markFinanceAccessReady();
  } catch (error) {
    renderBalances([]);
    setMessage(elements.balanceMessage, errorMessage(error, "No se pudieron consultar los saldos."), "error");
  }
}

function catalogCollection(response, keys) {
  return responseCollection(response, keys);
}

async function loadCatalog() {
  try {
    const response = await apiRequest("/finanzas/catalogo");
    const clients = catalogCollection(response, ["clientes", "clients", "catalogo.clientes"]);
    const providers = catalogCollection(response, ["proveedores", "providers", "catalogo.proveedores"]);
    const entities = catalogCollection(response, ["entidades", "entities", "catalogo.entidades"]);
    const methods = catalogCollection(response, ["metodos_pago", "metodos", "payment_methods", "catalogo.metodos_pago"]);

    fillSelect(elements.filterClient, clients, { placeholder: "Todos los clientes" });
    fillSelect(elements.filterProvider, providers, { placeholder: "Todos los proveedores" });
    fillSelect(elements.filterEntity, entities, { placeholder: "Todas las empresas" });
    fillSelect(elements.filterMethod, methods, { placeholder: "Todos los métodos" });
    markFinanceAccessReady();
  } catch (error) {
    if (Number(error?.status) !== 401) setMessage(elements.traceMessage, errorMessage(error, "No se pudieron cargar los filtros."), "error");
  }
}

function filterQuery() {
  const params = new URLSearchParams({
    page: String(state.page),
    per_page: String(state.perPage)
  });

  const filters = {
    desde: elements.filterFrom.value,
    hasta: elements.filterTo.value,
    cliente_id: elements.filterClient.value,
    proveedor_id: elements.filterProvider.value,
    entidad_financiera_id: elements.filterEntity.value,
    metodo_pago_id: elements.filterMethod.value,
    buscar: elements.filterSearch.value.trim()
  };

  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });

  return params;
}

function renderTrace(records) {
  if (!records.length) {
    elements.traceRows.innerHTML = `<tr><td class="fin-empty-cell" colspan="8">No hay movimientos que coincidan con los filtros.</td></tr>`;
    return;
  }

  elements.traceRows.innerHTML = records.map((record) => {
    const type = movementType(record);
    const client = partyLabel(record, ["cliente", "client", "movimiento.cliente", "origen", "origin"], "—");
    const entity = partyLabel(record, ["entidad_destino", "entidad", "entity", "cuenta_destino.entidad", "destination.entity"], "—");
    const provider = partyLabel(record, ["proveedor", "provider", "movimiento.proveedor"], "—");
    const method = partyLabel(record, ["metodo_pago", "metodo", "payment_method"], "—");
    const reference = firstDefined(record, ["referencia", "reference", "numero_operacion", "ticket.numero"], "—");
    const date = firstDefined(record, ["fecha_hora", "fecha", "created_at", "date", "movimiento.fecha_hora"], null);
    const status = String(firstDefined(record, ["estado", "status"], "REGISTRADO"));
    const unapplied = movementUnapplied(record);
    const applicationStatus = movementApplicationStatus(record);
    const applicationNote = PROVIDER_CREDIT_TYPES.has(type)
      ? ["ANULADO", "REVERSA"].includes(applicationStatus)
        ? applicationStatus === "ANULADO" ? "Pago anulado" : "Movimiento de reversa"
        : applicationStatus === "APLICADO"
        ? "Aplicado por completo"
        : unapplied > 0
          ? `${applicationStatus === "PARCIAL" ? "Aplicación parcial" : "Saldo a favor sin aplicar"} · ${formatMoney(unapplied, movementCurrency(record))}`
          : applicationStatus.replaceAll("_", " ")
      : "";

    return `
      <tr>
        <td><strong>${escapeHtml(formatDateTime(date))}</strong><small>${escapeHtml(status)}</small></td>
        <td><span class="fin-operation-tag ${isOutgoing(record, type) ? "is-out" : ""}">${escapeHtml(MOVEMENT_LABELS[type] || type.replaceAll("_", " "))}</span></td>
        <td>${escapeHtml(client)}</td>
        <td>${escapeHtml(entity)}</td>
        <td>${escapeHtml(provider)}</td>
        <td>${escapeHtml(method)}</td>
        <td>${escapeHtml(reference)}</td>
        <td class="fin-text-right fin-table-amount">
          <strong>${escapeHtml(formatMoney(movementAmount(record), movementCurrency(record)))}</strong>
          ${applicationNote ? `<small class="fin-application-note ${unapplied > 0 ? "is-pending" : ""}">${escapeHtml(applicationNote)}</small>` : ""}
        </td>
      </tr>`;
  }).join("");
}

function renderAdvances(records) {
  if (!records.length) {
    elements.advanceList.innerHTML = `
      <article class="fin-advance-empty">
        <strong>No hay saldo a favor disponible</strong>
        <span>Los depósitos, transferencias y saldos anteriores ya están aplicados a sus deudas.</span>
      </article>`;
    return;
  }

  elements.advanceList.innerHTML = records.map((record) => {
    const id = firstDefined(record, ["id", "movimiento.id"], "");
    const provider = partyLabel(record, ["proveedor", "provider"], "Proveedor sin nombre");
    const code = firstDefined(record, ["codigo", "code"], `Movimiento ${id}`);
    const reference = firstDefined(record, ["referencia", "reference"], "Sin referencia");
    const date = firstDefined(record, ["fecha_hora", "fecha", "created_at"], null);
    const currency = movementCurrency(record);
    const available = movementUnapplied(record);
    const applicationStatus = movementApplicationStatus(record);
    const type = movementType(record);
    const statusLabel = type === "SALDO_FAVOR_PROVEEDOR"
      ? applicationStatus === "PARCIAL" ? "Saldo anterior parcial" : "Saldo anterior"
      : applicationStatus === "PARCIAL" ? "Depósito aplicado parcialmente" : "Depósito sin aplicar";
    const actionLabel = `Aplicar ${formatMoney(available, currency)} del saldo a favor de ${provider}, fuente ${code}, a una deuda`;

    return `
      <article class="fin-advance-item">
        <div class="fin-advance-mark" aria-hidden="true">${escapeHtml(currencyInputPrefix(currency))}</div>
        <div class="fin-advance-copy">
          <div><strong>${escapeHtml(provider)}</strong><span class="fin-advance-status">${escapeHtml(statusLabel)}</span></div>
          <small>${escapeHtml(code)} · ${escapeHtml(reference)} · ${escapeHtml(formatDateTime(date))}</small>
        </div>
        <div class="fin-advance-amount">
          <span>Disponible</span>
          <strong>${escapeHtml(formatMoney(available, currency))}</strong>
        </div>
        <button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-advance-apply="${escapeHtml(id)}" aria-label="${escapeHtml(actionLabel)}">Aplicar a deuda</button>
      </article>`;
  }).join("");
}

async function loadAdvances() {
  setMessage(elements.advanceMessage, "Consultando saldo a favor con proveedores...");

  try {
    const params = new URLSearchParams({ aplicacion_estado: "CON_SALDO", per_page: "100" });
    if (requestedProviderId) params.set("proveedor_id", requestedProviderId);
    state.advances = await allPaginatedRecords(
      `/finanzas/movimientos?${params.toString()}`,
      ["movimientos", "items", "records"]
    );
    renderAdvances(state.advances);
    setMessage(elements.advanceMessage, state.advances.length
      ? `${state.advances.length} fuente${state.advances.length === 1 ? "" : "s"} con saldo disponible${requestedProviderId ? " para el proveedor seleccionado" : ""}`
      : requestedProviderId ? "El proveedor seleccionado no tiene saldo a favor disponible." : "");
    markFinanceAccessReady();
  } catch (error) {
    state.advances = [];
    elements.advanceList.innerHTML = `
      <article class="fin-advance-empty">
        <strong>No se pudo cargar el saldo a favor</strong>
        <span>Reintenta cuando la conexión esté disponible.</span>
      </article>`;
    setMessage(elements.advanceMessage, errorMessage(error, "No se pudo consultar el saldo a favor."), "error");
  }
}

function normalizeAdvanceDebt(rawDebt) {
  return {
    id: firstDefined(rawDebt, ["comprobante_id", "document_id", "id"], null),
    code: String(firstDefined(rawDebt, ["codigo", "numero_comprobante", "origen_clave"], "Documento")),
    pending: numericValue(firstDefined(rawDebt, ["saldo_pendiente", "saldo", "pendiente"], 0)),
    total: numericValue(firstDefined(rawDebt, ["total", "importe_total"], 0)),
    date: firstDefined(rawDebt, ["fecha_emision", "fecha", "created_at"], null),
    status: String(firstDefined(rawDebt, ["estado", "status"], "PENDIENTE"))
  };
}

function advanceSelectedTotal() {
  return [...state.advanceApplications.values()]
    .reduce((total, amount) => total + numericValue(amount), 0);
}

function currentAdvanceAvailable() {
  return movementUnapplied(state.advancePayment || {});
}

function advanceApplicationError() {
  for (const [documentId, rawAmount] of state.advanceApplications.entries()) {
    const debt = state.advanceDebts.find((item) => String(item.id) === String(documentId));
    const amount = numericValue(rawAmount);
    if (!debt || amount < .01) {
      return "Cada deuda seleccionada debe tener un importe mayor que cero.";
    }
    if (Math.abs(amount * 100 - Math.round(amount * 100)) > .000001) {
      return `El importe para ${debt.code} debe tener como máximo dos decimales.`;
    }
    if (amount > debt.pending + .001) {
      return `El importe para ${debt.code} supera su saldo pendiente.`;
    }
  }

  if (advanceSelectedTotal() > currentAdvanceAvailable() + .001) {
    return "Lo seleccionado supera el saldo a favor disponible.";
  }

  return "";
}

function updateAdvanceSummary() {
  const payment = state.advancePayment || {};
  const currency = movementCurrency(payment);
  const available = currentAdvanceAvailable();
  const selected = advanceSelectedTotal();
  const remaining = Math.max(0, available - selected);
  const validationError = advanceApplicationError();

  elements.advanceTotal.textContent = formatMoney(movementAmount(payment), currency);
  elements.advanceAvailable.textContent = formatMoney(available, currency);
  elements.advanceSelected.textContent = formatMoney(selected, currency);
  elements.advanceRemaining.textContent = formatMoney(remaining, currency);
  elements.advanceSelected.classList.toggle("is-error", validationError !== "");
  elements.advanceSubmit.disabled = state.savingAdvance || selected <= 0 || validationError !== "";
  elements.advanceClose.disabled = state.savingAdvance;
  elements.advanceCancel.disabled = state.savingAdvance;
  elements.advanceSubmit.textContent = selected > 0
    ? `Aplicar ${formatMoney(selected, currency)}`
    : "Aplicar a las deudas seleccionadas";
}

function renderAdvanceDebts() {
  const selected = state.advanceApplications;
  const currency = movementCurrency(state.advancePayment || {});
  const currencyPrefix = currencyInputPrefix(currency);
  const totalPending = state.advanceDebts.reduce((total, debt) => total + debt.pending, 0);
  elements.advanceDebtTotal.textContent = `Pendiente ${formatMoney(totalPending, currency)}`;

  if (!state.advanceDebts.length) {
    elements.advanceDebtList.innerHTML = "";
    setMessage(elements.advanceDebtMessage, "Este proveedor no tiene deudas pendientes en la moneda del pago.");
    updateAdvanceSummary();
    return;
  }

  setMessage(elements.advanceDebtMessage, `${state.advanceDebts.length} deuda${state.advanceDebts.length === 1 ? "" : "s"} disponible${state.advanceDebts.length === 1 ? "" : "s"}`);
  elements.advanceDebtList.innerHTML = state.advanceDebts.map((debt) => {
    const key = String(debt.id);
    const checked = selected.has(key);
    const amount = checked ? numericValue(selected.get(key)) : 0;
    return `
      <label class="fin-debt-item ${checked ? "is-selected" : ""}">
        <input class="fin-debt-check" type="checkbox" data-advance-debt-toggle="${escapeHtml(key)}" ${checked ? "checked" : ""}>
        <span class="fin-debt-copy">
          <strong>${escapeHtml(debt.code)}</strong>
          <small>${escapeHtml(formatAdvanceDate(debt.date))} · ${escapeHtml(debt.status)} · saldo ${escapeHtml(formatMoney(debt.pending, currency))}</small>
        </span>
        <span class="fin-debt-amount">${escapeHtml(currencyPrefix)}<input type="number" min="0.01" max="${debt.pending}" step="0.01" inputmode="decimal" data-advance-debt-amount="${escapeHtml(key)}" value="${amount > 0 ? amount.toFixed(2) : ""}" ${checked ? "" : "disabled"} aria-label="Importe que se aplicará a ${escapeHtml(debt.code)}"></span>
      </label>`;
  }).join("");
  updateAdvanceSummary();
}

function resetAdvanceDialog() {
  state.advanceRequestSequence += 1;
  state.advancePayment = null;
  state.advanceDebts = [];
  state.advanceApplications.clear();
  state.advanceIdempotencyKey = createIdempotencyKey();
  state.savingAdvance = false;
  elements.advanceForm.reset();
  setMessage(elements.advanceDebtMessage);
  setMessage(elements.advanceFormMessage);
  elements.advanceDebtList.innerHTML = "";
  updateAdvanceSummary();
}

function closeAdvanceDialog(force = false) {
  if (state.savingAdvance && !force) {
    setMessage(elements.advanceFormMessage, "Espera a que termine la aplicación antes de cerrar.", "error");
    return false;
  }

  if (elements.advanceDialog?.open) elements.advanceDialog.close();
  resetAdvanceDialog();
  return true;
}

async function openAdvanceDialog(paymentId) {
  if (state.savingAdvance) return;
  resetAdvanceDialog();
  const requestSequence = state.advanceRequestSequence;
  if (typeof elements.advanceDialog?.showModal === "function") elements.advanceDialog.showModal();
  else elements.advanceDialog?.setAttribute("open", "");
  setMessage(elements.advanceFormMessage, "Consultando la fuente y las deudas disponibles...");

  try {
    const movementResponse = await apiRequest(`/finanzas/movimientos/${encodeURIComponent(paymentId)}`);
    if (requestSequence !== state.advanceRequestSequence) return;
    const payment = firstDefined(movementResponse, ["data"], movementResponse);
    const providerId = firstDefined(payment, ["proveedor.id", "provider.id", "proveedor_id"], null);
    const available = movementUnapplied(payment);
    if (!providerId || !PROVIDER_CREDIT_TYPES.has(movementType(payment)) || available <= 0) {
      throw new Error("Esta fuente ya no tiene saldo disponible para aplicar.");
    }

    state.advancePayment = payment;
    state.advanceIdempotencyKey = createIdempotencyKey();
    elements.advanceCode.textContent = String(firstDefined(payment, ["codigo", "code"], `Movimiento ${paymentId}`));
    elements.advanceProvider.textContent = partyLabel(payment, ["proveedor", "provider"], "—");
    elements.advanceReference.textContent = String(firstDefined(payment, ["referencia", "reference"], "Sin referencia"));
    elements.advanceDate.textContent = formatDateTime(firstDefined(payment, ["fecha_hora", "fecha", "created_at"], null));
    updateAdvanceSummary();

    const params = new URLSearchParams({
      lado: "CXP",
      proveedor_id: String(providerId),
      moneda: movementCurrency(payment),
      naturaleza: "CARGO",
      solo_pendientes: "true",
      per_page: "100"
    });
    const portfolioRecords = await allPaginatedRecords(`/finanzas/cartera?${params.toString()}`, [
      "cartera", "comprobantes", "documentos", "items", "CXP", "cxp"
    ]);
    if (requestSequence !== state.advanceRequestSequence) return;

    state.advanceDebts = portfolioRecords
      .map(normalizeAdvanceDebt)
      .filter((debt) => debt.id && debt.pending > 0);
    renderAdvanceDebts();
    setMessage(elements.advanceFormMessage);
  } catch (error) {
    if (requestSequence !== state.advanceRequestSequence) return;
    setMessage(elements.advanceFormMessage, errorMessage(error, "No se pudo preparar la aplicación del saldo a favor."), "error");
    state.advanceDebts = [];
    state.advanceApplications.clear();
    elements.advanceDebtList.innerHTML = "";
    elements.advanceDebtTotal.textContent = `Pendiente ${formatMoney(0, movementCurrency(state.advancePayment || {}))}`;
    setMessage(elements.advanceDebtMessage);
    updateAdvanceSummary();
  }
}

function toggleAdvanceDebt(id, checked) {
  const key = String(id);
  if (!checked) {
    state.advanceApplications.delete(key);
  } else {
    const debt = state.advanceDebts.find((item) => String(item.id) === key);
    const remaining = Math.max(0, currentAdvanceAvailable() - advanceSelectedTotal());
    if (!debt || remaining <= 0) {
      setMessage(elements.advanceFormMessage, "El saldo a favor ya está completamente seleccionado.", "error");
      renderAdvanceDebts();
      return;
    }
    state.advanceApplications.set(key, Math.min(debt.pending, remaining));
    setMessage(elements.advanceFormMessage);
  }
  renderAdvanceDebts();
}

async function applyAdvance(event) {
  event.preventDefault();
  if (state.savingAdvance || !state.advancePayment) return;

  const applications = [...state.advanceApplications.entries()]
    .map(([documentId, amount]) => ({
      comprobante_id: Number(documentId),
      importe_aplicado: numericValue(amount).toFixed(2)
    }))
    .filter((application) => numericValue(application.importe_aplicado) > 0);
  if (!applications.length) {
    setMessage(elements.advanceFormMessage, "Selecciona al menos una deuda e indica el importe que deseas aplicar.", "error");
    return;
  }
  const validationError = advanceApplicationError();
  if (validationError) {
    setMessage(elements.advanceFormMessage, validationError, "error");
    return;
  }

  state.savingAdvance = true;
  updateAdvanceSummary();
  setMessage(elements.advanceFormMessage, "Aplicando el saldo a favor...");

  try {
    const paymentId = firstDefined(state.advancePayment, ["id"], null);
    const response = await apiRequest(`/finanzas/movimientos/${encodeURIComponent(paymentId)}/aplicaciones`, {
      method: "POST",
      body: JSON.stringify({
        idempotency_key: state.advanceIdempotencyKey,
        aplicaciones: applications,
        observaciones: "Aplicación posterior desde Tesorería"
      })
    });
    const message = response?.message || "El saldo a favor fue aplicado correctamente.";
    closeAdvanceDialog(true);
    await Promise.allSettled([loadAdvances(), loadBalances(), loadTrace(), loadRecentMovements()]);
    setMessage(elements.advanceMessage, message, "success");
  } catch (error) {
    setMessage(elements.advanceFormMessage, errorMessage(error, "No se pudo aplicar el saldo a favor."), "error");
  } finally {
    state.savingAdvance = false;
    updateAdvanceSummary();
  }
}

async function loadTrace() {
  if (state.loadingTrace) return;
  state.loadingTrace = true;
  elements.tracePrevious.disabled = true;
  elements.traceNext.disabled = true;
  setMessage(elements.traceMessage, "Consultando trazabilidad...");

  try {
    const response = await apiRequest(`/finanzas/trazabilidad?${filterQuery().toString()}`);
    const records = responseCollection(response, ["trazabilidad", "movimientos", "items", "records"]);
    const meta = responseMeta(response);
    state.page = numericValue(firstDefined(meta, ["current_page", "page"], state.page), state.page);
    state.lastPage = numericValue(firstDefined(meta, ["last_page", "pages"], records.length < state.perPage ? state.page : state.page + 1), 1);
    renderTrace(records);
    elements.tracePage.textContent = state.lastPage > 1 ? `Página ${state.page} de ${state.lastPage}` : "Página 1";
    setMessage(elements.traceMessage, records.length ? `${records.length} movimiento${records.length === 1 ? "" : "s"} en esta página` : "");
    markFinanceAccessReady();
  } catch (error) {
    renderTrace([]);
    setMessage(elements.traceMessage, errorMessage(error, "No se pudo cargar la trazabilidad."), "error");
  } finally {
    state.loadingTrace = false;
    elements.tracePrevious.disabled = state.page <= 1;
    elements.traceNext.disabled = state.page >= state.lastPage;
  }
}

function renderRecent(records) {
  if (!records.length) {
    elements.recentMovements.innerHTML = `<article class="fin-recent-item"><span class="fin-recent-mark">·</span><div class="fin-recent-copy"><strong>Aún no hay movimientos</strong><small>Los cobros y pagos registrados aparecerán aquí.</small></div><strong>S/ 0.00</strong></article>`;
    return;
  }

  elements.recentMovements.innerHTML = records.slice(0, 6).map((record) => {
    const type = movementType(record);
    const noCashFlow = type === "SALDO_FAVOR_PROVEEDOR";
    const outgoing = isOutgoing(record, type);
    const reference = firstDefined(record, ["referencia", "reference", "numero", "id"], "Sin referencia");
    const date = firstDefined(record, ["fecha_hora", "fecha", "created_at", "date"], null);

    return `
      <article class="fin-recent-item">
        <span class="fin-recent-mark ${noCashFlow ? "is-neutral" : outgoing ? "is-out" : ""}">${noCashFlow ? "·" : outgoing ? "−" : "+"}</span>
        <div class="fin-recent-copy">
          <strong>${escapeHtml(MOVEMENT_LABELS[type] || type.replaceAll("_", " "))}</strong>
          <small>${escapeHtml(reference)} · ${escapeHtml(formatDateTime(date))}</small>
        </div>
        <strong>${escapeHtml(formatMoney(movementAmount(record), movementCurrency(record)))}</strong>
      </article>`;
  }).join("");
}

async function loadRecentMovements() {
  setMessage(elements.movementMessage, "Cargando actividad reciente...");

  try {
    const response = await apiRequest("/finanzas/movimientos?per_page=6&orden=desc");
    const records = responseCollection(response, ["movimientos", "items", "records"]);
    renderRecent(records);
    setMessage(elements.movementMessage, "");
    markFinanceAccessReady();
  } catch (error) {
    renderRecent([]);
    setMessage(elements.movementMessage, errorMessage(error, "No se pudo cargar la actividad reciente."), "error");
  }
}

async function loadAll() {
  await Promise.allSettled([loadCatalog(), loadBalances(), loadAdvances(), loadTrace(), loadRecentMovements()]);
  if (requestedProviderId) {
    document.querySelector(".fin-advances-panel")?.scrollIntoView({ behavior: "smooth", block: "start" });
  }
}

elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  void loadTrace();
});

elements.clearFilters.addEventListener("click", () => {
  elements.filters.reset();
  state.page = 1;
  void loadTrace();
});

elements.tracePrevious.addEventListener("click", () => {
  if (state.page <= 1) return;
  state.page -= 1;
  void loadTrace();
});

elements.traceNext.addEventListener("click", () => {
  if (state.page >= state.lastPage) return;
  state.page += 1;
  void loadTrace();
});

elements.advanceList.addEventListener("click", (event) => {
  const button = event.target.closest("[data-advance-apply]");
  if (button) void openAdvanceDialog(button.dataset.advanceApply);
});

elements.advanceDebtList.addEventListener("change", (event) => {
  const checkbox = event.target.closest("[data-advance-debt-toggle]");
  if (checkbox) toggleAdvanceDebt(checkbox.dataset.advanceDebtToggle, checkbox.checked);
});

elements.advanceDebtList.addEventListener("input", (event) => {
  const input = event.target.closest("[data-advance-debt-amount]");
  if (!input) return;
  const key = String(input.dataset.advanceDebtAmount);
  if (state.advanceApplications.has(key)) {
    state.advanceApplications.set(key, Math.max(0, numericValue(input.value)));
    const validationError = advanceApplicationError();
    setMessage(elements.advanceFormMessage, validationError, validationError ? "error" : "");
    updateAdvanceSummary();
  }
});

elements.advanceForm.addEventListener("submit", applyAdvance);
elements.advanceClose.addEventListener("click", () => closeAdvanceDialog());
elements.advanceCancel.addEventListener("click", () => closeAdvanceDialog());
elements.advanceDialog.addEventListener("cancel", (event) => {
  event.preventDefault();
  closeAdvanceDialog();
});

initFinanceAccess(loadAll);
void loadAll();
