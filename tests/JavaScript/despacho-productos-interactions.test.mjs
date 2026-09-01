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
const dispatchUtilsSource = readFileSync(
  new URL("../../public/js/despacho-productos-despacho-utils.js", import.meta.url),
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

test("cantidad, merma por unidad y tara comparten el teclado; la merma total es solo lectura", () => {
  const quantityInput = dispatchView.match(/<input\b[^>]*\bid="pddQuantity"[^>]*>/)?.[0] || "";
  const wasteInput = dispatchView.match(/<input\b[^>]*\bid="pddWastePerUnit"[^>]*>/)?.[0] || "";
  const tareInput = dispatchView.match(/<input\b[^>]*\bid="pddTare"[^>]*>/)?.[0] || "";
  const wasteTotalOutput = dispatchView.match(/<output\b[^>]*\bid="pddWasteTotal"[^>]*>/)?.[0] || "";
  const keypadBinding = sourceBetween(
    dispatchSource,
    "bindIntegerKeypad({",
    "elements.chooseProduct.addEventListener"
  );

  assert.ok(quantityInput, "No se encontró el campo de cantidad.");
  assert.ok(wasteInput, "No se encontró el campo de merma por unidad.");
  assert.ok(tareInput, "No se encontró el campo de tara.");
  assert.ok(wasteTotalOutput, "La merma total debe mostrarse en un output.");
  assert.doesNotMatch(dispatchView, /<input\b[^>]*\bid="pddWasteTotal"/);
  for (const input of [quantityInput, wasteInput, tareInput]) {
    assert.match(input, /\breadonly\b/);
    assert.match(input, /\binputmode="none"/);
    assert.match(input, /\bdata-pdd-keypad-label=/);
    assert.match(input, /\baria-controls="[^"]+"/);
  }

  const quantityDialogId = quantityInput.match(/\baria-controls="([^"]+)"/)?.[1];
  const wasteDialogId = wasteInput.match(/\baria-controls="([^"]+)"/)?.[1];
  const tareDialogId = tareInput.match(/\baria-controls="([^"]+)"/)?.[1];
  assert.equal(wasteDialogId, quantityDialogId, "Cantidad y merma deben abrir el mismo diálogo.");
  assert.equal(tareDialogId, quantityDialogId, "Cantidad y tara deben abrir el mismo diálogo.");
  assert.match(dispatchView, new RegExp(`<dialog\\b[^>]*\\bid="${quantityDialogId}"[^>]*>`));

  assert.doesNotMatch(dispatchView, /data-pdd-quantity-step|Disminuir cantidad|Aumentar cantidad/);
  assert.doesNotMatch(dispatchSource, /data-pdd-quantity-step/);
  assert.equal((dispatchSource.match(/\bbindIntegerKeypad\s*\(\{/g) || []).length, 1);
  assert.match(keypadBinding, /elements\.quantity/);
  assert.match(keypadBinding, /elements\.wastePerUnit/);
  assert.match(keypadBinding, /elements\.tare/);
  assert.doesNotMatch(keypadBinding, /elements\.wasteTotal/);

  assert.equal((dispatchView.match(/class="pdd-touch-number-input"/g) || []).length, 3);
  const wasteControlStyles = dispatchStyles.match(/\.pdd-touch-number-input\s+input\s*\{([^}]*)\}/)?.[1] || "";
  const wasteControlHeight = Number(wasteControlStyles.match(/(?:min-)?height:\s*(\d+)px/)?.[1]);
  assert.ok(wasteControlHeight >= 60, "El control táctil de merma debe medir al menos 60 px de alto.");
});

test("el precio editable pertenece a la pesada y no existen cambios globales del ticket", () => {
  const capturePrice = dispatchView.match(/<input\b[^>]*\bid="pddUnitPrice"[^>]*>/)?.[0] || "";
  const editPrice = dispatchView.match(/<input\b[^>]*\bid="pddEditPrice"[^>]*>/)?.[0] || "";

  for (const input of [capturePrice, editPrice]) {
    assert.ok(input, "Debe existir el precio editable de la pesada.");
    assert.match(input, /\btype="number"/);
    assert.match(input, /\bmin="0\.0001"/);
    assert.match(input, /\bmax="9999999999\.9999"/);
    assert.match(input, /\bstep="0\.0001"/);
    assert.match(input, /\binputmode="decimal"/);
  }

  assert.match(dispatchView, /id="pddPriceCurrency"/);
  assert.match(dispatchView, /Importe pesada/);
  assert.match(dispatchStyles, /\.pdd-price-input\s*\{[^}]*min-height:\s*60px/);
  assert.doesNotMatch(dispatchView, /pddChangePrice|pddRailChangePrice|pddPriceDialog/);
  assert.doesNotMatch(dispatchSource, /openPriceDialog|savePrices|price_overrides/);
  assert.doesNotMatch(dispatchUtilsSource, /price_overrides/);
});

test("el precio manual se conserva al cambiar de lista y vuelve al catálogo solo donde corresponde", () => {
  const selectedRender = sourceBetween(dispatchSource, "function renderSelectedProduct", "function renderVariations");
  const listSelection = sourceBetween(dispatchSource, "function selectList", "function selectProduct");
  const productSelection = sourceBetween(dispatchSource, "function selectProduct", "function selectVariation");
  const variationSelection = sourceBetween(dispatchSource, "function selectVariation", "function capturedReadingIds");
  const addReading = sourceBetween(dispatchSource, "function addCurrentReading", "function openProductDialog");

  assert.doesNotMatch(selectedRender, /useCatalogPrice\(|setCapturePrice\(/);
  assert.doesNotMatch(listSelection, /useCatalogPrice\(|setCapturePrice\(/);
  assert.match(productSelection, /useCatalogPrice\(\)/);
  assert.match(variationSelection, /useCatalogPrice\(\)/);
  assert.match(addReading, /activeDraft\(\)\.items\.push\(item\);[\s\S]*?useCatalogPrice\(\)/);
  assert.match(dispatchSource, /elements\.unitPrice\.addEventListener\("input",\s*renderCapturePreview\)/);
});

test("captura y edición bloquean precios o importes inválidos", () => {
  const captureValidationFlow = sourceBetween(dispatchSource, "function captureValidation", "function renderCapturePreview");
  const addReadingFlow = sourceBetween(dispatchSource, "function addCurrentReading", "function openProductDialog");
  const editFlow = sourceBetween(dispatchSource, "function saveEditingItem", "function deleteEditingItem");

  assert.match(captureValidationFlow, /validateUnitPrice\(elements\.unitPrice\.value/);
  assert.match(captureValidationFlow, /line\.amount\s*<\s*0\.01/);
  assert.match(addReadingFlow, /unit_price:\s*elements\.unitPrice\.value/);
  assert.match(editFlow, /validateUnitPrice\(elements\.editPrice\.value/);
  assert.match(editFlow, /line|calculated/);
  assert.match(editFlow, /calculated\.amount\s*<\s*0\.01/);
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
    /elements\.quantity\.addEventListener\(["']input["'][\s\S]*?syncWasteTotal\(\);[\s\S]*?renderCapturePreview\(\);/
  );
  assert.match(
    dispatchSource,
    /elements\.wastePerUnit\.addEventListener\(["']input["'][\s\S]*?syncWasteTotal\(\);[\s\S]*?renderCapturePreview\(\);/
  );
  assert.match(dispatchSource, /elements\.tare\.addEventListener\(["']input["'],\s*renderCapturePreview\)/);
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

test("merma total y tara fuera de rango bloquean Capturar sin recortarse", () => {
  const captureValuesFlow = sourceBetween(
    dispatchSource,
    "function captureValues",
    "function renderWastePresets"
  );
  const validationFlow = sourceBetween(
    dispatchSource,
    "function captureValidation",
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
    captureValuesFlow,
    /waste_total_grams:\s*wasteGramsPerUnit\s*\*\s*quantity/
  );
  assert.doesNotMatch(captureValuesFlow, /Math\.min\s*\(|clamp(?:Number)?\s*\(/i);
  assert.match(validationFlow, /waste_total_grams\s*>\s*PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS/);
  assert.match(validationFlow, /waste_total_grams\s*\+\s*values\.tare_grams\s*>=\s*weightKg\s*\*\s*1000/);
  assert.match(previewFlow, /const validation\s*=\s*captureValidation\(/);
  assert.match(previewFlow, /captureReady[\s\S]*&&\s*!validation\.message[\s\S]*captureWeight\.disabled\s*=\s*!captureReady/);
  assert.match(
    addReadingFlow,
    /const validation\s*=\s*captureValidation\(weight\);\s*if\s*\(validation\.message\)\s*\{[\s\S]*validation\.target\?\.focus\(\);[\s\S]*setMessage\(validation\.message,\s*["']error["']\);[\s\S]*return false;/
  );
  assert.ok(
    addReadingFlow.indexOf("const validation = captureValidation(weight)")
      < addReadingFlow.indexOf("const calculated = calculateLine"),
    "La merma o tara fuera de rango debe rechazarse antes de calcular o insertar la pesada."
  );
});

test("los tres presets son táctiles, indican selección y se guardan en la configuración", () => {
  assert.equal((dispatchView.match(/data-pdd-waste-preset="[012]"/g) || []).length, 3);
  assert.match(dispatchView, /id="pddWastePresetForm"/);
  assert.match(dispatchSource, /aria-pressed="\$\{active\}"/);
  assert.match(dispatchSource, /apiRequest\(`\$\{apiBase\}\/configuracion`,\s*\{[\s\S]*method:\s*"PUT"/);
  assert.match(dispatchSource, /JSON\.stringify\(\{\s*waste_presets:\s*proposed\s*\}\)/);
  assert.match(dispatchStyles, /\.pdd-waste-presets button\s*\{[^}]*min-height:\s*52px/);
});

test("la tara vuelve a cero después de capturar una pesada", () => {
  const addReadingFlow = sourceBetween(dispatchSource, "function addCurrentReading", "function openProductDialog");
  assert.match(addReadingFlow, /activeDraft\(\)\.items\.push\(item\);[\s\S]*elements\.tare\.value\s*=\s*"0"/);
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
