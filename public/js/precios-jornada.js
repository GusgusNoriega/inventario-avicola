import { apiRequest } from "./api-client.js";

const PERU_TIME_ZONE = "America/Lima";
const elements = {
  date: document.getElementById("journeyPriceDate"),
  window: document.getElementById("journeyPriceWindow"),
  message: document.getElementById("journeyPriceMessage"),
  save: document.getElementById("journeyPriceSave"),
  prices: {
    POLLO_VIVO: document.getElementById("journeyPriceLive"),
    POLLO_PELADO: document.getElementById("journeyPriceDressed"),
    POLLO_BENEFICIADO: document.getElementById("journeyPriceProcessed")
  }
};
let currentPrices = {};

function normalizePrice(value) {
  const price = Number(value);
  return Number.isFinite(price) && price > 0
    ? Math.round(price * 100) / 100
    : null;
}

function formatDate(value, options) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "--";

  return new Intl.DateTimeFormat("es-PE", {
    timeZone: PERU_TIME_ZONE,
    ...options
  }).format(date);
}

function setMessage(message, isError = false) {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", isError);
}

function render(data) {
  elements.date.textContent = formatDate(`${data.operating_date}T12:00:00`, {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric"
  });
  elements.window.textContent = `${formatDate(data.starts_at, {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  })} — ${formatDate(data.ends_at, {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  })}`;

  currentPrices = Object.fromEntries(
    Object.keys(elements.prices).map((code) => [
      code,
      normalizePrice(data.global_prices?.[code])
    ])
  );

  Object.entries(elements.prices).forEach(([code, input]) => {
    const price = currentPrices[code];
    input.value = price === null ? "" : price.toFixed(2);
  });
}

function buildPayload() {
  const globalPrices = Object.fromEntries(Object.entries(elements.prices).map(([code, input]) => {
    const value = Number(input.value);
    if (!Number.isFinite(value) || value <= 0) {
      throw new Error("Ingresa los tres precios con valores mayores que cero.");
    }
    return [code, value];
  }));

  return {
    global_prices: globalPrices,
    expected_prices: Object.fromEntries(
      Object.keys(globalPrices).map((code) => [code, currentPrices[code] ?? null])
    )
  };
}

async function loadPrices(message = "Puedes actualizar los precios sin modificar los orígenes de la jornada.") {
  const response = await apiRequest("/operacion/precios-jornada");
  render(response.data);
  setMessage(message);
}

async function savePrices() {
  let body;
  try {
    body = buildPayload();
  } catch (error) {
    setMessage(error.message, true);
    return;
  }

  elements.save.disabled = true;
  setMessage("Guardando precios de la jornada...");

  try {
    const response = await apiRequest("/operacion/precios-jornada", {
      method: "PUT",
      body: JSON.stringify(body)
    });
    render(response.data);
    setMessage(response.message || "Precios actualizados correctamente.");
  } catch (error) {
    const validationMessage = Object.values(error.data?.errors || {})[0]?.[0];
    const pricesChanged = Object.keys(error.data?.errors || {})
      .some((field) => field.startsWith("expected_prices"));
    if (pricesChanged) {
      try {
        await loadPrices();
        setMessage(
          `${validationMessage || "Los precios cambiaron en otra estación."} Se cargaron los valores vigentes para que los revises.`,
          true
        );
      } catch {
        setMessage(validationMessage || error.message, true);
      }
    } else {
      setMessage(validationMessage || error.message, true);
    }
  } finally {
    elements.save.disabled = false;
  }
}

elements.save.addEventListener("click", savePrices);

loadPrices()
  .catch((error) => setMessage(error.message, true));
