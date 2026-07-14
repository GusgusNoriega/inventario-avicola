import { apiRequest } from "./api-client.js";
import {
  accountListFromEntities,
  createIdempotencyKey,
  errorMessage,
  escapeHtml,
  fillSelect,
  firstDefined,
  formatMoney,
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
  form: document.getElementById("purchaseForm"),
  conditionInputs: document.querySelectorAll('[name="purchaseCondition"]'),
  conditionBadge: document.getElementById("purchaseConditionBadge"),
  provider: document.getElementById("purchaseProvider"),
  documentType: document.getElementById("purchaseDocumentType"),
  documentNumber: document.getElementById("purchaseDocumentNumber"),
  date: document.getElementById("purchaseDate"),
  dueDateField: document.getElementById("purchaseDueDateField"),
  dueDate: document.getElementById("purchaseDueDate"),
  currency: document.getElementById("purchaseCurrency"),
  notes: document.getElementById("purchaseNotes"),
  addLine: document.getElementById("purchaseAddLine"),
  lines: document.getElementById("purchaseLines"),
  linesMessage: document.getElementById("purchaseLinesMessage"),
  cashPanel: document.getElementById("purchaseCashPanel"),
  origin: document.getElementById("purchaseOriginAccount"),
  originHelp: document.getElementById("purchaseOriginHelp"),
  destination: document.getElementById("purchaseDestinationAccount"),
  method: document.getElementById("purchasePaymentMethod"),
  paymentDate: document.getElementById("purchasePaymentDate"),
  referenceLabel: document.getElementById("purchasePaymentReferenceLabel"),
  reference: document.getElementById("purchasePaymentReference"),
  subtotal: document.getElementById("purchaseSubtotal"),
  tax: document.getElementById("purchaseTax"),
  total: document.getElementById("purchaseTotal"),
  summaryHint: document.getElementById("purchaseSummaryHint"),
  message: document.getElementById("purchaseFormMessage"),
  save: document.getElementById("purchaseSave"),
  reset: document.getElementById("purchaseReset")
};

const state = {
  providers: [],
  types: [],
  entities: [],
  accounts: [],
  methods: [],
  lines: [],
  nextLineId: 1,
  idempotencyKey: createIdempotencyKey(),
  saving: false
};

const queryProvider = new URLSearchParams(window.location.search).get("proveedor_id") || "";

function idValue(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : value;
}

function condition() {
  return document.querySelector('[name="purchaseCondition"]:checked')?.value || "CREDITO";
}

function isCashPurchase() {
  return condition() === "CONTADO";
}

function todayValue(date = new Date()) {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 10);
}

function normalizeEntity(entity) {
  const accounts = firstDefined(entity, ["cuentas", "accounts"], []);
  return {
    ...entity,
    id: firstDefined(entity, ["id", "entidad_id"]),
    tipo: String(firstDefined(entity, ["tipo", "type"], "PROPIA")).toUpperCase(),
    razon_social: String(firstDefined(entity, ["razon_social", "nombre", "name"], "Empresa sin nombre")),
    proveedor_id: firstDefined(entity, ["proveedor_id", "provider_id", "proveedor.id", "provider.id"], null),
    cuentas: (Array.isArray(accounts) ? accounts : accounts?.data || []).filter(isActiveRecord)
  };
}

function accountName(account) {
  const entity = account.entidad || account.entity || {};
  const alias = String(firstDefined(account, ["alias", "nombre", "name", "numero_cuenta"], "Cuenta"));
  const institution = firstDefined(account, ["banco", "bank", "numero_cuenta", "numero"], "");
  const balance = firstDefined(account, ["saldo", "saldo_actual", "balance"], null);
  const balanceLabel = balance === null ? "" : ` · saldo ${formatMoney(balance, firstDefined(account, ["moneda", "currency"], "PEN"))}`;
  return `${optionLabel(entity, "Empresa")} — ${alias}${institution && institution !== alias ? ` · ${institution}` : ""}${balanceLabel}`;
}

function accountType(account) {
  return String(firstDefined(account, ["tipo", "type"], "OTRA")).toUpperCase();
}

function selectedMethod() {
  return state.methods.find((method) => String(method.id) === String(elements.method.value)) || null;
}

function selectedMethodCode() {
  return String(firstDefined(selectedMethod() || {}, ["codigo", "code"], "")).toUpperCase();
}

function populateAccounts() {
  const providerId = elements.provider.value;
  const currency = elements.currency.value;
  const cashOnly = selectedMethodCode() === "EFECTIVO";
  const currentOrigin = elements.origin.value;
  const currentDestination = elements.destination.value;

  const own = state.accounts.filter((account) => {
    const entityType = String(firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "")).toUpperCase();
    const accountCurrency = String(firstDefined(account, ["moneda", "currency"], "PEN")).toUpperCase();
    return entityType === "PROPIA"
      && accountCurrency === currency
      && isActiveRecord(account)
      && (!cashOnly || accountType(account) === "CAJA");
  });
  const external = state.accounts.filter((account) => {
    const entityType = String(firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "")).toUpperCase();
    const accountProvider = firstDefined(account, ["proveedor_id", "entidad.proveedor_id", "entity.provider_id"], null);
    const accountCurrency = String(firstDefined(account, ["moneda", "currency"], "PEN")).toUpperCase();
    return entityType === "EXTERNA"
      && accountCurrency === currency
      && isActiveRecord(account)
      && String(accountProvider || "") === String(providerId || "")
      && (!cashOnly || accountType(account) === "CAJA");
  });

  fillSelect(elements.origin, own, {
    placeholder: own.length ? "Selecciona una cuenta propia" : "No hay cuentas propias disponibles",
    selected: currentOrigin,
    label: accountName
  });
  fillSelect(elements.destination, external, {
    placeholder: external.length ? "Selecciona una cuenta del proveedor" : providerId ? "El proveedor no tiene cuentas disponibles" : "Selecciona primero un proveedor",
    selected: currentDestination,
    label: accountName
  });
  elements.origin.disabled = !own.length;
  elements.destination.disabled = !external.length;
}

function updateMethodConstraints() {
  const requiresReference = Boolean(firstDefined(selectedMethod() || {}, ["requiere_referencia", "requires_reference"], false));
  elements.reference.required = isCashPurchase() && requiresReference;
  elements.referenceLabel.innerHTML = requiresReference
    ? "Número de operación / referencia <b>*</b>"
    : "Número de operación / referencia";
  elements.reference.placeholder = requiresReference ? "Referencia obligatoria para este método" : "Ej: OP-384729";
  populateAccounts();
}

function typeOptions(selected = "") {
  return state.types.map((type) => {
    const id = firstDefined(type, ["id", "tipo_pollo_id"]);
    return `<option value="${escapeHtml(id)}" ${String(id) === String(selected) ? "selected" : ""}>${escapeHtml(optionLabel(type, "Tipo de pollo"))}</option>`;
  }).join("");
}

function newLine() {
  return {
    key: state.nextLineId++,
    tipo_pollo_id: "",
    descripcion: "",
    cantidad_aves: "",
    peso_kg: "",
    precio_kg: ""
  };
}

function roundMoney(value) {
  return Math.round((numericValue(value) + Number.EPSILON) * 100) / 100;
}

function lineSubtotal(line) {
  return roundMoney(numericValue(line.peso_kg) * numericValue(line.precio_kg));
}

function renderLines() {
  elements.lines.innerHTML = state.lines.map((line, index) => `
    <article class="fin-purchase-line" data-line-key="${line.key}">
      <header>
        <div><span class="fin-purchase-line-number">${String(index + 1).padStart(2, "0")}</span><strong>Producto ${index + 1}</strong></div>
        <button class="fin-btn fin-btn-danger fin-btn-small" type="button" data-line-remove="${line.key}" ${state.lines.length === 1 ? "disabled" : ""}>Quitar</button>
      </header>
      <div class="fin-purchase-line-grid">
        <label class="fin-field">
          <span>Tipo de pollo <b>*</b></span>
          <select data-line-field="tipo_pollo_id" required><option value="">Selecciona un tipo</option>${typeOptions(line.tipo_pollo_id)}</select>
        </label>
        <label class="fin-field fin-purchase-line-description">
          <span>Descripción adicional</span>
          <input data-line-field="descripcion" type="text" maxlength="250" value="${escapeHtml(line.descripcion)}" placeholder="Opcional">
        </label>
        <label class="fin-field">
          <span>Cantidad de aves</span>
          <input data-line-field="cantidad_aves" type="number" min="1" step="1" inputmode="numeric" value="${escapeHtml(line.cantidad_aves)}" placeholder="Opcional">
        </label>
        <label class="fin-field">
          <span>Peso neto (kg) <b>*</b></span>
          <input data-line-field="peso_kg" type="number" min="0.001" step="0.001" inputmode="decimal" value="${escapeHtml(line.peso_kg)}" placeholder="0.000" required>
        </label>
        <label class="fin-field">
          <span>Precio por kg <b>*</b></span>
          <input data-line-field="precio_kg" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="${escapeHtml(line.precio_kg)}" placeholder="0.0000" required>
        </label>
        <div class="fin-purchase-line-total"><span>Subtotal</span><strong data-line-subtotal>${escapeHtml(formatMoney(lineSubtotal(line), elements.currency.value))}</strong></div>
      </div>
    </article>`).join("");
}

function totals() {
  const subtotal = roundMoney(state.lines.reduce((sum, line) => sum + lineSubtotal(line), 0));
  const tax = roundMoney(Math.max(0, numericValue(elements.tax.value)));
  return { subtotal, tax, total: roundMoney(subtotal + tax) };
}

function updateTotals() {
  const values = totals();
  const currency = elements.currency.value;
  elements.subtotal.textContent = formatMoney(values.subtotal, currency);
  elements.total.textContent = formatMoney(values.total, currency);
}

function updateLineFromInput(input) {
  const article = input.closest("[data-line-key]");
  const line = state.lines.find((item) => String(item.key) === String(article?.dataset.lineKey));
  if (!line) return;
  line[input.dataset.lineField] = input.value;
  const subtotal = article.querySelector("[data-line-subtotal]");
  if (subtotal) subtotal.textContent = formatMoney(lineSubtotal(line), elements.currency.value);
  updateTotals();
}

function updateCondition() {
  const cash = isCashPurchase();
  elements.conditionBadge.textContent = cash ? "Contado" : "Crédito";
  elements.cashPanel.hidden = !cash;
  elements.dueDateField.hidden = cash;
  elements.dueDate.required = !cash;
  elements.origin.required = cash;
  elements.destination.required = cash;
  elements.method.required = cash;
  elements.paymentDate.required = cash;
  elements.summaryHint.textContent = cash
    ? "Al guardar se registrará la compra, la salida de dinero y el pago completo."
    : "Al guardar se creará una deuda por el total de la compra.";
  elements.save.textContent = cash ? "Registrar compra al contado" : "Registrar compra a crédito";
  updateMethodConstraints();
}

function validateLines() {
  if (!state.lines.length) throw new Error("Agrega al menos un producto a la compra.");
  state.lines.forEach((line, index) => {
    if (!line.tipo_pollo_id) throw new Error(`Selecciona el tipo de pollo del producto ${index + 1}.`);
    if (numericValue(line.peso_kg) <= 0) throw new Error(`Ingresa un peso mayor a cero en el producto ${index + 1}.`);
    if (numericValue(line.precio_kg) <= 0) throw new Error(`Ingresa un precio por kg mayor a cero en el producto ${index + 1}.`);
    if (line.cantidad_aves !== "" && (!Number.isInteger(Number(line.cantidad_aves)) || Number(line.cantidad_aves) <= 0)) {
      throw new Error(`La cantidad de aves del producto ${index + 1} debe ser un entero mayor a cero.`);
    }
  });
}

function validateHeader() {
  if (!elements.provider.value) {
    elements.provider.focus();
    throw new Error("Selecciona el proveedor de la compra.");
  }
  if (!elements.documentType.value) throw new Error("Selecciona el tipo de documento.");
  if (!elements.date.value) {
    elements.date.focus();
    throw new Error("Indica la fecha de compra.");
  }
  if (!isCashPurchase()) {
    if (!elements.dueDate.value) {
      elements.dueDate.focus();
      throw new Error("Indica la fecha de vencimiento de la compra a crédito.");
    }
    if (elements.dueDate.value < elements.date.value) {
      elements.dueDate.focus();
      throw new Error("La fecha de vencimiento no puede ser anterior a la compra.");
    }
  }
}

function validateCashPayment(total) {
  if (!isCashPurchase()) return;
  if (!elements.origin.value) {
    elements.origin.focus();
    throw new Error("Selecciona la cuenta propia desde donde salió el dinero.");
  }
  if (!elements.destination.value) {
    elements.destination.focus();
    throw new Error("Selecciona la cuenta receptora del proveedor.");
  }
  if (!elements.method.value) {
    elements.method.focus();
    throw new Error("Selecciona el método de pago.");
  }
  if (!elements.paymentDate.value) {
    elements.paymentDate.focus();
    throw new Error("Indica la fecha y hora del pago.");
  }
  if (elements.reference.required && !elements.reference.value.trim()) {
    elements.reference.focus();
    throw new Error("Ingresa la referencia requerida por el método de pago.");
  }
  const account = state.accounts.find((item) => String(item.id) === String(elements.origin.value));
  const balance = firstDefined(account || {}, ["saldo", "saldo_actual", "balance"], null);
  if (balance !== null && total > numericValue(balance) + .001) {
    elements.origin.focus();
    throw new Error("La cuenta propia seleccionada no tiene saldo suficiente para esta compra.");
  }
}

function purchasePayload() {
  validateHeader();
  validateLines();
  const values = totals();
  if (values.total <= 0) throw new Error("El total de la compra debe ser mayor a cero.");
  validateCashPayment(values.total);

  const payload = {
    idempotency_key: state.idempotencyKey,
    proveedor_id: idValue(elements.provider.value),
    condicion: condition(),
    tipo_documento: elements.documentType.value,
    numero_documento: optionalString(elements.documentNumber.value),
    fecha_compra: elements.date.value,
    fecha_vencimiento: isCashPurchase() ? null : elements.dueDate.value,
    moneda: elements.currency.value,
    observaciones: optionalString(elements.notes.value),
    impuesto: values.tax.toFixed(2),
    detalles: state.lines.map((line) => ({
      tipo_pollo_id: idValue(line.tipo_pollo_id),
      descripcion: optionalString(line.descripcion) || optionLabel(
        state.types.find((type) => String(firstDefined(type, ["id", "tipo_pollo_id"])) === String(line.tipo_pollo_id)) || {},
        "Pollo"
      ),
      cantidad_aves: line.cantidad_aves === "" ? null : Number(line.cantidad_aves),
      peso_kg: numericValue(line.peso_kg).toFixed(3),
      precio_kg: numericValue(line.precio_kg).toFixed(4)
    }))
  };

  if (isCashPurchase()) {
    payload.pago = {
      cuenta_origen_id: idValue(elements.origin.value),
      cuenta_destino_id: idValue(elements.destination.value),
      metodo_pago_id: idValue(elements.method.value),
      referencia: optionalString(elements.reference.value),
      fecha_hora: elements.paymentDate.value
    };
  }
  return payload;
}

function setDefaultDates() {
  const now = new Date();
  const due = new Date(now);
  due.setDate(due.getDate() + 7);
  elements.date.value = todayValue(now);
  elements.dueDate.value = todayValue(due);
  elements.paymentDate.value = toLocalDateTimeValue(now);
}

function resetForm({ keepMessage = false } = {}) {
  elements.form.reset();
  state.lines = [newLine()];
  state.idempotencyKey = createIdempotencyKey();
  setDefaultDates();
  renderLines();
  updateCondition();
  updateTotals();
  if (!keepMessage) setMessage(elements.message);
  setMessage(elements.linesMessage);
}

async function loadCatalog() {
  setMessage(elements.message, "Cargando catálogo de compras...");
  try {
    const response = await apiRequest("/compras/catalogo");
    const currentProvider = elements.provider.value || queryProvider;
    const currentMethod = elements.method.value;
    state.providers = responseCollection(response, ["proveedores", "providers", "catalogo.proveedores"]);
    state.types = responseCollection(response, ["tipos_pollo", "tipos", "chicken_types", "catalogo.tipos_pollo"]);
    state.entities = responseCollection(response, ["entidades", "entities", "catalogo.entidades"]).map(normalizeEntity);
    state.methods = responseCollection(response, ["metodos_pago", "metodos", "payment_methods", "catalogo.metodos_pago"]);
    const ownAccounts = responseCollection(response, ["cuentas_propias", "own_accounts"])
      .map((account) => ({
        ...account,
        entidad_tipo: firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "PROPIA")
      }));
    const providerAccounts = responseCollection(response, ["cuentas_proveedores", "provider_accounts"])
      .map((account) => ({
        ...account,
        entidad_tipo: firstDefined(account, ["entidad_tipo", "entidad.tipo", "entity.type"], "EXTERNA")
      }));
    state.accounts = (ownAccounts.length || providerAccounts.length
      ? [...ownAccounts, ...providerAccounts]
      : accountListFromEntities(state.entities)).filter(isActiveRecord);
    const currency = String(firstDefined(response, ["data.moneda", "moneda", "data.currency", "currency"], elements.currency.value));
    if ([...elements.currency.options].some((option) => option.value === currency)) elements.currency.value = currency;

    fillSelect(elements.provider, state.providers, { placeholder: "Selecciona un proveedor", selected: currentProvider });
    fillSelect(elements.method, state.methods, { placeholder: "Selecciona un método", selected: currentMethod });
    renderLines();
    updateMethodConstraints();
    updateTotals();
    setMessage(elements.message);
    if (!state.providers.length) setMessage(elements.message, "No hay proveedores activos para registrar la compra.", "error");
    else if (!state.types.length) setMessage(elements.message, "No hay tipos de pollo activos para agregar al detalle.", "error");
    markFinanceAccessReady();
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudo cargar el catálogo de compras."), "error");
  }
}

async function savePurchase(event) {
  event.preventDefault();
  if (state.saving) return;
  setMessage(elements.message);
  setMessage(elements.linesMessage);

  try {
    const payload = purchasePayload();
    state.saving = true;
    elements.save.disabled = true;
    elements.reset.disabled = true;
    elements.save.textContent = "Registrando compra...";
    const response = await apiRequest("/compras", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    const purchaseId = firstDefined(response, ["data.id", "data.compra.id", "compra.id", "id"], null);
    const message = response?.message || "Compra registrada correctamente.";
    if (purchaseId) {
      setMessage(elements.message, `${message} Abriendo el detalle...`, "success");
      const base = document.body.dataset.purchasesUrl || "/compras";
      window.setTimeout(() => window.location.assign(`${base}?compra=${encodeURIComponent(purchaseId)}`), 450);
      return;
    }
    resetForm({ keepMessage: true });
    setMessage(elements.message, message, "success");
  } catch (error) {
    setMessage(elements.message, errorMessage(error, "No se pudo registrar la compra."), "error");
  } finally {
    state.saving = false;
    elements.save.disabled = false;
    elements.reset.disabled = false;
    updateCondition();
  }
}

elements.conditionInputs.forEach((input) => input.addEventListener("change", updateCondition));
elements.provider.addEventListener("change", populateAccounts);
elements.currency.addEventListener("change", () => {
  populateAccounts();
  renderLines();
  updateTotals();
});
elements.method.addEventListener("change", updateMethodConstraints);
elements.tax.addEventListener("input", updateTotals);
elements.addLine.addEventListener("click", () => {
  state.lines.push(newLine());
  renderLines();
  updateTotals();
  elements.lines.querySelector("[data-line-key]:last-child select")?.focus();
});
elements.lines.addEventListener("input", (event) => {
  const input = event.target.closest("[data-line-field]");
  if (input) updateLineFromInput(input);
});
elements.lines.addEventListener("change", (event) => {
  const input = event.target.closest("[data-line-field]");
  if (input) updateLineFromInput(input);
});
elements.lines.addEventListener("click", (event) => {
  const button = event.target.closest("[data-line-remove]");
  if (!button || state.lines.length === 1) return;
  state.lines = state.lines.filter((line) => String(line.key) !== String(button.dataset.lineRemove));
  renderLines();
  updateTotals();
});
elements.form.addEventListener("submit", savePurchase);
elements.reset.addEventListener("click", () => resetForm());

state.lines = [newLine()];
setDefaultDates();
renderLines();
updateCondition();
updateTotals();
initFinanceAccess(loadCatalog);
void loadCatalog();
