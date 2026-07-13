import { apiRequest } from "./api-client.js";
import {
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  fillSelect,
  firstDefined,
  initFinanceAccess,
  isActiveRecord,
  markFinanceAccessReady,
  numericValue,
  optionalString,
  optionLabel,
  responseCollection,
  setMessage,
  toLocalDateTimeValue
} from "./finanzas-common.js";

const elements = {
  ownCount: document.getElementById("financeOwnEntityCount"),
  externalCount: document.getElementById("financeExternalEntityCount"),
  accountCount: document.getElementById("financeAccountCount"),
  entityForm: document.getElementById("financeEntityForm"),
  entityId: document.getElementById("financeEntityId"),
  entityTypeInputs: document.querySelectorAll('[name="financeEntityType"]'),
  entityProviderField: document.getElementById("financeEntityProviderField"),
  entityProvider: document.getElementById("financeEntityProvider"),
  entityName: document.getElementById("financeEntityName"),
  entityCommercialName: document.getElementById("financeEntityCommercialName"),
  entityDocumentType: document.getElementById("financeEntityDocumentType"),
  entityDocument: document.getElementById("financeEntityDocument"),
  entityAddress: document.getElementById("financeEntityAddress"),
  entityPhone: document.getElementById("financeEntityPhone"),
  entityEmail: document.getElementById("financeEntityEmail"),
  entityFormTitle: document.getElementById("financeEntityFormTitle"),
  entityEditBadge: document.getElementById("financeEntityEditBadge"),
  entitySave: document.getElementById("financeEntitySave"),
  entityCancel: document.getElementById("financeEntityCancel"),
  entityFormMessage: document.getElementById("financeEntityFormMessage"),
  entityTypeFilter: document.getElementById("financeEntityTypeFilter"),
  entitySearch: document.getElementById("financeEntitySearch"),
  entityListMessage: document.getElementById("financeEntityListMessage"),
  entityList: document.getElementById("financeEntityList"),
  accountsPanel: document.getElementById("financeAccountsPanel"),
  accountPanelTitle: document.getElementById("financeAccountPanelTitle"),
  accountPanelCopy: document.getElementById("financeAccountPanelCopy"),
  accountEntityBadge: document.getElementById("financeAccountEntityBadge"),
  accountWorkspace: document.getElementById("financeAccountWorkspace"),
  accountForm: document.getElementById("financeAccountForm"),
  accountId: document.getElementById("financeAccountId"),
  accountFormTitle: document.getElementById("financeAccountFormTitle"),
  accountEditBadge: document.getElementById("financeAccountEditBadge"),
  accountType: document.getElementById("financeAccountType"),
  accountName: document.getElementById("financeAccountName"),
  accountBankField: document.getElementById("financeAccountBankField"),
  accountBank: document.getElementById("financeAccountBank"),
  accountNumberField: document.getElementById("financeAccountNumberField"),
  accountNumber: document.getElementById("financeAccountNumber"),
  accountCciField: document.getElementById("financeAccountCciField"),
  accountCci: document.getElementById("financeAccountCci"),
  accountCurrency: document.getElementById("financeAccountCurrency"),
  accountOpeningField: document.getElementById("financeAccountOpeningField"),
  accountOpening: document.getElementById("financeAccountOpening"),
  accountSave: document.getElementById("financeAccountSave"),
  accountCancel: document.getElementById("financeAccountCancel"),
  accountFormMessage: document.getElementById("financeAccountFormMessage"),
  accountList: document.getElementById("financeAccountList")
};

const state = {
  entities: [],
  providers: [],
  editingEntityId: null,
  selectedEntityId: null,
  editingAccountId: null,
  savingEntity: false,
  savingAccount: false
};

function normalizeAccount(account) {
  return {
    ...account,
    id: firstDefined(account, ["id", "cuenta_id"]),
    type: String(firstDefined(account, ["tipo", "type"], "OTRA")).toUpperCase(),
    name: String(firstDefined(account, ["alias", "nombre", "name"], "Cuenta sin nombre")),
    bank: String(firstDefined(account, ["banco", "bank"], "")),
    number: String(firstDefined(account, ["numero", "numero_cuenta", "account_number"], "")),
    cci: String(firstDefined(account, ["cci", "codigo_interbancario"], "")),
    currency: String(firstDefined(account, ["moneda", "currency"], "PEN")),
    openingBalance: numericValue(firstDefined(account, ["saldo_inicial", "opening_balance"], 0)),
    currentBalance: numericValue(firstDefined(account, ["saldo_actual", "saldo", "balance"], 0)),
    active: isActiveRecord(account)
  };
}

function normalizeEntity(entity) {
  const provider = firstDefined(entity, ["proveedor", "provider"], null);
  const accounts = firstDefined(entity, ["cuentas", "accounts"], []);

  return {
    ...entity,
    id: firstDefined(entity, ["id", "entidad_id"]),
    type: String(firstDefined(entity, ["tipo", "type"], "PROPIA")).toUpperCase(),
    name: String(firstDefined(entity, ["razon_social", "name", "nombre"], "Empresa sin nombre")),
    document: String(firstDefined(entity, ["numero_documento", "documento", "ruc"], "")),
    commercialName: String(firstDefined(entity, ["nombre_comercial", "commercial_name"], "")),
    documentType: String(firstDefined(entity, ["tipo_documento", "document_type"], "")),
    address: String(firstDefined(entity, ["direccion", "address"], "")),
    phone: String(firstDefined(entity, ["telefono", "phone"], "")),
    email: String(firstDefined(entity, ["email", "correo"], "")),
    provider,
    providerId: firstDefined(entity, ["proveedor_id", "provider_id", "proveedor.id", "provider.id"], null),
    active: isActiveRecord(entity),
    accounts: (Array.isArray(accounts) ? accounts : accounts?.data || []).map(normalizeAccount)
  };
}

function selectedEntity() {
  return state.entities.find((entity) => String(entity.id) === String(state.selectedEntityId)) || null;
}

function currentEntityType() {
  return document.querySelector('[name="financeEntityType"]:checked')?.value || "PROPIA";
}

function updateEntityTypeFields() {
  const external = currentEntityType() === "EXTERNA";
  elements.entityProviderField.hidden = !external;
  elements.entityProvider.required = external;

  if (!external) elements.entityProvider.value = "";
}

function updateAccountTypeFields() {
  const type = elements.accountType.value;
  elements.accountBankField.hidden = type !== "BANCO";
  elements.accountCciField.hidden = type !== "BANCO";
  elements.accountNumberField.hidden = type === "CAJA";

  if (type !== "BANCO") {
    elements.accountBank.value = "";
    elements.accountCci.value = "";
  }
  if (type === "CAJA") elements.accountNumber.value = "";

  const entity = selectedEntity();
  elements.accountOpeningField.hidden = state.editingAccountId !== null || entity?.type !== "PROPIA";
}

function resetEntityForm({ focus = false, keepMessage = false } = {}) {
  elements.entityForm.reset();
  state.editingEntityId = null;
  elements.entityId.value = "";
  elements.entityFormTitle.textContent = "Agregar empresa";
  elements.entityEditBadge.hidden = true;
  elements.entitySave.textContent = "Guardar empresa";
  if (!keepMessage) setMessage(elements.entityFormMessage);
  updateEntityTypeFields();
  if (focus) elements.entityName.focus();
}

function resetAccountForm({ focus = false, keepMessage = false } = {}) {
  elements.accountForm.reset();
  state.editingAccountId = null;
  elements.accountId.value = "";
  elements.accountType.value = "BANCO";
  elements.accountCurrency.value = "PEN";
  elements.accountFormTitle.textContent = "Agregar cuenta";
  elements.accountEditBadge.hidden = true;
  elements.accountSave.textContent = "Guardar cuenta";
  if (!keepMessage) setMessage(elements.accountFormMessage);
  updateAccountTypeFields();
  if (focus) elements.accountName.focus();
}

function renderCounts() {
  const own = state.entities.filter((entity) => entity.type === "PROPIA").length;
  const external = state.entities.filter((entity) => entity.type === "EXTERNA").length;
  const activeAccounts = state.entities.flatMap((entity) => entity.accounts).filter((account) => account.active).length;
  elements.ownCount.textContent = String(own);
  elements.externalCount.textContent = String(external);
  elements.accountCount.textContent = String(activeAccounts);
}

function filteredEntities() {
  const type = elements.entityTypeFilter.value;
  const term = elements.entitySearch.value.trim().toLocaleLowerCase("es-PE");

  return state.entities.filter((entity) => {
    if (type && entity.type !== type) return false;
    if (!term) return true;

    const haystack = [
      entity.name,
      entity.document,
      optionLabel(entity.provider || {}, ""),
      entity.commercialName,
      entity.accounts.map((account) => `${account.name} ${account.bank} ${account.number}`).join(" ")
    ].join(" ").toLocaleLowerCase("es-PE");
    return haystack.includes(term);
  });
}

function renderEntities() {
  const entities = filteredEntities();
  renderCounts();

  if (!entities.length) {
    elements.entityList.innerHTML = `<div class="fin-account-balance-card"><header><strong>Sin empresas</strong><span>0 resultados</span></header><small>Registra una empresa propia o cambia los filtros de búsqueda.</small></div>`;
    return;
  }

  elements.entityList.innerHTML = entities.map((entity) => {
    const providerName = optionLabel(entity.provider || {}, "Proveedor no disponible");
    return `
      <article class="fin-entity-item ${String(entity.id) === String(state.selectedEntityId) ? "is-selected" : ""}">
        <div class="fin-entity-main">
          <header>
            <h3>${escapeHtml(entity.name)}</h3>
            <span class="fin-badge">${entity.type === "PROPIA" ? "Propia" : "Externa"}</span>
            <span class="fin-status-tag ${entity.active ? "" : "is-inactive"}">${entity.active ? "Activa" : "Inactiva"}</span>
          </header>
          <div class="fin-entity-meta">
            <span>${escapeHtml(entity.document || "Sin documento")}</span>
            <span>${entity.accounts.length} cuenta${entity.accounts.length === 1 ? "" : "s"}</span>
          </div>
          ${entity.type === "EXTERNA" ? `<p class="fin-entity-provider">Proveedor: ${escapeHtml(providerName)}</p>` : ""}
        </div>
        <div class="fin-entity-actions">
          <button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-entity-action="accounts" data-entity-id="${escapeHtml(entity.id)}">Administrar cuentas</button>
          <button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-entity-action="edit" data-entity-id="${escapeHtml(entity.id)}">Editar</button>
          ${entity.active ? `<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-entity-action="deactivate" data-entity-id="${escapeHtml(entity.id)}">Desactivar</button>` : ""}
          ${entity.active ? "" : `<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-entity-action="activate" data-entity-id="${escapeHtml(entity.id)}">Activar</button>`}
        </div>
      </article>`;
  }).join("");
}

function renderAccounts() {
  const entity = selectedEntity();

  if (!entity) {
    elements.accountWorkspace.hidden = true;
    elements.accountEntityBadge.hidden = true;
    elements.accountPanelTitle.textContent = "Selecciona una empresa";
    elements.accountPanelCopy.textContent = "Elige “Administrar cuentas” en una empresa para registrar sus bancos, cajas o billeteras.";
    return;
  }

  elements.accountWorkspace.hidden = false;
  elements.accountEntityBadge.hidden = false;
  elements.accountEntityBadge.textContent = entity.type === "PROPIA" ? "Empresa propia" : "Empresa externa";
  elements.accountPanelTitle.textContent = entity.name;
  elements.accountPanelCopy.textContent = entity.type === "PROPIA"
    ? "Sus entradas y salidas modifican el saldo disponible de la empresa."
    : `Destino relacionado con ${optionLabel(entity.provider || {}, "el proveedor seleccionado")}.`;

  if (!entity.accounts.length) {
    elements.accountList.innerHTML = `<div class="fin-account-balance-card"><header><strong>Sin cuentas registradas</strong><span>${escapeHtml(entity.type)}</span></header><small>Agrega la primera cuenta receptora de esta empresa.</small></div>`;
  } else {
    elements.accountList.innerHTML = entity.accounts.map((account) => {
      const detail = [account.bank, account.number, account.cci ? `CCI ${account.cci}` : ""].filter(Boolean).join(" · ");
      return `
        <article class="fin-account-item">
          <div>
            <span class="fin-account-type">${escapeHtml(account.type)}</span>
            <h4>${escapeHtml(account.name)}</h4>
            <p>${escapeHtml(detail || "Sin datos adicionales")}</p>
            <small>${escapeHtml(account.currency)} · ${account.active ? "Activa" : "Inactiva"}</small>
          </div>
          <div class="fin-account-item-actions">
            <button class="fin-btn fin-btn-ghost fin-btn-small" type="button" data-account-action="edit" data-account-id="${escapeHtml(account.id)}">Editar</button>
            ${account.active ? `<button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-account-action="deactivate" data-account-id="${escapeHtml(account.id)}">Desactivar</button>` : ""}
            ${account.active ? "" : `<button class="fin-btn fin-btn-primary fin-btn-small" type="button" data-account-action="activate" data-account-id="${escapeHtml(account.id)}">Activar</button>`}
          </div>
        </article>`;
    }).join("");
  }

  updateAccountTypeFields();
}

async function loadCatalog() {
  const response = await apiRequest("/finanzas/catalogo");
  state.providers = responseCollection(response, ["proveedores", "providers", "catalogo.proveedores"]);
  fillSelect(elements.entityProvider, state.providers, { placeholder: "Selecciona un proveedor" });
}

async function loadEntities() {
  setMessage(elements.entityListMessage, "Cargando empresas...");

  try {
    const response = await apiRequest("/finanzas/entidades?include=cuentas&per_page=100");
    state.entities = responseCollection(response, ["entidades", "entities", "items"]).map(normalizeEntity);

    if (state.selectedEntityId && !selectedEntity()) state.selectedEntityId = null;
    if (!state.selectedEntityId && state.entities.length) state.selectedEntityId = state.entities[0].id;

    renderEntities();
    renderAccounts();
    setMessage(elements.entityListMessage, state.entities.length ? `${state.entities.length} empresa${state.entities.length === 1 ? "" : "s"} registrada${state.entities.length === 1 ? "" : "s"}` : "");
    markFinanceAccessReady();
  } catch (error) {
    state.entities = [];
    renderEntities();
    renderAccounts();
    setMessage(elements.entityListMessage, errorMessage(error, "No se pudieron cargar las empresas."), "error");
  }
}

async function loadAll() {
  try {
    await Promise.all([loadCatalog(), loadEntities()]);
    markFinanceAccessReady();
  } catch (error) {
    setMessage(elements.entityListMessage, errorMessage(error, "No se pudo preparar la administración financiera."), "error");
  }
}

function entityPayload() {
  const type = currentEntityType();
  const name = elements.entityName.value.trim();
  const providerId = elements.entityProvider.value;

  if (!name) {
    elements.entityName.focus();
    throw new Error("Ingresa la razón social de la empresa.");
  }
  if (type === "EXTERNA" && !providerId) {
    elements.entityProvider.focus();
    throw new Error("Selecciona el proveedor relacionado con la empresa externa.");
  }
  const documentType = optionalString(elements.entityDocumentType.value);
  const documentNumber = optionalString(elements.entityDocument.value);
  if ((documentType === null) !== (documentNumber === null)) {
    (documentType === null ? elements.entityDocumentType : elements.entityDocument).focus();
    throw new Error("El tipo y el número de documento deben registrarse juntos.");
  }

  return {
    tipo: type,
    proveedor_id: type === "EXTERNA" ? Number(providerId) : null,
    razon_social: name,
    numero_documento: documentNumber,
    nombre_comercial: optionalString(elements.entityCommercialName.value),
    tipo_documento: documentType,
    direccion: optionalString(elements.entityAddress.value),
    telefono: optionalString(elements.entityPhone.value),
    email: optionalString(elements.entityEmail.value)
  };
}

async function saveEntity(event) {
  event.preventDefault();
  if (state.savingEntity) return;
  setMessage(elements.entityFormMessage);

  try {
    const payload = entityPayload();
    const editing = state.editingEntityId !== null;
    const path = editing ? `/finanzas/entidades/${state.editingEntityId}` : "/finanzas/entidades";
    state.savingEntity = true;
    elements.entitySave.disabled = true;

    const response = await apiRequest(path, {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify(payload)
    });
    const savedId = firstDefined(response, ["data.id", "id"], state.editingEntityId);
    if (savedId) state.selectedEntityId = savedId;
    resetEntityForm({ keepMessage: true });
    setMessage(elements.entityFormMessage, response?.message || "Empresa guardada correctamente.", "success");
    await loadEntities();
  } catch (error) {
    setMessage(elements.entityFormMessage, errorMessage(error), "error");
  } finally {
    state.savingEntity = false;
    elements.entitySave.disabled = false;
  }
}

function editEntity(id) {
  const entity = state.entities.find((item) => String(item.id) === String(id));
  if (!entity) return;

  state.editingEntityId = entity.id;
  elements.entityId.value = entity.id;
  const typeInput = document.querySelector(`[name="financeEntityType"][value="${entity.type}"]`);
  if (typeInput) typeInput.checked = true;
  updateEntityTypeFields();
  elements.entityProvider.value = entity.providerId ? String(entity.providerId) : "";
  elements.entityName.value = entity.name;
  elements.entityCommercialName.value = entity.commercialName;
  elements.entityDocumentType.value = entity.documentType;
  elements.entityDocument.value = entity.document;
  elements.entityAddress.value = entity.address;
  elements.entityPhone.value = entity.phone;
  elements.entityEmail.value = entity.email;
  elements.entityFormTitle.textContent = "Editar empresa";
  elements.entityEditBadge.hidden = false;
  elements.entitySave.textContent = "Guardar cambios";
  setMessage(elements.entityFormMessage);
  elements.entityForm.scrollIntoView({ behavior: "smooth", block: "start" });
  elements.entityName.focus();
}

async function deactivateEntity(id) {
  const entity = state.entities.find((item) => String(item.id) === String(id));
  if (!entity || !window.confirm(`¿Desactivar ${entity.name}? Sus movimientos históricos se conservarán.`)) return;

  try {
    await apiRequest(`/finanzas/entidades/${id}`, { method: "DELETE" });
    setMessage(elements.entityListMessage, "Empresa desactivada correctamente.", "success");
    await loadEntities();
  } catch (error) {
    setMessage(elements.entityListMessage, errorMessage(error), "error");
  }
}

async function activateEntity(id) {
  const entity = state.entities.find((item) => String(item.id) === String(id));
  if (!entity) return;

  try {
    await apiRequest(`/finanzas/entidades/${id}`, {
      method: "PUT",
      body: JSON.stringify({
        tipo: entity.type,
        proveedor_id: entity.type === "EXTERNA" ? Number(entity.providerId) : null,
        razon_social: entity.name,
        numero_documento: optionalString(entity.document),
        nombre_comercial: optionalString(entity.commercialName),
        tipo_documento: optionalString(entity.documentType),
        direccion: optionalString(entity.address),
        telefono: optionalString(entity.phone),
        email: optionalString(entity.email),
        estado: "ACTIVO"
      })
    });
    setMessage(elements.entityListMessage, "Empresa activada correctamente.", "success");
    await loadEntities();
  } catch (error) {
    setMessage(elements.entityListMessage, errorMessage(error), "error");
  }
}

function selectEntity(id, scroll = true) {
  state.selectedEntityId = id;
  resetAccountForm();
  renderEntities();
  renderAccounts();
  if (scroll) elements.accountsPanel.scrollIntoView({ behavior: "smooth", block: "start" });
}

function accountPayload() {
  const entity = selectedEntity();
  const name = elements.accountName.value.trim();
  const opening = elements.accountOpening.value.trim();

  if (!entity) throw new Error("Selecciona una empresa antes de registrar una cuenta.");
  if (!name) {
    elements.accountName.focus();
    throw new Error("Ingresa un nombre para identificar la cuenta.");
  }
  if (opening && numericValue(opening, -1) < 0) {
    elements.accountOpening.focus();
    throw new Error("El saldo inicial no puede ser negativo.");
  }

  const payload = {
    tipo: elements.accountType.value,
    banco: optionalString(elements.accountBank.value),
    numero_cuenta: optionalString(elements.accountNumber.value),
    cci: optionalString(elements.accountCci.value),
    alias: name,
    moneda: elements.accountCurrency.value
  };

  return payload;
}

async function saveAccount(event) {
  event.preventDefault();
  if (state.savingAccount) return;
  setMessage(elements.accountFormMessage);

  try {
    const entity = selectedEntity();
    const payload = accountPayload();
    const editing = state.editingAccountId !== null;
    const openingAmount = !editing && entity.type === "PROPIA"
      ? numericValue(elements.accountOpening.value)
      : 0;
    const path = editing
      ? `/finanzas/cuentas/${state.editingAccountId}`
      : `/finanzas/entidades/${entity.id}/cuentas`;

    state.savingAccount = true;
    elements.accountSave.disabled = true;
    const response = await apiRequest(path, {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify(payload)
    });
    let openingError = null;

    if (openingAmount > 0) {
      const accountId = firstDefined(response, ["data.id", "data.cuenta.id", "id"], null);

      try {
        if (!accountId) throw new Error("La cuenta fue creada, pero la respuesta no incluyó su identificador.");
        await apiRequest("/finanzas/movimientos", {
          method: "POST",
          body: JSON.stringify({
            idempotency_key: createIdempotencyKey(),
            tipo: "SALDO_INICIAL",
            fecha_hora: toLocalDateTimeValue(),
            cliente_id: null,
            proveedor_id: null,
            cuenta_origen_id: null,
            cuenta_destino_id: Number(accountId),
            metodo_pago_id: null,
            moneda: payload.moneda,
            importe: openingAmount.toFixed(2),
            referencia: "SALDO INICIAL",
            observaciones: "Saldo inicial registrado al crear la cuenta.",
            aplicaciones: []
          })
        });
      } catch (error) {
        openingError = error;
      }
    }

    resetAccountForm({ keepMessage: true });
    await loadEntities();
    if (openingError) {
      setMessage(elements.accountFormMessage, `La cuenta se creó, pero no se pudo registrar su saldo inicial: ${errorMessage(openingError)}`, "error");
    } else {
      setMessage(elements.accountFormMessage, response?.message || "Cuenta guardada correctamente.", "success");
    }
  } catch (error) {
    setMessage(elements.accountFormMessage, errorMessage(error), "error");
  } finally {
    state.savingAccount = false;
    elements.accountSave.disabled = false;
  }
}

function editAccount(id) {
  const entity = selectedEntity();
  const account = entity?.accounts.find((item) => String(item.id) === String(id));
  if (!account) return;

  state.editingAccountId = account.id;
  elements.accountId.value = account.id;
  elements.accountType.value = account.type;
  elements.accountName.value = account.name;
  elements.accountBank.value = account.bank;
  elements.accountNumber.value = account.number;
  elements.accountCci.value = account.cci;
  elements.accountCurrency.value = account.currency;
  elements.accountFormTitle.textContent = "Editar cuenta";
  elements.accountEditBadge.hidden = false;
  elements.accountSave.textContent = "Guardar cambios";
  setMessage(elements.accountFormMessage);
  updateAccountTypeFields();
  elements.accountName.focus();
}

async function deactivateAccount(id) {
  const entity = selectedEntity();
  const account = entity?.accounts.find((item) => String(item.id) === String(id));
  if (!account || !window.confirm(`¿Desactivar la cuenta “${account.name}”? Su historial y saldo se conservarán.`)) return;

  try {
    await apiRequest(`/finanzas/cuentas/${id}`, { method: "DELETE" });
    setMessage(elements.accountFormMessage, "Cuenta desactivada correctamente.", "success");
    await loadEntities();
  } catch (error) {
    setMessage(elements.accountFormMessage, errorMessage(error), "error");
  }
}

async function activateAccount(id) {
  const entity = selectedEntity();
  const account = entity?.accounts.find((item) => String(item.id) === String(id));
  if (!account) return;

  try {
    await apiRequest(`/finanzas/cuentas/${id}`, {
      method: "PUT",
      body: JSON.stringify({
        tipo: account.type,
        alias: account.name,
        banco: optionalString(account.bank),
        numero_cuenta: optionalString(account.number),
        cci: optionalString(account.cci),
        moneda: account.currency,
        estado: "ACTIVO"
      })
    });
    setMessage(elements.accountFormMessage, "Cuenta activada correctamente.", "success");
    await loadEntities();
  } catch (error) {
    setMessage(elements.accountFormMessage, errorMessage(error), "error");
  }
}

elements.entityTypeInputs.forEach((input) => input.addEventListener("change", updateEntityTypeFields));
elements.entityForm.addEventListener("submit", saveEntity);
elements.entityCancel.addEventListener("click", () => resetEntityForm({ focus: true }));
elements.entityTypeFilter.addEventListener("change", renderEntities);
elements.entitySearch.addEventListener("input", renderEntities);
elements.accountType.addEventListener("change", updateAccountTypeFields);
elements.accountForm.addEventListener("submit", saveAccount);
elements.accountCancel.addEventListener("click", () => resetAccountForm({ focus: true }));

elements.entityList.addEventListener("click", (event) => {
  const button = event.target.closest("[data-entity-action]");
  if (!button) return;
  const { entityAction, entityId } = button.dataset;
  if (entityAction === "accounts") selectEntity(entityId);
  if (entityAction === "edit") editEntity(entityId);
  if (entityAction === "deactivate") void deactivateEntity(entityId);
  if (entityAction === "activate") void activateEntity(entityId);
});

elements.accountList.addEventListener("click", (event) => {
  const button = event.target.closest("[data-account-action]");
  if (!button) return;
  const { accountAction, accountId } = button.dataset;
  if (accountAction === "edit") editAccount(accountId);
  if (accountAction === "deactivate") void deactivateAccount(accountId);
  if (accountAction === "activate") void activateAccount(accountId);
});

initFinanceAccess(loadAll);
resetEntityForm();
resetAccountForm();
void loadAll();
