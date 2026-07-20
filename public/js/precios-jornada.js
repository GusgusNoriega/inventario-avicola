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

  Object.entries(elements.prices).forEach(([code, input]) => {
    const price = data.global_prices?.[code];
    input.value = price === null || price === undefined ? "" : Number(price).toFixed(2);
  });
}

function buildPayload() {
  return {
    global_prices: Object.fromEntries(Object.entries(elements.prices).map(([code, input]) => {
      const value = Number(input.value);
      if (!Number.isFinite(value) || value <= 0) {
        throw new Error("Ingresa los tres precios con valores mayores que cero.");
      }
      return [code, value];
    }))
  };
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
    setMessage(validationMessage || error.message, true);
  } finally {
    elements.save.disabled = false;
  }
}

elements.save.addEventListener("click", savePrices);

apiRequest("/operacion/precios-jornada")
  .then((response) => {
    render(response.data);
    setMessage("Puedes actualizar los precios sin modificar los orígenes de la jornada.");
  })
  .catch((error) => setMessage(error.message, true));
