import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
  applyIntegerKeypadKey,
  sanitizeIntegerKeypadBuffer,
  validateIntegerKeypadBuffer
} from "../../public/js/despacho-productos-numeric-keypad.js";

const dispatchSource = readFileSync(
  new URL("../../public/js/despacho-productos-despacho.js", import.meta.url),
  "utf8"
);
const dispatchView = readFileSync(
  new URL("../../resources/views/despacho-productos-despacho.blade.php", import.meta.url),
  "utf8"
);
const dispatchStyles = readFileSync(
  new URL("../../public/css/despacho-productos-despacho.css", import.meta.url),
  "utf8"
);
const keypadSource = readFileSync(
  new URL("../../public/js/despacho-productos-numeric-keypad.js", import.meta.url),
  "utf8"
);

function sourceBetween(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);

  assert.notEqual(start, -1, `No se encontró el inicio: ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró el final: ${endMarker}`);
  return source.slice(start, end);
}

test("el buffer del teclado conserva solamente una cantidad entera acotada", () => {
  assert.equal(sanitizeIntegerKeypadBuffer(null), "");
  assert.equal(sanitizeIntegerKeypadBuffer("0"), "0");
  assert.equal(sanitizeIntegerKeypadBuffer(" 00a123x "), "123");
  assert.equal(sanitizeIntegerKeypadBuffer("987654321", 6), "987654");
});

test("las teclas táctiles agregan, borran y limpian sin aceptar símbolos", () => {
  assert.equal(applyIntegerKeypadKey("12", "3"), "123");
  assert.equal(applyIntegerKeypadKey("12", "00"), "1200");
  assert.equal(applyIntegerKeypadKey("0", "7"), "7");
  assert.equal(applyIntegerKeypadKey("123", "backspace"), "12");
  assert.equal(applyIntegerKeypadKey("123", "clear"), "");
  assert.equal(applyIntegerKeypadKey("123", "."), "123");
  assert.equal(applyIntegerKeypadKey("123456", "7", 6), "123456");
});

test("la validación del teclado respeta obligatoriedad y límites de cantidad", () => {
  assert.equal(validateIntegerKeypadBuffer("", { required: false }), "");
  assert.match(validateIntegerKeypadBuffer("", { required: true }), /Ingresa una cantidad/);
  assert.match(validateIntegerKeypadBuffer("0", { min: 1 }), /mínima es 1/);
  assert.match(validateIntegerKeypadBuffer("100001", { max: 100000 }), /máxima es 100000/);
  assert.equal(validateIntegerKeypadBuffer("42", { min: 1, max: 100000 }), "");
  assert.match(
    validateIntegerKeypadBuffer("90071992547409910", { maxLength: 20 }),
    /cantidad válida/
  );
});

test("la cantidad se presenta como control táctil y abre su propio diálogo", () => {
  const quantityInput = dispatchView.match(/<input\b[^>]*\bid="pddQuantity"[^>]*>/)?.[0] || "";

  assert.ok(quantityInput, "No se encontró el campo de cantidad.");
  assert.match(quantityInput, /\breadonly\b/);
  assert.match(quantityInput, /\binputmode="none"/);
  assert.match(quantityInput, /\bdata-pdd-keypad-label=/);
  assert.match(dispatchView, /<dialog\b[^>]*\bid="pddQuantityKeypad"[^>]*>/);
});

test("confirmar el peso manual solo fija la lectura pendiente y no agrega una pesada", () => {
  const manualSubmitFlow = sourceBetween(
    dispatchSource,
    'elements.manualForm.addEventListener("submit"',
    'elements.lists.addEventListener("click"'
  );

  assert.match(manualSubmitFlow, /pendingManualReading\s*=/);
  assert.doesNotMatch(manualSubmitFlow, /addCurrentReading\s*\(/);
  assert.match(manualSubmitFlow, /closeDialog\(elements\.manualDialog\)/);
});

test("la lectura manual pendiente tiene prioridad hasta pulsar Capturar peso", () => {
  const effectiveReadingFlow = sourceBetween(
    dispatchSource,
    "function effectiveCaptureReading",
    "function clientById"
  );
  const addReadingFlow = sourceBetween(
    dispatchSource,
    "function addCurrentReading",
    "function openProductDialog"
  );

  assert.match(effectiveReadingFlow, /pendingManualReading/);
  assert.ok(
    /\.\.\.physicalState[\s\S]*\.\.\.state\.pendingManualReading/.test(effectiveReadingFlow)
      || /state\.pendingManualReading\s*\|\|\s*physicalState/.test(effectiveReadingFlow),
    "La lectura manual pendiente debe prevalecer sobre la lectura física."
  );
  assert.match(addReadingFlow, /scaleState\s*=\s*effectiveCaptureReading\(\)/);
  assert.match(
    dispatchSource,
    /elements\.captureWeight\.addEventListener\(["']click["'],\s*\(\)\s*=>\s*addCurrentReading\(\)\)/
  );
  assert.match(
    addReadingFlow,
    /if\s*\(isPhysical\)[\s\S]*state\.scale\.clearReading\(\)[\s\S]*else[\s\S]*pendingManualReading\s*=\s*null/
  );
});

test("capturar el override manual activa un cooldown contra el doble toque", () => {
  const cooldownFlow = sourceBetween(
    dispatchSource,
    "function blockCaptureBriefly",
    "function clientById"
  );
  const addReadingFlow = sourceBetween(
    dispatchSource,
    "function addCurrentReading",
    "function openProductDialog"
  );

  assert.match(dispatchSource, /captureBlockedUntil:\s*0/);
  assert.match(cooldownFlow, /captureBlockedUntil\s*=\s*Date\.now\(\)\s*\+\s*durationMs/);
  assert.match(addReadingFlow, /if\s*\(Date\.now\(\)\s*<\s*state\.captureBlockedUntil\)\s*return false/);
  assert.ok(
    addReadingFlow.indexOf("captureBlockedUntil") < addReadingFlow.indexOf("activeDraft().items.push"),
    "El bloqueo debe comprobarse antes de insertar la pesada."
  );
  assert.match(
    addReadingFlow,
    /if\s*\(isPhysical\)[\s\S]*else\s*\{\s*blockCaptureBriefly\(\);[\s\S]*pendingManualReading\s*=\s*null/
  );
});

test("la pesada manual fija su hora al capturar y no reutiliza readingAt", () => {
  const addReadingFlow = sourceBetween(
    dispatchSource,
    "function addCurrentReading",
    "function openProductDialog"
  );
  const itemFlow = sourceBetween(
    addReadingFlow,
    "const item = {",
    "activeDraft().items.push"
  );

  assert.match(
    itemFlow,
    /weighed_at:\s*isPhysical\s*\?[\s\S]*scaleState\.readingAt[\s\S]*:\s*new Date\(\)\.toISOString\(\)/
  );
  assert.doesNotMatch(itemFlow, /weighed_at:\s*scaleState\.readingAt\s*\|\|/);
});

test("Enter confirma el teclado sin depender del elemento que tiene el foco", () => {
  const dialogKeyboardFlow = sourceBetween(
    keypadSource,
    'dialog.addEventListener("keydown"',
    "return { open, close, confirm"
  );

  assert.match(
    dialogKeyboardFlow,
    /if\s*\(event\.key\s*===\s*["']Enter["']\)\s*\{\s*event\.preventDefault\(\);\s*confirm\(\);\s*\}/
  );
  assert.doesNotMatch(dialogKeyboardFlow, /event\.target|closest\(["']button/);
});

test("en móvil Cantidad ocupa toda la fila para evitar desbordes", () => {
  const mobileStyles = sourceBetween(
    dispatchStyles,
    "@media (max-width: 560px)",
    "@media (max-width: 390px)"
  );

  assert.match(
    mobileStyles,
    /\.pdd-fields-grid\s*>\s*label:first-child(?:\s*,[^\{]+)?\s*\{[^}]*grid-column:\s*1\s*\/\s*-1\s*;/
  );
});

test("el diálogo manual explica que prepara el peso sin prometer agregar la pesada", () => {
  const manualDialog = sourceBetween(
    dispatchView,
    '<dialog id="pddManualDialog"',
    '<dialog id="pddClientDialog"'
  );

  assert.match(manualDialog, /type="submit"/);
  assert.doesNotMatch(manualDialog, /agregar pesada/i);
});
