const CHANNEL_NAME = "sistema-pollos-pantalla-cliente-v1";
const STORAGE_KEY_PREFIX = "sistema-pollos-pantalla-cliente-estado-v1";
const PRODUCER_ID = new URLSearchParams(window.location.search).get("source") || "";
const STORAGE_KEY = PRODUCER_ID ? `${STORAGE_KEY_PREFIX}:${PRODUCER_ID}` : STORAGE_KEY_PREFIX;

const elements = {
  name: document.getElementById("customerDisplayName"),
  ticket: document.getElementById("customerDisplayTicket"),
  scales: {
    1: {
      card: document.getElementById("customerDisplayScaleCard1"),
      weight: document.getElementById("customerDisplayScale1"),
      status: document.getElementById("customerDisplayScaleStatus1")
    },
    2: {
      card: document.getElementById("customerDisplayScaleCard2"),
      weight: document.getElementById("customerDisplayScale2"),
      status: document.getElementById("customerDisplayScaleStatus2")
    }
  },
  records: document.getElementById("customerDisplayRecords"),
  cages: document.getElementById("customerDisplayCages"),
  birds: document.getElementById("customerDisplayBirds"),
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

function normalizeWeight(value) {
  if (value === null || value === undefined || String(value).trim() === "") {
    return null;
  }

  const weight = Number(value);
  return Number.isFinite(weight) && weight >= 0 ? weight : null;
}

function getPayloadScaleWeight(payload, scaleId) {
  const scale = payload.scales?.[scaleId];
  if (scale && Object.prototype.hasOwnProperty.call(scale, "weightKg")) {
    return normalizeWeight(scale.weightKg);
  }

  return String(payload.weightSource || "") === String(scaleId)
    ? normalizeWeight(payload.weightKg)
    : null;
}

function renderScale(payload, scaleId) {
  const scaleElements = elements.scales[scaleId];
  const weight = getPayloadScaleWeight(payload, scaleId);
  const isSelected = String(payload.weightSource || "") === String(scaleId);

  scaleElements.weight.textContent = weight === null ? "---" : weight.toFixed(2);
  scaleElements.card.classList.toggle("is-selected", isSelected);
  scaleElements.card.classList.toggle("has-reading", weight !== null);
  scaleElements.status.classList.toggle("is-waiting", weight === null);
  scaleElements.status.textContent = weight === null
    ? (isSelected ? "Seleccionada · sin lectura" : "Sin lectura")
    : (isSelected ? "Seleccionada" : "En vivo");
}

function renderPayload(payload) {
  if (
    !payload
    || payload.type !== "customer-display-state"
    || (PRODUCER_ID && payload.producerId !== PRODUCER_ID)
  ) {
    return;
  }

  const payloadTimestamp = Date.parse(payload.updatedAt || "");
  const payloadRevision = Number(payload.revision);
  const payloadProducerInstance = Number(payload.producerInstance);
  const hasRevision = Number.isFinite(payloadRevision) && payloadRevision > 0;
  const hasTimestamp = Number.isFinite(payloadTimestamp);
  const hasProducerInstance = Number.isSafeInteger(payloadProducerInstance)
    && payloadProducerInstance > 0;
  const isSameProducerInstance = hasProducerInstance
    && payloadProducerInstance === lastProducerInstance;
  if (
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
  ) {
    return;
  }

  const ticket = payload.ticket && typeof payload.ticket === "object" ? payload.ticket : {};
  const customerName = String(payload.customerName || "Sin cliente asignado");
  const ticketLabel = String(ticket.label || payload.ticketLabel || "Sin ticket seleccionado");
  const recordCount = normalizeCount(ticket.records ?? payload.records);
  const cageCount = normalizeCount(ticket.javas ?? ticket.cages ?? payload.cages);
  const birdCount = normalizeCount(ticket.birds ?? payload.ticketBirds ?? payload.birds);
  const announcement = `${customerName}. ${ticketLabel}. ${birdCount} ${birdCount === 1 ? "ave" : "aves"} en el ticket.`;

  elements.name.textContent = customerName;
  elements.ticket.textContent = ticketLabel;
  elements.records.textContent = String(recordCount);
  elements.cages.textContent = String(cageCount);
  elements.birds.textContent = String(birdCount);
  if (elements.announcement.textContent !== announcement) {
    elements.announcement.textContent = announcement;
  }
  renderScale(payload, 1);
  renderScale(payload, 2);
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

function readStoredState() {
  try {
    renderPayload(JSON.parse(localStorage.getItem(STORAGE_KEY) || "null"));
  } catch {
    // La pantalla puede seguir esperando aunque el navegador bloquee localStorage.
  }
}

function requestCurrentState() {
  channel?.postMessage({
    type: "customer-display-request",
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
    elements.chooseScreen.textContent = `Cambiar pantalla`;
  } catch {
    try {
      window.moveTo(Number(screen.availLeft) || Number(screen.left) || 0, Number(screen.availTop) || Number(screen.top) || 0);
      window.resizeTo(Number(screen.availWidth) || Number(screen.width), Number(screen.availHeight) || Number(screen.height));
      await document.documentElement.requestFullscreen({ navigationUI: "hide" });
      elements.screenDialog.close();
      elements.chooseScreen.textContent = `Cambiar pantalla`;
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

  if (!("getScreenDetails" in window)) {
    setScreenFeedback(
      "Este navegador no permite elegir el monitor. Abre la aplicación con una versión reciente de Chrome o Edge.",
      true
    );
    return;
  }

  try {
    const screenDetails = await window.getScreenDetails();
    const screens = Array.from(screenDetails.screens || []);

    if (screens.length === 0) {
      setScreenFeedback("No se encontraron pantallas disponibles.", true);
      return;
    }

    renderScreenChoices(screens);
    setScreenFeedback(`${screens.length} ${screens.length === 1 ? "pantalla encontrada" : "pantallas encontradas"}.`);
  } catch (error) {
    const permissionDenied = error?.name === "NotAllowedError";
    setScreenFeedback(
      permissionDenied
        ? "Chrome necesita permiso para identificar y administrar tus pantallas. Permite el acceso e inténtalo nuevamente."
        : "No fue posible consultar las pantallas conectadas.",
      true
    );
  }
}

if ("BroadcastChannel" in window) {
  channel = new BroadcastChannel(CHANNEL_NAME);
  channel.addEventListener("message", (event) => renderPayload(event.data));
}

window.addEventListener("storage", (event) => {
  if (event.key === STORAGE_KEY && event.newValue) {
    try {
      renderPayload(JSON.parse(event.newValue));
    } catch {
      // Se ignoran mensajes parciales o inválidos.
    }
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
  elements.fullscreen.textContent = document.fullscreenElement ? "Salir de pantalla completa" : "Pantalla completa";
});

window.setInterval(() => {
  requestCurrentState();
  if (lastUpdateAt && Date.now() - lastUpdateAt > 5000) {
    elements.status.textContent = "Reconectando";
    elements.status.classList.add("is-waiting");
  }
}, 2000);

readStoredState();
requestCurrentState();
