import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
  applyDecimalKeypadKey,
  applyIntegerKeypadKey,
  sanitizeDecimalKeypadBuffer,
  sanitizeIntegerKeypadBuffer,
  validateDecimalKeypadBuffer,
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
  assert.equal(validateIntegerKeypadBuffer("0", { min: 0, max: 100000 }), "");
  assert.match(validateIntegerKeypadBuffer("100001", { max: 100000 }), /máxima es 100000/);
  assert.equal(validateIntegerKeypadBuffer("42", { min: 1, max: 100000 }), "");
  assert.match(
    validateIntegerKeypadBuffer("90071992547409910", { maxLength: 20 }),
    /cantidad válida/
  );
});

test("el modo decimal del mismo teclado conserva dos decimales y admite punto o coma", () => {
  assert.equal(sanitizeDecimalKeypadBuffer("0012,345", 10, 2), "12.34");
  assert.equal(applyDecimalKeypadKey("12", ".", 10, 2), "12.");
  assert.equal(applyDecimalKeypadKey("12.", "3", 10, 2), "12.3");
  assert.equal(applyDecimalKeypadKey("12.3", "4", 10, 2), "12.34");
  assert.equal(applyDecimalKeypadKey("12.34", "5", 10, 2), "12.34");
  assert.equal(applyDecimalKeypadKey("12.34", "backspace", 10, 2), "12.3");
  assert.equal(validateDecimalKeypadBuffer("0.01", { min: 0.01, max: 9999999999.99, decimalPlaces: 2 }), "");
  assert.equal(validateDecimalKeypadBuffer("18,50", { min: 0.01, decimalPlaces: 2 }), "");
  assert.equal(validateDecimalKeypadBuffer("12.", { min: 0.01, decimalPlaces: 2 }), "");
  assert.match(validateDecimalKeypadBuffer("12.345", { decimalPlaces: 2 }), /hasta 2 decimales/);
  assert.match(validateDecimalKeypadBuffer("0", { min: 0.01, decimalPlaces: 2, valueName: "precio" }), /mínimo es 0.01/);
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
  assert.match(quantityInput, /\bmin="0"/);
  assert.match(quantityInput, /\bvalue="0"/);
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
  assert.ok(wasteControlHeight >= 44, "El control táctil de merma debe medir al menos 44 px de alto.");
});

test("los precios de captura y edición comparten el teclado decimal de la pesada", () => {
  const capturePrice = dispatchView.match(/<input\b[^>]*\bid="pddUnitPrice"[^>]*>/)?.[0] || "";
  const editPrice = dispatchView.match(/<input\b[^>]*\bid="pddEditPrice"[^>]*>/)?.[0] || "";
  const keypadBinding = sourceBetween(dispatchSource, "bindIntegerKeypad({", "elements.chooseProduct.addEventListener");

  for (const input of [capturePrice, editPrice]) {
    assert.ok(input, "Debe existir el precio editable de la pesada.");
    assert.match(input, /\btype="number"/);
    assert.match(input, /\bmin="0\.01"/);
    assert.match(input, /\bmax="9999999999\.99"/);
    assert.match(input, /\bstep="0\.01"/);
    assert.match(input, /\binputmode="none"/);
    assert.match(input, /\breadonly\b/);
    assert.match(input, /\bdata-pdd-keypad-label=/);
    assert.match(input, /\baria-controls="pddNumericKeypad"/);
  }

  assert.match(keypadBinding, /input:\s*elements\.unitPrice,\s*mode:\s*"decimal",\s*decimalPlaces:\s*2/);
  assert.match(keypadBinding, /input:\s*elements\.editPrice,\s*mode:\s*"decimal",\s*decimalPlaces:\s*2/);
  assert.match(keypadSource, /decimal\s*&&\s*key\s*===\s*"00"\s*\?\s*"\."\s*:\s*key/);
  assert.match(keypadSource, /event\.key\s*===\s*"\."\s*\|\|\s*event\.key\s*===\s*","/);
  assert.match(keypadSource, /toFixed\(bindingDecimalPlaces\(\)\)/);
  assert.match(dispatchView, /id="pddPriceCurrency"/);
  assert.match(dispatchView, /Importe/);
  assert.match(dispatchStyles, /\.pdd-price-input\s*\{[^}]*min-height:\s*5[6-9]px/);
  assert.doesNotMatch(dispatchView, /pddChangePrice|pddRailChangePrice|pddPriceDialog/);
  assert.doesNotMatch(dispatchSource, /openPriceDialog|savePrices|price_overrides/);
  assert.doesNotMatch(dispatchUtilsSource, /price_overrides/);
});

test("el acceso rápido muestra los cuatro productos configurados y selecciona con un toque", () => {
  const quickRender = sourceBetween(dispatchSource, "function configuredQuickProducts", "function renderSelectedProduct");
  const quickListeners = sourceBetween(dispatchSource, "elements.chooseProduct.addEventListener", "elements.productSearch.addEventListener");
  const desktopResponsive = sourceBetween(dispatchStyles, "@media (max-width: 1280px)", "@media (max-width: 1120px)");
  const compactResponsive = sourceBetween(dispatchStyles, "@media (max-width: 960px)", "@media (max-width: 860px)");
  const mobileResponsive = sourceBetween(dispatchStyles, "@media (max-width: 560px)", "@media (max-width: 390px)");

  assert.match(dispatchView, /id="pddQuickProducts"/);
  assert.match(dispatchView, /id="pddQuickAllProducts"/);
  assert.match(quickRender, /normalizeQuickProductIds\(state\.catalog\.quick_product_ids,\s*state\.catalog\.products\)/);
  assert.match(quickRender, /data-pdd-quick-product-id/);
  assert.match(quickRender, /aria-pressed="\$\{active\}"/);
  assert.match(quickRender, /product\.image_url/);
  assert.match(quickListeners, /quickAllProducts\.addEventListener\("click",\s*openProductDialog\)/);
  assert.match(quickListeners, /quickProducts\.addEventListener[\s\S]*selectProduct\(option\.dataset\.pddQuickProductId\)/);
  assert.match(dispatchStyles, /\.pdd-capture-deck\s*\{[^}]*grid-template-columns:\s*minmax\(0,[^;]+minmax\(0,[^;]+minmax\(220px/);
  assert.match(dispatchStyles, /\.pdd-quick-products\s*\{[^}]*grid-template-columns:\s*repeat\(2/);
  assert.match(desktopResponsive, /\.pdd-capture-deck\s*\{[^}]*minmax\(170px/);
  assert.doesNotMatch(desktopResponsive, /\.pdd-quick-panel\s*\{[^}]*grid-column/);
  assert.match(compactResponsive, /\.pdd-quick-panel\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/);
  assert.match(compactResponsive, /\.pdd-quick-products\s*\{[^}]*repeat\(4/);
  assert.match(mobileResponsive, /\.pdd-quick-products\s*\{[^}]*repeat\(2/);
});

test("las imágenes rápidas no aumentan la fila y dejan el espacio restante a las listas", () => {
  const desktopHeightContainment = sourceBetween(
    dispatchStyles,
    "@media (min-width: 961px)",
    "@media (max-width: 1280px)"
  );

  assert.match(dispatchStyles, /\.pdd-zoom-surface\s*\{[^}]*grid-template-rows:\s*auto\s+auto\s+minmax\(330px,\s*1fr\)/);
  assert.match(dispatchStyles, /\.pdd-capture-deck\s*\{[^}]*align-items:\s*stretch/);
  assert.match(dispatchStyles, /\.pdd-quick-panel\s*\{[^}]*height:\s*auto;[^}]*align-self:\s*stretch;[^}]*overflow:\s*hidden/);
  assert.match(desktopHeightContainment, /\.pdd-quick-panel\s*\{[^}]*contain:\s*size/);
  assert.match(dispatchStyles, /\.pdd-quick-product-media\s*\{[^}]*position:\s*relative;[^}]*overflow:\s*hidden/);
  assert.match(dispatchStyles, /\.pdd-quick-product img,\s*\.pdd-quick-product-placeholder\s*\{[^}]*position:\s*absolute;[^}]*inset:\s*0;[^}]*height:\s*100%;[^}]*max-height:\s*100%/);
  assert.match(dispatchStyles, /\.pdd-lists-grid\s*\{[^}]*height:\s*calc\(100%\s*-\s*43px\)/);
});

test("cada lista muestra producto, cantidad y neto en una tabla compacta editable", () => {
  const displayNameFlow = sourceBetween(dispatchSource, "function itemDisplayName", "function renderLists");
  const listRender = sourceBetween(dispatchSource, "function renderLists", "function renderActiveSummary");
  const listInteraction = sourceBetween(dispatchSource, 'elements.lists.addEventListener("click"', "elements.assignClient.addEventListener");

  assert.match(displayNameFlow, /return\s+item\.variation_name\s*\|\|\s*item\.product_name/);
  assert.doesNotMatch(displayNameFlow, /item\.product_name\s*\}[^`]*item\.variation_name/);
  assert.match(listRender, /<table class="pdd-weighing-table">/);
  assert.match(listRender, /<caption class="sr-only">Pesadas de la lista/);
  assert.match(listRender, /<abbr title="Producto">Prod\.<\/abbr>/);
  assert.match(listRender, /<abbr title="Cantidad">Cant\.<\/abbr>/);
  assert.match(listRender, /<abbr title="Peso neto">Neto<\/abbr>/);
  assert.match(listRender, /itemDisplayName\(item\)/);
  assert.match(listRender, /String\(item\.quantity\)/);
  assert.match(listRender, /formatWeight\(item\.net_weight_kg\)/);
  assert.match(listRender, /class="pdd-weighing-edit"[^>]*data-pdd-edit-item=/);
  assert.match(listRender, /aria-label="\$\{escapeHtml\(editLabel\)\}"/);
  assert.match(listInteraction, /closest\("\[data-pdd-edit-item\]"\)/);
  assert.match(listInteraction, /openEditDialog\(edit\.dataset\.pddEditItem,\s*edit\.dataset\.pddListIndex\)/);
  assert.doesNotMatch(listRender, /item\.amount|item\.waste_total_grams|item\.tare_grams/);

  assert.match(dispatchStyles, /\.pdd-weighing-table\s*\{[^}]*table-layout:\s*fixed/);
  assert.match(dispatchStyles, /\.pdd-weighing-table th\s*\{[^}]*position:\s*sticky/);
  assert.match(dispatchStyles, /\.pdd-weighing-edit\s*\{[^}]*min-height:\s*34px/);
  assert.match(dispatchStyles, /\.pdd-weighing-row td\s*\{[^}]*padding:\s*5px 4px/);
  assert.match(dispatchStyles, /\.pdd-weighing-edit::after\s*\{[^}]*position:\s*absolute;[^}]*inset:\s*0/);
  assert.match(dispatchStyles, /\.pdd-weighing-row:focus-within td/);
  assert.doesNotMatch(dispatchSource, /Índice de la pesada|Importe de la pesada|\.pdd-weighing-row small/);
});

test("en escritorio se ven cinco listas anchas y las restantes conservan el desplazamiento", () => {
  const baseGrid = dispatchStyles.match(/\.pdd-lists-grid\s*\{([^}]*)\}/)?.[1] || "";
  const tabletResponsive = sourceBetween(dispatchStyles, "@media (max-width: 1120px)", "@media (max-width: 960px)");
  const compactResponsive = sourceBetween(dispatchStyles, "@media (max-width: 860px)", "@media (max-width: 560px)");
  const mobileResponsive = sourceBetween(dispatchStyles, "@media (max-width: 560px)", "@media (max-width: 390px)");

  assert.match(baseGrid, /grid-auto-flow:\s*column/);
  assert.match(baseGrid, /grid-auto-columns:\s*calc\(20%\s*-\s*7\.2px\)/);
  assert.match(baseGrid, /gap:\s*9px/);
  assert.match(baseGrid, /overflow-x:\s*auto/);
  assert.match(tabletResponsive, /\.pdd-lists-grid\s*\{[^}]*grid-auto-columns:\s*minmax\(250px,\s*33%\)/);
  assert.match(compactResponsive, /\.pdd-lists-grid\s*\{[^}]*grid-auto-columns:\s*minmax\(255px,\s*54%\)/);
  assert.match(mobileResponsive, /\.pdd-lists-grid\s*\{[^}]*grid-auto-columns:\s*minmax\(245px,\s*88%\)/);
});

test("el documento queda fijo en escritorio y el zoom conserva el tamaño del viewport", () => {
  const desktopViewport = sourceBetween(dispatchStyles, "@media (min-width: 861px)", "@media (min-width: 961px)");
  const mobileLayout = sourceBetween(dispatchStyles, "@media (max-width: 860px)", "@media (max-width: 560px)");
  const appScaleFlow = sourceBetween(dispatchSource, "function applyAppScale", "function stepAppScale");

  assert.match(desktopViewport, /html\.product-dispatch-operation-root,\s*body\.pdd-page\s*\{[^}]*width:\s*100%;[^}]*height:\s*100%;[^}]*min-height:\s*0;[^}]*overflow:\s*hidden/);
  assert.match(desktopViewport, /\.pdd-station\s*\{[^}]*max-width:\s*100vw;[^}]*height:\s*100dvh;[^}]*overflow:\s*hidden/);
  assert.match(desktopViewport, /\.pdd-zoom-surface\s*\{[^}]*width:\s*var\(--pdd-app-layout-width,\s*100%\);[^}]*height:\s*var\(--pdd-app-layout-height-dvh,\s*100dvh\);[^}]*overflow:\s*hidden/);
  assert.match(appScaleFlow, /const layoutPercent\s*=\s*\(100\s*\/\s*scale\)\.toFixed\(6\)/);
  assert.match(appScaleFlow, /setProperty\("--pdd-app-layout-width",\s*`\$\{layoutPercent\}%`\)/);
  assert.match(appScaleFlow, /setProperty\("--pdd-app-layout-height-dvh",\s*`\$\{layoutPercent\}dvh`\)/);
  assert.match(appScaleFlow, /elements\.zoomSurface\.style\.zoom\s*=\s*String\(scale\)/);
  assert.match(appScaleFlow, /matchMedia\("\(min-width: 861px\)"\)\.matches\) window\.scrollTo\(0,\s*0\)/);
  assert.match(mobileLayout, /\.pdd-zoom-surface\s*\{[^}]*display:\s*block/);
  assert.match(dispatchStyles, /\.pdd-lists-grid\s*\{[^}]*overflow-x:\s*auto/);
  assert.match(dispatchStyles, /\.pdd-list-items\s*\{[^}]*overflow-y:\s*auto/);
});

test("Configuración permite buscar y guardar exactamente cuatro productos rápidos ordenados", () => {
  const settingsFlow = sourceBetween(dispatchSource, "function quickProductSettingVisual", "function renderWastePresetSettings");
  const settingsListeners = sourceBetween(dispatchSource, 'elements.openViewSettings.addEventListener', 'elements.wastePresetForm.addEventListener');

  for (const id of [
    "pddQuickProductForm",
    "pddQuickProductCount",
    "pddQuickProductSelection",
    "pddQuickProductSearch",
    "pddQuickProductResults",
    "pddSaveQuickProducts",
    "pddQuickProductStatus"
  ]) {
    assert.match(dispatchView, new RegExp(`id="${id}"`));
  }

  assert.match(settingsFlow, /while\s*\(selectedCards\.length\s*<\s*4\)/);
  assert.match(settingsFlow, /textContent\s*=\s*`\$\{selectedProducts\.length\}\/4`/);
  assert.match(settingsFlow, /saveQuickProducts\.disabled\s*=\s*state\.quickProductSaving\s*\|\|\s*selectedProducts\.length\s*!==\s*4/);
  assert.match(settingsFlow, /state\.quickProductSelection\.push\(id\)/);
  assert.match(settingsFlow, /body:\s*JSON\.stringify\(\{\s*quick_product_ids:\s*proposed\s*\}\)/);
  assert.match(settingsFlow, /state\.catalog\.quick_product_ids\s*=\s*normalizeQuickProductIds/);
  assert.match(settingsFlow, /renderQuickProducts\(\)/);
  assert.match(settingsListeners, /resetQuickProductSettings\(\)/);
  assert.match(settingsListeners, /quickProductSearch\.addEventListener\("input"/);
  assert.match(settingsListeners, /data-pdd-quick-setting-remove/);
  assert.match(settingsListeners, /data-pdd-quick-setting-product/);
  assert.match(dispatchStyles, /\.pdd-quick-product-selection\s*\{[^}]*repeat\(4/);
  assert.match(dispatchStyles, /\.pdd-quick-product-results\s*\{[^}]*grid-template-columns:\s*1fr\s+1fr/);
});

test("las imágenes rápidas tienen un respaldo visual propio", () => {
  const imageFallbackFlow = sourceBetween(dispatchSource, 'document.addEventListener("error"', 'document.addEventListener("click"');

  assert.match(dispatchSource, /data-pdd-quick-image-fallback/);
  assert.match(imageFallbackFlow, /pddQuickImageFallback/);
  assert.match(imageFallbackFlow, /pdd-quick-product-placeholder/);
  assert.match(imageFallbackFlow, /productInitial\(image\.dataset\.pddQuickImageFallback\)/);
  assert.match(imageFallbackFlow, /pddQuickSettingImageFallback/);
  assert.match(imageFallbackFlow, /pdd-quick-product-setting-placeholder/);
});

test("las variaciones ahorran espacio y muestran solamente una imagen más grande con el nombre", () => {
  const variationsRender = sourceBetween(dispatchSource, "function renderVariations", "function captureValues");

  assert.doesNotMatch(dispatchView, /pdd-variations-heading/);
  assert.doesNotMatch(variationsRender, /<small>|formatMoney\(variation\.price|priceModeLabel\(variation\.price_mode/);
  assert.match(variationsRender, /\$\{visual\}<b>\$\{escapeHtml\(variation\.name\)\}<\/b>/);
  assert.match(dispatchStyles, /\.pdd-variation-option\s*\{[^}]*min-height:\s*50px/);
  assert.match(dispatchStyles, /\.pdd-variation-option img,\s*\.pdd-variation-option i\s*\{[^}]*width:\s*40px;\s*height:\s*40px/);
  assert.doesNotMatch(dispatchStyles, /\.pdd-variation-option small\s*\{/);
  assert.doesNotMatch(dispatchSource, /Título Variaciones|Precio y detalle/);
});

test("el peso neto queda como lectura principal y el bruto de balanza en el recuadro verde", () => {
  const previewFlow = sourceBetween(
    dispatchSource,
    "function renderCapturePreview",
    "function renderScale"
  );
  const wasteTotalFlow = sourceBetween(
    dispatchSource,
    "function syncWasteTotal",
    "function setWastePerUnit"
  );

  assert.match(dispatchView, /id="pddLiveWeight"[^>]*aria-label="Peso neto actual"/);
  assert.match(dispatchView, /class="pdd-gross-preview"[\s\S]*?<span>Peso bruto<\/span>[\s\S]*?id="pddGrossPreview"/);
  assert.doesNotMatch(dispatchView, /id="pddNetPreview"|class="pdd-net-preview"/);
  assert.match(previewFlow, /elements\.liveWeight\.innerHTML\s*=\s*`\$\{hasDisplayedWeight\s*\?\s*line\.net_weight_kg\.toFixed\(3\)/);
  assert.match(previewFlow, /elements\.grossPreview\.textContent[\s\S]*formatWeight\(scaleWeight\)/);
  assert.match(dispatchUtilsSource, /wasteTotalGrams\s*=\s*readWeightKg\s*>\s*0\s*\?\s*wasteGramsPerUnit\s*\*\s*quantity\s*:\s*0/);
  assert.match(wasteTotalFlow, /calculateLine\(\{[\s\S]*read_weight_kg:\s*Number\.isFinite\(scaleWeight\)\s*\?\s*scaleWeight\s*:\s*0[\s\S]*\}\)\.waste_total_grams/);
  assert.match(dispatchStyles, /\.pdd-gross-preview\s*\{[^}]*background:\s*linear-gradient/);
});

test("el peso manual se calcula como neto directo sin merma ni tara", () => {
  const calculationFlow = sourceBetween(
    dispatchSource,
    "function calculationValuesForReading",
    "function resetCaptureQuantity"
  );
  const manualDialog = sourceBetween(
    dispatchView,
    '<dialog id="pddManualDialog"',
    '<dialog id="pddClientDialog"'
  );

  assert.match(calculationFlow, /weight_source:\s*isManualReading\(scaleState\)\s*\?\s*"MANUAL"/);
  assert.match(dispatchUtilsSource, /String\(input\.weight_source\s*\|\|\s*""\)\.toUpperCase\(\)\s*===\s*"MANUAL"/);
  assert.match(dispatchUtilsSource, /waste_grams_per_unit:\s*0,[\s\S]*waste_total_grams:\s*0,[\s\S]*tare_grams:\s*0/);
  assert.match(manualDialog, /<span>Peso neto<\/span>/);
  assert.match(manualDialog, /directamente como peso neto, sin aplicar merma ni tara/i);
});

test("cambiar producto o subproducto restablece la cantidad a cero", () => {
  const resetFlow = sourceBetween(dispatchSource, "function resetCaptureQuantity", "function createPendingManualReading");
  const productSelection = sourceBetween(dispatchSource, "function selectProduct", "function selectVariation");
  const variationSelection = sourceBetween(dispatchSource, "function selectVariation", "function capturedReadingIds");
  const editSelection = sourceBetween(dispatchSource, "function changeEditingProduct", "function saveEditingItem");

  assert.match(resetFlow, /elements\.quantity\.value\s*=\s*"0"/);
  assert.match(productSelection, /resetCaptureQuantity\(\)/);
  assert.match(variationSelection, /resetCaptureQuantity\(\)/);
  assert.match(editSelection, /elements\.editQuantity\.value\s*=\s*"0"/);
});

test("la barra lateral ya no muestra el cuadro de lista activa", () => {
  assert.doesNotMatch(dispatchView, /pdd-active-list-badge|id="pddActiveList"|>Lista activa</i);
  assert.doesNotMatch(dispatchSource, /activeList:\s*document\.querySelector|elements\.activeList/);
  assert.doesNotMatch(dispatchStyles, /\.pdd-active-list-badge/);
});

test("la barra inferior se elimina sin reservar espacio y los errores conservan un aviso flotante", () => {
  assert.doesNotMatch(dispatchView, /pdd-statusbar|pdd-footer-summary|pddFooter(?:Weighings|Quantity|Waste|Net)/);
  assert.match(dispatchView, /id="pddMessage"\s+class="pdd-message-live"\s+role="status"\s+aria-live="polite"/);
  assert.doesNotMatch(dispatchSource, /footerWeighings|footerQuantity|footerWaste|footerNet/);
  assert.doesNotMatch(dispatchSource, /Mensaje de estado|Resumen inferior|--pdd-fs-footer-(?:message|summary)/);
  assert.doesNotMatch(dispatchStyles, /\.pdd-statusbar|\.pdd-footer-summary|--pdd-fs-footer-(?:message|summary)/);
  assert.match(dispatchStyles, /\.pdd-message-live\s*\{[^}]*position:\s*absolute;[^}]*width:\s*1px;[^}]*height:\s*1px/);
  assert.match(dispatchStyles, /\.pdd-message-live\.is-error\s*\{[^}]*position:\s*fixed/);
});

test("el teclado de precio anidado conserva el foco de cada diálogo", () => {
  const dialogFlow = sourceBetween(dispatchSource, "function openDialog", "function normalizeAppScale");
  const dialogListeners = sourceBetween(dispatchSource, 'document.querySelectorAll(".pdd-dialog")', 'document.addEventListener("keydown"');

  assert.match(dispatchSource, /dialogFocus:\s*new WeakMap\(\)/);
  assert.match(dialogFlow, /state\.dialogFocus\.set\(dialog,\s*document\.activeElement\)/);
  assert.match(dialogListeners, /state\.dialogFocus\.get\(dialog\)/);
  assert.match(dialogListeners, /state\.dialogFocus\.delete\(dialog\)/);
  assert.doesNotMatch(dispatchSource, /state\.lastFocus/);
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
  const netValidationFlow = sourceBetween(
    dispatchSource,
    "function captureNetValidation",
    "function captureValidation"
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
  assert.match(netValidationFlow, /waste_total_grams\s*>\s*PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS/);
  assert.match(netValidationFlow, /values\.tare_grams\s*>=\s*Math\.round\(weightKg\s*\*\s*1000\)\s*\+\s*values\.waste_total_grams/);
  assert.doesNotMatch(netValidationFlow, /values\.waste_total_grams\s*\+\s*values\.tare_grams\s*>=/);
  assert.match(validationFlow, /const netValidation\s*=\s*captureNetValidation\(weightKg,\s*values\)/);
  assert.match(previewFlow, /const validation\s*=\s*captureValidation\(/);
  assert.match(previewFlow, /captureReady[\s\S]*&&\s*!validation\.message[\s\S]*captureWeight\.disabled\s*=\s*!captureReady/);
  assert.match(
    addReadingFlow,
    /const validation\s*=\s*captureValidation\(weight,\s*selection,\s*values\);\s*if\s*\(validation\.message\)\s*\{[\s\S]*validation\.target\?\.focus\(\);[\s\S]*setMessage\(validation\.message,\s*["']error["']\);[\s\S]*return false;/
  );
  assert.ok(
    addReadingFlow.indexOf("const validation = captureValidation(weight, selection, values)")
      < addReadingFlow.indexOf("const calculated = calculateLine"),
    "La merma máxima o una tara fuera de rango deben rechazarse antes de insertar la pesada."
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

test("la configuración guarda el título exclusivo de la pantalla cliente", () => {
  const setting = sourceBetween(
    dispatchView,
    '<form id="pddCustomerDisplayTitleForm"',
    '<div class="pdd-theme-setting">'
  );
  const saveFlow = sourceBetween(
    dispatchSource,
    "async function saveCustomerDisplayTitle",
    "async function loadCatalog"
  );

  assert.match(setting, /id="pddCustomerDisplayTitle"[^>]*maxlength="120"[^>]*required/);
  assert.match(setting, /id="pddSaveCustomerDisplayTitle"[^>]*type="submit"/);
  assert.match(saveFlow, /JSON\.stringify\(\{\s*customer_display_title:\s*proposed\s*\}\)/);
  assert.match(saveFlow, /state\.catalog\.customer_display_title/);
  assert.match(saveFlow, /publishProductCustomerDisplayState\(true\)/);
});

test("la estación publica la lista activa y el neto calculado en una pantalla aislada", () => {
  const builderFlow = sourceBetween(
    dispatchSource,
    "function buildCurrentProductCustomerDisplayState",
    "function flushProductCustomerDisplayStorage"
  );
  const openFlow = sourceBetween(
    dispatchSource,
    "function openProductCustomerDisplay",
    "function blockCaptureBriefly"
  );

  assert.match(dispatchView, /id="pddOpenCustomerDisplay"/);
  assert.match(dispatchView, /route\('despacho-productos\.pantalla-cliente'\)/);
  assert.match(builderFlow, /const draft\s*=\s*activeDraft\(\)/);
  assert.match(builderFlow, /const totals\s*=\s*calculateDraft\(draft\.items\)/);
  assert.match(builderFlow, /const line\s*=\s*calculateLine\(/);
  assert.match(builderFlow, /resolveProductDispatchCustomerDisplayPreview\(/);
  assert.match(builderFlow, /calculationAvailable:\s*Boolean\(!netValidation\.message\s*&&\s*line\.net_weight_kg\s*>\s*0\)/);
  assert.match(builderFlow, /amountAvailable:\s*Boolean\(selection\s*&&\s*!validation\.message\s*&&\s*line\.amount\s*>=\s*0\.01\)/);
  assert.match(builderFlow, /rows:\s*draft\.items\.map/);
  assert.match(builderFlow, /name:\s*itemDisplayName\(item\)/);
  assert.match(builderFlow, /netWeightKg:\s*calculated\.net_weight_kg/);
  assert.match(builderFlow, /amount:\s*calculated\.amount/);
  assert.doesNotMatch(builderFlow, /scale_reading|read_weight_kg:\s*calculated|tare_grams|waste_total_grams/);
  assert.match(openFlow, /searchParams\.set\("source"/);
  assert.match(openFlow, /searchParams\.set\("branch"/);
  assert.match(openFlow, /searchParams\.set\("user"/);
  assert.match(openFlow, /scrollbars=no/);
  assert.match(
    dispatchSource,
    /PRODUCT_CUSTOMER_DISPLAY_PRODUCER_ID\s*=\s*`\$\{PRODUCT_CUSTOMER_DISPLAY_PRODUCER_BASE_ID\}-\$\{PRODUCT_CUSTOMER_DISPLAY_PRODUCER_INSTANCE\}`/
  );
  assert.match(
    dispatchSource,
    /window\.addEventListener\("pagehide",\s*\(event\)\s*=>\s*\{[\s\S]*if\s*\(!event\.persisted\)\s*resetProductCustomerDisplay\(\)/
  );
});

test("la balanza se reconstruye al volver desde la caché de navegación", () => {
  const factoryFlow = sourceBetween(
    dispatchSource,
    "function createProductScaleController",
    "state.scale = createProductScaleController()"
  );
  const pageShowFlow = sourceBetween(
    dispatchSource,
    'window.addEventListener("pageshow"',
    'window.addEventListener("pagehide"'
  );
  const pageHideFlow = sourceBetween(
    dispatchSource,
    'window.addEventListener("pagehide"',
    'document.addEventListener("visibilitychange"'
  );

  assert.match(factoryFlow, /new RetailScaleController\(/);
  assert.match(pageShowFlow, /if\s*\(!event\.persisted\)\s*return/);
  assert.match(pageShowFlow, /state\.scale\s*=\s*createProductScaleController\(\)/);
  assert.match(pageShowFlow, /configureProductScaleForCurrentBranch\(\)/);
  assert.match(pageShowFlow, /state\.scale\.getState\(\)\.autoConnectMode[\s\S]*restoreScale\(\)/);
  assert.match(pageHideFlow, /state\.scale\.destroy\(\)/);
});
