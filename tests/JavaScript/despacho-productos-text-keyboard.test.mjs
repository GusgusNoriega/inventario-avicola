import assert from "node:assert/strict";
import test from "node:test";
import { applyTextKeyboardKey, bindTextKeyboard, validateTextKeyboardValue } from "../../public/js/despacho-productos-text-keyboard.js";

test("el teclado escribe español y sustituye la selección en el punto del cursor", () => {
  assert.deepEqual(applyTextKeyboardKey("cafe", 3, 4, "é"), { value: "café", selectionStart: 4, selectionEnd: 4 });
  assert.deepEqual(applyTextKeyboardKey("ao", 1, 1, "ñ"), { value: "año", selectionStart: 2, selectionEnd: 2 });
  assert.equal(applyTextKeyboardKey("ÁÉÍÓÚ", 5, 5, "Ü").value, "ÁÉÍÓÚÜ");
  assert.equal(applyTextKeyboardKey("hola", 4, 4, "space").value, "hola ");
});

test("borrar distingue selección, carácter anterior y carácter siguiente", () => {
  assert.deepEqual(applyTextKeyboardKey("abcd", 1, 3, "backspace"), { value: "ad", selectionStart: 1, selectionEnd: 1 });
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "backspace").value, "acd");
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "delete").value, "abd");
  assert.equal(applyTextKeyboardKey("abcd", 0, 0, "backspace").value, "abcd");
  assert.equal(applyTextKeyboardKey("abcd", 4, 4, "delete").value, "abcd");
  assert.deepEqual(applyTextKeyboardKey("abcd", 2, 2, "clear"), { value: "", selectionStart: 0, selectionEnd: 0 });
});

test("las teclas de navegación permiten corregir y seleccionar todo", () => {
  assert.deepEqual(applyTextKeyboardKey("abcd", 1, 3, "select-all"), { value: "abcd", selectionStart: 0, selectionEnd: 4 });
  assert.equal(applyTextKeyboardKey("abcd", 1, 3, "left").selectionStart, 1);
  assert.equal(applyTextKeyboardKey("abcd", 1, 3, "right").selectionStart, 3);
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "left").selectionStart, 1);
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "right").selectionStart, 3);
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "home").selectionStart, 0);
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "end").selectionStart, 4);
});

test("maxlength permite reemplazar sin exceder el límite y respeta cero", () => {
  assert.equal(applyTextKeyboardKey("abcd", 2, 2, "ñ", { maxLength: 4 }).value, "abcd");
  assert.equal(applyTextKeyboardKey("abcd", 1, 3, "XY", { maxLength: 4 }).value, "aXYd");
  assert.equal(applyTextKeyboardKey("", 0, 0, "a", { maxLength: 0 }).value, "");
  assert.equal(applyTextKeyboardKey("", 0, 0, "a", { maxLength: -1 }).value, "a");
  assert.equal(applyTextKeyboardKey("abcd", 4, 4, "backspace", { maxLength: 0 }).value, "abc");
});

test("sólo los campos de varias líneas admiten saltos de línea", () => {
  assert.equal(applyTextKeyboardKey("ab", 1, 1, "newline").value, "ab");
  assert.equal(applyTextKeyboardKey("ab", 1, 1, "newline", { multiline: true }).value, "a\nb");
  assert.equal(applyTextKeyboardKey("", 0, 0, "a\r\nb\rc", { multiline: true }).value, "a\nb\nc");
});

test("el texto pegado es literal y respeta selecciones y límites", () => {
  for (const value of ["clear", "backspace", "space", "select-all", "delete"]) {
    assert.equal(applyTextKeyboardKey("", 0, 0, value, { literal: true }).value, value);
  }
  assert.equal(applyTextKeyboardKey("Nombre", 0, 6, "José Pérez", { literal: true, maxLength: 9 }).value, "José Pére");
  assert.equal(applyTextKeyboardKey("", 0, 0, "a\nb", { literal: true }).value, "ab");
});

test("borrado y límites preservan caracteres de dos unidades UTF-16", () => {
  assert.equal(applyTextKeyboardKey("a🐔b", 3, 3, "backspace").value, "ab");
  assert.equal(applyTextKeyboardKey("a🐔b", 1, 1, "delete").value, "ab");
  assert.equal(applyTextKeyboardKey("a🐔b", 3, 3, "left").selectionStart, 1);
  assert.equal(applyTextKeyboardKey("a🐔b", 1, 1, "right").selectionStart, 3);
  assert.equal(applyTextKeyboardKey("", 0, 0, "🐔", { maxLength: 1 }).value, "");
});

test("la aceptación valida una copia editable, conserva las restricciones y permite vaciar búsquedas", () => {
  let validationCopy;
  const input = {
    maxLength: -1,
    minLength: -1,
    validity: { customError: false },
    cloneNode() {
      validationCopy = {
        readOnly: true,
        disabled: false,
        checkValidity() { return !this.readOnly && !this.disabled && this.value === ""; },
        validationMessage: "Valor inválido"
      };
      return validationCopy;
    }
  };
  assert.equal(validateTextKeyboardValue(input, ""), "");
  assert.equal(validationCopy.readOnly, false);
  assert.equal(validateTextKeyboardValue(input, "abc"), "Valor inválido");
  assert.match(validateTextKeyboardValue({ ...input, maxLength: 0 }, "a"), /máximo 0/);
  assert.match(validateTextKeyboardValue({ ...input, minLength: 3 }, "a"), /al menos 3/);
  assert.equal(validateTextKeyboardValue({ ...input, validity: { customError: true }, validationMessage: "No disponible" }, "abc"), "No disponible");
});

function keyboardFixture() {
  let onMutation = () => {};
  let document;
  class Element extends EventTarget {
    constructor(tagName = "div") {
      super();
      Object.assign(this, { tagName: tagName.toUpperCase(), dataset: {}, children: [], attributes: {}, textContent: "", isConnected: true, disabled: false });
      this.classList = { toggle() {} };
      this.ownerDocument = document;
    }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    matches(selector) { return selector === ":disabled" ? this.disabled || this.fieldsetDisabled : true; }
    closest(selector) { return selector === "dialog" ? this.parentDialog || null : null; }
    focus() { document.activeElement = this; }
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatchEvent(new Event("close")); }
    contains() { return true; }
    querySelector(selector) { return this.controls[selector]; }
    cloneNode() {
      return { readOnly: this.readOnly, disabled: this.disabled, required: this.required, validationMessage: "Completa este campo.", checkValidity() { return !this.required || Boolean(this.value); } };
    }
  }
  document = {
    defaultView: {
      Event,
      MutationObserver: class {
        constructor(callback) { onMutation = callback; }
        observe() {}
        disconnect() { onMutation = () => {}; }
      }
    },
    createElement: tagName => new Element(tagName),
    createTextNode: value => ({ textContent: value })
  };
  document.documentElement = new Element();
  const dialog = new Element("dialog");
  dialog.controls = Object.fromEntries(["value", "title", "message", "keys"].map(name => [`[data-pdk-text-${name}]`, new Element()]));
  const output = dialog.controls["[data-pdk-text-value]"];
  const controller = bindTextKeyboard({ dialog });
  const input = new Element("input");
  Object.assign(input, { type: "text", value: "Ana", maxLength: -1, minLength: -1, labels: [{ textContent: "Nombre" }], selectionStart: 3, selectionEnd: 3 });
  const events = [];
  input.addEventListener("input", () => events.push(["input", input.value]));
  input.addEventListener("change", () => events.push(["change", input.value]));
  const emit = (type, properties = {}, target = output) => {
    const event = new Event(type, { cancelable: true });
    Object.entries({ ...properties, target }).forEach(([key, value]) => Object.defineProperty(event, key, { value }));
    dialog.dispatchEvent(event);
    return event;
  };
  return { controller, dialog, input, output, events, document, Element, emit, mutate: () => onMutation() };
}

test("el diálogo emite input en vivo, cancelar restaura la búsqueda y aceptar emite change", () => {
  const { controller, input, dialog, output, document, events, emit } = keyboardFixture();
  assert.equal(controller.open(input), true);
  assert.equal(input.readOnly, true);
  assert.equal(input.getAttribute("inputmode"), "none");
  assert.equal(document.activeElement, output);
  emit("keydown", { key: "í" });
  assert.equal(input.value, "Anaí");
  assert.deepEqual(events, [["input", "Anaí"]]);
  assert.equal(controller.close(), true);
  assert.equal(input.value, "Ana");
  assert.equal(dialog.open, false);
  assert.equal(document.activeElement, input);
  assert.deepEqual(events, [["input", "Anaí"], ["input", "Ana"]]);
  controller.open(input);
  emit("keydown", { key: "a", ctrlKey: true });
  emit("keydown", { key: "Ñ" });
  assert.equal(controller.confirm(), true);
  assert.equal(input.value, "Ñ");
  assert.deepEqual(events.slice(-2), [["input", "Ñ"], ["change", "Ñ"]]);
});

test("el teclado respeta disabled heredado y descarta la edición al cerrar el diálogo padre", () => {
  const { controller, input, dialog, Element, emit } = keyboardFixture();
  input.fieldsetDisabled = true;
  assert.equal(controller.open(input), false);
  input.fieldsetDisabled = false;
  const parent = new Element("dialog");
  parent.open = true;
  input.parentDialog = parent;
  controller.open(input);
  emit("keydown", { key: "x" });
  parent.close();
  assert.equal(controller.activeInput, null);
  assert.equal(dialog.open, false);
  assert.equal(input.value, "Ana");
});

test("Escape cancela, required bloquea aceptar vacío y el campo eliminado cierra el teclado", () => {
  const { controller, input, dialog, emit, mutate } = keyboardFixture();
  input.required = true;
  controller.open(input);
  emit("keydown", { key: "a", ctrlKey: true });
  emit("keydown", { key: "Backspace" });
  assert.equal(input.value, "");
  assert.equal(controller.confirm(), false);
  assert.equal(dialog.open, true);
  assert.equal(emit("keydown", { key: "Escape" }).defaultPrevented, true);
  assert.equal(input.value, "Ana");
  controller.open(input);
  input.isConnected = false;
  mutate();
  assert.equal(dialog.open, false);
  assert.equal(controller.activeInput, null);
});

test("las contraseñas se muestran enmascaradas y los atajos de copiar permanecen disponibles", () => {
  const { controller, input, output, emit } = keyboardFixture();
  input.type = "password";
  controller.open(input);
  assert.equal(output.children.map(node => node.textContent).join("").replaceAll("\u200b", ""), "•••");
  assert.equal(emit("keydown", { key: "c", ctrlKey: true }).defaultPrevented, false);
  controller.close();
  input.type = "text";
  controller.open(input);
  emit("keydown", { key: "a", ctrlKey: true });
  let copied;
  emit("copy", { clipboardData: { setData(type, value) { copied = value; } } });
  assert.equal(copied, "Ana");
  emit("paste", { clipboardData: { getData() { return "clear"; } } });
  assert.equal(input.value, "clear");
});
