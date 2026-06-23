import { apiRequest } from "./api-client.js";

const elements = {
  date: document.getElementById("journeyDate"),
  window: document.getElementById("journeyWindow"),
  selectedCount: document.getElementById("journeySelectedCount"),
  totalCount: document.getElementById("journeyTotalCount"),
  status: document.getElementById("journeyStatus"),
  globalPrices: {
    POLLO_VIVO: document.getElementById("globalPriceLive"),
    POLLO_PELADO: document.getElementById("globalPriceDressed"),
    POLLO_BENEFICIADO: document.getElementById("globalPriceProcessed")
  },
  search: document.getElementById("journeySearch"),
  selectAll: document.getElementById("journeySelectAll"),
  save: document.getElementById("journeySaveBtn"),
  message: document.getElementById("journeyMessage"),
  tableHead: document.getElementById("journeyTableHead"),
  rows: document.getElementById("journeyRows")
};

let journey = null;

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function formatDate(value, options) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "--";
  }

  return new Intl.DateTimeFormat("es-CO", options).format(date);
}

function setMessage(message, isError = false) {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", isError);
}

function selectedVehicleIds() {
  return Array.from(
    elements.rows.querySelectorAll(".journey-truck-check:checked")
  ).map((checkbox) => Number(checkbox.value));
}

function visibleChecks() {
  return Array.from(elements.rows.querySelectorAll(".journey-truck-row:not([hidden]) .journey-truck-check"));
}

function updateSelectionState() {
  const selectedIds = selectedVehicleIds();
  const visible = visibleChecks();

  elements.selectedCount.textContent = String(selectedIds.length);
  elements.selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
  elements.selectAll.indeterminate = visible.some((checkbox) => checkbox.checked)
    && !elements.selectAll.checked;

  elements.rows.querySelectorAll(".journey-truck-row").forEach((row) => {
    const checkbox = row.querySelector(".journey-truck-check");
    row.classList.toggle("is-selected", Boolean(checkbox?.checked));
  });
}

function applySearch() {
  const query = elements.search.value.trim().toLocaleLowerCase("es");

  elements.rows.querySelectorAll(".journey-truck-row").forEach((row) => {
    row.hidden = Boolean(query) && !row.dataset.search.includes(query);
  });

  updateSelectionState();
}

function renderJourney() {
  const types = Array.isArray(journey.chicken_types) ? journey.chicken_types : [];
  const trucks = Array.isArray(journey.trucks) ? journey.trucks : [];

  elements.date.textContent = formatDate(`${journey.operating_date}T12:00:00`, {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric"
  });
  elements.window.textContent = `${formatDate(journey.starts_at, {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  })} — ${formatDate(journey.ends_at, {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  })}`;
  elements.status.textContent = String(journey.status || "SIN CONFIGURAR").replaceAll("_", " ");
  elements.totalCount.textContent = String(trucks.length);
  Object.entries(elements.globalPrices).forEach(([code, input]) => {
    const price = journey.global_prices?.[code];
    input.value = price === null || price === undefined
      ? ""
      : Number(price).toFixed(2);
  });

  elements.tableHead.innerHTML = `
    <th scope="col">Elegir</th>
    <th scope="col">Proveedor</th>
    <th scope="col">Placa</th>
    ${types.map((type) => `
      <th scope="col">
        <span>${escapeHtml(type.name)}</span>
        <small>Precio proveedor/kg</small>
      </th>
    `).join("")}
  `;

  if (!trucks.length) {
    elements.rows.innerHTML = `
      <tr>
        <td colspan="${3 + types.length}" class="journey-loading">
          No hay proveedores con placas activas. Asigna placas desde el directorio de proveedores.
        </td>
      </tr>
    `;
    updateSelectionState();
    return;
  }

  elements.rows.innerHTML = trucks.map((truck) => {
    const search = [
      truck.provider_name,
      truck.document,
      truck.plate,
      truck.alias
    ].join(" ").toLocaleLowerCase("es");

    return `
      <tr
        class="journey-truck-row ${truck.selected ? "is-selected" : ""}"
        data-search="${escapeHtml(search)}"
      >
        <td class="journey-check-cell">
          <input
            class="journey-truck-check"
            type="checkbox"
            value="${Number(truck.provider_vehicle_id)}"
            data-provider-id="${Number(truck.provider_id)}"
            aria-label="Seleccionar ${escapeHtml(truck.provider_name)} placa ${escapeHtml(truck.plate)}"
            ${truck.selected ? "checked" : ""}
          >
        </td>
        <td>
          <strong class="journey-provider-name">${escapeHtml(truck.provider_name)}</strong>
          <small>${escapeHtml(truck.document || "Sin documento")}</small>
        </td>
        <td>
          <strong class="journey-plate">${escapeHtml(truck.plate)}</strong>
          <small>${escapeHtml(truck.alias || "Camión activo")}</small>
        </td>
        ${types.map((type) => {
          const price = truck.prices?.[type.code];
          return `
            <td class="journey-price-cell">
              <strong class="journey-price-value">
                ${price === null || price === undefined ? "Sin precio" : Number(price).toFixed(2)}
              </strong>
            </td>
          `;
        }).join("")}
      </tr>
    `;
  }).join("");

  applySearch();
}

function buildPayload() {
  const globalPrices = Object.fromEntries(
    Object.entries(elements.globalPrices).map(([code, input]) => {
      const value = Number(input.value);
      if (!Number.isFinite(value) || value <= 0) {
        throw new Error("Ingresa los tres precios globales con valores mayores que cero.");
      }

      return [code, value];
    })
  );

  return {
    provider_vehicle_ids: selectedVehicleIds(),
    global_prices: globalPrices
  };
}

async function saveJourney() {
  let payload;

  try {
    payload = buildPayload();
  } catch (error) {
    setMessage(error.message, true);
    return;
  }

  elements.save.disabled = true;
  setMessage("Guardando selección y precios globales...");

  try {
    const response = await apiRequest("/operacion/jornada", {
      method: "PUT",
      body: JSON.stringify(payload)
    });
    journey = response.data;
    renderJourney();
    setMessage(response.message || "Jornada actualizada correctamente.");
  } catch (error) {
    const validationMessage = Object.values(error.data?.errors || {})[0]?.[0];
    setMessage(validationMessage || error.message, true);
  } finally {
    elements.save.disabled = false;
  }
}

elements.search.addEventListener("input", applySearch);
elements.selectAll.addEventListener("change", () => {
  visibleChecks().forEach((checkbox) => {
    checkbox.checked = elements.selectAll.checked;
  });
  updateSelectionState();
});
elements.rows.addEventListener("change", (event) => {
  if (event.target.matches(".journey-truck-check")) {
    updateSelectionState();
  }
});
elements.save.addEventListener("click", saveJourney);

apiRequest("/operacion/jornada")
  .then((response) => {
    journey = response.data;
    renderJourney();
    setMessage(
      journey.configured
        ? "La jornada está configurada. Puedes modificarla y guardar nuevamente."
        : "Selecciona los camiones que estarán disponibles en despacho."
    );
  })
  .catch((error) => {
    elements.rows.innerHTML = `
      <tr>
        <td colspan="6" class="journey-loading">No se pudo cargar la jornada.</td>
      </tr>
    `;
    setMessage(error.message, true);
  });
