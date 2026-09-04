import {
  buildProductDispatchCustomerDisplayChannelName,
  buildProductDispatchCustomerDisplayStorageKey,
  productDispatchCustomerDisplayPayloadMatches,
  PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE,
  PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE
} from "./product-dispatch-customer-display.js";
import { initializeProductCustomerDisplayTypography } from "./product-dispatch-customer-display-typography.js";
import { formatWeightValue } from "./despacho-productos-despacho-utils.js";

const query = new URLSearchParams(globalThis.location.search);
const BRANCH_ID = String(query.get("branch") || "").trim();
const USER_ID = String(query.get("user") || "").trim();
const PRODUCER_ID = String(query.get("source") || "").trim();
const AUTHENTICATED_USER_ID = String(document.body.dataset.authenticatedUserId || "").trim();
const DISPLAY_TTL_MS = 8000;
const CHANNEL_NAME = buildProductDispatchCustomerDisplayChannelName(
  BRANCH_ID,
  USER_ID,
  PRODUCER_ID
);
const STORAGE_KEY = buildProductDispatchCustomerDisplayStorageKey(
  BRANCH_ID,
  USER_ID,
  PRODUCER_ID
);

const elements = {
  title: document.getElementById("productCustomerDisplayTitle"),
  status: document.getElementById("productCustomerDisplayStatus"),
  liveNet: document.getElementById("productCustomerDisplayLiveNet"),
  liveAmount: document.getElementById("productCustomerDisplayLiveAmount"),
  liveStatus: document.getElementById("productCustomerDisplayLiveStatus"),
  listHeading: document.getElementById("productCustomerDisplayListHeading"),
  customer: document.getElementById("productCustomerDisplayCustomer"),
  rows: document.getElementById("productCustomerDisplayRows"),
  listNet: document.getElementById("productCustomerDisplayListNet"),
  listAmount: document.getElementById("productCustomerDisplayListAmount"),
  announcement: document.getElementById("productCustomerDisplayAnnouncement"),
  fullscreen: document.getElementById("productCustomerDisplayFullscreen"),
  fullscreenLabel: document.getElementById("productCustomerDisplayFullscreenLabel"),
  chooseScreen: document.getElementById("productCustomerDisplayChooseScreen"),
  chooseScreenLabel: document.getElementById("productCustomerDisplayChooseScreenLabel"),
  screenDialog: document.getElementById("productCustomerDisplayScreenDialog"),
  screenClose: document.getElementById("productCustomerDisplayScreenClose"),
  screenList: document.getElementById("productCustomerDisplayScreenList"),
  screenFeedback: document.getElementById("productCustomerDisplayScreenFeedback")
};

let channel = null;
let lastUpdateAt = 0;
let lastPayloadTimestamp = 0;
let lastRevision = 0;
let lastProducerInstance = 0;
let typographySettings = null;

function normalizeNumber(value) {
  if (value === null || value === undefined || String(value).trim() === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function currencyLabel(currency) {
  if (String(currency || "").toUpperCase() === "COP") return "$";
  if (String(currency || "").toUpperCase() === "PEN") return "S/";
  return String(currency || "S/").trim() || "S/";
}

function formatAmount(value, currency = "PEN") {
  const amount = normalizeNumber(value) ?? 0;
  const absolute = Math.abs(amount).toLocaleString("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
  const label = currencyLabel(currency);
  return amount < 0 ? `-${label} ${absolute}` : `${label} ${absolute}`;
}

function formatWeight(value) {
  return `${(normalizeNumber(value) ?? 0).toLocaleString("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })} kg`;
}

function removeStoredState() {
  if (!PRODUCER_ID) return;
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // La pantalla sigue limpiándose aunque el navegador bloquee localStorage.
  }
}

function renderEmptyRow() {
  const row = document.createElement("tr");
  const cell = document.createElement("td");
  row.className = "pdcd-empty-row";
  cell.colSpan = 4;
  cell.textContent = "Lista vacía";
  row.append(cell);
  elements.rows.replaceChildren(row);
}

function clearDisplay(statusMessage = "Esperando despacho", removeStorage = false) {
  elements.title.textContent = "Despacho de productos";
  elements.status.textContent = statusMessage;
  elements.status.classList.add("is-waiting");
  elements.liveNet.textContent = "---";
  elements.liveAmount.textContent = "S/ 0.00";
  elements.liveStatus.textContent = "Sin cálculo";
  elements.listHeading.textContent = "Lista 1";
  elements.customer.textContent = "Venta al público";
  elements.listNet.textContent = "0.00 kg";
  elements.listAmount.textContent = "S/ 0.00";
  elements.announcement.textContent = "";
  renderEmptyRow();
  lastUpdateAt = 0;
  if (removeStorage) removeStoredState();
}

function payloadBelongsToThisDisplay(payload) {
  return productDispatchCustomerDisplayPayloadMatches(payload, {
    branchId: BRANCH_ID,
    userId: USER_ID,
    producerId: PRODUCER_ID
  });
}

function payloadIsOlder({
  payloadTimestamp,
  payloadRevision,
  payloadProducerInstance,
  hasTimestamp,
  hasRevision,
  hasProducerInstance
}) {
  const sameInstance = hasProducerInstance && payloadProducerInstance === lastProducerInstance;
  return Boolean(
    (hasProducerInstance && lastProducerInstance > 0 && payloadProducerInstance < lastProducerInstance)
    || (!hasProducerInstance && lastProducerInstance > 0)
    || (sameInstance && hasRevision && payloadRevision <= lastRevision)
    || (sameInstance && !hasRevision && hasTimestamp && payloadTimestamp < lastPayloadTimestamp)
    || (
      !hasProducerInstance
      && !lastProducerInstance
      && hasRevision
      && payloadRevision <= lastRevision
      && (!hasTimestamp || payloadTimestamp <= lastPayloadTimestamp)
    )
    || (
      !hasProducerInstance
      && !lastProducerInstance
      && !hasRevision
      && hasTimestamp
      && payloadTimestamp < lastPayloadTimestamp
    )
  );
}

function renderRows(rows, currency) {
  const safeRows = Array.isArray(rows) ? rows : [];
  if (safeRows.length === 0) {
    renderEmptyRow();
    return;
  }

  const fragment = document.createDocumentFragment();
  safeRows.forEach((item) => {
    const row = document.createElement("tr");
    const product = document.createElement("td");
    const quantity = document.createElement("td");
    const net = document.createElement("td");
    const amount = document.createElement("td");
    product.textContent = String(item?.name || "Producto");
    quantity.textContent = String(Math.max(0, Math.trunc(Number(item?.quantity) || 0)));
    net.textContent = formatWeight(item?.netWeightKg);
    amount.textContent = formatAmount(item?.amount, currency);
    row.append(product, quantity, net, amount);
    fragment.append(row);
  });
  elements.rows.replaceChildren(fragment);
}

function previewStatusLabel(preview) {
  if (normalizeNumber(preview.netWeightKg) === null) {
    if (preview.status === "unavailable") return "Lectura no disponible";
    if (preview.status === "calculating") return "Completa los datos";
    return "Sin cálculo";
  }
  if (preview.status === "manual") return "Neto manual";
  if (preview.status === "stable") return "Neto estable";
  return "Neto en vivo";
}

function renderPayload(payload) {
  if (!payloadBelongsToThisDisplay(payload)) return;

  const payloadTimestamp = Date.parse(payload.updatedAt || "");
  const payloadRevision = Number(payload.revision);
  const payloadProducerInstance = Number(payload.producerInstance);
  const hasTimestamp = Number.isFinite(payloadTimestamp);
  const hasRevision = Number.isFinite(payloadRevision) && payloadRevision > 0;
  const hasProducerInstance = Number.isSafeInteger(payloadProducerInstance)
    && payloadProducerInstance > 0;

  if (
    (hasTimestamp && Date.now() - payloadTimestamp > DISPLAY_TTL_MS)
    || payloadIsOlder({
      payloadTimestamp,
      payloadRevision,
      payloadProducerInstance,
      hasTimestamp,
      hasRevision,
      hasProducerInstance
    })
  ) return;

  const activeList = payload.activeList && typeof payload.activeList === "object"
    ? payload.activeList
    : {};
  const totals = activeList.totals && typeof activeList.totals === "object"
    ? activeList.totals
    : {};
  const preview = payload.preview && typeof payload.preview === "object"
    ? payload.preview
    : {};
  const currency = String(payload.currency || "PEN");
  const previewNet = normalizeNumber(preview.netWeightKg);
  const previewAmount = normalizeNumber(preview.amount);
  const customer = String(activeList.customer || "Venta al público");
  const listNumber = Math.max(1, Math.trunc(Number(activeList.number) || 1));
  const listAmount = formatAmount(totals.amount, currency);

  const companyTitle = String(payload.companyTitle || "Despacho de productos");
  elements.title.textContent = companyTitle;
  document.title = `${companyTitle} | Sistema Pollos`;
  elements.status.textContent = "En vivo";
  elements.status.classList.remove("is-waiting");
  elements.liveNet.textContent = previewNet === null ? "---" : formatWeightValue(previewNet);
  elements.liveAmount.textContent = previewNet === null
    ? `${currencyLabel(currency)} 0.00`
    : (previewAmount === null ? `${currencyLabel(currency)} --` : formatAmount(previewAmount, currency));
  elements.liveStatus.textContent = previewStatusLabel(preview);
  elements.listHeading.textContent = `Lista ${listNumber}`;
  elements.customer.textContent = customer;
  elements.listNet.textContent = formatWeight(totals.netWeightKg);
  elements.listAmount.textContent = listAmount;
  renderRows(activeList.rows, currency);

  const announcement = `${customer}. Lista ${listNumber}. Neto de la lista ${formatWeight(totals.netWeightKg)}. Total ${listAmount}.`;
  if (elements.announcement.textContent !== announcement) {
    elements.announcement.textContent = announcement;
  }

  if (hasProducerInstance) {
    if (payloadProducerInstance !== lastProducerInstance) {
      lastRevision = 0;
      lastPayloadTimestamp = 0;
    }
    lastProducerInstance = payloadProducerInstance;
  }
  if (hasRevision) lastRevision = payloadRevision;
  if (hasTimestamp) lastPayloadTimestamp = payloadTimestamp;
  lastUpdateAt = Date.now();
}

function resetMatches(payload) {
  return Boolean(
    payload?.type === PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE
    && String(payload.branchId || "") === BRANCH_ID
    && String(payload.userId || "") === USER_ID
    && String(payload.producerId || "") === PRODUCER_ID
  );
}

function handleReset(payload) {
  if (!resetMatches(payload)) return;
  lastRevision = Number.MAX_SAFE_INTEGER;
  clearDisplay("Despacho cerrado", true);
}

function readStoredState() {
  if (!PRODUCER_ID) return;
  try {
    const payload = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
    const payloadTimestamp = Date.parse(payload?.updatedAt || "");
    if (Number.isFinite(payloadTimestamp) && Date.now() - payloadTimestamp > DISPLAY_TTL_MS) {
      clearDisplay("Esperando despacho", true);
      return;
    }
    renderPayload(payload);
  } catch {
    // BroadcastChannel mantiene la sincronización si localStorage no está disponible.
  }
}

function requestCurrentState() {
  if (!BRANCH_ID || !USER_ID || !PRODUCER_ID) return;
  channel?.postMessage({
    type: PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE,
    branchId: BRANCH_ID,
    userId: USER_ID,
    producerId: PRODUCER_ID
  });
}

function screenName(screen, index) {
  return String(screen.label || "").trim() || `Pantalla ${index + 1}`;
}

function screenDimensions(screen) {
  const width = Number(screen.width) || Number(screen.availWidth) || 0;
  const height = Number(screen.height) || Number(screen.availHeight) || 0;
  return width > 0 && height > 0 ? `${width} × ${height}` : "Resolución no disponible";
}

function setScreenFeedback(message, isError = false) {
  elements.screenFeedback.textContent = message;
  elements.screenFeedback.classList.toggle("is-error", isError);
}

async function requestFullscreenOnScreen(screen, index) {
  const name = screenName(screen, index);
  setScreenFeedback(`Abriendo ${name}…`);
  try {
    if (document.fullscreenElement) await document.exitFullscreen();
    await document.documentElement.requestFullscreen({ navigationUI: "hide", screen });
    elements.screenDialog.close();
    elements.chooseScreenLabel.textContent = "Cambiar monitor";
  } catch {
    try {
      globalThis.moveTo(
        Number(screen.availLeft) || Number(screen.left) || 0,
        Number(screen.availTop) || Number(screen.top) || 0
      );
      globalThis.resizeTo(
        Number(screen.availWidth) || Number(screen.width),
        Number(screen.availHeight) || Number(screen.height)
      );
      await document.documentElement.requestFullscreen({ navigationUI: "hide" });
      elements.screenDialog.close();
    } catch {
      setScreenFeedback(`No fue posible abrir ${name}. Revisa el permiso de ventanas.`, true);
    }
  }
}

function renderScreenChoices(screens) {
  elements.screenList.replaceChildren();
  screens.forEach((screen, index) => {
    const button = document.createElement("button");
    const heading = document.createElement("strong");
    const detail = document.createElement("span");
    const badges = [];
    button.type = "button";
    button.setAttribute("role", "listitem");
    heading.textContent = screenName(screen, index);
    if (screen.isPrimary) badges.push("Principal");
    if (screen.isInternal) badges.push("Integrada");
    detail.textContent = [screenDimensions(screen), ...badges].join(" · ");
    button.append(heading, detail);
    button.addEventListener("click", () => requestFullscreenOnScreen(screen, index));
    elements.screenList.append(button);
  });
}

async function openScreenPicker() {
  typographySettings?.close({ restoreFocus: false });
  elements.screenList.replaceChildren();
  setScreenFeedback("Buscando pantallas…");
  elements.screenDialog.showModal();

  if (!("getScreenDetails" in globalThis)) {
    setScreenFeedback("Este navegador no permite elegir el monitor.", true);
    return;
  }

  try {
    const details = await globalThis.getScreenDetails();
    const screens = Array.from(details.screens || []);
    if (screens.length === 0) {
      setScreenFeedback("No se encontraron pantallas.", true);
      return;
    }
    renderScreenChoices(screens);
    setScreenFeedback(`${screens.length} ${screens.length === 1 ? "pantalla" : "pantallas"}.`);
  } catch (error) {
    setScreenFeedback(
      error?.name === "NotAllowedError"
        ? "Permite el acceso a las pantallas e inténtalo de nuevo."
        : "No fue posible consultar los monitores.",
      true
    );
  }
}

const scopeIsValid = Boolean(
  BRANCH_ID
  && USER_ID
  && PRODUCER_ID
  && AUTHENTICATED_USER_ID
  && USER_ID === AUTHENTICATED_USER_ID
);

typographySettings = initializeProductCustomerDisplayTypography({
  document,
  window: globalThis,
  branchId: BRANCH_ID,
  userId: USER_ID,
  enabled: scopeIsValid,
  beforeOpen() {
    if (elements.screenDialog.open) elements.screenDialog.close();
  }
});

if (scopeIsValid && "BroadcastChannel" in globalThis) {
  channel = new BroadcastChannel(CHANNEL_NAME);
  channel.addEventListener("message", (event) => {
    if (event.data?.type === PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE) {
      handleReset(event.data);
      return;
    }
    renderPayload(event.data);
  });
}

globalThis.addEventListener("storage", (event) => {
  if (!scopeIsValid || event.key !== STORAGE_KEY) return;
  if (!event.newValue) {
    clearDisplay("Despacho cerrado");
    return;
  }
  try {
    renderPayload(JSON.parse(event.newValue));
  } catch {
    // Se ignoran mensajes parciales o inválidos.
  }
});

elements.fullscreen.addEventListener("click", async () => {
  typographySettings?.close({ restoreFocus: false });
  try {
    if (document.fullscreenElement) await document.exitFullscreen();
    else await document.documentElement.requestFullscreen({ navigationUI: "hide" });
  } catch {
    // El usuario también puede usar F11 si su navegador restringe esta acción.
  }
});
elements.chooseScreen.addEventListener("click", openScreenPicker);
elements.screenClose.addEventListener("click", () => elements.screenDialog.close());
elements.screenDialog.addEventListener("click", (event) => {
  if (event.target === elements.screenDialog) elements.screenDialog.close();
});
document.addEventListener("fullscreenchange", () => {
  const label = document.fullscreenElement
    ? "Salir de pantalla completa"
    : "Pantalla completa";
  elements.fullscreenLabel.textContent = label;
  elements.fullscreen.setAttribute("aria-label", label);
});

globalThis.setInterval(() => {
  requestCurrentState();
  if (lastUpdateAt && Date.now() - lastUpdateAt > DISPLAY_TTL_MS) {
    clearDisplay("Esperando despacho", true);
  }
}, 2000);

if (!scopeIsValid) {
  clearDisplay("Ábrela desde Despacho de productos");
} else {
  readStoredState();
  requestCurrentState();
}
