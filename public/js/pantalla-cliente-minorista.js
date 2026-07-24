import {
  buildRetailCustomerDisplayChannelName,
  buildRetailCustomerDisplayStorageKey,
  retailCustomerDisplayPayloadMatches,
  RETAIL_CUSTOMER_DISPLAY_REQUEST_TYPE,
  RETAIL_CUSTOMER_DISPLAY_RESET_TYPE
} from "./retail-customer-display.js";

const RETAIL_STATION = String(document.body.dataset.retailStation || "");
const PRODUCER_ID = new URLSearchParams(globalThis.location.search).get("source") || "";
const CHANNEL_NAME = buildRetailCustomerDisplayChannelName(RETAIL_STATION);
const STORAGE_KEY = buildRetailCustomerDisplayStorageKey(RETAIL_STATION, PRODUCER_ID);
const DISPLAY_TTL_MS = 8000;

const elements = {
  name: document.getElementById("customerDisplayName"),
  ticket: document.getElementById("customerDisplayTicket"),
  scaleCard: document.getElementById("customerDisplayScaleCard1"),
  scaleWeight: document.getElementById("customerDisplayScale1"),
  scaleStatus: document.getElementById("customerDisplayScaleStatus1"),
  records: document.getElementById("customerDisplayRecords"),
  trays: document.getElementById("customerDisplayTrays"),
  birds: document.getElementById("customerDisplayBirds"),
  netWeight: document.getElementById("customerDisplayNetWeight"),
  amount: document.getElementById("customerDisplayAmount"),
  announcement: document.getElementById("customerDisplayAnnouncement"),
  status: document.getElementById("customerDisplayStatus"),
  fullscreen: document.getElementById("customerDisplayFullscreen"),
  chooseScreen: document.getElementById("customerDisplayChooseScreen"),
  screenDialog: document.getElementById("customerDisplayScreenDialog"),
  screenClose: document.getElementById("customerDisplayScreenClose"),
  screenList: document.getElementById("customerDisplayScreenList"),
  screenFeedback: document.getElementById("customerDisplayScreenFeedback")
};

let channel = null;
let lastUpdateAt = 0;
let lastPayloadTimestamp = 0;
let lastRevision = 0;
let lastProducerInstance = 0;

function normalizeCount(value) {
  const count = Math.trunc(Number(value));
  return Number.isFinite(count) && count >= 0 ? count : 0;
}

function normalizeNumber(value) {
  if (value === null || value === undefined || String(value).trim() === "") {
    return null;
  }

  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function formatAmount(value) {
  const amount = normalizeNumber(value) ?? 0;
  const absolute = Math.abs(amount).toLocaleString("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
  return amount < 0 ? `-S/ ${absolute}` : `S/ ${absolute}`;
}

function removeStoredState() {
  if (!PRODUCER_ID) {
    return;
  }

  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // El estado visible se limpia aunque el navegador bloquee localStorage.
  }
}

function clearDisplay(statusMessage = "Esperando despacho", removeStorage = false) {
  elements.name.textContent = "Esperando cliente";
  elements.ticket.textContent = "Sin lista vinculada";
  elements.scaleWeight.textContent = "---";
  elements.scaleCard.classList.remove("has-reading", "is-selected");
  elements.scaleStatus.textContent = "Sin cálculo";
  elements.scaleStatus.classList.add("is-waiting");
  elements.records.textContent = "0";
  elements.trays.textContent = "0";
  elements.birds.textContent = "0";
  elements.netWeight.textContent = "0.000 kg";
  elements.amount.textContent = "S/ 0.00";
  elements.amount.classList.remove("is-pending");
  elements.announcement.textContent = "";
  elements.status.textContent = statusMessage;
  elements.status.classList.add("is-waiting");
  lastUpdateAt = 0;

  if (removeStorage) {
    removeStoredState();
  }
}

function payloadBelongsToThisDisplay(payload) {
  return retailCustomerDisplayPayloadMatches(payload, {
    station: RETAIL_STATION,
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
  const isSameProducerInstance = hasProducerInstance
    && payloadProducerInstance === lastProducerInstance;

  return (
    (hasProducerInstance && lastProducerInstance > 0 && payloadProducerInstance < lastProducerInstance)
    || (!hasProducerInstance && lastProducerInstance > 0)
    || (isSameProducerInstance && hasRevision && payloadRevision <= lastRevision)
    || (
      isSameProducerInstance
      && !hasRevision
      && hasTimestamp
      && payloadTimestamp < lastPayloadTimestamp
    )
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

function renderPayload(payload) {
  if (!payloadBelongsToThisDisplay(payload)) {
    return;
  }

  const payloadTimestamp = Date.parse(payload.updatedAt || "");
  const payloadRevision = Number(payload.revision);
  const payloadProducerInstance = Number(payload.producerInstance);
  const hasRevision = Number.isFinite(payloadRevision) && payloadRevision > 0;
  const hasTimestamp = Number.isFinite(payloadTimestamp);
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
  ) {
    return;
  }

  const ticket = payload.ticket && typeof payload.ticket === "object" ? payload.ticket : {};
  const scale = payload.scale && typeof payload.scale === "object" ? payload.scale : {};
  const customerName = String(payload.customerName || "Venta sin cliente");
  const ticketLabel = String(ticket.label || "Sin lista vinculada");
  const birdCount = normalizeCount(ticket.birds);
  const trayCount = normalizeCount(ticket.trays);
  const weighingCount = normalizeCount(ticket.weighings);
  const netWeight = normalizeNumber(ticket.netWeightKg) ?? 0;
  const displayWeight = normalizeNumber(scale.displayWeightKg);
  const pricingComplete = ticket.pricingComplete !== false && normalizeNumber(ticket.amount) !== null;
  const amountLabel = pricingComplete ? formatAmount(ticket.amount) : "Precio pendiente";
  const announcement = pricingComplete
    ? `${customerName}. ${ticketLabel}. ${birdCount} ${birdCount === 1 ? "pollo" : "pollos"}, ${trayCount} ${trayCount === 1 ? "bandeja" : "bandejas"}. Total ${amountLabel}.`
    : `${customerName}. ${ticketLabel}. ${birdCount} ${birdCount === 1 ? "pollo" : "pollos"}, ${trayCount} ${trayCount === 1 ? "bandeja" : "bandejas"}. Precio pendiente.`;

  elements.name.textContent = customerName;
  elements.ticket.textContent = ticketLabel;
  elements.records.textContent = String(weighingCount);
  elements.trays.textContent = String(trayCount);
  elements.birds.textContent = String(birdCount);
  elements.netWeight.textContent = `${netWeight.toFixed(3)} kg`;
  elements.amount.textContent = amountLabel;
  elements.amount.classList.toggle("is-pending", !pricingComplete);
  elements.scaleWeight.textContent = displayWeight === null ? "---" : displayWeight.toFixed(3);
  elements.scaleCard.classList.toggle("has-reading", displayWeight !== null);
  elements.scaleCard.classList.toggle("is-selected", displayWeight !== null);
  elements.scaleStatus.classList.toggle("is-waiting", displayWeight === null);
  elements.scaleStatus.textContent = displayWeight === null
    ? "Sin cálculo"
    : (scale.isStable && scale.isFresh ? "Neto estable" : "Neto en vivo");
  if (elements.announcement.textContent !== announcement) {
    elements.announcement.textContent = announcement;
  }
  elements.status.textContent = "En vivo";
  elements.status.classList.remove("is-waiting");

  if (hasProducerInstance) {
    if (payloadProducerInstance !== lastProducerInstance) {
      lastRevision = 0;
      lastPayloadTimestamp = 0;
    }
    lastProducerInstance = payloadProducerInstance;
  }
  if (hasRevision) {
    lastRevision = payloadRevision;
  }
  if (hasTimestamp) {
    lastPayloadTimestamp = payloadTimestamp;
  }
  lastUpdateAt = Date.now();
}

function handleReset(payload) {
  if (
    !PRODUCER_ID
    || payload?.type !== RETAIL_CUSTOMER_DISPLAY_RESET_TYPE
    || String(payload.station || "") !== RETAIL_STATION
    || payload.producerId !== PRODUCER_ID
  ) {
    return;
  }

  lastRevision = Number.MAX_SAFE_INTEGER;
  clearDisplay("Despacho cerrado", true);
}

function readStoredState() {
  if (!PRODUCER_ID) {
    return;
  }

  try {
    const payload = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
    const payloadTimestamp = Date.parse(payload?.updatedAt || "");
    if (Number.isFinite(payloadTimestamp) && Date.now() - payloadTimestamp > DISPLAY_TTL_MS) {
      clearDisplay("Esperando despacho", true);
      return;
    }
    renderPayload(payload);
  } catch {
    // La pantalla puede seguir esperando aunque el navegador bloquee localStorage.
  }
}

function requestCurrentState() {
  if (!PRODUCER_ID || !RETAIL_STATION) {
    return;
  }

  channel?.postMessage({
    type: RETAIL_CUSTOMER_DISPLAY_REQUEST_TYPE,
    station: RETAIL_STATION,
    producerId: PRODUCER_ID
  });
}

function screenName(screen, index) {
  const label = String(screen.label || "").trim();
  return label || `Pantalla ${index + 1}`;
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
  const selectedName = screenName(screen, index);
  setScreenFeedback(`Abriendo ${selectedName}…`);

  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
    }

    await document.documentElement.requestFullscreen({
      navigationUI: "hide",
      screen
    });
    elements.screenDialog.close();
    elements.chooseScreen.textContent = "Cambiar pantalla";
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
      elements.chooseScreen.textContent = "Cambiar pantalla";
    } catch {
      setScreenFeedback(
        `No fue posible abrir ${selectedName}. Revisa el permiso para administrar ventanas de Chrome.`,
        true
      );
    }
  }
}

function renderScreenChoices(screens) {
  elements.screenList.replaceChildren();

  screens.forEach((screen, index) => {
    const button = document.createElement("button");
    const heading = document.createElement("strong");
    const details = document.createElement("span");
    const badges = [];

    button.type = "button";
    button.className = "customer-display-screen-option";
    button.setAttribute("role", "listitem");
    heading.textContent = screenName(screen, index);
    if (screen.isPrimary) badges.push("Principal");
    if (screen.isInternal) badges.push("Integrada");
    details.textContent = [screenDimensions(screen), ...badges].join(" · ");
    button.append(heading, details);
    button.addEventListener("click", () => requestFullscreenOnScreen(screen, index));
    elements.screenList.append(button);
  });
}

async function openScreenPicker() {
  elements.screenList.replaceChildren();
  setScreenFeedback("Buscando pantallas conectadas…");
  elements.screenDialog.showModal();

  if (!("getScreenDetails" in globalThis)) {
    setScreenFeedback(
      "Este navegador no permite elegir el monitor. Abre la aplicación con una versión reciente de Chrome o Edge.",
      true
    );
    return;
  }

  try {
    const screenDetails = await globalThis.getScreenDetails();
    const screens = Array.from(screenDetails.screens || []);
    if (screens.length === 0) {
      setScreenFeedback("No se encontraron pantallas disponibles.", true);
      return;
    }

    renderScreenChoices(screens);
    setScreenFeedback(`${screens.length} ${screens.length === 1 ? "pantalla encontrada" : "pantallas encontradas"}.`);
  } catch (error) {
    setScreenFeedback(
      error?.name === "NotAllowedError"
        ? "Chrome necesita permiso para identificar y administrar tus pantallas. Permite el acceso e inténtalo nuevamente."
        : "No fue posible consultar las pantallas conectadas.",
      true
    );
  }
}

if ("BroadcastChannel" in globalThis && PRODUCER_ID && RETAIL_STATION) {
  channel = new BroadcastChannel(CHANNEL_NAME);
  channel.addEventListener("message", (event) => {
    if (event.data?.type === RETAIL_CUSTOMER_DISPLAY_RESET_TYPE) {
      handleReset(event.data);
      return;
    }
    renderPayload(event.data);
  });
}

globalThis.addEventListener("storage", (event) => {
  if (event.key !== STORAGE_KEY || !PRODUCER_ID) {
    return;
  }

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
  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
    } else {
      await document.documentElement.requestFullscreen();
    }
  } catch {
    // Algunos navegadores requieren usar F11 para entrar en pantalla completa.
  }
});

elements.chooseScreen.addEventListener("click", openScreenPicker);
elements.screenClose.addEventListener("click", () => elements.screenDialog.close());
elements.screenDialog.addEventListener("click", (event) => {
  if (event.target === elements.screenDialog) {
    elements.screenDialog.close();
  }
});

document.addEventListener("fullscreenchange", () => {
  elements.fullscreen.textContent = document.fullscreenElement
    ? "Salir de pantalla completa"
    : "Pantalla completa";
});

globalThis.setInterval(() => {
  requestCurrentState();
  if (lastUpdateAt && Date.now() - lastUpdateAt > DISPLAY_TTL_MS) {
    clearDisplay("Esperando despacho", true);
  }
}, 2000);

if (!PRODUCER_ID || !RETAIL_STATION) {
  clearDisplay("Abre esta pantalla desde el despacho minorista");
} else {
  readStoredState();
  requestCurrentState();
}
