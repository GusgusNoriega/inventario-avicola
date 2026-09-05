const LETTER_ROWS = ["qwertyuiop", "asdfghjklñ", "zxcvbnm,.", "áéíóúü@-_"];
const SYMBOL_ROWS = ["1234567890", "!@#$%&*()¿?", "+-=/_:;¡€$", ['"', "'", "[", "]", "{", "}", "\\", "|", "<", ">"]];

function textLimit(maxLength) {
  return maxLength !== null && maxLength !== undefined && Number.isInteger(Number(maxLength)) && Number(maxLength) >= 0
    ? Number(maxLength)
    : Infinity;
}

function previousPosition(value, position) {
  return Math.max(0, position - (Array.from(value.slice(0, position)).at(-1)?.length || 1));
}

function nextPosition(value, position) {
  return Math.min(value.length, position + (Array.from(value.slice(position))[0]?.length || 1));
}

/** Edit a text selection using the same UTF-16 positions and maxlength as native fields. */
export function applyTextKeyboardKey(value, selectionStart, selectionEnd, key, options = {}) {
  const current = String(value ?? "");
  let start = Math.max(0, Math.min(current.length, Number(selectionStart) || 0));
  let end = Math.max(start, Math.min(current.length, Number(selectionEnd) || 0));
  const result = (text, from, to = from) => ({ value: text, selectionStart: from, selectionEnd: to });
  const command = options.literal ? null : key;

  if (command === "select-all") return result(current, 0, current.length);
  if (command === "home") return result(current, 0);
  if (command === "end") return result(current, current.length);
  if (command === "left") return result(current, start === end ? previousPosition(current, start) : start);
  if (command === "right") return result(current, start === end ? nextPosition(current, end) : end);
  if (command === "clear") return result("", 0);

  let insertion;
  if (command === "backspace") {
    if (start === end) start = previousPosition(current, start);
    insertion = "";
  } else if (command === "delete") {
    if (start === end) end = nextPosition(current, end);
    insertion = "";
  } else {
    insertion = command === "space" ? " " : command === "newline" ? "\n" : String(key ?? "");
    insertion = insertion.replace(/\r\n?/g, "\n");
    if (!options.multiline) insertion = insertion.replace(/\n/g, "");
    if (!insertion) return result(current, start, end);
    const available = Math.max(0, textLimit(options.maxLength) - (current.length - (end - start)));
    insertion = insertion.slice(0, available);
    // Do not introduce half an emoji when a maxlength ends in a surrogate pair.
    if (/[\uD800-\uDBFF]$/.test(insertion)) insertion = insertion.slice(0, -1);
    if (!insertion) return result(current, start, end);
  }

  return result(current.slice(0, start) + insertion + current.slice(end), start + insertion.length);
}

export function validateTextKeyboardValue(input, value) {
  const text = String(value ?? "");
  const maximum = textLimit(input.maxLength);
  if (text.length > maximum) return `Usa como máximo ${maximum} caracteres.`;
  if (text && input.minLength > 0 && text.length < input.minLength) {
    return `Ingresa al menos ${input.minLength} caracteres.`;
  }
  if (input.validity?.customError && input.validationMessage) return input.validationMessage;
  const validationInput = input.cloneNode(false);
  validationInput.readOnly = false;
  validationInput.disabled = false;
  validationInput.value = text;
  return validationInput.checkValidity() ? "" : validationInput.validationMessage;
}

/** Shared modal controller. The module coordinator owns field event bindings. */
export function bindTextKeyboard({ dialog } = {}) {
  const valueOutput = dialog?.querySelector("[data-pdk-text-value]");
  const titleOutput = dialog?.querySelector("[data-pdk-text-title]");
  const messageOutput = dialog?.querySelector("[data-pdk-text-message]");
  const keysOutput = dialog?.querySelector("[data-pdk-text-keys]");
  if (!dialog || !valueOutput || !titleOutput || !messageOutput || !keysOutput) {
    throw new TypeError("El teclado de texto necesita todos sus controles.");
  }

  const document = dialog.ownerDocument;
  const window = document.defaultView;
  let activeInput = null;
  let parentDialog = null;
  let initialValue = "";
  let buffer = "";
  let selectionStart = 0;
  let selectionEnd = 0;
  let uppercase = false;
  let symbols = false;
  let observer = null;

  const isMultiline = () => activeInput?.tagName?.toLowerCase() === "textarea";
  const isPassword = () => activeInput?.type === "password";
  const isAvailable = input => Boolean(input?.isConnected && !input.disabled && !input.matches(":disabled"));
  const makeEvent = type => new window.Event(type, { bubbles: true });
  const focusOutput = () => valueOutput.focus({ preventScroll: true });

  function render(message = "") {
    messageOutput.textContent = message;
    const visible = isPassword() ? "•".repeat(buffer.length) : buffer;
    const before = document.createTextNode(visible.slice(0, selectionStart));
    const selection = document.createElement("span");
    selection.className = "pdk-text-selection";
    selection.textContent = visible.slice(selectionStart, selectionEnd);
    const caret = document.createElement("span");
    caret.className = "pdk-text-caret";
    caret.setAttribute("aria-hidden", "true");
    caret.textContent = "\u200b";
    const after = document.createTextNode(visible.slice(selectionEnd));
    valueOutput.replaceChildren(before, selection, caret, after);
    valueOutput.classList.toggle("is-empty", !buffer);
    valueOutput.setAttribute("aria-multiline", String(isMultiline()));
    caret.scrollIntoView?.({ block: "nearest", inline: "nearest" });
  }

  function keyButton(label, key, action = false, className = "") {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `pdk-key ${className}`.trim();
    button.textContent = label;
    button.dataset[action ? "pdkTextAction" : "pdkTextKey"] = key;
    return button;
  }

  function renderKeys() {
    const rows = (symbols ? SYMBOL_ROWS : LETTER_ROWS).map(keys => {
      const row = document.createElement("div");
      row.className = "pdk-text-row";
      Array.from(keys).forEach(key => {
        const value = uppercase && !symbols ? key.toLocaleUpperCase("es") : key;
        row.append(keyButton(value, value));
      });
      return row;
    });
    const controls = document.createElement("div");
    controls.className = "pdk-text-row is-controls";
    const shift = keyButton("Mayús", "shift", true, uppercase ? "is-active" : "");
    shift.setAttribute("aria-pressed", String(uppercase));
    shift.disabled = symbols;
    controls.append(
      keyButton(symbols ? "ABC" : "123 / símbolos", "layout", true, "is-layout"),
      shift,
      keyButton("Espacio", "space", true, "is-space"),
      keyButton("⌫ Borrar", "backspace", true, "is-backspace")
    );
    if (isMultiline()) controls.append(keyButton("↵ Salto", "newline", true));
    keysOutput.replaceChildren(...rows, controls);
    keysOutput.setAttribute("aria-label", symbols ? "Números y símbolos" : "Letras del teclado español");
  }

  function updateTarget() {
    if (!activeInput) return;
    const input = activeInput;
    if (input.value !== buffer) {
      input.value = buffer;
      buffer = input.value;
      selectionStart = Math.min(selectionStart, buffer.length);
      selectionEnd = Math.min(selectionEnd, buffer.length);
      input.dispatchEvent(makeEvent("input"));
      buffer = input.value;
      selectionStart = Math.min(selectionStart, buffer.length);
      selectionEnd = Math.min(selectionEnd, buffer.length);
    }
    try { input.setSelectionRange(selectionStart, selectionEnd); } catch { /* Email has no selection API. */ }
  }

  function applyKey(key, literal = false) {
    if (!isAvailable(activeInput)) return close();
    const next = applyTextKeyboardKey(buffer, selectionStart, selectionEnd, key, {
      maxLength: activeInput.maxLength,
      multiline: isMultiline(),
      literal
    });
    buffer = next.value;
    selectionStart = next.selectionStart;
    selectionEnd = next.selectionEnd;
    updateTarget();
    if (!activeInput) return;
    render();
    focusOutput();
  }

  function finish(commit) {
    const input = activeInput;
    if (!input) return false;
    activeInput = null;
    observer?.disconnect();
    observer = null;
    parentDialog?.removeEventListener("close", onParentClose);
    const canRestoreFocus = isAvailable(input) && (!parentDialog || parentDialog.open);
    parentDialog = null;
    input.setAttribute("aria-expanded", "false");
    if (!commit && input.value !== initialValue) {
      input.value = initialValue;
      input.dispatchEvent(makeEvent("input"));
    }
    if (dialog.open) dialog.close();
    if (commit && input.value !== initialValue) input.dispatchEvent(makeEvent("change"));
    if (canRestoreFocus && input.isConnected && !dialog.open) input.focus({ preventScroll: true });
    return true;
  }

  function confirm() {
    if (!isAvailable(activeInput)) return close();
    const message = validateTextKeyboardValue(activeInput, buffer);
    if (message) {
      render(message);
      focusOutput();
      return false;
    }
    return finish(true);
  }

  function close(commit = false) {
    return commit ? confirm() : finish(false);
  }

  function onParentClose() { close(); }

  function open(input) {
    if (!isAvailable(input) || !input.matches("textarea, input:not([type]), input[type=text], input[type=search], input[type=email], input[type=url], input[type=tel], input[type=password]")) return false;
    if (activeInput === input && dialog.open) { focusOutput(); return true; }
    if (activeInput) close();
    activeInput = input;
    parentDialog = input.closest("dialog");
    if (parentDialog && !parentDialog.open) { activeInput = null; parentDialog = null; return false; }
    initialValue = buffer = String(input.value || "");
    selectionStart = input.selectionStart ?? buffer.length;
    selectionEnd = input.selectionEnd ?? buffer.length;
    uppercase = false;
    symbols = false;
    input.readOnly = true;
    input.setAttribute("inputmode", "none");
    input.setAttribute("aria-expanded", "true");
    titleOutput.textContent = String(input.dataset.pdkLabel || input.dataset.pddKeypadLabel || input.labels?.[0]?.textContent || input.getAttribute("aria-label") || input.placeholder || "Ingresar texto").trim();
    renderKeys();
    render();
    parentDialog?.addEventListener("close", onParentClose);
    if (!dialog.open) dialog.showModal();
    focusOutput();
    if (window.MutationObserver) {
      observer = new window.MutationObserver(() => {
        if (activeInput && (!isAvailable(activeInput) || !dialog.isConnected || (parentDialog && !parentDialog.open))) close();
      });
      observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ["open", "disabled"] });
    }
    return true;
  }

  function onClick(event) {
    const button = event.target.closest("[data-pdk-text-key], [data-pdk-text-action]");
    if (!button || !dialog.contains(button)) return;
    const action = button.dataset.pdkTextAction;
    if (!action) return applyKey(button.dataset.pdkTextKey);
    if (action === "cancel") return close();
    if (action === "accept") return confirm();
    if (action === "shift" || action === "layout") {
      if (action === "shift") uppercase = !uppercase;
      else symbols = !symbols;
      renderKeys();
      focusOutput();
      return;
    }
    applyKey(action);
  }

  function onKeydown(event) {
    if (!activeInput) return;
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }
    const shortcut = event.metaKey || (event.ctrlKey && !event.altKey);
    if (shortcut) {
      if (event.key.toLowerCase() === "a") {
        event.preventDefault();
        event.stopPropagation();
        applyKey("select-all");
      }
      return;
    }
    if (event.altKey && !event.ctrlKey) return;
    const keys = { Backspace: "backspace", Delete: "delete", ArrowLeft: "left", ArrowRight: "right", Home: "home", End: "end" };
    if (event.key === "Enter") {
      if (event.target.closest("button")) return;
      event.preventDefault();
      event.stopPropagation();
      if (isMultiline()) applyKey("newline");
      else confirm();
    } else if (keys[event.key] || Array.from(event.key).length === 1) {
      if (event.key === " " && event.target.closest("button")) return;
      event.preventDefault();
      event.stopPropagation();
      applyKey(keys[event.key] || event.key);
    }
  }

  function onCancel(event) {
    event.preventDefault();
    event.stopPropagation();
    close();
  }

  function onClose() { if (activeInput && !dialog.open) finish(false); }

  function onCopy(event) {
    if (!activeInput || isPassword() || selectionStart === selectionEnd || !event.clipboardData) return;
    event.clipboardData.setData("text/plain", buffer.slice(selectionStart, selectionEnd));
    event.preventDefault();
    if (event.type === "cut") applyKey("backspace");
  }

  function onPaste(event) {
    if (!activeInput || !event.clipboardData) return;
    const text = event.clipboardData.getData("text/plain");
    event.preventDefault();
    // Pasted words such as "clear" must remain literal text, never keyboard commands.
    applyKey(text, true);
  }

  const listeners = { click: onClick, keydown: onKeydown, cancel: onCancel, close: onClose, copy: onCopy, cut: onCopy, paste: onPaste };
  Object.entries(listeners).forEach(([type, listener]) => dialog.addEventListener(type, listener));

  return {
    open,
    close,
    confirm,
    get activeInput() { return activeInput; },
    destroy() {
      close();
      Object.entries(listeners).forEach(([type, listener]) => dialog.removeEventListener(type, listener));
    }
  };
}
