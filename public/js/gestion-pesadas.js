import { apiRequest } from "./api-client.js";
import { printWeightControlTicket } from "./ticket-printer.js";

const root = document.querySelector("[data-weighing-management]");
const RETAIL_CHANNEL = "MINORISTA";
const PENDING_DELIVERY_MODE = "PENDING_ASSIGNMENT";
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
  message: document.getElementById("weighingManagementMessage"),
  searchForm: document.getElementById("ticketSearchForm"),
  searchInput: document.getElementById("ticketSearchInput"),
  searchClear: document.getElementById("ticketSearchClear"),
  resultCount: document.getElementById("ticketResultsCount"),
  resultList: document.getElementById("ticketResultsList"),
  selectedPanel: document.getElementById("selectedTicketPanel"),
  editModal: document.getElementById("editWeighingModal"),
  editForm: document.getElementById("editWeighingForm"),
  editClose: document.getElementById("editWeighingClose"),
  editCancel: document.getElementById("editWeighingCancel"),
  editMessage: document.getElementById("editWeighingMessage"),
  chickenTypeField: document.getElementById("editChickenTypeField"),
  chickenType: document.getElementById("editChickenType"),
  chickenConditionField: document.getElementById("editChickenConditionField"),
  chickenCondition: document.getElementById("editChickenCondition"),
  chickenVariantField: document.getElementById("editChickenVariantField"),
  chickenVariant: document.getElementById("editChickenVariant"),
  chickenSexField: document.getElementById("editChickenSexField"),
  chickenSexButtons: document.querySelectorAll("[data-management-sex]"),
  birdsPerCage: document.getElementById("editBirdsPerCage"),
  cages: document.getElementById("editCages"),
  cageType: document.getElementById("editCageType"),
  grossWeight: document.getElementById("editGrossWeight"),
  weightLabel: document.getElementById("editWeightLabel"),
  weightHelp: document.getElementById("editWeightHelp"),
  originTruckField: document.getElementById("editOriginTruckField"),
  originTruck: document.getElementById("editOriginTruck"),
  originTruckHelp: document.getElementById("editOriginTruckHelp"),
  weightSource: document.getElementById("editWeightSource"),
  weighedAt: document.getElementById("editWeighedAt"),
  weightPreview: document.getElementById("editWeightPreview"),
  deliveryModal: document.getElementById("editTicketDeliveryModal"),
  deliveryForm: document.getElementById("editTicketDeliveryForm"),
  deliveryClose: document.getElementById("editTicketDeliveryClose"),
  deliveryCancel: document.getElementById("editTicketDeliveryCancel"),
  deliveryCode: document.getElementById("editTicketDeliveryCode"),
  deliveryVehicle: document.getElementById("editTicketVehicle"),
  deliveryDriver: document.getElementById("editTicketDriver"),
  deliveryMessage: document.getElementById("editTicketDeliveryMessage"),
  deleteModal: document.getElementById("deleteWeighingModal"),
  deleteForm: document.getElementById("deleteWeighingForm"),
  deleteCopy: document.getElementById("deleteWeighingCopy"),
  deleteReason: document.getElementById("deleteWeighingReason"),
  deleteMessage: document.getElementById("deleteWeighingMessage"),
  deleteCancel: document.getElementById("deleteWeighingCancel"),
  voidTicketModal: document.getElementById("voidTicketModal"),
  voidTicketForm: document.getElementById("voidTicketForm"),
  voidTicketCode: document.getElementById("voidTicketCode"),
  voidTicketReason: document.getElementById("voidTicketReason"),
  voidTicketMessage: document.getElementById("voidTicketMessage"),
  voidTicketClose: document.getElementById("voidTicketClose"),
  voidTicketCancel: document.getElementById("voidTicketCancel"),
  voidTicketSubmit: document.getElementById("voidTicketSubmit")
};

const state = {
  tickets: [],
  selectedTicket: null,
  ticketTitle: "",
  ticketMessage: "",
  catalogs: { chicken_types: [], cage_types: [], delivery_trucks: [], delivery_drivers: [], origin_trucks: [], weight_adjustments: [] },
  editingWeighing: null,
  editingChickenSex: "MACHO",
  editingChickenVariantCode: "",
  deletingWeighing: null,
  voidingTicket: null,
  searchTimer: null,
  searching: false,
  saving: false
};

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function formatNumber(value) {
  return new Intl.NumberFormat("es-CO").format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatMoney(value) {
  return `S/ ${Number(value || 0).toFixed(2)}`;
}

function formatDate(value, includeTime = false) {
  if (!value) {
    return "--";
  }

  if (includeTime) {
    const parts = String(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (parts) {
      return `${parts[3]}/${parts[2]}/${parts[1]}, ${parts[4]}:${parts[5]}`;
    }
  }

  const normalized = `${value}T12:00:00`;
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("es-CO", includeTime
    ? { dateStyle: "short", timeStyle: "short" }
    : { dateStyle: "medium" }
  ).format(date);
}

function operationLabel(value) {
  return value === "DEVOLUCION" ? "Devolución" : "Despacho";
}

function isRetailTicket(ticket) {
  return ticket?.channel === RETAIL_CHANNEL;
}

function isWholesaleTwoTicket(ticket) {
  return ticket?.source_module === WHOLESALE_TWO_SOURCE_MODULE;
}

function usesWholesaleTwoVariants(ticket) {
  return isWholesaleTwoTicket(ticket) && ticket?.operation_type === "DESPACHO";
}

function wholesaleTwoVariantByCode(value) {
  const code = String(value || "").trim().toUpperCase();
  return WHOLESALE_TWO_CHICKEN_VARIANTS.find((variant) => variant.code === code) || null;
}

function requiresWholesaleTwoTicketPrice(value) {
  return WHOLESALE_TWO_PRICE_REQUIRED_VARIANTS.has(String(value || "").trim().toUpperCase());
}

function wholesaleTwoVariantForWeighing(weighing) {
  const explicit = wholesaleTwoVariantByCode(weighing?.chicken_variant_code);
  if (explicit) {
    return explicit;
  }

  const typeCode = String(weighing?.chicken_type?.code || weighing?.chicken_type_code || "").toUpperCase();
  const sex = String(weighing?.chicken_sex || "").toUpperCase() || null;
  const presentation = String(weighing?.presentation || "").toUpperCase() || null;

  return WHOLESALE_TWO_CHICKEN_VARIANTS.find((variant) => (
    variant.typeCode === typeCode
      && variant.sex === sex
      && variant.presentation === presentation
  )) || null;
}

function wholesaleTwoAdjustmentForVariant(value) {
  const code = String(value || "").trim().toUpperCase();

  return (state.catalogs.weight_adjustments || [])
    .find((adjustment) => String(adjustment?.code || "").toUpperCase() === code) || null;
}

function editingWholesaleTwoAdjustmentGrams() {
  if (!usesWholesaleTwoVariants(state.selectedTicket)) {
    return 0;
  }

  const selectedCode = String(elements.chickenVariant.value || "").toUpperCase();
  const originalCode = wholesaleTwoVariantForWeighing(state.editingWeighing)?.code || "";

  if (selectedCode === originalCode) {
    return Math.max(0, Number(state.editingWeighing?.adjustment?.additional_grams) || 0);
  }

  return Math.max(0, Number(wholesaleTwoAdjustmentForVariant(selectedCode)?.additional_grams) || 0);
}

function ticketCustomerName(ticket) {
  if (ticket?.client?.name) {
    return ticket.client.name;
  }

  if (isRetailTicket(ticket)) {
    return "Venta externa (sin cliente asignado)";
  }

  return ticket?.destination?.name || "Sin destino registrado";
}

function retailCustomerBadge(ticket) {
  if (!isRetailTicket(ticket)) {
    return "";
  }

  return ticket?.client?.id
    ? '<span class="weighing-customer-badge">Cliente registrado</span>'
    : '<span class="weighing-customer-badge is-external">Venta externa</span>';
}

function hasDeferredDeliveryAssignment(ticket) {
  return Boolean(ticket?.delivery_assignment_deferred);
}

function hasPendingDeliveryAssignment(ticket) {
  const deliveryMode = ticket?.delivery?.mode || ticket?.delivery_mode;

  return hasDeferredDeliveryAssignment(ticket)
    && deliveryMode === PENDING_DELIVERY_MODE
    && !ticket?.delivery?.vehicle
    && !ticket?.delivery?.driver;
}

function pendingDeliveryBadge(ticket) {
  return hasPendingDeliveryAssignment(ticket)
    ? '<span class="weighing-delivery-pending-badge">Pendiente de agregar camión y chofer</span>'
    : "";
}

function priceOriginLabel(value) {
  const labels = {
    CLIENTE: "Precio del cliente",
    LISTA_CLIENTE: "Precio del cliente",
    MANUAL: "Precio personalizado",
    PERSONALIZADO: "Precio personalizado",
    LISTA_PERSONALIZADA: "Precio personalizado",
    GENERAL: "Precio vigente",
    VIGENTE: "Precio vigente",
    LISTA_VIGENTE: "Precio vigente"
  };

  return labels[String(value || "").toUpperCase()] || "Precio aplicado al despacho";
}

function normalizeChickenSex(value) {
  return String(value || "").toUpperCase() === "HEMBRA" ? "HEMBRA" : "MACHO";
}

function renderChickenSexButtons() {
  const selectedSex = normalizeChickenSex(state.editingChickenSex);
  elements.chickenSexButtons.forEach((button) => {
    const selected = button.dataset.managementSex === selectedSex;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
}

function selectChickenSex(value) {
  state.editingChickenSex = normalizeChickenSex(value);
  renderChickenSexButtons();
}

function applySuggestedChickenSex() {
  const birdsPerCage = Number(elements.birdsPerCage.value);
  if (birdsPerCage === 7) {
    selectChickenSex("MACHO");
  } else if (birdsPerCage === 9) {
    selectChickenSex("HEMBRA");
  }
}

function chickenSexBadge(value) {
  const normalized = String(value || "").toUpperCase();
  if (normalized !== "MACHO" && normalized !== "HEMBRA") {
    return '<span class="directory-record-tag" title="Sin sexo aplicable" aria-label="Sin sexo aplicable">--</span>';
  }

  const sex = normalizeChickenSex(value);
  const female = sex === "HEMBRA";
  const label = female ? "Hembra" : "Macho";
  return `<span class="chicken-sex-badge chicken-sex-${female ? "hembra" : "macho"}" title="${label}" aria-label="${label}">${female ? "H" : "M"}</span>`;
}

function weighingClassificationBadge(weighing, ticket) {
  if (!usesWholesaleTwoVariants(ticket)) {
    return chickenSexBadge(weighing?.chicken_sex);
  }

  const variant = wholesaleTwoVariantForWeighing(weighing);
  if (!variant) {
    return '<span class="directory-record-tag" title="Clasificación no disponible">--</span>';
  }

  return `<span class="directory-record-tag" title="${escapeHtml(variant.label)}" aria-label="${escapeHtml(variant.label)}">${escapeHtml(variant.shortLabel)}</span>`;
}

function getPrintedTypeCode(weighing, ticket) {
  if (ticket?.operation_type === "DEVOLUCION") {
    return weighing?.chicken_condition === "MUERTO" ? "PM" : "PV";
  }

  if (usesWholesaleTwoVariants(ticket)) {
    return wholesaleTwoVariantForWeighing(weighing)?.shortLabel || "--";
  }

  const typeCode = weighing?.chicken_type?.code || weighing?.chicken_type_code;
  const printedCodes = {
    POLLO_VIVO: "PV",
    POLLO_PELADO: "PP",
    POLLO_BENEFICIADO: "PB",
    POLLO_MUERTO: "PM"
  };

  return printedCodes[typeCode] || typeCode || "PV";
}

function optionalNumber(value) {
  if (value === null || value === undefined || String(value).trim() === "") {
    return null;
  }

  const numericValue = Number(value);
  return Number.isFinite(numericValue) ? numericValue : null;
}

function missingWholesaleTwoPrices(ticket) {
  if (!usesWholesaleTwoVariants(ticket)) {
    return [];
  }

  const missingByCode = new Map();
  (ticket.weighings || []).forEach((weighing) => {
    const variant = wholesaleTwoVariantForWeighing(weighing);
    if (
      variant
      && WHOLESALE_TWO_PRICE_REQUIRED_VARIANTS.has(variant.code)
      && !(optionalNumber(weighing.price_kg) > 0)
    ) {
      missingByCode.set(variant.code, variant.label);
    }
  });

  return Array.from(missingByCode.values());
}

function buildSelectedTicketPrintData(ticket) {
  const retail = isRetailTicket(ticket);

  return {
    code: ticket.code,
    channel: ticket.channel,
    sourceModule: ticket.source_module,
    operationType: ticket.operation_type,
    destinationName: retail ? (ticket.client?.name || "Venta externa") : ticketCustomerName(ticket),
    customerKind: retail ? (ticket.client?.id ? "CLIENTE_REGISTRADO" : "VENTA_EXTERNA") : null,
    ticketTitle: state.ticketTitle,
    ticketMessage: state.ticketMessage,
    operatingDate: ticket.operating_date,
    emittedAt: ticket.closed_at,
    totalAmount: ticket.summary?.amount,
    delivery: ticket.delivery,
    records: (ticket.weighings || []).map((weighing) => ({
      typeCode: getPrintedTypeCode(weighing, ticket),
      birds: Number(weighing.birds) || 0,
      birdsPerCage: Number(retail ? weighing.birds_per_tray : weighing.birds_per_cage) || 0,
      cages: Number(retail ? weighing.trays : weighing.cages) || 0,
      readWeight: Number(weighing.read_weight_kg) || 0,
      grossWeight: Number(weighing.gross_weight_kg) || 0,
      tareWeight: Number(weighing.tare_weight_kg) || 0,
      netWeight: Number(weighing.net_weight_kg) || 0,
      priceKg: optionalNumber(weighing.price_kg),
      amount: optionalNumber(weighing.amount),
      adjustment: weighing.adjustment || null
    }))
  };
}

function printSelectedTicket() {
  const ticket = state.selectedTicket;

  if (!ticket) {
    setMessage("Selecciona un ticket para imprimir.", true);
    return;
  }

  if (!ticket.weighings?.length) {
    setMessage("El ticket seleccionado no tiene pesadas para imprimir.", true);
    return;
  }

  const missingPrices = missingWholesaleTwoPrices(ticket);
  if (missingPrices.length) {
    setMessage(`Asigna un precio a ${missingPrices.join(", ")} antes de imprimir el ticket.`, true);
    return;
  }

  printWeightControlTicket(buildSelectedTicketPrintData(ticket), {
    frameTitle: `Impresión de ${ticket.code}`,
    onSuccess: () => setMessage(`${ticket.code} enviado a la ventana de impresión.`),
    onError: () => setMessage("No se pudo iniciar la impresión del ticket.", true)
  });
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.hidden = !text;
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function setModalMessage(element, text, isError = false) {
  element.textContent = text || "";
  element.classList.toggle("is-error", Boolean(isError));
}

function getErrorMessage(error, fallback = "No se pudo completar la solicitud.") {
  const validation = error?.data?.errors
    ? Object.values(error.data.errors).flat().find(Boolean)
    : null;

  return validation || error?.message || fallback;
}

function renderTicketResults() {
  elements.resultCount.textContent = formatNumber(state.tickets.length);

  if (!state.tickets.length) {
    elements.resultList.innerHTML = `
      <div class="weighing-results-empty">
        <strong>Sin resultados</strong>
        <span>Prueba con otro número de ticket o nombre de cliente.</span>
      </div>
    `;
    return;
  }

  elements.resultList.innerHTML = state.tickets.map((ticket) => {
    const active = Number(ticket.id) === Number(state.selectedTicket?.id);
    const readOnly = ticket.editable === false;
    const retail = isRetailTicket(ticket);
    return `
      <button class="weighing-ticket-option${active ? " is-active" : ""}${readOnly ? " is-readonly" : ""}${retail ? " is-retail" : ""}" type="button" data-ticket-id="${ticket.id}">
        <span class="weighing-ticket-option-head">
          <strong>${escapeHtml(ticket.code)}</strong>
          <span class="weighing-ticket-option-tags">
            <small>${escapeHtml(operationLabel(ticket.operation_type))}</small>
            ${retail ? '<span class="weighing-channel-badge">Despacho minorista</span>' : ""}
            ${retailCustomerBadge(ticket)}
            ${pendingDeliveryBadge(ticket)}
            ${readOnly ? '<span class="weighing-readonly-badge">Solo lectura</span>' : ""}
          </span>
        </span>
        <span>${escapeHtml(ticketCustomerName(ticket))}</span>
        <small>${escapeHtml(formatDate(ticket.operating_date))} · ${formatNumber(ticket.weighings_count)} pesada${Number(ticket.weighings_count) === 1 ? "" : "s"}</small>
      </button>
    `;
  }).join("");
}

function renderAppliedPrices(ticket) {
  if (!isRetailTicket(ticket)) {
    return "";
  }

  const prices = Object.entries(ticket.prices || {});
  if (!prices.length) {
    return `
      <div class="weighing-ticket-prices">
        <div>
          <small>Precios aplicados al despacho</small>
          <strong>Sin precios guardados</strong>
        </div>
      </div>
    `;
  }

  return `
    <div class="weighing-ticket-prices" aria-label="Precios aplicados al despacho">
      ${prices.map(([typeCode, price]) => `
        <div>
          <small>${escapeHtml(price?.chicken_type?.name || typeCode.replaceAll("_", " "))}</small>
          <strong>${escapeHtml(formatMoney(price?.price_kg))}/kg</strong>
          <span>${escapeHtml(priceOriginLabel(price?.source))}</span>
        </div>
      `).join("")}
    </div>
  `;
}

function renderSelectedTicket() {
  const ticket = state.selectedTicket;

  renderTicketResults();
  if (!ticket) {
    elements.selectedPanel.innerHTML = `
      <div class="directory-empty card">
        <strong>Selecciona un ticket</strong>
        <span>Solo se mostrarán las pesadas del ticket seleccionado.</span>
      </div>
    `;
    return;
  }

  const summary = ticket.summary || {};
  const isDispatch = ticket.operation_type === "DESPACHO";
  const retail = isRetailTicket(ticket);
  const wholesaleTwo = usesWholesaleTwoVariants(ticket);
  const canEdit = ticket.editable && !retail;
  const deliveryPending = hasPendingDeliveryAssignment(ticket);
  const showDeliveryManagement = isDispatch
    && (!retail || hasDeferredDeliveryAssignment(ticket));
  const rows = (ticket.weighings || []).map((weighing) => {
    const actions = canEdit
      ? `
          <button class="btn btn-secondary" type="button" data-edit-weighing="${weighing.id}">Editar</button>
          <button class="btn btn-danger" type="button" data-delete-weighing="${weighing.id}">Eliminar</button>
        `
      : '<span class="weighing-readonly-badge">Solo lectura</span>';
    const wholesaleTwoVariant = usesWholesaleTwoVariants(ticket)
      ? wholesaleTwoVariantForWeighing(weighing)
      : null;
    const chickenTypeDetail = wholesaleTwoVariant
      ? `<span class="customer-chicken-condition">${escapeHtml(wholesaleTwoVariant.label)}</span>`
      : `<span class="customer-chicken-condition${weighing.chicken_condition === "MUERTO" ? " customer-chicken-condition-muerto" : ""}">${escapeHtml(weighing.chicken_condition || "VIVO")}</span>`;

    return `
      <tr>
      <td><strong>#${formatNumber(weighing.number)}</strong></td>
      <td>
        <div class="customer-record-type">
          <strong>${escapeHtml(weighing.chicken_type?.name || "Sin tipo")}</strong>
          ${chickenTypeDetail}
        </div>
      </td>
      <td>${weighingClassificationBadge(weighing, ticket)}</td>
      <td>${retail
        ? `${escapeHtml(weighing.adjustment?.name || "Sin ajuste")}${weighing.adjustment?.additional_grams ? `<small><br>+${formatNumber(weighing.adjustment.additional_grams)} g/pollo</small>` : ""}`
        : `${escapeHtml(weighing.origin || "--")}<small>${weighing.plate ? `<br>${escapeHtml(weighing.plate)}` : ""}</small>`}
      </td>
      <td>${escapeHtml((retail ? weighing.tray_type?.name : weighing.cage_type?.name) || "--")}</td>
      <td>${formatNumber(retail ? weighing.trays : weighing.cages)}</td>
      <td>${formatNumber(retail ? weighing.birds_per_tray : weighing.birds_per_cage)}</td>
      <td>${formatNumber(weighing.birds)}</td>
      ${wholesaleTwo ? `
        <td>${formatWeight(weighing.read_weight_kg)}</td>
        <td><strong>${formatWeight(weighing.adjustment?.total_weight_kg ?? (Number(weighing.gross_weight_kg) - Number(weighing.read_weight_kg)))}</strong><small><br>${formatNumber(weighing.adjustment?.additional_grams || 0)} g/ave</small></td>
      ` : ""}
      <td>${formatWeight(weighing.gross_weight_kg)}</td>
      <td>${formatWeight(weighing.tare_weight_kg)}</td>
      <td><strong>${formatWeight(weighing.net_weight_kg)}</strong></td>
      ${retail ? `
        <td><strong>${escapeHtml(formatMoney(weighing.price_kg))}/kg</strong><small><br>${escapeHtml(priceOriginLabel(weighing.price_origin))}</small></td>
        <td><strong>${escapeHtml(formatMoney(weighing.amount))}</strong></td>
      ` : ""}
      <td>${escapeHtml(formatDate(weighing.weighed_at, true))}</td>
      <td>
        <div class="weighing-row-actions">
          ${actions}
        </div>
      </td>
      </tr>
    `;
  }).join("");

  elements.selectedPanel.innerHTML = `
    <article class="weighing-ticket-detail card">
      <header class="weighing-ticket-detail-head">
        <div>
          <div class="customer-ticket-badges">
            <span class="customer-operation-tag ${ticket.operation_type === "DEVOLUCION" ? "customer-operation-return" : "customer-operation-dispatch"}">${escapeHtml(operationLabel(ticket.operation_type))}</span>
            ${retail ? '<span class="weighing-channel-badge">Despacho minorista</span>' : ""}
            ${retailCustomerBadge(ticket)}
            ${pendingDeliveryBadge(ticket)}
            <span class="directory-record-tag">${escapeHtml(formatDate(ticket.operating_date))}</span>
            ${ticket.editable ? "" : '<span class="weighing-readonly-badge">Solo lectura</span>'}
          </div>
          <h2>${escapeHtml(ticket.code)}</h2>
          <p>${escapeHtml(ticketCustomerName(ticket))}</p>
        </div>
        <div class="weighing-ticket-detail-actions">
          ${ticket.can_void ? '<button class="btn btn-danger" type="button" data-void-selected-ticket>Anular ticket</button>' : ""}
          <button class="btn btn-primary" type="button" data-print-selected-ticket ${(ticket.weighings || []).length ? "" : "disabled"}>Imprimir ticket</button>
          <button class="btn btn-ghost" type="button" data-refresh-ticket>Actualizar</button>
        </div>
      </header>
      ${ticket.editable ? "" : `
        <div class="weighing-readonly-notice" role="note">
          ${escapeHtml(ticket.edit_restriction || (retail
            ? "Los tickets de despacho minorista se conservan en modo de consulta para respetar los precios aplicados al vender."
            : "Este ticket pertenece a una jornada anterior y solo puede consultarse en esta vista."))}
        </div>
      `}
      ${showDeliveryManagement ? `
        <div class="weighing-ticket-delivery">
          <span>
            <small>Camión de entrega</small>
            <strong>${escapeHtml(ticket.internal_client
              ? "No aplica - cliente interno"
              : (ticket.delivery?.vehicle?.plate || (deliveryPending ? "Pendiente de agregar camión" : "Sin camión asignado")))}</strong>
          </span>
          <span>
            <small>Chofer de entrega</small>
            <strong>${escapeHtml(ticket.internal_client
              ? "No aplica - cliente interno"
              : (ticket.delivery?.driver?.name || (deliveryPending ? "Pendiente de agregar chofer" : "Sin chofer asignado")))}</strong>
          </span>
          ${ticket.delivery_editable && !ticket.internal_client
            ? `<button class="btn btn-secondary" type="button" data-edit-ticket-delivery>${deliveryPending ? "Agregar camión y chofer" : "Editar transporte"}</button>`
            : ""}
        </div>
      ` : ""}
      <div class="weighing-ticket-stats${retail ? " is-retail" : ""}">
        <span><small>Pesadas</small><strong>${formatNumber(summary.weighings)}</strong></span>
        <span><small>${retail ? "Bandejas" : "Javas"}</small><strong>${formatNumber(retail ? summary.trays : summary.cages)}</strong></span>
        <span><small>Aves</small><strong>${formatNumber(summary.birds)}</strong></span>
        ${wholesaleTwo ? `<span><small>Peso leído</small><strong>${formatWeight(summary.read_weight_kg)}</strong></span>` : ""}
        ${wholesaleTwo ? `<span><small>Merma aplicada</small><strong>${formatWeight(summary.adjustment_weight_kg)}</strong></span>` : ""}
        <span><small>Peso bruto</small><strong>${formatWeight(summary.gross_weight_kg)}</strong></span>
        <span><small>Tara</small><strong>${formatWeight(summary.tare_weight_kg)}</strong></span>
        <span class="is-accent"><small>Peso neto</small><strong>${formatWeight(summary.net_weight_kg)}</strong></span>
        ${retail ? `<span class="is-sale-total"><small>Total del ticket</small><strong>${escapeHtml(formatMoney(summary.amount))}</strong></span>` : ""}
      </div>
      ${renderAppliedPrices(ticket)}
      <div class="customer-history-table-wrap weighing-table-wrap">
        <table class="customer-history-table weighing-records-table${retail ? " is-retail" : ""}">
          <thead>
            <tr>
              <th>N.º</th><th>Tipo</th><th>Sexo</th><th>${retail ? "Ajuste" : "Origen"}</th><th>${retail ? "Bandeja" : "Java"}</th><th>${retail ? "Bandejas" : "Javas"}</th><th>${retail ? "Aves/bandeja" : "Aves/java"}</th><th>Aves</th>${wholesaleTwo ? "<th>Lectura</th><th>Merma</th>" : ""}<th>Bruto</th><th>Tara</th><th>Neto</th>${retail ? "<th>Precio aplicado</th><th>Subtotal</th>" : ""}<th>Fecha</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>${rows || `<tr><td colspan="${retail || wholesaleTwo ? 15 : 13}" class="customer-history-empty-cell">Este ticket ya no tiene pesadas activas.</td></tr>`}</tbody>
        </table>
      </div>
    </article>
  `;
}

async function searchTickets(showStatus = true) {
  if (state.searching) {
    return;
  }

  state.searching = true;
  elements.resultList.classList.add("is-loading");
  if (showStatus) {
    setMessage("Buscando tickets...");
  }

  try {
    const query = elements.searchInput.value.trim();
    const suffix = query ? `?search=${encodeURIComponent(query)}` : "";
    const response = await apiRequest(`/operacion/gestion-pesadas${suffix}`);
    state.tickets = response.data?.tickets || [];
    renderTicketResults();
    if (showStatus) {
      setMessage("");
    }
  } catch (error) {
    state.tickets = [];
    renderTicketResults();
    setMessage(getErrorMessage(error, "No se pudieron buscar los tickets."), true);
  } finally {
    state.searching = false;
    elements.resultList.classList.remove("is-loading");
  }
}

async function selectTicket(ticketId, showStatus = true) {
  if (!ticketId) {
    return;
  }

  if (showStatus) {
    setMessage("Cargando pesadas del ticket...");
  }
  elements.selectedPanel.classList.add("is-loading");

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas`);
    state.selectedTicket = response.data?.ticket || null;
    state.ticketTitle = String(response.data?.ticket_title || "").trim();
    state.ticketMessage = String(response.data?.ticket_message || "").trim();
    state.catalogs = response.data?.catalogs || {
      chicken_types: [],
      cage_types: [],
      delivery_trucks: [],
      delivery_drivers: [],
      origin_trucks: [],
      weight_adjustments: []
    };
    renderSelectedTicket();
    setMessage("");
  } catch (error) {
    setMessage(getErrorMessage(error, "No se pudo cargar el ticket."), true);
  } finally {
    elements.selectedPanel.classList.remove("is-loading");
  }
}

function findSelectedWeighing(id) {
  return state.selectedTicket?.weighings?.find((item) => Number(item.id) === Number(id)) || null;
}

function renderDeliveryOptions() {
  elements.deliveryVehicle.innerHTML = (state.catalogs.delivery_trucks || [])
    .map((truck) => `<option value="${escapeHtml(truck.id)}">${escapeHtml(truck.plate)}${truck.detail ? ` · ${escapeHtml(truck.detail)}` : ""}</option>`)
    .join("");
  elements.deliveryDriver.innerHTML = (state.catalogs.delivery_drivers || [])
    .map((driver) => `<option value="${escapeHtml(driver.id)}">${escapeHtml(driver.name)}${driver.document ? ` · ${escapeHtml(driver.document)}` : ""}</option>`)
    .join("");
}

function openDeliveryModal() {
  const ticket = state.selectedTicket;
  if (!ticket || ticket.operation_type !== "DESPACHO") {
    setMessage("Solo los tickets de despacho tienen transporte de entrega.", true);
    return;
  }

  if (ticket.internal_client) {
    setMessage("El cliente interno no requiere transporte de entrega.", true);
    return;
  }

  if (!ticket.delivery_editable) {
    setMessage("El transporte de este ticket no puede modificarse en la jornada actual.", true);
    return;
  }

  renderDeliveryOptions();
  elements.deliveryCode.textContent = `${ticket.code} · ${ticket.destination?.name || "Sin destino"}`;
  elements.deliveryVehicle.value = String(ticket.delivery?.vehicle?.id || "");
  elements.deliveryDriver.value = String(ticket.delivery?.driver?.id || "");
  setModalMessage(elements.deliveryMessage, "");
  elements.deliveryModal.hidden = false;
  elements.deliveryVehicle.focus();
}

function closeDeliveryModal() {
  if (state.saving) {
    return;
  }
  elements.deliveryModal.hidden = true;
  setModalMessage(elements.deliveryMessage, "");
}

async function saveDelivery(event) {
  event.preventDefault();
  if (!state.selectedTicket || state.saving) {
    return;
  }

  state.saving = true;
  setModalMessage(elements.deliveryMessage, "Guardando transporte...");

  try {
    const response = await apiRequest(`/operacion/tickets/${state.selectedTicket.id}/transporte`, {
      method: "PUT",
      body: JSON.stringify({
        vehicle_id: Number(elements.deliveryVehicle.value),
        driver_id: Number(elements.deliveryDriver.value)
      })
    });
    state.selectedTicket = response.data?.ticket || state.selectedTicket;
    elements.deliveryModal.hidden = true;
    renderSelectedTicket();
    setMessage(response.message || "Transporte actualizado correctamente.");
    await searchTickets(false);
  } catch (error) {
    setModalMessage(elements.deliveryMessage, getErrorMessage(error, "No se pudo actualizar el transporte."), true);
  } finally {
    state.saving = false;
  }
}

function renderEditOptions() {
  const originalVariant = wholesaleTwoVariantForWeighing(state.editingWeighing);
  const originalRequiresTicketPrice = requiresWholesaleTwoTicketPrice(originalVariant?.code);
  elements.chickenType.innerHTML = (state.catalogs.chicken_types || [])
    .map((type) => `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)}</option>`)
    .join("");
  elements.chickenVariant.innerHTML = WHOLESALE_TWO_CHICKEN_VARIANTS
    .map((variant) => {
      const disabled = originalRequiresTicketPrice
        ? variant.code !== originalVariant.code
        : requiresWholesaleTwoTicketPrice(variant.code);
      return `<option value="${escapeHtml(variant.code)}"${disabled ? " disabled" : ""}>${escapeHtml(variant.label)}</option>`;
    })
    .join("");
  elements.chickenVariant.disabled = originalRequiresTicketPrice;
  elements.cageType.innerHTML = (state.catalogs.cage_types || [])
    .map((type) => `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)} (${Number(type.weight_kg).toFixed(3)} kg)</option>`)
    .join("");
  elements.originTruck.innerHTML = `
    <option value="">Mantener el origen actual</option>
    ${(state.catalogs.origin_trucks || []).map((truck) => `
      <option value="${escapeHtml(truck.program_detail_id)}">${escapeHtml(truck.provider_name)} · ${escapeHtml(truck.plate)}</option>
    `).join("")}
  `;
}

function updateWeightPreview() {
  const cage = (state.catalogs.cage_types || []).find((item) => item.code === elements.cageType.value);
  const cages = Math.max(0, Number(elements.cages.value) || 0);
  const birdsPerCage = Math.max(0, Number(elements.birdsPerCage.value) || 0);
  const enteredWeight = Math.max(0, Number(elements.grossWeight.value) || 0);
  const tare = cages * Number(cage?.weight_kg || 0);
  const wholesaleTwo = usesWholesaleTwoVariants(state.selectedTicket);
  const adjustmentGrams = wholesaleTwo ? editingWholesaleTwoAdjustmentGrams() : 0;
  const adjustmentWeight = wholesaleTwo
    ? (adjustmentGrams * birdsPerCage * Math.max(cages, 1)) / 1000
    : 0;
  const gross = enteredWeight + adjustmentWeight;
  const net = gross - tare;
  const birds = birdsPerCage * Math.max(cages, 1);

  elements.weightPreview.innerHTML = wholesaleTwo ? `
    <span><small>Aves totales</small><strong>${formatNumber(birds)}</strong></span>
    <span><small>Merma aplicada</small><strong>${formatWeight(adjustmentWeight)}</strong></span>
    <span><small>Peso bruto ajustado</small><strong>${formatWeight(gross)}</strong></span>
    <span><small>Tara calculada</small><strong>${formatWeight(tare)}</strong></span>
    <span class="${net <= 0 ? "is-invalid" : ""}"><small>Peso neto</small><strong>${formatWeight(net)}</strong></span>
  ` : `
    <span><small>Aves totales</small><strong>${formatNumber(birds)}</strong></span>
    <span><small>Tara calculada</small><strong>${formatWeight(tare)}</strong></span>
    <span class="${net <= 0 ? "is-invalid" : ""}"><small>Peso neto</small><strong>${formatWeight(net)}</strong></span>
  `;
}

function openEditModal(weighingId) {
  if (state.selectedTicket?.editable === false) {
    setMessage("Este ticket pertenece a una jornada anterior y solo puede consultarse.", true);
    return;
  }

  const weighing = findSelectedWeighing(weighingId);
  if (!weighing) {
    setMessage("La pesada seleccionada ya no existe.", true);
    return;
  }

  state.editingWeighing = weighing;
  renderEditOptions();
  const isReturn = state.selectedTicket.operation_type === "DEVOLUCION";
  const useWholesaleTwoVariants = usesWholesaleTwoVariants(state.selectedTicket);
  elements.chickenTypeField.hidden = isReturn || useWholesaleTwoVariants;
  elements.chickenConditionField.hidden = !isReturn;
  elements.chickenVariantField.hidden = !useWholesaleTwoVariants;
  elements.chickenSexField.hidden = useWholesaleTwoVariants;
  elements.originTruckField.hidden = isReturn;
  elements.weightLabel.textContent = useWholesaleTwoVariants ? "Peso leído (kg)" : "Peso bruto (kg)";
  elements.weightHelp.textContent = useWholesaleTwoVariants
    ? "Lectura original de la balanza. La merma configurada se calcula en el servidor."
    : "Peso bruto antes de descontar la tara de las javas.";
  elements.chickenType.value = weighing.chicken_type?.code || "";
  elements.chickenCondition.value = weighing.chicken_condition || "VIVO";
  state.editingChickenVariantCode = wholesaleTwoVariantForWeighing(weighing)?.code || "";
  elements.chickenVariant.value = state.editingChickenVariantCode;
  state.editingChickenSex = normalizeChickenSex(weighing.chicken_sex);
  renderChickenSexButtons();
  elements.birdsPerCage.value = weighing.birds_per_cage;
  elements.cages.value = weighing.cages;
  elements.cageType.value = weighing.cage_type?.code || "";
  elements.grossWeight.value = Number(useWholesaleTwoVariants
    ? weighing.read_weight_kg
    : weighing.gross_weight_kg).toFixed(3);
  const currentOriginId = String(weighing.origin_program_detail_id || "");
  const currentOriginIsAvailable = (state.catalogs.origin_trucks || [])
    .some((truck) => String(truck.program_detail_id) === currentOriginId);
  elements.originTruck.value = currentOriginIsAvailable ? currentOriginId : "";
  elements.originTruckHelp.textContent = currentOriginIsAvailable
    ? "Origen actual preseleccionado. Solo puedes cambiarlo por otro camión de esta jornada."
    : `Origen actual: ${weighing.origin || "sin origen"}${weighing.plate ? ` · ${weighing.plate}` : ""}. Selecciona un camión de la jornada para cambiarlo.`;
  elements.weightSource.value = [...elements.weightSource.options].some((option) => option.value === weighing.weight_source)
    ? weighing.weight_source
    : "MANUAL";
  elements.weighedAt.value = String(weighing.weighed_at || "").slice(0, 16);
  setModalMessage(
    elements.editMessage,
    requiresWholesaleTwoTicketPrice(state.editingChickenVariantCode)
      ? "La clasificación queda bloqueada para conservar el precio asignado al ticket. Puedes corregir cantidades, java, peso, origen y fecha."
      : ""
  );
  updateWeightPreview();
  elements.editModal.hidden = false;
  elements.birdsPerCage.focus();
}

function closeEditModal() {
  if (state.saving) {
    return;
  }
  state.editingWeighing = null;
  state.editingChickenSex = "MACHO";
  state.editingChickenVariantCode = "";
  elements.editModal.hidden = true;
  setModalMessage(elements.editMessage, "");
}

async function saveWeighing(event) {
  event.preventDefault();
  if (!state.editingWeighing || state.saving) {
    return;
  }

  state.saving = true;
  setModalMessage(elements.editMessage, "Guardando cambios...");
  const ticketId = state.selectedTicket.id;
  const weighingId = state.editingWeighing.id;
  const useWholesaleTwoVariants = usesWholesaleTwoVariants(state.selectedTicket);
  const selectedVariant = useWholesaleTwoVariants
    ? wholesaleTwoVariantByCode(elements.chickenVariant.value)
    : null;

  if (useWholesaleTwoVariants && !selectedVariant) {
    state.saving = false;
    setModalMessage(elements.editMessage, "Selecciona una clasificación de pollo válida.", true);
    return;
  }

  const payload = {
    chicken_type_code: selectedVariant?.typeCode
      || elements.chickenType.value
      || state.editingWeighing.chicken_type?.code,
    chicken_condition: elements.chickenCondition.value,
    cage_type_code: elements.cageType.value,
    weight_source: elements.weightSource.value,
    birds_per_cage: Number(elements.birdsPerCage.value),
    cages: Number(elements.cages.value),
    weighed_at: elements.weighedAt.value
  };
  if (useWholesaleTwoVariants) {
    payload.chicken_variant_code = selectedVariant.code;
    payload.read_weight_kg = Number(elements.grossWeight.value);
  } else {
    payload.chicken_sex = normalizeChickenSex(state.editingChickenSex);
    payload.gross_weight_kg = Number(elements.grossWeight.value);
  }
  if (state.selectedTicket.operation_type !== "DEVOLUCION" && elements.originTruck.value) {
    payload.origin_program_detail_id = Number(elements.originTruck.value);
  }

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas/${weighingId}`, {
      method: "PUT",
      body: JSON.stringify(payload)
    });
    state.selectedTicket = response.data?.ticket || state.selectedTicket;
    state.editingWeighing = null;
    elements.editModal.hidden = true;
    renderSelectedTicket();
    setMessage(response.message || "Pesada actualizada correctamente.");
    await searchTickets(false);
  } catch (error) {
    setModalMessage(elements.editMessage, getErrorMessage(error, "No se pudo actualizar la pesada."), true);
  } finally {
    state.saving = false;
  }
}

function openDeleteModal(weighingId) {
  if (state.selectedTicket?.editable === false) {
    setMessage("Este ticket pertenece a una jornada anterior y solo puede consultarse.", true);
    return;
  }

  const weighing = findSelectedWeighing(weighingId);
  if (!weighing) {
    setMessage("La pesada seleccionada ya no existe.", true);
    return;
  }

  state.deletingWeighing = weighing;
  elements.deleteCopy.textContent = `Se eliminará la pesada #${weighing.number} del ticket ${state.selectedTicket.code}. Esta acción quedará registrada.`;
  elements.deleteReason.value = "";
  setModalMessage(elements.deleteMessage, "");
  elements.deleteModal.hidden = false;
  elements.deleteReason.focus();
}

function closeDeleteModal() {
  if (state.saving) {
    return;
  }
  state.deletingWeighing = null;
  elements.deleteModal.hidden = true;
  setModalMessage(elements.deleteMessage, "");
}

async function deleteWeighing(event) {
  event.preventDefault();
  if (!state.deletingWeighing || state.saving) {
    return;
  }

  state.saving = true;
  setModalMessage(elements.deleteMessage, "Eliminando pesada...");
  const ticketId = state.selectedTicket.id;
  const weighingId = state.deletingWeighing.id;

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas/${weighingId}`, {
      method: "DELETE",
      body: JSON.stringify({ reason: elements.deleteReason.value.trim() })
    });
    state.selectedTicket = response.data?.ticket || state.selectedTicket;
    state.deletingWeighing = null;
    elements.deleteModal.hidden = true;
    renderSelectedTicket();
    setMessage(response.message || "Pesada eliminada correctamente.");
    await searchTickets(false);
  } catch (error) {
    setModalMessage(elements.deleteMessage, getErrorMessage(error, "No se pudo eliminar la pesada."), true);
  } finally {
    state.saving = false;
  }
}

function openVoidTicketModal() {
  const ticket = state.selectedTicket;

  if (!ticket?.can_void) {
    setMessage("Solo un administrador puede anular tickets cerrados.", true);
    return;
  }

  state.voidingTicket = ticket;
  elements.voidTicketCode.textContent = ticket.code || "--";
  elements.voidTicketReason.value = "";
  setModalMessage(elements.voidTicketMessage, "");
  elements.voidTicketModal.hidden = false;
  elements.voidTicketReason.focus();
}

function closeVoidTicketModal() {
  if (state.saving) {
    return;
  }

  state.voidingTicket = null;
  elements.voidTicketModal.hidden = true;
  setModalMessage(elements.voidTicketMessage, "");
}

async function voidTicket(event) {
  event.preventDefault();
  if (!state.voidingTicket || state.saving) {
    return;
  }

  const reason = elements.voidTicketReason.value.trim();
  if (reason.length < 3) {
    setModalMessage(elements.voidTicketMessage, "Escribe un motivo de al menos 3 caracteres.", true);
    elements.voidTicketReason.focus();
    return;
  }

  state.saving = true;
  elements.voidTicketSubmit.disabled = true;
  elements.voidTicketSubmit.textContent = "Anulando...";
  setModalMessage(elements.voidTicketMessage, "Anulando ticket...");
  const ticketId = state.voidingTicket.id;
  const ticketCode = state.voidingTicket.code;

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/anular`, {
      method: "POST",
      body: JSON.stringify({ motivo: reason })
    });
    state.voidingTicket = null;
    state.selectedTicket = null;
    elements.voidTicketModal.hidden = true;
    renderSelectedTicket();
    await searchTickets(false);
    setMessage(response.message || `${ticketCode} anulado correctamente.`);
  } catch (error) {
    setModalMessage(elements.voidTicketMessage, getErrorMessage(error, "No se pudo anular el ticket."), true);
  } finally {
    state.saving = false;
    elements.voidTicketSubmit.disabled = false;
    elements.voidTicketSubmit.textContent = "Sí, anular ticket";
  }
}

function bindEvents() {
  elements.searchForm.addEventListener("submit", (event) => {
    event.preventDefault();
    window.clearTimeout(state.searchTimer);
    void searchTickets();
  });
  elements.searchInput.addEventListener("input", () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(() => void searchTickets(false), 350);
  });
  elements.searchClear.addEventListener("click", () => {
    elements.searchInput.value = "";
    elements.searchInput.focus();
    void searchTickets();
  });
  elements.resultList.addEventListener("click", (event) => {
    const option = event.target.closest("[data-ticket-id]");
    if (option) {
      void selectTicket(option.dataset.ticketId);
    }
  });
  elements.selectedPanel.addEventListener("click", (event) => {
    const voidButton = event.target.closest("[data-void-selected-ticket]");
    const printButton = event.target.closest("[data-print-selected-ticket]");
    const editButton = event.target.closest("[data-edit-weighing]");
    const deleteButton = event.target.closest("[data-delete-weighing]");
    if (voidButton) {
      openVoidTicketModal();
    } else if (printButton) {
      printSelectedTicket();
    } else if (editButton) {
      openEditModal(editButton.dataset.editWeighing);
    } else if (deleteButton) {
      openDeleteModal(deleteButton.dataset.deleteWeighing);
    } else if (event.target.closest("[data-edit-ticket-delivery]")) {
      openDeliveryModal();
    } else if (event.target.closest("[data-refresh-ticket]")) {
      void selectTicket(state.selectedTicket?.id);
    }
  });
  elements.editForm.addEventListener("submit", saveWeighing);
  elements.deliveryForm.addEventListener("submit", saveDelivery);
  elements.deliveryClose.addEventListener("click", closeDeliveryModal);
  elements.deliveryCancel.addEventListener("click", closeDeliveryModal);
  elements.chickenSexButtons.forEach((button) => {
    button.addEventListener("click", () => selectChickenSex(button.dataset.managementSex));
  });
  elements.editClose.addEventListener("click", closeEditModal);
  elements.editCancel.addEventListener("click", closeEditModal);
  elements.deleteForm.addEventListener("submit", deleteWeighing);
  elements.deleteCancel.addEventListener("click", closeDeleteModal);
  elements.voidTicketForm.addEventListener("submit", voidTicket);
  elements.voidTicketClose.addEventListener("click", closeVoidTicketModal);
  elements.voidTicketCancel.addEventListener("click", closeVoidTicketModal);
  elements.birdsPerCage.addEventListener("input", applySuggestedChickenSex);
  elements.chickenVariant.addEventListener("change", updateWeightPreview);
  [elements.cages, elements.birdsPerCage, elements.grossWeight, elements.cageType]
    .forEach((control) => control.addEventListener("input", updateWeightPreview));
  elements.editModal.addEventListener("click", (event) => {
    if (event.target === elements.editModal) {
      closeEditModal();
    }
  });
  elements.deleteModal.addEventListener("click", (event) => {
    if (event.target === elements.deleteModal) {
      closeDeleteModal();
    }
  });
  elements.deliveryModal.addEventListener("click", (event) => {
    if (event.target === elements.deliveryModal) {
      closeDeliveryModal();
    }
  });
  elements.voidTicketModal.addEventListener("click", (event) => {
    if (event.target === elements.voidTicketModal) {
      closeVoidTicketModal();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }
    if (!elements.voidTicketModal.hidden) {
      closeVoidTicketModal();
    } else if (!elements.deleteModal.hidden) {
      closeDeleteModal();
    } else if (!elements.deliveryModal.hidden) {
      closeDeliveryModal();
    } else if (!elements.editModal.hidden) {
      closeEditModal();
    }
  });
}

if (root) {
  bindEvents();
  void searchTickets();
}
