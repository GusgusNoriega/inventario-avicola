import {
  PRODUCT_DISPATCH_MAX_UNIT_PRICE,
  PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS,
  calculateDraft,
  calculateLine,
  currencyLabel,
  effectiveProduct,
  escapeHtml,
  formatMoney,
  formatWeight,
  normalizeCatalog,
  normalizePriceMode,
  priceModeLabel,
} from "./despacho-productos-despacho-utils.js";
import { printProductDispatchTicket } from "./despacho-productos-ticket-printer.js";

const ALLOWED_PAGE_SIZES = [10, 20, 50];
const MAX_READ_WEIGHT_KG = 999999999.999;
let editorLineSequence = 0;

function positiveInteger(value, fallback = 0) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : fallback;
}

function ticketFromResponse(response = {}) {
  return response?.data?.ticket
    || response?.data
    || response?.ticket
    || response
    || {};
}

function localDateTimeValue(value) {
  const raw = String(value || "").trim();
  const localMatch = raw.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/);
  const includesTimezone = /(?:z|[+-]\d{2}:?\d{2})$/i.test(raw);
  if (localMatch && !includesTimezone) {
    const seconds = localMatch[3] ? `:${localMatch[3]}` : "";
    return `${localMatch[1]}T${localMatch[2]}${seconds}`;
  }

  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (part) => String(part).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function canonicalLocalDateTime(value) {
  const normalized = localDateTimeValue(value);
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalized)
    ? `${normalized}:00`
    : normalized;
}

function lineKey(line = {}) {
  const existingId = positiveInteger(line.id);
  editorLineSequence += 1;
  return existingId ? `existing-${existingId}` : `new-${Date.now()}-${editorLineSequence}`;
}

function normalizeEditorLine(line = {}) {
  const product = line.product || line.producto || {};
  const variation = line.variation || line.variacion || null;
  const normalized = calculateLine({
    quantity: line.quantity ?? line.cantidad ?? 1,
    read_weight_kg: line.read_weight_kg ?? line.peso_leido_kg ?? 0,
    waste_grams_per_unit: line.waste_grams_per_unit ?? line.merma_gramos_unidad,
    waste_total_grams: line.waste_total_grams ?? line.merma_total_gramos ?? 0,
    tare_grams: line.tare_grams ?? line.tara_gramos ?? 0,
    unit_price: line.unit_price ?? line.precio_venta ?? 0,
    price_mode: line.price_mode ?? line.modo_precio,
  });
  const id = positiveInteger(line.id);
  const variationId = positiveInteger(
    line.variation_id
      ?? line.variacion_producto_despacho_id
      ?? variation?.id,
  );
  const productId = positiveInteger(
    line.product_id
      ?? line.producto_despacho_id
      ?? product?.id,
  ) || null;
  const productName = String(
    line.product_name
      ?? line.nombre_producto
      ?? product?.name
      ?? product?.nombre
      ?? "Producto",
  );
  const variationName = variationId
    ? String(
      line.variation_name
        ?? line.nombre_variacion
        ?? variation?.name
        ?? variation?.nombre
        ?? "Variación",
    )
    : null;

  return {
    ...(id ? { id } : {}),
    local_key: String(line.local_key || lineKey(line)),
    product_id: productId,
    product_name: productName,
    variation_id: variationId || null,
    variation_name: variationName,
    weight_source: String(line.weight_source ?? line.origen_peso ?? "MANUAL"),
    original_read_weight_kg: normalized.read_weight_kg,
    historical_selection: id
      ? {
        product_id: productId,
        product_name: productName,
        variation_id: variationId || null,
        variation_name: variationName,
        price_mode: normalized.price_mode,
        unit_price: normalized.unit_price,
        waste_grams_per_unit: normalized.waste_grams_per_unit,
      }
      : null,
    weighed_at: line.weighed_at ?? line.pesada_at ?? null,
    weighed_at_local: localDateTimeValue(
      line.weighed_at_local ?? line.pesada_at_local ?? line.weighed_at ?? line.pesada_at,
    ),
    ...normalized,
  };
}

export function buildProductDispatchTicketQuery(filters = {}) {
  const params = new URLSearchParams();
  const search = String(filters.search || "").trim();
  const dateFrom = String(filters.date_from || "").trim();
  const dateTo = String(filters.date_to || "").trim();
  const page = Math.max(1, positiveInteger(filters.page, 1));
  const requestedPageSize = positiveInteger(filters.per_page, 10);
  const perPage = ALLOWED_PAGE_SIZES.includes(requestedPageSize) ? requestedPageSize : 10;

  if (search) params.set("search", search);
  if (dateFrom) params.set("date_from", dateFrom);
  if (dateTo) params.set("date_to", dateTo);
  params.set("page", String(page));
  params.set("per_page", String(perPage));
  return params.toString();
}

export function normalizeProductDispatchTicketsPayload(response = {}) {
  const source = response?.data && !Array.isArray(response.data) ? response.data : response;
  const tickets = Array.isArray(source?.tickets)
    ? source.tickets
    : (Array.isArray(source?.data) ? source.data : []);
  const rawPagination = source?.pagination || source?.meta || {};
  const total = Math.max(0, Number(rawPagination.total ?? tickets.length) || 0);
  const perPage = positiveInteger(rawPagination.per_page, tickets.length || 10);
  const currentPage = positiveInteger(rawPagination.current_page, 1);
  const lastPage = positiveInteger(
    rawPagination.last_page,
    Math.max(1, Math.ceil(total / Math.max(1, perPage))),
  );
  const rawSummary = source?.summary || {};
  const summaryAmounts = Array.isArray(rawSummary.amounts)
    ? rawSummary.amounts
      .map((entry) => ({
        currency: String(entry?.currency || entry?.moneda || "").toUpperCase(),
        amount: Number(entry?.amount ?? entry?.total ?? 0) || 0,
      }))
      .filter((entry) => entry.currency)
    : [];
  const rawAmount = Object.prototype.hasOwnProperty.call(rawSummary, "amount")
    ? rawSummary.amount
    : (rawSummary.total ?? 0);

  return {
    tickets,
    pagination: {
      current_page: currentPage,
      last_page: lastPage,
      per_page: perPage,
      total,
      from: total ? Number(rawPagination.from ?? ((currentPage - 1) * perPage + 1)) : null,
      to: total ? Number(rawPagination.to ?? Math.min(currentPage * perPage, total)) : null,
    },
    summary: {
      tickets: Math.max(0, Number(rawSummary.tickets ?? total) || 0),
      currency: rawSummary.currency ? String(rawSummary.currency).toUpperCase() : null,
      amount: rawAmount === null ? null : (Number(rawAmount) || 0),
      amounts: summaryAmounts,
    },
    applied_filters: source?.applied_filters && typeof source.applied_filters === "object"
      ? source.applied_filters
      : {},
  };
}

export function normalizeProductDispatchTicketForEditor(response = {}) {
  const ticket = ticketFromResponse(response);
  const weighings = ticket.weighings || ticket.pesadas || ticket.items || [];
  const listNumber = Math.min(8, Math.max(1, positiveInteger(
    ticket.list_number ?? ticket.numero_lista,
    1,
  )));

  const registeredAt = localDateTimeValue(
    ticket.registered_at_local
      ?? ticket.registrado_at_local
      ?? ticket.registered_at
      ?? ticket.registrado_at,
  );

  return {
    id: positiveInteger(ticket.id),
    code: String(ticket.code ?? ticket.codigo ?? "Ticket"),
    version: ticket.version ?? ticket.updated_at ?? ticket.actualizado_at ?? null,
    updated_at: ticket.updated_at ?? ticket.actualizado_at ?? null,
    ticket_title: String(
      ticket.product_ticket_title
        ?? ticket.ticket_title
        ?? ticket.titulo_ticket
        ?? "DESPACHO DE PRODUCTOS",
    ).trim().slice(0, 180) || "DESPACHO DE PRODUCTOS",
    list_number: listNumber,
    client_id: positiveInteger(ticket.client_id ?? ticket.cliente_id ?? ticket.client?.id) || null,
    client: ticket.client || ticket.cliente || null,
    registered_at: registeredAt,
    original_registered_at: registeredAt,
    correction_reason: "",
    currency: String(ticket.currency ?? ticket.moneda ?? "PEN"),
    weighings: (Array.isArray(weighings) ? weighings : []).map(normalizeEditorLine),
  };
}

export function buildProductDispatchTicketUpdatePayload(draft = {}) {
  return {
    version: draft.version,
    correction_reason: String(draft.correction_reason || "").trim(),
    ticket_title: String(draft.ticket_title || "").trim(),
    list_number: Math.min(8, Math.max(1, Math.round(Number(draft.list_number) || 1))),
    client_id: positiveInteger(draft.client_id) || null,
    registered_at: localDateTimeValue(draft.registered_at),
    weighings: (Array.isArray(draft.weighings) ? draft.weighings : []).map((line) => {
      const calculated = calculateLine(line);
      const id = positiveInteger(line.id);
      return {
        ...(id ? { id } : {}),
        product_id: positiveInteger(line.product_id),
        variation_id: positiveInteger(line.variation_id) || null,
        quantity: calculated.quantity,
        price_mode: normalizePriceMode(line.price_mode),
        unit_price: calculated.unit_price,
        waste_grams_per_unit: calculated.waste_grams_per_unit,
        waste_total_grams: calculated.waste_total_grams,
        tare_grams: calculated.tare_grams,
        read_weight_kg: calculated.read_weight_kg,
      };
    }),
  };
}

export function productDispatchEditorFingerprint(draft = {}) {
  return JSON.stringify({
    ticket_title: String(draft.ticket_title || ""),
    list_number: String(draft.list_number ?? ""),
    client_id: positiveInteger(draft.client_id) || null,
    registered_at: canonicalLocalDateTime(draft.registered_at),
    correction_reason: String(draft.correction_reason || ""),
    weighings: (Array.isArray(draft.weighings) ? draft.weighings : []).map((line) => ({
      id: positiveInteger(line.id) || null,
      product_id: positiveInteger(line.product_id) || null,
      variation_id: positiveInteger(line.variation_id) || null,
      quantity: String(line.quantity ?? ""),
      price_mode: String(line.price_mode || ""),
      unit_price: String(line.unit_price ?? ""),
      waste_grams_per_unit: String(line.waste_grams_per_unit ?? ""),
      tare_grams: String(line.tare_grams ?? ""),
      read_weight_kg: String(line.read_weight_kg ?? ""),
    })),
  });
}

export function applyProductDispatchCatalogDefaults(line, catalog = {}) {
  const product = (Array.isArray(catalog.products) ? catalog.products : [])
    .find((entry) => Number(entry.id) === Number(line.product_id));
  const variation = product?.variations?.find(
    (entry) => Number(entry.id) === Number(line.variation_id),
  ) || null;
  const historical = line.historical_selection;
  const returnsToHistorical = historical
    && Number(historical.product_id) === Number(line.product_id)
    && (positiveInteger(historical.variation_id) || null)
      === (positiveInteger(line.variation_id) || null);

  if (returnsToHistorical) {
    Object.assign(line, {
      product_id: historical.product_id,
      product_name: historical.product_name,
      variation_id: historical.variation_id,
      variation_name: historical.variation_name,
      price_mode: historical.price_mode,
      unit_price: historical.unit_price,
      waste_grams_per_unit: historical.waste_grams_per_unit,
    });
    return line;
  }

  const selection = effectiveProduct(product, variation);
  if (!selection) return line;
  Object.assign(line, {
    product_id: selection.product_id,
    product_name: selection.product_name,
    variation_id: selection.variation_id,
    variation_name: selection.variation_name,
    price_mode: selection.price_mode,
    unit_price: selection.price,
    waste_grams_per_unit: selection.waste_grams_per_unit,
  });
  return line;
}

function mountProductDispatchTickets() {
  const root = document.querySelector("#productDispatchTickets");
  if (!root) return;

  return import("./api-client.js").then(({ apiRequest }) => {
    const apiBase = root.dataset.apiBase || "/despacho-productos";
    const elements = {
      filters: document.querySelector("#pdtFilters"),
      search: document.querySelector("#pdtSearch"),
      dateFrom: document.querySelector("#pdtDateFrom"),
      dateTo: document.querySelector("#pdtDateTo"),
      perPage: document.querySelector("#pdtPerPage"),
      filterSubmit: document.querySelector("#pdtFilterSubmit"),
      filterReset: document.querySelector("#pdtFilterReset"),
      message: document.querySelector("#pdtMessage"),
      summaryTickets: document.querySelector("#pdtSummaryTickets"),
      summaryAmount: document.querySelector("#pdtSummaryAmount"),
      recordRange: document.querySelector("#pdtRecordRange"),
      list: document.querySelector("#pdtTicketList"),
      pagination: document.querySelector("#pdtPagination"),
      pagePrevious: document.querySelector("#pdtPagePrevious"),
      pageNext: document.querySelector("#pdtPageNext"),
      pageStatus: document.querySelector("#pdtPageStatus"),
      editorDialog: document.querySelector("#pdtEditorDialog"),
      editorForm: document.querySelector("#pdtEditorForm"),
      editorTitle: document.querySelector("#pdtEditorTitle"),
      editorSubtitle: document.querySelector("#pdtEditorSubtitle"),
      editorLoading: document.querySelector("#pdtEditorLoading"),
      editorContent: document.querySelector("#pdtEditorContent"),
      ticketTitle: document.querySelector("#pdtTicketTitle"),
      listNumber: document.querySelector("#pdtListNumber"),
      client: document.querySelector("#pdtClient"),
      registeredAt: document.querySelector("#pdtRegisteredAt"),
      correctionReason: document.querySelector("#pdtCorrectionReason"),
      addLine: document.querySelector("#pdtAddLine"),
      editorLines: document.querySelector("#pdtEditorLines"),
      editTotalWeighings: document.querySelector("#pdtEditTotalWeighings"),
      editTotalQuantity: document.querySelector("#pdtEditTotalQuantity"),
      editTotalNet: document.querySelector("#pdtEditTotalNet"),
      editTotalAmount: document.querySelector("#pdtEditTotalAmount"),
      editorMessage: document.querySelector("#pdtEditorMessage"),
      saveTicket: document.querySelector("#pdtSaveTicket"),
      timeChangeWarning: document.querySelector("#pdtTimeChangeWarning"),
      acknowledgeTimeChange: document.querySelector("#pdtAcknowledgeTimeChange"),
    };

    const state = {
      data: null,
      loading: false,
      requestRevision: 0,
      lastRequest: null,
      catalog: normalizeCatalog(),
      catalogReady: false,
      catalogPromise: null,
      editorTicket: null,
      editorTicketId: null,
      editorLoading: false,
      editorRevision: 0,
      editorBaseline: null,
      saving: false,
      returnFocus: null,
      currency: "PEN",
      printing: new Set(),
    };

    const integerFormatter = new Intl.NumberFormat("es-PE", { maximumFractionDigits: 0 });
    const dateTimeFormatter = new Intl.DateTimeFormat("es-PE", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
    const dateFormatter = new Intl.DateTimeFormat("es-PE", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    });

    function errorMessage(error, fallback = "No se pudo completar la solicitud.") {
      const errors = error?.data?.errors;
      if (errors && typeof errors === "object") {
        const first = Object.values(errors).flat().find(Boolean);
        if (first) return String(first);
      }
      return String(error?.data?.message || error?.message || fallback);
    }

    function setMessage(message, type = "") {
      elements.message.textContent = message;
      elements.message.classList.toggle("is-error", type === "error");
      elements.message.classList.toggle("is-success", type === "success");
    }

    function setEditorMessage(message, type = "") {
      elements.editorMessage.textContent = message;
      elements.editorMessage.classList.toggle("is-error", type === "error");
      elements.editorMessage.classList.toggle("is-success", type === "success");
    }

    function formatInteger(value) {
      return integerFormatter.format(Number(value || 0));
    }

    function parsableDate(value) {
      if (!value) return null;
      const raw = String(value).trim();
      const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(raw)
        ? raw.replace(" ", "T")
        : raw;
      const date = new Date(normalized);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDateTime(value) {
      const date = parsableDate(value);
      return date ? dateTimeFormatter.format(date) : String(value || "Sin fecha");
    }

    function formatDate(value) {
      if (/^\d{4}-\d{2}-\d{2}$/.test(String(value || ""))) {
        const [year, month, day] = String(value).split("-").map(Number);
        return dateFormatter.format(new Date(year, month - 1, day));
      }
      const date = parsableDate(value);
      return date ? dateFormatter.format(date) : String(value || "Sin fecha");
    }

    function displayTicketDate(ticket) {
      return formatDateTime(ticket.registered_at_local || ticket.registered_at || ticket.registrado_at);
    }

    function ticketCurrency(ticket) {
      return String(ticket?.currency || ticket?.moneda || state.catalog.currency || state.currency || "PEN");
    }

    function ticketStatus(ticket) {
      const status = String(ticket?.status || ticket?.estado || "ACTIVO").trim();
      return status || "ACTIVO";
    }

    function ticketCustomer(ticket) {
      return String(
        ticket?.customer_label
          || ticket?.client?.name
          || ticket?.client_label
          || ticket?.cliente?.name
          || ticket?.cliente?.nombre
          || "Venta al público",
      );
    }

    function ticketCustomerDocument(ticket) {
      return String(
        ticket?.client?.document
          || ticket?.client?.document_number
          || ticket?.client?.numero_documento
          || ticket?.cliente?.documento
          || ticket?.cliente?.numero_documento
          || "",
      ).trim();
    }

    function ticketCustomerDisplay(ticket) {
      const name = ticketCustomer(ticket);
      const document = ticketCustomerDocument(ticket);
      return document ? `${name} · ${document}` : name;
    }

    function ticketCreator(ticket) {
      const creator = ticket?.creator || ticket?.created_by || ticket?.usuario;
      if (typeof creator === "string") return creator;
      return String(
        creator?.name
          || creator?.nombre
          || ticket?.creator_name
          || ticket?.created_by_name
          || "No informado",
      );
    }

    function ticketTotals(ticket) {
      const source = ticket?.totals || ticket?.totales || {};
      const fallback = calculateDraft(ticket?.weighings || ticket?.pesadas || []);
      return {
        weighings: Number(source.weighings ?? fallback.weighings) || 0,
        quantity: Number(source.quantity ?? fallback.quantity) || 0,
        read_weight_kg: Number(source.read_weight_kg ?? fallback.read_weight_kg) || 0,
        net_weight_kg: Number(source.net_weight_kg ?? fallback.net_weight_kg) || 0,
        amount: Number(source.amount ?? source.total ?? fallback.amount) || 0,
      };
    }

    function lineProduct(line) {
      const product = line?.product || line?.producto || {};
      const variation = line?.variation || line?.variacion || null;
      return {
        product: String(line?.product_name || product?.name || product?.nombre || "Producto"),
        variation: String(line?.variation_name || variation?.name || variation?.nombre || ""),
      };
    }

    function weightSourceLabel(line) {
      const source = String(line?.weight_source || line?.origen_peso || "MANUAL").toUpperCase();
      return source.includes("BALANZA") ? "Balanza" : "Manual";
    }

    function renderTicketLine(line, currency) {
      const names = lineProduct(line);
      const calculated = calculateLine(line);
      const wastePerUnit = Number(line.waste_grams_per_unit ?? calculated.waste_grams_per_unit) || 0;
      const wasteTotal = Number(line.waste_total_grams ?? calculated.waste_total_grams) || 0;
      const tare = Number(line.tare_grams ?? calculated.tare_grams) || 0;
      const net = Number(line.net_weight_kg ?? calculated.net_weight_kg) || 0;
      const amount = Number(line.amount ?? line.total ?? calculated.amount) || 0;

      return `<tr>
        <td><span class="pdt-line-product"><strong>${escapeHtml(names.product)}</strong><small>${escapeHtml(names.variation || "Producto base")}</small></span></td>
        <td>${escapeHtml(formatInteger(line.quantity ?? calculated.quantity))}</td>
        <td>${escapeHtml(priceModeLabel(line.price_mode))}</td>
        <td>${escapeHtml(formatWeight(line.read_weight_kg ?? calculated.read_weight_kg))}</td>
        <td>${escapeHtml(weightSourceLabel(line))}</td>
        <td>${escapeHtml(formatDateTime(line.weighed_at_local || line.weighed_at || line.pesada_at))}</td>
        <td>${escapeHtml(`${formatInteger(wastePerUnit)} g`)}</td>
        <td>${escapeHtml(`${formatInteger(wasteTotal)} g`)}</td>
        <td>${escapeHtml(`${formatInteger(tare)} g`)}</td>
        <td>${escapeHtml(formatWeight(net))}</td>
        <td>${escapeHtml(formatMoney(line.unit_price ?? calculated.unit_price, currency))}</td>
        <td class="pdt-line-amount">${escapeHtml(formatMoney(amount, currency))}</td>
      </tr>`;
    }

    function renderTicket(ticket) {
      const ticketId = positiveInteger(ticket.id);
      const code = String(ticket.code || ticket.codigo || `Ticket ${ticketId}`);
      const status = ticketStatus(ticket);
      const isVoided = /ANUL|VOID|CANCEL/i.test(status);
      const listNumber = positiveInteger(ticket.list_number ?? ticket.numero_lista);
      const totals = ticketTotals(ticket);
      const currency = ticketCurrency(ticket);
      const weighings = Array.isArray(ticket.weighings || ticket.pesadas)
        ? (ticket.weighings || ticket.pesadas)
        : [];
      const title = String(ticket.product_ticket_title || ticket.ticket_title || "DESPACHO DE PRODUCTOS");
      const updated = ticket.updated_at || ticket.actualizado_at;
      const rows = weighings.length
        ? weighings.map((line) => renderTicketLine(line, currency)).join("")
        : '<tr><td class="pdt-empty-line" colspan="12">Este ticket no contiene pesadas.</td></tr>';

      return `<article class="pdt-ticket-card" aria-label="Detalle del ticket ${escapeHtml(code)}">
        <header class="pdt-ticket-head">
          <div class="pdt-ticket-identity">
            <div class="pdt-ticket-code-row">
              <h3>${escapeHtml(code)}</h3>
              <span class="pdt-status${isVoided ? " is-voided" : ""}">${escapeHtml(status)}</span>
              ${listNumber ? `<span class="pdt-list-badge">Lista ${listNumber}</span>` : ""}
            </div>
            <span>${escapeHtml(ticketCustomerDisplay(ticket))}</span>
          </div>
          <div class="pdt-ticket-time">
            <strong>${escapeHtml(displayTicketDate(ticket))}</strong>
            <span>Fecha operativa: ${escapeHtml(formatDate(ticket.operating_date || ticket.fecha_operativa))}</span>
          </div>
          <div class="pdt-ticket-actions">
            <button class="pdt-ticket-action is-edit" type="button" data-pdt-edit-ticket="${ticketId}" aria-label="Editar ticket ${escapeHtml(code)}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 16-.8 4.8L8 20l11-11-4-4L4 16zM13.8 6.2l4 4"></path></svg>
              Editar
            </button>
            <button class="pdt-ticket-action" type="button" data-pdt-print-ticket="${ticketId}" aria-label="Volver a imprimir ticket ${escapeHtml(code)}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 18H4V9h16v9h-3M7 14h10v7H7z"></path></svg>
              Reimprimir
            </button>
          </div>
        </header>

        <div class="pdt-ticket-meta">
          <div class="pdt-meta-item"><span>Cliente / documento</span><strong>${escapeHtml(ticketCustomerDisplay(ticket))}</strong></div>
          <div class="pdt-meta-item"><span>Creado por</span><strong>${escapeHtml(ticketCreator(ticket))}</strong></div>
          <div class="pdt-meta-item"><span>Título impreso</span><strong>${escapeHtml(title)}</strong></div>
          <div class="pdt-meta-item"><span>Registro actualizado</span><strong>${escapeHtml(updated ? formatDateTime(updated) : "Fecha no disponible")}</strong></div>
        </div>

        <div class="pdt-ticket-totals" aria-label="Totales del ticket">
          <div class="pdt-ticket-metric"><span>Pesadas</span><strong>${escapeHtml(formatInteger(totals.weighings))}</strong></div>
          <div class="pdt-ticket-metric"><span>Cantidad</span><strong>${escapeHtml(formatInteger(totals.quantity))}</strong></div>
          <div class="pdt-ticket-metric"><span>Peso leído</span><strong>${escapeHtml(formatWeight(totals.read_weight_kg))}</strong></div>
          <div class="pdt-ticket-metric"><span>Peso neto</span><strong>${escapeHtml(formatWeight(totals.net_weight_kg))}</strong></div>
          <div class="pdt-ticket-metric is-amount"><span>Total</span><strong>${escapeHtml(formatMoney(totals.amount, currency))}</strong></div>
        </div>

        <div class="pdt-ticket-detail">
          <div class="pdt-ticket-detail-head">
            <strong>Pesadas registradas</strong>
            <span>Desliza horizontalmente para revisar todas las columnas.</span>
          </div>
          <div class="pdt-table-wrap" role="region" tabindex="0" aria-label="Pesadas del ticket ${escapeHtml(code)}">
            <table class="pdt-detail-table">
              <caption class="sr-only">Detalle completo de pesadas del ticket ${escapeHtml(code)}</caption>
              <thead><tr>
                <th scope="col">Producto</th>
                <th scope="col">Cantidad</th>
                <th scope="col">Cobro</th>
                <th scope="col">Peso leído</th>
                <th scope="col">Origen</th>
                <th scope="col">Hora pesada</th>
                <th scope="col">Merma/u.</th>
                <th scope="col">Merma total</th>
                <th scope="col">Tara</th>
                <th scope="col">Peso neto</th>
                <th scope="col">Precio</th>
                <th scope="col">Importe</th>
              </tr></thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      </article>`;
    }

    function renderListState(title, detail, { error = false, retry = false, loading = false } = {}) {
      elements.list.setAttribute("aria-busy", loading ? "true" : "false");
      elements.list.innerHTML = `<div class="pdt-state card${error ? " is-error" : ""}">
        ${loading ? '<span class="pdt-spinner" aria-hidden="true"></span>' : ""}
        <strong>${escapeHtml(title)}</strong>
        <span>${escapeHtml(detail)}</span>
        ${retry ? '<button class="btn btn-ghost" type="button" data-pdt-retry-list>Intentar nuevamente</button>' : ""}
      </div>`;
    }

    function renderTickets(tickets) {
      elements.list.setAttribute("aria-busy", "false");
      if (!tickets.length) {
        renderListState(
          "No encontramos tickets",
          "Prueba con otro texto, amplía el rango de fechas o limpia los filtros.",
        );
        return;
      }
      elements.list.innerHTML = tickets.map(renderTicket).join("");
    }

    function renderSummary(data) {
      elements.summaryTickets.textContent = formatInteger(data.summary.tickets);
      if (!data.summary.tickets) {
        elements.summaryAmount.textContent = "Sin ventas";
        return;
      }
      if (data.summary.amounts.length > 1) {
        elements.summaryAmount.textContent = data.summary.amounts
          .map((entry) => formatMoney(entry.amount, entry.currency))
          .join(" + ");
        return;
      }
      const groupedAmount = data.summary.amounts[0];
      const currency = groupedAmount?.currency
        || data.summary.currency
        || (data.tickets[0] ? ticketCurrency(data.tickets[0]) : state.currency);
      elements.summaryAmount.textContent = formatMoney(
        groupedAmount?.amount ?? data.summary.amount ?? 0,
        currency,
      );
    }

    function renderPagination(pagination) {
      const total = Number(pagination.total || 0);
      const page = Number(pagination.current_page || 1);
      const lastPage = Number(pagination.last_page || 1);
      elements.recordRange.textContent = total
        ? `${formatInteger(pagination.from)}–${formatInteger(pagination.to)} de ${formatInteger(total)} tickets`
        : "0 tickets";
      elements.pagination.hidden = lastPage <= 1;
      elements.pageStatus.textContent = `Página ${formatInteger(page)} de ${formatInteger(lastPage)}`;
      elements.pagePrevious.disabled = state.loading || page <= 1;
      elements.pageNext.disabled = state.loading || page >= lastPage;
    }

    function setFilterControlsDisabled(disabled) {
      elements.search.disabled = disabled;
      elements.dateFrom.disabled = disabled;
      elements.dateTo.disabled = disabled;
      elements.perPage.disabled = disabled;
      elements.filterSubmit.disabled = disabled;
      elements.filterReset.disabled = disabled;
      elements.pagePrevious.disabled = disabled || Number(state.data?.pagination?.current_page || 1) <= 1;
      elements.pageNext.disabled = disabled || Number(state.data?.pagination?.current_page || 1) >= Number(state.data?.pagination?.last_page || 1);
    }

    function filtersFromForm() {
      return {
        search: elements.search.value.trim(),
        date_from: elements.dateFrom.value,
        date_to: elements.dateTo.value,
        per_page: Number(elements.perPage.value) || 10,
      };
    }

    function initialFilters() {
      const params = new URLSearchParams(window.location.search);
      const requestedPageSize = Number(params.get("per_page") || 10);
      return {
        search: params.get("search") || "",
        date_from: params.get("date_from") || "",
        date_to: params.get("date_to") || "",
        per_page: ALLOWED_PAGE_SIZES.includes(requestedPageSize) ? requestedPageSize : 10,
        page: Math.max(1, Number(params.get("page") || 1)),
      };
    }

    function setFilterValues(filters) {
      elements.search.value = filters.search || "";
      elements.dateFrom.value = filters.date_from || "";
      elements.dateTo.value = filters.date_to || "";
      elements.perPage.value = String(ALLOWED_PAGE_SIZES.includes(Number(filters.per_page)) ? filters.per_page : 10);
    }

    function validateDateRange() {
      const invalid = Boolean(
        elements.dateFrom.value
        && elements.dateTo.value
        && elements.dateFrom.value > elements.dateTo.value,
      );
      elements.dateTo.setCustomValidity(invalid ? "La fecha final debe ser igual o posterior a la inicial." : "");
      return !invalid;
    }

    function updateUrl(request) {
      const params = new URLSearchParams();
      if (request.search) params.set("search", request.search);
      if (request.date_from) params.set("date_from", request.date_from);
      if (request.date_to) params.set("date_to", request.date_to);
      if (Number(request.per_page) !== 10) params.set("per_page", String(request.per_page));
      if (Number(request.page) > 1) params.set("page", String(request.page));
      const query = params.toString();
      window.history.replaceState({}, "", `${window.location.pathname}${query ? `?${query}` : ""}`);
    }

    function resultMessage(data) {
      const total = Number(data.pagination.total || 0);
      if (!total) return "No hay tickets que coincidan con los filtros seleccionados.";
      return `${formatInteger(total)} ${total === 1 ? "ticket encontrado" : "tickets encontrados"}. Cada tarjeta muestra todas sus pesadas.`;
    }

    async function loadTickets({ page = 1, filters = filtersFromForm(), successMessage = "" } = {}) {
      if (state.loading) return false;
      const request = {
        search: String(filters.search || "").trim(),
        date_from: String(filters.date_from || ""),
        date_to: String(filters.date_to || ""),
        per_page: ALLOWED_PAGE_SIZES.includes(Number(filters.per_page)) ? Number(filters.per_page) : 10,
        page: Math.max(1, Number(page) || 1),
      };
      const revision = ++state.requestRevision;
      state.lastRequest = request;
      state.loading = true;
      setFilterControlsDisabled(true);
      setMessage("Consultando tickets y sus pesadas…");
      renderListState("Cargando tickets…", "Estamos preparando el detalle de las ventas.", { loading: true });
      elements.pagination.hidden = true;

      try {
        const response = await apiRequest(`${apiBase}/tickets?${buildProductDispatchTicketQuery(request)}`);
        if (revision !== state.requestRevision) return false;
        const data = normalizeProductDispatchTicketsPayload(response);
        state.data = data;
        state.currency = String(data.tickets[0]?.currency || state.catalog.currency || state.currency);
        renderSummary(data);
        renderTickets(data.tickets);
        renderPagination(data.pagination);
        updateUrl({ ...request, page: data.pagination.current_page });
        const messageType = successMessage || data.pagination.total ? "success" : "";
        setMessage(successMessage || resultMessage(data), messageType);
        return true;
      } catch (error) {
        console.error(error);
        if (revision !== state.requestRevision) return false;
        state.data = null;
        elements.summaryTickets.textContent = "—";
        elements.summaryAmount.textContent = "—";
        elements.recordRange.textContent = "Sin datos";
        renderListState(
          "No pudimos cargar los tickets",
          errorMessage(error, "Revisa la conexión e inténtalo nuevamente."),
          { error: true, retry: true },
        );
        setMessage(errorMessage(error, "No se pudo cargar el historial de tickets."), "error");
        return false;
      } finally {
        if (revision === state.requestRevision) {
          state.loading = false;
          setFilterControlsDisabled(false);
          if (state.data) renderPagination(state.data.pagination);
        }
      }
    }

    function ensureCatalog(forceRefresh = false) {
      if (forceRefresh && state.catalogPromise) {
        return state.catalogPromise.then(() => ensureCatalog(true));
      }
      if (!forceRefresh && state.catalogReady) return Promise.resolve(state.catalog);
      if (state.catalogPromise) return state.catalogPromise;
      if (forceRefresh) state.catalogReady = false;
      state.catalogPromise = apiRequest(`${apiBase}/catalogo`)
        .then((response) => {
          state.catalog = normalizeCatalog(response);
          state.catalogReady = true;
          state.currency = state.catalog.currency || state.currency;
          return state.catalog;
        })
        .finally(() => {
          state.catalogPromise = null;
        });
      return state.catalogPromise;
    }

    function openEditorDialog(trigger) {
      if (!elements.editorDialog.open) {
        state.returnFocus = trigger || document.activeElement;
        elements.editorDialog.showModal();
      }
    }

    function hasUnsavedEditorChanges() {
      if (!state.editorTicket || state.editorLoading || state.editorBaseline === null) return false;
      syncGeneralEditorValues();
      return productDispatchEditorFingerprint(state.editorTicket) !== state.editorBaseline;
    }

    function closeEditorDialog({ force = false } = {}) {
      if (state.saving) return;
      if (!force && hasUnsavedEditorChanges()
        && !window.confirm("Hay cambios sin guardar en este ticket. ¿Quieres descartarlos?")) {
        return;
      }
      if (elements.editorDialog.open) elements.editorDialog.close();
    }

    function setEditorBusy(busy) {
      state.saving = busy;
      elements.saveTicket.disabled = busy || state.editorLoading || !state.editorTicket;
      elements.addLine.disabled = busy || (state.editorTicket?.weighings.length || 0) >= 100;
      elements.editorForm.querySelectorAll("input, select, textarea, button").forEach((control) => {
        if (control.matches("[data-pdt-close-editor]")) {
          control.disabled = busy;
        } else if (control !== elements.saveTicket && control !== elements.addLine) {
          if (control.matches('[data-pdt-line-field="price_mode"]')) {
            control.disabled = true;
          } else if (!busy && control.matches('[data-pdt-line-field="variation_id"]')) {
            const row = control.closest("[data-pdt-line-key]");
            control.disabled = !editorLine(row?.dataset.pdtLineKey)?.product_id;
          } else {
            control.disabled = busy;
          }
        }
      });
      elements.saveTicket.textContent = busy ? "Guardando…" : "Guardar corrección";
    }

    function showEditorLoading(ticketId) {
      state.editorLoading = true;
      state.editorTicket = null;
      state.editorBaseline = null;
      state.editorTicketId = ticketId;
      elements.editorTitle.textContent = "Editar ticket";
      elements.editorSubtitle.textContent = "Cargando detalle…";
      elements.editorContent.hidden = true;
      elements.editorLoading.hidden = false;
      elements.editorLoading.innerHTML = '<span class="pdt-spinner" aria-hidden="true"></span><strong>Cargando ticket y catálogo…</strong>';
      elements.saveTicket.disabled = true;
      setEditorMessage("");
    }

    function showEditorLoadError(message) {
      state.editorLoading = false;
      elements.editorLoading.hidden = false;
      elements.editorLoading.innerHTML = `<strong>No pudimos abrir este ticket</strong><span>${escapeHtml(message)}</span><button class="btn btn-ghost" type="button" data-pdt-retry-editor>Intentar nuevamente</button>`;
      setEditorMessage(message, "error");
    }

    function catalogProduct(productId) {
      return state.catalog.products.find((product) => product.id === Number(productId)) || null;
    }

    function catalogVariation(product, variationId) {
      return product?.variations.find((variation) => variation.id === Number(variationId)) || null;
    }

    function productOptions(line) {
      const currentId = Number(line.product_id);
      const currentAvailable = state.catalog.products.some((product) => product.id === currentId);
      const historical = line.historical_selection;
      const usesHistoricalSelection = historical
        && Number(historical.product_id) === currentId
        && (positiveInteger(historical.variation_id) || null)
          === (positiveInteger(line.variation_id) || null);
      const options = ['<option value="">Selecciona un producto</option>'];
      if (currentId && !currentAvailable) {
        options.push(`<option value="${currentId}" selected>${escapeHtml(line.product_name)} (no disponible)</option>`);
      }
      state.catalog.products.forEach((product) => {
        const isSelected = product.id === currentId;
        const historicalName = usesHistoricalSelection && isSelected
          ? String(historical.product_name || line.product_name || product.name)
          : null;
        const label = historicalName && historicalName !== product.name
          ? `${historicalName} (nombre del ticket)`
          : (historicalName || product.name);
        options.push(`<option value="${product.id}"${isSelected ? " selected" : ""}>${escapeHtml(label)}</option>`);
      });
      return options.join("");
    }

    function variationOptions(line) {
      const product = catalogProduct(line.product_id);
      const currentId = Number(line.variation_id);
      const currentAvailable = product?.variations.some((variation) => variation.id === currentId);
      const historical = line.historical_selection;
      const usesHistoricalSelection = historical
        && Number(historical.product_id) === Number(line.product_id)
        && (positiveInteger(historical.variation_id) || null) === (currentId || null);
      const options = [`<option value=""${!currentId ? " selected" : ""}>Producto base</option>`];
      if (currentId && !currentAvailable) {
        options.push(`<option value="${currentId}" selected>${escapeHtml(line.variation_name || "Variación")} (no disponible)</option>`);
      }
      (product?.variations || []).forEach((variation) => {
        const isSelected = variation.id === currentId;
        const historicalName = usesHistoricalSelection && isSelected
          ? String(historical.variation_name || line.variation_name || variation.name)
          : null;
        const label = historicalName && historicalName !== variation.name
          ? `${historicalName} (nombre del ticket)`
          : (historicalName || variation.name);
        options.push(`<option value="${variation.id}"${isSelected ? " selected" : ""}>${escapeHtml(label)}</option>`);
      });
      return options.join("");
    }

    function renderEditorLine(line, index) {
      const calculated = calculateLine(line);
      const fieldId = `pdtLine${index}`;
      const existingLabel = line.id ? `Pesada existente #${line.id}` : "Nueva pesada";
      const evidenceLabel = line.id
        ? `${weightSourceLabel(line)} · ${formatDateTime(line.weighed_at_local || line.weighed_at)}`
        : "Origen manual · hora del ticket";
      return `<article class="pdt-edit-line" data-pdt-line-key="${escapeHtml(line.local_key)}">
        <header class="pdt-edit-line-head">
          <span class="pdt-edit-line-identity"><strong>Pesada ${index + 1} · ${escapeHtml(existingLabel)}</strong><small>${escapeHtml(evidenceLabel)}</small></span>
          <button class="pdt-remove-line" type="button" data-pdt-remove-line="${escapeHtml(line.local_key)}" aria-label="Quitar pesada ${index + 1}: ${escapeHtml(line.product_name || "sin producto")}">Quitar</button>
        </header>
        <div class="pdt-edit-line-fields">
          <label class="pdt-field pdt-product-field" for="${fieldId}Product">
            <span>Producto <b>*</b></span>
            <select id="${fieldId}Product" data-pdt-line-field="product_id" required>${productOptions(line)}</select>
          </label>
          <label class="pdt-field pdt-variation-field" for="${fieldId}Variation">
            <span>Variación</span>
            <select id="${fieldId}Variation" data-pdt-line-field="variation_id"${line.product_id ? "" : " disabled"}>${variationOptions(line)}</select>
          </label>
          <label class="pdt-field" for="${fieldId}Quantity">
            <span>Cantidad <b>*</b></span>
            <input id="${fieldId}Quantity" data-pdt-line-field="quantity" type="number" min="1" max="100000" step="1" value="${escapeHtml(line.quantity)}" inputmode="numeric" required>
          </label>
          <label class="pdt-field" for="${fieldId}Mode">
            <span>Forma de cobro (catálogo)</span>
            <select id="${fieldId}Mode" data-pdt-line-field="price_mode" disabled aria-label="Forma de cobro definida por el catálogo">
              <option value="POR_KG"${normalizePriceMode(line.price_mode) === "POR_KG" ? " selected" : ""}>Por kg</option>
              <option value="POR_UNIDAD"${normalizePriceMode(line.price_mode) === "POR_UNIDAD" ? " selected" : ""}>Por unidad</option>
            </select>
          </label>
          <label class="pdt-field" for="${fieldId}Price">
            <span>Precio unitario <b>*</b></span>
            <input id="${fieldId}Price" data-pdt-line-field="unit_price" type="number" min="0.01" max="${PRODUCT_DISPATCH_MAX_UNIT_PRICE}" step="0.01" value="${escapeHtml(line.unit_price)}" inputmode="decimal" required>
          </label>
          <label class="pdt-field" for="${fieldId}ReadWeight">
            <span>Peso leído (kg) <b>*</b></span>
            <input id="${fieldId}ReadWeight" data-pdt-line-field="read_weight_kg" type="number" min="0.001" max="${MAX_READ_WEIGHT_KG}" step="0.001" value="${escapeHtml(line.read_weight_kg)}" inputmode="decimal" required>
          </label>
          <label class="pdt-field" for="${fieldId}Waste">
            <span>Merma por unidad (g) <b>*</b></span>
            <input id="${fieldId}Waste" data-pdt-line-field="waste_grams_per_unit" type="number" min="0" max="1000000" step="1" value="${escapeHtml(line.waste_grams_per_unit)}" inputmode="numeric" required>
          </label>
          <label class="pdt-field" for="${fieldId}Tare">
            <span>Tara (g) <b>*</b></span>
            <input id="${fieldId}Tare" data-pdt-line-field="tare_grams" type="number" min="0" max="1000000000" step="1" value="${escapeHtml(line.tare_grams)}" inputmode="numeric" required>
          </label>
          <div class="pdt-calculated-field"><span>Merma total</span><strong data-pdt-line-waste>${escapeHtml(`${formatInteger(calculated.waste_total_grams)} g`)}</strong></div>
          <div class="pdt-calculated-field"><span>Neto e importe</span><strong data-pdt-line-calculation>${escapeHtml(`${formatWeight(calculated.net_weight_kg)} · ${formatMoney(calculated.amount, state.editorTicket.currency || state.currency)}`)}</strong></div>
        </div>
      </article>`;
    }

    function renderEditorLines() {
      const lines = state.editorTicket?.weighings || [];
      if (!lines.length) {
        elements.editorLines.innerHTML = '<div class="pdt-editor-empty-lines"><strong>El ticket no tiene pesadas</strong><span>Usa “Agregar pesada” para incluir al menos una antes de guardar.</span></div>';
      } else {
        elements.editorLines.innerHTML = lines.map(renderEditorLine).join("");
      }
      renderEditorTotals();
      setEditorBusy(state.saving);
    }

    function renderEditorTotals() {
      const totals = calculateDraft(state.editorTicket?.weighings || []);
      elements.editTotalWeighings.textContent = formatInteger(totals.weighings);
      elements.editTotalQuantity.textContent = formatInteger(totals.quantity);
      elements.editTotalNet.textContent = formatWeight(totals.net_weight_kg);
      elements.editTotalAmount.textContent = formatMoney(
        totals.amount,
        state.editorTicket?.currency || state.currency,
      );
    }

    function updateLineCalculation(row, line) {
      const calculated = calculateLine(line);
      const waste = row.querySelector("[data-pdt-line-waste]");
      const calculation = row.querySelector("[data-pdt-line-calculation]");
      if (waste) waste.textContent = `${formatInteger(calculated.waste_total_grams)} g`;
      if (calculation) {
        calculation.textContent = `${formatWeight(calculated.net_weight_kg)} · ${formatMoney(calculated.amount, state.editorTicket.currency || state.currency)}`;
      }
      renderEditorTotals();
    }

    function clientOptions(ticket) {
      const currentId = Number(ticket.client_id);
      const currentAvailable = state.catalog.clients.some((client) => client.id === currentId);
      const historicalName = String(ticket.client?.name || ticket.client?.nombre || "Cliente anterior");
      const historicalDocument = String(
        ticket.client?.document
          || ticket.client?.document_number
          || ticket.client?.numero_documento
          || "",
      ).trim();
      const options = ['<option value="">Venta al público</option>'];
      if (currentId && !currentAvailable) {
        const detail = historicalDocument ? ` · ${historicalDocument}` : "";
        options.push(`<option value="${currentId}" selected>${escapeHtml(`${historicalName}${detail}`)} (no disponible)</option>`);
      }
      state.catalog.clients.forEach((client) => {
        const isSelected = client.id === currentId;
        const catalogDocument = String(client.document || "").trim();
        const catalogLabel = `${client.name}${catalogDocument ? ` · ${catalogDocument}` : ""}`;
        const snapshotLabel = `${historicalName}${historicalDocument ? ` · ${historicalDocument}` : ""}`;
        const label = isSelected && snapshotLabel !== catalogLabel
          ? `${snapshotLabel} (datos del ticket)`
          : catalogLabel;
        options.push(`<option value="${client.id}"${isSelected ? " selected" : ""}>${escapeHtml(label)}</option>`);
      });
      return options.join("");
    }

    function populateEditor(ticket) {
      state.editorTicket = ticket;
      state.editorLoading = false;
      elements.editorTitle.textContent = `Editar ${ticket.code}`;
      elements.editorSubtitle.textContent = `Versión actual cargada · ${ticket.weighings.length} ${ticket.weighings.length === 1 ? "pesada" : "pesadas"}`;
      elements.ticketTitle.value = ticket.ticket_title;
      elements.listNumber.value = String(ticket.list_number);
      elements.client.innerHTML = clientOptions(ticket);
      elements.client.value = ticket.client_id ? String(ticket.client_id) : "";
      elements.registeredAt.value = ticket.registered_at;
      elements.acknowledgeTimeChange.checked = false;
      elements.correctionReason.value = "";
      elements.editorLoading.hidden = true;
      elements.editorContent.hidden = false;
      setEditorMessage("Revisa todos los datos. El motivo de corrección es obligatorio.");
      renderEditorLines();
      updateTimeChangeWarning();
      syncGeneralEditorValues();
      state.editorBaseline = productDispatchEditorFingerprint(state.editorTicket);
      elements.saveTicket.disabled = false;
      window.setTimeout(() => elements.ticketTitle.focus(), 0);
    }

    async function loadEditor(ticketId, trigger = null) {
      if (!ticketId || state.editorLoading || state.saving) return;
      const revision = ++state.editorRevision;
      showEditorLoading(ticketId);
      openEditorDialog(trigger);
      try {
        const [, response] = await Promise.all([
          ensureCatalog(true),
          apiRequest(`${apiBase}/tickets/${ticketId}`),
        ]);
        if (revision !== state.editorRevision || !elements.editorDialog.open) return;
        const ticket = normalizeProductDispatchTicketForEditor(response);
        if (!ticket.id) throw new Error("El servidor no devolvió un ticket válido.");
        populateEditor(ticket);
      } catch (error) {
        console.error(error);
        if (revision !== state.editorRevision || !elements.editorDialog.open) return;
        showEditorLoadError(errorMessage(error, "No se pudo cargar el ticket para editar."));
      }
    }

    function editorLine(key) {
      return state.editorTicket?.weighings.find((line) => line.local_key === key) || null;
    }

    function applyCatalogDefaults(line) {
      applyProductDispatchCatalogDefaults(line, state.catalog);
    }

    function newEditorLine() {
      const product = state.catalog.products[0] || null;
      const line = normalizeEditorLine({
        product_id: product?.id || null,
        product_name: product?.name || "Producto",
        variation_id: null,
        quantity: 1,
        price_mode: product?.price_mode || "POR_KG",
        unit_price: product?.price || 0,
        waste_grams_per_unit: product?.waste_grams_per_unit || 0,
        tare_grams: 0,
        read_weight_kg: 0,
      });
      applyCatalogDefaults(line);
      return line;
    }

    function syncGeneralEditorValues() {
      if (!state.editorTicket) return;
      state.editorTicket.ticket_title = elements.ticketTitle.value;
      state.editorTicket.list_number = Number(elements.listNumber.value);
      state.editorTicket.client_id = positiveInteger(elements.client.value) || null;
      state.editorTicket.registered_at = elements.registeredAt.value;
      state.editorTicket.correction_reason = elements.correctionReason.value;
    }

    function updateTimeChangeWarning() {
      const timeChanged = Boolean(
        state.editorTicket
        && canonicalLocalDateTime(elements.registeredAt.value)
          !== canonicalLocalDateTime(state.editorTicket.original_registered_at),
      );
      const scaleWeightChanged = Boolean(state.editorTicket?.weighings.some((line) => (
        line.id
        && String(line.weight_source || "").toUpperCase().includes("BALANZA")
        && Math.abs(Number(line.read_weight_kg) - Number(line.original_read_weight_kg)) > 0.0005
      )));
      const changed = timeChanged || scaleWeightChanged;
      elements.timeChangeWarning.hidden = !changed;
      if (changed) {
        const title = elements.timeChangeWarning.querySelector("strong");
        const detail = elements.timeChangeWarning.querySelector(":scope > span");
        if (title) {
          title.textContent = timeChanged
            ? "Este cambio modifica la hora de las pesadas."
            : "Este cambio modifica un peso capturado por balanza.";
        }
        if (detail) {
          detail.textContent = timeChanged
            ? "Las horas se desplazarán con el ticket. Para no atribuir una hora distinta a una captura física, sus pesadas de balanza quedarán identificadas como manuales."
            : "La pesada corregida quedará identificada como manual para no atribuir el nuevo valor a la lectura física original.";
        }
      }
      if (!changed) {
        elements.acknowledgeTimeChange.checked = false;
        elements.acknowledgeTimeChange.setCustomValidity("");
        elements.acknowledgeTimeChange.removeAttribute("aria-invalid");
      }
      return changed;
    }

    function markInvalid(control, message) {
      control.setCustomValidity(message);
      control.setAttribute("aria-invalid", "true");
      control.focus();
      control.reportValidity();
      setEditorMessage(message, "error");
      return false;
    }

    function clearEditorValidation() {
      elements.editorForm.querySelectorAll("input, select, textarea").forEach((control) => {
        control.setCustomValidity("");
        control.removeAttribute("aria-invalid");
      });
    }

    function validateEditor() {
      clearEditorValidation();
      syncGeneralEditorValues();
      if (!elements.ticketTitle.value.trim()) {
        return markInvalid(elements.ticketTitle, "Escribe el título que se imprimirá en el ticket.");
      }
      if (!elements.registeredAt.value) {
        return markInvalid(elements.registeredAt, "Selecciona la fecha y hora del ticket.");
      }
      if (updateTimeChangeWarning() && !elements.acknowledgeTimeChange.checked) {
        return markInvalid(
          elements.acknowledgeTimeChange,
          "Confirma que entiendes el efecto de la corrección sobre las horas y el origen de las pesadas.",
        );
      }
      if (elements.correctionReason.value.trim().length < 3) {
        return markInvalid(elements.correctionReason, "El motivo de la corrección debe tener al menos 3 caracteres.");
      }
      if (!state.editorTicket.weighings.length) {
        setEditorMessage("Agrega al menos una pesada antes de guardar.", "error");
        elements.addLine.focus();
        return false;
      }
      if (state.editorTicket.weighings.length > 100) {
        setEditorMessage("Un ticket puede contener como máximo 100 pesadas.", "error");
        elements.addLine.focus();
        return false;
      }

      for (const line of state.editorTicket.weighings) {
        const row = elements.editorLines.querySelector(`[data-pdt-line-key="${CSS.escape(line.local_key)}"]`);
        if (!row) continue;
        const control = (field) => row.querySelector(`[data-pdt-line-field="${field}"]`);
        const quantity = Number(line.quantity);
        const price = Number(line.unit_price);
        const readWeight = Number(line.read_weight_kg);
        const wastePerUnit = Number(line.waste_grams_per_unit);
        const tare = Number(line.tare_grams);
        const calculated = calculateLine(line);

        if (!positiveInteger(line.product_id)) return markInvalid(control("product_id"), "Selecciona un producto para cada pesada.");
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 100000) return markInvalid(control("quantity"), "La cantidad debe ser un número entero entre 1 y 100.000.");
        if (!Number.isFinite(price) || price < 0.01 || price > PRODUCT_DISPATCH_MAX_UNIT_PRICE) return markInvalid(control("unit_price"), "Ingresa un precio válido mayor o igual a 0,01.");
        if (!Number.isFinite(readWeight) || readWeight < 0.001 || readWeight > MAX_READ_WEIGHT_KG) return markInvalid(control("read_weight_kg"), "El peso leído debe estar entre 0,001 y 999.999.999,999 kg.");
        if (!Number.isInteger(wastePerUnit) || wastePerUnit < 0) return markInvalid(control("waste_grams_per_unit"), "La merma debe ser un número entero igual o mayor que cero.");
        if (calculated.waste_total_grams > PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS) return markInvalid(control("waste_grams_per_unit"), "La merma total supera el máximo permitido.");
        if (!Number.isInteger(tare) || tare < 0) return markInvalid(control("tare_grams"), "La tara debe ser un número entero igual o mayor que cero.");
        if (tare >= Math.round(readWeight * 1000) + calculated.waste_total_grams) return markInvalid(control("tare_grams"), "El peso leído más la merma debe superar la tara.");
        if (calculated.net_weight_kg <= 0) return markInvalid(control("read_weight_kg"), "El peso neto de cada pesada debe ser mayor que cero.");
        if (calculated.amount < 0.01) return markInvalid(control("unit_price"), "Cada pesada debe producir un importe mínimo de 0,01.");
      }

      return elements.editorForm.reportValidity();
    }

    async function saveEditor(event) {
      event.preventDefault();
      if (state.saving || state.editorLoading || !state.editorTicket || !validateEditor()) return;
      const ticketId = state.editorTicket.id;
      const ticketCode = state.editorTicket.code;
      const payload = buildProductDispatchTicketUpdatePayload(state.editorTicket);
      setEditorBusy(true);
      setEditorMessage("Guardando la corrección y recalculando el ticket…");
      try {
        await apiRequest(`${apiBase}/tickets/${ticketId}`, {
          method: "PUT",
          body: JSON.stringify(payload),
        });
        setEditorBusy(false);
        state.editorBaseline = null;
        closeEditorDialog({ force: true });
        const refreshed = await loadTickets({
          page: state.data?.pagination?.current_page || 1,
          filters: state.lastRequest || filtersFromForm(),
          successMessage: `Ticket ${ticketCode} actualizado correctamente.`,
        });
        if (!refreshed) {
          setMessage(`El ticket ${ticketCode} se actualizó, pero no pudimos refrescar la lista. Intenta recargarla.`, "error");
        }
      } catch (error) {
        console.error(error);
        const message = Number(error?.status) === 409
          ? "Este ticket cambió después de abrirlo. Cierra el editor, vuelve a abrirlo y aplica nuevamente la corrección."
          : errorMessage(error, "No se pudo guardar la corrección.");
        setEditorMessage(message, "error");
      } finally {
        if (elements.editorDialog.open) setEditorBusy(false);
      }
    }

    function reservePrintWindow() {
      const popup = window.open("", "_blank", "popup=yes,width=420,height=720");
      if (!popup) return null;
      popup.document.open();
      popup.document.write('<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Preparando ticket</title></head><body style="margin:0;padding:32px;text-align:center;color:#24313d;font-family:system-ui,sans-serif"><strong>Preparando ticket…</strong><p>La ventana de impresión se abrirá en un momento.</p></body></html>');
      popup.document.close();
      return popup;
    }

    function closePrintWindow(popup) {
      try { popup?.close(); } catch { /* La ventana pudo cerrarse mientras cargaba. */ }
    }

    async function reprintTicket(ticketId, button) {
      if (!ticketId || state.printing.has(ticketId)) return;
      const popup = reservePrintWindow();
      if (!popup) {
        setMessage("El navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes e inténtalo nuevamente.", "error");
        return;
      }

      const originalMarkup = button.innerHTML;
      state.printing.add(ticketId);
      button.disabled = true;
      button.textContent = "Preparando…";
      setMessage("Cargando la versión más reciente del ticket para imprimir…");
      try {
        const [response] = await Promise.all([
          apiRequest(`${apiBase}/tickets/${ticketId}`),
          ensureCatalog().catch(() => null),
        ]);
        if (popup.closed) throw new Error("La ventana de impresión se cerró antes de terminar la carga.");
        const ticket = ticketFromResponse(response);
        const ticketMessage = Object.prototype.hasOwnProperty.call(ticket, "ticket_message")
          ? ticket.ticket_message
          : state.catalog.ticket_message;
        printProductDispatchTicket(ticket, {
          currency: ticket.currency || state.catalog.currency || state.currency,
          productTicketTitle: ticket.product_ticket_title || state.catalog.product_ticket_title,
          ticketMessage,
          timezone: ticket.branch?.timezone || state.catalog.branch?.timezone,
          printWindow: popup,
        });
        setMessage(`Ticket ${ticket.code || ticket.codigo || "seleccionado"} enviado a impresión.`, "success");
      } catch (error) {
        console.error(error);
        closePrintWindow(popup);
        setMessage(errorMessage(error, "No se pudo reimprimir el ticket."), "error");
      } finally {
        state.printing.delete(ticketId);
        if (button.isConnected) {
          button.disabled = false;
          button.innerHTML = originalMarkup;
        }
      }
    }

    elements.filters.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!validateDateRange() || !elements.filters.reportValidity()) return;
      void loadTickets({ page: 1 });
    });

    [elements.dateFrom, elements.dateTo].forEach((input) => {
      input.addEventListener("input", validateDateRange);
    });

    elements.perPage.addEventListener("change", () => {
      if (!state.loading && validateDateRange()) void loadTickets({ page: 1 });
    });

    elements.filterReset.addEventListener("click", () => {
      setFilterValues({ search: "", date_from: "", date_to: "", per_page: 10 });
      validateDateRange();
      void loadTickets({ page: 1 });
    });

    elements.pagePrevious.addEventListener("click", () => {
      const currentPage = Number(state.data?.pagination?.current_page || 1);
      if (currentPage > 1) void loadTickets({ page: currentPage - 1, filters: state.lastRequest });
    });

    elements.pageNext.addEventListener("click", () => {
      const currentPage = Number(state.data?.pagination?.current_page || 1);
      const lastPage = Number(state.data?.pagination?.last_page || 1);
      if (currentPage < lastPage) void loadTickets({ page: currentPage + 1, filters: state.lastRequest });
    });

    elements.list.addEventListener("click", (event) => {
      const retry = event.target.closest("[data-pdt-retry-list]");
      if (retry) {
        const request = state.lastRequest || initialFilters();
        void loadTickets({ page: request.page || 1, filters: request });
        return;
      }
      const edit = event.target.closest("[data-pdt-edit-ticket]");
      if (edit) {
        void loadEditor(positiveInteger(edit.dataset.pdtEditTicket), edit);
        return;
      }
      const print = event.target.closest("[data-pdt-print-ticket]");
      if (print) void reprintTicket(positiveInteger(print.dataset.pdtPrintTicket), print);
    });

    elements.addLine.addEventListener("click", () => {
      if (!state.editorTicket || state.saving) return;
      if (state.editorTicket.weighings.length >= 100) {
        setEditorMessage("Un ticket puede contener como máximo 100 pesadas.", "error");
        return;
      }
      state.editorTicket.weighings.push(newEditorLine());
      renderEditorLines();
      const lastRow = elements.editorLines.lastElementChild;
      lastRow?.querySelector("[data-pdt-line-field='product_id']")?.focus();
      setEditorMessage("Pesada nueva agregada. Completa el peso y revisa sus valores.");
    });

    elements.editorLines.addEventListener("click", (event) => {
      const remove = event.target.closest("[data-pdt-remove-line]");
      if (!remove || !state.editorTicket || state.saving) return;
      state.editorTicket.weighings = state.editorTicket.weighings.filter(
        (line) => line.local_key !== remove.dataset.pdtRemoveLine,
      );
      renderEditorLines();
      setEditorMessage("Pesada quitada del borrador. El cambio se aplicará al guardar.");
    });

    elements.editorLines.addEventListener("input", (event) => {
      const field = event.target.dataset.pdtLineField;
      const row = event.target.closest("[data-pdt-line-key]");
      const line = row ? editorLine(row.dataset.pdtLineKey) : null;
      if (!field || !line || field === "product_id" || field === "variation_id") return;
      line[field] = field === "price_mode" ? normalizePriceMode(event.target.value) : event.target.value;
      event.target.setCustomValidity("");
      event.target.removeAttribute("aria-invalid");
      updateLineCalculation(row, line);
      updateTimeChangeWarning();
    });

    elements.editorLines.addEventListener("change", (event) => {
      const field = event.target.dataset.pdtLineField;
      const row = event.target.closest("[data-pdt-line-key]");
      const line = row ? editorLine(row.dataset.pdtLineKey) : null;
      if (!field || !line) return;
      if (field === "product_id") {
        line.product_id = positiveInteger(event.target.value) || null;
        line.variation_id = null;
        applyCatalogDefaults(line);
        renderEditorLines();
        return;
      }
      if (field === "variation_id") {
        line.variation_id = positiveInteger(event.target.value) || null;
        applyCatalogDefaults(line);
        renderEditorLines();
        return;
      }
      line[field] = field === "price_mode" ? normalizePriceMode(event.target.value) : event.target.value;
      updateLineCalculation(row, line);
    });

    elements.registeredAt.addEventListener("input", () => {
      elements.registeredAt.setCustomValidity("");
      elements.registeredAt.removeAttribute("aria-invalid");
      updateTimeChangeWarning();
    });

    elements.acknowledgeTimeChange.addEventListener("change", () => {
      elements.acknowledgeTimeChange.setCustomValidity("");
      elements.acknowledgeTimeChange.removeAttribute("aria-invalid");
    });

    elements.editorForm.addEventListener("submit", saveEditor);

    elements.editorDialog.addEventListener("click", (event) => {
      if (event.target === elements.editorDialog) closeEditorDialog();
      if (event.target.closest("[data-pdt-close-editor]")) closeEditorDialog();
      if (event.target.closest("[data-pdt-retry-editor]")) {
        const ticketId = state.editorTicketId;
        state.editorLoading = false;
        void loadEditor(ticketId);
      }
    });

    elements.editorDialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      closeEditorDialog();
    });

    elements.editorDialog.addEventListener("close", () => {
      state.editorRevision += 1;
      state.editorLoading = false;
      state.editorTicket = null;
      state.editorBaseline = null;
      const returnFocus = state.returnFocus;
      state.returnFocus = null;
      window.setTimeout(() => returnFocus?.focus?.(), 0);
    });

    window.addEventListener("auth:expired", () => {
      setMessage("Tu sesión venció. Inicia sesión nuevamente para consultar o editar tickets.", "error");
      if (elements.editorDialog.open) setEditorMessage("Tu sesión venció. Inicia sesión nuevamente antes de guardar.", "error");
    });

    window.addEventListener("beforeunload", (event) => {
      if (!hasUnsavedEditorChanges()) return;
      event.preventDefault();
      event.returnValue = "";
    });

    const initial = initialFilters();
    setFilterValues(initial);
    validateDateRange();
    void ensureCatalog().catch((error) => console.warn("No se pudo precargar el catálogo de edición.", error));
    void loadTickets({ page: initial.page, filters: initial });
  });
}

if (typeof document !== "undefined") {
  Promise.resolve(mountProductDispatchTickets()).catch((error) => {
    console.error(error);
    const list = document.querySelector("#pdtTicketList");
    const message = document.querySelector("#pdtMessage");
    if (list) {
      list.setAttribute("aria-busy", "false");
      list.innerHTML = '<div class="pdt-state card is-error"><strong>No pudimos iniciar esta vista</strong><span>Recarga la página para intentarlo nuevamente.</span></div>';
    }
    if (message) {
      message.textContent = "No se pudo iniciar la consulta de tickets.";
      message.classList.add("is-error");
    }
  });
}
