import { apiRequest } from "./api-client.js";

const PERU_TIME_ZONE = "America/Lima";
const elements = {
  date: document.getElementById("journeyPriceDate"),
  window: document.getElementById("journeyPriceWindow"),
  message: document.getElementById("journeyPriceMessage"),
  save: document.getElementById("journeyPriceSave"),
  ticketTitleForm: document.getElementById("ticketTitleForm"),
  ticketTitleInput: document.getElementById("ticketTitleInput"),
  ticketTitleStatus: document.getElementById("ticketTitleStatus"),
  ticketTitleSave: document.getElementById("ticketTitleSave"),
  ticketMessageForm: document.getElementById("ticketMessageForm"),
  ticketMessageInput: document.getElementById("ticketMessageInput"),
  ticketMessageStatus: document.getElementById("ticketMessageStatus"),
  ticketMessageSave: document.getElementById("ticketMessageSave"),
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

function setTicketMessageStatus(message, isError = false) {
  elements.ticketMessageStatus.textContent = message;
  elements.ticketMessageStatus.classList.toggle("is-error", isError);
}

function setTicketTitleStatus(message, isError = false) {
  elements.ticketTitleStatus.textContent = message;
  elements.ticketTitleStatus.classList.toggle("is-error", isError);
}

function renderTicketTitle(title) {
  elements.ticketTitleInput.value = typeof title === "string" ? title : "";
}

function renderTicketMessage(message) {
  elements.ticketMessageInput.value = typeof message === "string" ? message : "";
}

function renderPrices(data) {
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
  renderPrices(response.data);
  setMessage(message);

  return response.data;
}

async function loadPage() {
  const data = await loadPrices();
  renderTicketTitle(data.ticket_title);
  renderTicketMessage(data.ticket_message);
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
    renderPrices(response.data);
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

async function saveTicketMessage(event) {
  event.preventDefault();
  elements.ticketMessageSave.disabled = true;
  setTicketMessageStatus("Guardando mensaje...");

  try {
    const response = await apiRequest("/operacion/precios-jornada/mensaje-ticket", {
      method: "PUT",
      body: JSON.stringify({
        ticket_message: elements.ticketMessageInput.value
      })
    });
    renderTicketMessage(response.data?.ticket_message);
    setTicketMessageStatus(response.message || "Mensaje actualizado correctamente.");
  } catch (error) {
    const validationMessage = Object.values(error.data?.errors || {})[0]?.[0];
    setTicketMessageStatus(validationMessage || error.message, true);
  } finally {
    elements.ticketMessageSave.disabled = false;
  }
}

async function saveTicketTitle(event) {
  event.preventDefault();
  elements.ticketTitleSave.disabled = true;
  setTicketTitleStatus("Guardando título...");

  try {
    const response = await apiRequest("/operacion/precios-jornada/titulo-ticket", {
      method: "PUT",
      body: JSON.stringify({
        ticket_title: elements.ticketTitleInput.value
      })
    });
    renderTicketTitle(response.data?.ticket_title);
    setTicketTitleStatus(response.message || "Título actualizado correctamente.");
  } catch (error) {
    const validationMessage = Object.values(error.data?.errors || {})[0]?.[0];
    setTicketTitleStatus(validationMessage || error.message, true);
  } finally {
    elements.ticketTitleSave.disabled = false;
  }
}

elements.save.addEventListener("click", savePrices);
elements.ticketTitleForm.addEventListener("submit", saveTicketTitle);
elements.ticketMessageForm.addEventListener("submit", saveTicketMessage);

loadPage()
  .catch((error) => setMessage(error.message, true));
