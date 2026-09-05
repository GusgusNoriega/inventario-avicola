import { bindIntegerKeypad } from "./despacho-productos-numeric-keypad.js";
import { bindTextKeyboard } from "./despacho-productos-text-keyboard.js";

const textTypes = new Set(["text", "search", "email", "tel", "url", "password"]);
const dateTypes = new Set(["date", "datetime-local", "time", "month", "week"]);
const instanceKey = Symbol.for("avicola.productDispatchKeyboards");

export function keyboardModeFor(input) {
  if (!input || input.dataset?.pddKeyboard === "off") return null;
  const configured = input.dataset?.pddKeyboard;
  if (["numeric", "digits", "text"].includes(configured)) return configured;
  if (input.readOnly) return null;
  if (input.type === "number") return "numeric";
  if (input.tagName === "TEXTAREA") return "text";
  if (textTypes.has(input.type)) {
    return input.getAttribute("inputmode") === "numeric" ? "digits" : "text";
  }
  return null;
}

export function numericOptionsFor(input) {
  if (keyboardModeFor(input) === "digits") {
    return { input, mode: "digits", maxLength: input.maxLength >= 0 ? input.maxLength : 32 };
  }
  const step = input.getAttribute("step") || "1";
  const [coefficient, exponent = "0"] = step.toLowerCase().split("e");
  const decimalPlaces = step === "any"
    ? 10
    : Math.max(0, (coefficient.split(".")[1] || "").length - Number(exponent));
  const maximum = input.getAttribute("max");
  const maxLength = maximum && Number.isFinite(Number(maximum))
    ? Math.max(1, Math.trunc(Math.abs(Number(maximum))).toString().length)
    : 15;
  return { input, mode: decimalPlaces ? "decimal" : "integer", decimalPlaces, maxLength };
}

export function keyboardFieldLabel(input) {
  const label = input.dataset.pddKeypadLabel || input.getAttribute("aria-label")
    || Array.from(input.labels || []).map(element => element.textContent.trim()).join(" ")
    || input.placeholder || "Ingresar valor";
  return label.replace(/\s+/g, " ").replace(/\s*\*\s*$/, "").trim();
}

// readonly prevents the operating system keyboard, but excludes native constraints.
// A detached editable copy checks the same constraints without focusing an editor.
export function keyboardValidationMessage(input) {
  if (input.matches(":disabled")) return "";
  if (input.validity?.customError && input.validationMessage) return input.validationMessage;
  const value = input.value;
  if (input.maxLength >= 0 && value.length > input.maxLength) return `Usa como máximo ${input.maxLength} caracteres.`;
  if (value && input.minLength > 0 && value.length < input.minLength) return `Escribe al menos ${input.minLength} caracteres.`;
  const editable = input.cloneNode(false);
  editable.readOnly = false;
  editable.disabled = false;
  editable.value = value;
  return editable.validationMessage || "";
}

export function initializeProductDispatchKeyboards() {
  if (document[instanceKey]) return document[instanceKey];
  const numericDialog = document.getElementById("pddNumericKeypad");
  const textDialog = document.getElementById("pddTextKeyboard");
  if (!numericDialog || !textDialog) return null;
  const numeric = bindIntegerKeypad({
    inputs: [],
    dialog: numericDialog,
    titleOutput: document.getElementById("pddNumericKeypadTitle"),
    valueLabelOutput: document.getElementById("pddNumericKeypadValueLabel"),
    hintOutput: document.getElementById("pddNumericKeypadHint"),
    valueOutput: document.getElementById("pddNumericKeypadValue"),
    messageOutput: document.getElementById("pddNumericKeypadMessage"),
    clearButton: document.getElementById("pddNumericKeypadClear"),
    confirmButton: document.getElementById("pddNumericKeypadConfirm"),
  });
  const text = bindTextKeyboard({ dialog: textDialog });

  function prepare(input) {
    if (dateTypes.has(input.type)) {
      if (input.inputMode !== "none") input.inputMode = "none";
      if (input.getAttribute("virtualkeyboardpolicy") !== "manual") input.setAttribute("virtualkeyboardpolicy", "manual");
      return;
    }
    if (input.closest(".pdk-dialog")) return;
    const mode = keyboardModeFor(input);
    if (!mode) return;
    if (input.dataset.pddKeyboard !== mode) input.dataset.pddKeyboard = mode;
    if (!input.dataset.pddKeypadLabel) input.dataset.pddKeypadLabel = keyboardFieldLabel(input);
    if (!input.readOnly) input.readOnly = true;
    if (input.inputMode !== "none") input.inputMode = "none";
    if (input.getAttribute("virtualkeyboardpolicy") !== "manual") input.setAttribute("virtualkeyboardpolicy", "manual");
    if (mode !== "text") {
      if (!numeric.has(input)) numeric.register(numericOptionsFor(input));
    } else {
      input.setAttribute("aria-haspopup", "dialog");
      input.setAttribute("aria-controls", textDialog.id);
    }
  }

  function scan(root) {
    if (root.matches?.("input, textarea")) prepare(root);
    root.querySelectorAll?.("input, textarea").forEach(prepare);
  }

  function open(input) {
    if (!input || input.matches(":disabled") || input.hidden) return;
    prepare(input);
    const mode = keyboardModeFor(input);
    if (mode === "text") text.open(input);
    else if (mode === "numeric" || mode === "digits") numeric.open(input);
  }

  function closeOrphanedKeyboard(controller) {
    const target = controller.activeInput;
    if (!target) return;
    const parentDialog = target.closest("dialog");
    if (!target.isConnected || target.matches(":disabled") || (parentDialog && !parentDialog.open)) controller.close();
  }

  scan(document);
  const observer = new MutationObserver(records => {
    for (const record of records) {
      if (record.type === "attributes") {
        if (record.target.matches("input, textarea")) prepare(record.target);
      }
      else record.addedNodes.forEach(scan);
    }
    closeOrphanedKeyboard(numeric);
    closeOrphanedKeyboard(text);
  });
  observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["type", "readonly", "inputmode", "disabled", "open"] });
  document.addEventListener("close", () => {
    closeOrphanedKeyboard(numeric);
    closeOrphanedKeyboard(text);
  }, true);

  // Prepare before focus, including fields inserted and immediately focused by a view.
  document.addEventListener("pointerdown", event => {
    if (event.target.matches?.("input, textarea")) prepare(event.target);
  }, true);
  document.addEventListener("focusin", event => {
    if (event.target.matches?.("input, textarea")) prepare(event.target);
  }, true);
  document.addEventListener("click", event => {
    if (keyboardModeFor(event.target) === "text") open(event.target);
  });
  document.addEventListener("keydown", event => {
    if (!event.target.matches?.("input, textarea") || event.ctrlKey || event.metaKey || event.altKey) return;
    const mode = keyboardModeFor(event.target);
    if (!mode) return;
    const activation = event.key === "Enter" || event.key === " ";
    const character = event.key.length === 1;
    if (!activation && !character) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    open(event.target);
    if (!activation) {
      const dialog = mode === "text" ? textDialog : numericDialog;
      dialog.dispatchEvent(new KeyboardEvent("keydown", { key: event.key, bubbles: true, cancelable: true }));
    }
  }, true);

  document.addEventListener("submit", event => {
    const invalid = Array.from(event.target.elements || []).find(input =>
      input.dataset?.pddKeyboard && keyboardValidationMessage(input));
    if (!invalid) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    open(invalid);
    const dialog = keyboardModeFor(invalid) === "text" ? textDialog : numericDialog;
    const message = dialog.querySelector(".pdk-message");
    if (message) message.textContent = keyboardValidationMessage(invalid);
  }, true);

  document[instanceKey] = { numeric, text, open, scan };
  return document[instanceKey];
}

export function bindProductDispatchNumericKeypad({ inputs = [] } = {}) {
  const { numeric } = initializeProductDispatchKeyboards();
  inputs.forEach(input => numeric.register(input));
  return numeric;
}

if (typeof document !== "undefined") initializeProductDispatchKeyboards();
