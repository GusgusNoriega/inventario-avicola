import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  setMessage
} from "./finanzas-common.js";

const byId = (id) => document.getElementById(id);
const elements = {
  form: byId("customerDiscountForm"),
  clientSearch: byId("customerDiscountClientSearch"),
  client: byId("customerDiscountClient"),
  amount: byId("customerDiscountAmount"),
  reason: byId("customerDiscountReason"),
  currencyPrefix: byId("customerDiscountCurrencyPrefix"),
  total: byId("customerDiscountTotal"),
  save: byId("customerDiscountSave"),
  message: byId("customerDiscountMessage"),
  currentDebt: byId("customerDiscountCurrentDebt"),
  currentHelp: byId("customerDiscountCurrentHelp"),
  projectedCredit: byId("customerDiscountProjectedCredit"),
  filters: byId("customerDiscountFilters"),
  recordSearch: byId("customerDiscountRecordSearch"),
  status: byId("customerDiscountStatus"),
  clearFilters: byId("customerDiscountClearFilters"),
  listMessage: byId("customerDiscountListMessage"),
  rows: byId("customerDiscountRows"),
  previous: byId("customerDiscountPrevious"),
  next: byId("customerDiscountNext"),
  page: byId("customerDiscountPage"),
  editDialog: byId("customerDiscountEditDialog"),
  editForm: byId("customerDiscountEditForm"),
  editId: byId("customerDiscountEditId"),
  editClient: byId("customerDiscountEditClient"),
  editAmount: byId("customerDiscountEditAmount"),
  editReason: byId("customerDiscountEditReason"),
  editMessage: byId("customerDiscountEditMessage"),
  voidDialog: byId("customerDiscountVoidDialog"),
  voidForm: byId("customerDiscountVoidForm"),
  voidDescription: byId("customerDiscountVoidDescription"),
  voidReason: byId("customerDiscountVoidReason"),
  voidMessage: byId("customerDiscountVoidMessage")
};

const canAdjust = document.body.dataset.canAdjustDiscounts === "1";
const state = {
  clients: [],
  records: new Map(),
  currency: "PEN",
  currentPending: 0,
  currentCredit: 0,
  selectedClientId: null,
  voidId: null,
  page: 1,
  lastPage: 1,
  loading: false
};

function currencyPrefix(currency) {
  return currency === "USD" ? "$" : "S/";
}

function clientLabel(client) {
  const document = client.numero_documento ? ` · ${client.numero_documento}` : "";
  return `${client.nombre}${document}`;
}

function clientOptions(records, selected = "") {
  const options = records.map((client) =>
    `<option value="${client.id}">${escapeHtml(clientLabel(client))}</option>`
  );
  return `<option value="">Selecciona un cliente</option>${options.join("")}`;
}

function fillClientSelects() {
  const selected = elements.client.value;
  const query = elements.clientSearch.value.trim().toLocaleLowerCase("es");
  const filtered = !query
    ? state.clients
    : state.clients.filter((client) =>
      `${client.nombre} ${client.numero_documento || ""}`.toLocaleLowerCase("es").includes(query)
    );

  elements.client.innerHTML = clientOptions(filtered, selected);
  if (filtered.some((client) => String(client.id) === String(selected))) {
    elements.client.value = selected;
  }
  elements.editClient.innerHTML = clientOptions(state.clients);
}

async function loadCatalog() {
  const response = await apiRequest("/finanzas/catalogo");
  const catalog = response.data || {};
  state.clients = catalog.clientes || [];
  fillClientSelects();
  markFinanceAccessReady();
}

async function loadCustomerSummary() {
  const clientId = Number(elements.client.value || 0);
  state.selectedClientId = clientId || null;
  state.currentPending = 0;
  state.currentCredit = 0;

  if (!clientId) {
    elements.currentHelp.textContent = "Selecciona un cliente para consultar su saldo.";
    updatePreview();
    return;
  }

  elements.currentHelp.textContent = "Consultando saldo...";
  try {
    const response = await apiRequest(`/finanzas/clientes/${clientId}/resumen`);
    const summary = response.data || {};
    const pending = Number(summary.pending || 0);
    state.currency = summary.currency || "PEN";
    state.currentPending = Math.max(pending, 0);
    state.currentCredit = Math.max(-pending, 0);
    elements.currentHelp.textContent = state.currentCredit > 0
      ? `Ya tiene ${formatMoney(state.currentCredit, state.currency)} a favor.`
      : "Saldo pendiente antes de este descuento.";
  } catch (error) {
    elements.currentHelp.textContent = errorMessage(error, "No se pudo consultar el saldo.");
  }
  updatePreview();
}

function updatePreview() {
  const amount = Math.max(Number(elements.amount.value || 0), 0);
  const projectedCredit = state.currentCredit + Math.max(amount - state.currentPending, 0);
  elements.currencyPrefix.textContent = currencyPrefix(state.currency);
  elements.total.textContent = formatMoney(amount, state.currency);
  elements.currentDebt.textContent = formatMoney(state.currentPending, state.currency);
  elements.projectedCredit.textContent = formatMoney(projectedCredit, state.currency);
}

function query() {
  const params = new URLSearchParams({
    page: String(state.page),
    per_page: "25"
  });
  if (elements.recordSearch.value.trim()) params.set("buscar", elements.recordSearch.value.trim());
  if (elements.status.value) params.set("estado", elements.status.value);
  return params;
}

function statusBadge(status) {
  const active = status === "REGISTRADO";
  return `<span class="fin-management-status is-${active ? "registrado" : "anulado"}">${active ? "Vigente" : "Anulado"}</span>`;
}

function recordActions(record) {
  if (!canAdjust || record.estado !== "REGISTRADO") {
    return '<span class="fin-management-muted">Solo consulta</span>';
  }

  return `<div class="fin-management-actions">
    <button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-discount="${record.id}">Editar</button>
    <button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-void-discount="${record.id}">Anular</button>
  </div>`;
}

function renderRows(records) {
  state.records = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.rows.innerHTML = '<tr><td colspan="8" class="fin-empty">No hay descuentos que coincidan con la búsqueda.</td></tr>';
    return;
  }

  elements.rows.innerHTML = records.map((record) => `
    <tr class="${record.estado === "ANULADO" ? "is-muted" : ""}">
      <td><strong>${escapeHtml(formatDateTime(record.fecha_hora))}</strong><small>${escapeHtml(record.codigo || `#${record.id}`)}</small></td>
      <td><strong>${escapeHtml(record.cliente?.nombre || "Cliente")}</strong><small>${escapeHtml(record.cliente?.numero_documento || "Sin documento")}</small></td>
      <td><span class="fin-management-detail">${escapeHtml(record.motivo || "Sin motivo")}</span></td>
      <td>${statusBadge(record.estado)}</td>
      <td class="fin-text-right"><strong>${escapeHtml(formatMoney(record.importe, record.moneda))}</strong></td>
      <td class="fin-text-right">${escapeHtml(formatMoney(record.importe_aplicado, record.moneda))}</td>
      <td class="fin-text-right">${escapeHtml(formatMoney(record.saldo_favor, record.moneda))}</td>
      <td>${recordActions(record)}</td>
    </tr>
  `).join("");
}

async function loadRecords() {
  if (state.loading) return;
  state.loading = true;
  setMessage(elements.listMessage, "Cargando descuentos...");

  try {
    const response = await apiRequest(`/finanzas/descuentos-clientes?${query()}`);
    renderRows(response.data || []);
    state.page = Number(response.meta?.current_page || 1);
    state.lastPage = Number(response.meta?.last_page || 1);
    elements.page.textContent = `Página ${state.page} de ${state.lastPage}`;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
    setMessage(
      elements.listMessage,
      response.meta?.total
        ? `${response.meta.total} registro${Number(response.meta.total) === 1 ? "" : "s"} encontrado${Number(response.meta.total) === 1 ? "" : "s"}.`
        : "No hay descuentos registrados."
    );
    markFinanceAccessReady();
  } catch (error) {
    renderRows([]);
    setMessage(elements.listMessage, errorMessage(error, "No se pudieron cargar los descuentos."), "error");
  } finally {
    state.loading = false;
  }
}

function validPayload(client, amount, reason) {
  if (!client) throw new Error("Selecciona un cliente.");
  if (!Number.isFinite(amount) || amount <= 0) throw new Error("Ingresa un monto mayor que cero.");
  if (reason.length < 3) throw new Error("Escribe el motivo del descuento.");

  return {
    idempotency_key: createIdempotencyKey(),
    cliente_id: client,
    importe: amount.toFixed(2),
    motivo: reason
  };
}

async function submitCreate(event) {
  event.preventDefault();
  if (!canAdjust) {
    setMessage(elements.message, "No tienes permiso para ajustar saldos.", "error");
    return;
  }

  try {
    const payload = validPayload(
      Number(elements.client.value || 0),
      Number(elements.amount.value || 0),
      elements.reason.value.trim()
    );
    elements.save.disabled = true;
    setMessage(elements.message, "Registrando descuento...");
    const response = await apiRequest("/finanzas/descuentos-clientes", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    setMessage(elements.message, response.message || "Descuento registrado.", "success");
    elements.amount.value = "";
    elements.reason.value = "";
    state.page = 1;
    await Promise.all([loadCustomerSummary(), loadRecords()]);
  } catch (error) {
    setMessage(elements.message, errorMessage(error, error.message), "error");
  } finally {
    elements.save.disabled = false;
    updatePreview();
  }
}

function openDialog(dialog) {
  if (typeof dialog.showModal === "function") dialog.showModal();
  else dialog.setAttribute("open", "");
}

function closeDialog(dialog) {
  if (typeof dialog.close === "function") dialog.close();
  else dialog.removeAttribute("open");
}

function openEdit(record) {
  elements.editId.value = record.id;
  elements.editClient.innerHTML = clientOptions(state.clients);
  elements.editClient.value = String(record.cliente?.id || "");
  elements.editAmount.value = Number(record.importe).toFixed(2);
  elements.editReason.value = record.motivo || "";
  setMessage(elements.editMessage);
  openDialog(elements.editDialog);
}

async function submitEdit(event) {
  event.preventDefault();
  const id = Number(elements.editId.value || 0);

  try {
    const payload = validPayload(
      Number(elements.editClient.value || 0),
      Number(elements.editAmount.value || 0),
      elements.editReason.value.trim()
    );
    const submit = elements.editForm.querySelector('[type="submit"]');
    submit.disabled = true;
    setMessage(elements.editMessage, "Guardando cambios...");
    const response = await apiRequest(`/finanzas/descuentos-clientes/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload)
    });
    closeDialog(elements.editDialog);
    setMessage(elements.listMessage, response.message || "Descuento actualizado.", "success");
    await loadRecords();
    if (state.selectedClientId) await loadCustomerSummary();
    submit.disabled = false;
  } catch (error) {
    setMessage(elements.editMessage, errorMessage(error, "No se pudo editar el descuento."), "error");
    const submit = elements.editForm.querySelector('[type="submit"]');
    submit.disabled = false;
  }
}

function openVoid(record) {
  state.voidId = record.id;
  elements.voidDescription.textContent = `Se restaurará el saldo descontado a ${record.cliente?.nombre || "este cliente"}.`;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage);
  openDialog(elements.voidDialog);
}

async function submitVoid(event) {
  event.preventDefault();
  const reason = elements.voidReason.value.trim();
  if (reason.length < 5) {
    setMessage(elements.voidMessage, "Escribe un motivo de al menos 5 caracteres.", "error");
    return;
  }

  const submit = elements.voidForm.querySelector('[type="submit"]');
  submit.disabled = true;
  setMessage(elements.voidMessage, "Anulando descuento...");
  try {
    const response = await apiRequest(`/finanzas/descuentos-clientes/${state.voidId}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    closeDialog(elements.voidDialog);
    setMessage(elements.listMessage, response.message || "Descuento anulado.", "success");
    await loadRecords();
    if (state.selectedClientId) await loadCustomerSummary();
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular el descuento."), "error");
  } finally {
    submit.disabled = false;
  }
}

elements.clientSearch.addEventListener("input", fillClientSelects);
elements.client.addEventListener("change", loadCustomerSummary);
elements.amount.addEventListener("input", updatePreview);
elements.form.addEventListener("submit", submitCreate);
elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  loadRecords();
});
elements.clearFilters.addEventListener("click", () => {
  elements.recordSearch.value = "";
  elements.status.value = "";
  state.page = 1;
  loadRecords();
});
elements.previous.addEventListener("click", () => {
  if (state.page <= 1) return;
  state.page -= 1;
  loadRecords();
});
elements.next.addEventListener("click", () => {
  if (state.page >= state.lastPage) return;
  state.page += 1;
  loadRecords();
});
elements.rows.addEventListener("click", (event) => {
  const editButton = event.target.closest("[data-edit-discount]");
  const voidButton = event.target.closest("[data-void-discount]");
  const id = editButton?.dataset.editDiscount || voidButton?.dataset.voidDiscount;
  const record = state.records.get(String(id));
  if (!record) return;
  if (editButton) openEdit(record);
  if (voidButton) openVoid(record);
});
elements.editForm.addEventListener("submit", submitEdit);
elements.voidForm.addEventListener("submit", submitVoid);
document.querySelectorAll("[data-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => closeDialog(button.closest("dialog")));
});

if (!canAdjust) {
  elements.form.querySelectorAll("input, select, textarea, button").forEach((control) => {
    control.disabled = true;
  });
  setMessage(elements.message, "Puedes consultar registros, pero no ajustar saldos.", "error");
}

updatePreview();
initFinanceAccess(async () => {
  await Promise.all([loadCatalog(), loadRecords()]);
});
Promise.all([loadCatalog(), loadRecords()]).catch(() => {});
