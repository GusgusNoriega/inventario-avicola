import { apiRequest } from "./api-client.js";

const DIRECTORY_TYPES = {
  clientes: {
    singular: "cliente",
    plural: "clientes",
    title: "Clientes registrados",
    formTitle: "Registrar cliente",
    editTitle: "Editar cliente",
    saveLabel: "Guardar cliente"
  },
  proveedores: {
    singular: "proveedor",
    plural: "proveedores",
    title: "Proveedores registrados",
    formTitle: "Registrar proveedor",
    editTitle: "Editar proveedor",
    saveLabel: "Guardar proveedor"
  }
};

const PRICE_FIELDS = [
  { key: "pollo_vivo", apiKey: "POLLO_VIVO", inputId: "priceVivo", label: "Vivo" },
  { key: "pollo_pelado", apiKey: "POLLO_PELADO", inputId: "pricePelado", label: "Pelado" },
  { key: "pollo_beneficiado", apiKey: "POLLO_BENEFICIADO", inputId: "priceBeneficiado", label: "Beneficiado" }
];

const ICONS = {
  view: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"></path>
      <circle cx="12" cy="12" r="3"></circle>
    </svg>
  `,
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
  internalClientField: document.getElementById("internalClientField"),
  internalClient: document.getElementById("internalClient"),
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
let searchTimer = null;
let requestSequence = { clientes: 0, proveedores: 0 };
let directory = { clientes: [], proveedores: [] };
let totals = { clientes: 0, proveedores: 0 };
let loading = { clientes: false, proveedores: false };

function normalizePrice(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed > 0 ? Math.round(parsed * 10000) / 10000 : null;
}

function normalizeRecord(record, type) {
  return {
    id: String(record.id),
    type,
    name: String(record.name || record.nombre || "").trim(),
    dni: String(record.dni || record.numero_documento || "").trim(),
    direccion: String(record.direccion || "").trim(),
    isInternalClient: Boolean(record.es_cliente_interno),
    pricesKg: PRICE_FIELDS.reduce((prices, field) => {
      prices[field.key] = normalizePrice(record.pricesKg?.[field.key]);
      return prices;
    }, {}),
    createdAt: record.createdAt,
    updatedAt: record.updatedAt
  };
}

function formatCurrency(value) {
  return value === null || value === undefined
    ? "Sin precio"
    : `S/ ${Number(value).toFixed(2)}`;
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

function getErrorMessage(error) {
  const validationErrors = error?.data?.errors;
  const firstError = validationErrors
    ? Object.values(validationErrors).flat().find(Boolean)
    : null;

  return firstError || error?.message || "No se pudo completar la operación.";
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function setGlobalPriceMessage(text, isError = false) {
  elements.globalPriceMessage.textContent = text || "";
  elements.globalPriceMessage.classList.toggle("is-error", Boolean(isError));
}

function setFormBusy(isBusy) {
  elements.saveBtn.disabled = isBusy;
  elements.clearBtn.disabled = isBusy;
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

function setActiveType(type, shouldUpdateHash = true, shouldLoad = true) {
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

  if (shouldLoad) {
    void searchRecords(type, elements.search.value.trim());
  }
}

function resetForm(shouldRender = true) {
  editingId = null;
  pendingDeleteId = null;
  elements.form.reset();
  elements.editBadge.hidden = true;
  setMessage("");
  setGlobalPriceMessage("");

  if (shouldRender) {
    render();
  }
}

function readFormRecord() {
  const meta = getRecordLabel();
  const name = elements.name.value.trim().toLocaleUpperCase("es-PE");
  const dni = elements.dni.value.replace(/\D+/g, "");
  const direccion = elements.address.value.trim();
  const prices = PRICE_FIELDS.reduce((acc, field) => {
    const rawValue = elements.priceInputs[field.key].value.trim();
    acc[field.apiKey] = rawValue === "" ? null : normalizePrice(rawValue);
    return acc;
  }, {});

  if (!name) {
    return { error: `Ingresa el nombre del ${meta.singular}.` };
  }

  if (![8, 11].includes(dni.length)) {
    return { error: "El DNI debe tener 8 dígitos y el RUC 11 dígitos." };
  }

  if (!direccion) {
    return { error: "Ingresa la dirección." };
  }

  const invalidPrice = PRICE_FIELDS.find((field) => {
    const price = prices[field.apiKey];
    return activeType === "proveedores"
      ? price === null || price <= 0
      : price !== null && price <= 0;
  });
  if (invalidPrice) {
    return { error: `Ingresa un precio válido para pollo ${invalidPrice.label.toLowerCase()}.` };
  }

  return {
    record: {
      nombre_razon_social: name,
      numero_documento: dni,
      direccion,
      ...(activeType === "clientes" ? { es_cliente_interno: elements.internalClient.checked } : {}),
      precios: prices
    }
  };
}

async function saveRecord(event) {
  event.preventDefault();

  const meta = getRecordLabel();
  const result = readFormRecord();

  if (result.error) {
    setMessage(result.error, true);
    return;
  }

  setFormBusy(true);
  setMessage(editingId ? "Guardando cambios..." : "Guardando...");

  try {
    const path = editingId ? `/${activeType}/${editingId}` : `/${activeType}`;
    const response = await apiRequest(path, {
      method: editingId ? "PUT" : "POST",
      body: JSON.stringify(result.record)
    });

    resetForm(false);
    setMessage(response.message || `${meta.singular[0].toUpperCase()}${meta.singular.slice(1)} guardado.`);
    await refreshType(activeType);
  } catch (error) {
    setMessage(getErrorMessage(error), true);
  } finally {
    setFormBusy(false);
    render();
  }
}

async function loadRecords(type, query = "", updateTotal = query === "") {
  const requestId = ++requestSequence[type];
  loading[type] = true;

  if (type === activeType) {
    renderList();
  }

  try {
    const params = new URLSearchParams({ per_page: "100" });
    if (query) {
      params.set("buscar", query);
    }

    const response = await apiRequest(`/${type}?${params.toString()}`);

    if (requestId !== requestSequence[type]) {
      return;
    }

    directory[type] = (response.data || []).map((record) => normalizeRecord(record, type));
    if (updateTotal) {
      totals[type] = Number(response.meta?.total ?? directory[type].length);
    }
  } catch (error) {
    if (requestId !== requestSequence[type]) {
      return;
    }

    directory[type] = [];
    setMessage(getErrorMessage(error), true);
  } finally {
    if (requestId === requestSequence[type]) {
      loading[type] = false;
      render();
    }
  }
}

async function refreshType(type) {
  const query = type === activeType ? elements.search.value.trim() : "";

  if (!query) {
    await loadRecords(type);
    return;
  }

  const countResponse = await apiRequest(`/${type}?per_page=1`);
  totals[type] = Number(countResponse.meta?.total ?? 0);
  await loadRecords(type, query, false);
}

async function searchRecords(type, query) {
  await loadRecords(type, query, query === "");
}

function editRecord(id) {
  const record = getActiveRecords().find((item) => item.id === String(id));
  if (!record) {
    return;
  }

  pendingDeleteId = null;
  editingId = record.id;
  elements.name.value = record.name;
  elements.dni.value = record.dni;
  elements.address.value = record.direccion;
  elements.internalClient.checked = record.isInternalClient;
  PRICE_FIELDS.forEach((field) => {
    const price = record.pricesKg[field.key];
    elements.priceInputs[field.key].value = price === null || price === undefined
      ? ""
      : Number(price).toFixed(2);
  });
  elements.editBadge.hidden = false;
  setMessage("");
  renderFormHeader();
  elements.name.focus();
}

async function deleteRecord(id) {
  const meta = getRecordLabel();
  const record = getActiveRecords().find((item) => item.id === String(id));
  if (!record) {
    return;
  }

  if (pendingDeleteId !== record.id) {
    pendingDeleteId = record.id;
    setMessage(`Pulsa eliminar otra vez para desactivar a ${record.name}.`, true);
    renderList();
    return;
  }

  pendingDeleteId = null;

  try {
    const response = await apiRequest(`/${activeType}/${record.id}`, { method: "DELETE" });
    if (editingId === record.id) {
      resetForm(false);
    }
    setMessage(response.message || `${meta.singular[0].toUpperCase()}${meta.singular.slice(1)} desactivado.`);
    await refreshType(activeType);
  } catch (error) {
    setMessage(getErrorMessage(error), true);
  }
}

async function applyGlobalPriceAdjustment(direction) {
  const meta = getRecordLabel();
  const amount = normalizePrice(elements.globalPriceAmount.value);
  const field = PRICE_FIELDS.find((item) => item.key === elements.globalPriceType.value) || PRICE_FIELDS[0];

  if (amount <= 0) {
    setGlobalPriceMessage("Ingresa un monto mayor a cero.", true);
    return;
  }

  elements.increaseGlobalPriceBtn.disabled = true;
  elements.decreaseGlobalPriceBtn.disabled = true;
  setGlobalPriceMessage("Actualizando precios...");

  try {
    const response = await apiRequest(`/${activeType}/precios/ajuste-global`, {
      method: "PATCH",
      body: JSON.stringify({
        tipo_pollo: field.apiKey,
        monto: amount,
        direccion: direction === "decrease" ? "DISMINUIR" : "AUMENTAR"
      })
    });

    await refreshType(activeType);
    syncEditingFormPrices();
    setGlobalPriceMessage(
      `${direction === "decrease" ? "Disminuido" : "Aumentado"} ${formatCurrency(amount)} en pollo ${field.label.toLowerCase()} para ${response.affected} ${meta.plural}.`
    );
  } catch (error) {
    setGlobalPriceMessage(getErrorMessage(error), true);
  } finally {
    elements.increaseGlobalPriceBtn.disabled = false;
    elements.decreaseGlobalPriceBtn.disabled = false;
  }
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
    const price = record.pricesKg[field.key];
    elements.priceInputs[field.key].value = price === null || price === undefined
      ? ""
      : Number(price).toFixed(2);
  });
}

function renderFormHeader() {
  const meta = getRecordLabel();
  elements.formTitle.textContent = editingId ? meta.editTitle : meta.formTitle;
  elements.saveBtn.querySelector("span").textContent = editingId ? "Guardar cambios" : meta.saveLabel;
  elements.editBadge.hidden = !editingId;
  elements.internalClientField.hidden = activeType !== "clientes";
  PRICE_FIELDS.forEach((field) => {
    const input = elements.priceInputs[field.key];
    input.required = activeType === "proveedores";
    input.placeholder = activeType === "proveedores"
      ? "0.00"
      : "Vacío: usa el global";
  });
}

function renderTabs() {
  elements.recordTypeButtons.forEach((button) => {
    const isActive = button.dataset.recordType === activeType;
    button.classList.toggle("is-active", isActive);
    button.setAttribute("aria-selected", isActive ? "true" : "false");
  });
}

function renderStats() {
  const meta = getRecordLabel();
  elements.clientCount.textContent = String(totals.clientes);
  elements.providerCount.textContent = String(totals.proveedores);
  elements.activeTypeLabel.textContent = meta.plural;
  elements.activeTypeCount.textContent = String(totals[activeType]);
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
  const detailTemplate = activeType === "clientes"
    ? elements.root.dataset.clientDetailUrl
    : elements.root.dataset.providerDetailUrl;
  const detailUrl = detailTemplate?.replace("__ID__", encodeURIComponent(record.id));
  const detailAction = detailUrl
    ? `
        <a class="directory-record-action" href="${escapeHtml(detailUrl)}" aria-label="Ver detalle de ${escapeHtml(record.name)}" title="Ver detalle e historial">
          ${ICONS.view}
        </a>
      `
    : "";
  const recordTag = activeType === "clientes" && record.isInternalClient
    ? "interno"
    : meta.singular;

  return `
    <article class="directory-record card" data-record-id="${escapeHtml(record.id)}">
      <header class="directory-record-head">
        <span class="directory-record-icon">${icon}</span>
        <div>
          <strong>${escapeHtml(record.name)}</strong>
          <small>${escapeHtml(record.dni)}</small>
        </div>
        <span class="directory-record-tag">${escapeHtml(recordTag)}</span>
      </header>
      <p class="directory-address">${escapeHtml(record.direccion)}</p>
      <div class="directory-prices">
        ${priceItems}
      </div>
      <div class="directory-record-actions">
        ${detailAction}
        <button class="directory-record-action" type="button" data-action="edit" data-id="${escapeHtml(record.id)}" aria-label="Editar ${escapeHtml(record.name)}" title="Editar">
          ${ICONS.edit}
        </button>
        <button class="directory-record-action directory-record-action-danger ${isConfirmingDelete ? "is-confirming" : ""}" type="button" data-action="delete" data-id="${escapeHtml(record.id)}" aria-label="${isConfirmingDelete ? "Confirmar desactivación de" : "Desactivar"} ${escapeHtml(record.name)}" title="${isConfirmingDelete ? "Confirmar" : "Desactivar"}">
          ${ICONS.delete}
        </button>
      </div>
    </article>
  `;
}

function renderList() {
  const meta = getRecordLabel();
  const query = elements.search.value.trim();
  elements.listTitle.textContent = meta.title;

  if (loading[activeType]) {
    elements.list.innerHTML = `
      <div class="directory-empty">
        <strong>Cargando ${escapeHtml(meta.plural)}...</strong>
      </div>
    `;
    return;
  }

  const records = getActiveRecords();
  if (!records.length) {
    elements.list.innerHTML = `
      <div class="directory-empty">
        <strong>Sin ${escapeHtml(meta.plural)}.</strong>
        <span>${query ? "No hay coincidencias por nombre o documento." : "Aún no hay registros guardados."}</span>
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
elements.name.addEventListener("blur", () => {
  elements.name.value = elements.name.value.trim().toLocaleUpperCase("es-PE");
});
elements.clearBtn.addEventListener("click", () => resetForm(true));
elements.search.addEventListener("input", () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    void searchRecords(activeType, elements.search.value.trim());
  }, 300);
});
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
    void deleteRecord(id);
  }
});

window.addEventListener("hashchange", () => {
  const type = getTypeFromHash();
  if (type && type !== activeType) {
    setActiveType(type, false);
  }
});

window.addEventListener("auth:expired", () => {
  setMessage("La sesión de la API venció. Inicia sesión nuevamente.", true);
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && !elements.globalPriceModal.hidden) {
    closeGlobalPriceModal();
  }
});

setActiveType(activeType, Boolean(window.location.hash), false);
await Promise.all([
  loadRecords("clientes"),
  loadRecords("proveedores")
]);
