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
  responseCollection,
  responseMeta,
  setMessage
} from "./finanzas-common.js";

const TYPE_LABELS = {
  COBRO_CLIENTE: "Cobro de cliente",
  PAGO_DIRECTO: "Pago directo a proveedor",
  PAGO_PROVEEDOR: "Pago a proveedor",
  SALDO_FAVOR_PROVEEDOR: "Saldo anterior con proveedor",
  COBRO_MINORISTA: "Cobro minorista",
  REEMBOLSO_CLIENTE: "Reembolso a cliente",
  GASTO_EMPRESA: "Gasto de empresa",
  SALDO_INICIAL: "Saldo inicial",
  AJUSTE: "Ajuste",
  TRANSFERENCIA_INTERNA: "Transferencia interna"
};

const elements = {
  movementsTab: document.getElementById("financeMovementsTab"),
  debtsTab: document.getElementById("financeDebtsTab"),
  movementsPanel: document.getElementById("financeMovementsPanel"),
  debtsPanel: document.getElementById("financeDebtsPanel"),
  filters: document.getElementById("financeManagementFilters"),
  search: document.getElementById("financeManagementSearch"),
  status: document.getElementById("financeManagementStatus"),
  type: document.getElementById("financeManagementType"),
  typeField: document.getElementById("financeManagementTypeField"),
  from: document.getElementById("financeManagementFrom"),
  to: document.getElementById("financeManagementTo"),
  clear: document.getElementById("financeManagementClear"),
  movementsMessage: document.getElementById("financeMovementsMessage"),
  debtsMessage: document.getElementById("financeDebtsMessage"),
  movementsRows: document.getElementById("financeMovementsRows"),
  debtsRows: document.getElementById("financeDebtsRows"),
  previous: document.getElementById("financeManagementPrevious"),
  next: document.getElementById("financeManagementNext"),
  page: document.getElementById("financeManagementPage"),
  editMovementDialog: document.getElementById("financeEditMovementDialog"),
  editMovementForm: document.getElementById("financeEditMovementForm"),
  editMovementId: document.getElementById("financeEditMovementId"),
  editMovementDate: document.getElementById("financeEditMovementDate"),
  editMovementReference: document.getElementById("financeEditMovementReference"),
  editMovementNotes: document.getElementById("financeEditMovementNotes"),
  editMovementMessage: document.getElementById("financeEditMovementMessage"),
  editDebtDialog: document.getElementById("financeEditDebtDialog"),
  editDebtForm: document.getElementById("financeEditDebtForm"),
  editDebtId: document.getElementById("financeEditDebtId"),
  editDebtClient: document.getElementById("financeEditDebtClient"),
  editDebtDate: document.getElementById("financeEditDebtDate"),
  editDebtAmount: document.getElementById("financeEditDebtAmount"),
  editDebtCurrency: document.getElementById("financeEditDebtCurrency"),
  editDebtDetail: document.getElementById("financeEditDebtDetail"),
  editDebtMessage: document.getElementById("financeEditDebtMessage"),
  voidDialog: document.getElementById("financeVoidDialog"),
  voidForm: document.getElementById("financeVoidForm"),
  voidTitle: document.getElementById("financeVoidTitle"),
  voidDescription: document.getElementById("financeVoidDescription"),
  voidReason: document.getElementById("financeVoidReason"),
  voidMessage: document.getElementById("financeVoidMessage")
};

const state = {
  active: "movements",
  page: 1,
  lastPage: 1,
  movements: new Map(),
  debts: new Map(),
  clients: [],
  voidTarget: null,
  loading: false
};

const permissions = {
  editMovements: document.body.dataset.canEditMovements === "1",
  voidMovements: document.body.dataset.canVoidMovements === "1",
  adjustBalances: document.body.dataset.canAdjustBalances === "1"
};

function localDateTime(value) {
  if (!value) return "";
  const normalized = String(value).replace(" ", "T");
  return normalized.slice(0, 16);
}

function counterpart(record) {
  return firstDefined(record, ["cliente.nombre", "proveedor.nombre"], "Sin contraparte");
}

function statusBadge(status) {
  const normalized = String(status || "").toUpperCase();
  const label = {
    REGISTRADO: "Vigente",
    PENDIENTE: "Pendiente",
    PARCIAL: "Parcial",
    PAGADO: "Pagado",
    ANULADO: "Anulado"
  }[normalized] || normalized;
  return `<span class="fin-management-status is-${escapeHtml(normalized.toLowerCase())}">${escapeHtml(label)}</span>`;
}

function movementActions(record) {
  const active = record.estado === "REGISTRADO" && !record.reversa_de_pago_id;
  const actions = [];
  if (active && permissions.editMovements) {
    actions.push(`<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-movement="${record.id}">Editar</button>`);
  }
  if (active && permissions.voidMovements) {
    actions.push(`<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-void-movement="${record.id}">Anular</button>`);
  }
  if (!actions.length) return '<span class="fin-management-muted">Sin acciones</span>';
  return `<div class="fin-management-actions">${actions.join("")}</div>`;
}

function renderMovements(records) {
  state.movements = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.movementsRows.innerHTML = '<tr><td class="fin-empty-cell" colspan="7">No hay transacciones que coincidan con los filtros.</td></tr>';
    return;
  }
  elements.movementsRows.innerHTML = records.map((record) => {
    const type = String(record.tipo || "");
    const isReverse = Boolean(record.reversa_de_pago_id);
    const application = record.aplicacion;
    const credit = application && Number(application.importe_sin_aplicar) > 0
      ? `<small>Disponible: ${escapeHtml(formatMoney(application.importe_sin_aplicar, record.moneda))}</small>`
      : "";
    return `<tr class="${record.estado === "ANULADO" || isReverse ? "is-muted" : ""}">
      <td><strong>${escapeHtml(formatDateTime(record.fecha_hora))}</strong><small>${escapeHtml(record.codigo || `#${record.id}`)}</small></td>
      <td><strong>${escapeHtml(isReverse ? `Reversa de ${TYPE_LABELS[type] || type}` : TYPE_LABELS[type] || type)}</strong>${credit}</td>
      <td><strong>${escapeHtml(counterpart(record))}</strong><small>${escapeHtml(firstDefined(record, ["cliente.numero_documento", "proveedor.numero_documento"], "—"))}</small></td>
      <td><strong>${escapeHtml(record.referencia || "Sin referencia")}</strong><small>${escapeHtml(record.observaciones || "Sin observaciones")}</small></td>
      <td>${statusBadge(isReverse ? "REVERSA" : record.estado)}</td>
      <td class="fin-text-right"><strong>${escapeHtml(formatMoney(record.importe, record.moneda))}</strong></td>
      <td>${movementActions(record)}</td>
    </tr>`;
  }).join("");
}

function debtActions(record) {
  const actions = [];
  if (record.puede_editar && permissions.adjustBalances) {
    actions.push(`<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-debt="${record.id}">Editar</button>`);
  }
  if (record.puede_anular && permissions.adjustBalances) {
    actions.push(`<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-void-debt="${record.id}">Anular</button>`);
  }
  return actions.length
    ? `<div class="fin-management-actions">${actions.join("")}</div>`
    : '<span class="fin-management-muted">Registro cerrado</span>';
}

function renderDebts(records) {
  state.debts = new Map(records.map((record) => [String(record.id), record]));
  if (!records.length) {
    elements.debtsRows.innerHTML = '<tr><td class="fin-empty-cell" colspan="7">No hay deudas anteriores que coincidan con los filtros.</td></tr>';
    return;
  }
  elements.debtsRows.innerHTML = records.map((record) => `<tr class="${record.estado === "ANULADO" ? "is-muted" : ""}">
    <td><strong>${escapeHtml(record.fecha_emision)}</strong><small>${escapeHtml(record.codigo || `#${record.id}`)}</small></td>
    <td><strong>${escapeHtml(record.cliente?.nombre || "Sin cliente")}</strong><small>${escapeHtml(record.cliente?.numero_documento || "—")}</small></td>
    <td><span class="fin-management-detail">${escapeHtml(record.detalle || "Sin detalle")}</span></td>
    <td>${statusBadge(record.estado)}</td>
    <td class="fin-text-right"><strong>${escapeHtml(formatMoney(record.total, record.moneda))}</strong></td>
    <td class="fin-text-right"><strong>${escapeHtml(formatMoney(record.saldo_pendiente, record.moneda))}</strong></td>
    <td>${debtActions(record)}</td>
  </tr>`).join("");
}

function query() {
  const params = new URLSearchParams({ page: String(state.page), per_page: "25" });
  if (elements.search.value.trim()) params.set("buscar", elements.search.value.trim());
  if (elements.status.value) params.set("estado", elements.status.value);
  if (elements.from.value) params.set("desde", elements.from.value);
  if (elements.to.value) params.set("hasta", elements.to.value);
  if (state.active === "movements" && elements.type.value) params.set("tipo", elements.type.value);
  return params;
}

async function loadRecords() {
  if (state.loading) return;
  state.loading = true;
  const message = state.active === "movements" ? elements.movementsMessage : elements.debtsMessage;
  setMessage(message, state.active === "movements" ? "Cargando movimientos..." : "Cargando deudas anteriores...");
  try {
    const endpoint = state.active === "movements" ? "/finanzas/movimientos" : "/finanzas/deudas-clientes";
    const response = await apiRequest(`${endpoint}?${query()}`);
    const records = responseCollection(response, ["movimientos", "deudas", "items", "records"]);
    const meta = responseMeta(response);
    state.lastPage = Number(meta.last_page || 1);
    state.page = Number(meta.current_page || state.page);
    if (state.active === "movements") renderMovements(records);
    else renderDebts(records);
    elements.page.textContent = `Página ${state.page} de ${state.lastPage}`;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
    setMessage(message, `${Number(meta.total ?? records.length)} registro${Number(meta.total ?? records.length) === 1 ? "" : "s"} encontrado${Number(meta.total ?? records.length) === 1 ? "" : "s"}.`);
    markFinanceAccessReady();
  } catch (error) {
    setMessage(message, errorMessage(error, "No se pudo cargar el historial."), "error");
  } finally {
    state.loading = false;
  }
}

async function loadClients() {
  if (state.clients.length) return;
  const response = await apiRequest("/finanzas/catalogo");
  state.clients = responseCollection(response, ["clientes", "catalogo.clientes"]);
  fillSelect(elements.editDebtClient, state.clients, {
    placeholder: "Selecciona un cliente",
    label: (item) => firstDefined(item, ["nombre", "nombre_razon_social"], "Cliente"),
    value: (item) => item.id
  });
}

function setStatusOptions(active) {
  elements.status.innerHTML = active === "movements"
    ? '<option value="">Todos</option><option value="REGISTRADO">Vigentes</option><option value="ANULADO">Anulados</option>'
    : '<option value="">Todos</option><option value="PENDIENTE">Pendientes</option><option value="PARCIAL">Parciales</option><option value="PAGADO">Pagadas</option><option value="ANULADO">Anuladas</option>';
}

function switchTab(active) {
  state.active = active;
  state.page = 1;
  const movements = active === "movements";
  elements.movementsTab.classList.toggle("is-active", movements);
  elements.debtsTab.classList.toggle("is-active", !movements);
  elements.movementsTab.setAttribute("aria-selected", String(movements));
  elements.debtsTab.setAttribute("aria-selected", String(!movements));
  elements.movementsPanel.hidden = !movements;
  elements.debtsPanel.hidden = movements;
  elements.typeField.hidden = !movements;
  setStatusOptions(active);
  void loadRecords();
}

function openMovementEdit(id) {
  const record = state.movements.get(String(id));
  if (!record) return;
  elements.editMovementId.value = record.id;
  elements.editMovementDate.value = localDateTime(record.fecha_hora);
  elements.editMovementReference.value = record.referencia || "";
  elements.editMovementNotes.value = record.observaciones || "";
  setMessage(elements.editMovementMessage, "");
  elements.editMovementDialog.showModal();
}

async function openDebtEdit(id) {
  const record = state.debts.get(String(id));
  if (!record) return;
  setMessage(elements.editDebtMessage, "Preparando datos...");
  elements.editDebtDialog.showModal();
  try {
    await loadClients();
    elements.editDebtId.value = record.id;
    elements.editDebtClient.value = record.cliente?.id || "";
    elements.editDebtDate.value = record.fecha_emision || "";
    elements.editDebtAmount.value = Number(record.total).toFixed(2);
    elements.editDebtCurrency.value = record.moneda || "PEN";
    elements.editDebtDetail.value = record.detalle || "";
    setMessage(elements.editDebtMessage, "");
  } catch (error) {
    setMessage(elements.editDebtMessage, errorMessage(error, "No se pudieron cargar los clientes."), "error");
  }
}

function openVoid(kind, id) {
  const record = kind === "movement" ? state.movements.get(String(id)) : state.debts.get(String(id));
  if (!record) return;
  state.voidTarget = { kind, id };
  const label = record.codigo || `#${id}`;
  elements.voidTitle.textContent = kind === "movement" ? "Anular movimiento" : "Anular deuda anterior";
  elements.voidDescription.textContent = kind === "movement"
    ? `Se anulará ${label} y se generará una reversa contable. Esta acción no elimina el historial.`
    : `Se anulará ${label} y su saldo pendiente quedará en cero. Solo es posible porque aún no tiene cobros aplicados.`;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage, "");
  elements.voidDialog.showModal();
}

async function saveMovement(event) {
  event.preventDefault();
  const id = elements.editMovementId.value;
  setMessage(elements.editMovementMessage, "Guardando cambios...");
  try {
    await apiRequest(`/finanzas/movimientos/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify({
        fecha_hora: elements.editMovementDate.value,
        referencia: elements.editMovementReference.value.trim() || null,
        observaciones: elements.editMovementNotes.value.trim() || null
      })
    });
    elements.editMovementDialog.close();
    await loadRecords();
  } catch (error) {
    setMessage(elements.editMovementMessage, errorMessage(error, "No se pudo editar el movimiento."), "error");
  }
}

async function saveDebt(event) {
  event.preventDefault();
  const id = elements.editDebtId.value;
  setMessage(elements.editDebtMessage, "Guardando deuda...");
  try {
    await apiRequest(`/finanzas/deudas-clientes/${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify({
        cliente_id: Number(elements.editDebtClient.value),
        fecha_emision: elements.editDebtDate.value,
        importe: Number(elements.editDebtAmount.value).toFixed(2),
        moneda: elements.editDebtCurrency.value,
        detalle: elements.editDebtDetail.value.trim()
      })
    });
    elements.editDebtDialog.close();
    await loadRecords();
  } catch (error) {
    setMessage(elements.editDebtMessage, errorMessage(error, "No se pudo editar la deuda."), "error");
  }
}

async function voidRecord(event) {
  event.preventDefault();
  if (!state.voidTarget) return;
  const reason = elements.voidReason.value.trim();
  if (reason.length < 5) {
    setMessage(elements.voidMessage, "Escribe un motivo de al menos 5 caracteres.", "error");
    return;
  }
  setMessage(elements.voidMessage, "Procesando anulación...");
  const { kind, id } = state.voidTarget;
  const endpoint = kind === "movement"
    ? `/finanzas/movimientos/${encodeURIComponent(id)}/anular`
    : `/finanzas/deudas-clientes/${encodeURIComponent(id)}/anular`;
  try {
    await apiRequest(endpoint, { method: "POST", body: JSON.stringify({ motivo: reason }) });
    state.voidTarget = null;
    elements.voidDialog.close();
    await loadRecords();
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular el registro."), "error");
  }
}

elements.movementsTab.addEventListener("click", () => switchTab("movements"));
elements.debtsTab.addEventListener("click", () => switchTab("debts"));
elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  void loadRecords();
});
elements.clear.addEventListener("click", () => {
  elements.filters.reset();
  setStatusOptions(state.active);
  state.page = 1;
  void loadRecords();
});
elements.previous.addEventListener("click", () => {
  if (state.page <= 1) return;
  state.page -= 1;
  void loadRecords();
});
elements.next.addEventListener("click", () => {
  if (state.page >= state.lastPage) return;
  state.page += 1;
  void loadRecords();
});
elements.movementsRows.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-edit-movement]");
  const voidButton = event.target.closest("[data-void-movement]");
  if (edit) openMovementEdit(edit.dataset.editMovement);
  if (voidButton) openVoid("movement", voidButton.dataset.voidMovement);
});
elements.debtsRows.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-edit-debt]");
  const voidButton = event.target.closest("[data-void-debt]");
  if (edit) void openDebtEdit(edit.dataset.editDebt);
  if (voidButton) openVoid("debt", voidButton.dataset.voidDebt);
});
document.querySelectorAll("[data-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => button.closest("dialog")?.close());
});
elements.editMovementForm.addEventListener("submit", saveMovement);
elements.editDebtForm.addEventListener("submit", saveDebt);
elements.voidForm.addEventListener("submit", voidRecord);

initFinanceAccess(loadRecords);
void loadRecords();
