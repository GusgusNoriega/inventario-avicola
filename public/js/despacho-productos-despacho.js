import { apiRequest } from "./api-client.js";
import { RetailScaleController } from "./despacho-minorista-balanza.js";
import { bindIntegerKeypad } from "./despacho-productos-numeric-keypad.js";
import {
  PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS,
  PRODUCT_DISPATCH_SCALE_CODE,
  buildDraftCollection,
  buildTicketPayload,
  calculateDraft,
  calculateLine,
  createEmptyDraft,
  createUuid,
  currencyLabel,
  effectiveProduct,
  escapeHtml,
  formatMoney,
  formatWeight,
  itemKey,
  normalizeCatalog,
  normalizeWastePresets,
  priceModeLabel,
  productInitial,
  roundTo,
  searchClients
} from "./despacho-productos-despacho-utils.js";
import { printProductDispatchTicket } from "./despacho-productos-ticket-printer.js";
import {
  buildTypographyPresetValues,
  defaultTypographyValues,
  flattenTypographyControls,
  normalizeTypographyValue,
  parseTypographyPreferences,
  sanitizeTypographyValues,
  serializeTypographyPreferences,
  typographyChangedCount,
  typographyValuesEqual
} from "./despacho-productos-typography.js";

const station = document.querySelector("#productDispatchStation");
const apiBase = station?.dataset.apiBase || "/despacho-productos";
const currentUserId = station?.dataset.userId || "anonymous";
const APP_SCALE_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
const viewStorageKey = `sistema-pollos-product-dispatch-view-v1-user-${currentUserId}`;
const typographyStorageKey = `sistema-pollos-product-dispatch-typography-v1-user-${currentUserId}`;
const TYPOGRAPHY_GROUPS = [
  {
    id: "header",
    label: "Encabezado",
    description: "Marca, sucursal, reloj y accesos superiores.",
    controls: [
      { label: "Título principal", description: "Texto “Despacho de productos”.", variable: "--pdd-fs-header-title", defaultValue: 25, min: 18, max: 36, step: 1, target: ".pdd-brand h1" },
      { label: "Subtítulo y sucursal", description: "Nombre de la estación y nombre de la sucursal.", variable: "--pdd-fs-header-meta", defaultValue: 12, min: 9, max: 18, step: 0.5, target: ".pdd-brand p, .pdd-branch-meta span" },
      { label: "Reloj", description: "Hora mostrada en el encabezado.", variable: "--pdd-fs-header-clock", defaultValue: 18, min: 13, max: 30, step: 1, target: ".pdd-branch-meta strong" },
      { label: "Botones superiores", description: "Balanza, Configuración, Módulo y estado de conexión.", variable: "--pdd-fs-header-actions", defaultValue: 13, min: 10, max: 22, step: 1, target: ".pdd-status-chip, .pdd-icon-action, .pdd-menu-action" }
    ]
  },
  {
    id: "sections",
    label: "Títulos de secciones",
    description: "Encabezados del producto y configuración de la pesada.",
    controls: [
      { label: "Etiquetas superiores", description: "Textos pequeños en mayúsculas sobre cada sección.", variable: "--pdd-fs-section-label", defaultValue: 11.5, min: 9, max: 18, step: 0.5, target: ".pdd-panel-heading span, .pdd-config-head span" },
      { label: "Títulos y producto seleccionado", description: "Nombre del producto y título de configuración.", variable: "--pdd-fs-section-title", defaultValue: 16, min: 12, max: 26, step: 1, target: ".pdd-panel-heading strong, .pdd-config-head strong" },
      { label: "Acciones de sección", description: "Elegir producto y cambiar precios del ticket.", variable: "--pdd-fs-section-action", defaultValue: 13, min: 10, max: 22, step: 1, target: ".pdd-choose-product, .pdd-link-button" }
    ]
  },
  {
    id: "scale",
    label: "Producto y balanza",
    description: "Imagen, lectura principal y captura del peso.",
    open: true,
    controls: [
      { label: "Inicial del producto", description: "Letras grandes cuando el producto no tiene imagen.", variable: "--pdd-fs-product-initial", defaultValue: 37, min: 22, max: 54, step: 1, target: ".pdd-media-placeholder b" },
      { label: "Nombre bajo la imagen", description: "Nombre mostrado dentro del cuadro del producto.", variable: "--pdd-fs-product-name", defaultValue: 12, min: 9, max: 20, step: 0.5, target: ".pdd-media-placeholder small" },
      { label: "Estado de la lectura", description: "Origen de la lectura y estado de la balanza.", variable: "--pdd-fs-scale-meta", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-scale-reading-head" },
      { label: "Peso principal", description: "Número grande de la balanza en tiempo real.", variable: "--pdd-fs-scale-weight", defaultValue: 70, min: 32, max: 96, step: 2, target: ".pdd-live-weight" },
      { label: "Unidad kg", description: "Unidad que acompaña el peso principal.", variable: "--pdd-fs-scale-unit", defaultValue: 16, min: 10, max: 28, step: 1, target: ".pdd-live-weight small" },
      { label: "Botones de captura", description: "Peso manual y Capturar peso.", variable: "--pdd-fs-scale-actions", defaultValue: 12, min: 10, max: 22, step: 1, target: ".pdd-secondary-touch, .pdd-capture-touch" }
    ]
  },
  {
    id: "fields",
    label: "Campos y peso neto",
    description: "Cantidad, precio, tara, merma y total estimado.",
    controls: [
      { label: "Etiquetas de campos", description: "Cantidad, precio, tara y merma.", variable: "--pdd-fs-field-label", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-fields-grid label > span:first-child, .pdd-waste-unit-control > span:first-child" },
      { label: "Valores de campos", description: "Cantidad, precio, tara y merma.", variable: "--pdd-fs-field-value", defaultValue: 16, min: 12, max: 28, step: 1, target: ".pdd-fields-grid label > strong, .pdd-touch-number-input input" },
      { label: "Ayudas de campos", description: "Forma de cobro y validaciones.", variable: "--pdd-fs-field-help", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-fields-grid small, .pdd-waste-hint" },
      { label: "Unidades", description: "Unidades junto a los valores táctiles.", variable: "--pdd-fs-field-unit", defaultValue: 12, min: 9, max: 20, step: 1, target: ".pdd-touch-number-input b" },
      { label: "Etiqueta de peso neto", description: "Texto “Peso neto estimado”.", variable: "--pdd-fs-net-label", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-net-preview > span" },
      { label: "Valor de peso neto", description: "Kilogramos netos estimados.", variable: "--pdd-fs-net-value", defaultValue: 16, min: 12, max: 30, step: 1, target: ".pdd-net-preview strong" },
      { label: "Total estimado", description: "Importe estimado debajo del peso neto.", variable: "--pdd-fs-net-help", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-net-preview small" }
    ]
  },
  {
    id: "variations",
    label: "Variaciones",
    description: "Slider de presentaciones del producto.",
    controls: [
      { label: "Título Variaciones", description: "Encabezado de la sección de variaciones.", variable: "--pdd-fs-variation-heading", defaultValue: 12, min: 9, max: 20, step: 0.5, target: ".pdd-variations-heading span" },
      { label: "Ayuda del slider", description: "Indicación para deslizar y estado sin producto.", variable: "--pdd-fs-variation-hint", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-variations-heading small, .pdd-variation-empty" },
      { label: "Nombre de la variación", description: "Nombre principal de cada presentación.", variable: "--pdd-fs-variation-name", defaultValue: 12, min: 10, max: 22, step: 0.5, target: ".pdd-variation-option b" },
      { label: "Precio y detalle", description: "Precio o información secundaria de la variación.", variable: "--pdd-fs-variation-detail", defaultValue: 10, min: 9, max: 18, step: 0.5, target: ".pdd-variation-option small" }
    ]
  },
  {
    id: "lists",
    label: "Listas de distribución",
    description: "Encabezados, clientes y totales de las ocho listas.",
    controls: [
      { label: "Etiqueta Distribución", description: "Texto superior de distribución de tickets.", variable: "--pdd-fs-lists-label", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-lists-heading span" },
      { label: "Título de las listas", description: "Instrucción principal sobre las ocho listas.", variable: "--pdd-fs-lists-title", defaultValue: 15, min: 11, max: 26, step: 1, target: ".pdd-lists-heading strong" },
      { label: "Ayuda horizontal", description: "Indicación para deslizar entre las listas.", variable: "--pdd-fs-lists-help", defaultValue: 11, min: 9, max: 18, step: 0.5, target: ".pdd-lists-heading small" },
      { label: "Número de lista", description: "Número grande en la cabecera de cada lista.", variable: "--pdd-fs-list-number", defaultValue: 18, min: 14, max: 32, step: 1, target: ".pdd-list-number" },
      { label: "Cliente o nombre de lista", description: "Venta al público o cliente asignado.", variable: "--pdd-fs-list-name", defaultValue: 12, min: 10, max: 22, step: 0.5, target: ".pdd-list-card-head b, .pdd-list-empty b" },
      { label: "Detalle y contador", description: "Cliente opcional, lista vacía y cantidad de pesadas.", variable: "--pdd-fs-list-detail", defaultValue: 10, min: 9, max: 18, step: 0.5, target: ".pdd-list-card-head small, .pdd-list-count, .pdd-list-empty span" },
      { label: "Etiquetas de totales", description: "Neto y Total al pie de cada lista.", variable: "--pdd-fs-list-total", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-list-totals" },
      { label: "Importe de la lista", description: "Monto resaltado al pie de cada lista.", variable: "--pdd-fs-list-amount", defaultValue: 13, min: 10, max: 24, step: 1, target: ".pdd-list-total-amount" }
    ]
  },
  {
    id: "weighings",
    label: "Pesadas registradas",
    description: "Cada renglón agregado dentro de una lista.",
    controls: [
      { label: "Índice de la pesada", description: "Número pequeño al inicio del renglón.", variable: "--pdd-fs-weighing-index", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-weighing-row > i" },
      { label: "Nombre del producto pesado", description: "Producto y variación dentro de la lista.", variable: "--pdd-fs-weighing-name", defaultValue: 11, min: 9, max: 21, step: 0.5, target: ".pdd-weighing-row b" },
      { label: "Detalle de la pesada", description: "Cantidad, peso leído, merma y peso neto.", variable: "--pdd-fs-weighing-detail", defaultValue: 10, min: 9, max: 18, step: 0.5, target: ".pdd-weighing-row small" },
      { label: "Importe de la pesada", description: "Monto mostrado al lado derecho del renglón.", variable: "--pdd-fs-weighing-amount", defaultValue: 11, min: 9, max: 21, step: 0.5, target: ".pdd-weighing-row > strong" }
    ]
  },
  {
    id: "ticket",
    label: "Ticket y acciones",
    description: "Lista activa, botones laterales y total del ticket.",
    controls: [
      { label: "Títulos de acciones", description: "Asignar cliente, Cambiar precio, Guardar e imprimir.", variable: "--pdd-fs-action-title", defaultValue: 12, min: 10, max: 22, step: 0.5, target: ".pdd-rail-action b, .pdd-save-button b, .pdd-active-list-badge span" },
      { label: "Detalle de acciones", description: "Explicación secundaria debajo de cada botón.", variable: "--pdd-fs-action-detail", defaultValue: 10, min: 9, max: 18, step: 0.5, target: ".pdd-rail-action small, .pdd-save-button small" },
      { label: "Número de lista activa", description: "Número superior de la barra lateral del ticket.", variable: "--pdd-fs-active-list", defaultValue: 22, min: 15, max: 36, step: 1, target: ".pdd-active-list-badge strong" },
      { label: "Etiqueta Total de la lista", description: "Texto pequeño sobre el monto principal.", variable: "--pdd-fs-ticket-label", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-ticket-total span" },
      { label: "Total principal del ticket", description: "Monto grande del ticket activo.", variable: "--pdd-fs-ticket-total", defaultValue: 18, min: 14, max: 38, step: 1, target: ".pdd-ticket-total strong" },
      { label: "Resumen del ticket", description: "Cantidad de pesadas y kilogramos netos.", variable: "--pdd-fs-ticket-detail", defaultValue: 10, min: 9, max: 20, step: 0.5, target: ".pdd-ticket-total small" }
    ]
  },
  {
    id: "footer",
    label: "Estado y avisos",
    description: "Mensajes inferiores y confirmación de tickets.",
    controls: [
      { label: "Mensaje de estado", description: "Mensaje operativo en la barra inferior.", variable: "--pdd-fs-footer-message", defaultValue: 12, min: 9, max: 22, step: 0.5, target: ".pdd-statusbar p" },
      { label: "Resumen inferior", description: "Pesadas, unidades, merma y peso neto.", variable: "--pdd-fs-footer-summary", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-footer-summary" },
      { label: "Título del aviso", description: "Título mostrado cuando se guarda un ticket.", variable: "--pdd-fs-toast-title", defaultValue: 13, min: 10, max: 24, step: 1, target: ".pdd-ticket-toast strong" },
      { label: "Detalle del aviso", description: "Información y botón de reintento de impresión.", variable: "--pdd-fs-toast-detail", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-ticket-toast span, #pddRetryPrint" }
    ]
  },
  {
    id: "dialogs",
    label: "Ventanas emergentes",
    description: "Producto, cliente, precio, edición, balanza y peso manual.",
    controls: [
      { label: "Subtítulos de ventanas", description: "Texto pequeño superior de cada ventana.", variable: "--pdd-fs-dialog-eyebrow", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-dialog-head p" },
      { label: "Títulos de ventanas", description: "Título principal de todos los popups.", variable: "--pdd-fs-dialog-title", defaultValue: 22, min: 16, max: 38, step: 1, target: ".pdd-dialog-head h2" },
      { label: "Texto explicativo", description: "Instrucciones, mensajes y lectura técnica.", variable: "--pdd-fs-dialog-body", defaultValue: 12, min: 9, max: 22, step: 0.5, target: ".pdd-dialog-intro, .pdd-scale-message, .pdd-raw-reading" },
      { label: "Buscadores", description: "Texto escrito para buscar productos o clientes.", variable: "--pdd-fs-dialog-search", defaultValue: 16, min: 12, max: 28, step: 1, target: ".pdd-dialog-search input" },
      { label: "Etiquetas de campos", description: "Nombres de los datos editables en ventanas.", variable: "--pdd-fs-dialog-label", defaultValue: 11, min: 9, max: 20, step: 0.5, target: ".pdd-edit-grid label > span, .pdd-price-row label > span" },
      { label: "Valores de campos", description: "Números, listas y precios editables.", variable: "--pdd-fs-dialog-field", defaultValue: 16, min: 12, max: 28, step: 1, target: ".pdd-edit-grid input, .pdd-edit-grid select, .pdd-price-row input" },
      { label: "Nombres de tarjetas", description: "Productos, clientes, precios y dispositivos.", variable: "--pdd-fs-dialog-item-title", defaultValue: 13, min: 10, max: 24, step: 0.5, target: ".pdd-product-option b, .pdd-client-option b, .pdd-price-row b" },
      { label: "Detalles de tarjetas", description: "Precio, documento y textos secundarios.", variable: "--pdd-fs-dialog-item-detail", defaultValue: 10, min: 9, max: 20, step: 0.5, target: ".pdd-product-option small, .pdd-client-option small, .pdd-price-row small" },
      { label: "Botones de ventanas", description: "Cancelar, guardar, aplicar y confirmar.", variable: "--pdd-fs-dialog-actions", defaultValue: 14, min: 10, max: 24, step: 1, target: ".pdd-dialog-actions button" },
      { label: "Peso manual grande", description: "Número principal al ingresar un peso manual.", variable: "--pdd-fs-manual-weight", defaultValue: 32, min: 22, max: 56, step: 2, target: ".pdd-big-number-input input" },
      { label: "Resumen de edición", description: "Origen, peso neto y total al editar una pesada.", variable: "--pdd-fs-edit-summary", defaultValue: 12, min: 9, max: 22, step: 0.5, target: ".pdd-edit-summary" }
    ]
  }
];
const TYPOGRAPHY_CONTROLS = flattenTypographyControls(TYPOGRAPHY_GROUPS);
const TYPOGRAPHY_PRESETS = {
  compact: { label: "Compacta", factor: 0.9 },
  standard: { label: "Estándar", factor: 1 },
  large: { label: "Grande", factor: 1.15 },
  accessible: { label: "Alta legibilidad", factor: 1.22, readableFloor: 13 }
};

const elements = {
  zoomSurface: document.querySelector("#pddZoomSurface"),
  branchName: document.querySelector("#pddBranchName"),
  clock: document.querySelector("#pddClock"),
  scaleStatus: document.querySelector("#pddScaleStatus"),
  openScaleSettings: document.querySelector("#pddOpenScaleSettings"),
  openViewSettings: document.querySelector("#pddOpenViewSettings"),
  selectedName: document.querySelector("#pddSelectedName"),
  chooseProduct: document.querySelector("#pddChooseProduct"),
  productMedia: document.querySelector("#pddProductMedia"),
  weightSource: document.querySelector("#pddWeightSource"),
  readingState: document.querySelector("#pddReadingState"),
  liveWeight: document.querySelector("#pddLiveWeight"),
  manualWeight: document.querySelector("#pddManualWeight"),
  clearManualWeight: document.querySelector("#pddClearManualWeight"),
  captureWeight: document.querySelector("#pddCaptureWeight"),
  selectedVariantLabel: document.querySelector("#pddSelectedVariantLabel"),
  changePrice: document.querySelector("#pddChangePrice"),
  quantity: document.querySelector("#pddQuantity"),
  unitPrice: document.querySelector("#pddUnitPrice"),
  priceMode: document.querySelector("#pddPriceMode"),
  tare: document.querySelector("#pddTare"),
  wastePerUnit: document.querySelector("#pddWastePerUnit"),
  wasteTotal: document.querySelector("#pddWasteTotal"),
  wastePresets: document.querySelector("#pddWastePresets"),
  wasteHint: document.querySelector("#pddWasteHint"),
  netPreview: document.querySelector("#pddNetPreview"),
  amountPreview: document.querySelector("#pddAmountPreview"),
  variations: document.querySelector("#pddVariations"),
  lists: document.querySelector("#pddLists"),
  activeList: document.querySelector("#pddActiveList"),
  assignClient: document.querySelector("#pddAssignClient"),
  clientActionLabel: document.querySelector("#pddClientActionLabel"),
  clientActionDetail: document.querySelector("#pddClientActionDetail"),
  railChangePrice: document.querySelector("#pddRailChangePrice"),
  ticketTotal: document.querySelector("#pddTicketTotal"),
  ticketSummary: document.querySelector("#pddTicketSummary"),
  save: document.querySelector("#pddSave"),
  savePrint: document.querySelector("#pddSavePrint"),
  message: document.querySelector("#pddMessage"),
  footerWeighings: document.querySelector("#pddFooterWeighings"),
  footerQuantity: document.querySelector("#pddFooterQuantity"),
  footerWaste: document.querySelector("#pddFooterWaste"),
  footerNet: document.querySelector("#pddFooterNet"),
  lastTicket: document.querySelector("#pddLastTicket"),
  lastTicketTitle: document.querySelector("#pddLastTicketTitle"),
  lastTicketDetail: document.querySelector("#pddLastTicketDetail"),
  retryPrint: document.querySelector("#pddRetryPrint"),
  dismissTicket: document.querySelector("#pddDismissTicket"),
  productDialog: document.querySelector("#pddProductDialog"),
  productSearch: document.querySelector("#pddProductSearch"),
  productGrid: document.querySelector("#pddProductGrid"),
  manualDialog: document.querySelector("#pddManualDialog"),
  manualForm: document.querySelector("#pddManualForm"),
  manualInput: document.querySelector("#pddManualInput"),
  numericKeypad: document.querySelector("#pddNumericKeypad"),
  numericKeypadTitle: document.querySelector("#pddNumericKeypadTitle"),
  numericKeypadValueLabel: document.querySelector("#pddNumericKeypadValueLabel"),
  numericKeypadValue: document.querySelector("#pddNumericKeypadValue"),
  numericKeypadMessage: document.querySelector("#pddNumericKeypadMessage"),
  numericKeypadClear: document.querySelector("#pddNumericKeypadClear"),
  numericKeypadConfirm: document.querySelector("#pddNumericKeypadConfirm"),
  clientDialog: document.querySelector("#pddClientDialog"),
  publicSale: document.querySelector("#pddPublicSale"),
  clientSearch: document.querySelector("#pddClientSearch"),
  clientList: document.querySelector("#pddClientList"),
  editDialog: document.querySelector("#pddEditDialog"),
  editForm: document.querySelector("#pddEditForm"),
  editProduct: document.querySelector("#pddEditProduct"),
  editVariation: document.querySelector("#pddEditVariation"),
  editQuantity: document.querySelector("#pddEditQuantity"),
  editWeight: document.querySelector("#pddEditWeight"),
  editWastePerUnit: document.querySelector("#pddEditWastePerUnit"),
  editWasteTotal: document.querySelector("#pddEditWasteTotal"),
  editTare: document.querySelector("#pddEditTare"),
  editPrice: document.querySelector("#pddEditPrice"),
  editSource: document.querySelector("#pddEditSource"),
  editCalculated: document.querySelector("#pddEditCalculated"),
  deleteWeighing: document.querySelector("#pddDeleteWeighing"),
  priceDialog: document.querySelector("#pddPriceDialog"),
  priceForm: document.querySelector("#pddPriceForm"),
  priceRows: document.querySelector("#pddPriceRows"),
  scaleDialog: document.querySelector("#pddScaleDialog"),
  scaleForm: document.querySelector("#pddScaleForm"),
  scaleDialogDot: document.querySelector("#pddScaleDialogDot"),
  scaleDialogStatus: document.querySelector("#pddScaleDialogStatus"),
  scaleDevice: document.querySelector("#pddScaleDevice"),
  connectBle: document.querySelector("#pddConnectBle"),
  connectSerial: document.querySelector("#pddConnectSerial"),
  baudRate: document.querySelector("#pddBaudRate"),
  dataBits: document.querySelector("#pddDataBits"),
  stopBits: document.querySelector("#pddStopBits"),
  parity: document.querySelector("#pddParity"),
  rawReading: document.querySelector("#pddRawReading"),
  scaleMessage: document.querySelector("#pddScaleMessage"),
  disconnectScale: document.querySelector("#pddDisconnectScale"),
  viewDialog: document.querySelector("#pddViewDialog"),
  zoomOut: document.querySelector("#pddZoomOut"),
  zoomValue: document.querySelector("#pddZoomValue"),
  zoomIn: document.querySelector("#pddZoomIn"),
  zoomReset: document.querySelector("#pddZoomReset"),
  openTypography: document.querySelector("#pddOpenTypography"),
  wastePresetForm: document.querySelector("#pddWastePresetForm"),
  wastePresetInputs: [
    document.querySelector("#pddWastePreset1"),
    document.querySelector("#pddWastePreset2"),
    document.querySelector("#pddWastePreset3")
  ],
  saveWastePresets: document.querySelector("#pddSaveWastePresets"),
  wastePresetStatus: document.querySelector("#pddWastePresetStatus"),
  typographySummary: document.querySelector("#pddTypographySummary"),
  typographyPanel: document.querySelector("#pddTypographyPanel"),
  typographyClose: document.querySelector("#pddTypographyClose"),
  typographyDone: document.querySelector("#pddTypographyDone"),
  typographyProfile: document.querySelector("#pddTypographyProfile"),
  typographySaveStatus: document.querySelector("#pddTypographySaveStatus"),
  typographyPreview: document.querySelector("#pddTypographyPreview"),
  typographySearch: document.querySelector("#pddTypographySearch"),
  typographyControls: document.querySelector("#pddTypographyControls"),
  typographyExpandAll: document.querySelector("#pddTypographyExpandAll"),
  typographyCollapseAll: document.querySelector("#pddTypographyCollapseAll"),
  typographyResetAll: document.querySelector("#pddTypographyResetAll")
};

const state = {
  catalog: normalizeCatalog(),
  drafts: buildDraftCollection(),
  activeIndex: 0,
  selectedProductId: null,
  selectedVariationId: null,
  wastePresetSaving: false,
  storageKey: null,
  loading: true,
  saving: false,
  editingLocalId: null,
  liveScale: {},
  pendingManualReading: null,
  captureBlockedUntil: 0,
  lastRaw: "",
  pendingPrintTicket: null,
  lastFocus: null,
  appScale: 100,
  typography: defaultTypographyValues(TYPOGRAPHY_GROUPS),
  typographyPreset: "standard",
  typographySaveTimer: null,
  typographyHideTimer: null,
  typographyHighlightTimer: null,
  typographyHighlighted: [],
  scale: null,
  numericKeypad: null
};

function activeDraft() {
  return state.drafts[state.activeIndex];
}

function selectedProduct() {
  return state.catalog.products.find((product) => product.id === Number(state.selectedProductId)) || null;
}

function selectedVariation(product = selectedProduct()) {
  return product?.variations.find((variation) => variation.id === Number(state.selectedVariationId)) || null;
}

function currentSelection() {
  return effectiveProduct(selectedProduct(), selectedVariation());
}

function createPendingManualReading(rawValue) {
  const weightKg = roundTo(Number(String(rawValue ?? "").trim().replace(",", ".")), 3);
  if (!Number.isFinite(weightKg) || weightKg <= 0) {
    throw new RangeError("La lectura manual debe ser un peso válido mayor que cero.");
  }

  const readingAt = new Date().toISOString();
  return {
    currentWeightKg: weightKg,
    updatedAt: readingAt,
    readingAt,
    readingSource: "manual",
    readingStatus: "stable",
    inputStatus: "stable",
    readingId: `manual-pending-${Date.now()}`,
    readingRaw: `MANUAL ${weightKg.toFixed(3)} kg`,
    lastRawValue: String(rawValue),
    isStable: true,
    isFresh: true,
    isCaptureReady: true
  };
}

function effectiveCaptureReading(scaleState = state.scale?.getState?.() || state.liveScale) {
  const physicalState = scaleState || {};
  return state.pendingManualReading
    ? { ...physicalState, ...state.pendingManualReading }
    : physicalState;
}

function blockCaptureBriefly(durationMs = 650) {
  state.captureBlockedUntil = Date.now() + durationMs;
  window.setTimeout(() => {
    if (Date.now() >= state.captureBlockedUntil) renderCapturePreview();
  }, durationMs + 25);
}

function clientById(id) {
  return state.catalog.clients.find((client) => client.id === Number(id)) || null;
}

function errorMessage(error) {
  const errors = error?.data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }
  return error?.message || "No se pudo completar la operación.";
}

function setMessage(message, tone = "") {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", tone === "error");
  elements.message.classList.toggle("is-success", tone === "success");

  document.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  const openDialog = document.querySelector("dialog.pdd-dialog[open]");
  const dialogContent = openDialog?.querySelector(":scope > form, :scope > section");
  if (tone === "error" && dialogContent) {
    const notice = document.createElement("p");
    notice.className = "pdd-dialog-message";
    notice.setAttribute("role", "alert");
    notice.textContent = message;
    const actions = dialogContent.querySelector(".pdd-dialog-actions");
    dialogContent.insertBefore(notice, actions || null);
  }
}

function openDialog(dialog, focusTarget = null) {
  if (!dialog) return;
  dialog.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  state.lastFocus = document.activeElement;
  if (!dialog.open) dialog.showModal();
  window.setTimeout(() => (focusTarget || dialog.querySelector("input,button,select"))?.focus(), 0);
}

function closeDialog(dialog) {
  dialog?.querySelectorAll(".pdd-dialog-message").forEach((notice) => notice.remove());
  if (dialog?.open) dialog.close();
}

function normalizeAppScale(value) {
  const numeric = Number(value);
  return APP_SCALE_LEVELS.includes(numeric) ? numeric : 100;
}

function storedAppScale() {
  try {
    const saved = JSON.parse(localStorage.getItem(viewStorageKey) || "null");
    return normalizeAppScale(saved?.scale ?? saved);
  } catch {
    return 100;
  }
}

function renderAppScale() {
  elements.zoomValue.textContent = `${state.appScale}%`;
  elements.zoomValue.value = `${state.appScale}%`;
  elements.zoomOut.disabled = state.appScale === APP_SCALE_LEVELS[0];
  elements.zoomIn.disabled = state.appScale === APP_SCALE_LEVELS.at(-1);
  elements.zoomReset.disabled = state.appScale === 100;
}

function applyAppScale(value, persist = true) {
  state.appScale = normalizeAppScale(value);
  elements.zoomSurface.style.zoom = String(state.appScale / 100);
  renderAppScale();

  if (!persist) return;
  try {
    localStorage.setItem(viewStorageKey, JSON.stringify({ version: 1, scale: state.appScale }));
  } catch {
    // El ajuste sigue funcionando durante esta visita aunque el navegador bloquee el almacenamiento.
  }
}

function stepAppScale(direction) {
  const currentIndex = Math.max(0, APP_SCALE_LEVELS.indexOf(state.appScale));
  const nextIndex = Math.max(0, Math.min(APP_SCALE_LEVELS.length - 1, currentIndex + direction));
  applyAppScale(APP_SCALE_LEVELS[nextIndex]);
}

function typographyControl(variable) {
  return TYPOGRAPHY_CONTROLS.find((control) => control.variable === variable) || null;
}

function typographyPresetValues(preset) {
  const options = TYPOGRAPHY_PRESETS[preset] || TYPOGRAPHY_PRESETS.standard;
  return buildTypographyPresetValues(TYPOGRAPHY_GROUPS, options);
}

function detectTypographyPreset() {
  return Object.keys(TYPOGRAPHY_PRESETS).find((preset) => (
    typographyValuesEqual(TYPOGRAPHY_GROUPS, state.typography, typographyPresetValues(preset))
  )) || "custom";
}

function setTypographySaveStatus(message, tone = "") {
  if (!elements.typographySaveStatus) return;
  elements.typographySaveStatus.textContent = message;
  elements.typographySaveStatus.classList.toggle("is-saving", tone === "saving");
  elements.typographySaveStatus.classList.toggle("is-session-only", tone === "session-only");
}

function storedTypographyPreferences(serialized = undefined) {
  try {
    const source = serialized === undefined ? localStorage.getItem(typographyStorageKey) : serialized;
    return parseTypographyPreferences(TYPOGRAPHY_GROUPS, source);
  } catch {
    return {
      valid: false,
      preset: "standard",
      values: defaultTypographyValues(TYPOGRAPHY_GROUPS)
    };
  }
}

function applyTypographyValue(variable, value) {
  const control = typographyControl(variable);
  if (!control) return;
  const normalized = normalizeTypographyValue(control, value);
  state.typography[variable] = normalized;
  document.documentElement.style.setProperty(variable, `${normalized}px`);
}

function applyTypographyValues(values) {
  state.typography = sanitizeTypographyValues(TYPOGRAPHY_GROUPS, values);
  TYPOGRAPHY_CONTROLS.forEach((control) => {
    document.documentElement.style.setProperty(control.variable, `${state.typography[control.variable]}px`);
  });
}

function updateTypographyOverview() {
  const preset = detectTypographyPreset();
  const changed = typographyChangedCount(TYPOGRAPHY_GROUPS, state.typography);
  state.typographyPreset = preset;

  if (elements.typographyProfile) {
    elements.typographyProfile.textContent = preset === "custom"
      ? "Perfil personalizado"
      : `Perfil ${TYPOGRAPHY_PRESETS[preset].label.toLocaleLowerCase("es")}`;
  }
  if (elements.typographySummary) {
    elements.typographySummary.textContent = changed === 0
      ? `Tamaño estándar · ${TYPOGRAPHY_CONTROLS.length} ajustes independientes disponibles.`
      : `${changed} de ${TYPOGRAPHY_CONTROLS.length} tamaños personalizados · guardado automático.`;
  }
  document.querySelectorAll("[data-pdd-typography-preset]").forEach((button) => {
    const active = button.dataset.pddTypographyPreset === preset;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-pressed", String(active));
  });
}

function persistTypographyNow() {
  if (state.typographySaveTimer) {
    window.clearTimeout(state.typographySaveTimer);
    state.typographySaveTimer = null;
  }
  state.typographyPreset = detectTypographyPreset();
  try {
    localStorage.setItem(
      typographyStorageKey,
      serializeTypographyPreferences(TYPOGRAPHY_GROUPS, state.typography, state.typographyPreset)
    );
    setTypographySaveStatus("Cambios guardados", "");
    return true;
  } catch {
    setTypographySaveStatus("Activos sólo durante esta visita", "session-only");
    return false;
  }
}

function scheduleTypographyPersistence() {
  if (state.typographySaveTimer) window.clearTimeout(state.typographySaveTimer);
  setTypographySaveStatus("Guardando cambios…", "saving");
  state.typographySaveTimer = window.setTimeout(persistTypographyNow, 180);
}

function normalizeSearchText(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es")
    .trim();
}

function typographyControlMarkup(control, groupIndex, controlIndex) {
  const value = state.typography[control.variable];
  const inputId = `pddTypographyRange-${groupIndex}-${controlIndex}`;
  const numberId = `pddTypographyNumber-${groupIndex}-${controlIndex}`;
  const isSmall = value < 10;

  return `
    <article class="pdd-typography-control ${isSmall ? "is-small" : ""}" data-pdd-typography-row="${escapeHtml(control.variable)}">
      <div class="pdd-typography-control-head">
        <label for="${inputId}">${escapeHtml(control.label)}</label>
        <output class="pdd-typography-current" data-pdd-typography-current="${escapeHtml(control.variable)}">${value}px</output>
        <small>${escapeHtml(control.description)}</small>
        <span class="pdd-typography-warning">Tamaño muy pequeño: comprueba que siga siendo legible.</span>
      </div>
      <div class="pdd-typography-inputs">
        <button type="button" data-pdd-typography-step="-1" data-pdd-typography-variable="${escapeHtml(control.variable)}" aria-label="Disminuir ${escapeHtml(control.label)}">−</button>
        <input id="${inputId}" type="range" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" data-pdd-typography-range="${escapeHtml(control.variable)}" aria-label="${escapeHtml(control.label)}" aria-valuetext="${value} píxeles">
        <label class="pdd-typography-number" for="${numberId}">
          <input id="${numberId}" type="number" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" inputmode="decimal" data-pdd-typography-number="${escapeHtml(control.variable)}" aria-label="Valor exacto de ${escapeHtml(control.label)} en píxeles">
          <span>px</span>
        </label>
        <button type="button" data-pdd-typography-step="1" data-pdd-typography-variable="${escapeHtml(control.variable)}" aria-label="Aumentar ${escapeHtml(control.label)}">＋</button>
        <button class="pdd-typography-reset-one" type="button" data-pdd-typography-reset="${escapeHtml(control.variable)}" aria-label="Restablecer ${escapeHtml(control.label)}" title="Restablecer este tamaño">↺</button>
      </div>
    </article>
  `;
}

function renderTypographyControls(search = elements.typographySearch?.value || "") {
  if (!elements.typographyControls) return;
  const query = normalizeSearchText(search);
  const groups = TYPOGRAPHY_GROUPS.map((group, groupIndex) => {
    const groupMatches = normalizeSearchText(`${group.label} ${group.description}`).includes(query);
    const controls = query && !groupMatches
      ? group.controls.filter((control) => normalizeSearchText(`${control.label} ${control.description}`).includes(query))
      : group.controls;
    if (!controls.length) return "";

    return `
      <details class="pdd-typography-group" data-pdd-typography-group="${escapeHtml(group.id)}" ${query || group.open ? "open" : ""}>
        <summary>
          <span><b>${escapeHtml(group.label)}</b><small>${escapeHtml(group.description)} · ${controls.length} ${controls.length === 1 ? "ajuste" : "ajustes"}</small></span>
        </summary>
        <div class="pdd-typography-group-body">
          <button class="pdd-typography-reset-group" type="button" data-pdd-typography-reset-group="${escapeHtml(group.id)}">Restablecer este grupo</button>
          ${controls.map((control) => typographyControlMarkup(control, groupIndex, group.controls.indexOf(control))).join("")}
        </div>
      </details>
    `;
  }).join("");

  elements.typographyControls.innerHTML = groups || `
    <div class="pdd-typography-empty">
      <strong>No encontramos ese ajuste</strong>
      <span>Prueba con peso, lista, título, botón, total o ventana.</span>
    </div>
  `;
}

function syncTypographyInputs(variable = null) {
  const controls = variable ? [typographyControl(variable)].filter(Boolean) : TYPOGRAPHY_CONTROLS;
  controls.forEach((control) => {
    const value = state.typography[control.variable];
    elements.typographyControls?.querySelectorAll(`[data-pdd-typography-range="${control.variable}"]`).forEach((input) => {
      input.value = value;
      input.setAttribute("aria-valuetext", `${value} píxeles`);
    });
    elements.typographyControls?.querySelectorAll(`[data-pdd-typography-number="${control.variable}"]`).forEach((input) => {
      input.value = value;
    });
    elements.typographyControls?.querySelectorAll(`[data-pdd-typography-current="${control.variable}"]`).forEach((output) => {
      output.textContent = `${value}px`;
    });
    elements.typographyControls?.querySelectorAll(`[data-pdd-typography-row="${control.variable}"]`).forEach((row) => {
      row.classList.toggle("is-small", value < 10);
    });
  });
}

function updateTypographyPreview(control, value) {
  if (!elements.typographyPreview || !control) return;
  elements.typographyPreview.style.setProperty("--pdd-typography-preview-size", `${value}px`);
  elements.typographyPreview.querySelector("span").textContent = `Vista: ${control.label}`;
  elements.typographyPreview.querySelector("strong").textContent = control.variable.includes("ticket-total")
    ? "S/ 98.70"
    : control.variable.includes("weight") || control.variable.includes("net")
      ? "12.450 kg"
      : "Aa 123.45";
  elements.typographyPreview.querySelector("small").textContent = control.description;
}

function highlightTypographyTarget(control) {
  state.typographyHighlighted.forEach((element) => element.classList.remove("pdd-typography-target-highlight"));
  state.typographyHighlighted = [];
  if (state.typographyHighlightTimer) window.clearTimeout(state.typographyHighlightTimer);
  if (!control?.target) return;

  state.typographyHighlighted = Array.from(document.querySelectorAll(control.target))
    .filter((element) => !elements.typographyPanel?.contains(element))
    .slice(0, 24);
  state.typographyHighlighted.forEach((element) => {
    element.classList.remove("pdd-typography-target-highlight");
    void element.offsetWidth;
    element.classList.add("pdd-typography-target-highlight");
  });
  state.typographyHighlightTimer = window.setTimeout(() => {
    state.typographyHighlighted.forEach((element) => element.classList.remove("pdd-typography-target-highlight"));
    state.typographyHighlighted = [];
  }, 840);
}

function updateTypography(variable, value, persist = true) {
  const control = typographyControl(variable);
  if (!control) return;
  applyTypographyValue(variable, value);
  syncTypographyInputs(variable);
  updateTypographyPreview(control, state.typography[variable]);
  updateTypographyOverview();
  highlightTypographyTarget(control);
  if (persist) scheduleTypographyPersistence();
}

function applyTypographyPreset(preset) {
  if (!TYPOGRAPHY_PRESETS[preset]) return;
  applyTypographyValues(typographyPresetValues(preset));
  syncTypographyInputs();
  updateTypographyOverview();
  updateTypographyPreview(TYPOGRAPHY_GROUPS[2].controls[3], state.typography["--pdd-fs-scale-weight"]);
  highlightTypographyTarget(TYPOGRAPHY_GROUPS[2].controls[3]);
  persistTypographyNow();
}

function resetTypographyGroup(groupId) {
  const group = TYPOGRAPHY_GROUPS.find((candidate) => candidate.id === groupId);
  if (!group) return;
  group.controls.forEach((control) => applyTypographyValue(control.variable, control.defaultValue));
  syncTypographyInputs();
  updateTypographyOverview();
  updateTypographyPreview(group.controls[0], state.typography[group.controls[0].variable]);
  highlightTypographyTarget(group.controls[0]);
  persistTypographyNow();
}

function resetAllTypography() {
  applyTypographyValues(defaultTypographyValues(TYPOGRAPHY_GROUPS));
  syncTypographyInputs();
  updateTypographyOverview();
  updateTypographyPreview(TYPOGRAPHY_GROUPS[2].controls[3], state.typography["--pdd-fs-scale-weight"]);
  try {
    localStorage.removeItem(typographyStorageKey);
    setTypographySaveStatus("Todos los tamaños fueron restablecidos", "");
  } catch {
    setTypographySaveStatus("Restablecidos sólo durante esta visita", "session-only");
  }
}

function openTypographyPanel() {
  if (!elements.typographyPanel) return;
  closeDialog(elements.viewDialog);
  if (state.typographyHideTimer) window.clearTimeout(state.typographyHideTimer);
  elements.typographyPanel.hidden = false;
  elements.typographyPanel.setAttribute("aria-hidden", "false");
  elements.openTypography?.setAttribute("aria-expanded", "true");
  renderTypographyControls();
  updateTypographyOverview();
  window.requestAnimationFrame(() => elements.typographyPanel.classList.add("is-open"));
  window.setTimeout(() => elements.typographySearch?.focus(), 40);
}

function closeTypographyPanel() {
  if (!elements.typographyPanel || elements.typographyPanel.hidden) return;
  persistTypographyNow();
  elements.typographyPanel.classList.remove("is-open");
  elements.typographyPanel.setAttribute("aria-hidden", "true");
  elements.openTypography?.setAttribute("aria-expanded", "false");
  state.typographyHideTimer = window.setTimeout(() => {
    elements.typographyPanel.hidden = true;
    state.typographyHideTimer = null;
  }, 250);
  elements.openViewSettings?.focus();
}

function initializeTypography() {
  const restored = storedTypographyPreferences();
  applyTypographyValues(restored.values);
  renderTypographyControls("");
  updateTypographyOverview();
  updateTypographyPreview(TYPOGRAPHY_GROUPS[2].controls[3], state.typography["--pdd-fs-scale-weight"]);
  if (!restored.valid) setTypographySaveStatus("Se recuperaron los tamaños predeterminados", "session-only");
}

function storageRead() {
  if (!state.storageKey) return null;
  try {
    const parsed = JSON.parse(localStorage.getItem(state.storageKey) || "null");
    return Array.isArray(parsed?.drafts) ? parsed.drafts : null;
  } catch {
    return null;
  }
}

function persistDrafts() {
  if (!state.storageKey) return;
  try {
    activeDraft().updated_at = new Date().toISOString();
    localStorage.setItem(state.storageKey, JSON.stringify({ version: 1, drafts: state.drafts }));
  } catch {
    setMessage("Las listas funcionan, pero este navegador no permitió guardar el borrador local.", "error");
  }
}

function initializeDraftStorage() {
  const branchId = state.catalog.branch?.id || "default";
  const userId = state.catalog.user?.id || currentUserId;
  state.storageKey = `sistema-pollos-product-dispatch-drafts-v1-user-${userId}-branch-${branchId}`;
  state.drafts = buildDraftCollection(storageRead());
}

function effectivePrice(selection = currentSelection(), draft = activeDraft()) {
  if (!selection) return 0;
  const override = draft.price_overrides[itemKey(selection.product_id, selection.variation_id)];
  return Number(override ?? selection.price ?? 0);
}

function mediaMarkup(name, imageUrl, altPrefix = "Imagen de") {
  if (imageUrl) {
    return `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(`${altPrefix} ${name}`)}" data-pdd-image-fallback="${escapeHtml(name)}">`;
  }
  return `<span class="pdd-media-placeholder has-name"><b>${escapeHtml(productInitial(name))}</b><small>${escapeHtml(name)}</small></span>`;
}

function renderSelectedProduct() {
  const product = selectedProduct();
  const variation = selectedVariation(product);
  const selection = effectiveProduct(product, variation);

  if (!selection) {
    elements.selectedName.textContent = "Ningún producto seleccionado";
    elements.selectedVariantLabel.textContent = "Producto base";
    elements.productMedia.innerHTML = '<span class="pdd-media-placeholder"><b>?</b><small>Elige un producto</small></span>';
    elements.unitPrice.textContent = `${currencyLabel(state.catalog.currency)} --`;
    elements.priceMode.textContent = "Selecciona un producto";
  } else {
    elements.selectedName.textContent = selection.product_name;
    elements.selectedVariantLabel.textContent = selection.variation_name || "Producto base";
    elements.productMedia.innerHTML = mediaMarkup(selection.display_name, selection.image_url);
    elements.unitPrice.textContent = formatMoney(effectivePrice(selection), state.catalog.currency);
    elements.priceMode.textContent = `Precio ${priceModeLabel(selection.price_mode)}`;
  }

  renderVariations();
  syncWasteTotal();
  renderCapturePreview();
}

function renderVariations() {
  const product = selectedProduct();
  if (!product) {
    elements.variations.innerHTML = '<span class="pdd-variation-empty">Elige un producto para ver sus variaciones.</span>';
    return;
  }

  const options = [{
    id: null,
    name: "Producto base",
    image_url: product.image_url,
    price: product.price,
    price_mode: product.price_mode
  }, ...product.variations];

  elements.variations.innerHTML = options.map((variation) => {
    const active = variation.id === null
      ? state.selectedVariationId === null
      : Number(variation.id) === Number(state.selectedVariationId);
    const visual = variation.image_url
      ? `<img src="${escapeHtml(variation.image_url)}" alt="" data-pdd-image-fallback="${escapeHtml(variation.name)}">`
      : `<i>${escapeHtml(productInitial(variation.name))}</i>`;
    return `<button class="pdd-variation-option${active ? " is-active" : ""}" type="button" role="option" aria-selected="${active}" data-pdd-variation-id="${variation.id ?? "base"}">
      ${visual}<span><b>${escapeHtml(variation.name)}</b><small>${escapeHtml(formatMoney(variation.price, state.catalog.currency))} ${escapeHtml(priceModeLabel(variation.price_mode))}</small></span>
    </button>`;
  }).join("");
}

function captureValues() {
  const quantity = Math.max(1, Math.round(Number(elements.quantity.value || 1)));
  const wasteGramsPerUnit = Number(elements.wastePerUnit.value);
  const tareGrams = Number(elements.tare.value);
  return {
    quantity,
    waste_grams_per_unit: wasteGramsPerUnit,
    waste_total_grams: wasteGramsPerUnit * quantity,
    tare_grams: tareGrams
  };
}

function renderWastePresets() {
  const current = Number(elements.wastePerUnit.value);
  elements.wastePresets.innerHTML = state.catalog.waste_presets.map((preset, index) => {
    const active = current === preset;
    return `<button class="${active ? "is-active" : ""}" type="button" data-pdd-waste-preset="${index}" aria-pressed="${active}"><span>M${index + 1}</span><strong>${preset.toLocaleString("es-PE")} g</strong></button>`;
  }).join("");
}

function syncWasteTotal() {
  const values = captureValues();
  const total = Number.isFinite(values.waste_total_grams) ? values.waste_total_grams : 0;
  elements.wasteTotal.textContent = `${Math.max(0, total).toLocaleString("es-PE")} g`;
  renderWastePresets();
}

function setWastePerUnit(value) {
  elements.wastePerUnit.value = String(Math.max(0, Math.round(Number(value) || 0)));
  state.numericKeypad?.refreshLabel(elements.wastePerUnit);
  syncWasteTotal();
}

function useCatalogWaste() {
  const selection = currentSelection();
  if (selection) setWastePerUnit(selection.waste_grams_per_unit);
}

function captureValidation(weightKg = Number(state.liveScale.currentWeightKg)) {
  const values = captureValues();
  if (!Number.isSafeInteger(values.waste_grams_per_unit)
    || values.waste_grams_per_unit < 0
    || values.waste_grams_per_unit > Number(elements.wastePerUnit.max)) {
    return { message: "Merma/u inválida.", target: elements.wastePerUnit };
  }
  if (values.waste_total_grams > PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS) {
    return { message: "Merma total supera el máximo.", target: elements.wastePerUnit };
  }
  if (!Number.isSafeInteger(values.tare_grams)
    || values.tare_grams < 0
    || values.tare_grams > Number(elements.tare.max)) {
    return { message: "Tara inválida.", target: elements.tare };
  }
  if (Number.isFinite(weightKg) && weightKg > 0
    && values.waste_total_grams + values.tare_grams >= weightKg * 1000) {
    return { message: "Merma + tara deben ser menores al peso.", target: elements.tare };
  }
  return { message: "", target: null };
}

function renderCapturePreview() {
  const selection = currentSelection();
  const scaleWeight = Number(state.liveScale.currentWeightKg);
  const hasWeight = Number.isFinite(scaleWeight) && scaleWeight > 0;
  syncWasteTotal();
  const validation = captureValidation(hasWeight ? scaleWeight : 0);
  elements.wasteHint.classList.toggle("is-error", Boolean(validation.message));
  elements.wasteHint.textContent = validation.message;
  const line = calculateLine({
    quantity: elements.quantity.value,
    read_weight_kg: hasWeight ? scaleWeight : 0,
    waste_grams_per_unit: elements.wastePerUnit.value,
    tare_grams: elements.tare.value,
    unit_price: effectivePrice(selection),
    price_mode: selection?.price_mode
  });

  elements.netPreview.textContent = hasWeight ? formatWeight(line.net_weight_kg) : "--- kg";
  elements.amountPreview.textContent = hasWeight && selection
    ? `Total estimado ${formatMoney(line.amount, state.catalog.currency)}`
    : `Total estimado ${currencyLabel(state.catalog.currency)} --`;
  const captureReady = Boolean(
    selection
    && state.liveScale.isCaptureReady
    && !validation.message
    && line.net_weight_kg > 0
    && !state.saving
    && Date.now() >= state.captureBlockedUntil
  );
  elements.captureWeight.disabled = !captureReady;
}

function renderScale(scaleState = state.scale?.getState?.() || state.liveScale) {
  scaleState = effectiveCaptureReading(scaleState);
  state.liveScale = scaleState || {};
  const weight = Number(scaleState.currentWeightKg);
  const hasWeight = Number.isFinite(weight) && weight >= 0;
  elements.liveWeight.innerHTML = `${hasWeight ? weight.toFixed(3) : "---"}<small>kg</small>`;
  elements.weightSource.textContent = scaleState.readingSource === "manual"
    ? "Peso manual"
    : scaleState.connectionMode === "ble"
      ? "Balanza Bluetooth"
      : scaleState.connectionMode === "serial"
        ? "Balanza serial"
        : "Sin lectura";
  if (state.pendingManualReading) {
    elements.readingState.textContent = "Listo para agregar";
  } else if (scaleState.isCaptureReady) {
    elements.readingState.textContent = "Peso estable";
  } else {
    elements.readingState.textContent = scaleState.currentWeightKg > 0
      ? "Estabilizando…"
      : "Esperando peso";
  }
  elements.clearManualWeight.hidden = !state.pendingManualReading;

  const status = scaleState.status || "offline";
  elements.scaleStatus.className = `pdd-status-chip is-${status}`;
  elements.scaleStatus.querySelector("span").textContent = status === "connected"
    ? `${scaleState.deviceName || "Balanza"} conectada`
    : status === "connecting"
      ? "Conectando balanza…"
      : status === "error"
        ? "Error de balanza"
        : "Balanza sin conectar";
  elements.scaleDialogStatus.textContent = scaleState.statusMessage || "Balanza sin conexión.";
  elements.scaleDevice.textContent = scaleState.deviceName || "No hay dispositivo seleccionado";
  elements.scaleDialogDot.classList.toggle("is-connected", status === "connected");

  const capabilities = scaleState.capabilities || state.scale?.getCapabilities?.() || {};
  elements.connectBle.disabled = status === "connecting" || !capabilities.bluetooth;
  elements.connectSerial.disabled = status === "connecting" || !capabilities.serial;
  elements.disconnectScale.disabled = status === "connecting" || (!scaleState.autoConnectMode && status === "offline");
  renderCapturePreview();
}

function renderProductGrid(query = "") {
  const needle = String(query).trim().toLocaleLowerCase("es");
  const products = state.catalog.products.filter((product) => !needle || [
    product.name,
    product.description,
    ...product.variations.map((variation) => variation.name)
  ].some((value) => String(value || "").toLocaleLowerCase("es").includes(needle)));

  if (!products.length) {
    elements.productGrid.innerHTML = '<div class="pdd-empty-dialog"><strong>No encontramos productos</strong><span>Prueba con otro nombre o agrega productos desde la administración del módulo.</span></div>';
    return;
  }

  elements.productGrid.innerHTML = products.map((product) => `
    <button class="pdd-product-option" type="button" data-pdd-product-id="${product.id}">
      ${product.image_url
        ? `<img src="${escapeHtml(product.image_url)}" alt="Imagen de ${escapeHtml(product.name)}" loading="lazy" data-pdd-image-fallback="${escapeHtml(product.name)}">`
        : `<span class="pdd-product-option-placeholder">${escapeHtml(productInitial(product.name))}</span>`}
      <span><b>${escapeHtml(product.name)}</b><small>${product.variations.length} ${product.variations.length === 1 ? "variación" : "variaciones"} · ${escapeHtml(formatMoney(product.price, state.catalog.currency))}</small></span>
    </button>`).join("");
}

function itemDisplayName(item) {
  return item.variation_name ? `${item.product_name} · ${item.variation_name}` : item.product_name;
}

function renderLists() {
  elements.lists.innerHTML = state.drafts.map((draft, index) => {
    const totals = calculateDraft(draft.items);
    const client = clientById(draft.client_id);
    const rows = draft.items.length
      ? draft.items.map((item, itemIndex) => `<button class="pdd-weighing-row" type="button"${state.saving ? " disabled" : ""} data-pdd-edit-item="${escapeHtml(item.local_id)}" data-pdd-list-index="${index}">
          <i>${itemIndex + 1}</i><span><b>${escapeHtml(itemDisplayName(item))}</b><small>${item.quantity} und · ${formatWeight(item.net_weight_kg)} · M ${item.waste_total_grams} g${item.tare_grams ? ` · T ${item.tare_grams} g` : ""}</small></span><strong>${escapeHtml(formatMoney(item.amount, state.catalog.currency))}</strong>
        </button>`).join("")
      : '<div class="pdd-list-empty"><b>Lista vacía</b><span>Selecciónala y captura el primer peso.</span></div>';

    return `<article class="pdd-list-card${index === state.activeIndex ? " is-active" : ""}" data-pdd-list-card="${index}">
      <button class="pdd-list-card-head" type="button" aria-pressed="${index === state.activeIndex}"${state.saving ? " disabled" : ""} data-pdd-select-list="${index}">
        <span class="pdd-list-number">${index + 1}</span><span><b>${escapeHtml(client?.name || "Venta al público")}</b><small>${client?.document ? `Doc. ${escapeHtml(client.document)}` : "Cliente opcional"}</small></span><span class="pdd-list-count">${totals.weighings}</span>
      </button>
      <div class="pdd-list-items">${rows}</div>
      <div class="pdd-list-totals"><span>Neto</span><strong>${formatWeight(totals.net_weight_kg)}</strong><span>Total</span><strong class="pdd-list-total-amount">${escapeHtml(formatMoney(totals.amount, state.catalog.currency))}</strong></div>
    </article>`;
  }).join("");
}

function renderActiveSummary() {
  const draft = activeDraft();
  const totals = calculateDraft(draft.items);
  const client = clientById(draft.client_id);
  elements.activeList.textContent = String(state.activeIndex + 1);
  elements.clientActionLabel.textContent = client ? client.name : "Asignar cliente";
  elements.clientActionDetail.textContent = client?.document || "Venta al público";
  elements.ticketTotal.textContent = formatMoney(totals.amount, state.catalog.currency);
  elements.ticketSummary.textContent = `${totals.weighings} ${totals.weighings === 1 ? "pesada" : "pesadas"} · ${formatWeight(totals.net_weight_kg)} netos`;
  elements.footerWeighings.textContent = String(totals.weighings);
  elements.footerQuantity.textContent = String(totals.quantity);
  elements.footerWaste.textContent = `${totals.waste_total_grams} g`;
  elements.footerNet.textContent = formatWeight(totals.net_weight_kg);
  elements.assignClient.disabled = state.saving;
  elements.railChangePrice.disabled = state.saving;
  elements.changePrice.disabled = state.saving;
  elements.manualWeight.disabled = state.saving;
  elements.save.disabled = state.saving || !draft.items.length;
  elements.savePrint.disabled = state.saving || !draft.items.length;
}

function renderAll() {
  renderSelectedProduct();
  renderLists();
  renderActiveSummary();
  renderScale();
}

function selectList(index, scroll = false) {
  if (state.saving) return;
  const next = Number(index);
  if (!Number.isInteger(next) || next < 0 || next >= state.drafts.length) return;
  state.activeIndex = next;
  renderLists();
  renderActiveSummary();
  renderSelectedProduct();
  if (scroll) {
    elements.lists.querySelector(`[data-pdd-list-card="${next}"]`)?.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
  }
}

function selectProduct(productId) {
  const product = state.catalog.products.find((entry) => entry.id === Number(productId));
  if (!product) return;
  state.selectedProductId = product.id;
  state.selectedVariationId = null;
  useCatalogWaste();
  renderSelectedProduct();
  closeDialog(elements.productDialog);
  setMessage(`${product.name} seleccionado. Captura un peso o ingrésalo manualmente.`);
}

function selectVariation(variationId) {
  const product = selectedProduct();
  if (!product) return;
  const normalized = variationId === "base" ? null : Number(variationId);
  if (normalized !== null && !product.variations.some((variation) => variation.id === normalized)) return;
  state.selectedVariationId = normalized;
  useCatalogWaste();
  renderSelectedProduct();
}

function capturedReadingIds() {
  return new Set(state.drafts.flatMap((draft) => draft.items)
    .map((item) => item.physical_reading_id)
    .filter(Boolean));
}

function addCurrentReading(scaleState = effectiveCaptureReading()) {
  if (Date.now() < state.captureBlockedUntil) return false;
  if (state.saving) {
    setMessage("Espera a que termine el guardado antes de agregar otra pesada.", "error");
    return false;
  }
  const selection = currentSelection();
  if (!selection) {
    setMessage("Primero elige el producto que vas a despachar.", "error");
    openProductDialog();
    return false;
  }

  const weight = Number(scaleState.currentWeightKg);
  if (!scaleState.isCaptureReady || !Number.isFinite(weight) || weight <= 0) {
    setMessage("Espera un peso estable de la balanza o usa el botón Peso manual.", "error");
    return false;
  }
  if (activeDraft().items.length >= 100) {
    setMessage("Esta lista ya alcanzó el máximo de 100 pesadas.", "error");
    return false;
  }

  const isPhysical = ["ble", "serial"].includes(scaleState.readingSource);
  if (isPhysical && scaleState.readingId && capturedReadingIds().has(scaleState.readingId)) {
    setMessage("Esta lectura de la balanza ya fue capturada. Retira el producto y espera una lectura nueva.", "error");
    return false;
  }

  const validation = captureValidation(weight);
  if (validation.message) {
    validation.target?.focus();
    setMessage(validation.message, "error");
    return false;
  }

  const calculated = calculateLine({
    quantity: elements.quantity.value,
    read_weight_kg: weight,
    waste_grams_per_unit: elements.wastePerUnit.value,
    tare_grams: elements.tare.value,
    unit_price: effectivePrice(selection),
    price_mode: selection.price_mode
  });
  if (calculated.net_weight_kg <= 0) {
    setMessage("Merma + tara deben ser menores al peso.", "error");
    return false;
  }
  if (calculated.amount < 0.01) {
    setMessage("El precio y la cantidad o peso neto deben producir un total mínimo de 0.01.", "error");
    return false;
  }

  const item = {
    local_id: createUuid(),
    physical_reading_id: isPhysical ? scaleState.readingId : null,
    product_id: selection.product_id,
    variation_id: selection.variation_id,
    product_name: selection.product_name,
    variation_name: selection.variation_name,
    image_url: selection.image_url,
    catalog_price: selection.price,
    catalog_waste_grams_per_unit: selection.waste_grams_per_unit,
    ...calculated,
    weight_source: isPhysical ? PRODUCT_DISPATCH_SCALE_CODE : "MANUAL",
    weighed_at: isPhysical ? (scaleState.readingAt || new Date().toISOString()) : new Date().toISOString(),
    scale_reading: isPhysical ? {
      raw_frame: String(scaleState.readingRaw || state.lastRaw || "").slice(0, 500) || null,
      connection_mode: scaleState.connectionMode,
      device_name: scaleState.deviceName || null,
      captured_at: scaleState.readingAt || new Date().toISOString()
    } : null
  };

  activeDraft().items.push(item);
  elements.tare.value = "0";
  state.numericKeypad?.refreshLabel(elements.tare);
  syncWasteTotal();
  persistDrafts();
  renderLists();
  renderActiveSummary();
  setMessage(`${itemDisplayName(item)} agregado a la lista ${state.activeIndex + 1}.`, "success");
  if (isPhysical) {
    state.scale.clearReading();
  } else {
    blockCaptureBriefly();
    state.pendingManualReading = null;
    renderScale(state.scale.getState());
  }
  return true;
}

function openProductDialog() {
  elements.productSearch.value = "";
  renderProductGrid();
  openDialog(elements.productDialog, elements.productSearch);
}

function renderClientList(query = "") {
  const clients = searchClients(state.catalog.clients, query).slice(0, 100);
  if (!clients.length) {
    elements.clientList.innerHTML = '<div class="pdd-empty-dialog"><strong>No encontramos clientes</strong><span>Puedes guardar como Venta al público o probar otra búsqueda.</span></div>';
    return;
  }
  elements.clientList.innerHTML = clients.map((client) => `
    <button class="pdd-client-option" type="button" data-pdd-client-id="${client.id}">
      <i>${escapeHtml(productInitial(client.name))}</i><span><b>${escapeHtml(client.name)}</b><small>${escapeHtml([client.document && `Doc. ${client.document}`, client.phone].filter(Boolean).join(" · ") || "Sin documento")}</small></span><em>Elegir</em>
    </button>`).join("");
}

function openClientDialog() {
  elements.clientSearch.value = "";
  renderClientList();
  openDialog(elements.clientDialog, elements.clientSearch);
}

function assignClient(clientId = null) {
  if (state.saving) return;
  activeDraft().client_id = clientId ? Number(clientId) : null;
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.clientDialog);
  const client = clientById(clientId);
  setMessage(client ? `Ticket asignado a ${client.name}.` : "El ticket se guardará como Venta al público.", "success");
}

function productForItem(item) {
  return state.catalog.products.find((product) => product.id === Number(item.product_id)) || null;
}

function variationForItem(item, product = productForItem(item)) {
  return product?.variations.find((variation) => variation.id === Number(item.variation_id)) || null;
}

function fillEditProductOptions(selectedId) {
  elements.editProduct.innerHTML = state.catalog.products.map((product) => `<option value="${product.id}"${product.id === Number(selectedId) ? " selected" : ""}>${escapeHtml(product.name)}</option>`).join("");
  fillEditVariationOptions();
}

function fillEditVariationOptions(selectedId = null) {
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  elements.editVariation.innerHTML = `<option value="">Producto base</option>${(product?.variations || []).map((variation) => `<option value="${variation.id}"${variation.id === Number(selectedId) ? " selected" : ""}>${escapeHtml(variation.name)}</option>`).join("")}`;
}

function editingItem() {
  return activeDraft().items.find((item) => item.local_id === state.editingLocalId) || null;
}

function renderEditCalculation() {
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  const line = calculateLine({
    quantity: elements.editQuantity.value,
    read_weight_kg: elements.editWeight.value,
    waste_grams_per_unit: elements.editWastePerUnit.value,
    tare_grams: elements.editTare.value,
    unit_price: elements.editPrice.value,
    price_mode: selection?.price_mode
  });
  elements.editWasteTotal.textContent = `${line.waste_total_grams.toLocaleString("es-PE")} g`;
  elements.editCalculated.textContent = `Neto ${formatWeight(line.net_weight_kg)} · ${formatMoney(line.amount, state.catalog.currency)}`;
}

function openEditDialog(localId, listIndex) {
  selectList(listIndex);
  const item = activeDraft().items.find((entry) => entry.local_id === localId);
  if (!item) return;
  state.editingLocalId = item.local_id;
  fillEditProductOptions(item.product_id);
  fillEditVariationOptions(item.variation_id);
  elements.editQuantity.value = String(item.quantity);
  elements.editWeight.value = Number(item.read_weight_kg).toFixed(3);
  elements.editWastePerUnit.value = String(item.waste_grams_per_unit ?? Math.round(item.waste_total_grams / item.quantity));
  elements.editTare.value = String(item.tare_grams || 0);
  elements.editPrice.value = Number(item.unit_price).toFixed(4);
  elements.editSource.textContent = item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
    ? `Origen: balanza ${item.scale_reading?.device_name || "conectada"}`
    : "Origen: peso manual";
  renderEditCalculation();
  openDialog(elements.editDialog, elements.editQuantity);
}

function changeEditingProduct(useCatalogDefaults = true) {
  const item = editingItem();
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  if (selection && useCatalogDefaults) {
    const override = activeDraft().price_overrides[itemKey(selection.product_id, selection.variation_id)];
    elements.editPrice.value = Number(override ?? selection.price).toFixed(4);
    elements.editWastePerUnit.value = String(selection.waste_grams_per_unit);
  } else if (!selection && item) {
    elements.editPrice.value = Number(item.unit_price).toFixed(4);
  }
  renderEditCalculation();
}

function saveEditingItem(event) {
  event.preventDefault();
  if (state.saving) return;
  const item = editingItem();
  if (!item || !elements.editForm.reportValidity()) return;
  const product = state.catalog.products.find((entry) => entry.id === Number(elements.editProduct.value));
  const variation = product?.variations.find((entry) => entry.id === Number(elements.editVariation.value)) || null;
  const selection = effectiveProduct(product, variation);
  if (!selection) return;
  const calculated = calculateLine({
    quantity: elements.editQuantity.value,
    read_weight_kg: elements.editWeight.value,
    waste_grams_per_unit: elements.editWastePerUnit.value,
    tare_grams: elements.editTare.value,
    unit_price: elements.editPrice.value,
    price_mode: selection.price_mode
  });
  if (calculated.waste_total_grams > PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS) {
    elements.editWastePerUnit.focus();
    setMessage("Merma total supera el máximo.", "error");
    return;
  }
  if (calculated.waste_total_grams + calculated.tare_grams >= calculated.read_weight_kg * 1000) {
    elements.editTare.focus();
    setMessage("Merma + tara deben ser menores al peso.", "error");
    return;
  }
  if (calculated.net_weight_kg <= 0) {
    setMessage("Merma + tara deben ser menores al peso.", "error");
    return;
  }
  if (calculated.amount < 0.01) {
    setMessage("La pesada editada debe producir un total mínimo de 0.01.", "error");
    return;
  }

  const weightChanged = roundTo(item.read_weight_kg, 3) !== calculated.read_weight_kg;
  Object.assign(item, {
    product_id: selection.product_id,
    variation_id: selection.variation_id,
    product_name: selection.product_name,
    variation_name: selection.variation_name,
    image_url: selection.image_url,
    catalog_price: selection.price,
    catalog_waste_grams_per_unit: selection.waste_grams_per_unit,
    ...calculated,
    weight_source: weightChanged ? "MANUAL" : item.weight_source,
    scale_reading: weightChanged ? null : item.scale_reading,
    physical_reading_id: weightChanged ? null : item.physical_reading_id
  });
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.editDialog);
  setMessage("Pesada actualizada correctamente.", "success");
}

function deleteEditingItem() {
  if (state.saving) return;
  const item = editingItem();
  if (!item || !window.confirm(`¿Quitar la pesada de ${itemDisplayName(item)} de esta lista?`)) return;
  activeDraft().items = activeDraft().items.filter((entry) => entry.local_id !== item.local_id);
  state.editingLocalId = null;
  persistDrafts();
  renderLists();
  renderActiveSummary();
  closeDialog(elements.editDialog);
  setMessage("La pesada se quitó del borrador.", "success");
}

function priceTargets() {
  const targets = new Map();
  activeDraft().items.forEach((item) => {
    const key = itemKey(item.product_id, item.variation_id);
    if (!targets.has(key)) targets.set(key, {
      key,
      product_id: item.product_id,
      variation_id: item.variation_id,
      name: itemDisplayName(item),
      price_mode: item.price_mode,
      catalog_price: item.catalog_price,
      price: activeDraft().price_overrides[key] ?? item.unit_price
    });
  });
  const selection = currentSelection();
  if (selection) {
    const key = itemKey(selection.product_id, selection.variation_id);
    if (!targets.has(key)) targets.set(key, {
      key,
      product_id: selection.product_id,
      variation_id: selection.variation_id,
      name: selection.display_name,
      price_mode: selection.price_mode,
      catalog_price: selection.price,
      price: effectivePrice(selection)
    });
  }
  return [...targets.values()];
}

function openPriceDialog() {
  const targets = priceTargets();
  elements.priceRows.innerHTML = targets.length ? targets.map((target) => `
    <div class="pdd-price-row">
      <span><b>${escapeHtml(target.name)}</b><small>Catálogo: ${escapeHtml(formatMoney(target.catalog_price, state.catalog.currency))} ${escapeHtml(priceModeLabel(target.price_mode))}</small></span>
      <label><span>${escapeHtml(currencyLabel(state.catalog.currency))}</span><input type="number" min="0.0001" max="9999999999.9999" step="0.0001" value="${Number(target.price).toFixed(4)}" data-pdd-price-key="${escapeHtml(target.key)}" required></label>
    </div>`).join("") : '<div class="pdd-empty-dialog"><strong>No hay productos para cambiar</strong><span>Selecciona un producto o agrega una pesada primero.</span></div>';
  openDialog(elements.priceDialog, elements.priceRows.querySelector("input"));
}

function savePrices(event) {
  event.preventDefault();
  if (state.saving) return;
  const inputs = [...elements.priceRows.querySelectorAll("[data-pdd-price-key]")];
  if (!inputs.length) {
    closeDialog(elements.priceDialog);
    return;
  }
  if (!elements.priceForm.reportValidity()) return;
  const proposedPrices = new Map(inputs.map((input) => [
    input.dataset.pddPriceKey,
    roundTo(input.value, 4)
  ]));
  const invalidItem = activeDraft().items.find((item) => {
    const price = proposedPrices.get(itemKey(item.product_id, item.variation_id));
    return price !== undefined && calculateLine({ ...item, unit_price: price }).amount < 0.01;
  });
  if (invalidItem) {
    const key = itemKey(invalidItem.product_id, invalidItem.variation_id);
    elements.priceRows.querySelector(`[data-pdd-price-key="${CSS.escape(key)}"]`)?.focus();
    setMessage(`El precio de ${itemDisplayName(invalidItem)} debe producir un total mínimo de 0.01.`, "error");
    return;
  }
  inputs.forEach((input) => {
    const price = proposedPrices.get(input.dataset.pddPriceKey);
    activeDraft().price_overrides[input.dataset.pddPriceKey] = price;
    activeDraft().items.forEach((item) => {
      if (itemKey(item.product_id, item.variation_id) !== input.dataset.pddPriceKey) return;
      Object.assign(item, calculateLine({ ...item, unit_price: price }));
    });
  });
  persistDrafts();
  renderAll();
  closeDialog(elements.priceDialog);
  setMessage("Los precios del ticket activo fueron actualizados.", "success");
}

function showTicketToast(ticket, printed, printError = null) {
  state.pendingPrintTicket = printError ? ticket : null;
  elements.lastTicket.hidden = false;
  elements.lastTicketTitle.textContent = printError
    ? "Ticket guardado; impresión pendiente"
    : printed
      ? "Ticket guardado y enviado a impresión"
      : "Ticket guardado sin imprimir";
  elements.lastTicketDetail.textContent = `${ticket?.code || ticket?.codigo || "Ticket confirmado"}${printError ? ` · ${printError}` : ""}`;
  elements.retryPrint.hidden = !printError;
}

function closeReservedPrintWindow(printWindow) {
  try { printWindow?.close(); } catch { /* La ventana pudo cerrarse manualmente. */ }
}

async function saveActiveDraft(shouldPrint = false) {
  const draft = activeDraft();
  if (!draft.items.length || state.saving) return;
  const finishedIndex = state.activeIndex;
  const finishedDraftId = draft.id;
  const payload = buildTicketPayload(draft);
  let printWindow = null;
  if (shouldPrint) {
    printWindow = window.open("", "_blank", "popup=yes,width=420,height=720");
    if (printWindow) {
      printWindow.document.write('<!doctype html><html><body style="font-family:sans-serif;padding:28px;text-align:center">Guardando ticket…</body></html>');
      printWindow.document.close();
    }
  }

  state.saving = true;
  renderActiveSummary();
  setMessage("Guardando el ticket y sus pesadas…");
  try {
    const response = await apiRequest(`${apiBase}/tickets`, {
      method: "POST",
      body: JSON.stringify(payload)
    });
    const ticket = response?.data?.ticket || response?.data || response?.ticket || response;
    if (state.drafts[finishedIndex]?.id === finishedDraftId) {
      state.drafts[finishedIndex] = createEmptyDraft(finishedIndex + 1);
    }
    persistDrafts();
    renderLists();
    renderActiveSummary();
    setMessage(`Ticket ${ticket?.code || ticket?.codigo || "confirmado"} guardado correctamente.`, "success");

    if (shouldPrint) {
      try {
        printProductDispatchTicket(ticket, {
          currency: state.catalog.currency,
          ticketTitle: state.catalog.ticket_title,
          ticketMessage: state.catalog.ticket_message,
          printWindow
        });
        showTicketToast(ticket, true);
      } catch (error) {
        closeReservedPrintWindow(printWindow);
        showTicketToast(ticket, false, errorMessage(error));
        setMessage("El ticket se guardó. La impresión puede reintentarse sin volver a guardar.", "error");
      }
    } else {
      showTicketToast(ticket, false);
    }
  } catch (error) {
    closeReservedPrintWindow(printWindow);
    setMessage(errorMessage(error), "error");
  } finally {
    state.saving = false;
    renderLists();
    renderActiveSummary();
  }
}

function retryPrint() {
  if (!state.pendingPrintTicket) return;
  try {
    printProductDispatchTicket(state.pendingPrintTicket, {
      currency: state.catalog.currency,
      ticketTitle: state.catalog.ticket_title,
      ticketMessage: state.catalog.ticket_message
    });
    const ticket = state.pendingPrintTicket;
    state.pendingPrintTicket = null;
    showTicketToast(ticket, true);
    setMessage("La impresión se abrió correctamente.", "success");
  } catch (error) {
    showTicketToast(state.pendingPrintTicket, false, errorMessage(error));
  }
}

function serialOptions() {
  return {
    baudRate: Number(elements.baudRate.value),
    dataBits: Number(elements.dataBits.value),
    stopBits: Number(elements.stopBits.value),
    parity: elements.parity.value,
    flowControl: "none"
  };
}

function fillSerialOptions() {
  const options = state.scale.getState().serialOptions || {};
  elements.baudRate.value = String(options.baudRate || 9600);
  elements.dataBits.value = String(options.dataBits || 8);
  elements.stopBits.value = String(options.stopBits || 1);
  elements.parity.value = options.parity || "none";
}

async function connectBle() {
  elements.scaleMessage.classList.remove("is-error");
  elements.scaleMessage.textContent = "Selecciona la balanza Bluetooth en la ventana del navegador…";
  try {
    const connected = await state.scale.connectBle();
    elements.scaleMessage.textContent = connected ? "Balanza Bluetooth conectada." : state.scale.getState().statusMessage;
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function connectSerial() {
  elements.scaleMessage.classList.remove("is-error");
  elements.scaleMessage.textContent = "Selecciona el puerto de la balanza…";
  try {
    state.scale.configureSerial(serialOptions());
    const connected = await state.scale.connectSerial({ serialOptions: serialOptions() });
    elements.scaleMessage.textContent = connected ? "Balanza serial conectada." : state.scale.getState().statusMessage;
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function disconnectScale() {
  try {
    await state.scale.disconnect({ forget: true });
    elements.scaleMessage.textContent = "La balanza se desconectó y se olvidó en este puesto.";
  } catch (error) {
    elements.scaleMessage.classList.add("is-error");
    elements.scaleMessage.textContent = errorMessage(error);
  }
}

async function restoreScale() {
  try {
    await state.scale.restoreAuthorizedConnection();
  } catch {
    // La interfaz conserva el modo manual aunque el dispositivo recordado ya no esté disponible.
  }
}

function renderWastePresetSettings() {
  state.catalog.waste_presets.forEach((preset, index) => {
    elements.wastePresetInputs[index].value = String(preset);
  });
}

async function saveWastePresets(event) {
  event.preventDefault();
  if (state.wastePresetSaving || !elements.wastePresetForm.reportValidity()) return;
  const proposed = elements.wastePresetInputs.map((input) => Number(input.value));
  if (proposed.some((value) => !Number.isSafeInteger(value) || value < 0 || value > 1_000_000)) {
    elements.wastePresetStatus.textContent = "Revisa los valores.";
    return;
  }

  state.wastePresetSaving = true;
  elements.saveWastePresets.disabled = true;
  elements.wastePresetStatus.textContent = "Guardando…";
  try {
    const response = await apiRequest(`${apiBase}/configuracion`, {
      method: "PUT",
      body: JSON.stringify({ waste_presets: proposed })
    });
    state.catalog.waste_presets = normalizeWastePresets(
      response?.data?.waste_presets ?? response?.waste_presets ?? proposed
    );
    renderWastePresetSettings();
    renderWastePresets();
    elements.wastePresetStatus.textContent = "Guardado";
  } catch (error) {
    elements.wastePresetStatus.textContent = errorMessage(error);
  } finally {
    state.wastePresetSaving = false;
    elements.saveWastePresets.disabled = false;
  }
}

async function loadCatalog() {
  state.loading = true;
  setMessage("Cargando productos, clientes y configuración de la sucursal…");
  try {
    const response = await apiRequest(`${apiBase}/catalogo`);
    state.catalog = normalizeCatalog(response);
    renderWastePresetSettings();
    initializeDraftStorage();
    elements.branchName.textContent = state.catalog.branch?.name || state.catalog.branch?.nombre || "Sucursal actual";
    const branchId = state.catalog.branch?.id || "default";
    state.scale.setStorageKey(`sistema-pollos-product-dispatch-scale-v1-branch-${branchId}`, {
      reload: true,
      persistCurrent: false
    });
    const scaleState = state.scale.getState();
    if (!scaleState.autoConnectMode && state.catalog.scale?.configuration) {
      state.scale.configureSerial(state.catalog.scale.configuration);
    }
    fillSerialOptions();
    state.loading = false;
    renderAll();
    setMessage("Estación lista. Elige un producto y captura el peso; cada lista se conserva automáticamente.", "success");
    void restoreScale();
  } catch (error) {
    state.loading = false;
    renderAll();
    setMessage(errorMessage(error), "error");
  }
}

state.scale = new RetailScaleController({
  storageKey: "sistema-pollos-product-dispatch-scale-v1-pending",
  onReading(payload) {
    renderScale(payload?.state || payload || state.scale.getState());
  },
  onStatus(payload) {
    renderScale(payload?.state || payload || state.scale.getState());
  },
  onRaw(payload) {
    state.lastRaw = String(payload?.raw || "");
    elements.rawReading.textContent = `Trama: ${state.lastRaw || "--"}`;
  }
});

state.numericKeypad = bindIntegerKeypad({
  inputs: [
    { input: elements.quantity, maxLength: 6 },
    { input: elements.wastePerUnit, maxLength: 7 },
    { input: elements.tare, maxLength: 10 }
  ],
  dialog: elements.numericKeypad,
  titleOutput: elements.numericKeypadTitle,
  valueLabelOutput: elements.numericKeypadValueLabel,
  valueOutput: elements.numericKeypadValue,
  messageOutput: elements.numericKeypadMessage,
  clearButton: elements.numericKeypadClear,
  confirmButton: elements.numericKeypadConfirm,
  showDialog(focusTarget) {
    openDialog(elements.numericKeypad, focusTarget);
  },
  hideDialog() {
    closeDialog(elements.numericKeypad);
  }
});

elements.chooseProduct.addEventListener("click", openProductDialog);
elements.productMedia.addEventListener("click", openProductDialog);
elements.productSearch.addEventListener("input", () => renderProductGrid(elements.productSearch.value));
elements.productGrid.addEventListener("click", (event) => {
  const option = event.target.closest("[data-pdd-product-id]");
  if (option) selectProduct(option.dataset.pddProductId);
});
elements.variations.addEventListener("click", (event) => {
  const option = event.target.closest("[data-pdd-variation-id]");
  if (option) selectVariation(option.dataset.pddVariationId);
});
document.addEventListener("error", (event) => {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || !image.dataset.pddImageFallback) return;
  const name = image.dataset.pddImageFallback;
  if (image.closest(".pdd-product-option")) {
    const fallback = document.createElement("span");
    fallback.className = "pdd-product-option-placeholder";
    fallback.textContent = productInitial(name);
    image.replaceWith(fallback);
    return;
  }
  if (image.closest(".pdd-variation-option")) {
    const fallback = document.createElement("i");
    fallback.textContent = productInitial(name);
    image.replaceWith(fallback);
    return;
  }
  const fallback = document.createElement("span");
  fallback.className = "pdd-media-placeholder has-name";
  const initial = document.createElement("b");
  initial.textContent = productInitial(name);
  const label = document.createElement("small");
  label.textContent = name;
  fallback.append(initial, label);
  image.replaceWith(fallback);
}, true);
document.addEventListener("click", (event) => {
  const close = event.target.closest("[data-pdd-close]");
  if (close) closeDialog(document.querySelector(`#${CSS.escape(close.dataset.pddClose)}`));
});
elements.quantity.addEventListener("input", () => {
  syncWasteTotal();
  renderCapturePreview();
});
elements.quantity.addEventListener("change", () => {
  elements.quantity.value = String(Math.max(1, Math.min(100000, Math.round(Number(elements.quantity.value || 1)))));
  state.numericKeypad?.refreshLabel(elements.quantity);
  syncWasteTotal();
  renderCapturePreview();
});
elements.wastePerUnit.addEventListener("input", () => {
  syncWasteTotal();
  renderCapturePreview();
});
elements.wastePerUnit.addEventListener("change", () => {
  setWastePerUnit(elements.wastePerUnit.value);
  renderCapturePreview();
});
elements.tare.addEventListener("input", renderCapturePreview);
elements.tare.addEventListener("change", () => {
  const normalized = Math.round(Number(elements.tare.value || 0));
  elements.tare.value = String(Number.isFinite(normalized) ? Math.max(0, normalized) : 0);
  state.numericKeypad?.refreshLabel(elements.tare);
  renderCapturePreview();
});
elements.wastePresets.addEventListener("click", (event) => {
  const button = event.target.closest("[data-pdd-waste-preset]");
  if (!button) return;
  const preset = state.catalog.waste_presets[Number(button.dataset.pddWastePreset)];
  if (Number.isSafeInteger(preset)) {
    setWastePerUnit(preset);
    renderCapturePreview();
  }
});
elements.captureWeight.addEventListener("click", () => addCurrentReading());
elements.manualWeight.addEventListener("click", () => {
  if (!currentSelection()) {
    setMessage("Primero elige un producto antes de ingresar el peso.", "error");
    openProductDialog();
    return;
  }
  elements.manualInput.value = state.pendingManualReading
    ? state.pendingManualReading.currentWeightKg.toFixed(3)
    : "";
  openDialog(elements.manualDialog, elements.manualInput);
});
elements.manualForm.addEventListener("submit", (event) => {
  event.preventDefault();
  if (!elements.manualForm.reportValidity()) return;
  try {
    state.pendingManualReading = createPendingManualReading(elements.manualInput.value);
    renderScale(state.scale.getState());
    closeDialog(elements.manualDialog);
    setMessage(
      `Peso manual ${formatWeight(state.pendingManualReading.currentWeightKg)} listo. Ajusta los datos y presiona Capturar peso.`,
      "success"
    );
  } catch (error) {
    setMessage(errorMessage(error), "error");
  }
});
elements.clearManualWeight.addEventListener("click", () => {
  if (!state.pendingManualReading) return;
  state.pendingManualReading = null;
  renderScale(state.scale.getState());
  setMessage("Se quitó el peso manual. La pantalla vuelve a mostrar la lectura de la balanza.");
});
elements.lists.addEventListener("click", (event) => {
  const edit = event.target.closest("[data-pdd-edit-item]");
  if (edit) {
    openEditDialog(edit.dataset.pddEditItem, edit.dataset.pddListIndex);
    return;
  }
  const list = event.target.closest("[data-pdd-select-list]");
  if (list) selectList(list.dataset.pddSelectList);
});
elements.assignClient.addEventListener("click", openClientDialog);
elements.clientSearch.addEventListener("input", () => renderClientList(elements.clientSearch.value));
elements.clientList.addEventListener("click", (event) => {
  const client = event.target.closest("[data-pdd-client-id]");
  if (client) assignClient(client.dataset.pddClientId);
});
elements.publicSale.addEventListener("click", () => assignClient(null));
elements.editProduct.addEventListener("change", () => {
  fillEditVariationOptions();
  changeEditingProduct(true);
});
elements.editVariation.addEventListener("change", () => changeEditingProduct(true));
[elements.editQuantity, elements.editWeight, elements.editWastePerUnit, elements.editTare, elements.editPrice].forEach((input) => input.addEventListener("input", renderEditCalculation));
elements.editForm.addEventListener("submit", saveEditingItem);
elements.deleteWeighing.addEventListener("click", deleteEditingItem);
elements.changePrice.addEventListener("click", openPriceDialog);
elements.railChangePrice.addEventListener("click", openPriceDialog);
elements.priceForm.addEventListener("submit", savePrices);
elements.save.addEventListener("click", () => void saveActiveDraft(false));
elements.savePrint.addEventListener("click", () => void saveActiveDraft(true));
elements.retryPrint.addEventListener("click", retryPrint);
elements.dismissTicket.addEventListener("click", () => { elements.lastTicket.hidden = true; });
elements.openScaleSettings.addEventListener("click", () => {
  fillSerialOptions();
  elements.scaleMessage.textContent = "La primera conexión necesita que elijas el dispositivo; luego intentaremos restaurarla automáticamente.";
  elements.scaleMessage.classList.remove("is-error");
  openDialog(elements.scaleDialog);
});
elements.openViewSettings.addEventListener("click", () => {
  if (!elements.typographyPanel.hidden) closeTypographyPanel();
  renderAppScale();
  renderWastePresetSettings();
  elements.wastePresetStatus.textContent = "";
  openDialog(elements.viewDialog, state.appScale === APP_SCALE_LEVELS.at(-1) ? elements.zoomOut : elements.zoomIn);
});
elements.wastePresetForm.addEventListener("submit", saveWastePresets);
elements.zoomOut.addEventListener("click", () => stepAppScale(-1));
elements.zoomIn.addEventListener("click", () => stepAppScale(1));
elements.zoomReset.addEventListener("click", () => applyAppScale(100));
elements.openTypography.addEventListener("click", openTypographyPanel);
elements.typographyClose.addEventListener("click", closeTypographyPanel);
elements.typographyDone.addEventListener("click", closeTypographyPanel);
elements.typographySearch.addEventListener("input", () => renderTypographyControls(elements.typographySearch.value));
elements.typographyExpandAll.addEventListener("click", () => {
  elements.typographyControls.querySelectorAll("details").forEach((details) => { details.open = true; });
});
elements.typographyCollapseAll.addEventListener("click", () => {
  elements.typographyControls.querySelectorAll("details").forEach((details) => { details.open = false; });
});
elements.typographyResetAll.addEventListener("click", resetAllTypography);
document.querySelectorAll("[data-pdd-typography-preset]").forEach((button) => {
  button.addEventListener("click", () => applyTypographyPreset(button.dataset.pddTypographyPreset));
});
elements.typographyControls.addEventListener("click", (event) => {
  const step = event.target.closest("[data-pdd-typography-step]");
  if (step) {
    const control = typographyControl(step.dataset.pddTypographyVariable);
    const direction = Number(step.dataset.pddTypographyStep);
    if (control && (direction === -1 || direction === 1)) {
      updateTypography(control.variable, state.typography[control.variable] + direction * control.step, false);
      persistTypographyNow();
    }
    return;
  }

  const reset = event.target.closest("[data-pdd-typography-reset]");
  if (reset) {
    const control = typographyControl(reset.dataset.pddTypographyReset);
    if (control) {
      updateTypography(control.variable, control.defaultValue, false);
      persistTypographyNow();
    }
    return;
  }

  const resetGroup = event.target.closest("[data-pdd-typography-reset-group]");
  if (resetGroup) resetTypographyGroup(resetGroup.dataset.pddTypographyResetGroup);
});
elements.typographyControls.addEventListener("input", (event) => {
  const rangeVariable = event.target.dataset.pddTypographyRange;
  if (rangeVariable) {
    updateTypography(rangeVariable, event.target.value);
    return;
  }

  const numberVariable = event.target.dataset.pddTypographyNumber;
  const control = typographyControl(numberVariable);
  const numeric = Number(event.target.value);
  if (control && event.target.value.trim() !== "" && Number.isFinite(numeric) && numeric >= control.min && numeric <= control.max) {
    updateTypography(numberVariable, numeric);
  }
});
elements.typographyControls.addEventListener("change", (event) => {
  const variable = event.target.dataset.pddTypographyRange || event.target.dataset.pddTypographyNumber;
  const control = typographyControl(variable);
  if (!control) return;
  const raw = String(event.target.value).trim();
  if (!raw || !Number.isFinite(Number(raw))) {
    syncTypographyInputs(variable);
    return;
  }
  updateTypography(variable, raw, false);
  persistTypographyNow();
});
elements.typographyControls.addEventListener("focusin", (event) => {
  const row = event.target.closest("[data-pdd-typography-row]");
  const control = typographyControl(row?.dataset.pddTypographyRow);
  if (control) updateTypographyPreview(control, state.typography[control.variable]);
});
elements.connectBle.addEventListener("click", () => void connectBle());
elements.connectSerial.addEventListener("click", () => void connectSerial());
elements.disconnectScale.addEventListener("click", () => void disconnectScale());

document.querySelectorAll(".pdd-dialog").forEach((dialog) => {
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) closeDialog(dialog);
  });
  dialog.addEventListener("close", () => state.lastFocus?.focus?.());
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !elements.typographyPanel.hidden) {
    event.preventDefault();
    closeTypographyPanel();
  }
});

window.addEventListener("auth:expired", () => setMessage("Tu sesión venció. Inicia sesión nuevamente; los ocho borradores permanecen en este navegador.", "error"));
window.addEventListener("storage", (event) => {
  if (event.key === viewStorageKey) applyAppScale(storedAppScale(), false);
  if (event.key === typographyStorageKey) {
    const restored = storedTypographyPreferences(event.newValue);
    applyTypographyValues(restored.values);
    syncTypographyInputs();
    updateTypographyOverview();
    setTypographySaveStatus("Actualizado desde otra pestaña", restored.valid ? "" : "session-only");
  }
});
window.addEventListener("pageshow", (event) => {
  if (!event.persisted) return;
  const restored = storedTypographyPreferences();
  applyTypographyValues(restored.values);
  syncTypographyInputs();
  updateTypographyOverview();
});
window.addEventListener("pagehide", () => {
  if (state.typographySaveTimer) persistTypographyNow();
  void state.scale.destroy();
});
document.addEventListener("visibilitychange", () => {
  if (!document.hidden && !state.loading && state.scale.getState().autoConnectMode) void restoreScale();
});

function updateClock() {
  elements.clock.textContent = new Intl.DateTimeFormat("es-CO", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  }).format(new Date());
}

updateClock();
window.setInterval(updateClock, 1000);
applyAppScale(storedAppScale(), false);
initializeTypography();
renderAll();
void loadCatalog();
