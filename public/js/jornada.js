import { apiRequest } from "./api-client.js";

const PERU_TIME_ZONE = "America/Lima";

const elements = {
  date: document.getElementById("journeyDate"),
  window: document.getElementById("journeyWindow"),
  selectedCount: document.getElementById("journeySelectedCount"),
  totalCount: document.getElementById("journeyTotalCount"),
  status: document.getElementById("journeyStatus"),
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

  return new Intl.DateTimeFormat("es-PE", {
    timeZone: PERU_TIME_ZONE,
    ...options
  }).format(date);
}

function setMessage(message, isError = false) {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", isError);
}

function selectedVehicleIds() {
  return Array.from(
    elements.rows.querySelectorAll('.journey-origin-check[data-origin-kind="truck"]:checked')
  ).map((checkbox) => Number(checkbox.value));
}

function selectedWarehouseIds() {
  return Array.from(
    elements.rows.querySelectorAll('.journey-origin-check[data-origin-kind="warehouse"]:checked')
  ).map((checkbox) => Number(checkbox.value));
}

function visibleChecks() {
  return Array.from(elements.rows.querySelectorAll(".journey-origin-row:not([hidden]) .journey-origin-check"));
}

function updateSelectionState() {
  const selectedIds = [...selectedVehicleIds(), ...selectedWarehouseIds()];
  const visible = visibleChecks();

  elements.selectedCount.textContent = String(selectedIds.length);
  elements.selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
  elements.selectAll.indeterminate = visible.some((checkbox) => checkbox.checked)
    && !elements.selectAll.checked;

  elements.rows.querySelectorAll(".journey-origin-row").forEach((row) => {
    const checkbox = row.querySelector(".journey-origin-check");
    row.classList.toggle("is-selected", Boolean(checkbox?.checked));
  });
}

function applySearch() {
  const query = elements.search.value.trim().toLocaleLowerCase("es");

  elements.rows.querySelectorAll(".journey-origin-row").forEach((row) => {
    row.hidden = Boolean(query) && !row.dataset.search.includes(query);
  });

  updateSelectionState();
}

function renderJourney() {
  const trucks = Array.isArray(journey.trucks) ? journey.trucks : [];
  const warehouses = Array.isArray(journey.warehouses) ? journey.warehouses : [];

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
  elements.totalCount.textContent = String(trucks.length + warehouses.length);
  elements.tableHead.innerHTML = `
    <th scope="col">Elegir</th>
    <th scope="col">Origen</th>
    <th scope="col">Detalle</th>
  `;

  if (!trucks.length && !warehouses.length) {
    elements.rows.innerHTML = `
      <tr>
        <td colspan="3" class="journey-loading">
          No hay proveedores con placas activas. Asigna placas desde el directorio de proveedores.
        </td>
      </tr>
    `;
    updateSelectionState();
    return;
  }

  const warehouseRows = warehouses.map((warehouse) => {
    const search = [warehouse.name, warehouse.code, warehouse.address]
      .join(" ")
      .toLocaleLowerCase("es");

    return `
      <tr class="journey-origin-row journey-truck-row ${warehouse.selected ? "is-selected" : ""}" data-search="${escapeHtml(search)}">
        <td class="journey-check-cell">
          <input class="journey-origin-check journey-truck-check" type="checkbox" value="${Number(warehouse.id)}"
            data-origin-kind="warehouse" aria-label="Seleccionar ${escapeHtml(warehouse.name)} como origen"
            ${warehouse.selected ? "checked" : ""}>
        </td>
        <td>
          <strong class="journey-provider-name">${escapeHtml(warehouse.name)}</strong>
          <small>Almacén interno</small>
        </td>
        <td>
          <strong class="journey-plate">${escapeHtml(warehouse.code || "ALMACÉN")}</strong>
          <small>${escapeHtml(warehouse.address || "Sin dirección registrada")}</small>
        </td>
      </tr>
    `;
  });

  const truckRows = trucks.map((truck) => {
    const search = [
      truck.provider_name,
      truck.document,
      truck.plate,
      truck.alias
    ].join(" ").toLocaleLowerCase("es");

    return `
      <tr
        class="journey-origin-row journey-truck-row ${truck.selected ? "is-selected" : ""}"
        data-search="${escapeHtml(search)}"
      >
        <td class="journey-check-cell">
          <input
            class="journey-origin-check journey-truck-check"
            type="checkbox"
            value="${Number(truck.provider_vehicle_id)}"
            data-origin-kind="truck"
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
      </tr>
    `;
  });

  elements.rows.innerHTML = [...warehouseRows, ...truckRows].join("");

  applySearch();
}

function buildPayload() {
  return {
    provider_vehicle_ids: selectedVehicleIds(),
    warehouse_ids: selectedWarehouseIds()
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
  setMessage("Guardando orígenes de la jornada...");

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
  if (event.target.matches(".journey-origin-check")) {
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
        <td colspan="3" class="journey-loading">No se pudo cargar la jornada.</td>
      </tr>
    `;
    setMessage(error.message, true);
  });
