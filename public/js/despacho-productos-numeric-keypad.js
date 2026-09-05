const DEFAULT_MAX_LENGTH = 6;
const DEFAULT_DECIMAL_PLACES = 2;

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
  const current = String(buffer ?? "");

  if (key === "clear") return "";
  if (key === "backspace") return current.slice(0, -1);
  if (!/^\d{1,2}$/.test(String(key))) return current;

  const prefix = current === "0" ? "" : current;
  const next = `${prefix}${key}`;
  if (!/^\d+$/.test(next) || next.length > limit) return current;
  return next.replace(/^0+(?=\d)/, "");
}

function normalizedDecimalPlaces(value) {
  const parsed = Math.trunc(Number(value));
  return Number.isInteger(parsed) && parsed >= 0 ? parsed : DEFAULT_DECIMAL_PLACES;
}

export function sanitizeDecimalKeypadBuffer(
  value,
  maxIntegerLength = DEFAULT_MAX_LENGTH,
  decimalPlaces = DEFAULT_DECIMAL_PLACES
) {
  const integerLimit = normalizedMaxLength(maxIntegerLength);
  const decimalLimit = normalizedDecimalPlaces(decimalPlaces);
  const source = String(value ?? "").trim().replaceAll(",", ".");
  let filtered = "";
  let hasSeparator = false;

  for (const character of source) {
    if (/^\d$/.test(character)) {
      filtered += character;
    } else if (character === "." && !hasSeparator) {
      filtered += character;
      hasSeparator = true;
    }
  }

  if (!filtered) return "";
  const separatorIndex = filtered.indexOf(".");
  const rawInteger = separatorIndex >= 0 ? filtered.slice(0, separatorIndex) : filtered;
  const rawDecimals = separatorIndex >= 0 ? filtered.slice(separatorIndex + 1) : "";
  const integer = (rawInteger || "0")
    .replace(/^0+(?=\d)/, "")
    .slice(0, integerLimit) || "0";

  if (separatorIndex < 0 || decimalLimit === 0) return integer;
  return `${integer}.${rawDecimals.slice(0, decimalLimit)}`;
}

export function applyDecimalKeypadKey(
  buffer,
  key,
  maxIntegerLength = DEFAULT_MAX_LENGTH,
  decimalPlaces = DEFAULT_DECIMAL_PLACES
) {
  const current = String(buffer ?? "").replaceAll(",", ".");

  if (key === "clear") return "";
  if (key === "backspace") return current.slice(0, -1);
  if (key === "." || key === ",") {
    if (current.includes(".") || normalizedDecimalPlaces(decimalPlaces) === 0) return current;
    return `${current || "0"}.`;
  }
  if (!/^\d{1,2}$/.test(String(key))) return current;

  const next = `${current === "0" ? "" : current}${key}`;
  const [integer, decimals = ""] = next.split(".");
  if (!/^\d+(?:\.\d*)?$/.test(next)
      || integer.length > normalizedMaxLength(maxIntegerLength)
      || decimals.length > normalizedDecimalPlaces(decimalPlaces)) return current;
  return next.replace(/^0+(?=\d)/, "");
}

export function applyDigitsKeypadKey(buffer, key, maxLength = DEFAULT_MAX_LENGTH) {
  const current = String(buffer ?? "");
  if (key === "clear") return "";
  if (key === "backspace") return current.slice(0, -1);
  if (!/^\d{1,2}$/.test(String(key))) return current;
  const next = `${current}${key}`;
  return /^\d+$/.test(next) && next.length <= normalizedMaxLength(maxLength) ? next : current;
}

function validateStep(value, options, valueName) {
  if (options.step === undefined || options.step === "any") return "";
  const step = Number(options.step);
  if (!Number.isFinite(step) || step <= 0) return "";
  const base = options.min ?? options.stepBase ?? 0;
  // Compare decimal integers exactly: large prices must not fail because of
  // floating-point division (for example 9999999999.9999 in steps of 0.0001).
  const parts = [value, Number.isFinite(Number(base)) ? base : 0, options.step].map(number => {
    const [mantissa, exponent = "0"] = String(number).toLowerCase().split("e");
    return { digits: BigInt(mantissa.replace(".", "")), places: (mantissa.split(".")[1] || "").length - Number(exponent) };
  });
  const precision = Math.max(0, ...parts.map(part => part.places));
  const [amount, origin, increment] = parts.map(part => part.digits * 10n ** BigInt(precision - part.places));
  return (amount - origin) % increment === 0n
    ? ""
    : `El valor de ${valueName} debe respetar incrementos de ${step}.`;
}

export function validateIntegerKeypadBuffer(buffer, options = {}) {
  const { required = true } = options;
  const value = String(buffer ?? "").trim();
  const valueName = String(options.valueName || "cantidad").trim();
  const valueArticle = String(options.valueArticle || "una").trim();

  if (!value) return required ? `Ingresa ${valueArticle} ${valueName}.` : "";

  if (!/^\d+$/.test(value)) return `Ingresa ${valueArticle} ${valueName} válida.`;
  if (options.maxLength !== undefined && value.length > normalizedMaxLength(options.maxLength)) {
    return `El valor admite hasta ${normalizedMaxLength(options.maxLength)} dígitos.`;
  }
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

  return validateStep(value, options, valueName);
}

export function validateDecimalKeypadBuffer(buffer, options = {}) {
  const { required = true } = options;
  const decimalPlaces = normalizedDecimalPlaces(options.decimalPlaces);
  const normalized = String(buffer ?? "").trim().replace(",", ".");
  const raw = normalized.endsWith(".") ? normalized.slice(0, -1) : normalized;
  const valueName = String(options.valueName || "valor").trim();
  const valueArticle = String(options.valueArticle || "un").trim();
  const pattern = decimalPlaces > 0
    ? new RegExp(`^\\d+(?:\\.\\d{1,${decimalPlaces}})?$`)
    : /^\d+$/;

  if (!raw) return required ? `Ingresa ${valueArticle} ${valueName}.` : "";
  if (!pattern.test(raw)) return `Ingresa ${valueArticle} ${valueName} válido con hasta ${decimalPlaces} decimales.`;

  if (options.maxLength !== undefined && raw.split(".")[0].length > normalizedMaxLength(options.maxLength)) {
    return `El valor admite hasta ${normalizedMaxLength(options.maxLength)} dígitos enteros.`;
  }
  const numeric = Number(raw);
  if (!Number.isFinite(numeric)) return `Ingresa ${valueArticle} ${valueName} válido.`;

  const minimum = Number(options.min);
  if (options.min !== undefined && Number.isFinite(minimum) && numeric < minimum) {
    return `El ${valueName} mínimo es ${minimum}.`;
  }

  const maximum = Number(options.max);
  if (options.max !== undefined && Number.isFinite(maximum) && numeric > maximum) {
    return `El ${valueName} máximo es ${maximum}.`;
  }

  return validateStep(raw, options, valueName);
}

// A detached editable clone validates the same constraints without ever enabling
// the operating-system keyboard on the focused, read-only touch field.
export function validateKeypadInputValue(input, value) {
  const raw = String(value ?? "");
  if (input.required && !raw) return "Completa este campo.";
  const maxLength = Number(input.getAttribute("maxlength"));
  if (input.hasAttribute("maxlength") && maxLength >= 0 && raw.length > maxLength) {
    return `Escribe hasta ${maxLength} caracteres.`;
  }
  const minLength = Number(input.getAttribute("minlength"));
  if (raw && input.hasAttribute("minlength") && minLength > 0 && raw.length < minLength) {
    return `Escribe al menos ${minLength} caracteres.`;
  }
  if (typeof input.cloneNode === "function") {
    const probe = input.cloneNode(false);
    probe.readOnly = false;
    probe.disabled = false;
    probe.value = raw;
    if (probe.value !== raw) return "Ingresa un valor válido.";
    if (typeof probe.checkValidity === "function" && !probe.checkValidity()) {
      return probe.validationMessage || "Revisa el formato y los límites de este campo.";
    }
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
    hintOutput,
    showDialog,
    hideDialog
  } = options;

  const inputOptions = Array.isArray(options.inputs)
    ? options.inputs
    : [{
        input: options.input,
        maxLength: options.maxLength,
        onCommit: options.onCommit,
        valueName: options.valueName,
        valueArticle: options.valueArticle
      }];
  const bindings = new WeakMap();

  if (!dialog || !valueOutput || !messageOutput || !clearButton || !confirmButton) {
    throw new TypeError("El teclado numérico necesita todos sus controles.");
  }

  const keyButtons = [...dialog.querySelectorAll("[data-pdd-keypad-key]")];
  const doubleZeroButton = keyButtons.find((button) => button.dataset.pddKeypadKey === "00");
  let activeBinding = null;
  let defaultInputReference;
  let returnFocus = null;
  let buffer = "";
  let replaceOnNextDigit = false;

  function bindingMaxLength(binding = activeBinding) {
    const inputLength = binding?.input.getAttribute("maxlength");
    return normalizedMaxLength(binding?.maxLength ?? (inputLength || undefined) ?? options.maxLength);
  }

  function bindingMode(binding = activeBinding) {
    return ["decimal", "digits"].includes(binding?.mode) ? binding.mode : "integer";
  }

  function bindingDecimalPlaces(binding = activeBinding) {
    return normalizedDecimalPlaces(binding?.decimalPlaces ?? options.decimalPlaces);
  }

  function fieldLabel(binding = activeBinding) {
    const input = binding?.input;
    return String(input?.dataset.pddKeypadLabel || binding?.label || "Valor").trim();
  }

  function refreshLabel(input = activeBinding?.input) {
    const binding = input && bindings.get(input);
    if (!binding) return;
    const currentValue = String(input.value || "Sin valor");
    input.setAttribute("aria-label", `${fieldLabel(binding)}: ${currentValue}. Presiona para cambiarla con el teclado táctil.`);
  }

  function renderDialogCopy(binding = activeBinding) {
    const input = binding.input;
    if (titleOutput) titleOutput.textContent = fieldLabel(binding);
    if (valueLabelOutput) valueLabelOutput.textContent = input.dataset.pddKeypadValueLabel || "Valor seleccionado";
    if (hintOutput) {
      hintOutput.textContent = input.dataset.pddKeypadHint || "";
      hintOutput.hidden = !hintOutput.textContent;
    }
    confirmButton.textContent = input.dataset.pddKeypadConfirmLabel || "Usar valor";
    if (doubleZeroButton) {
      const decimal = bindingMode(binding) === "decimal";
      doubleZeroButton.textContent = decimal ? "." : "00";
      doubleZeroButton.setAttribute("aria-label", decimal ? "Separador decimal" : "Doble cero");
    }
  }

  function render(message = "") {
    valueOutput.textContent = buffer || "—";
    messageOutput.textContent = message;
  }

  function open(input = defaultInputReference?.deref()) {
    const nextBinding = input && bindings.get(input);
    if (!nextBinding || input.disabled || input.matches?.(":disabled")) return;
    if (activeBinding?.input !== input) {
      activeBinding?.input.setAttribute("aria-expanded", "false");
      const previouslyFocused = input.ownerDocument?.activeElement;
      returnFocus = input.hidden ? previouslyFocused : input;
    }
    activeBinding = nextBinding;
    // Preserve existing excess digits and precision so confirmation can reject them.
    buffer = String(input.value ?? "").trim().replaceAll(",", ".");
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
    restoreFocus();
  }

  function restoreFocus() {
    const input = returnFocus;
    activeBinding?.input.setAttribute("aria-expanded", "false");
    activeBinding = null;
    returnFocus = null;
    const parentDialog = input?.closest?.("dialog");
    if (input && input.isConnected !== false && !input.disabled && !input.matches?.(":disabled") && !input.hidden && (!parentDialog || parentDialog.open)) {
      input.focus?.({ preventScroll: true });
    }
  }

  function applyKey(key) {
    if (!activeBinding) return;
    const decimal = bindingMode() === "decimal";
    const effectiveKey = decimal && key === "00" ? "." : key;
    if ((/^\d{1,2}$/.test(String(effectiveKey)) || (decimal && effectiveKey === ".")) && replaceOnNextDigit) {
      buffer = "";
    }
    replaceOnNextDigit = false;
    buffer = decimal
      ? applyDecimalKeypadKey(buffer, effectiveKey, bindingMaxLength(), bindingDecimalPlaces())
      : bindingMode() === "digits"
        ? applyDigitsKeypadKey(buffer, effectiveKey, bindingMaxLength())
        : applyIntegerKeypadKey(buffer, effectiveKey, bindingMaxLength());
    render();
  }

  function confirm() {
    if (!activeBinding) return false;
    const input = activeBinding.input;
    if (input.disabled || input.matches?.(":disabled") || input.isConnected === false) {
      close();
      return false;
    }
    const mode = bindingMode();
    const maxLength = bindingMaxLength();
    const validationOptions = {
      required: input.required,
      min: input.hasAttribute("min") ? input.min : undefined,
      max: input.hasAttribute("max") ? input.max : undefined,
      step: input.hasAttribute("step") ? input.step : (input.type === "number" ? "1" : undefined),
      stepBase: input.getAttribute("value") || 0,
      maxLength,
      decimalPlaces: bindingDecimalPlaces(),
      valueName: activeBinding.valueName || input.dataset.pddKeypadValueName,
      valueArticle: activeBinding.valueArticle || input.dataset.pddKeypadValueArticle
    };
    let validationMessage = "";
    if (mode === "digits") {
      if (buffer && !/^\d+$/.test(buffer)) validationMessage = "Escribe solamente números.";
      if (buffer.length > maxLength) validationMessage = `Escribe hasta ${maxLength} dígitos.`;
    } else {
      validationMessage = mode === "decimal"
        ? validateDecimalKeypadBuffer(buffer, validationOptions)
        : validateIntegerKeypadBuffer(buffer, validationOptions);
    }
    const nextValue = !buffer || mode === "digits"
      ? buffer
      : mode === "decimal"
        ? Number(buffer.replace(/\.$/, "")).toFixed(bindingDecimalPlaces())
        : String(Number(buffer));
    validationMessage ||= validateKeypadInputValue(input, nextValue);
    if (validationMessage) {
      render(validationMessage);
      return false;
    }

    const onCommit = activeBinding.onCommit || options.onCommit;
    input.value = nextValue;
    refreshLabel();
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    if (typeof onCommit === "function") onCommit(mode === "digits" || !nextValue ? nextValue : Number(nextValue), input);
    close();
    return true;
  }

  function has(input) {
    return Boolean(input && bindings.has(input));
  }

  function register(entry) {
    const incoming = entry?.input ? entry : { input: entry };
    const input = incoming.input;
    if (!input) return false;
    const previous = bindings.get(input);
    const binding = {
      ...previous,
      ...incoming,
      label: incoming.label || previous?.label || input.dataset.pddKeypadLabel || input.getAttribute("aria-label") || "Valor"
    };
    bindings.set(input, binding);
    if (activeBinding?.input === input) activeBinding = binding;
    if (!defaultInputReference?.deref()) defaultInputReference = new WeakRef(input);
    input.dataset.pddKeyboard = binding.mode === "digits" ? "digits" : "numeric";
    input.readOnly = true;
    input.setAttribute("inputmode", "none");
    input.setAttribute("role", "button");
    input.setAttribute("aria-haspopup", "dialog");
    input.setAttribute("aria-controls", dialog.id);
    if (!previous) input.setAttribute("aria-expanded", "false");
    refreshLabel(input);
    if (!previous) {
      input.addEventListener("click", () => open(input));
      input.addEventListener("keydown", (event) => {
        if (event.key !== "Enter" && event.key !== " ") return;
        event.preventDefault();
        open(input);
      });
    }
    return true;
  }

  inputOptions.forEach(register);

  keyButtons.forEach((button) => {
    button.addEventListener("click", () => applyKey(button.dataset.pddKeypadKey));
  });
  clearButton.addEventListener("click", () => applyKey("clear"));
  confirmButton.addEventListener("click", confirm);
  dialog.querySelectorAll("[data-pdd-close]").forEach((button) => {
    if (!button.dataset.pddClose || button.dataset.pddClose === dialog.id) {
      button.addEventListener("click", close);
    }
  });
  dialog.addEventListener("click", (event) => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
  });
  dialog.addEventListener("cancel", (event) => {
    event.preventDefault();
    event.stopPropagation();
    close();
  });
  dialog.addEventListener("close", () => {
    if (!dialog.open) restoreFocus();
  });
  dialog.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }
    if (/^\d$/.test(event.key)) {
      event.preventDefault();
      applyKey(event.key);
      return;
    }
    if ((event.key === "." || event.key === ",") && bindingMode() === "decimal") {
      event.preventDefault();
      applyKey(".");
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

  return { open, close, confirm, refreshLabel, register, has,
    get activeInput() { return activeBinding?.input || null; }
  };
}
