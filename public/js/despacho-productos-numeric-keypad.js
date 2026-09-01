const DEFAULT_MAX_LENGTH = 6;

function normalizedMaxLength(value) {
  const parsed = Math.trunc(Number(value));
  return Number.isInteger(parsed) && parsed > 0 ? parsed : DEFAULT_MAX_LENGTH;
}

export function sanitizeIntegerKeypadBuffer(value, maxLength = DEFAULT_MAX_LENGTH) {
  const limit = normalizedMaxLength(maxLength);
  const digits = String(value ?? "").replace(/\D/g, "").slice(0, limit);
  return digits.replace(/^0+(?=\d)/, "");
}

export function applyIntegerKeypadKey(buffer, key, maxLength = DEFAULT_MAX_LENGTH) {
  const limit = normalizedMaxLength(maxLength);
  const current = sanitizeIntegerKeypadBuffer(buffer, limit);

  if (key === "clear") return "";
  if (key === "backspace") return current.slice(0, -1);
  if (!/^\d{1,2}$/.test(String(key))) return current;

  const prefix = current === "0" ? "" : current;
  return sanitizeIntegerKeypadBuffer(`${prefix}${key}`, limit);
}

export function validateIntegerKeypadBuffer(buffer, options = {}) {
  const { required = true } = options;
  const value = sanitizeIntegerKeypadBuffer(buffer, options.maxLength);

  if (!value) return required ? "Ingresa una cantidad." : "";

  const numeric = Number(value);
  if (!Number.isSafeInteger(numeric)) return "Ingresa una cantidad válida.";

  const minimum = Number(options.min);
  if (options.min !== undefined && Number.isFinite(minimum) && numeric < minimum) {
    return `La cantidad mínima es ${minimum}.`;
  }

  const maximum = Number(options.max);
  if (options.max !== undefined && Number.isFinite(maximum) && numeric > maximum) {
    return `La cantidad máxima es ${maximum}.`;
  }

  return "";
}

export function bindIntegerKeypad(options = {}) {
  const {
    input,
    dialog,
    valueOutput,
    messageOutput,
    clearButton,
    confirmButton,
    showDialog,
    hideDialog,
    onCommit
  } = options;

  if (!input || !dialog || !valueOutput || !messageOutput || !clearButton || !confirmButton) {
    throw new TypeError("El teclado numérico necesita todos sus controles.");
  }

  const keyButtons = [...dialog.querySelectorAll("[data-pdd-keypad-key]")];
  const maxLength = normalizedMaxLength(options.maxLength);
  const fieldLabel = String(input.dataset.pddKeypadLabel || input.getAttribute("aria-label") || "Cantidad").trim();
  let buffer = "";
  let replaceOnNextDigit = false;

  function refreshLabel() {
    const currentValue = String(input.value || "0");
    input.setAttribute("aria-label", `${fieldLabel}: ${currentValue}. Presiona para cambiarla con el teclado táctil.`);
  }

  function render(message = "") {
    valueOutput.textContent = buffer || "0";
    messageOutput.textContent = message;
  }

  function open() {
    if (input.disabled) return;
    buffer = sanitizeIntegerKeypadBuffer(input.value, maxLength);
    replaceOnNextDigit = true;
    render();
    input.setAttribute("aria-expanded", "true");
    if (typeof showDialog === "function") {
      showDialog(keyButtons[0] || confirmButton);
    } else if (!dialog.open) {
      dialog.showModal();
    }
  }

  function close() {
    input.setAttribute("aria-expanded", "false");
    if (typeof hideDialog === "function") {
      hideDialog();
    } else if (dialog.open) {
      dialog.close();
    }
  }

  function applyKey(key) {
    if (/^\d{1,2}$/.test(String(key)) && replaceOnNextDigit) {
      buffer = "";
    }
    replaceOnNextDigit = false;
    buffer = applyIntegerKeypadKey(buffer, key, maxLength);
    render();
  }

  function confirm() {
    const validationMessage = validateIntegerKeypadBuffer(buffer, {
      required: input.required,
      min: input.hasAttribute("min") ? input.min : undefined,
      max: input.hasAttribute("max") ? input.max : undefined,
      maxLength
    });
    if (validationMessage) {
      render(validationMessage);
      return;
    }

    const nextValue = String(Number(buffer));
    input.value = nextValue;
    refreshLabel();
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    if (typeof onCommit === "function") onCommit(Number(nextValue));
    close();
  }

  input.readOnly = true;
  input.setAttribute("inputmode", "none");
  input.setAttribute("role", "button");
  input.setAttribute("aria-haspopup", "dialog");
  input.setAttribute("aria-controls", dialog.id);
  input.setAttribute("aria-expanded", "false");
  refreshLabel();
  input.addEventListener("click", open);
  input.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    open();
  });

  keyButtons.forEach((button) => {
    button.addEventListener("click", () => applyKey(button.dataset.pddKeypadKey));
  });
  clearButton.addEventListener("click", () => applyKey("clear"));
  confirmButton.addEventListener("click", confirm);
  dialog.addEventListener("close", () => input.setAttribute("aria-expanded", "false"));
  dialog.addEventListener("keydown", (event) => {
    if (/^\d$/.test(event.key)) {
      event.preventDefault();
      applyKey(event.key);
      return;
    }
    if (event.key === "Backspace") {
      event.preventDefault();
      applyKey("backspace");
      return;
    }
    if (event.key === "Delete") {
      event.preventDefault();
      applyKey("clear");
      return;
    }
    if (event.key === "Enter") {
      event.preventDefault();
      confirm();
    }
  });

  return { open, close, confirm, refreshLabel };
}
