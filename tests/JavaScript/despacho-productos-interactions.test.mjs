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

test("cantidad y merma son controles grandes del mismo teclado y no usan botones más o menos", () => {
  const quantityInput = dispatchView.match(/<input\b[^>]*\bid="pddQuantity"[^>]*>/)?.[0] || "";
  const wasteInput = dispatchView.match(/<input\b[^>]*\bid="pddWasteTotal"[^>]*>/)?.[0] || "";
  const keypadBinding = sourceBetween(
    dispatchSource,
    "bindIntegerKeypad({",
    "elements.chooseProduct.addEventListener"
  );

  assert.ok(quantityInput, "No se encontró el campo de cantidad.");
  assert.ok(wasteInput, "No se encontró el campo de merma.");
  for (const input of [quantityInput, wasteInput]) {
    assert.match(input, /\breadonly\b/);
    assert.match(input, /\binputmode="none"/);
    assert.match(input, /\bdata-pdd-keypad-label=/);
    assert.match(input, /\baria-controls="[^"]+"/);
  }

  const quantityDialogId = quantityInput.match(/\baria-controls="([^"]+)"/)?.[1];
  const wasteDialogId = wasteInput.match(/\baria-controls="([^"]+)"/)?.[1];
  assert.equal(wasteDialogId, quantityDialogId, "Cantidad y merma deben abrir el mismo diálogo.");
  assert.match(dispatchView, new RegExp(`<dialog\\b[^>]*\\bid="${quantityDialogId}"[^>]*>`));

  assert.doesNotMatch(dispatchView, /data-pdd-quantity-step|Disminuir cantidad|Aumentar cantidad/);
  assert.doesNotMatch(dispatchSource, /data-pdd-quantity-step/);
  assert.equal((dispatchSource.match(/\bbindIntegerKeypad\s*\(\{/g) || []).length, 1);
  assert.match(keypadBinding, /elements\.quantity/);
  assert.match(keypadBinding, /elements\.wasteTotal/);

  assert.match(
    dispatchView,
    /class="pdd-touch-number-input"[\s\S]*?id="pddQuantity"[\s\S]*?class="pdd-touch-number-input"[\s\S]*?id="pddWasteTotal"/
  );
  const wasteControlStyles = dispatchStyles.match(/\.pdd-touch-number-input\s+input\s*\{([^}]*)\}/)?.[1] || "";
  const wasteControlHeight = Number(wasteControlStyles.match(/(?:min-)?height:\s*(\d+)px/)?.[1]);
  assert.ok(wasteControlHeight >= 60, "El control táctil de merma debe medir al menos 60 px de alto.");
});

test("el teclado confirma con eventos que recalculan cantidad, merma y total", () => {
  assert.match(
    keypadSource,
    /dispatchEvent\(new Event\(["']input["'],\s*\{\s*bubbles:\s*true\s*\}\)\)/
  );
  assert.match(
    keypadSource,
    /dispatchEvent\(new Event\(["']change["'],\s*\{\s*bubbles:\s*true\s*\}\)\)/
  );
  assert.match(
    dispatchSource,
    /elements\.quantity\.addEventListener\(["']input["'][\s\S]*?syncDefaultWaste\(\);[\s\S]*?renderCapturePreview\(\);/
  );
  assert.match(
    dispatchSource,
    /elements\.wasteTotal\.addEventListener\(["']input["'][\s\S]*?wasteWasEdited\s*=\s*true;[\s\S]*?renderCapturePreview\(\);/
  );
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

test("el teclado enfoca el output y Enter respeta cualquier botón del diálogo", () => {
  const openFlow = sourceBetween(
    keypadSource,
    "function open(",
    "function close()"
  );
  const dialogKeyboardFlow = sourceBetween(
    keypadSource,
    'dialog.addEventListener("keydown"',
    "return { open, close, confirm"
  );
  const keypadOutput = dispatchView.match(/<output\b[^>]*\bid="pddNumericKeypadValue"[^>]*>/)?.[0] || "";
  const enterStart = dialogKeyboardFlow.indexOf('if (event.key === "Enter")');
  const enterFlow = enterStart >= 0 ? dialogKeyboardFlow.slice(enterStart) : "";

  assert.match(keypadOutput, /\btabindex="-1"/);
  assert.match(openFlow, /showDialog\(valueOutput\)/);
  assert.match(openFlow, /valueOutput\.focus\(\)/);
  assert.notEqual(enterStart, -1, "No se encontró el manejo de Enter en el diálogo.");
  assert.match(enterFlow, /event\.target\.closest\?\.\(["']button["']\)\)\s*return/);
  assert.ok(
    enterFlow.indexOf('closest?.("button")') < enterFlow.indexOf("event.preventDefault()"),
    "La acción nativa de un botón debe respetarse antes de interceptar Enter."
  );
  assert.ok(
    enterFlow.indexOf("event.preventDefault()") < enterFlow.indexOf("confirm()"),
    "Enter desde el output debe prevenir el envío nativo antes de confirmar."
  );
  assert.match(
    dispatchView,
    /<button\b[^>]*\bdata-pdd-close="pddNumericKeypad"[^>]*>Cancelar<\/button>/
  );
});

test("la merma automática fuera del máximo bloquea Capturar sin recortarse", () => {
  const syncWasteFlow = sourceBetween(
    dispatchSource,
    "function syncDefaultWaste",
    "function wasteValidationMessage"
  );
  const validationFlow = sourceBetween(
    dispatchSource,
    "function wasteValidationMessage",
    "function renderCapturePreview"
  );
  const previewFlow = sourceBetween(
    dispatchSource,
    "function renderCapturePreview",
    "function renderScale"
  );
  const addReadingFlow = sourceBetween(
    dispatchSource,
    "function addCurrentReading",
    "function openProductDialog"
  );

  assert.match(
    syncWasteFlow,
    /wasteTotal\.value\s*=\s*String\(selection\.waste_grams_per_unit\s*\*\s*quantity\)/
  );
  assert.doesNotMatch(syncWasteFlow, /Math\.min\s*\(|clamp(?:Number)?\s*\(/i);
  assert.match(validationFlow, /waste\s*>\s*maximum/);
  assert.match(validationFlow, /merma total no puede superar/i);
  assert.match(previewFlow, /const wasteError\s*=\s*wasteValidationMessage\(\)/);
  assert.match(previewFlow, /captureReady[\s\S]*&&\s*!wasteError[\s\S]*captureWeight\.disabled\s*=\s*!captureReady/);
  assert.match(
    addReadingFlow,
    /const wasteError\s*=\s*wasteValidationMessage\(\);\s*if\s*\(wasteError\)\s*\{[\s\S]*wasteTotal\.focus\(\);[\s\S]*setMessage\(wasteError,\s*["']error["']\);[\s\S]*return false;/
  );
  assert.ok(
    addReadingFlow.indexOf("const wasteError = wasteValidationMessage()")
      < addReadingFlow.indexOf("const calculated = calculateLine"),
    "La merma fuera de rango debe rechazarse antes de calcular o insertar la pesada."
  );
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
