const form = document.querySelector("#loginForm");
const identifierInput = document.querySelector("#loginIdentifier");
const passwordInput = document.querySelector("#loginPassword");
const passwordToggle = document.querySelector("#loginPasswordToggle");
const capsLockNotice = document.querySelector("#loginCapsLock");
const message = document.querySelector("#loginMessage");
const serverMessage = document.querySelector("#loginServerMessage");
const submitButton = document.querySelector("#loginSubmit");

function showMessage(text) {
  message.textContent = text;
  message.hidden = false;
  message.focus({ preventScroll: true });
}

function clearMessages() {
  message.hidden = true;
  message.textContent = "";
  if (serverMessage) serverMessage.hidden = true;
  document.querySelector("#loginIdentifierError").textContent = "";
  document.querySelector("#loginPasswordError").textContent = "";
  identifierInput.removeAttribute("aria-invalid");
  passwordInput.removeAttribute("aria-invalid");
}

function fieldError(input, errorElement, text) {
  input.setAttribute("aria-invalid", "true");
  errorElement.textContent = text;
}

function setBusy(busy) {
  submitButton.disabled = busy;
  submitButton.querySelector(".access-button-label").textContent = busy
    ? "Verificando…"
    : "Ingresar al sistema";
  submitButton.querySelector(".access-spinner").hidden = !busy;
  submitButton.setAttribute("aria-busy", String(busy));
}

function validate() {
  let firstInvalid = null;
  const identifier = identifierInput.value.trim();

  if (!identifier) {
    fieldError(identifierInput, document.querySelector("#loginIdentifierError"), "Ingresa tu correo o nombre de usuario.");
    firstInvalid = identifierInput;
  }

  if (!passwordInput.value) {
    fieldError(passwordInput, document.querySelector("#loginPasswordError"), "Ingresa tu contraseña.");
    firstInvalid ||= passwordInput;
  }

  firstInvalid?.focus();
  return !firstInvalid;
}

passwordToggle?.addEventListener("click", () => {
  const shouldShow = passwordInput.type === "password";
  passwordInput.type = shouldShow ? "text" : "password";
  passwordToggle.setAttribute("aria-pressed", String(shouldShow));
  passwordToggle.setAttribute("aria-label", shouldShow ? "Ocultar contraseña" : "Mostrar contraseña");
  passwordInput.focus();
});

passwordInput?.addEventListener("keyup", event => {
  capsLockNotice.hidden = !event.getModifierState?.("CapsLock");
});

passwordInput?.addEventListener("blur", () => {
  capsLockNotice.hidden = true;
});

for (const input of [identifierInput, passwordInput]) {
  input?.addEventListener("input", () => {
    clearMessages();
  });
}

form?.addEventListener("submit", async event => {
  clearMessages();
  if (!validate()) {
    event.preventDefault();
    return;
  }

  setBusy(true);
});

window.addEventListener("pageshow", () => setBusy(false));
