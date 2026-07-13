import { authSession, login } from "./api-client.js";

let accessRetry = null;
let accessInitialized = false;

export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function errorMessage(error, fallback = "No se pudo completar la operación.") {
  const validationErrors = error?.data?.errors;
  const firstValidationError = validationErrors
    ? Object.values(validationErrors).flat().find(Boolean)
    : null;

  if (firstValidationError) return String(firstValidationError);
  if (Number(error?.status) === 401) return "Inicia sesión para consultar la información financiera.";
  return error?.message || fallback;
}

export function setMessage(element, message = "", tone = "") {
  if (!element) return;
  element.textContent = message;
  element.classList.toggle("is-error", tone === "error");
  element.classList.toggle("is-success", tone === "success");
}

export function dataRoot(response) {
  return response?.data ?? response ?? {};
}

function nestedValue(source, path) {
  return String(path).split(".").reduce((value, key) => value?.[key], source);
}

export function responseCollection(response, keys = []) {
  const candidates = [response, response?.data, response?.data?.data].filter(Boolean);

  for (const candidate of candidates) {
    if (Array.isArray(candidate)) return candidate;

    for (const key of keys) {
      const value = nestedValue(candidate, key);
      if (Array.isArray(value)) return value;
      if (Array.isArray(value?.data)) return value.data;
    }

    if (Array.isArray(candidate?.data)) return candidate.data;
  }

  return [];
}

export function responseMeta(response) {
  return response?.meta || response?.data?.meta || response?.data?.data?.meta || {};
}

export function firstDefined(source, keys, fallback = null) {
  for (const key of keys) {
    const value = nestedValue(source, key);
    if (value !== undefined && value !== null && value !== "") return value;
  }
  return fallback;
}

export function numericValue(value, fallback = 0) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

export function optionalString(value) {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? null : normalized;
}

export function formatMoney(value, currency = "PEN") {
  const amount = numericValue(value);

  try {
    return new Intl.NumberFormat("es-PE", {
      style: "currency",
      currency: currency || "PEN",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(amount);
  } catch {
    return `S/ ${amount.toFixed(2)}`;
  }
}

export function formatDateTime(value) {
  if (!value) return "Sin fecha";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit"
  }).format(date);
}

export function toLocalDateTimeValue(date = new Date()) {
  const offsetDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return offsetDate.toISOString().slice(0, 16);
}

export function createIdempotencyKey() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (window.crypto?.getRandomValues) {
    window.crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const value = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  return `${value.slice(0, 8)}-${value.slice(8, 12)}-${value.slice(12, 16)}-${value.slice(16, 20)}-${value.slice(20)}`;
}

export function optionLabel(record, fallback = "Sin nombre") {
  return String(firstDefined(record, [
    "razon_social",
    "nombre_completo",
    "name",
    "nombre",
    "label",
    "descripcion",
    "codigo"
  ], fallback));
}

export function fillSelect(select, records, {
  placeholder = "Selecciona una opción",
  selected = "",
  label = optionLabel,
  value = (record) => record?.id
} = {}) {
  if (!select) return;

  const options = records.map((record) => {
    const optionValue = value(record);
    return `<option value="${escapeHtml(optionValue)}">${escapeHtml(label(record))}</option>`;
  });

  select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>${options.join("")}`;
  select.value = String(selected ?? "");
}

function updateAccessState(state, label) {
  const button = document.getElementById("financeAccessButton");
  const labelElement = document.getElementById("financeAccessLabel");

  if (!button || !labelElement) return;
  button.classList.toggle("is-ready", state === "ready");
  button.classList.toggle("is-required", state === "required");
  labelElement.textContent = label;
}

export function openFinanceAccess() {
  const dialog = document.getElementById("financeAuthDialog");
  const loginField = document.getElementById("financeAuthLogin");

  updateAccessState("required", "Acceso requerido");
  if (!dialog) return;

  if (!dialog.open) {
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  window.setTimeout(() => loginField?.focus(), 40);
}

export function markFinanceAccessReady() {
  updateAccessState("ready", "Sesión financiera activa");
}

export function initFinanceAccess(retry) {
  accessRetry = retry;
  const dialog = document.getElementById("financeAuthDialog");
  const form = document.getElementById("financeAuthForm");
  const button = document.getElementById("financeAccessButton");
  const submit = document.getElementById("financeAuthSubmit");
  const loginField = document.getElementById("financeAuthLogin");
  const password = document.getElementById("financeAuthPassword");
  const message = document.getElementById("financeAuthMessage");

  if (authSession.getToken()) markFinanceAccessReady();
  else updateAccessState("required", "Acceso requerido");

  if (accessInitialized) return;
  accessInitialized = true;

  window.addEventListener("auth:expired", openFinanceAccess);
  button?.addEventListener("click", openFinanceAccess);

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const loginValue = loginField?.value.trim() || "";
    const passwordValue = password?.value || "";

    if (!loginValue || !passwordValue) {
      setMessage(message, "Ingresa tu usuario o correo y contraseña.", "error");
      (!loginValue ? loginField : password)?.focus();
      return;
    }

    submit.disabled = true;
    setMessage(message, "Verificando acceso...");

    try {
      await login(loginValue, passwordValue, "finanzas-web");
      markFinanceAccessReady();
      setMessage(message, "Acceso confirmado.", "success");
      password.value = "";

      if (dialog?.open) dialog.close();
      await accessRetry?.();
    } catch (error) {
      setMessage(message, errorMessage(error, "No se pudo iniciar sesión."), "error");
      password?.focus();
    } finally {
      submit.disabled = false;
    }
  });
}

export function isActiveRecord(record) {
  const status = String(firstDefined(record, ["estado", "status"], "ACTIVO")).toUpperCase();
  const active = firstDefined(record, ["activo", "active", "is_active"], null);
  if (active !== null) return Boolean(active);
  return !["INACTIVA", "INACTIVO", "ANULADA", "ANULADO", "0", "FALSE"].includes(status);
}

export function accountListFromEntities(entities) {
  return entities.flatMap((entity) => {
    const accounts = firstDefined(entity, ["cuentas", "accounts"], []);
    return (Array.isArray(accounts) ? accounts : []).map((account) => ({
      ...account,
      entidad: account.entidad || entity,
      entidad_id: account.entidad_id || entity.id,
      entidad_tipo: account.entidad_tipo || entity.tipo || entity.type,
      proveedor_id: account.proveedor_id || entity.proveedor_id || entity.proveedor?.id
    }));
  });
}
