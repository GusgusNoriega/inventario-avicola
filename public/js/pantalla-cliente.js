const CHANNEL_NAME = "sistema-pollos-pantalla-cliente-v1";
const STORAGE_KEY_PREFIX = "sistema-pollos-pantalla-cliente-estado-v1";
const PRODUCER_ID = new URLSearchParams(window.location.search).get("source") || "";
const STORAGE_KEY = PRODUCER_ID ? `${STORAGE_KEY_PREFIX}:${PRODUCER_ID}` : STORAGE_KEY_PREFIX;

const elements = {
  name: document.getElementById("customerDisplayName"),
  weight: document.getElementById("customerDisplayWeight"),
  cages: document.getElementById("customerDisplayCages"),
  birds: document.getElementById("customerDisplayBirds"),
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

function normalizeCount(value) {
  const count = Math.trunc(Number(value));
  return Number.isFinite(count) && count >= 0 ? count : 0;
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
  if (
    (Number.isFinite(payloadRevision) && payloadRevision > 0 && payloadRevision <= lastRevision)
    || (
      (!Number.isFinite(payloadRevision) || payloadRevision <= 0)
      && Number.isFinite(payloadTimestamp)
      && payloadTimestamp < lastPayloadTimestamp
    )
  ) {
    return;
  }

  const weight = Number(payload.weightKg);
  elements.name.textContent = String(payload.customerName || "Sin cliente asignado");
  elements.weight.textContent = Number.isFinite(weight) && weight >= 0 ? weight.toFixed(2) : "0.00";
  elements.cages.textContent = String(normalizeCount(payload.cages));
  elements.birds.textContent = String(normalizeCount(payload.birds));
  elements.status.textContent = "En vivo";
  elements.status.classList.remove("is-waiting");
  if (Number.isFinite(payloadRevision) && payloadRevision > 0) {
    lastRevision = payloadRevision;
  }
  if (Number.isFinite(payloadTimestamp)) {
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
