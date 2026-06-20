const PEOPLE_STORAGE_KEY = "sistema-pollos-personas-v1";

const DIRECTORY_TYPES = {
  clientes: {
    singular: "cliente",
    plural: "clientes",
    title: "Clientes registrados",
    formTitle: "Registrar cliente",
    editTitle: "Editar cliente",
    saveLabel: "Guardar cliente",
    prefix: "cli-local"
  },
  proveedores: {
    singular: "proveedor",
    plural: "proveedores",
    title: "Proveedores registrados",
    formTitle: "Registrar proveedor",
    editTitle: "Editar proveedor",
    saveLabel: "Guardar proveedor",
    prefix: "prov-local"
  }
};

const PRICE_FIELDS = [
  { key: "pollo_vivo", inputId: "priceVivo", label: "Vivo" },
  { key: "pollo_pelado", inputId: "pricePelado", label: "Pelado" },
  { key: "pollo_beneficiado", inputId: "priceBeneficiado", label: "Beneficiado" }
];

const ICONS = {
  edit: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M4 20h4l11-11-4-4L4 16z"></path>
      <path d="M13 7l4 4"></path>
    </svg>
  `,
  delete: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M4 7h16"></path>
      <path d="M9 7V5h6v2"></path>
      <path d="M8 11v7"></path>
      <path d="M16 11v7"></path>
      <path d="M6 7l1 13h10l1-13"></path>
    </svg>
  `,
  user: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
      <path d="M4 21a8 8 0 0 1 16 0"></path>
    </svg>
  `,
  truck: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M3 7h11v9H3z"></path>
      <path d="M14 10h3l3 3v3h-6z"></path>
      <path d="M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
      <path d="M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
    </svg>
  `
};

const elements = {
  root: document.querySelector("[data-directory-view]"),
  recordTypeButtons: document.querySelectorAll("[data-record-type]"),
  form: document.getElementById("directoryForm"),
  formTitle: document.getElementById("directoryFormTitle"),
  editBadge: document.getElementById("directoryEditBadge"),
  saveBtn: document.getElementById("saveDirectoryBtn"),
  clearBtn: document.getElementById("clearDirectoryBtn"),
  message: document.getElementById("directoryMessage"),
  name: document.getElementById("personName"),
  dni: document.getElementById("personDni"),
  address: document.getElementById("personAddress"),
  priceInputs: PRICE_FIELDS.reduce((acc, field) => {
    acc[field.key] = document.getElementById(field.inputId);
    return acc;
  }, {}),
  openGlobalPriceModalBtn: document.getElementById("openGlobalPriceModalBtn"),
  globalPriceModal: document.getElementById("globalPriceModal"),
  closeGlobalPriceModalBtn: document.getElementById("closeGlobalPriceModalBtn"),
  globalPriceScope: document.getElementById("globalPriceScope"),
  globalPriceType: document.getElementById("globalPriceType"),
  globalPriceAmount: document.getElementById("globalPriceAmount"),
  increaseGlobalPriceBtn: document.getElementById("increaseGlobalPriceBtn"),
  decreaseGlobalPriceBtn: document.getElementById("decreaseGlobalPriceBtn"),
  globalPriceMessage: document.getElementById("globalPriceMessage"),
  clientCount: document.getElementById("clientCount"),
  providerCount: document.getElementById("providerCount"),
  activeTypeLabel: document.getElementById("activeTypeLabel"),
  activeTypeCount: document.getElementById("activeTypeCount"),
  listTitle: document.getElementById("directoryListTitle"),
  search: document.getElementById("directorySearch"),
  list: document.getElementById("directoryList")
};

let activeType = getTypeFromHash() || "clientes";
let editingId = null;
let pendingDeleteId = null;
let directory = loadDirectory();

function createEmptyDirectory() {
  return {
    clientes: [],
    proveedores: []
  };
}

function loadDirectory() {
  try {
    const raw = localStorage.getItem(PEOPLE_STORAGE_KEY);
    if (!raw) {
      return createEmptyDirectory();
    }

    const parsed = JSON.parse(raw);
    return {
      clientes: normalizeRecordList(parsed?.clientes, "clientes"),
      proveedores: normalizeRecordList(parsed?.proveedores, "proveedores")
    };
  } catch {
    return createEmptyDirectory();
  }
}

function saveDirectory() {
  localStorage.setItem(PEOPLE_STORAGE_KEY, JSON.stringify(directory));
}

function normalizeRecordList(records, type) {
  if (!Array.isArray(records)) {
    return [];
  }

  return records
    .map((record) => normalizeRecord(record, type))
    .filter(Boolean);
}

function normalizeRecord(record, type) {
  const meta = DIRECTORY_TYPES[type] || DIRECTORY_TYPES.clientes;
  const name = String(record?.name || record?.nombre || "").trim();
  const id = String(record?.id || `${meta.prefix}-${Date.now()}`).trim();

  if (!name || !id) {
    return null;
  }

  const rawPrices = record?.pricesKg || record?.preciosKg || {};

  return {
    id,
    type,
    name,
    nombre: name,
    dni: String(record?.dni || "").trim(),
    direccion: String(record?.direccion || "").trim(),
    pricesKg: {
      pollo_vivo: normalizePrice(rawPrices.pollo_vivo ?? record?.precioPolloVivoKg),
      pollo_pelado: normalizePrice(rawPrices.pollo_pelado ?? record?.precioPolloPeladoKg),
      pollo_beneficiado: normalizePrice(rawPrices.pollo_beneficiado ?? record?.precioPolloBeneficiadoKg)
    },
    createdAt: record?.createdAt || new Date().toISOString(),
    updatedAt: record?.updatedAt || record?.createdAt || new Date().toISOString()
  };
}

function normalizePrice(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? Math.round(parsed * 100) / 100 : 0;
}

function formatCurrency(value) {
  return `S/ ${Number(value || 0).toFixed(2)}`;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function getTypeFromHash() {
  const hash = String(window.location.hash || "").replace("#", "");
  return DIRECTORY_TYPES[hash] ? hash : null;
}

function getActiveRecords() {
  return directory[activeType] || [];
}

function getRecordLabel(type = activeType) {
  return DIRECTORY_TYPES[type] || DIRECTORY_TYPES.clientes;
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function setGlobalPriceMessage(text, isError = false) {
  elements.globalPriceMessage.textContent = text || "";
  elements.globalPriceMessage.classList.toggle("is-error", Boolean(isError));
}

function openGlobalPriceModal() {
  elements.globalPriceModal.hidden = false;
  elements.openGlobalPriceModalBtn.setAttribute("aria-expanded", "true");
  elements.globalPriceAmount.focus();
}

function closeGlobalPriceModal() {
  elements.globalPriceModal.hidden = true;
  elements.openGlobalPriceModalBtn.setAttribute("aria-expanded", "false");
  setGlobalPriceMessage("");
}

function setActiveType(type, shouldUpdateHash = true) {
  if (!DIRECTORY_TYPES[type]) {
    return;
  }

  activeType = type;
  elements.root.dataset.directoryView = type;
  resetForm(false);

  if (shouldUpdateHash) {
    window.history.replaceState(null, "", `#${type}`);
  }

  render();
}

function resetForm(shouldRender = true) {
  editingId = null;
  pendingDeleteId = null;
  elements.form.reset();
  PRICE_FIELDS.forEach((field) => {
    elements.priceInputs[field.key].value = "";
  });
  elements.editBadge.hidden = true;
  setMessage("");
  setGlobalPriceMessage("");

  if (shouldRender) {
    render();
  }
}

function readFormRecord() {
  const meta = getRecordLabel();
  const name = elements.name.value.trim();
  const dni = elements.dni.value.trim();
  const direccion = elements.address.value.trim();
  const pricesKg = PRICE_FIELDS.reduce((acc, field) => {
    acc[field.key] = normalizePrice(elements.priceInputs[field.key].value);
    return acc;
  }, {});

  if (!name) {
    return { error: `Ingresa el nombre del ${meta.singular}.` };
  }

  if (!dni) {
    return { error: "Ingresa DNI o RUC." };
  }

  if (!direccion) {
    return { error: "Ingresa la dirección." };
  }

  const invalidPrice = PRICE_FIELDS.find((field) => pricesKg[field.key] <= 0);
  if (invalidPrice) {
    return { error: `Ingresa un precio válido para pollo ${invalidPrice.label.toLowerCase()}.` };
  }

  const duplicated = getActiveRecords().some((record) => (
    record.dni.toLowerCase() === dni.toLowerCase() && record.id !== editingId
  ));

  if (duplicated) {
    return { error: `Ya existe un ${meta.singular} con ese DNI/RUC.` };
  }

  const now = new Date().toISOString();
  const existing = getActiveRecords().find((record) => record.id === editingId);

  return {
    record: {
      id: existing?.id || `${meta.prefix}-${Date.now()}`,
      type: activeType,
      name,
      nombre: name,
      dni,
      direccion,
      pricesKg,
      createdAt: existing?.createdAt || now,
      updatedAt: now
    }
  };
}

function saveRecord(event) {
  event.preventDefault();

  const meta = getRecordLabel();
  const result = readFormRecord();

  if (result.error) {
    setMessage(result.error, true);
    return;
  }

  const records = getActiveRecords();
  const index = records.findIndex((record) => record.id === result.record.id);

  if (index >= 0) {
    records[index] = result.record;
  } else {
    records.unshift(result.record);
  }

  directory[activeType] = records;
  saveDirectory();
  resetForm(false);
  setMessage(`${meta.singular[0].toUpperCase()}${meta.singular.slice(1)} guardado.`);
  setGlobalPriceMessage("");
  render();
}

function getPriceField(fieldKey) {
  return PRICE_FIELDS.find((field) => field.key === fieldKey) || PRICE_FIELDS[0];
}

function syncEditingFormPrices() {
  if (!editingId) {
    return;
  }

  const record = getActiveRecords().find((item) => item.id === editingId);
  if (!record) {
    return;
  }

  PRICE_FIELDS.forEach((field) => {
    elements.priceInputs[field.key].value = Number(record.pricesKg[field.key] || 0).toFixed(2);
  });
}

function applyGlobalPriceAdjustment(direction) {
  const meta = getRecordLabel();
  const records = getActiveRecords();
  const amount = normalizePrice(elements.globalPriceAmount.value);
  const field = getPriceField(elements.globalPriceType.value);
  const multiplier = direction === "decrease" ? -1 : 1;

  if (!records.length) {
    setGlobalPriceMessage(`No hay ${meta.plural} para ajustar.`, true);
    return;
  }

  if (amount <= 0) {
    setGlobalPriceMessage("Ingresa un monto mayor a cero.", true);
    return;
  }

  const blockedRecord = records.find((record) => (
    normalizePrice((record.pricesKg[field.key] || 0) + multiplier * amount) <= 0
  ));

  if (blockedRecord) {
    setGlobalPriceMessage(`La disminucion dejaria a ${blockedRecord.name} sin precio valido.`, true);
    return;
  }

  const now = new Date().toISOString();
  directory[activeType] = records.map((record) => ({
    ...record,
    pricesKg: {
      ...record.pricesKg,
      [field.key]: normalizePrice(record.pricesKg[field.key] + multiplier * amount)
    },
    updatedAt: now
  }));

  saveDirectory();
  pendingDeleteId = null;
  syncEditingFormPrices();
  setMessage("");
  setGlobalPriceMessage(
    `${direction === "decrease" ? "Disminuido" : "Aumentado"} ${formatCurrency(amount)} en pollo ${field.label.toLowerCase()} para ${records.length} ${meta.plural}.`
  );
  render();
}

function editRecord(id) {
  const record = getActiveRecords().find((item) => item.id === id);
  if (!record) {
    return;
  }

  pendingDeleteId = null;
  editingId = record.id;
  elements.name.value = record.name;
  elements.dni.value = record.dni;
  elements.address.value = record.direccion;
  PRICE_FIELDS.forEach((field) => {
    elements.priceInputs[field.key].value = Number(record.pricesKg[field.key] || 0).toFixed(2);
  });
  elements.editBadge.hidden = false;
  setMessage("");
  renderFormHeader();
  elements.name.focus();
}

function deleteRecord(id) {
  const meta = getRecordLabel();
  const record = getActiveRecords().find((item) => item.id === id);
  if (!record) {
    return;
  }

  if (pendingDeleteId !== id) {
    pendingDeleteId = id;
    setMessage(`Pulsa eliminar otra vez para borrar ${record.name}.`, true);
    renderList();
    return;
  }

  pendingDeleteId = null;
  directory[activeType] = getActiveRecords().filter((item) => item.id !== id);
  saveDirectory();

  if (editingId === id) {
    resetForm(false);
  }

  setMessage(`${meta.singular[0].toUpperCase()}${meta.singular.slice(1)} eliminado.`);
  render();
}

function recordMatchesSearch(record, query) {
  if (!query) {
    return true;
  }

  const haystack = [
    record.name,
    record.dni,
    record.direccion,
    PRICE_FIELDS.map((field) => record.pricesKg[field.key]).join(" ")
  ].join(" ").toLowerCase();

  return haystack.includes(query);
}

function renderFormHeader() {
  const meta = getRecordLabel();
  elements.formTitle.textContent = editingId ? meta.editTitle : meta.formTitle;
  elements.saveBtn.querySelector("span").textContent = editingId ? "Guardar cambios" : meta.saveLabel;
  elements.editBadge.hidden = !editingId;
}

function renderTabs() {
  elements.recordTypeButtons.forEach((button) => {
    const isActive = button.dataset.recordType === activeType;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-selected", isActive ? "true" : "false");
  });
}

function renderStats() {
  const clientTotal = directory.clientes.length;
  const providerTotal = directory.proveedores.length;
  const meta = getRecordLabel();
  const activeTotal = getActiveRecords().length;

  elements.clientCount.textContent = String(clientTotal);
  elements.providerCount.textContent = String(providerTotal);
  elements.activeTypeLabel.textContent = meta.plural;
  elements.activeTypeCount.textContent = String(activeTotal);
  elements.globalPriceScope.textContent = meta.plural;
}

function renderRecordCard(record) {
  const meta = getRecordLabel();
  const icon = activeType === "proveedores" ? ICONS.truck : ICONS.user;
  const isConfirmingDelete = pendingDeleteId === record.id;
  const priceItems = PRICE_FIELDS.map((field) => `
    <div class="directory-price">
      <span>${field.label}</span>
      <strong>${formatCurrency(record.pricesKg[field.key])}</strong>
    </div>
  `).join("");

  return `
    <article class="directory-record card" data-record-id="${escapeHtml(record.id)}">
      <header class="directory-record-head">
        <span class="directory-record-icon">${icon}</span>
        <div>
          <strong>${escapeHtml(record.name)}</strong>
          <small>${escapeHtml(record.dni)}</small>
        </div>
        <span class="directory-record-tag">${escapeHtml(meta.singular)}</span>
      </header>
      <p class="directory-address">${escapeHtml(record.direccion)}</p>
      <div class="directory-prices">
        ${priceItems}
      </div>
      <div class="directory-record-actions">
        <button class="directory-record-action" type="button" data-action="edit" data-id="${escapeHtml(record.id)}" aria-label="Editar ${escapeHtml(record.name)}" title="Editar">
          ${ICONS.edit}
        </button>
        <button class="directory-record-action directory-record-action-danger ${isConfirmingDelete ? "is-confirming" : ""}" type="button" data-action="delete" data-id="${escapeHtml(record.id)}" aria-label="${isConfirmingDelete ? "Confirmar eliminación de" : "Eliminar"} ${escapeHtml(record.name)}" title="${isConfirmingDelete ? "Confirmar" : "Eliminar"}">
          ${ICONS.delete}
        </button>
      </div>
    </article>
  `;
}

function renderList() {
  const meta = getRecordLabel();
  const query = elements.search.value.trim().toLowerCase();
  const records = getActiveRecords()
    .filter((record) => recordMatchesSearch(record, query))
    .sort((a, b) => String(b.updatedAt).localeCompare(String(a.updatedAt)));

  elements.listTitle.textContent = meta.title;

  if (!records.length) {
    elements.list.innerHTML = `
      <div class="directory-empty">
        <strong>Sin ${escapeHtml(meta.plural)}.</strong>
        <span>${query ? "No hay coincidencias con la búsqueda." : "Aún no hay registros guardados."}</span>
      </div>
    `;
    return;
  }

  elements.list.innerHTML = records.map(renderRecordCard).join("");
}

function render() {
  renderTabs();
  renderFormHeader();
  renderStats();
  renderList();
}

elements.recordTypeButtons.forEach((button) => {
  button.addEventListener("click", () => {
    setActiveType(button.dataset.recordType);
  });
});

elements.form.addEventListener("submit", saveRecord);
elements.clearBtn.addEventListener("click", () => resetForm(true));
elements.search.addEventListener("input", renderList);
elements.openGlobalPriceModalBtn.addEventListener("click", openGlobalPriceModal);
elements.closeGlobalPriceModalBtn.addEventListener("click", closeGlobalPriceModal);
elements.increaseGlobalPriceBtn.addEventListener("click", () => applyGlobalPriceAdjustment("increase"));
elements.decreaseGlobalPriceBtn.addEventListener("click", () => applyGlobalPriceAdjustment("decrease"));
elements.globalPriceAmount.addEventListener("input", () => setGlobalPriceMessage(""));

elements.globalPriceModal.addEventListener("click", (event) => {
  if (event.target === elements.globalPriceModal) {
    closeGlobalPriceModal();
  }
});

elements.list.addEventListener("click", (event) => {
  const actionButton = event.target.closest("[data-action]");
  if (!actionButton) {
    return;
  }

  const id = actionButton.dataset.id;
  if (actionButton.dataset.action === "edit") {
    editRecord(id);
    return;
  }

  if (actionButton.dataset.action === "delete") {
    deleteRecord(id);
  }
});

window.addEventListener("hashchange", () => {
  const type = getTypeFromHash();
  if (type && type !== activeType) {
    setActiveType(type, false);
  }
});

window.addEventListener("storage", (event) => {
  if (event.key !== PEOPLE_STORAGE_KEY) {
    return;
  }

  directory = loadDirectory();
  resetForm(false);
  render();
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !elements.globalPriceModal.hidden) {
    closeGlobalPriceModal();
  }
});

setActiveType(activeType, Boolean(window.location.hash));
