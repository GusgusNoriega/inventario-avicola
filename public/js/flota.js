import { apiRequest } from "./api-client.js";

const TYPES = {
  camiones: {
    singular: "camión",
    plural: "camiones",
    formTitle: "Agregar camión",
    editTitle: "Editar camión",
    listTitle: "Camiones propios",
    saveLabel: "Guardar camión",
    searchPlaceholder: "Buscar por placa, marca o modelo"
  },
  choferes: {
    singular: "chofer",
    plural: "choferes",
    formTitle: "Agregar chofer",
    editTitle: "Editar chofer",
    listTitle: "Choferes de la empresa",
    saveLabel: "Guardar chofer",
    searchPlaceholder: "Buscar por nombre o documento"
  }
};

const ICONS = {
  truck: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M3 7h11v9H3z"></path><path d="M14 10h3l3 3v3h-6z"></path>
      <circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>
    </svg>`,
  driver: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <circle cx="12" cy="7" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
    </svg>`,
  edit: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M4 20h4l11-11-4-4L4 16z"></path><path d="M13 7l4 4"></path>
    </svg>`,
  delete: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M4 7h16"></path><path d="M9 7V5h6v2"></path>
      <path d="M8 11v7"></path><path d="M16 11v7"></path><path d="M6 7l1 13h10l1-13"></path>
    </svg>`
};

const elements = {
  body: document.body,
  tabs: document.querySelectorAll("[data-fleet-type]"),
  form: document.getElementById("fleetForm"),
  formTitle: document.getElementById("fleetFormTitle"),
  editBadge: document.getElementById("fleetEditBadge"),
  saveBtn: document.getElementById("saveFleetBtn"),
  clearBtn: document.getElementById("clearFleetBtn"),
  formMessage: document.getElementById("fleetFormMessage"),
  listMessage: document.getElementById("fleetListMessage"),
  listTitle: document.getElementById("fleetListTitle"),
  list: document.getElementById("fleetList"),
  search: document.getElementById("fleetSearch"),
  truckFields: document.getElementById("truckFields"),
  driverFields: document.getElementById("driverFields"),
  truckCount: document.getElementById("truckCount"),
  driverCount: document.getElementById("driverCount"),
  truckPlate: document.getElementById("truckPlate"),
  truckBrand: document.getElementById("truckBrand"),
  truckModel: document.getElementById("truckModel"),
  truckColor: document.getElementById("truckColor"),
  truckDescription: document.getElementById("truckDescription"),
  driverName: document.getElementById("driverName"),
  driverDocumentType: document.getElementById("driverDocumentType"),
  driverDocumentNumber: document.getElementById("driverDocumentNumber"),
  driverPhone: document.getElementById("driverPhone")
};

const state = {
  activeType: "camiones",
  records: [],
  editingId: null,
  confirmingDeleteId: null,
  loading: false,
  counts: { camiones: 0, choferes: 0 },
  searchTimer: null
};

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function optionalValue(value) {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? null : normalized;
}

function errorMessage(error) {
  const validationErrors = error?.data?.errors;

  if (validationErrors) {
    const firstError = Object.values(validationErrors).flat()[0];
    if (firstError) {
      return firstError;
    }
  }

  return error?.message || "No se pudo completar la operación.";
}

function setMessage(element, message = "", isError = false) {
  element.textContent = message;
  element.classList.toggle("is-error", isError);
}

function setBusy(isBusy) {
  state.loading = isBusy;
  elements.saveBtn.disabled = isBusy;
  elements.clearBtn.disabled = isBusy;
  elements.tabs.forEach((tab) => {
    tab.disabled = isBusy;
  });
}

function payloadFromForm() {
  if (state.activeType === "camiones") {
    return {
      placa: elements.truckPlate.value,
      marca: optionalValue(elements.truckBrand.value),
      modelo: optionalValue(elements.truckModel.value),
      color: optionalValue(elements.truckColor.value),
      descripcion: optionalValue(elements.truckDescription.value)
    };
  }

  const documentType = optionalValue(elements.driverDocumentType.value);
  const documentNumber = optionalValue(elements.driverDocumentNumber.value);

  if ((documentType === null) !== (documentNumber === null)) {
    throw new Error("El tipo y el número de documento deben diligenciarse juntos.");
  }

  return {
    nombre_completo: elements.driverName.value,
    tipo_documento: documentType,
    numero_documento: documentNumber,
    telefono: optionalValue(elements.driverPhone.value)
  };
}

function validateRequiredFields() {
  if (state.activeType === "camiones" && !elements.truckPlate.value.trim()) {
    elements.truckPlate.focus();
    throw new Error("La placa del camión es obligatoria.");
  }

  if (state.activeType === "choferes" && !elements.driverName.value.trim()) {
    elements.driverName.focus();
    throw new Error("El nombre completo del chofer es obligatorio.");
  }
}

function resetForm(focus = false) {
  elements.form.reset();
  state.editingId = null;
  state.confirmingDeleteId = null;
  setMessage(elements.formMessage);
  renderFormState();
  renderList();

  if (focus) {
    const input = state.activeType === "camiones" ? elements.truckPlate : elements.driverName;
    input.focus();
  }
}

function editRecord(id) {
  const record = state.records.find((item) => Number(item.id) === Number(id));
  if (!record) {
    return;
  }

  state.editingId = Number(record.id);
  state.confirmingDeleteId = null;

  if (state.activeType === "camiones") {
    elements.truckPlate.value = record.placa || "";
    elements.truckBrand.value = record.marca || "";
    elements.truckModel.value = record.modelo || "";
    elements.truckColor.value = record.color || "";
    elements.truckDescription.value = record.descripcion || "";
    elements.truckPlate.focus();
  } else {
    elements.driverName.value = record.nombre_completo || "";
    elements.driverDocumentType.value = record.tipo_documento || "";
    elements.driverDocumentNumber.value = record.numero_documento || "";
    elements.driverPhone.value = record.telefono || "";
    elements.driverName.focus();
  }

  setMessage(elements.formMessage);
  renderFormState();
  renderList();
  elements.form.scrollIntoView({ behavior: "smooth", block: "start" });
}

async function saveRecord(event) {
  event.preventDefault();
  setMessage(elements.formMessage);

  try {
    validateRequiredFields();
    const payload = payloadFromForm();
    const isEditing = state.editingId !== null;
    const path = isEditing
      ? `/${state.activeType}/${state.editingId}`
      : `/${state.activeType}`;

    setBusy(true);
    const response = await apiRequest(path, {
      method: isEditing ? "PATCH" : "POST",
      body: JSON.stringify(payload)
    });

    resetForm();
    setMessage(elements.formMessage, response.message || `${TYPES[state.activeType].singular} guardado correctamente.`);
    await Promise.all([loadRecords(), refreshCounts()]);
  } catch (error) {
    setMessage(elements.formMessage, errorMessage(error), true);
  } finally {
    setBusy(false);
  }
}

async function loadRecords(search = elements.search.value.trim()) {
  const params = new URLSearchParams({ per_page: "100" });
  if (search) {
    params.set("buscar", search);
  }

  setMessage(elements.listMessage, "Cargando registros...");

  try {
    const response = await apiRequest(`/${state.activeType}?${params.toString()}`);
    state.records = response.data || [];
    state.confirmingDeleteId = null;
    setMessage(elements.listMessage);
    renderList();
  } catch (error) {
    state.records = [];
    setMessage(elements.listMessage, errorMessage(error), true);
    renderList();
  }
}

async function refreshCounts() {
  try {
    const [trucks, drivers] = await Promise.all([
      apiRequest("/camiones?per_page=1"),
      apiRequest("/choferes?per_page=1")
    ]);

    state.counts.camiones = Number(trucks.meta?.total ?? trucks.data?.length ?? 0);
    state.counts.choferes = Number(drivers.meta?.total ?? drivers.data?.length ?? 0);
    renderCounts();
  } catch {
    // La lista activa conserva su propio mensaje de error.
  }
}

async function deleteRecord(id) {
  if (state.confirmingDeleteId !== Number(id)) {
    state.confirmingDeleteId = Number(id);
    renderList();
    return;
  }

  setBusy(true);
  setMessage(elements.listMessage, `Eliminando ${TYPES[state.activeType].singular}...`);

  try {
    const response = await apiRequest(`/${state.activeType}/${id}`, { method: "DELETE" });
    if (state.editingId === Number(id)) {
      resetForm();
    }
    setMessage(elements.listMessage, response.message || "Registro eliminado correctamente.");
    await Promise.all([loadRecords(), refreshCounts()]);
  } catch (error) {
    setMessage(elements.listMessage, errorMessage(error), true);
  } finally {
    setBusy(false);
  }
}

async function switchType(type) {
  if (!TYPES[type] || type === state.activeType || state.loading) {
    return;
  }

  state.activeType = type;
  elements.body.dataset.fleetView = type;
  elements.search.value = "";
  state.records = [];
  resetForm();
  renderType();
  await loadRecords("");
}

function renderType() {
  const type = TYPES[state.activeType];
  const isTruck = state.activeType === "camiones";

  elements.tabs.forEach((tab) => {
    const active = tab.dataset.fleetType === state.activeType;
    tab.classList.toggle("is-active", active);
    tab.setAttribute("aria-selected", String(active));
  });

  elements.truckFields.hidden = !isTruck;
  elements.driverFields.hidden = isTruck;
  elements.listTitle.textContent = type.listTitle;
  elements.search.placeholder = type.searchPlaceholder;
  renderFormState();
  renderList();
}

function renderFormState() {
  const type = TYPES[state.activeType];
  const isEditing = state.editingId !== null;

  elements.formTitle.textContent = isEditing ? type.editTitle : type.formTitle;
  elements.editBadge.hidden = !isEditing;
  elements.saveBtn.querySelector("span").textContent = isEditing
    ? `Actualizar ${type.singular}`
    : type.saveLabel;
}

function renderCounts() {
  elements.truckCount.textContent = String(state.counts.camiones);
  elements.driverCount.textContent = String(state.counts.choferes);
}

function truckCard(record) {
  const details = [record.marca, record.modelo, record.color]
    .filter(Boolean)
    .map(escapeHtml)
    .join(" · ");

  return `
    <article class="fleet-record${state.editingId === Number(record.id) ? " is-editing" : ""}">
      <div class="fleet-record-main">
        <span class="fleet-record-icon">${ICONS.truck}</span>
        <div>
          <strong class="fleet-plate">${escapeHtml(record.placa)}</strong>
          <span>${details || "Sin datos adicionales"}</span>
        </div>
      </div>
      ${record.descripcion ? `<p>${escapeHtml(record.descripcion)}</p>` : ""}
      ${recordActions(record.id, `camión ${record.placa}`)}
    </article>`;
}

function driverCard(record) {
  const document = record.tipo_documento && record.numero_documento
    ? `${escapeHtml(record.tipo_documento)} ${escapeHtml(record.numero_documento)}`
    : "Sin documento registrado";

  return `
    <article class="fleet-record${state.editingId === Number(record.id) ? " is-editing" : ""}">
      <div class="fleet-record-main">
        <span class="fleet-record-icon fleet-driver-icon">${ICONS.driver}</span>
        <div>
          <strong>${escapeHtml(record.nombre_completo)}</strong>
          <span>${document}</span>
        </div>
      </div>
      ${record.telefono ? `<p>Teléfono: ${escapeHtml(record.telefono)}</p>` : ""}
      ${recordActions(record.id, `chofer ${record.nombre_completo}`)}
    </article>`;
}

function recordActions(id, label) {
  const confirming = state.confirmingDeleteId === Number(id);

  return `
    <div class="fleet-record-actions">
      <button class="fleet-action" type="button" data-fleet-action="edit" data-record-id="${id}" aria-label="Editar ${escapeHtml(label)}">
        ${ICONS.edit}<span>Editar</span>
      </button>
      <button class="fleet-action fleet-action-danger${confirming ? " is-confirming" : ""}" type="button" data-fleet-action="delete" data-record-id="${id}" aria-label="Eliminar ${escapeHtml(label)}">
        ${ICONS.delete}<span>${confirming ? "Confirmar" : "Eliminar"}</span>
      </button>
    </div>`;
}

function renderList() {
  if (!state.records.length) {
    elements.list.innerHTML = `
      <div class="fleet-empty">
        <strong>No hay ${TYPES[state.activeType].plural} para mostrar</strong>
        <span>Registra el primer ${TYPES[state.activeType].singular} de tu empresa.</span>
      </div>`;
    return;
  }

  elements.list.innerHTML = state.records
    .map((record) => state.activeType === "camiones" ? truckCard(record) : driverCard(record))
    .join("");
}

elements.form.addEventListener("submit", saveRecord);
elements.clearBtn.addEventListener("click", () => resetForm(true));
elements.truckPlate.addEventListener("input", () => {
  elements.truckPlate.value = elements.truckPlate.value.toUpperCase().replace(/\s+/g, "");
});
elements.driverDocumentNumber.addEventListener("input", () => {
  elements.driverDocumentNumber.value = elements.driverDocumentNumber.value.toUpperCase();
});

elements.tabs.forEach((tab) => {
  tab.addEventListener("click", () => switchType(tab.dataset.fleetType));
});

elements.search.addEventListener("input", () => {
  window.clearTimeout(state.searchTimer);
  state.searchTimer = window.setTimeout(() => loadRecords(), 300);
});

elements.list.addEventListener("click", (event) => {
  const button = event.target.closest("[data-fleet-action]");
  if (!button || state.loading) {
    return;
  }

  const id = Number(button.dataset.recordId);
  if (button.dataset.fleetAction === "edit") {
    editRecord(id);
  } else if (button.dataset.fleetAction === "delete") {
    deleteRecord(id);
  }
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    resetForm();
  }
});

window.addEventListener("auth:expired", () => {
  setMessage(elements.listMessage, "La sesión expiró. Inicia sesión nuevamente.", true);
});

renderType();
refreshCounts();
loadRecords();
