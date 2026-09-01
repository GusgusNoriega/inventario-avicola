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
  const valueName = String(options.valueName || "cantidad").trim();
  const valueArticle = String(options.valueArticle || "una").trim();

  if (!value) return required ? `Ingresa ${valueArticle} ${valueName}.` : "";

  const numeric = Number(value);
  if (!Number.isSafeInteger(numeric)) return `Ingresa ${valueArticle} ${valueName} válida.`;

  const minimum = Number(options.min);
  if (options.min !== undefined && Number.isFinite(minimum) && numeric < minimum) {
    return `La ${valueName} mínima es ${minimum}.`;
  }

  const maximum = Number(options.max);
  if (options.max !== undefined && Number.isFinite(maximum) && numeric > maximum) {
    return `La ${valueName} máxima es ${maximum}.`;
  }

  return "";
}

export function bindIntegerKeypad(options = {}) {
  const {
    dialog,
    valueOutput,
    messageOutput,
    clearButton,
    confirmButton,
    titleOutput,
    valueLabelOutput,
    showDialog,
    hideDialog
  } = options;

  const inputOptions = Array.isArray(options.inputs) && options.inputs.length
    ? options.inputs
    : [{
        input: options.input,
        maxLength: options.maxLength,
        onCommit: options.onCommit,
        valueName: options.valueName,
        valueArticle: options.valueArticle
      }];
  const bindings = inputOptions
    .map((entry) => (entry?.input ? entry : { input: entry }))
    .filter((entry) => entry.input);

  if (!bindings.length || !dialog || !valueOutput || !messageOutput || !clearButton || !confirmButton) {
    throw new TypeError("El teclado numérico necesita todos sus controles.");
  }

  const keyButtons = [...dialog.querySelectorAll("[data-pdd-keypad-key]")];
  let activeBinding = bindings[0];
  let buffer = "";
  let replaceOnNextDigit = false;

  function bindingMaxLength(binding = activeBinding) {
    return normalizedMaxLength(binding?.maxLength ?? options.maxLength);
  }

  function fieldLabel(binding = activeBinding) {
    const input = binding?.input;
    return String(input?.dataset.pddKeypadLabel || input?.getAttribute("aria-label") || "Valor").trim();
  }

  function refreshLabel(input = activeBinding?.input) {
    if (!input) return;
    const currentValue = String(input.value || "0");
    const binding = bindings.find((entry) => entry.input === input) || activeBinding;
    input.setAttribute("aria-label", `${fieldLabel(binding)}: ${currentValue}. Presiona para cambiarla con el teclado táctil.`);
  }

  function renderDialogCopy(binding = activeBinding) {
    const input = binding.input;
    if (titleOutput) titleOutput.textContent = fieldLabel(binding);
    if (valueLabelOutput) valueLabelOutput.textContent = input.dataset.pddKeypadValueLabel || "Valor seleccionado";
    confirmButton.textContent = input.dataset.pddKeypadConfirmLabel || "Usar valor";
  }

  function render(message = "") {
    valueOutput.textContent = buffer || "0";
    messageOutput.textContent = message;
  }

  function open(input = bindings[0].input) {
    const nextBinding = bindings.find((entry) => entry.input === input);
    if (!nextBinding) return;
    activeBinding = nextBinding;
    if (input.disabled) return;
    buffer = sanitizeIntegerKeypadBuffer(input.value, bindingMaxLength());
    replaceOnNextDigit = true;
    renderDialogCopy();
    const numericValue = Number(input.value);
    const maximum = Number(input.max);
    const exceedsMaximum = input.hasAttribute("max") && Number.isFinite(maximum) && numericValue > maximum;
    render(exceedsMaximum ? `La ${input.dataset.pddKeypadValueName || "cantidad"} máxima es ${maximum}. Ingresa un valor nuevo.` : "");
    input.setAttribute("aria-expanded", "true");
    if (typeof showDialog === "function") {
      showDialog(valueOutput);
    } else if (!dialog.open) {
      dialog.showModal();
      valueOutput.focus();
    }
  }

  function close() {
    activeBinding?.input.setAttribute("aria-expanded", "false");
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
    buffer = applyIntegerKeypadKey(buffer, key, bindingMaxLength());
    render();
  }

  function confirm() {
    const input = activeBinding.input;
    const maxLength = bindingMaxLength();
    const validationMessage = validateIntegerKeypadBuffer(buffer, {
      required: input.required,
      min: input.hasAttribute("min") ? input.min : undefined,
      max: input.hasAttribute("max") ? input.max : undefined,
      maxLength,
      valueName: activeBinding.valueName || input.dataset.pddKeypadValueName,
      valueArticle: activeBinding.valueArticle || input.dataset.pddKeypadValueArticle
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
    const onCommit = activeBinding.onCommit || options.onCommit;
    if (typeof onCommit === "function") onCommit(Number(nextValue), input);
    close();
  }

  bindings.forEach(({ input }) => {
    input.readOnly = true;
    input.setAttribute("inputmode", "none");
    input.setAttribute("role", "button");
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-controls", dialog.id);
    input.setAttribute("aria-expanded", "false");
    refreshLabel(input);
    input.addEventListener("click", () => open(input));
    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
      open(input);
    });
  });

  keyButtons.forEach((button) => {
    button.addEventListener("click", () => applyKey(button.dataset.pddKeypadKey));
  });
  clearButton.addEventListener("click", () => applyKey("clear"));
  confirmButton.addEventListener("click", confirm);
  dialog.addEventListener("close", () => {
    bindings.forEach(({ input }) => input.setAttribute("aria-expanded", "false"));
  });
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
      if (event.target.closest?.("button")) return;
      event.preventDefault();
      confirm();
    }
  });

  return { open, close, confirm, refreshLabel };
}
