import { apiRequest } from "./api-client.js";
import {
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
  COBRO_MINORISTA: "Cobro minorista",
  REEMBOLSO_CLIENTE: "Empresa → cliente",
  SALDO_INICIAL: "Saldo inicial",
  TRANSFERENCIA_INTERNA: "Transferencia interna",
  AJUSTE: "Ajuste de saldo",
  AJUSTE_ENTRADA: "Ajuste de entrada",
  AJUSTE_SALIDA: "Ajuste de salida",
  REVERSO: "Reverso"
};

const elements = {
  available: document.getElementById("financeAvailableBalance"),
  receivable: document.getElementById("financeReceivableBalance"),
  payable: document.getElementById("financePayableBalance"),
  directPaid: document.getElementById("financeDirectPaid"),
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
  recentMovements: document.getElementById("financeRecentMovements")
};

const state = {
  page: 1,
  lastPage: 1,
  perPage: 25,
  loadingTrace: false
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

function isOutgoing(record, type = movementType(record)) {
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

    elements.available.textContent = formatMoney(firstDefined(penTotal || {}, ["saldo"], calculatedBalance));
    elements.receivable.textContent = formatMoney(monetaryMetric(firstDefined(portfolio, ["por_cobrar", "cxc"], 0)));
    elements.payable.textContent = formatMoney(monetaryMetric(firstDefined(portfolio, ["por_pagar", "cxp"], 0)));
    elements.directPaid.textContent = formatMoney(monetaryMetric(firstDefined(providerPayments, ["directos_clientes", "pagos_directos"], 0)));

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

    return `
      <tr>
        <td><strong>${escapeHtml(formatDateTime(date))}</strong><small>${escapeHtml(status)}</small></td>
        <td><span class="fin-operation-tag ${isOutgoing(record, type) ? "is-out" : ""}">${escapeHtml(MOVEMENT_LABELS[type] || type.replaceAll("_", " "))}</span></td>
        <td>${escapeHtml(client)}</td>
        <td>${escapeHtml(entity)}</td>
        <td>${escapeHtml(provider)}</td>
        <td>${escapeHtml(method)}</td>
        <td>${escapeHtml(reference)}</td>
        <td class="fin-text-right fin-table-amount">${escapeHtml(formatMoney(movementAmount(record), movementCurrency(record)))}</td>
      </tr>`;
  }).join("");
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
    const reference = firstDefined(record, ["referencia", "reference", "numero", "id"], "Sin referencia");
    const date = firstDefined(record, ["fecha_hora", "fecha", "created_at", "date"], null);

    return `
      <article class="fin-recent-item">
        <span class="fin-recent-mark ${isOutgoing(record, type) ? "is-out" : ""}">${isOutgoing(record, type) ? "−" : "+"}</span>
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
  await Promise.allSettled([loadCatalog(), loadBalances(), loadTrace(), loadRecentMovements()]);
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

initFinanceAccess(loadAll);
void loadAll();
