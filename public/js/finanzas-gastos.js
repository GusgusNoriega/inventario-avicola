import { apiRequest } from "./api-client.js";
import {
  errorMessage,
  escapeHtml,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  setMessage
} from "./finanzas-common.js";

const CATEGORY_LABELS = {
  MANTENIMIENTO: "Mantenimiento",
  INDUMENTARIA: "Indumentaria",
  SERVICIOS: "Servicios",
  TRANSPORTE: "Transporte",
  ALIMENTACION: "Alimentación",
  IMPUESTOS: "Impuestos",
  SUMINISTROS: "Suministros",
  OTRO: "Otro / personalizado"
};

const byId = (id) => document.getElementById(id);
const elements = {
  form: byId("companyExpenseForm"),
  date: byId("companyExpenseDate"),
  category: byId("companyExpenseCategory"),
  concept: byId("companyExpenseConcept"),
  destination: byId("companyExpenseDestination"),
  document: byId("companyExpenseDocument"),
  account: byId("companyExpenseAccount"),
  accountHelp: byId("companyExpenseAccountHelp"),
  method: byId("companyExpenseMethod"),
  amount: byId("companyExpenseAmount"),
  reference: byId("companyExpenseReference"),
  notes: byId("companyExpenseNotes"),
  currencyPrefix: byId("companyExpenseCurrencyPrefix"),
  total: byId("companyExpenseTotal"),
  save: byId("companyExpenseSave"),
  message: byId("companyExpenseMessage"),
  summaryTotal: byId("companyExpenseSummaryTotal"),
  summaryCount: byId("companyExpenseSummaryCount"),
  filters: byId("companyExpenseFilters"),
  search: byId("companyExpenseSearch"),
  filterCategory: byId("companyExpenseFilterCategory"),
  filterStatus: byId("companyExpenseFilterStatus"),
  from: byId("companyExpenseFrom"),
  to: byId("companyExpenseTo"),
  clearFilters: byId("companyExpenseClearFilters"),
  listMessage: byId("companyExpenseListMessage"),
  rows: byId("companyExpenseRows"),
  previous: byId("companyExpensePrevious"),
  next: byId("companyExpenseNext"),
  page: byId("companyExpensePage"),
  editDialog: byId("companyExpenseEditDialog"),
  editForm: byId("companyExpenseEditForm"),
  editId: byId("companyExpenseEditId"),
  editDate: byId("companyExpenseEditDate"),
  editCategory: byId("companyExpenseEditCategory"),
  editConcept: byId("companyExpenseEditConcept"),
  editDestination: byId("companyExpenseEditDestination"),
  editDocument: byId("companyExpenseEditDocument"),
  editAccount: byId("companyExpenseEditAccount"),
  editMethod: byId("companyExpenseEditMethod"),
  editAmount: byId("companyExpenseEditAmount"),
  editReference: byId("companyExpenseEditReference"),
  editNotes: byId("companyExpenseEditNotes"),
  editMessage: byId("companyExpenseEditMessage"),
  voidDialog: byId("companyExpenseVoidDialog"),
  voidForm: byId("companyExpenseVoidForm"),
  voidDescription: byId("companyExpenseVoidDescription"),
  voidReason: byId("companyExpenseVoidReason"),
  voidMessage: byId("companyExpenseVoidMessage")
};

const permissions = {
  create: document.body.dataset.canCreateExpenses === "1",
  void: document.body.dataset.canVoidExpenses === "1"
};
const state = {
  accounts: [],
  methods: [],
  categories: Object.keys(CATEGORY_LABELS),
  records: new Map(),
  page: 1,
  lastPage: 1,
  voidId: null,
  loading: false
};

function nowLocal() {
  const date = new Date();
  const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return shifted.toISOString().slice(0, 16);
}

function localDateTime(value) {
  return value ? String(value).replace(" ", "T").slice(0, 16) : "";
}

function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
    const random = Math.random() * 16 | 0;
    return (char === "x" ? random : (random & 3 | 8)).toString(16);
  });
}

function selectedAccount(select = elements.account) {
  return state.accounts.find((account) => String(account.id) === String(select.value)) || null;
}

function categoryOptions(placeholder) {
  return `<option value="">${escapeHtml(placeholder)}</option>${state.categories
    .map((category) => `<option value="${escapeHtml(category)}">${escapeHtml(CATEGORY_LABELS[category] || category)}</option>`)
    .join("")}`;
}

function accountOptions(placeholder) {
  return `<option value="">${escapeHtml(placeholder)}</option>${state.accounts.map((account) => {
    const label = `${account.alias} · ${account.entityName} · ${formatMoney(account.saldo, account.moneda)}`;
    return `<option value="${account.id}">${escapeHtml(label)}</option>`;
  }).join("")}`;
}

function methodOptions(placeholder) {
  return `<option value="">${escapeHtml(placeholder)}</option>${state.methods
    .map((method) => `<option value="${method.id}">${escapeHtml(method.nombre)}</option>`)
    .join("")}`;
}

function fillCatalogControls() {
  elements.category.innerHTML = categoryOptions("Selecciona una categoría");
  elements.editCategory.innerHTML = categoryOptions("Selecciona una categoría");
  elements.filterCategory.innerHTML = categoryOptions("Todas");
  elements.account.innerHTML = accountOptions("Selecciona una caja o cuenta");
  elements.editAccount.innerHTML = accountOptions("Selecciona una caja o cuenta");
  elements.method.innerHTML = methodOptions("Selecciona un método");
  elements.editMethod.innerHTML = methodOptions("Selecciona un método");
}

async function loadCatalog() {
  const response = await apiRequest("/finanzas/gastos/catalogo");
  const catalog = response.data || {};
  state.accounts = (catalog.entidades || []).flatMap((entity) =>
    (entity.cuentas || []).map((account) => ({
      ...account,
      entityName: entity.nombre_comercial || entity.razon_social || "Empresa"
    }))
  );
  state.methods = catalog.metodos_pago || [];
  state.categories = catalog.categorias || Object.keys(CATEGORY_LABELS);
  fillCatalogControls();
  markFinanceAccessReady();
}

function updateCreateSummary() {
  const account = selectedAccount();
  const currency = account?.moneda || "PEN";
  const prefix = currency === "USD" ? "$" : "S/";
  elements.currencyPrefix.textContent = prefix;
  elements.total.textContent = formatMoney(Number(elements.amount.value || 0), currency);
  elements.accountHelp.textContent = account
    ? `Saldo disponible: ${formatMoney(account.saldo, account.moneda)}`
    : "Selecciona de dónde sale el dinero.";
}

function payload(fields) {
  const account = selectedAccount(fields.account);
  return {
    fecha_hora: fields.date.value,
    categoria: fields.category.value,
    concepto: fields.concept.value.trim(),
    destino: fields.destination.value.trim(),
    numero_documento: fields.document.value.trim() || null,
    cuenta_origen_id: Number(fields.account.value),
    metodo_pago_id: Number(fields.method.value),
    moneda: account?.moneda || "PEN",
    importe: Number(fields.amount.value || 0).toFixed(2),
    referencia: fields.reference.value.trim() || null,
    observaciones: fields.notes.value.trim() || null
  };
}

function createFields() {
  return {
    date: elements.date,
    category: elements.category,
    concept: elements.concept,
    destination: elements.destination,
    document: elements.document,
    account: elements.account,
    method: elements.method,
    amount: elements.amount,
    reference: elements.reference,
    notes: elements.notes
  };
}

function editFields() {
  return {
    date: elements.editDate,
    category: elements.editCategory,
    concept: elements.editConcept,
    destination: elements.editDestination,
    document: elements.editDocument,
    account: elements.editAccount,
    method: elements.editMethod,
    amount: elements.editAmount,
    reference: elements.editReference,
    notes: elements.editNotes
  };
}

function query() {
  const params = new URLSearchParams({ page: String(state.page), per_page: "25" });
  if (elements.search.value.trim()) params.set("buscar", elements.search.value.trim());
  if (elements.filterCategory.value) params.set("categoria", elements.filterCategory.value);
  if (elements.filterStatus.value) params.set("estado", elements.filterStatus.value);
  if (elements.from.value) params.set("desde", elements.from.value);
  if (elements.to.value) params.set("hasta", elements.to.value);
  return params;
}

function statusBadge(status) {
  const active = status === "REGISTRADO";
  return `<span class="fin-management-status is-${active ? "registrado" : "anulado"}">${active ? "Vigente" : "Anulado"}</span>`;
}

function recordActions(record) {
  if (record.estado !== "REGISTRADO") return '<span class="fin-management-muted">Solo consulta</span>';
  const actions = [];
  if (permissions.create) actions.push(`<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-expense="${record.id}">Editar</button>`);
  if (permissions.void) actions.push(`<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-void-expense="${record.id}">Anular</button>`);
  return actions.length ? `<div class="fin-management-actions">${actions.join("")}</div>` : '<span class="fin-management-muted">Sin acciones</span>';
}

function renderRows(records) {
  state.records = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="7">No hay gastos que coincidan con los filtros.</td></tr>';
    return;
  }
  elements.rows.innerHTML = records.map((record) => `<tr class="${record.estado === "ANULADO" ? "is-muted" : ""}">
    <td><strong>${escapeHtml(formatDateTime(record.fecha_hora))}</strong><small>${escapeHtml(record.codigo)}</small></td>
    <td><strong>${escapeHtml(record.concepto)}</strong><small>${escapeHtml(CATEGORY_LABELS[record.categoria] || record.categoria)}${record.numero_documento ? ` · ${escapeHtml(record.numero_documento)}` : ""}</small></td>
    <td><strong>${escapeHtml(record.destino)}</strong><small>${escapeHtml(record.referencia || "Sin referencia")}</small></td>
    <td><strong>${escapeHtml(record.cuenta?.alias || "Sin cuenta")}</strong><small>${escapeHtml(record.metodo_pago?.nombre || "Sin método")}</small></td>
    <td>${statusBadge(record.estado)}</td>
    <td class="fin-text-right"><strong>${escapeHtml(formatMoney(record.importe, record.moneda))}</strong></td>
    <td>${recordActions(record)}</td>
  </tr>`).join("");
}

async function loadExpenses() {
  if (state.loading) return;
  state.loading = true;
  setMessage(elements.listMessage, "Consultando gastos...");
  try {
    const response = await apiRequest(`/finanzas/gastos?${query()}`);
    const records = Array.isArray(response.data) ? response.data : [];
    const meta = response.meta || {};
    const summary = response.resumen || {};
    state.page = Number(meta.current_page || 1);
    state.lastPage = Number(meta.last_page || 1);
    renderRows(records);
    elements.summaryTotal.textContent = formatMoney(summary.total_vigente || 0, summary.moneda || "PEN");
    elements.summaryCount.textContent = String(summary.cantidad_vigente || 0);
    elements.page.textContent = `Página ${state.page} de ${state.lastPage}`;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
    setMessage(elements.listMessage, `${Number(meta.total || 0)} registro${Number(meta.total || 0) === 1 ? "" : "s"} encontrado${Number(meta.total || 0) === 1 ? "" : "s"}.`);
    markFinanceAccessReady();
  } catch (error) {
    renderRows([]);
    setMessage(elements.listMessage, errorMessage(error, "No se pudieron cargar los gastos."), "error");
  } finally {
    state.loading = false;
  }
}

async function saveExpense(event) {
  event.preventDefault();
  if (!permissions.create) {
    setMessage(elements.message, "No tienes permiso para registrar gastos.", "error");
    return;
  }
  elements.save.disabled = true;
  setMessage(elements.message, "Registrando gasto...");
  try {
    await apiRequest("/finanzas/gastos", {
      method: "POST",
      body: JSON.stringify({ idempotency_key: uuid(), ...payload(createFields()) })
    });
    elements.form.reset();
    elements.date.value = nowLocal();
    updateCreateSummary();
    setMessage(elements.message, "Gasto registrado correctamente.", "success");
    await Promise.all([loadCatalog(), loadExpenses()]);
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudo registrar el gasto."), "error");
  } finally {
    elements.save.disabled = false;
  }
}

function openEdit(id) {
  const record = state.records.get(String(id));
  if (!record) return;
  elements.editId.value = record.id;
  elements.editDate.value = localDateTime(record.fecha_hora);
  elements.editCategory.value = record.categoria;
  elements.editConcept.value = record.concepto || "";
  elements.editDestination.value = record.destino || "";
  elements.editDocument.value = record.numero_documento || "";
  elements.editAccount.value = record.cuenta?.id || "";
  elements.editMethod.value = record.metodo_pago?.id || "";
  elements.editAmount.value = Number(record.importe).toFixed(2);
  elements.editReference.value = record.referencia || "";
  elements.editNotes.value = record.observaciones || "";
  setMessage(elements.editMessage, "");
  elements.editDialog.showModal();
}

async function saveEdit(event) {
  event.preventDefault();
  setMessage(elements.editMessage, "Guardando corrección...");
  try {
    await apiRequest(`/finanzas/gastos/${encodeURIComponent(elements.editId.value)}`, {
      method: "PUT",
      body: JSON.stringify(payload(editFields()))
    });
    elements.editDialog.close();
    await Promise.all([loadCatalog(), loadExpenses()]);
  } catch (error) {
    setMessage(elements.editMessage, errorMessage(error, "No se pudo editar el gasto."), "error");
  }
}

function openVoid(id) {
  const record = state.records.get(String(id));
  if (!record) return;
  state.voidId = record.id;
  elements.voidDescription.textContent = `Se anulará ${record.codigo} por ${formatMoney(record.importe, record.moneda)}. El importe volverá a ${record.cuenta?.alias || "la cuenta de origen"} y el historial se conservará.`;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage, "");
  elements.voidDialog.showModal();
}

async function voidExpense(event) {
  event.preventDefault();
  const reason = elements.voidReason.value.trim();
  if (reason.length < 5) {
    setMessage(elements.voidMessage, "Escribe un motivo de al menos 5 caracteres.", "error");
    return;
  }
  setMessage(elements.voidMessage, "Anulando y reintegrando el importe...");
  try {
    await apiRequest(`/finanzas/gastos/${encodeURIComponent(state.voidId)}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    state.voidId = null;
    elements.voidDialog.close();
    await Promise.all([loadCatalog(), loadExpenses()]);
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular el gasto."), "error");
  }
}

elements.date.value = nowLocal();
elements.account.addEventListener("change", updateCreateSummary);
elements.amount.addEventListener("input", updateCreateSummary);
elements.form.addEventListener("submit", saveExpense);
elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  void loadExpenses();
});
elements.clearFilters.addEventListener("click", () => {
  elements.filters.reset();
  state.page = 1;
  void loadExpenses();
});
elements.previous.addEventListener("click", () => {
  if (state.page > 1) {
    state.page -= 1;
    void loadExpenses();
  }
});
elements.next.addEventListener("click", () => {
  if (state.page < state.lastPage) {
    state.page += 1;
    void loadExpenses();
  }
});
elements.rows.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-edit-expense]");
  const voidButton = event.target.closest("[data-void-expense]");
  if (edit) openEdit(edit.dataset.editExpense);
  if (voidButton) openVoid(voidButton.dataset.voidExpense);
});
elements.editForm.addEventListener("submit", saveEdit);
elements.voidForm.addEventListener("submit", voidExpense);
document.querySelectorAll("[data-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => button.closest("dialog")?.close());
});
if (!permissions.create) {
  elements.form.querySelectorAll("input, select, textarea, button").forEach((field) => { field.disabled = true; });
  setMessage(elements.message, "Tienes acceso de consulta. Se requiere PAGOS_REGISTRAR para crear o editar.", "error");
}

async function initialize() {
  try {
    await loadCatalog();
    updateCreateSummary();
    await loadExpenses();
  } catch (error) {
    setMessage(elements.listMessage, errorMessage(error, "No se pudo iniciar la vista de gastos."), "error");
  }
}

initFinanceAccess(initialize);
void initialize();
