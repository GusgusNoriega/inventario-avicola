import { apiRequest, authSession, logout } from "./api-client.js";

const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

const urls = {
  login: document.body.dataset.loginUrl || "/login",
  menu: document.body.dataset.menuUrl || "/",
};

let account = null;

function payloadUser(response) {
  const payload = response?.data ?? response ?? {};
  return payload?.user?.data ?? payload?.user ?? payload;
}

function normalizeRole(role) {
  if (typeof role === "string") return role.replaceAll("_", " ");
  return role?.name ?? role?.nombre ?? role?.code ?? role?.codigo ?? "Rol";
}

function normalizeAccount(item = {}) {
  const branch = item.branch ?? item.sucursal ?? null;
  return {
    id: Number(item.id),
    name: String(item.name ?? item.nombre ?? "Usuario"),
    email: String(item.email ?? item.correo ?? ""),
    status: String(item.status ?? item.estado ?? "ACTIVO").toUpperCase(),
    branch: branch ? String(branch.name ?? branch.nombre ?? branch.code ?? branch.codigo ?? "Sucursal") : null,
    roles: (item.roles ?? []).map(normalizeRole),
    mustChangePassword: Boolean(item.must_change_password ?? item.debe_cambiar_password),
  };
}

function initials(value) {
  return String(value || "Usuario").trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join("").toUpperCase() || "US";
}

function safeRedirect(value, fallback = urls.menu) {
  if (!value || typeof value !== "string" || !value.startsWith("/") || value.startsWith("//")) return fallback;
  try {
    const url = new URL(value, window.location.origin);
    return url.origin === window.location.origin ? `${url.pathname}${url.search}${url.hash}` : fallback;
  } catch {
    return fallback;
  }
}

function messageFor(error, fallback = "No se pudo completar la solicitud.") {
  if (error?.status === 422) return error?.message || "Revisa los campos marcados e intenta de nuevo.";
  if (error?.status === 403) return error?.message || "No puedes realizar esta acción.";
  if (error?.status === 429) return "Se realizaron demasiadas solicitudes. Espera un momento e intenta de nuevo.";
  return error?.message || fallback;
}

function setAlert(target, text, type = "danger") {
  target.textContent = text;
  target.className = `access-alert access-alert-${type}`;
  target.hidden = false;
}

function clearAlert(target) {
  target.hidden = true;
  target.textContent = "";
}

function toast(text, error = false) {
  const notice = document.createElement("div");
  notice.className = `access-toast${error ? " is-error" : ""}`;
  notice.setAttribute("role", error ? "alert" : "status");
  notice.textContent = text;
  $("#accountToasts").append(notice);
  window.setTimeout(() => notice.remove(), 4500);
}

function setButtonBusy(button, busy, busyLabel) {
  const label = $(".access-button-label", button);
  const spinner = $(".access-spinner", button);
  if (!button.dataset.idleLabel && label) button.dataset.idleLabel = label.textContent;
  button.disabled = busy;
  button.setAttribute("aria-busy", String(busy));
  if (label) label.textContent = busy ? busyLabel : button.dataset.idleLabel;
  if (spinner) spinner.hidden = !busy;
}

function clearFormErrors(form) {
  $$(".access-field-error", form).forEach(node => { node.textContent = ""; });
  $$(".access-field.is-invalid", form).forEach(node => node.classList.remove("is-invalid"));
  $$("[aria-invalid='true']", form).forEach(node => node.removeAttribute("aria-invalid"));
}

function showFormErrors(form, errors = {}) {
  let firstInvalid = null;
  for (const [key, messages] of Object.entries(errors)) {
    const errorNode = $$('[data-error-for]', form).find(node => node.dataset.errorFor === key);
    const input = form.elements.namedItem(key);
    if (errorNode) errorNode.textContent = Array.isArray(messages) ? messages[0] : String(messages);
    if (input instanceof HTMLElement) {
      input.setAttribute("aria-invalid", "true");
      input.closest(".access-field")?.classList.add("is-invalid");
      firstInvalid ||= input;
    }
  }
  firstInvalid?.focus();
}

function renderAccount() {
  if (!account) return;
  $("#accountAvatar").textContent = initials(account.name);
  $("#accountDisplayName").textContent = account.name;
  $("#accountDisplayEmail").textContent = account.email;
  $("#accountStatus").replaceChildren();
  const status = document.createElement("span");
  status.className = `access-status ${account.status === "ACTIVO" ? "is-active" : "is-inactive"}`;
  status.textContent = account.status === "ACTIVO" ? "Activo" : "Inactivo";
  $("#accountStatus").append(status);
  $("#accountBranch").textContent = account.branch || "Sin asignar";
  $("#accountRoles").textContent = account.roles.join(", ") || "Sin roles";
  $("#accountName").value = account.name;
  $("#accountEmail").value = account.email;
  $("#accountBranchReadOnly").value = account.branch || "Sin asignar";

  const forcedByQuery = new URLSearchParams(window.location.search).get("password") === "required";
  $("#accountRequiredPasswordNotice").hidden = !(account.mustChangePassword || forcedByQuery);
}

async function loadAccount() {
  clearAlert($("#accountLoadMessage"));
  try {
    account = normalizeAccount(payloadUser(await apiRequest("/account")));
    renderAccount();
  } catch (error) {
    setAlert($("#accountLoadMessage"), messageFor(error, "No fue posible cargar los datos de tu cuenta."));
    $("#profileForm").inert = true;
    $("#accountPasswordForm").inert = true;
  }
}

async function saveProfile(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const message = $("#profileFormMessage");
  clearFormErrors(form);
  clearAlert(message);
  if (!form.reportValidity()) return;
  const button = $("#saveProfileButton");
  setButtonBusy(button, true, "Guardando…");
  try {
    const response = await apiRequest("/account", {
      method: "PUT",
      body: JSON.stringify({
        name: $("#accountName").value.trim(),
        email: $("#accountEmail").value.trim(),
      }),
    });
    account = normalizeAccount(payloadUser(response));
    renderAccount();
    setAlert(message, "Tus datos se actualizaron correctamente.", "success");
    toast("Tus datos se guardaron.");
  } catch (error) {
    showFormErrors(form, error?.data?.errors);
    setAlert(message, messageFor(error));
    message.focus();
  } finally {
    setButtonBusy(button, false);
  }
}

async function savePassword(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const message = $("#accountPasswordMessage");
  clearFormErrors(form);
  clearAlert(message);
  if (!form.reportValidity()) return;

  if ($("#newPassword").value !== $("#newPasswordConfirmation").value) {
    $('[data-error-for="password_confirmation"]', form).textContent = "Las contraseñas no coinciden.";
    $("#newPasswordConfirmation").setAttribute("aria-invalid", "true");
    $("#newPasswordConfirmation").focus();
    return;
  }

  const button = $("#saveAccountPasswordButton");
  const params = new URLSearchParams(window.location.search);
  const wasRequired = Boolean(account?.mustChangePassword || params.get("password") === "required");
  setButtonBusy(button, true, "Actualizando…");
  try {
    await apiRequest("/account/password", {
      method: "PUT",
      body: JSON.stringify({
        current_password: $("#currentPassword").value,
        password: $("#newPassword").value,
        password_confirmation: $("#newPasswordConfirmation").value,
        revoke_other_sessions: $("#accountRevokeSessions").checked,
      }),
    });
    form.reset();
    $("#accountRevokeSessions").checked = true;
    account.mustChangePassword = false;
    $("#accountRequiredPasswordNotice").hidden = true;
    setAlert(message, "Tu contraseña se cambió correctamente.", "success");
    toast("Contraseña actualizada.");

    if (wasRequired) {
      const destination = safeRedirect(params.get("redirect"));
      window.setTimeout(() => window.location.assign(destination), 900);
    }
  } catch (error) {
    showFormErrors(form, error?.data?.errors);
    setAlert(message, messageFor(error));
    message.focus();
  } finally {
    setButtonBusy(button, false);
  }
}

function bindPasswordToggles() {
  $$('[data-password-toggle]').forEach(button => {
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-pressed", String(show));
      button.setAttribute("aria-label", show ? "Ocultar contraseña" : "Mostrar contraseña");
      input.focus();
    });
  });
}

function bindEvents() {
  $("#profileForm").addEventListener("submit", saveProfile);
  $("#accountPasswordForm").addEventListener("submit", savePassword);
  bindPasswordToggles();

  $("#accountLogoutButton").addEventListener("click", async () => {
    const button = $("#accountLogoutButton");
    button.disabled = true;
    try { await logout(); } finally { window.location.assign(urls.login); }
  });

  $("#logoutAllButton").addEventListener("click", async () => {
    if (!window.confirm("Se cerrarán todas tus sesiones, incluida esta. ¿Deseas continuar?")) return;
    const button = $("#logoutAllButton");
    setButtonBusy(button, true, "Cerrando…");
    try {
      await apiRequest("/auth/logout-all", { method: "POST" });
      authSession.clear();
      window.location.assign(urls.login);
    } catch (error) {
      setButtonBusy(button, false);
      toast(messageFor(error, "No fue posible cerrar todas las sesiones."), true);
    }
  });

  window.addEventListener("auth:expired", () => {
    const redirect = encodeURIComponent(`${window.location.pathname}${window.location.search}${window.location.hash}`);
    window.location.assign(`${urls.login}?redirect=${redirect}`);
  }, { once: true });
}

bindEvents();
loadAccount();
