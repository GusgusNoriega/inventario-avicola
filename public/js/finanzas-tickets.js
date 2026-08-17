import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  firstDefined,
  formatDateTime,
  formatMoney,
  initFinanceAccess,
  markFinanceAccessReady,
  responseCollection,
  responseMeta,
  setMessage
} from "./finanzas-common.js";

const WHOLESALE_TWO_SOURCE_MODULE = "MODULO_DESPACHO_MAYORISTA_2";
const WHOLESALE_TWO_CHICKEN_VARIANTS = Object.freeze([
  { code: "MACHO", label: "Pollo vivo macho", shortLabel: "PV-M", typeCode: "POLLO_VIVO", sex: "MACHO", presentation: null },
  { code: "HEMBRA", label: "Pollo vivo hembra", shortLabel: "PV-H", typeCode: "POLLO_VIVO", sex: "HEMBRA", presentation: null },
  { code: "MACHO_ABIERTO", label: "Macho abierto", shortLabel: "MA", typeCode: "POLLO_PELADO", sex: "MACHO", presentation: "ABIERTO" },
  { code: "MACHO_CERRADO", label: "Macho cerrado", shortLabel: "MC", typeCode: "POLLO_PELADO", sex: "MACHO", presentation: "CERRADO" },
  { code: "HEMBRA_ABIERTA", label: "Hembra abierta", shortLabel: "HA", typeCode: "POLLO_PELADO", sex: "HEMBRA", presentation: "ABIERTA" },
  { code: "HEMBRA_CERRADA", label: "Hembra cerrada", shortLabel: "HC", typeCode: "POLLO_PELADO", sex: "HEMBRA", presentation: "CERRADA" },
  { code: "POLLO_BENEFICIADO", label: "Pollo beneficiado", shortLabel: "PB", typeCode: "POLLO_BENEFICIADO", sex: null, presentation: null },
  { code: "GALLINA_ROJA", label: "Gallina roja", shortLabel: "GR", typeCode: "GALLINA_ROJA", sex: null, presentation: null },
  { code: "GALLINA_DOBLE", label: "Gallina doble", shortLabel: "GD", typeCode: "GALLINA_DOBLE", sex: null, presentation: null },
  { code: "OTROS", label: "Otros", shortLabel: "OT", typeCode: "OTROS", sex: null, presentation: null }
]);
const WHOLESALE_TWO_PRICE_REQUIRED_VARIANTS = new Set([
  "GALLINA_ROJA",
  "GALLINA_DOBLE",
  "OTROS"
]);

const elements = {
  filters: document.getElementById("financeTicketFilters"),
  ticket: document.getElementById("financeTicketNumber"),
  client: document.getElementById("financeTicketClient"),
  clientCombobox: document.getElementById("financeTicketClientCombobox"),
  clientSuggestions: document.getElementById("financeTicketClientSuggestions"),
  status: document.getElementById("financeTicketStatus"),
  from: document.getElementById("financeTicketFrom"),
  until: document.getElementById("financeTicketUntil"),
  clear: document.getElementById("financeTicketClear"),
  message: document.getElementById("financeTicketMessage"),
  rows: document.getElementById("financeTicketRows"),
  previous: document.getElementById("financeTicketPrevious"),
  next: document.getElementById("financeTicketNext"),
  page: document.getElementById("financeTicketPage"),
  bulkOpen: document.getElementById("financeTicketBulkOpen"),
  weighingDialog: document.getElementById("financeTicketWeighingDialog"),
  weighingCard: document.getElementById("financeTicketWeighingCard"),
  weighingTitle: document.getElementById("financeTicketWeighingTitle"),
  weighingDescription: document.getElementById("financeTicketWeighingDescription"),
  weighingGeneralActions: document.getElementById("financeTicketWeighingGeneralActions"),
  weighingSummary: document.getElementById("financeTicketWeighingSummary"),
  weighingCount: document.getElementById("financeTicketWeighingCount"),
  weighingRows: document.getElementById("financeTicketWeighingRows"),
  weighingEditor: document.getElementById("financeTicketWeighingEditor"),
  weighingEditorTitle: document.getElementById("financeTicketWeighingEditorTitle"),
  weighingEditCancelTop: document.getElementById("financeTicketWeighingEditCancelTop"),
  weighingForm: document.getElementById("financeTicketWeighingForm"),
  weighingChickenTypeField: document.getElementById("financeWeighingChickenTypeField"),
  weighingChickenType: document.getElementById("financeWeighingChickenType"),
  weighingChickenConditionField: document.getElementById("financeWeighingChickenConditionField"),
  weighingChickenCondition: document.getElementById("financeWeighingChickenCondition"),
  weighingChickenVariantField: document.getElementById("financeWeighingChickenVariantField"),
  weighingChickenVariant: document.getElementById("financeWeighingChickenVariant"),
  weighingChickenSexField: document.getElementById("financeWeighingChickenSexField"),
  weighingChickenSex: document.getElementById("financeWeighingChickenSex"),
  weighingBirdsPerCage: document.getElementById("financeWeighingBirdsPerCage"),
  weighingCages: document.getElementById("financeWeighingCages"),
  weighingCageType: document.getElementById("financeWeighingCageType"),
  weighingWeightLabel: document.getElementById("financeWeighingWeightLabel"),
  weighingWeight: document.getElementById("financeWeighingWeight"),
  weighingWeightHelp: document.getElementById("financeWeighingWeightHelp"),
  weighingPriceField: document.getElementById("financeWeighingPriceField"),
  weighingPriceLabel: document.getElementById("financeWeighingPriceLabel"),
  weighingPriceCurrency: document.getElementById("financeWeighingPriceCurrency"),
  weighingPrice: document.getElementById("financeWeighingPrice"),
  weighingPriceHelp: document.getElementById("financeWeighingPriceHelp"),
  weighingOriginField: document.getElementById("financeWeighingOriginField"),
  weighingOrigin: document.getElementById("financeWeighingOrigin"),
  weighingOriginHelp: document.getElementById("financeWeighingOriginHelp"),
  weighingSource: document.getElementById("financeWeighingSource"),
  weighingDateTime: document.getElementById("financeWeighingDateTime"),
  weighingReason: document.getElementById("financeWeighingReason"),
  weighingPreview: document.getElementById("financeTicketWeighingPreview"),
  weighingEditorMessage: document.getElementById("financeTicketWeighingEditorMessage"),
  weighingEditCancel: document.getElementById("financeTicketWeighingEditCancel"),
  weighingSave: document.getElementById("financeTicketWeighingSave"),
  weighingMessage: document.getElementById("financeTicketWeighingMessage"),
  priceDialog: document.getElementById("financeTicketPriceDialog"),
  priceForm: document.getElementById("financeTicketPriceForm"),
  priceTitle: document.getElementById("financeTicketPriceTitle"),
  priceDescription: document.getElementById("financeTicketPriceDescription"),
  priceFields: document.getElementById("financeTicketPriceFields"),
  priceMessage: document.getElementById("financeTicketPriceMessage"),
  clientDialog: document.getElementById("financeTicketClientDialog"),
  clientForm: document.getElementById("financeTicketClientForm"),
  clientTitle: document.getElementById("financeTicketClientTitle"),
  clientDescription: document.getElementById("financeTicketClientDescription"),
  clientSearch: document.getElementById("financeTicketClientSearch"),
  clientOptions: document.getElementById("financeTicketClientOptions"),
  clientSelection: document.getElementById("financeTicketClientSelection"),
  clientMessage: document.getElementById("financeTicketClientMessage"),
  clientSave: document.getElementById("financeTicketClientSave"),
  dateTimeDialog: document.getElementById("financeTicketDateTimeDialog"),
  dateTimeForm: document.getElementById("financeTicketDateTimeForm"),
  dateTimeTitle: document.getElementById("financeTicketDateTimeTitle"),
  dateTimeDescription: document.getElementById("financeTicketDateTimeDescription"),
  dateTimeInput: document.getElementById("financeTicketDateTimeInput"),
  dateTimeMessage: document.getElementById("financeTicketDateTimeMessage"),
  dateTimeSave: document.getElementById("financeTicketDateTimeSave"),
  voidDialog: document.getElementById("financeTicketVoidDialog"),
  voidForm: document.getElementById("financeTicketVoidForm"),
  voidDescription: document.getElementById("financeTicketVoidDescription"),
  voidReason: document.getElementById("financeTicketVoidReason"),
  voidMessage: document.getElementById("financeTicketVoidMessage"),
  voidSubmit: document.getElementById("financeTicketVoidSubmit"),
  restoreDialog: document.getElementById("financeTicketRestoreDialog"),
  restoreForm: document.getElementById("financeTicketRestoreForm"),
  restoreDescription: document.getElementById("financeTicketRestoreDescription"),
  restoreMessage: document.getElementById("financeTicketRestoreMessage"),
  restoreSubmit: document.getElementById("financeTicketRestoreSubmit"),
  bulkDialog: document.getElementById("financeTicketBulkDialog"),
  bulkForm: document.getElementById("financeTicketBulkForm"),
  bulkScope: document.getElementById("financeTicketBulkScope"),
  bulkTypes: document.getElementById("financeTicketBulkTypes"),
  bulkAmount: document.getElementById("financeTicketBulkAmount"),
  bulkMessage: document.getElementById("financeTicketBulkMessage")
};

const state = {
  page: 1,
  lastPage: 1,
  total: 0,
  timezone: null,
  records: new Map(),
  appliedFilters: null,
  priceTypes: [],
  clients: [],
  clientsLoaded: false,
  clientsPromise: null,
  filterClientId: null,
  filterClientMatches: [],
  filterClientActiveIndex: -1,
  filterClientOpenRequested: false,
  filterClientPendingMove: 0,
  filterClientRequestGeneration: 0,
  filterClientRequestController: null,
  filterClientDebounceTimer: null,
  filterClientLoading: false,
  editingPriceTicketId: null,
  editingClientTicketId: null,
  editingDateTimeTicketId: null,
  editingWeighingTicketId: null,
  editingWeighingId: null,
  weighingRecord: null,
  weighingTicket: null,
  weighingCatalogs: {
    chicken_types: [],
    cage_types: [],
    origin_trucks: [],
    weight_adjustments: []
  },
  weighingRequestGeneration: 0,
  weighingRequestController: null,
  weighingLoading: false,
  weighingSaving: false,
  voidingTicketId: null,
  restoringTicketId: null,
  selectedClientId: null,
  selectedBulkTypeId: null,
  pendingFilters: null,
  pendingPage: 1,
  requestGeneration: 0,
  requestController: null,
  bulkIdempotencyKey: null,
  bulkAttemptFingerprint: null,
  bulkSaving: false,
  dateTimeSaving: false,
  lifecycleSaving: false,
  loading: false,
  saving: false
};

const canManage = document.body.dataset.canManageTickets === "1";
const canManageStatus = document.body.dataset.canManageTicketStatus === "1";
const FILTER_CLIENT_RESULT_LIMIT = 8;
const FILTER_CLIENT_DEBOUNCE_MS = 180;

function normalizeSearch(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es")
    .trim();
}

function currentFilters() {
  const filters = {
    ticket: elements.ticket.value.trim(),
    estado: elements.status.value,
    desde: elements.from.value,
    hasta: elements.until.value
  };

  if (state.filterClientId !== null) {
    filters.cliente_id = String(state.filterClientId);
  } else {
    filters.cliente = elements.client.value.trim();
  }

  return filters;
}

function activeFilterEntries(filters) {
  return Object.entries(filters).filter(([, value]) => String(value || "").trim() !== "");
}

function validateFilters(filters) {
  const hasRangeValue = Boolean(filters.desde || filters.hasta);
  if (hasRangeValue && (!filters.desde || !filters.hasta)) {
    return "Completa tanto la fecha y hora inicial como la final.";
  }
  if (filters.desde && filters.hasta && Date.parse(filters.hasta) < Date.parse(filters.desde)) {
    return "La fecha y hora final debe ser igual o posterior a la inicial.";
  }
  if (
    !filters.ticket
    && !filters.cliente
    && !filters.cliente_id
    && !hasRangeValue
    && filters.estado !== "ANULADOS"
  ) {
    return "Debes filtrar por número de ticket, cliente, un rango de fecha y hora o seleccionar solo los anulados.";
  }
  return "";
}

function filtersEqual(first, second) {
  if (!first || !second) return false;
  return ["ticket", "cliente", "cliente_id", "desde", "hasta", "estado"]
    .every((key) => String(first[key] || "") === String(second[key] || ""));
}

function updateBulkAvailability() {
  elements.bulkOpen.disabled = !canManage
    || state.loading
    || state.saving
    || state.total < 1
    || state.priceTypes.length < 1
    || String(state.appliedFilters?.estado || "VIGENTES") !== "VIGENTES"
    || !filtersEqual(currentFilters(), state.appliedFilters);
}

function queryFor(filters, page) {
  const params = new URLSearchParams({ page: String(page) });
  activeFilterEntries(filters).forEach(([key, value]) => params.set(key, String(value)));
  return params;
}

function filterSnapshot(filters) {
  const snapshot = {
    ticket: String(filters?.ticket || "").trim(),
    estado: String(filters?.estado || "VIGENTES").toUpperCase(),
    desde: String(filters?.desde || ""),
    hasta: String(filters?.hasta || "")
  };

  const clientId = String(filters?.cliente_id || "").trim();
  if (clientId) {
    snapshot.cliente_id = clientId;
  } else {
    snapshot.cliente = String(filters?.cliente || "").trim();
  }

  return snapshot;
}

function invalidateTicketRequests({ clearPending = true } = {}) {
  state.requestGeneration += 1;
  state.requestController?.abort();
  state.requestController = null;
  state.loading = false;
  if (clearPending) {
    state.pendingFilters = null;
    state.pendingPage = 1;
  }
  updateBulkAvailability();
}

function requestWasCancelled(error, requestId, controller) {
  return requestId !== state.requestGeneration
    || controller.signal.aborted
    || error?.name === "AbortError";
}

function formatUnitPrice(value, currency = "PEN") {
  const number = Number(value || 0);
  try {
    return new Intl.NumberFormat("es-PE", {
      style: "currency",
      currency: currency || "PEN",
      minimumFractionDigits: 2,
      maximumFractionDigits: 4
    }).format(number);
  } catch {
    return `S/ ${number.toFixed(4)}`;
  }
}

function formatTicketDateTime(value) {
  if (!value) return "Sin fecha";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  try {
    return new Intl.DateTimeFormat("es-PE", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: state.timezone || undefined
    }).format(date);
  } catch {
    return formatDateTime(value);
  }
}

function dateTimeLocalValue(value) {
  const match = String(value || "").match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/);
  return match?.[1] || "";
}

function formatCount(value) {
  return new Intl.NumberFormat("es-PE").format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function ticketCustomerName(ticket) {
  return ticket?.client?.name || ticket?.destination?.name || "Sin cliente registrado";
}

function usesWholesaleTwoVariants(ticket) {
  return ticket?.source_module === WHOLESALE_TWO_SOURCE_MODULE
    && ticket?.operation_type === "DESPACHO";
}

function normalizeChickenSex(value) {
  return String(value || "").toUpperCase() === "HEMBRA" ? "HEMBRA" : "MACHO";
}

function wholesaleTwoVariantByCode(value) {
  const code = String(value || "").trim().toUpperCase();
  return WHOLESALE_TWO_CHICKEN_VARIANTS.find((variant) => variant.code === code) || null;
}

function wholesaleTwoVariantForWeighing(weighing) {
  const explicit = wholesaleTwoVariantByCode(weighing?.chicken_variant_code);
  if (explicit) return explicit;

  const typeCode = String(
    weighing?.chicken_type?.code || weighing?.chicken_type_code || ""
  ).toUpperCase();
  const sex = String(weighing?.chicken_sex || "").toUpperCase() || null;
  const presentation = String(weighing?.presentation || "").toUpperCase() || null;

  return WHOLESALE_TWO_CHICKEN_VARIANTS.find((variant) => (
    variant.typeCode === typeCode
      && variant.sex === sex
      && variant.presentation === presentation
  )) || null;
}

function requiresWholesaleTwoTicketPrice(value) {
  return WHOLESALE_TWO_PRICE_REQUIRED_VARIANTS.has(
    String(value || "").trim().toUpperCase()
  );
}

function wholesaleTwoAdjustmentForVariant(value) {
  const code = String(value || "").trim().toUpperCase();
  return (state.weighingCatalogs.weight_adjustments || [])
    .find((adjustment) => String(adjustment?.code || "").toUpperCase() === code) || null;
}

function currentEditingWeighing() {
  return state.weighingTicket?.weighings?.find(
    (weighing) => String(weighing.id) === String(state.editingWeighingId)
  ) || null;
}

function availableWeighingPrices() {
  const detailPrices = Array.isArray(state.weighingTicket?.prices)
    ? state.weighingTicket.prices
    : Object.values(state.weighingTicket?.prices || {});
  const recordPrices = recordForTicket(state.editingWeighingTicketId)?.prices || [];

  return [...detailPrices, ...recordPrices];
}

function selectedWeighingProduct() {
  let code = "";
  if (usesWholesaleTwoVariants(state.weighingTicket)) {
    code = wholesaleTwoVariantByCode(elements.weighingChickenVariant.value)?.typeCode || "";
  } else if (state.weighingTicket?.operation_type === "DEVOLUCION") {
    code = elements.weighingChickenCondition.value === "MUERTO"
      ? "POLLO_MUERTO"
      : "POLLO_VIVO";
  } else {
    code = elements.weighingChickenType.value;
  }

  const normalizedCode = String(code || "").trim().toUpperCase();
  const type = (state.weighingCatalogs.chicken_types || []).find(
    (item) => String(item?.code || "").trim().toUpperCase() === normalizedCode
  );
  return {
    code: normalizedCode,
    name: type?.name || normalizedCode || "producto seleccionado"
  };
}

function weighingPriceForType(typeCode) {
  const normalizedCode = String(typeCode || "").trim().toUpperCase();
  const price = availableWeighingPrices().find(
    (item) => String(item?.chicken_type?.code || "").trim().toUpperCase() === normalizedCode
  );
  if (price?.price_kg !== null && price?.price_kg !== undefined) return price;

  const weighing = currentEditingWeighing();
  if (
    String(weighing?.chicken_type?.code || "").trim().toUpperCase() === normalizedCode
    && weighing?.price_kg !== null
    && weighing?.price_kg !== undefined
  ) {
    return { price_kg: weighing.price_kg, chicken_type: weighing.chicken_type };
  }
  return null;
}

function normalizedWeighingPrice(value) {
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number.toFixed(4) : "";
}

function updateWeighingPriceState() {
  const currentPrice = normalizedWeighingPrice(elements.weighingPrice.value);
  const originalPrice = elements.weighingPrice.dataset.originalPrice || "";
  elements.weighingPriceField.classList.toggle(
    "is-changed",
    currentPrice !== "" && currentPrice !== originalPrice
  );
}

function syncWeighingPriceField({ resetValue = false } = {}) {
  const product = selectedWeighingProduct();
  const price = weighingPriceForType(product.code);
  const originalPrice = normalizedWeighingPrice(price?.price_kg);
  const ticketUsesPrices = availableWeighingPrices().length > 0
    || usesWholesaleTwoVariants(state.weighingTicket)
    || Boolean(state.weighingTicket?.client?.id);

  if (resetValue) {
    elements.weighingPrice.value = originalPrice;
    elements.weighingPrice.dataset.originalPrice = originalPrice;
  }

  elements.weighingPrice.required = Boolean(originalPrice || ticketUsesPrices);
  elements.weighingPriceLabel.innerHTML = `Precio por kg de ${escapeHtml(product.name)} <b>${elements.weighingPrice.required ? "*" : ""}</b>`;
  const currency = recordForTicket(state.editingWeighingTicketId)?.currency || "PEN";
  elements.weighingPriceCurrency.textContent = currency === "PEN" ? "S/" : currency;
  elements.weighingPriceField.classList.toggle("is-missing", !originalPrice && ticketUsesPrices);
  elements.weighingPriceHelp.textContent = originalPrice
    ? "Precio actual del ticket. Si lo cambias, se aplicará a todas sus pesadas de este producto."
    : (ticketUsesPrices
      ? "Este producto aún no tiene precio en el ticket. Asígnalo para poder guardar la pesada."
      : "Puedes asignar un precio manual a este producto si el ticket debe valorizarse.");
  updateWeighingPriceState();
}

function editingWholesaleTwoAdjustmentGrams() {
  const weighing = currentEditingWeighing();
  if (!weighing || !usesWholesaleTwoVariants(state.weighingTicket)) return 0;

  const selectedCode = String(elements.weighingChickenVariant.value || "").toUpperCase();
  const originalCode = wholesaleTwoVariantForWeighing(weighing)?.code || "";
  if (selectedCode === originalCode) {
    return Math.max(0, Number(weighing.adjustment?.additional_grams) || 0);
  }

  return Math.max(
    0,
    Number(wholesaleTwoAdjustmentForVariant(selectedCode)?.additional_grams) || 0
  );
}

function weighingClassificationLabel(weighing, ticket) {
  if (usesWholesaleTwoVariants(ticket)) {
    return wholesaleTwoVariantForWeighing(weighing)?.label || "Sin clasificación";
  }
  if (ticket?.operation_type === "DEVOLUCION") {
    return weighing?.chicken_condition === "MUERTO" ? "Pollo muerto" : "Pollo vivo";
  }
  return normalizeChickenSex(weighing?.chicken_sex) === "HEMBRA" ? "Hembra" : "Macho";
}

function recordForTicket(ticketId) {
  if (
    String(state.editingWeighingTicketId) === String(ticketId)
    && String(state.weighingRecord?.id) === String(ticketId)
  ) {
    return state.weighingRecord;
  }
  return state.records.get(String(ticketId)) || null;
}

function rememberWeighingRecord(record) {
  if (
    !record?.id
    || String(record.id) !== String(state.editingWeighingTicketId)
  ) return;
  state.weighingRecord = {
    ...(state.weighingRecord || {}),
    ...record
  };
}

function operationLabel(record) {
  const operation = String(record.operation_type || "").toUpperCase();
  const channel = String(record.channel || "").toUpperCase();
  const operationText = operation === "DEVOLUCION" ? "Devolución" : "Despacho";
  const channelText = channel === "MINORISTA" ? "minorista" : "mayorista";
  return `${operationText} ${channelText}`;
}

function pricesHtml(record) {
  if (!record.prices?.length) {
    return '<span class="fin-management-muted">Sin precio asignado</span>';
  }

  return `<div class="fin-ticket-prices">${record.prices.map((price) => `
    <span class="fin-ticket-price-line">
      <strong>${escapeHtml(price.chicken_type?.name || "Tipo de pollo")}</strong>
      <small>${escapeHtml(formatUnitPrice(price.price_kg, record.currency))} / kg</small>
    </span>
  `).join("")}</div>`;
}

function statusHtml(record) {
  const status = String(record.status || "SIN ESTADO").toUpperCase();
  const statusClass = `is-${status.toLocaleLowerCase("es")}`;
  const details = [];

  if (status === "ANULADO") {
    if (record.void_reason) details.push(String(record.void_reason));
    if (record.voided_by?.name) details.push(`Por ${record.voided_by.name}`);
    if (record.voided_at) details.push(formatTicketDateTime(record.voided_at));
  }

  return `
    <span class="fin-management-status ${escapeHtml(statusClass)}">${escapeHtml(status)}</span>
    ${details.length ? `<small>${escapeHtml(details.join(" · "))}</small>` : ""}
  `;
}

function actionsHtml(record) {
  const actions = [];

  if (canManage && record.status !== "ANULADO") {
    if (record.can_edit_weighings) {
      actions.push(`<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-edit-ticket-weighings="${record.id}">Editar ticket/pesadas</button>`);
    }
    actions.push(record.can_edit_prices
      ? `<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-prices="${record.id}">Editar precio</button>`
      : '<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" disabled>Sin precios</button>');
    if (record.can_edit_datetime) {
      actions.push(`<button class="fin-btn fin-btn-accent fin-btn-small" type="button" data-edit-date-time="${record.id}">Cambiar fecha/hora</button>`);
    }
    actions.push(record.can_change_client
      ? `<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-change-client="${record.id}">Cambiar cliente</button>`
      : '<button class="fin-btn fin-btn-primary fin-btn-small" type="button" disabled>Sin venta</button>');
  }

  if (canManageStatus && record.can_void) {
    actions.push(`<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-void-ticket="${record.id}">Anular ticket</button>`);
  }
  if (canManageStatus && record.can_restore) {
    actions.push(`<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-restore-ticket="${record.id}">Restablecer ticket</button>`);
  }

  return actions.length
    ? `<div class="fin-management-actions fin-ticket-actions">${actions.join("")}</div>`
    : '<span class="fin-management-muted">Solo consulta</span>';
}

function renderTickets(records) {
  state.records = new Map(records.map((record) => [String(record.id), record]));
  const refreshedWeighingRecord = state.records.get(String(state.editingWeighingTicketId));
  if (refreshedWeighingRecord) rememberWeighingRecord(refreshedWeighingRecord);

  if (!records.length) {
    elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="7">No hay tickets que coincidan con los filtros.</td></tr>';
    return;
  }

  elements.rows.innerHTML = records.map((record) => `
    <tr class="${record.status === "ANULADO" ? "is-voided" : ""}">
      <td>
        <strong>${escapeHtml(record.code || `#${record.id}`)}</strong>
        <small>${escapeHtml(operationLabel(record))}</small>
      </td>
      <td>
        <strong>${escapeHtml(record.client?.name || "Sin cliente registrado")}</strong>
        <small>${escapeHtml(record.client?.document_number || "Sin documento")}</small>
      </td>
      <td>${pricesHtml(record)}</td>
      <td class="fin-text-right">
        <strong class="fin-table-amount ${Number(record.amount) < 0 ? "is-negative" : ""}">
          ${escapeHtml(formatMoney(record.amount, record.currency))}
        </strong>
      </td>
      <td>
        <strong>${escapeHtml(formatTicketDateTime(record.registered_at))}</strong>
      </td>
      <td>${statusHtml(record)}</td>
      <td>${actionsHtml(record)}</td>
    </tr>
  `).join("");
}

function renderWeighingDialogLoading() {
  elements.weighingTitle.textContent = "Editar ticket y pesadas";
  elements.weighingDescription.textContent = "Cargando información completa del ticket...";
  elements.weighingGeneralActions.innerHTML = "";
  elements.weighingSummary.innerHTML = "";
  elements.weighingCount.textContent = "0 pesadas";
  elements.weighingRows.innerHTML = '<tr><td class="fin-empty-cell" colspan="10">Cargando pesadas...</td></tr>';
  elements.weighingEditor.hidden = true;
  setMessage(elements.weighingEditorMessage, "");
  setMessage(elements.weighingMessage, "Cargando pesadas del ticket...");
}

function renderWeighingGeneralActions() {
  const record = recordForTicket(state.editingWeighingTicketId);
  if (!record) {
    elements.weighingGeneralActions.innerHTML = "";
    return;
  }

  const actions = [];
  if (record.can_edit_prices) {
    actions.push('<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-weighing-general-action="prices">Editar precios</button>');
  }
  if (record.can_change_client) {
    actions.push('<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-weighing-general-action="client">Cambiar cliente</button>');
  }
  if (record.can_edit_datetime) {
    actions.push('<button class="fin-btn fin-btn-accent fin-btn-small" type="button" data-weighing-general-action="datetime">Cambiar fecha/hora general</button>');
  }

  elements.weighingGeneralActions.innerHTML = actions.length
    ? `<span>Datos generales:</span>${actions.join("")}`
    : '<span class="fin-management-muted">Este ticket no tiene datos generales editables.</span>';
  const actionsLocked = Boolean(state.editingWeighingId)
    || state.weighingSaving
    || state.weighingLoading;
  elements.weighingGeneralActions.querySelectorAll("button").forEach((button) => {
    button.disabled = actionsLocked;
    if (actionsLocked) {
      button.title = "Guarda o cancela la edición de la pesada antes de cambiar datos generales.";
    }
  });
}

function weighingSummaryItem(label, value, tone = "") {
  return `
    <article class="${escapeHtml(tone)}">
      <span>${escapeHtml(label)}</span>
      <strong>${escapeHtml(value)}</strong>
    </article>
  `;
}

function renderWeighingSummary() {
  const ticket = state.weighingTicket;
  const summary = ticket?.summary || {};
  const record = recordForTicket(state.editingWeighingTicketId);
  const items = [
    weighingSummaryItem("Pesadas", formatCount(summary.weighings)),
    weighingSummaryItem("Javas", formatCount(summary.cages)),
    weighingSummaryItem("Aves", formatCount(summary.birds))
  ];

  if (usesWholesaleTwoVariants(ticket)) {
    items.push(
      weighingSummaryItem("Peso leído", formatWeight(summary.read_weight_kg)),
      weighingSummaryItem("Merma", formatWeight(summary.adjustment_weight_kg))
    );
  }

  items.push(
    weighingSummaryItem("Peso bruto", formatWeight(summary.gross_weight_kg)),
    weighingSummaryItem("Tara", formatWeight(summary.tare_weight_kg)),
    weighingSummaryItem("Peso neto", formatWeight(summary.net_weight_kg), "is-accent")
  );

  const amount = summary.amount ?? record?.amount;
  if (amount !== null && amount !== undefined) {
    items.push(weighingSummaryItem(
      "Monto del ticket",
      formatMoney(amount, record?.currency || "PEN"),
      "is-total"
    ));
  }

  elements.weighingSummary.innerHTML = items.join("");
}

function weighingProductHtml(weighing, ticket) {
  return `
    <div class="fin-ticket-weighing-product">
      <strong>${escapeHtml(weighing.chicken_type?.name || "Sin tipo")}</strong>
      <small>${escapeHtml(weighingClassificationLabel(weighing, ticket))}</small>
    </div>
  `;
}

function renderWeighingRows() {
  const ticket = state.weighingTicket;
  const weighings = Array.isArray(ticket?.weighings) ? ticket.weighings : [];
  elements.weighingCount.textContent = `${formatCount(weighings.length)} pesada${weighings.length === 1 ? "" : "s"}`;

  if (!weighings.length) {
    elements.weighingRows.innerHTML = '<tr><td class="fin-empty-cell" colspan="10">Este ticket no tiene pesadas activas.</td></tr>';
    return;
  }

  elements.weighingRows.innerHTML = weighings.map((weighing) => {
    const selected = String(weighing.id) === String(state.editingWeighingId);
    const canEdit = Boolean(ticket.editable) && !state.weighingSaving && !state.weighingLoading;
    const origin = weighing.origin || "Sin origen";
    const plate = weighing.plate ? ` · ${weighing.plate}` : "";
    const cages = Number(weighing.cages || 0);
    const birdsPerCage = Number(weighing.birds_per_cage || 0);

    return `
      <tr class="${selected ? "is-editing" : ""}">
        <td><strong>#${escapeHtml(weighing.number)}</strong></td>
        <td>${weighingProductHtml(weighing, ticket)}</td>
        <td><strong>${escapeHtml(origin)}</strong><small>${escapeHtml(plate.replace(/^ · /, ""))}</small></td>
        <td><strong>${escapeHtml(weighing.cage_type?.name || "Sin java")}</strong><small>${formatCount(cages)} java${cages === 1 ? "" : "s"}</small></td>
        <td><strong>${formatCount(weighing.birds)}</strong><small>${formatCount(birdsPerCage)} por java</small></td>
        <td class="fin-text-right">${escapeHtml(formatWeight(weighing.gross_weight_kg))}</td>
        <td class="fin-text-right">${escapeHtml(formatWeight(weighing.tare_weight_kg))}</td>
        <td class="fin-text-right"><strong>${escapeHtml(formatWeight(weighing.net_weight_kg))}</strong></td>
        <td>${escapeHtml(formatTicketDateTime(weighing.weighed_at))}</td>
        <td>
          ${canEdit
            ? `<button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-edit-finance-weighing="${weighing.id}">Editar</button>`
            : '<span class="fin-management-muted">Solo consulta</span>'}
        </td>
      </tr>
    `;
  }).join("");
}

function renderWeighingTicketDetail() {
  const ticket = state.weighingTicket;
  if (!ticket) return;

  const record = recordForTicket(state.editingWeighingTicketId);
  const currentDate = record?.registered_at
    ? formatTicketDateTime(record.registered_at).replace(/[.]$/, "")
    : ticket.operating_date || "Sin fecha";
  elements.weighingTitle.textContent = `Editar ticket y pesadas · ${ticket.code}`;
  elements.weighingDescription.textContent = `Cliente: ${ticketCustomerName(ticket)} · ${operationLabel(ticket)} · ${currentDate}.`;
  renderWeighingGeneralActions();
  renderWeighingSummary();
  renderWeighingRows();
}

function weighingRequestWasCancelled(error, requestId, controller) {
  return requestId !== state.weighingRequestGeneration
    || controller.signal.aborted
    || error?.name === "AbortError";
}

async function loadWeighingTicketDetail(
  ticketId,
  { showLoading = true, successMessage = "" } = {}
) {
  state.weighingRequestController?.abort();
  const requestId = state.weighingRequestGeneration + 1;
  const controller = new AbortController();
  state.weighingRequestGeneration = requestId;
  state.weighingRequestController = controller;
  state.weighingLoading = true;
  elements.weighingCard.setAttribute("aria-busy", "true");
  if (showLoading) renderWeighingDialogLoading();

  try {
    const response = await apiRequest(
      `/finanzas/tickets/${encodeURIComponent(ticketId)}/pesadas`,
      { signal: controller.signal }
    );
    if (requestId !== state.weighingRequestGeneration) return { ok: false, stale: true };

    const ticket = response?.data?.ticket;
    if (!ticket) throw new Error("La respuesta no incluyó el detalle del ticket.");

    state.editingWeighingTicketId = ticket.id;
    state.editingWeighingId = null;
    state.weighingTicket = ticket;
    state.weighingCatalogs = response?.data?.catalogs || {
      chicken_types: [],
      cage_types: [],
      origin_trucks: [],
      weight_adjustments: []
    };
    state.weighingLoading = false;
    elements.weighingEditor.hidden = true;
    renderWeighingTicketDetail();
    setMessage(elements.weighingMessage, successMessage, successMessage ? "success" : "");
    return { ok: true, ticket };
  } catch (error) {
    if (weighingRequestWasCancelled(error, requestId, controller)) {
      return { ok: false, aborted: true };
    }
    setMessage(
      elements.weighingMessage,
      errorMessage(error, "No se pudieron cargar las pesadas del ticket."),
      "error"
    );
    return { ok: false, error };
  } finally {
    if (requestId === state.weighingRequestGeneration) {
      if (state.weighingRequestController === controller) {
        state.weighingRequestController = null;
      }
      state.weighingLoading = false;
      elements.weighingCard.setAttribute("aria-busy", "false");
    }
  }
}

function openWeighingTicketDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!canManage || !record?.can_edit_weighings || state.weighingSaving) return;

  state.editingWeighingTicketId = record.id;
  state.editingWeighingId = null;
  state.weighingRecord = record;
  state.weighingTicket = null;
  state.weighingCatalogs = {
    chicken_types: [],
    cage_types: [],
    origin_trucks: [],
    weight_adjustments: []
  };
  renderWeighingDialogLoading();
  elements.weighingDialog.showModal();
  void loadWeighingTicketDetail(record.id);
}

function renderWeighingEditOptions(weighing) {
  const originalVariant = wholesaleTwoVariantForWeighing(weighing);
  const originalRequiresTicketPrice = requiresWholesaleTwoTicketPrice(originalVariant?.code);
  const record = recordForTicket(state.editingWeighingTicketId);
  const detailPrices = Object.values(state.weighingTicket?.prices || {});
  const availablePrices = detailPrices.length ? detailPrices : (record?.prices || []);
  const chickenTypes = [...(state.weighingCatalogs.chicken_types || [])];
  const currentChickenCode = String(weighing.chicken_type?.code || "");
  if (currentChickenCode && !chickenTypes.some((type) => String(type.code) === currentChickenCode)) {
    chickenTypes.unshift({
      code: currentChickenCode,
      name: `${weighing.chicken_type?.name || currentChickenCode} (catálogo histórico)`
    });
  }
  const cageTypes = [...(state.weighingCatalogs.cage_types || [])];
  const currentCageCode = String(weighing.cage_type?.code || "");
  if (currentCageCode && !cageTypes.some((type) => String(type.code) === currentCageCode)) {
    cageTypes.unshift({
      code: currentCageCode,
      name: `${weighing.cage_type?.name || currentCageCode} (catálogo histórico)`,
      weight_kg: weighing.cage_type?.weight_kg
    });
  }
  const pricedTypeCodes = new Set(
    availablePrices
      .map((price) => String(price?.chicken_type?.code || "").trim().toUpperCase())
      .filter(Boolean)
  );

  elements.weighingChickenType.innerHTML = chickenTypes
    .map((type) => {
      const code = String(type?.code || "").trim().toUpperCase();
      const requiresPrice = pricedTypeCodes.size > 0 && !pricedTypeCodes.has(code);
      return `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)}${requiresPrice ? " (requiere asignar precio)" : ""}</option>`;
    })
    .join("");

  elements.weighingChickenVariant.innerHTML = WHOLESALE_TWO_CHICKEN_VARIANTS
    .map((variant) => {
      const unavailablePrice = pricedTypeCodes.size > 0 && !pricedTypeCodes.has(variant.typeCode);
      const disabledBySpecialPrice = originalRequiresTicketPrice
        ? variant.code !== originalVariant?.code
        : requiresWholesaleTwoTicketPrice(variant.code);
      const suffix = unavailablePrice ? " (requiere asignar precio)" : "";
      return `<option value="${escapeHtml(variant.code)}"${disabledBySpecialPrice ? " disabled" : ""}>${escapeHtml(variant.label)}${suffix}</option>`;
    })
    .join("");
  elements.weighingChickenVariant.disabled = originalRequiresTicketPrice;

  elements.weighingCageType.innerHTML = cageTypes
    .map((type) => `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)} (${Number(type.weight_kg).toFixed(3)} kg)</option>`)
    .join("");
  elements.weighingOrigin.innerHTML = `
    <option value="">Mantener el origen actual</option>
    ${(state.weighingCatalogs.origin_trucks || []).map((truck) => `
      <option value="${escapeHtml(truck.program_detail_id)}">${escapeHtml(truck.provider_name)} · ${escapeHtml(truck.plate)}</option>
    `).join("")}
  `;
}

function updateWeighingPreview() {
  const weighing = currentEditingWeighing();
  if (!weighing) {
    elements.weighingPreview.innerHTML = "";
    return;
  }

  const selectedCage = (state.weighingCatalogs.cage_types || [])
    .find((type) => type.code === elements.weighingCageType.value);
  const sameCageType = String(elements.weighingCageType.value)
    === String(weighing.cage_type?.code || "");
  const cageWeight = sameCageType
    ? Number(weighing.cage_type?.weight_kg || selectedCage?.weight_kg || 0)
    : Number(selectedCage?.weight_kg || 0);
  const cages = Math.max(0, Number(elements.weighingCages.value) || 0);
  const birdsPerCage = Math.max(0, Number(elements.weighingBirdsPerCage.value) || 0);
  const enteredWeight = Math.max(0, Number(elements.weighingWeight.value) || 0);
  const tare = cages * cageWeight;
  const wholesaleTwo = usesWholesaleTwoVariants(state.weighingTicket);
  const adjustmentGrams = wholesaleTwo ? editingWholesaleTwoAdjustmentGrams() : 0;
  const birds = birdsPerCage * Math.max(cages, 1);
  const adjustmentWeight = wholesaleTwo ? (adjustmentGrams * birds) / 1000 : 0;
  const gross = enteredWeight + adjustmentWeight;
  const net = gross - tare;
  const price = Number(elements.weighingPrice.value);
  const validPrice = Number.isFinite(price) && price > 0;
  const signedSubtotal = (state.weighingTicket?.operation_type === "DEVOLUCION" ? -1 : 1)
    * Math.max(0, net)
    * (validPrice ? price : 0);
  const currency = recordForTicket(state.editingWeighingTicketId)?.currency || "PEN";
  const priceTone = validPrice ? "is-valid" : (elements.weighingPrice.required ? "is-invalid" : "");
  const pricePreview = `
    <span class="${priceTone}"><small>Precio por kg</small><strong>${validPrice ? escapeHtml(formatUnitPrice(price, currency)) : "Pendiente"}</strong></span>
    <span class="${priceTone}"><small>Subtotal de esta pesada</small><strong>${validPrice && net > 0 ? escapeHtml(formatMoney(signedSubtotal, currency)) : "--"}</strong></span>
  `;

  elements.weighingWeight.setCustomValidity(
    enteredWeight > 0 && net <= 0
      ? "El peso debe ser mayor que la tara total de las javas."
      : ""
  );
  elements.weighingPreview.innerHTML = wholesaleTwo ? `
    <span><small>Aves totales</small><strong>${formatCount(birds)}</strong></span>
    <span><small>Merma aplicada</small><strong>${escapeHtml(formatWeight(adjustmentWeight))}</strong></span>
    <span><small>Peso bruto ajustado</small><strong>${escapeHtml(formatWeight(gross))}</strong></span>
    <span><small>Tara calculada</small><strong>${escapeHtml(formatWeight(tare))}</strong></span>
    <span class="${net <= 0 ? "is-invalid" : "is-valid"}"><small>Peso neto</small><strong>${escapeHtml(formatWeight(net))}</strong></span>
    ${pricePreview}
  ` : `
    <span><small>Aves totales</small><strong>${formatCount(birds)}</strong></span>
    <span><small>Tara calculada</small><strong>${escapeHtml(formatWeight(tare))}</strong></span>
    <span class="${net <= 0 ? "is-invalid" : "is-valid"}"><small>Peso neto</small><strong>${escapeHtml(formatWeight(net))}</strong></span>
    ${pricePreview}
  `;
}

function openWeighingEditor(weighingId) {
  if (
    state.saving
    || state.weighingSaving
    || state.weighingLoading
    || !state.weighingTicket?.editable
  ) return;
  const weighing = state.weighingTicket.weighings?.find(
    (item) => String(item.id) === String(weighingId)
  );
  if (!weighing) {
    setMessage(elements.weighingMessage, "La pesada seleccionada ya no existe.", "error");
    return;
  }

  state.editingWeighingId = weighing.id;
  renderWeighingEditOptions(weighing);
  const isReturn = state.weighingTicket.operation_type === "DEVOLUCION";
  const wholesaleTwo = usesWholesaleTwoVariants(state.weighingTicket);
  elements.weighingChickenTypeField.hidden = isReturn || wholesaleTwo;
  elements.weighingChickenConditionField.hidden = !isReturn;
  elements.weighingChickenVariantField.hidden = !wholesaleTwo;
  elements.weighingChickenSexField.hidden = wholesaleTwo;
  elements.weighingOriginField.hidden = isReturn;
  elements.weighingChickenVariant.required = wholesaleTwo;
  elements.weighingChickenType.value = weighing.chicken_type?.code || "";
  elements.weighingChickenCondition.value = weighing.chicken_condition || "VIVO";
  elements.weighingChickenVariant.value = wholesaleTwoVariantForWeighing(weighing)?.code || "";
  elements.weighingChickenSex.value = normalizeChickenSex(weighing.chicken_sex);
  elements.weighingBirdsPerCage.value = weighing.birds_per_cage;
  elements.weighingCages.value = weighing.cages;
  elements.weighingCageType.value = weighing.cage_type?.code || "";
  elements.weighingWeight.value = Number(
    wholesaleTwo ? weighing.read_weight_kg : weighing.gross_weight_kg
  ).toFixed(3);
  elements.weighingWeightLabel.innerHTML = wholesaleTwo
    ? "Peso leído (kg) <b>*</b>"
    : "Peso bruto (kg) <b>*</b>";
  elements.weighingWeightHelp.textContent = wholesaleTwo
    ? "Lectura original; la merma configurada se calculará automáticamente."
    : "Peso antes de descontar la tara de las javas.";

  const originId = String(weighing.origin_program_detail_id || "");
  const originIsAvailable = (state.weighingCatalogs.origin_trucks || [])
    .some((truck) => String(truck.program_detail_id) === originId);
  elements.weighingOrigin.value = originIsAvailable ? originId : "";
  elements.weighingOriginHelp.textContent = originIsAvailable
    ? "Origen actual preseleccionado. Puedes elegir otro camión de esta jornada."
    : `Origen actual: ${weighing.origin || "sin origen"}${weighing.plate ? ` · ${weighing.plate}` : ""}.`;
  elements.weighingSource.value = [...elements.weighingSource.options]
    .some((option) => option.value === weighing.weight_source)
    ? weighing.weight_source
    : "MANUAL";
  elements.weighingDateTime.value = dateTimeLocalValue(weighing.weighed_at);
  elements.weighingReason.value = "";
  syncWeighingPriceField({ resetValue: true });
  elements.weighingEditorTitle.textContent = `Editar pesada #${weighing.number}`;
  setMessage(elements.weighingEditorMessage, "");
  setMessage(elements.weighingMessage, "");
  elements.weighingEditor.hidden = false;
  renderWeighingGeneralActions();
  renderWeighingRows();
  updateWeighingPreview();
  elements.weighingEditor.scrollIntoView({ behavior: "smooth", block: "start" });
  window.requestAnimationFrame(() => elements.weighingBirdsPerCage.focus());
}

function closeWeighingEditor({ force = false } = {}) {
  if (state.weighingSaving && !force) return;
  state.editingWeighingId = null;
  elements.weighingEditor.hidden = true;
  elements.weighingWeight.setCustomValidity("");
  elements.weighingPriceField.classList.remove("is-missing", "is-changed");
  delete elements.weighingPrice.dataset.originalPrice;
  setMessage(elements.weighingEditorMessage, "");
  if (state.weighingTicket) {
    renderWeighingGeneralActions();
    renderWeighingRows();
  }
}

function setWeighingSaving(isSaving) {
  state.weighingSaving = isSaving;
  elements.weighingCard.setAttribute("aria-busy", String(isSaving));
  elements.weighingForm.querySelectorAll("input, select, textarea").forEach((control) => {
    if (isSaving) {
      control.dataset.weighingWasDisabled = control.disabled ? "1" : "0";
      control.disabled = true;
    } else {
      control.disabled = control.dataset.weighingWasDisabled === "1";
      delete control.dataset.weighingWasDisabled;
    }
  });
  elements.weighingSave.disabled = isSaving;
  elements.weighingEditCancel.disabled = isSaving;
  elements.weighingEditCancelTop.disabled = isSaving;
  elements.weighingSave.textContent = isSaving ? "Guardando pesada..." : "Guardar pesada";
  elements.weighingGeneralActions.querySelectorAll("button").forEach((button) => {
    button.disabled = isSaving;
  });
  elements.weighingRows.querySelectorAll("button").forEach((button) => {
    button.disabled = isSaving;
  });
}

async function saveWeighing(event) {
  event.preventDefault();
  if (
    state.saving
    || state.weighingSaving
    || !state.editingWeighingTicketId
    || !state.editingWeighingId
  ) return;

  elements.weighingReason.value = elements.weighingReason.value.trim();
  updateWeighingPreview();
  if (!elements.weighingForm.checkValidity()) {
    elements.weighingForm.reportValidity();
    setMessage(
      elements.weighingEditorMessage,
      "Revisa los campos obligatorios, el precio, el peso neto y el motivo de la corrección.",
      "error"
    );
    return;
  }

  const weighing = currentEditingWeighing();
  if (!weighing) return;
  const wholesaleTwo = usesWholesaleTwoVariants(state.weighingTicket);
  const selectedVariant = wholesaleTwo
    ? wholesaleTwoVariantByCode(elements.weighingChickenVariant.value)
    : null;
  if (wholesaleTwo && !selectedVariant) {
    setMessage(elements.weighingEditorMessage, "Selecciona una clasificación válida.", "error");
    elements.weighingChickenVariant.focus();
    return;
  }

  const payload = {
    chicken_type_code: selectedVariant?.typeCode
      || elements.weighingChickenType.value
      || weighing.chicken_type?.code,
    chicken_condition: elements.weighingChickenCondition.value,
    cage_type_code: elements.weighingCageType.value,
    weight_source: elements.weighingSource.value,
    birds_per_cage: Number(elements.weighingBirdsPerCage.value),
    cages: Number(elements.weighingCages.value),
    weighed_at: elements.weighingDateTime.value,
    correction_reason: elements.weighingReason.value
  };
  const normalizedPrice = normalizedWeighingPrice(elements.weighingPrice.value);
  const originalPrice = elements.weighingPrice.dataset.originalPrice || "";
  if (normalizedPrice && normalizedPrice !== originalPrice) {
    payload.price_kg = elements.weighingPrice.value;
  }
  if (weighing.updated_at) payload.expected_updated_at = weighing.updated_at;
  if (wholesaleTwo) {
    payload.chicken_variant_code = selectedVariant.code;
    payload.read_weight_kg = Number(elements.weighingWeight.value);
  } else {
    payload.chicken_sex = normalizeChickenSex(elements.weighingChickenSex.value);
    payload.gross_weight_kg = Number(elements.weighingWeight.value);
  }
  if (state.weighingTicket.operation_type !== "DEVOLUCION" && elements.weighingOrigin.value) {
    payload.origin_program_detail_id = Number(elements.weighingOrigin.value);
  }

  state.saving = true;
  setWeighingSaving(true);
  updateBulkAvailability();
  setMessage(elements.weighingEditorMessage, "Guardando la corrección y recalculando el ticket...");
  try {
    const response = await apiRequest(
      `/finanzas/tickets/${encodeURIComponent(state.editingWeighingTicketId)}/pesadas/${encodeURIComponent(state.editingWeighingId)}`,
      {
        method: "PUT",
        body: JSON.stringify(payload)
      }
    );
    state.weighingTicket = response?.data?.ticket || state.weighingTicket;
    closeWeighingEditor({ force: true });
    renderWeighingTicketDetail();
    const successMessage = response?.message || "Pesada actualizada correctamente.";
    setMessage(elements.weighingMessage, successMessage, "success");
    await refreshAfterMutation(successMessage);
  } catch (error) {
    setMessage(
      elements.weighingEditorMessage,
      errorMessage(error, "No se pudo actualizar la pesada."),
      "error"
    );
  } finally {
    state.saving = false;
    setWeighingSaving(false);
    updateBulkAvailability();
    if (state.weighingTicket) renderWeighingTicketDetail();
  }
}

async function loadTickets(
  filters = state.appliedFilters,
  requestedPage = state.page,
  { loadingMessage = "Consultando tickets..." } = {}
) {
  if (!filters) return { ok: false, skipped: true };

  const filtersToApply = filterSnapshot(filters);
  const pageToLoad = Math.max(1, Number(requestedPage) || 1);
  state.requestController?.abort();

  const requestId = state.requestGeneration + 1;
  const controller = new AbortController();
  state.requestGeneration = requestId;
  state.requestController = controller;
  state.pendingFilters = { ...filtersToApply };
  state.pendingPage = pageToLoad;
  state.loading = true;
  updateBulkAvailability();
  setMessage(elements.message, loadingMessage);

  try {
    const response = await apiRequest(
      `/finanzas/tickets?${queryFor(filtersToApply, pageToLoad)}`,
      { signal: controller.signal }
    );
    if (requestId !== state.requestGeneration) {
      return { ok: false, stale: true };
    }

    const records = responseCollection(response, ["tickets", "items", "records"]);
    const meta = responseMeta(response);
    const lastPage = Math.max(1, Number(meta.last_page || 1));

    if (pageToLoad > lastPage) {
      return await loadTickets(filtersToApply, lastPage, { loadingMessage });
    }

    state.appliedFilters = { ...filtersToApply };
    state.page = Math.min(lastPage, Math.max(1, Number(meta.current_page || pageToLoad)));
    state.lastPage = lastPage;
    state.total = Number(meta.total ?? records.length);
    state.timezone = typeof meta.timezone === "string" && meta.timezone
      ? meta.timezone
      : state.timezone;
    state.priceTypes = Array.isArray(meta.price_types) ? meta.price_types : [];

    renderTickets(records);
    elements.page.textContent = `Página ${state.page} de ${state.lastPage} · máximo 30 por página`;
    elements.previous.disabled = state.page <= 1;
    elements.next.disabled = state.page >= state.lastPage;
    setMessage(
      elements.message,
      `${state.total} ticket${state.total === 1 ? "" : "s"} encontrado${state.total === 1 ? "" : "s"}.`
    );
    markFinanceAccessReady();

    return { ok: true, records, meta };
  } catch (error) {
    if (requestWasCancelled(error, requestId, controller)) {
      return { ok: false, aborted: true };
    }
    setMessage(elements.message, errorMessage(error, "No se pudieron consultar los tickets."), "error");
    return { ok: false, error };
  } finally {
    if (requestId === state.requestGeneration) {
      if (state.requestController === controller) state.requestController = null;
      state.loading = false;
      updateBulkAvailability();
    }
  }
}

async function refreshOpenWeighingDialog(successMessage) {
  if (!elements.weighingDialog.open || !state.editingWeighingTicketId) {
    return { ok: true, skipped: true };
  }

  return loadWeighingTicketDetail(state.editingWeighingTicketId, {
    showLoading: false,
    successMessage
  });
}

async function refreshAfterMutation(successMessage) {
  const filters = state.appliedFilters ? { ...state.appliedFilters } : null;
  if (!filters) {
    setMessage(elements.message, successMessage, "success");
    await refreshOpenWeighingDialog(successMessage);
    return { ok: true, skipped: true };
  }

  const result = await loadTickets(filters, state.page, {
    loadingMessage: `${successMessage} Actualizando la lista...`
  });
  if (result.ok) {
    setMessage(elements.message, successMessage, "success");
    await refreshOpenWeighingDialog(successMessage);
  } else if (!result.aborted && !result.stale) {
    setMessage(
      elements.message,
      `${successMessage} Sin embargo, no se pudo actualizar la lista; vuelve a consultar los tickets.`,
      "error"
    );
  }
  return result;
}

function resetResults({ invalidateRequest = true } = {}) {
  if (invalidateRequest) invalidateTicketRequests();
  state.filterClientId = null;
  closeFilterClientSuggestions();
  state.page = 1;
  state.lastPage = 1;
  state.total = 0;
  state.timezone = null;
  state.records.clear();
  state.appliedFilters = null;
  state.priceTypes = [];
  elements.rows.innerHTML = '<tr><td class="fin-empty-cell" colspan="7">Los tickets aparecerán aquí después de aplicar un filtro.</td></tr>';
  elements.page.textContent = "Sin consulta";
  elements.previous.disabled = true;
  elements.next.disabled = true;
  setMessage(elements.message, "Aplica un filtro para mostrar los tickets.");
  updateBulkAvailability();
}

function openPriceDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!record?.can_edit_prices || !record?.prices?.length) return;

  state.editingPriceTicketId = record.id;
  elements.priceTitle.textContent = `Editar precios · ${record.code}`;
  elements.priceDescription.textContent = `Cliente: ${record.client?.name || "Sin cliente"}. El monto del ticket se recalculará al guardar.`;
  elements.priceFields.innerHTML = record.prices.map((price) => `
    <label class="fin-field fin-ticket-price-field">
      <span>${escapeHtml(price.chicken_type?.name || "Tipo de pollo")} <b>*</b></span>
      <span class="fin-ticket-price-input">
        <input
          type="number"
          min="0.0001"
          max="99999999.9999"
          step="0.0001"
          inputmode="decimal"
          value="${escapeHtml(price.price_kg)}"
          data-ticket-price-id="${price.id}"
          required
        >
        <small>por kg</small>
      </span>
    </label>
  `).join("");
  setMessage(elements.priceMessage, "");
  elements.priceDialog.showModal();
  elements.priceFields.querySelector("input")?.focus();
}

async function savePrices(event) {
  event.preventDefault();
  if (state.saving || !state.editingPriceTicketId) return;

  if (!elements.priceForm.checkValidity()) {
    elements.priceForm.reportValidity();
    setMessage(
      elements.priceMessage,
      "Revisa que todos los precios respeten el mínimo, máximo y cuatro decimales permitidos.",
      "error"
    );
    return;
  }

  const inputs = [...elements.priceFields.querySelectorAll("[data-ticket-price-id]")];
  const invalid = inputs.find((input) => {
    const value = Number(input.value);
    return !input.value || !Number.isFinite(value) || value <= 0;
  });
  if (invalid) {
    setMessage(elements.priceMessage, "Todos los precios deben ser mayores que cero.", "error");
    invalid.focus();
    return;
  }

  state.saving = true;
  setMessage(elements.priceMessage, "Guardando precios...");
  try {
    const response = await apiRequest(`/finanzas/tickets/${encodeURIComponent(state.editingPriceTicketId)}/precios`, {
      method: "PUT",
      body: JSON.stringify({
        precios: inputs.map((input) => ({
          id: Number(input.dataset.ticketPriceId),
          precio_kg: input.value
        }))
      })
    });
    rememberWeighingRecord(response?.data);
    elements.priceDialog.close();
    state.editingPriceTicketId = null;
    await refreshAfterMutation("Precios actualizados y monto del ticket recalculado.");
  } catch (error) {
    setMessage(elements.priceMessage, errorMessage(error, "No se pudieron actualizar los precios."), "error");
  } finally {
    state.saving = false;
    updateBulkAvailability();
  }
}

async function loadClients() {
  if (state.clientsLoaded) return state.clients;

  if (!state.clientsPromise) {
    state.clientsPromise = apiRequest("/finanzas/catalogo")
      .then((response) => {
        state.clients = responseCollection(response, ["clientes", "catalogo.clientes"]);
        state.clientsLoaded = true;
        return state.clients;
      })
      .finally(() => {
        state.clientsPromise = null;
      });
  }

  return state.clientsPromise;
}

function clientLabel(client) {
  return String(firstDefined(client, ["nombre", "nombre_razon_social", "name"], "Cliente"));
}

function clientDocument(client) {
  return String(firstDefined(client, ["numero_documento", "document_number"], "") || "");
}

function clientStatus(client) {
  return String(firstDefined(client, ["estado", "status"], "ACTIVO")).toUpperCase();
}

function invalidateFilterClientLookup() {
  state.filterClientRequestGeneration += 1;
  if (state.filterClientDebounceTimer !== null) {
    window.clearTimeout(state.filterClientDebounceTimer);
    state.filterClientDebounceTimer = null;
  }
  state.filterClientRequestController?.abort();
  state.filterClientRequestController = null;
  state.filterClientLoading = false;
}

function closeFilterClientSuggestions() {
  invalidateFilterClientLookup();
  state.filterClientOpenRequested = false;
  state.filterClientActiveIndex = -1;
  state.filterClientPendingMove = 0;
  state.filterClientMatches = [];
  elements.clientSuggestions.hidden = true;
  elements.clientSuggestions.setAttribute("aria-busy", "false");
  elements.client.setAttribute("aria-expanded", "false");
  elements.client.removeAttribute("aria-activedescendant");
}

function filterClientRequestWasCancelled(error, requestId, controller) {
  return requestId !== state.filterClientRequestGeneration
    || controller.signal.aborted
    || error?.name === "AbortError";
}

function syncFilterClientActiveOption() {
  const options = [...elements.clientSuggestions.querySelectorAll("[data-filter-client-index]")];

  options.forEach((option, index) => {
    option.classList.toggle("is-active", index === state.filterClientActiveIndex);
  });

  const active = options[state.filterClientActiveIndex];
  if (active) {
    elements.client.setAttribute("aria-activedescendant", active.id);
    active.scrollIntoView({ block: "nearest" });
  } else {
    elements.client.removeAttribute("aria-activedescendant");
  }
}

function setFilterClientActiveIndex(index) {
  if (!state.filterClientMatches.length) {
    state.filterClientActiveIndex = -1;
  } else {
    state.filterClientActiveIndex = Math.max(
      0,
      Math.min(index, state.filterClientMatches.length - 1),
    );
  }
  syncFilterClientActiveOption();
}

function renderFilterClientSuggestions({ resetActive = true } = {}) {
  if (!state.filterClientOpenRequested) return;

  if (resetActive) state.filterClientActiveIndex = -1;
  if (state.filterClientActiveIndex >= state.filterClientMatches.length) {
    state.filterClientActiveIndex = -1;
  }

  elements.clientSuggestions.innerHTML = state.filterClientMatches.length
    ? state.filterClientMatches.map((client, index) => {
      const selected = String(client.id) === String(state.filterClientId);
      const inactiveBadge = clientStatus(client) === "ACTIVO"
        ? ""
        : '<span class="fin-ticket-client-suggestion-status">Inactivo</span>';
      return `
        <button
          id="financeTicketClientSuggestion${index}"
          class="fin-ticket-client-suggestion"
          type="button"
          role="option"
          tabindex="-1"
          data-filter-client-index="${index}"
          aria-selected="${selected}"
        >
          <strong>${escapeHtml(clientLabel(client))}</strong>
          <span class="fin-ticket-client-suggestion-meta">
            <small>${escapeHtml(clientDocument(client) || "Sin documento")}</small>
            ${inactiveBadge}
          </span>
        </button>
      `;
    }).join("")
    : '<span class="fin-ticket-client-suggestion-empty" role="status">No hay clientes que coincidan.</span>';

  elements.clientSuggestions.hidden = false;
  elements.clientSuggestions.setAttribute("aria-busy", "false");
  elements.client.setAttribute("aria-expanded", "true");

  if (state.filterClientPendingMove !== 0 && state.filterClientMatches.length) {
    const pendingMove = state.filterClientPendingMove;
    state.filterClientPendingMove = 0;
    setFilterClientActiveIndex(pendingMove > 0 ? 0 : state.filterClientMatches.length - 1);
    return;
  }

  state.filterClientPendingMove = 0;
  syncFilterClientActiveOption();
}

function renderFilterClientLoading(message = "Buscando clientes...") {
  elements.clientSuggestions.innerHTML = `<span class="fin-ticket-client-suggestion-empty" role="status">${escapeHtml(message)}</span>`;
  elements.clientSuggestions.hidden = false;
  elements.clientSuggestions.setAttribute("aria-busy", "true");
  elements.client.setAttribute("aria-expanded", "true");
  elements.client.removeAttribute("aria-activedescendant");
}

async function fetchFilterClientSuggestions(query, requestId, resetActive) {
  if (
    requestId !== state.filterClientRequestGeneration
    || !state.filterClientOpenRequested
  ) return;

  const controller = new AbortController();
  state.filterClientRequestController = controller;
  try {
    const params = new URLSearchParams({ buscar: query });
    const response = await apiRequest(`/finanzas/tickets/clientes?${params}`, {
      signal: controller.signal
    });
    if (
      requestId === state.filterClientRequestGeneration
      && state.filterClientOpenRequested
      && document.activeElement === elements.client
    ) {
      state.filterClientMatches = responseCollection(
        response,
        ["clientes", "items", "records"],
      )
        .filter((client) => client?.id !== undefined && client?.id !== null)
        .slice(0, FILTER_CLIENT_RESULT_LIMIT);
      state.filterClientLoading = false;
      renderFilterClientSuggestions({ resetActive });
    }
  } catch (error) {
    if (filterClientRequestWasCancelled(error, requestId, controller)) return;
    if (
      requestId !== state.filterClientRequestGeneration
      || !state.filterClientOpenRequested
    ) return;
    state.filterClientMatches = [];
    state.filterClientActiveIndex = -1;
    elements.clientSuggestions.innerHTML = '<span class="fin-ticket-client-suggestion-empty is-error" role="status">No se pudieron buscar los clientes. Intenta nuevamente.</span>';
    elements.clientSuggestions.setAttribute("aria-busy", "false");
  } finally {
    if (
      requestId === state.filterClientRequestGeneration
      && state.filterClientRequestController === controller
    ) {
      state.filterClientRequestController = null;
      state.filterClientLoading = false;
    }
  }
}

function openFilterClientSuggestions({
  resetActive = true,
  debounce = false
} = {}) {
  invalidateFilterClientLookup();
  const requestId = state.filterClientRequestGeneration;
  const query = elements.client.value.trim();
  state.filterClientOpenRequested = true;
  state.filterClientLoading = true;
  state.filterClientMatches = [];
  if (resetActive) state.filterClientActiveIndex = -1;

  renderFilterClientLoading(debounce ? "Buscando clientes..." : "Cargando clientes...");

  if (debounce) {
    state.filterClientDebounceTimer = window.setTimeout(() => {
      state.filterClientDebounceTimer = null;
      void fetchFilterClientSuggestions(query, requestId, resetActive);
    }, FILTER_CLIENT_DEBOUNCE_MS);
    return;
  }

  void fetchFilterClientSuggestions(query, requestId, resetActive);
}

function selectFilterClient(index) {
  const client = state.filterClientMatches[index];
  if (!client) return;

  state.filterClientId = String(client.id);
  elements.client.value = clientLabel(client);
  closeFilterClientSuggestions();
  updateBulkAvailability();
}

function selectedClient() {
  return state.clients.find((client) => String(client.id) === String(state.selectedClientId));
}

function renderClientOptions() {
  const query = normalizeSearch(elements.clientSearch.value);
  const clients = state.clients.filter((client) => {
    const haystack = normalizeSearch(`${clientLabel(client)} ${clientDocument(client)}`);
    return !query || haystack.includes(query);
  });

  elements.clientOptions.innerHTML = clients.length
    ? clients.map((client) => {
      const selected = String(client.id) === String(state.selectedClientId);
      return `
        <label
          class="fin-ticket-client-option ${selected ? "is-selected" : ""}"
        >
          <input
            class="fin-sr-only"
            type="radio"
            name="finance-ticket-client-choice"
            value="${escapeHtml(client.id)}"
            data-client-id="${escapeHtml(client.id)}"
            ${selected ? "checked" : ""}
          >
          <strong>${escapeHtml(clientLabel(client))}</strong>
          <span>${escapeHtml(clientDocument(client) || "Sin documento")}</span>
        </label>
      `;
    }).join("")
    : '<p class="fin-ticket-option-empty">No hay clientes que coincidan con la búsqueda.</p>';
}

function syncClientOptionState() {
  elements.clientOptions.querySelectorAll("[data-client-id]").forEach((input) => {
    const selected = String(input.dataset.clientId) === String(state.selectedClientId);
    input.checked = selected;
    input.closest(".fin-ticket-client-option")?.classList.toggle("is-selected", selected);
  });
}

function updateClientSelection() {
  const client = selectedClient();
  elements.clientSelection.textContent = client
    ? `Seleccionado: ${clientLabel(client)} · ${clientDocument(client) || "sin documento"}`
    : "Selecciona un cliente.";
  elements.clientSave.disabled = !client || state.saving;
}

async function openClientDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!record?.can_change_client) return;

  state.editingClientTicketId = record.id;
  state.selectedClientId = record.client?.id || null;
  elements.clientTitle.textContent = `Cambiar cliente · ${record.code}`;
  elements.clientDescription.textContent = `Cliente actual: ${record.client?.name || "Sin cliente registrado"}.`;
  elements.clientSearch.value = "";
  elements.clientOptions.innerHTML = '<p class="fin-ticket-option-empty">Cargando clientes...</p>';
  setMessage(elements.clientMessage, "");
  updateClientSelection();
  elements.clientDialog.showModal();

  try {
    await loadClients();
    renderClientOptions();
    updateClientSelection();
    elements.clientSearch.focus();
  } catch (error) {
    setMessage(elements.clientMessage, errorMessage(error, "No se pudieron cargar los clientes."), "error");
  }
}

async function saveClient(event) {
  event.preventDefault();
  if (state.saving || !state.editingClientTicketId || !state.selectedClientId) return;

  state.saving = true;
  updateClientSelection();
  setMessage(elements.clientMessage, "Guardando cliente...");
  try {
    const response = await apiRequest(`/finanzas/tickets/${encodeURIComponent(state.editingClientTicketId)}/cliente`, {
      method: "PUT",
      body: JSON.stringify({ cliente_id: Number(state.selectedClientId) })
    });
    rememberWeighingRecord(response?.data);
    elements.clientDialog.close();
    state.editingClientTicketId = null;
    state.selectedClientId = null;
    await refreshAfterMutation("Cliente del ticket actualizado correctamente.");
  } catch (error) {
    setMessage(elements.clientMessage, errorMessage(error, "No se pudo cambiar el cliente."), "error");
  } finally {
    state.saving = false;
    updateClientSelection();
    updateBulkAvailability();
  }
}

function setDateTimeSaving(isSaving) {
  state.dateTimeSaving = isSaving;
  elements.dateTimeForm.setAttribute("aria-busy", String(isSaving));
  elements.dateTimeInput.disabled = isSaving;
  elements.dateTimeSave.disabled = isSaving;
  elements.dateTimeSave.textContent = isSaving ? "Guardando..." : "Guardar fecha y hora";
}

function openDateTimeDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!canManage || !record?.can_edit_datetime || state.dateTimeSaving) return;

  state.editingDateTimeTicketId = record.id;
  const weighingCount = Number(record.weighing_count || 0);
  const currentDateTime = formatTicketDateTime(record.registered_at).replace(/[.]$/, "");
  elements.dateTimeTitle.textContent = `Cambiar fecha y hora · ${record.code}`;
  elements.dateTimeDescription.textContent = weighingCount > 0
    ? `Fecha actual: ${currentDateTime}. Se actualizarán automáticamente ${weighingCount} pesada${weighingCount === 1 ? "" : "s"}.`
    : `Fecha actual: ${currentDateTime}. Este ticket no tiene pesadas asociadas.`;
  elements.dateTimeInput.value = dateTimeLocalValue(record.registered_at);
  setMessage(elements.dateTimeMessage, "");
  elements.dateTimeDialog.showModal();
  elements.dateTimeInput.focus();
}

async function saveDateTime(event) {
  event.preventDefault();
  if (state.saving || state.dateTimeSaving || !state.editingDateTimeTicketId) return;

  if (!elements.dateTimeForm.checkValidity()) {
    elements.dateTimeForm.reportValidity();
    setMessage(elements.dateTimeMessage, "Indica una fecha y hora válidas.", "error");
    return;
  }

  state.saving = true;
  setDateTimeSaving(true);
  updateBulkAvailability();
  setMessage(elements.dateTimeMessage, "Actualizando el ticket y todas sus pesadas...");
  try {
    const response = await apiRequest(
      `/finanzas/tickets/${encodeURIComponent(state.editingDateTimeTicketId)}/fecha-hora`,
      {
        method: "PUT",
        body: JSON.stringify({ fecha_hora: elements.dateTimeInput.value })
      }
    );
    rememberWeighingRecord(response?.data);
    elements.dateTimeDialog.close();
    state.editingDateTimeTicketId = null;
    await refreshAfterMutation(
      response?.message || "Fecha y hora del ticket y sus pesadas actualizadas correctamente."
    );
  } catch (error) {
    setMessage(
      elements.dateTimeMessage,
      errorMessage(error, "No se pudo cambiar la fecha y hora del ticket."),
      "error"
    );
  } finally {
    state.saving = false;
    setDateTimeSaving(false);
    updateBulkAvailability();
  }
}

function setLifecycleSaving(isSaving) {
  state.lifecycleSaving = isSaving;
  elements.voidForm.setAttribute("aria-busy", String(isSaving));
  elements.restoreForm.setAttribute("aria-busy", String(isSaving));
  elements.voidReason.disabled = isSaving;
  elements.voidSubmit.disabled = isSaving;
  elements.restoreSubmit.disabled = isSaving;
  elements.voidSubmit.textContent = isSaving ? "Anulando..." : "Sí, anular ticket";
  elements.restoreSubmit.textContent = isSaving ? "Restableciendo..." : "Sí, restablecer ticket";
}

function openVoidDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!canManageStatus || !record?.can_void || state.lifecycleSaving) return;

  state.voidingTicketId = record.id;
  elements.voidDescription.textContent = `Vas a anular el ticket ${record.code}. El registro se conservará para auditoría.`;
  elements.voidReason.value = "";
  setMessage(elements.voidMessage, "");
  elements.voidDialog.showModal();
  elements.voidReason.focus();
}

async function voidTicket(event) {
  event.preventDefault();
  if (state.lifecycleSaving || !state.voidingTicketId) return;

  const reason = elements.voidReason.value.trim();
  elements.voidReason.value = reason;
  if (!elements.voidForm.checkValidity()) {
    elements.voidForm.reportValidity();
    setMessage(elements.voidMessage, "Escribe un motivo de al menos 3 caracteres.", "error");
    return;
  }

  state.saving = true;
  setLifecycleSaving(true);
  updateBulkAvailability();
  setMessage(elements.voidMessage, "Anulando el ticket y actualizando sus efectos...");
  try {
    const response = await apiRequest(
      `/finanzas/tickets/${encodeURIComponent(state.voidingTicketId)}/anular`,
      {
        method: "POST",
        body: JSON.stringify({ motivo: reason })
      }
    );
    elements.voidDialog.close();
    state.voidingTicketId = null;
    await refreshAfterMutation(response?.message || "Ticket anulado correctamente.");
  } catch (error) {
    setMessage(elements.voidMessage, errorMessage(error, "No se pudo anular el ticket."), "error");
  } finally {
    state.saving = false;
    setLifecycleSaving(false);
    updateBulkAvailability();
  }
}

function openRestoreDialog(ticketId) {
  const record = state.records.get(String(ticketId));
  if (!canManageStatus || !record?.can_restore || state.lifecycleSaving) return;

  state.restoringTicketId = record.id;
  const reason = record.void_reason ? ` Motivo de anulación: ${record.void_reason}.` : "";
  elements.restoreDescription.textContent = `Vas a restablecer el ticket ${record.code}.${reason}`;
  setMessage(elements.restoreMessage, "");
  elements.restoreDialog.showModal();
  elements.restoreSubmit.focus();
}

async function restoreTicket(event) {
  event.preventDefault();
  if (state.lifecycleSaving || !state.restoringTicketId) return;

  state.saving = true;
  setLifecycleSaving(true);
  updateBulkAvailability();
  setMessage(elements.restoreMessage, "Restableciendo el ticket y actualizando sus efectos...");
  try {
    const response = await apiRequest(
      `/finanzas/tickets/${encodeURIComponent(state.restoringTicketId)}/restablecer`,
      { method: "POST" }
    );
    elements.restoreDialog.close();
    state.restoringTicketId = null;
    await refreshAfterMutation(response?.message || "Ticket restablecido correctamente.");
  } catch (error) {
    setMessage(elements.restoreMessage, errorMessage(error, "No se pudo restablecer el ticket."), "error");
  } finally {
    state.saving = false;
    setLifecycleSaving(false);
    updateBulkAvailability();
  }
}

function renderBulkTypes() {
  elements.bulkTypes.innerHTML = state.priceTypes.map((type) => {
    const selected = String(type.id) === String(state.selectedBulkTypeId);
    return `
      <label
        class="fin-ticket-type-option ${selected ? "is-selected" : ""}"
      >
        <input
          class="fin-sr-only"
          type="radio"
          name="finance-ticket-bulk-type"
          value="${escapeHtml(type.id)}"
          data-bulk-type-id="${escapeHtml(type.id)}"
          ${selected ? "checked" : ""}
        >
        <strong>${escapeHtml(type.name)}</strong>
        <small>${Number(type.ticket_count)} ticket${Number(type.ticket_count) === 1 ? "" : "s"}</small>
      </label>
    `;
  }).join("");
}

function syncBulkTypeSelection() {
  elements.bulkTypes.querySelectorAll("[data-bulk-type-id]").forEach((input) => {
    const selected = String(input.dataset.bulkTypeId) === String(state.selectedBulkTypeId);
    input.checked = selected;
    input.closest(".fin-ticket-type-option")?.classList.toggle("is-selected", selected);
  });
}

function setBulkSaving(isSaving) {
  state.bulkSaving = isSaving;
  elements.bulkForm.setAttribute("aria-busy", String(isSaving));
  elements.bulkAmount.disabled = isSaving;
  elements.bulkForm.querySelectorAll(
    "[data-bulk-type-id], [data-bulk-operation], [data-bulk-dialog-close]"
  ).forEach((control) => {
    control.disabled = isSaving;
  });
}

function bulkAttemptFingerprint(operation, amount) {
  return JSON.stringify({
    filters: filterSnapshot(state.appliedFilters),
    tipo_pollo_id: Number(state.selectedBulkTypeId),
    monto: String(amount),
    operacion: operation
  });
}

function idempotencyKeyForBulkAttempt(fingerprint) {
  if (
    !state.bulkIdempotencyKey
    || (state.bulkAttemptFingerprint && state.bulkAttemptFingerprint !== fingerprint)
  ) {
    state.bulkIdempotencyKey = createIdempotencyKey();
  }
  state.bulkAttemptFingerprint = fingerprint;
  return state.bulkIdempotencyKey;
}

function openBulkDialog() {
  if (elements.bulkOpen.disabled || !state.appliedFilters) return;

  state.selectedBulkTypeId = null;
  elements.bulkAmount.value = "";
  elements.bulkScope.textContent = `El filtro actual contiene ${state.total} ticket${state.total === 1 ? "" : "s"} en ${state.lastPage} página${state.lastPage === 1 ? "" : "s"}.`;
  setMessage(elements.bulkMessage, "");
  renderBulkTypes();
  elements.bulkDialog.showModal();
  window.requestAnimationFrame(() => {
    elements.bulkTypes.querySelector("[data-bulk-type-id]")?.focus();
  });
}

async function adjustBulkPrices(operation) {
  if (state.saving || !state.appliedFilters) return;
  if (!["AUMENTAR", "DISMINUIR"].includes(operation)) return;
  if (!state.selectedBulkTypeId) {
    setMessage(elements.bulkMessage, "Selecciona primero el tipo de pollo.", "error");
    return;
  }

  const amount = elements.bulkAmount.value;
  const numericAmount = Number(amount);
  if (
    !elements.bulkAmount.checkValidity()
    || !amount
    || !Number.isFinite(numericAmount)
    || numericAmount <= 0
  ) {
    elements.bulkAmount.reportValidity();
    setMessage(
      elements.bulkMessage,
      "Ingresa un monto válido, mayor que cero y con hasta cuatro decimales.",
      "error"
    );
    elements.bulkAmount.focus();
    return;
  }
  const normalizedAmount = numericAmount.toFixed(4);

  const type = state.priceTypes.find((item) => String(item.id) === String(state.selectedBulkTypeId));
  const verb = operation === "AUMENTAR" ? "Aumentando" : "Disminuyendo";
  const fingerprint = bulkAttemptFingerprint(operation, normalizedAmount);
  const idempotencyKey = idempotencyKeyForBulkAttempt(fingerprint);
  state.saving = true;
  setBulkSaving(true);
  updateBulkAvailability();
  setMessage(elements.bulkMessage, `${verb} el precio de ${type?.name || "ese tipo"}...`);

  try {
    const response = await apiRequest("/finanzas/tickets/ajustar-precios", {
      method: "POST",
      body: JSON.stringify({
        ...state.appliedFilters,
        tipo_pollo_id: Number(state.selectedBulkTypeId),
        monto: normalizedAmount,
        operacion: operation,
        idempotency_key: idempotencyKey
      })
    });
    const result = response?.data || {};
    state.bulkIdempotencyKey = null;
    state.bulkAttemptFingerprint = null;
    elements.bulkDialog.close();
    const skipped = Number(result.tickets_without_type || 0);
    const suffix = skipped > 0
      ? ` ${skipped} ticket${skipped === 1 ? "" : "s"} no tenía${skipped === 1 ? "" : "n"} ese tipo y no se modificó${skipped === 1 ? "" : "n"}.`
      : "";
    await refreshAfterMutation(
      `${Number(result.updated_tickets || 0)} ticket${Number(result.updated_tickets) === 1 ? "" : "s"} actualizado${Number(result.updated_tickets) === 1 ? "" : "s"} en todas las páginas.${suffix}`,
    );
  } catch (error) {
    setMessage(elements.bulkMessage, errorMessage(error, "No se pudo aplicar el ajuste masivo."), "error");
  } finally {
    state.saving = false;
    setBulkSaving(false);
    updateBulkAvailability();
  }
}

elements.filters.addEventListener("submit", (event) => {
  event.preventDefault();
  closeFilterClientSuggestions();
  const filters = currentFilters();
  const validationMessage = validateFilters(filters);
  if (validationMessage) {
    setMessage(elements.message, validationMessage, "error");
    return;
  }
  void loadTickets(filters, 1);
});

elements.filters.addEventListener("input", updateBulkAvailability);
elements.status.addEventListener("change", updateBulkAvailability);
elements.client.addEventListener("focus", () => {
  void openFilterClientSuggestions();
});
elements.client.addEventListener("input", () => {
  state.filterClientId = null;
  state.filterClientPendingMove = 0;
  openFilterClientSuggestions({ debounce: true });
});
elements.client.addEventListener("keydown", (event) => {
  if (event.key === "ArrowDown" || event.key === "ArrowUp") {
    event.preventDefault();
    const direction = event.key === "ArrowDown" ? 1 : -1;

    if (elements.clientSuggestions.hidden) {
      state.filterClientPendingMove = direction;
      openFilterClientSuggestions({ resetActive: true });
      return;
    }

    if (!state.filterClientMatches.length) {
      state.filterClientPendingMove = direction;
      if (!state.filterClientLoading) {
        openFilterClientSuggestions({ resetActive: true });
      }
      return;
    }

    const nextIndex = state.filterClientActiveIndex < 0
      ? (direction > 0 ? 0 : state.filterClientMatches.length - 1)
      : state.filterClientActiveIndex + direction;
    setFilterClientActiveIndex(nextIndex);
    return;
  }

  if (event.key === "Enter" && state.filterClientActiveIndex >= 0) {
    event.preventDefault();
    selectFilterClient(state.filterClientActiveIndex);
    return;
  }

  if (event.key === "Escape" && !elements.clientSuggestions.hidden) {
    event.preventDefault();
    closeFilterClientSuggestions();
  }
});
elements.client.addEventListener("blur", () => {
  window.setTimeout(() => {
    if (!elements.clientCombobox.contains(document.activeElement)) {
      closeFilterClientSuggestions();
    }
  }, 0);
});
elements.clientSuggestions.addEventListener("pointerdown", (event) => {
  if (event.target.closest("[data-filter-client-index]")) event.preventDefault();
});
elements.clientSuggestions.addEventListener("pointermove", (event) => {
  const option = event.target.closest("[data-filter-client-index]");
  if (!option) return;
  setFilterClientActiveIndex(Number(option.dataset.filterClientIndex));
});
elements.clientSuggestions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-filter-client-index]");
  if (!option) return;
  selectFilterClient(Number(option.dataset.filterClientIndex));
});
document.addEventListener("pointerdown", (event) => {
  if (!elements.clientCombobox.contains(event.target)) closeFilterClientSuggestions();
});
elements.clear.addEventListener("click", () => {
  elements.filters.reset();
  resetResults();
});
elements.previous.addEventListener("click", () => {
  if (!state.appliedFilters || state.page <= 1) return;
  void loadTickets(state.appliedFilters, state.page - 1);
});
elements.next.addEventListener("click", () => {
  if (!state.appliedFilters || state.page >= state.lastPage) return;
  void loadTickets(state.appliedFilters, state.page + 1);
});
elements.rows.addEventListener("click", (event) => {
  const editWeighings = event.target.closest("[data-edit-ticket-weighings]");
  const editPrices = event.target.closest("[data-edit-prices]");
  const editDateTime = event.target.closest("[data-edit-date-time]");
  const changeClient = event.target.closest("[data-change-client]");
  const voidButton = event.target.closest("[data-void-ticket]");
  const restoreButton = event.target.closest("[data-restore-ticket]");
  if (editWeighings) openWeighingTicketDialog(editWeighings.dataset.editTicketWeighings);
  if (editPrices) openPriceDialog(editPrices.dataset.editPrices);
  if (editDateTime) openDateTimeDialog(editDateTime.dataset.editDateTime);
  if (changeClient) void openClientDialog(changeClient.dataset.changeClient);
  if (voidButton) openVoidDialog(voidButton.dataset.voidTicket);
  if (restoreButton) openRestoreDialog(restoreButton.dataset.restoreTicket);
});
elements.weighingGeneralActions.addEventListener("click", (event) => {
  const button = event.target.closest("[data-weighing-general-action]");
  if (
    !button
    || state.saving
    || state.weighingSaving
    || !state.editingWeighingTicketId
  ) return;
  const ticketId = state.editingWeighingTicketId;
  if (button.dataset.weighingGeneralAction === "prices") openPriceDialog(ticketId);
  if (button.dataset.weighingGeneralAction === "client") void openClientDialog(ticketId);
  if (button.dataset.weighingGeneralAction === "datetime") openDateTimeDialog(ticketId);
});
elements.weighingRows.addEventListener("click", (event) => {
  const button = event.target.closest("[data-edit-finance-weighing]");
  if (button) openWeighingEditor(button.dataset.editFinanceWeighing);
});
elements.weighingForm.addEventListener("submit", saveWeighing);
elements.weighingEditCancel.addEventListener("click", () => closeWeighingEditor());
elements.weighingEditCancelTop.addEventListener("click", () => closeWeighingEditor());
[
  elements.weighingChickenType,
  elements.weighingChickenCondition,
  elements.weighingChickenVariant
].forEach((control) => {
  control.addEventListener("change", () => {
    syncWeighingPriceField({ resetValue: true });
    updateWeighingPreview();
  });
});
elements.weighingPrice.addEventListener("input", () => {
  updateWeighingPriceState();
  updateWeighingPreview();
});
[
  elements.weighingChickenVariant,
  elements.weighingBirdsPerCage,
  elements.weighingCages,
  elements.weighingCageType,
  elements.weighingWeight
].forEach((control) => {
  control.addEventListener("input", updateWeighingPreview);
  control.addEventListener("change", updateWeighingPreview);
});
elements.priceForm.addEventListener("submit", savePrices);
elements.clientForm.addEventListener("submit", saveClient);
elements.dateTimeForm.addEventListener("submit", saveDateTime);
elements.voidForm.addEventListener("submit", voidTicket);
elements.restoreForm.addEventListener("submit", restoreTicket);
elements.clientSearch.addEventListener("input", renderClientOptions);
elements.clientOptions.addEventListener("change", (event) => {
  const option = event.target.closest("[data-client-id]");
  if (!option) return;
  state.selectedClientId = Number(option.dataset.clientId);
  syncClientOptionState();
  updateClientSelection();
});
elements.bulkOpen.addEventListener("click", openBulkDialog);
elements.bulkTypes.addEventListener("change", (event) => {
  const option = event.target.closest("[data-bulk-type-id]");
  if (!option) return;
  state.selectedBulkTypeId = Number(option.dataset.bulkTypeId);
  syncBulkTypeSelection();
  setMessage(elements.bulkMessage, "");
});
elements.bulkForm.querySelectorAll("[data-bulk-operation]").forEach((button) => {
  button.addEventListener("click", () => void adjustBulkPrices(button.dataset.bulkOperation));
});
elements.bulkForm.addEventListener("submit", (event) => event.preventDefault());
document.querySelectorAll("[data-dialog-close]").forEach((button) => {
  button.addEventListener("click", () => {
    const dialog = button.closest("dialog");
    if (dialog === elements.bulkDialog && state.bulkSaving) {
      setMessage(elements.bulkMessage, "Espera a que termine el ajuste antes de cerrar.", "error");
      return;
    }
    if (dialog === elements.dateTimeDialog && state.dateTimeSaving) {
      setMessage(elements.dateTimeMessage, "Espera a que termine la actualización antes de cerrar.", "error");
      return;
    }
    if (dialog === elements.weighingDialog && state.weighingSaving) {
      setMessage(
        elements.weighingMessage,
        "Espera a que termine la actualización de la pesada antes de cerrar.",
        "error"
      );
      return;
    }
    if (state.lifecycleSaving && dialog === elements.voidDialog) {
      setMessage(elements.voidMessage, "Espera a que termine la anulación antes de cerrar.", "error");
      return;
    }
    if (state.lifecycleSaving && dialog === elements.restoreDialog) {
      setMessage(elements.restoreMessage, "Espera a que termine la restauración antes de cerrar.", "error");
      return;
    }
    dialog?.close();
  });
});
elements.bulkDialog.addEventListener("cancel", (event) => {
  if (!state.bulkSaving) return;
  event.preventDefault();
  setMessage(elements.bulkMessage, "Espera a que termine el ajuste antes de cerrar.", "error");
});
elements.weighingDialog.addEventListener("cancel", (event) => {
  if (!state.weighingSaving) return;
  event.preventDefault();
  setMessage(
    elements.weighingMessage,
    "Espera a que termine la actualización de la pesada antes de cerrar.",
    "error"
  );
});
elements.dateTimeDialog.addEventListener("cancel", (event) => {
  if (!state.dateTimeSaving) return;
  event.preventDefault();
  setMessage(elements.dateTimeMessage, "Espera a que termine la actualización antes de cerrar.", "error");
});
elements.voidDialog.addEventListener("cancel", (event) => {
  if (!state.lifecycleSaving) return;
  event.preventDefault();
  setMessage(elements.voidMessage, "Espera a que termine la anulación antes de cerrar.", "error");
});
elements.restoreDialog.addEventListener("cancel", (event) => {
  if (!state.lifecycleSaving) return;
  event.preventDefault();
  setMessage(elements.restoreMessage, "Espera a que termine la restauración antes de cerrar.", "error");
});
elements.voidDialog.addEventListener("close", () => {
  if (!state.lifecycleSaving) state.voidingTicketId = null;
});
elements.restoreDialog.addEventListener("close", () => {
  if (!state.lifecycleSaving) state.restoringTicketId = null;
});
elements.dateTimeDialog.addEventListener("close", () => {
  if (!state.dateTimeSaving) state.editingDateTimeTicketId = null;
});
elements.weighingDialog.addEventListener("close", () => {
  if (state.weighingSaving) return;
  state.weighingRequestGeneration += 1;
  state.weighingRequestController?.abort();
  state.weighingRequestController = null;
  state.weighingLoading = false;
  elements.weighingCard.setAttribute("aria-busy", "false");
  state.editingWeighingTicketId = null;
  state.editingWeighingId = null;
  state.weighingRecord = null;
  state.weighingTicket = null;
  state.weighingCatalogs = {
    chicken_types: [],
    cage_types: [],
    origin_trucks: [],
    weight_adjustments: []
  };
  closeWeighingEditor({ force: true });
  setMessage(elements.weighingMessage, "");
});

initFinanceAccess(() => {
  const filters = state.pendingFilters || state.appliedFilters;
  const page = state.pendingFilters ? state.pendingPage : state.page;
  return filters ? loadTickets(filters, page) : undefined;
});
resetResults();
