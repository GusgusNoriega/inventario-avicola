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
  fullscreen: document.getElementById("customerDisplayFullscreen")
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
