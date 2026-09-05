import assert from "node:assert/strict";
import test from "node:test";
import {
  applyDecimalKeypadKey,
  applyDigitsKeypadKey,
  applyIntegerKeypadKey,
  bindIntegerKeypad,
  validateDecimalKeypadBuffer,
  validateIntegerKeypadBuffer,
  validateKeypadInputValue
} from "../../public/js/despacho-productos-numeric-keypad.js";

class Control extends EventTarget {
  constructor(attributes = {}) {
    super();
    this.attributes = new Map();
    this.dataset = {};
    this.value = "";
    this.textContent = "";
    this.type = "text";
    this.required = false;
    this.disabled = false;
    this.readOnly = false;
    this.hidden = false;
    this.isConnected = true;
    this.ownerDocument = { activeElement: null };
    this.focusCount = 0;
    for (const [key, value] of Object.entries(attributes)) this.setAttribute(key, value);
  }

  setAttribute(key, value) {
    this.attributes.set(key, String(value));
    if (["type", "min", "max", "step", "value", "id"].includes(key)) this[key] = String(value);
    if (key === "required") this.required = true;
  }

  getAttribute(key) { return this.attributes.get(key) ?? null; }
  hasAttribute(key) { return this.attributes.has(key); }
  matches(selector) { return selector === ":disabled" && (this.disabled || this.fieldsetDisabled); }
  closest() { return null; }
  focus() {
    this.focusCount += 1;
    this.ownerDocument.activeElement = this;
    this.dispatchEvent(new Event("focusin"));
  }

  cloneNode() {
    const clone = new Control(Object.fromEntries(this.attributes));
    clone.readOnly = this.readOnly;
    clone.disabled = this.disabled;
    clone.nativeValidation = this.nativeValidation;
    this.lastClone = clone;
    return clone;
  }

  checkValidity() {
    return this.nativeValidation ? this.nativeValidation(this) : true;
  }
}

function emit(target, name, properties = {}) {
  const event = new Event(name, { cancelable: true });
  Object.assign(event, properties);
  target.dispatchEvent(event);
  return event;
}

function fixture(inputs = []) {
  const dialog = new Control({ id: "numeric" });
  dialog.open = false;
  dialog.showCount = 0;
  dialog.showModal = () => { dialog.open = true; dialog.showCount += 1; };
  dialog.close = () => { dialog.open = false; emit(dialog, "close"); };
  dialog.getBoundingClientRect = () => ({ left: 100, right: 500, top: 100, bottom: 600 });
  const keys = new Map(["0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "00", "backspace"].map((key) => {
    const button = new Control();
    button.dataset.pddKeypadKey = key;
    return [key, button];
  }));
  const cancel = new Control();
  cancel.dataset.pddClose = "numeric";
  dialog.querySelectorAll = (selector) => selector === "[data-pdd-close]" ? [cancel] : [...keys.values()];
  const controls = {
    inputs,
    dialog,
    valueOutput: new Control(),
    messageOutput: new Control(),
    clearButton: new Control(),
    confirmButton: new Control(),
    titleOutput: new Control()
  };
  const keypad = bindIntegerKeypad(controls);
  return { ...controls, keypad, keys, cancel, key: (value) => emit(dialog, "keydown", { key: value }) };
}

test("se crea vacío y registra o actualiza campos dinámicos sin duplicar apertura", () => {
  const f = fixture();
  const input = new Control({ value: "12", "aria-label": "Cantidad" });
  assert.equal(f.keypad.has(input), false);
  f.keypad.register({ input, maxLength: 8 });
  f.keypad.register({ input, maxLength: 9 });
  assert.equal(f.keypad.has(input), true);
  assert.equal(input.readOnly, true);
  assert.equal(input.getAttribute("inputmode"), "none");
  assert.equal(input.dataset.pddKeyboard, "numeric");
  emit(input, "click");
  assert.equal(f.dialog.showCount, 1);
  assert.equal(f.keypad.activeInput, input);
  f.keypad.close();
  assert.equal(f.keypad.activeInput, null);
  assert.equal(f.dialog.open, false);
  assert.equal(input.focusCount, 1);
});

test("refrescar la etiqueta conserva su nombre y no acumula instrucciones", () => {
  const input = new Control({ value: "5", "aria-label": "Cantidad" });
  const f = fixture([input]);
  for (let i = 0; i < 5; i += 1) f.keypad.refreshLabel(input);
  assert.equal(input.getAttribute("aria-label"), "Cantidad: 5. Presiona para cambiarla con el teclado táctil.");
  f.keypad.open(input);
  assert.equal(f.titleOutput.textContent, "Cantidad");
});

test("confirmar un número opcional vacío mantiene vacío y emite los eventos esperados", () => {
  const input = new Control({ type: "number", value: "52", min: "1" });
  const values = [];
  const events = [];
  input.addEventListener("input", () => events.push("input"));
  input.addEventListener("change", () => events.push("change"));
  const f = fixture([{ input, onCommit: (value) => values.push(value) }]);
  f.keypad.open(input);
  emit(f.clearButton, "click");
  assert.equal(f.keypad.confirm(), true);
  assert.equal(input.value, "");
  assert.deepEqual(values, [""]);
  assert.deepEqual(events, ["input", "change"]);
});

test("el modo dígitos conserva los ceros del DNI y verifica su patrón nativo", () => {
  const input = new Control({ value: "01234567", maxlength: "8", pattern: "[0-9]{8}", required: "" });
  input.nativeValidation = (probe) => {
    assert.equal(probe.readOnly, false);
    probe.validationMessage = "El documento debe tener ocho dígitos.";
    return /^[0-9]{8}$/.test(probe.value);
  };
  const commits = [];
  const f = fixture([{ input, mode: "digits", onCommit: (value) => commits.push(value) }]);
  assert.equal(input.dataset.pddKeyboard, "digits");
  f.keypad.open(input);
  assert.equal(f.keypad.confirm(), true);
  assert.equal(input.value, "01234567");
  assert.deepEqual(commits, ["01234567"]);
  f.keypad.open(input);
  f.key("1");
  assert.equal(f.keypad.confirm(), false);
  assert.match(f.messageOutput.textContent, /ocho dígitos/);
  assert.equal(input.value, "01234567");
  assert.equal(applyDigitsKeypadKey("00", "1", 8), "001");
  assert.equal(applyDigitsKeypadKey("01234567", "8", 8), "01234567");
});

test("no se recortan silenciosamente enteros, decimales ni documentos existentes", () => {
  for (const [value, binding] of [
    ["1234567", { mode: "integer", maxLength: 6 }],
    ["1234567.1234", { mode: "decimal", maxLength: 6, decimalPlaces: 4 }],
    ["1.12345", { mode: "decimal", maxLength: 6, decimalPlaces: 4 }],
    ["012345678", { mode: "digits", maxLength: 8 }]
  ]) {
    const input = new Control({ value, step: "any" });
    const f = fixture([{ input, ...binding }]);
    f.keypad.open(input);
    assert.equal(f.valueOutput.textContent, value);
    assert.equal(f.keypad.confirm(), false);
    assert.equal(input.value, value);
    assert.equal(f.dialog.open, true);
  }
  assert.equal(applyIntegerKeypadKey("12345678", "backspace", 6), "1234567");
  assert.equal(applyDecimalKeypadKey("12345678.1234", "backspace", 6, 2), "12345678.123");
  assert.match(validateIntegerKeypadBuffer("9999999", { maxLength: 6 }), /6 dígitos/);
  assert.match(validateIntegerKeypadBuffer("-12", { min: 0 }), /válida/);
  assert.match(validateDecimalKeypadBuffer("9999999.12", { maxLength: 6 }), /6 dígitos/);
});

test("el mismo diálogo conserva 2, 3 y 4 decimales según cada campo", () => {
  const f = fixture();
  for (const decimalPlaces of [2, 3, 4]) {
    const value = `1.${"2".repeat(decimalPlaces)}`;
    const input = new Control({ type: "number", value, step: String(10 ** -decimalPlaces) });
    f.keypad.register({ input, mode: "decimal", decimalPlaces });
    f.keypad.open(input);
    assert.equal(f.keypad.confirm(), true);
    assert.equal(input.value, value);
    assert.equal(f.keys.get("00").textContent, ".");
  }
});

test("los límites min, max, required y step bloquean la confirmación sin modificar el valor", () => {
  const input = new Control({ type: "number", value: "3", min: "1", max: "5", step: "2", required: "" });
  const f = fixture([input]);
  for (const proposed of ["", "0", "6", "2"]) {
    f.keypad.open(input);
    emit(f.clearButton, "click");
    for (const key of proposed) f.key(key);
    assert.equal(f.keypad.confirm(), false, proposed);
    assert.equal(input.value, "3");
  }
  f.keypad.open(input);
  f.key("5");
  assert.equal(f.keypad.confirm(), true);
  assert.equal(input.value, "5");
  assert.equal(validateDecimalKeypadBuffer("0.3", { step: "0.1", decimalPlaces: 4 }), "");
  assert.match(validateDecimalKeypadBuffer("0.35", { step: "0.1", decimalPlaces: 4 }), /incrementos/);
  assert.equal(validateDecimalKeypadBuffer("1.250", { min: "0.25", step: "0.5", decimalPlaces: 3 }), "");
  assert.equal(validateDecimalKeypadBuffer("9999999999.9999", { min: "0.0001", step: "0.0001", maxLength: 10, decimalPlaces: 4 }), "");
  assert.equal(validateDecimalKeypadBuffer("0.003", { step: "1e-3", decimalPlaces: 3 }), "");
});

test("la validación nativa usa una copia editable y nunca habilita el campo enfocado", () => {
  const input = new Control({ maxlength: "8", minlength: "3" });
  input.readOnly = true;
  input.value = "004";
  input.focus();
  input.nativeValidation = (probe) => {
    assert.notEqual(probe, input);
    assert.equal(probe.readOnly, false);
    assert.equal(input.readOnly, true);
    assert.equal(input.value, "004");
    probe.validationMessage = "Formato incorrecto.";
    return probe.value !== "123";
  };
  assert.equal(validateKeypadInputValue(input, "123"), "Formato incorrecto.");
  assert.match(validateKeypadInputValue(input, "12"), /al menos 3/);
  assert.match(validateKeypadInputValue(input, "123456789"), /hasta 8/);
  assert.equal(input.readOnly, true);
  assert.equal(input.ownerDocument.activeElement, input);
});

test("cancelar, Escape y el fondo descartan cambios y devuelven el foco sin reabrir", () => {
  const input = new Control({ value: "42" });
  const f = fixture([input]);
  const dismissals = [
    () => emit(f.cancel, "click"),
    () => f.key("Escape"),
    () => emit(f.dialog, "cancel"),
    () => emit(f.dialog, "click", { clientX: 10, clientY: 10 })
  ];
  for (const dismiss of dismissals) {
    f.keypad.open(input);
    f.key("5");
    dismiss();
    assert.equal(f.dialog.open, false);
    assert.equal(f.keypad.activeInput, null);
    assert.equal(input.value, "42");
    assert.equal(input.getAttribute("aria-expanded"), "false");
    assert.equal(input.ownerDocument.activeElement, input);
  }
  assert.equal(f.dialog.showCount, dismissals.length);
  f.keypad.open(input);
  emit(f.dialog, "click", { clientX: 150, clientY: 150 });
  assert.equal(f.dialog.open, true);
});

test("los campos deshabilitados directamente o por fieldset no abren teclado", () => {
  const input = new Control();
  const f = fixture([input]);
  input.disabled = true;
  emit(input, "click");
  input.disabled = false;
  input.fieldsetDisabled = true;
  f.keypad.open(input);
  assert.equal(f.dialog.showCount, 0);
  assert.equal(f.keypad.activeInput, null);
});

test("Enter y espacio abren el teclado y enfocar un campo no lo abre", () => {
  const input = new Control();
  const f = fixture([input]);
  input.focus();
  assert.equal(f.dialog.open, false);
  for (const key of ["Enter", " "]) {
    const event = emit(input, "keydown", { key });
    assert.equal(event.defaultPrevented, true);
    assert.equal(f.dialog.open, true);
    assert.equal(f.valueOutput.ownerDocument.activeElement, f.valueOutput);
    f.keypad.close();
  }
});
