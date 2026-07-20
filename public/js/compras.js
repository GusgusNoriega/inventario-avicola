import { apiRequest } from "./api-client.js";
import {
  errorMessage,
  escapeHtml,
  fillSelect,
  firstDefined,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  numericValue,
  optionLabel,
  responseCollection,
  responseMeta,
  setMessage
} from "./finanzas-common.js";

const elements = {
  total: document.getElementById("purchaseTotalAmount"),
  cash: document.getElementById("purchaseCashAmount"),
  credit: document.getElementById("purchaseCreditAmount"),
  legacy: document.getElementById("purchaseLegacyAmount"),
  pending: document.getElementById("purchasePendingAmount"),
  filters: document.getElementById("purchaseFilters"),
  filterFrom: document.getElementById("purchaseFilterFrom"),
  filterTo: document.getElementById("purchaseFilterTo"),
  filterProvider: document.getElementById("purchaseFilterProvider"),
  filterCondition: document.getElementById("purchaseFilterCondition"),
  filterCurrency: document.getElementById("purchaseFilterCurrency"),
  filterStatus: document.getElementById("purchaseFilterStatus"),
  filterSearch: document.getElementById("purchaseFilterSearch"),
  clearFilters: document.getElementById("purchaseClearFilters"),
  message: document.getElementById("purchaseListMessage"),
  rows: document.getElementById("purchaseRows"),
  previous: document.getElementById("purchasePrevious"),
  next: document.getElementById("purchaseNext"),
  page: document.getElementById("purchasePage"),
  dialog: document.getElementById("purchaseDetailDialog"),
  detailTitle: document.getElementById("purchaseDetailTitle"),
  detailMessage: document.getElementById("purchaseDetailMessage"),
  detailContent: document.getElementById("purchaseDetailContent"),
  detailClose: document.getElementById("purchaseDetailClose"),
  companyPayment: document.getElementById("purchaseCompanyPayment"),
  providerCredit: document.getElementById("purchaseProviderCredit"),
  directPayment: document.getElementById("purchaseDirectPayment"),
  voidStart: document.getElementById("purchaseVoidStart"),
  voidPanel: document.getElementById("purchaseVoidPanel"),
  voidReason: document.getElementById("purchaseVoidReason"),
  voidMessage: document.getElementById("purchaseVoidMessage"),
  voidConfirm: document.getElementById("purchaseVoidConfirm"),
  voidCancel: document.getElementById("purchaseVoidCancel")
};

const state = {
  currency: "PEN",
  page: 1,
  lastPage: 1,
  perPage: 25,
  loading: false,
  detailSequence: 0,
  currentPurchase: null,
  voiding: false,
  queryOpened: false
};

function moneyMetric(source, keys, fallback = 0) {
  const value = firstDefined(source || {}, keys, fallback);
  if (Array.isArray(value)) {
    const row = value.find((item) => String(firstDefined(item, ["moneda", "currency"], state.currency)) === state.currency) || value[0];
    return numericValue(firstDefined(row || {}, ["importe", "total", "saldo", "amount"], fallback));
  }
  if (value && typeof value === "object") {
    return numericValue(firstDefined(value, ["importe", "total", "saldo", "amount", state.currency], fallback));
  }
  return numericValue(value, fallback);
}

function formatDate(value) {
  if (!value) return "Sin fecha";
  const normalized = /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? `${value}T12:00:00` : value;
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("es-PE", { day: "2-digit", month: "short", year: "numeric" }).format(date);
}

function purchaseProvider(purchase) {
  return firstDefined(purchase, ["proveedor", "provider", "tercero", "counterparty"], {});
}

function providerName(purchase) {
  const provider = purchaseProvider(purchase);
  if (provider && typeof provider === "object") return optionLabel(provider, "Proveedor sin nombre");
  return String(provider || firstDefined(purchase, ["proveedor_nombre", "contraparte_nombre"], "Proveedor sin nombre"));
}

function providerId(purchase) {
  return firstDefined(purchase, ["proveedor_id", "provider_id", "proveedor.id", "provider.id", "tercero_id"], null);
}

function purchaseCondition(purchase) {
  return String(firstDefined(purchase, ["condicion", "condicion_pago", "payment_condition"], "CREDITO")).toUpperCase();
}

function conditionLabel(condition) {
  if (condition === "CONTADO") return "Contado";
  if (condition === "LEGADO") return "Histórica sin clasificar";
  return "Crédito";
}

function conditionClass(condition) {
  if (condition === "CONTADO") return "is-cash";
  if (condition === "LEGADO") return "is-legacy";
  return "is-credit";
}

function purchaseStatus(purchase) {
  return String(firstDefined(purchase, ["estado", "status"], "PENDIENTE")).toUpperCase();
}

function purchaseCurrency(purchase) {
  return String(firstDefined(purchase, ["moneda", "currency"], state.currency));
}

function purchaseTotal(purchase) {
  return numericValue(firstDefined(purchase, ["total", "importe_total", "amount"], 0));
}

function purchasePending(purchase) {
  return numericValue(firstDefined(purchase, ["saldo_pendiente", "pendiente", "balance"], 0));
}

function purchaseNumber(purchase) {
  const external = firstDefined(purchase, ["numero_documento", "numero_documento_externo", "document_number"], null);
  const internal = firstDefined(purchase, ["codigo", "code", "id"], "Sin número");
  return external || internal;
}

function documentType(purchase) {
  return String(firstDefined(purchase, ["tipo_documento", "document_type"], "INTERNO")).replaceAll("_", " ");
}

function statusClass(status) {
  if (status === "PAGADO") return "is-paid";
  if (status === "ANULADO") return "is-inactive";
  if (status === "PARCIAL") return "is-partial";
  return "is-pending";
}

function renderSummary(response, records) {
  const summary = firstDefined(response, ["resumen", "summary", "data.resumen", "data.summary"], {});
  const calculated = records.reduce((totals, record) => {
    const total = purchaseTotal(record);
    const condition = purchaseCondition(record);
    totals.total += total;
    if (condition === "CONTADO") totals.cash += total;
    else if (condition === "LEGADO") totals.legacy += total;
    else totals.credit += total;
    totals.pending += purchasePending(record);
    return totals;
  }, { total: 0, cash: 0, credit: 0, legacy: 0, pending: 0 });

  state.currency = String(firstDefined(summary, ["moneda", "currency"], state.currency));
  elements.total.textContent = formatMoney(moneyMetric(summary, ["total_compras", "total", "importe_total"], calculated.total), state.currency);
  elements.cash.textContent = formatMoney(moneyMetric(summary, ["total_contado", "contado", "compras_contado"], calculated.cash), state.currency);
  elements.credit.textContent = formatMoney(moneyMetric(summary, ["total_credito", "credito", "compras_credito"], calculated.credit), state.currency);
  elements.legacy.textContent = formatMoney(moneyMetric(summary, ["sin_clasificar", "legacy", "historicas"], calculated.legacy), state.currency);
  elements.pending.textContent = formatMoney(moneyMetric(summary, ["saldo_pendiente", "por_pagar", "pendiente"], calculated.pending), state.currency);
}

function renderRows(records) {
  if (!records.length) {
    elements.rows.innerHTML = `<tr><td class="fin-empty-cell" colspan="8">No hay compras que coincidan con los filtros.</td></tr>`;
    return;
  }

  elements.rows.innerHTML = records.map((purchase) => {
    const id = firstDefined(purchase, ["id", "compra_id"]);
    const condition = purchaseCondition(purchase);
    const status = purchaseStatus(purchase);
    const currency = purchaseCurrency(purchase);
    const date = firstDefined(purchase, ["fecha_compra", "fecha_emision", "date"], null);

    return `
      <tr>
        <td><strong>${escapeHtml(formatDate(date))}</strong><small>${escapeHtml(firstDefined(purchase, ["codigo", "code"], `Compra #${id}`))}</small></td>
        <td><strong>${escapeHtml(purchaseNumber(purchase))}</strong><small>${escapeHtml(documentType(purchase))}</small></td>
        <td>${escapeHtml(providerName(purchase))}</td>
        <td><span class="fin-purchase-condition-tag ${conditionClass(condition)}">${conditionLabel(condition)}</span></td>
        <td class="fin-text-right fin-table-amount">${escapeHtml(formatMoney(purchaseTotal(purchase), currency))}</td>
        <td class="fin-text-right fin-table-amount ${purchasePending(purchase) > 0 ? "is-pending" : ""}">${escapeHtml(formatMoney(purchasePending(purchase), currency))}</td>
        <td><span class="fin-status-tag ${statusClass(status)}">${escapeHtml(status.replaceAll("_", " "))}</span></td>
        <td class="fin-text-right"><button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-purchase-detail="${escapeHtml(id)}">Ver detalle</button></td>
      </tr>`;
  }).join("");
}

function filterQuery() {
  const params = new URLSearchParams({ page: String(state.page), per_page: String(state.perPage) });
  const filters = {
    desde: elements.filterFrom.value,
    hasta: elements.filterTo.value,
    proveedor_id: elements.filterProvider.value,
    condicion: elements.filterCondition.value,
    moneda: elements.filterCurrency.value,
    estado: elements.filterStatus.value,
    buscar: elements.filterSearch.value.trim()
  };
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });
  return params;
}

async function loadCatalog() {
  try {
    const response = await apiRequest("/compras/catalogo");
    const providers = responseCollection(response, ["proveedores", "providers", "catalogo.proveedores"]);
    state.currency = String(firstDefined(response, ["data.moneda", "moneda", "data.currency", "currency"], state.currency));
    fillSelect(elements.filterProvider, providers, { placeholder: "Todos los proveedores" });
    markFinanceAccessReady();
  } catch (error) {
    if (Number(error?.status) !== 401) setMessage(elements.message, errorMessage(error, "No se pudo cargar el catálogo de compras."), "error");
  }
}

async function loadPurchases() {
  if (state.loading) return;
  state.loading = true;
  elements.previous.disabled = true;
  elements.next.disabled = true;
  setMessage(elements.message, "Consultando compras...");

  try {
    const response = await apiRequest(`/compras?${filterQuery().toString()}`);
    const records = responseCollection(response, ["compras", "items", "records"]);
    const meta = responseMeta(response);
    state.page = numericValue(firstDefined(meta, ["current_page", "page"], state.page), state.page);
    state.lastPage = numericValue(firstDefined(meta, ["last_page", "pages"], records.length < state.perPage ? state.page : state.page + 1), 1);
    renderSummary(response, records);
    renderRows(records);
    elements.page.textContent = state.lastPage > 1 ? `Página ${state.page} de ${state.lastPage}` : "Página 1";
    setMessage(elements.message, records.length ? `${records.length} compra${records.length === 1 ? "" : "s"} en esta página` : "");
    markFinanceAccessReady();
  } catch (error) {
    renderRows([]);
    setMessage(elements.message, errorMessage(error, "No se pudieron consultar las compras."), "error");
  } finally {
    state.loading = false;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
  }
}

function openDialog() {
  if (elements.dialog.open) return;
  if (typeof elements.dialog.showModal === "function") elements.dialog.showModal();
  else elements.dialog.setAttribute("open", "");
}

function closeDialog() {
  if (typeof elements.dialog.close === "function") elements.dialog.close();
  else elements.dialog.removeAttribute("open");
  elements.voidPanel.hidden = true;
  setMessage(elements.voidMessage);
}

function detailLines(purchase) {
  return responseCollection(purchase, ["detalles", "details", "lineas", "items"]);
}

function renderDetail(purchase) {
  const status = purchaseStatus(purchase);
  const condition = purchaseCondition(purchase);
  const currency = purchaseCurrency(purchase);
  const pending = purchasePending(purchase);
  const total = purchaseTotal(purchase);
  const lines = detailLines(purchase);
  const date = firstDefined(purchase, ["fecha_compra", "fecha_emision", "date"], null);
  const dueDate = firstDefined(purchase, ["fecha_vencimiento", "due_date"], null);
  const paid = Math.max(0, total - pending);
  const payment = firstDefined(purchase, ["pago_inicial", "pago", "initial_payment"], null);

  elements.detailTitle.textContent = `${documentType(purchase)} ${purchaseNumber(purchase)}`;
  elements.detailContent.innerHTML = `
    <div class="fin-purchase-detail-grid">
      <article><span>Proveedor</span><strong>${escapeHtml(providerName(purchase))}</strong></article>
      <article><span>Condición</span><strong><span class="fin-purchase-condition-tag ${conditionClass(condition)}">${conditionLabel(condition)}</span></strong></article>
      <article><span>Fecha de compra</span><strong>${escapeHtml(formatDate(date))}</strong></article>
      <article><span>Vencimiento</span><strong>${escapeHtml(dueDate ? formatDate(dueDate) : "No aplica")}</strong></article>
      <article><span>Estado</span><strong><span class="fin-status-tag ${statusClass(status)}">${escapeHtml(status)}</span></strong></article>
      <article><span>Código interno</span><strong>${escapeHtml(firstDefined(purchase, ["codigo", "code"], `#${firstDefined(purchase, ["id"], "")}`))}</strong></article>
    </div>
    <div class="fin-purchase-detail-totals">
      <div><span>Total</span><strong>${escapeHtml(formatMoney(total, currency))}</strong></div>
      <div><span>Pagado</span><strong>${escapeHtml(formatMoney(paid, currency))}</strong></div>
      <div class="${pending > 0 ? "is-pending" : ""}"><span>Saldo pendiente</span><strong>${escapeHtml(formatMoney(pending, currency))}</strong></div>
    </div>
    <section class="fin-purchase-detail-lines">
      <h3>Productos</h3>
      ${lines.length ? lines.map((line) => {
        const type = firstDefined(line, ["tipo_pollo.nombre", "tipo.nombre", "tipo_pollo", "descripcion"], "Producto");
        const weight = numericValue(firstDefined(line, ["peso_kg", "peso_neto_kg", "weight"], 0));
        const price = numericValue(firstDefined(line, ["precio_kg", "unit_price"], 0));
        const subtotal = numericValue(firstDefined(line, ["subtotal", "importe", "amount"], weight * price));
        const birds = firstDefined(line, ["cantidad_aves", "birds"], null);
        return `<article><div><strong>${escapeHtml(type)}</strong><small>${birds ? `${escapeHtml(birds)} aves · ` : ""}${escapeHtml(weight.toFixed(3))} kg × ${escapeHtml(formatMoney(price, currency))}/kg</small></div><strong>${escapeHtml(formatMoney(subtotal, currency))}</strong></article>`;
      }).join("") : `<p class="fin-message">La compra no tiene líneas visibles.</p>`}
    </section>
    ${condition === "LEGADO" ? `<section class="fin-purchase-detail-note fin-purchase-legacy-note"><strong>Comprobante histórico conservado</strong><p>Este registro fue importado del modelo financiero anterior. Su condición original no se conoce y debe gestionarse desde su comprobante existente.</p></section>` : ""}
    ${firstDefined(purchase, ["observaciones", "notes"], null) ? `<section class="fin-purchase-detail-note"><strong>Observaciones</strong><p>${escapeHtml(firstDefined(purchase, ["observaciones", "notes"], ""))}</p></section>` : ""}
    ${payment ? `<section class="fin-purchase-detail-note"><strong>Pago al contado</strong><p>${escapeHtml(firstDefined(payment, ["metodo_pago.nombre", "metodo", "payment_method.name"], "Pago registrado"))}${firstDefined(payment, ["referencia", "reference"], null) ? ` · ${escapeHtml(firstDefined(payment, ["referencia", "reference"], ""))}` : ""}</p></section>` : ""}
  `;

  const provider = providerId(purchase);
  const movementBase = document.body.dataset.financeMovementUrl || "/finanzas/movimientos/nuevo";
  const balancesBase = document.body.dataset.financeBalancesUrl || "/finanzas/saldos";
  const hasPending = pending > 0 && !["ANULADO", "PAGADO"].includes(status);
  elements.companyPayment.hidden = !hasPending;
  elements.providerCredit.hidden = !hasPending;
  elements.directPayment.hidden = !hasPending;
  elements.companyPayment.href = `${movementBase}?tipo=PAGO_PROVEEDOR&proveedor_id=${encodeURIComponent(provider || "")}`;
  elements.providerCredit.href = `${balancesBase}?proveedor_id=${encodeURIComponent(provider || "")}`;
  elements.directPayment.href = `${movementBase}?tipo=PAGO_DIRECTO&proveedor_id=${encodeURIComponent(provider || "")}`;
  elements.voidStart.hidden = status === "ANULADO" || condition === "LEGADO";
  elements.voidPanel.hidden = true;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage);
}

async function showPurchase(id, successMessage = "") {
  const sequence = ++state.detailSequence;
  state.currentPurchase = null;
  elements.detailTitle.textContent = "Consultando compra...";
  elements.detailContent.innerHTML = "";
  elements.companyPayment.hidden = true;
  elements.providerCredit.hidden = true;
  elements.directPayment.hidden = true;
  elements.voidStart.hidden = true;
  setMessage(elements.detailMessage, "Cargando detalle...");
  openDialog();

  try {
    const response = await apiRequest(`/compras/${encodeURIComponent(id)}`);
    if (sequence !== state.detailSequence) return;
    const purchase = firstDefined(response, ["data", "compra"], response);
    state.currentPurchase = purchase;
    renderDetail(purchase);
    setMessage(elements.detailMessage, successMessage, successMessage ? "success" : "");
    markFinanceAccessReady();
  } catch (error) {
    if (sequence !== state.detailSequence) return;
    elements.detailTitle.textContent = "No se pudo abrir la compra";
    setMessage(elements.detailMessage, errorMessage(error, "No se pudo consultar el detalle."), "error");
  }
}

async function voidPurchase() {
  if (state.voiding || !state.currentPurchase) return;
  const reason = elements.voidReason.value.trim();
  if (reason.length < 3) {
    setMessage(elements.voidMessage, "Describe brevemente el motivo de la anulación.", "error");
    elements.voidReason.focus();
    return;
  }

  const id = firstDefined(state.currentPurchase, ["id", "compra_id"]);
  state.voiding = true;
  elements.voidConfirm.disabled = true;
  elements.voidCancel.disabled = true;
  setMessage(elements.voidMessage, "Anulando compra...");

  try {
    await apiRequest(`/compras/${encodeURIComponent(id)}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    await loadPurchases();
    await showPurchase(id, "Compra anulada correctamente.");
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular la compra."), "error");
  } finally {
    state.voiding = false;
    elements.voidConfirm.disabled = false;
    elements.voidCancel.disabled = false;
  }
}

async function loadAll() {
  await Promise.allSettled([loadCatalog(), loadPurchases()]);
  const requested = new URLSearchParams(window.location.search).get("compra");
  if (requested && !state.queryOpened) {
    state.queryOpened = true;
    await showPurchase(requested);
  }
}

elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  void loadPurchases();
});

elements.clearFilters.addEventListener("click", () => {
  elements.filters.reset();
  state.page = 1;
  void loadPurchases();
});

elements.previous.addEventListener("click", () => {
  if (state.page <= 1) return;
  state.page -= 1;
  void loadPurchases();
});

elements.next.addEventListener("click", () => {
  if (state.page >= state.lastPage) return;
  state.page += 1;
  void loadPurchases();
});

elements.rows.addEventListener("click", (event) => {
  const button = event.target.closest("[data-purchase-detail]");
  if (button) void showPurchase(button.dataset.purchaseDetail);
});

elements.detailClose.addEventListener("click", closeDialog);
elements.dialog.addEventListener("click", (event) => {
  if (event.target === elements.dialog) closeDialog();
});
elements.voidStart.addEventListener("click", () => {
  elements.voidPanel.hidden = false;
  elements.voidReason.focus();
});
elements.voidCancel.addEventListener("click", () => {
  elements.voidPanel.hidden = true;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage);
});
elements.voidConfirm.addEventListener("click", voidPurchase);

initFinanceAccess(loadAll);
void loadAll();
