import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import test from "node:test";
import {
  keyboardModeFor,
  numericOptionsFor,
  keyboardValidationMessage,
  initializeProductDispatchKeyboards
} from "../../public/js/despacho-productos-keyboards.js";

function field(type, attributes = {}, dataset = {}) {
  return {
    type, dataset, tagName: type === "textarea" ? "TEXTAREA" : "INPUT",
    readOnly: false, value: "", maxLength: -1, minLength: -1,
    getAttribute(name) { return attributes[name] ?? null; },
    matches(selector) { return selector === ":disabled" && Boolean(this.disabled); }
  };
}

test("elige números, documentos con ceros y texto sin alterar controles específicos", () => {
  assert.equal(keyboardModeFor(field("number")), "numeric");
  assert.equal(keyboardModeFor(field("text", { inputmode: "numeric" })), "digits");
  for (const type of ["text", "search", "email", "url", "password", "tel", "textarea"]) {
    assert.equal(keyboardModeFor(field(type)), "text");
  }
  for (const type of ["date", "datetime-local", "range", "checkbox", "file", "hidden"]) {
    assert.equal(keyboardModeFor(field(type)), null);
  }
  assert.equal(keyboardModeFor({ ...field("text"), readOnly: true }), null);
  assert.equal(keyboardModeFor({ ...field("text", {}, { pddKeyboard: "text" }), readOnly: true }), "text");
});

test("deduce la precisión y longitud de los campos actuales, incluidos pasos de medio píxel", () => {
  for (const [step, precision] of [["1", 0], ["0.5", 1], ["0.01", 2], ["0.001", 3], ["1e-3", 3], ["0.0001", 4]]) {
    const options = numericOptionsFor(field("number", { step, max: "9999999999.9999" }));
    assert.equal(options.decimalPlaces, precision);
    assert.equal(options.mode, precision ? "decimal" : "integer");
    assert.equal(options.maxLength, 10);
  }
  const document = { ...field("text", {}, { pddKeyboard: "digits" }), maxLength: 11 };
  assert.deepEqual(numericOptionsFor(document), { input: document, mode: "digits", maxLength: 11 });
});

test("comprueba validación sobre una copia editable y conserva el destino protegido", () => {
  const input = { ...field("text"), readOnly: true, required: true };
  input.cloneNode = () => ({
    readOnly: true, disabled: false,
    get validationMessage() { return this.readOnly || this.value ? "" : "Completa este campo."; }
  });
  assert.equal(keyboardValidationMessage(input), "Completa este campo.");
  assert.equal(input.readOnly, true);
  input.value = "ab";
  input.minLength = 3;
  assert.match(keyboardValidationMessage(input), /al menos 3/);
  input.value = "abcdef";
  input.maxLength = 5;
  assert.match(keyboardValidationMessage(input), /máximo 5/);
  input.disabled = true;
  assert.equal(keyboardValidationMessage(input), "");
});

test("una segunda URL de importación reutiliza la misma instancia del documento", async () => {
  const existing = { numeric: {}, text: {} };
  globalThis.document = { [Symbol.for("avicola.productDispatchKeyboards")]: existing };
  try {
    const otherImport = await import("../../public/js/despacho-productos-keyboards.js?another-version");
    assert.equal(initializeProductDispatchKeyboards(), existing);
    assert.equal(otherImport.initializeProductDispatchKeyboards(), existing);
  } finally {
    delete globalThis.document;
  }
});

test("todas las vistas comparten componentes y protegen campos estáticos antes de cargar scripts", () => {
  const directory = new URL("../../resources/views/", import.meta.url);
  const files = readdirSync(directory).filter(name => /^despacho-productos.*\.blade\.php$/.test(name));
  assert.ok(files.length >= 9);
  for (const name of files) {
    const view = readFileSync(new URL(name, directory), "utf8");
    assert.match(view, /@include\('partials.product-dispatch-keyboards-styles'\)/, name);
    assert.match(view, /@include\('partials.product-dispatch-keyboards'\)/, name);
    for (const [markup] of view.matchAll(/<(?:input|textarea)\b[^>]*>/g)) {
      const type = markup.startsWith("<textarea") ? "textarea" : markup.match(/type="([^"]+)"/)?.[1] || "text";
      if (!["text", "search", "email", "tel", "url", "password", "number", "textarea"].includes(type)) continue;
      assert.match(markup, /data-pdd-keyboard="(?:numeric|digits|text)"/, name);
      assert.match(markup, /\breadonly\b/, name);
      assert.match(markup, /inputmode="none"/, name);
      assert.match(markup, /virtualkeyboardpolicy="manual"/, name);
    }
  }
});
