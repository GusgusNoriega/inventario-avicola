import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  setMessage
} from "./finanzas-common.js";

const byId = (id) => document.getElementById(id);
const elements = {
  account: byId("cashRegisterAccount"),
  saveDefault: byId("cashRegisterSaveDefault"),
  date: byId("cashRegisterDate"),
  liveStatus: byId("cashRegisterLiveStatus"),
  configMessage: byId("cashRegisterConfigMessage"),
  income: byId("cashRegisterIncome"),
  accountIncome: byId("cashRegisterAccountIncome"),
  expense: byId("cashRegisterExpense"),
  net: byId("cashRegisterNet"),
  refresh: byId("cashRegisterRefresh"),
  listMessage: byId("cashRegisterListMessage"),
  list: byId("cashRegisterList"),
  add: byId("cashRegisterAdd"),
  dialog: byId("cashRegisterDialog"),
  form: byId("cashRegisterForm"),
  dialogEyebrow: byId("cashRegisterDialogEyebrow"),
  dialogTitle: byId("cashRegisterDialogTitle"),
  movementId: byId("cashRegisterMovementId"),
  direction: byId("cashRegisterDirection"),
  movementDate: byId("cashRegisterMovementDate"),
  amount: byId("cashRegisterAmount"),
  currencyPrefix: byId("cashRegisterCurrencyPrefix"),
  counterpartLabel: byId("cashRegisterCounterpartLabel"),
  counterpartType: byId("cashRegisterCounterpartType"),
  clientField: byId("cashRegisterClientField"),
  clientSearch: byId("cashRegisterClientSearch"),
  clientId: byId("cashRegisterClientId"),
  clientSuggestions: byId("cashRegisterClientSuggestions"),
  selectedClient: byId("cashRegisterSelectedClient"),
  otherCashField: byId("cashRegisterOtherCashField"),
  otherCashLabel: byId("cashRegisterOtherCashLabel"),
  otherCash: byId("cashRegisterOtherCash"),
  detail: byId("cashRegisterDetail"),
  formMessage: byId("cashRegisterFormMessage"),
  submit: byId("cashRegisterSubmit")
};

const canManage = document.body.dataset.canManageCash === "1";
const canReverse = document.body.dataset.canReverseCash === "1";
const POLL_INTERVAL = 3000;
const STORAGE_PREFIX = "sistema-pollos:finanzas:caja-efectivo:v1";
const EXPENSE_DESTINATION_LABELS = {
  ADMINISTRATIVO: "Administrativo",
  TRANSPORTE: "Transporte",
  DEPOSITO: "Depósito"
};
const channel = "BroadcastChannel" in window
  ? new BroadcastChannel("sistema-pollos-caja-efectivo")
  : null;

const state = {
  companyId: null,
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "America/Lima",
  cashRegisters: [],
  clients: [],
  records: new Map(),
  loadPromise: null,
  pollingTimer: null,
  pendingKey: null,
  selectedClientId: null,
  activeSuggestion: -1,
  suggestionClients: [],
  saving: false,
  deletingId: null
};

function storageKey() {
  return `${STORAGE_PREFIX}:${state.companyId || "empresa"}`;
}

function normalizedSearch(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es")
    .trim();
}

function dateParts(date = new Date(), includeTime = false) {
  const options = {
    timeZone: state.timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit"
  };
  if (includeTime) {
    options.hour = "2-digit";
    options.minute = "2-digit";
    options.hourCycle = "h23";
  }
  const parts = new Intl.DateTimeFormat("en-CA", options)
    .formatToParts(date)
    .reduce((result, part) => ({ ...result, [part.type]: part.value }), {});
  const day = `${parts.year}-${parts.month}-${parts.day}`;
  return includeTime ? `${day}T${parts.hour}:${parts.minute}` : day;
}

function dateTimeInput(value = new Date()) {
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? "" : dateParts(date, true);
}

function dateTimeInputForFilteredDay(day, value = new Date()) {
  const currentDateTime = dateTimeInput(value);
  if (!currentDateTime || !/^\d{4}-\d{2}-\d{2}$/.test(day)) return currentDateTime;
  return `${day}${currentDateTime.slice(10)}`;
}

function formatMovementTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value || "");
  return new Intl.DateTimeFormat("es-PE", {
    timeZone: state.timezone,
    hour: "2-digit",
    minute: "2-digit",
    day: "2-digit",
    month: "short"
  }).format(date);
}

function currencyPrefix(currency) {
  return currency === "USD" ? "$" : "S/";
}

function selectedCashRegister() {
  return state.cashRegisters.find((account) => String(account.id) === elements.account.value) || null;
}

function cashRegisterLabel(account) {
  const entity = account.entidad?.nombre_comercial || account.entidad?.razon_social;
  return entity ? `${account.alias} · ${entity} · ${account.moneda}` : `${account.alias} · ${account.moneda}`;
}

function readDefaultCashRegister() {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey()) || "null");
    const id = Number(stored?.cashRegisterId || 0);
    return state.cashRegisters.some((account) => Number(account.id) === id) ? String(id) : "";
  } catch {
    try {
      localStorage.removeItem(storageKey());
    } catch {
      // El navegador puede bloquear el almacenamiento; la vista sigue funcionando.
    }
    return "";
  }
}

function saveDefaultCashRegister() {
  const account = selectedCashRegister();
  if (!account) {
    setMessage(elements.configMessage, "Selecciona una caja antes de guardarla.", "error");
    return;
  }
  try {
    localStorage.setItem(storageKey(), JSON.stringify({ cashRegisterId: Number(account.id) }));
    setMessage(elements.configMessage, `${account.alias} quedó como caja predeterminada en este equipo.`, "success");
  } catch {
    setMessage(elements.configMessage, "El navegador no permitió guardar esta preferencia.", "error");
  }
}

function fillCashRegisters() {
  const previous = elements.account.value;
  elements.account.innerHTML = state.cashRegisters.length
    ? `<option value="">Selecciona una caja</option>${state.cashRegisters.map((account) =>
      `<option value="${account.id}">${escapeHtml(cashRegisterLabel(account))}</option>`
    ).join("")}`
    : '<option value="">No hay cajas activas configuradas</option>';

  const saved = readDefaultCashRegister();
  const validPrevious = state.cashRegisters.some((account) => String(account.id) === String(previous));
  elements.account.value = saved || (validPrevious ? previous : String(state.cashRegisters[0]?.id || ""));
  elements.saveDefault.disabled = !state.cashRegisters.length;
  elements.add.disabled = !state.cashRegisters.length || !canManage;
  elements.refresh.disabled = !state.cashRegisters.length;
  updateCurrency();
}

function updateCurrency() {
  const currency = selectedCashRegister()?.moneda || "PEN";
  elements.currencyPrefix.textContent = currencyPrefix(currency);
}

function accountIncomeText(income, fallbackCurrency) {
  if (!Array.isArray(income) || !income.length) return formatMoney(0, fallbackCurrency);
  return income.map((item) => {
    const currency = item?.moneda === "USD" ? "USD" : "PEN";
    return formatMoney(item?.importe || 0, currency);
  }).join(" · ");
}

function resetSummary() {
  const currency = selectedCashRegister()?.moneda || "PEN";
  elements.income.textContent = formatMoney(0, currency);
  elements.accountIncome.textContent = accountIncomeText([], currency);
  elements.expense.textContent = formatMoney(0, currency);
  elements.net.textContent = formatMoney(0, currency);
}

function counterpartDescription(record) {
  const counterpart = record.contraparte || {};
  if (counterpart.tipo === "CLIENTE") {
    return counterpart.documento
      ? `${counterpart.nombre} · ${counterpart.documento}`
      : counterpart.nombre;
  }
  if (counterpart.tipo === "OTRA_CAJA") {
    return counterpart.entidad
      ? `${counterpart.nombre} · ${counterpart.entidad}`
      : counterpart.nombre;
  }
  if (record.direccion === "EGRESO" && EXPENSE_DESTINATION_LABELS[record.contraparte_tipo]) {
    return EXPENSE_DESTINATION_LABELS[record.contraparte_tipo];
  }
  return counterpart.nombre || (record.direccion === "INGRESO" ? "Otro origen" : "Otro destino");
}

function renderLedger(records) {
  state.records = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.list.innerHTML = '<li class="fin-cash-empty">No hay ingresos ni gastos registrados para esta caja en el día seleccionado.</li>';
    return;
  }

  elements.list.innerHTML = records.map((record) => {
    const income = record.direccion === "INGRESO";
    const sign = income ? "+" : "−";
    const edit = canManage && record.puede_editar
      ? `<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-cash="${record.id}">Editar</button>`
      : "";
    const deleting = state.deletingId === String(record.id);
    const code = record.codigo || record.movimiento_codigo || `#${record.id}`;
    const remove = canReverse && record.puede_anular !== false
      ? `<button
          class="fin-btn fin-btn-danger fin-btn-small"
          type="button"
          data-delete-cash="${record.id}"
          aria-haspopup="dialog"
          aria-label="Eliminar movimiento ${escapeHtml(code)}"
          ${deleting ? 'disabled aria-busy="true"' : ""}
        >${deleting ? "Eliminando…" : "Eliminar"}</button>`
      : "";
    return `<li class="fin-cash-item ${income ? "is-income" : "is-expense"}">
      <span class="fin-cash-direction" aria-hidden="true">${sign}</span>
      <span class="fin-cash-item-copy">
        <h3 class="fin-cash-item-title">${escapeHtml(record.detalle)}</h3>
        <span class="fin-cash-item-counterpart">${escapeHtml(counterpartDescription(record))}</span>
        <small>${escapeHtml(formatMovementTime(record.fecha_hora))} · ${escapeHtml(code)}${record.creado_por ? ` · ${escapeHtml(record.creado_por)}` : ""}</small>
      </span>
      <strong class="fin-cash-amount">${sign}${escapeHtml(formatMoney(record.importe, record.moneda))}</strong>
      <span class="fin-cash-item-action">${edit}${remove}</span>
    </li>`;
  }).join("");
}

function applySummary(summary = {}) {
  const currency = summary.moneda || selectedCashRegister()?.moneda || "PEN";
  elements.income.textContent = formatMoney(summary.ingresos || 0, currency);
  elements.accountIncome.textContent = accountIncomeText(summary.ingresos_cuentas, currency);
  elements.expense.textContent = formatMoney(summary.egresos || 0, currency);
  elements.net.textContent = formatMoney(summary.total || 0, currency);
  elements.net.classList.toggle("is-negative", Number(summary.total || 0) < 0);
}

async function loadCatalog() {
  const response = await apiRequest("/finanzas/caja-efectivo/catalogo");
  const catalog = response?.data || {};
  state.companyId = Number(catalog.empresa_id || 0) || null;
  state.timezone = catalog.timezone || state.timezone;
  state.cashRegisters = Array.isArray(catalog.cajas) ? catalog.cajas : [];
  state.clients = Array.isArray(catalog.clientes) ? catalog.clientes : [];
  if (!elements.date.value) elements.date.value = dateParts();
  fillCashRegisters();
  markFinanceAccessReady();

  if (!state.cashRegisters.length) {
    setMessage(elements.configMessage, "Primero crea una cuenta propia de tipo Caja en Empresas y cuentas.", "error");
  } else if (!canManage) {
    setMessage(elements.configMessage, "Puedes consultar la caja, pero no tienes permiso para registrar ajustes de efectivo.");
  }
}

async function loadLedger({ silent = false } = {}) {
  if (state.loadPromise) return state.loadPromise;
  const accountId = Number(elements.account.value || 0);
  const date = elements.date.value;
  if (!accountId || !date) {
    resetSummary();
    renderLedger([]);
    setMessage(elements.listMessage, "Selecciona una caja y una fecha para consultar los movimientos.");
    return null;
  }

  const requestSignature = `${accountId}:${date}`;
  if (!silent) setMessage(elements.listMessage, "Actualizando movimientos de efectivo…");
  state.loadPromise = (async () => {
    try {
      const params = new URLSearchParams({ caja_id: String(accountId), fecha: date });
      const response = await apiRequest(`/finanzas/caja-efectivo?${params}`);
      if (`${elements.account.value}:${elements.date.value}` !== requestSignature) return;
      const records = Array.isArray(response?.data) ? response.data : [];
      renderLedger(records);
      applySummary(response?.resumen || {});
      const updatedAt = response?.meta?.actualizado_en;
      elements.liveStatus.textContent = updatedAt
        ? `Actualización automática activa · ${formatMovementTime(updatedAt)}`
        : "Actualización automática activa";
      setMessage(
        elements.listMessage,
        `${records.length} movimiento${records.length === 1 ? "" : "s"} en el día seleccionado.`,
      );
      markFinanceAccessReady();
    } catch (error) {
      if (`${elements.account.value}:${elements.date.value}` !== requestSignature) return;
      elements.liveStatus.textContent = "Intentando recuperar la actualización automática…";
      if (!silent) setMessage(elements.listMessage, errorMessage(error, "No se pudo cargar la caja."), "error");
    } finally {
      state.loadPromise = null;
    }
  })();

  return state.loadPromise;
}

async function reloadLedgerAfterMutation() {
  const pendingLoad = state.loadPromise;
  if (pendingLoad) await pendingLoad;
  return loadLedger();
}

function schedulePolling() {
  window.clearTimeout(state.pollingTimer);
  state.pollingTimer = window.setTimeout(async () => {
    if (!document.hidden && elements.account.value) await loadLedger({ silent: true });
    schedulePolling();
  }, POLL_INTERVAL);
}

async function loadApplication() {
  await loadCatalog();
  await loadLedger();
  schedulePolling();
}

function fillOtherCashRegisters(selected = "") {
  const current = selectedCashRegister();
  const options = state.cashRegisters.filter((account) =>
    String(account.id) !== String(current?.id)
      && account.moneda === current?.moneda
  );
  elements.otherCash.innerHTML = `<option value="">Selecciona la otra caja</option>${options.map((account) =>
    `<option value="${account.id}">${escapeHtml(cashRegisterLabel(account))}</option>`
  ).join("")}`;
  elements.otherCash.value = String(selected || "");
}

function counterpartOptions() {
  const income = elements.direction.value === "INGRESO";
  const current = elements.counterpartType.value;
  const options = income
    ? [
        ["CLIENTE", "De un cliente"],
        ["OTRA_CAJA", "De otra caja"],
        ["OTRO", "Otro origen"]
      ]
    : [
        ["ADMINISTRATIVO", "Administrativo"],
        ["TRANSPORTE", "Transporte"],
        ["DEPOSITO", "Depósito"],
        ["OTRA_CAJA", "Otra caja"]
      ];
  elements.counterpartType.innerHTML = options.map(([value, label]) =>
    `<option value="${value}">${label}</option>`
  ).join("");
  elements.counterpartType.value = options.some(([value]) => value === current)
    ? current
    : options[0][0];
}

function updateConditionalFields() {
  const income = elements.direction.value === "INGRESO";
  counterpartOptions();
  const type = elements.counterpartType.value;
  elements.counterpartLabel.innerHTML = `${income ? "¿De dónde viene el dinero?" : "Destino del gasto"} <b>*</b>`;
  elements.clientField.hidden = type !== "CLIENTE";
  elements.otherCashField.hidden = type !== "OTRA_CAJA";
  elements.otherCashLabel.innerHTML = `${income ? "Caja de origen" : "Caja de destino"} <b>*</b>`;
  if (type !== "CLIENTE") clearClient();
  if (type === "OTRA_CAJA") fillOtherCashRegisters(elements.otherCash.value);
}

function clientLabel(client) {
  return client.numero_documento
    ? `${client.nombre} · ${client.numero_documento}`
    : client.nombre;
}

function clearClient() {
  state.selectedClientId = null;
  elements.clientId.value = "";
  if (elements.counterpartType.value !== "CLIENTE") elements.clientSearch.value = "";
  elements.selectedClient.textContent = "Selecciona el cliente que entregó el efectivo.";
  closeClientSuggestions();
}

function selectClient(client) {
  state.selectedClientId = Number(client.id);
  elements.clientId.value = String(client.id);
  elements.clientSearch.value = clientLabel(client);
  elements.selectedClient.textContent = `Cliente seleccionado: ${clientLabel(client)}`;
  closeClientSuggestions();
}

function matchingClients() {
  const query = normalizedSearch(elements.clientSearch.value);
  return state.clients.filter((client) =>
    !query || normalizedSearch(`${client.nombre} ${client.numero_documento || ""}`).includes(query)
  ).slice(0, 8);
}

function renderClientSuggestions() {
  state.suggestionClients = matchingClients();
  state.activeSuggestion = Math.min(state.activeSuggestion, state.suggestionClients.length - 1);
  elements.clientSuggestions.innerHTML = state.suggestionClients.length
    ? state.suggestionClients.map((client, index) => `<button
        id="cashRegisterClientOption${index}"
        class="fin-cash-suggestion ${index === state.activeSuggestion ? "is-active" : ""}"
        type="button"
        role="option"
        aria-selected="${index === state.activeSuggestion}"
        data-client-index="${index}"
      ><strong>${escapeHtml(client.nombre)}</strong><small>${escapeHtml(client.numero_documento || "Sin documento")}</small></button>`).join("")
    : '<p class="fin-cash-suggestion-empty">No se encontraron clientes.</p>';
  elements.clientSuggestions.hidden = false;
  elements.clientSearch.setAttribute("aria-expanded", "true");
  if (state.activeSuggestion >= 0) {
    elements.clientSearch.setAttribute("aria-activedescendant", `cashRegisterClientOption${state.activeSuggestion}`);
  } else {
    elements.clientSearch.removeAttribute("aria-activedescendant");
  }
}

function closeClientSuggestions() {
  state.activeSuggestion = -1;
  state.suggestionClients = [];
  elements.clientSuggestions.hidden = true;
  elements.clientSearch.setAttribute("aria-expanded", "false");
  elements.clientSearch.removeAttribute("aria-activedescendant");
}

function openCreateDialog() {
  if (!canManage || !selectedCashRegister()) return;
  state.pendingKey = createIdempotencyKey();
  elements.form.reset();
  elements.movementId.value = "";
  elements.dialogEyebrow.textContent = "Nuevo movimiento";
  elements.dialogTitle.textContent = "Registrar efectivo";
  elements.submit.textContent = "Guardar movimiento";
  elements.direction.value = "INGRESO";
  elements.movementDate.value = dateTimeInputForFilteredDay(elements.date.value);
  elements.counterpartType.value = "CLIENTE";
  elements.detail.value = "";
  clearClient();
  updateConditionalFields();
  updateCurrency();
  setMessage(elements.formMessage, "");
  elements.dialog.showModal();
  window.setTimeout(() => elements.amount.focus(), 40);
}

function openEditDialog(id) {
  const record = state.records.get(String(id));
  if (!record || !canManage) return;
  state.pendingKey = null;
  elements.form.reset();
  elements.movementId.value = String(record.id);
  elements.dialogEyebrow.textContent = "Corrección auditada";
  elements.dialogTitle.textContent = "Editar movimiento de efectivo";
  elements.submit.textContent = "Guardar cambios";
  elements.direction.value = record.direccion;
  elements.movementDate.value = dateTimeInput(record.fecha_hora);
  elements.amount.value = Number(record.importe).toFixed(2);
  elements.counterpartType.value = record.contraparte_tipo;
  elements.detail.value = record.detalle || "";
  counterpartOptions();
  elements.counterpartType.value = record.contraparte_tipo;
  updateConditionalFields();
  if (record.contraparte_tipo === "CLIENTE") {
    const client = state.clients.find((item) => Number(item.id) === Number(record.contraparte?.id));
    if (client) selectClient(client);
  } else if (record.contraparte_tipo === "OTRA_CAJA") {
    fillOtherCashRegisters(record.contraparte?.id);
  }
  updateCurrency();
  setMessage(
    elements.formMessage,
    canReverse ? "" : "Puedes editar fecha y detalle; los cambios contables requieren permiso para anular.",
  );
  elements.dialog.showModal();
  window.setTimeout(() => elements.amount.focus(), 40);
}

function movementPayload() {
  const amount = Number(elements.amount.value || 0);
  const type = elements.counterpartType.value;
  return {
    caja_id: Number(elements.account.value),
    direccion: elements.direction.value,
    contraparte_tipo: type,
    cliente_id: type === "CLIENTE" ? Number(elements.clientId.value || 0) : null,
    otra_caja_id: type === "OTRA_CAJA" ? Number(elements.otherCash.value || 0) : null,
    fecha_hora: elements.movementDate.value,
    importe: Number.isFinite(amount) ? amount.toFixed(2) : "",
    detalle: elements.detail.value.trim()
  };
}

async function saveMovement(event) {
  event.preventDefault();
  if (state.saving) return;
  const payload = movementPayload();
  if (!payload.caja_id || !payload.fecha_hora || Number(payload.importe) <= 0 || !payload.detalle) {
    setMessage(elements.formMessage, "Completa la fecha, el importe y el detalle del movimiento.", "error");
    return;
  }
  if (payload.contraparte_tipo === "CLIENTE" && !payload.cliente_id) {
    setMessage(elements.formMessage, "Busca y selecciona el cliente que entregó el efectivo.", "error");
    elements.clientSearch.focus();
    return;
  }
  if (payload.contraparte_tipo === "OTRA_CAJA" && !payload.otra_caja_id) {
    setMessage(elements.formMessage, "Selecciona la otra caja involucrada.", "error");
    elements.otherCash.focus();
    return;
  }

  const id = elements.movementId.value;
  const endpoint = id
    ? `/finanzas/caja-efectivo/${encodeURIComponent(id)}`
    : "/finanzas/caja-efectivo";
  if (!id) payload.idempotency_key = state.pendingKey || createIdempotencyKey();
  state.pendingKey = payload.idempotency_key || null;
  state.saving = true;
  elements.submit.disabled = true;
  setMessage(elements.formMessage, id ? "Guardando corrección…" : "Registrando efectivo…");
  try {
    await apiRequest(endpoint, {
      method: id ? "PUT" : "POST",
      body: JSON.stringify(payload)
    });
    state.pendingKey = null;
    elements.dialog.close();
    channel?.postMessage({ companyId: state.companyId, type: "cash-register-updated" });
    await reloadLedgerAfterMutation();
    setMessage(elements.listMessage, id ? "Movimiento actualizado correctamente." : "Movimiento registrado correctamente.", "success");
  } catch (error) {
    setMessage(elements.formMessage, errorMessage(error, "No se pudo guardar el movimiento."), "error");
  } finally {
    state.saving = false;
    elements.submit.disabled = false;
  }
}

async function deleteMovement(id) {
  const record = state.records.get(String(id));
  if (!record || !canReverse || state.deletingId !== null) return;

  const kind = record.direccion === "INGRESO" ? "ingreso" : "gasto";
  const amount = formatMoney(record.importe, record.moneda);
  const confirmed = window.confirm(
    `¿Eliminar este ${kind} de ${amount}?\n\nEl sistema conservará la trazabilidad mediante una reversa.`,
  );
  if (!confirmed) return;

  state.deletingId = String(record.id);
  renderLedger([...state.records.values()]);
  setMessage(elements.listMessage, "Eliminando movimiento de efectivo…");

  try {
    await apiRequest(`/finanzas/caja-efectivo/${encodeURIComponent(record.id)}`, {
      method: "DELETE"
    });
    channel?.postMessage({ companyId: state.companyId, type: "cash-register-updated" });

    await reloadLedgerAfterMutation();

    state.deletingId = null;
    renderLedger([...state.records.values()]);
    setMessage(elements.listMessage, "Movimiento eliminado correctamente.", "success");
  } catch (error) {
    state.deletingId = null;
    renderLedger([...state.records.values()]);
    setMessage(elements.listMessage, errorMessage(error, "No se pudo eliminar el movimiento."), "error");
  }
}

elements.saveDefault.addEventListener("click", saveDefaultCashRegister);
elements.account.addEventListener("change", async () => {
  updateCurrency();
  setMessage(elements.configMessage, "");
  await loadLedger();
});
elements.date.addEventListener("change", () => void loadLedger());
elements.refresh.addEventListener("click", () => void loadLedger());
elements.add.addEventListener("click", openCreateDialog);
elements.direction.addEventListener("change", updateConditionalFields);
elements.counterpartType.addEventListener("change", updateConditionalFields);
elements.form.addEventListener("submit", saveMovement);
elements.list.addEventListener("click", (event) => {
  const editButton = event.target.closest("[data-edit-cash]");
  const deleteButton = event.target.closest("[data-delete-cash]");
  if (editButton) openEditDialog(editButton.dataset.editCash);
  if (deleteButton) void deleteMovement(deleteButton.dataset.deleteCash);
});
document.querySelectorAll("[data-cash-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => elements.dialog.close());
});

elements.clientSearch.addEventListener("input", () => {
  const selected = state.clients.find((client) => Number(client.id) === Number(state.selectedClientId));
  if (!selected || elements.clientSearch.value !== clientLabel(selected)) {
    state.selectedClientId = null;
    elements.clientId.value = "";
    elements.selectedClient.textContent = "Selecciona una opción de la lista.";
  }
  state.activeSuggestion = -1;
  renderClientSuggestions();
});
elements.clientSearch.addEventListener("focus", renderClientSuggestions);
elements.clientSearch.addEventListener("blur", () => window.setTimeout(closeClientSuggestions, 120));
elements.clientSearch.addEventListener("keydown", (event) => {
  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();
    if (elements.clientSuggestions.hidden) renderClientSuggestions();
    const delta = event.key === "ArrowDown" ? 1 : -1;
    const length = state.suggestionClients.length;
    if (length) state.activeSuggestion = (state.activeSuggestion + delta + length) % length;
    renderClientSuggestions();
  } else if (event.key === "Enter" && state.activeSuggestion >= 0) {
    event.preventDefault();
    selectClient(state.suggestionClients[state.activeSuggestion]);
  } else if (event.key === "Escape") {
    closeClientSuggestions();
  }
});
elements.clientSuggestions.addEventListener("mousedown", (event) => event.preventDefault());
elements.clientSuggestions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-client-index]");
  if (option) selectClient(state.suggestionClients[Number(option.dataset.clientIndex)]);
});

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) void loadLedger({ silent: true });
});
window.addEventListener("focus", () => void loadLedger({ silent: true }));
window.addEventListener("storage", (event) => {
  if (event.key !== storageKey()) return;
  const saved = readDefaultCashRegister();
  if (saved && saved !== elements.account.value) {
    elements.account.value = saved;
    updateCurrency();
    void loadLedger();
  }
});
channel?.addEventListener("message", (event) => {
  if (Number(event.data?.companyId) === Number(state.companyId)) void loadLedger({ silent: true });
});
window.addEventListener("beforeunload", () => {
  window.clearTimeout(state.pollingTimer);
  channel?.close();
});

initFinanceAccess(loadApplication);
void loadApplication();
