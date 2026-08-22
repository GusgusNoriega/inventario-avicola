import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../public/js/recepcion-pollo-vivo.js", import.meta.url),
  "utf8",
);
const view = readFileSync(
  new URL("../../resources/views/recepcion-pollo-vivo.blade.php", import.meta.url),
  "utf8",
);
const stylesheet = readFileSync(
  new URL("../../public/css/recepcion-pollo-vivo.css", import.meta.url),
  "utf8",
);

test("los dos botones de propietario usan exactamente los valores aceptados por la API", () => {
  assert.match(view, /data-live-owner="PROPIA"/);
  assert.match(view, /data-live-owner="EXTERNA"/);
  assert.match(source, /ownerType:\s*"PROPIA"/);
  assert.match(source, /state\.ownerType === "EXTERNA"\s*\? defaultExternalOwnerId : null/);
  assert.match(source, /totals\?\.external/);
  assert.match(view, /id="liveIntakeExternalBirds"/);
  assert.doesNotMatch(view, /data-live-owner="(?:OWN|EXTERNAL)"/);
});

test("las cuatro columnas mantienen dos entradas y dos despachos en retrato", () => {
  assert.match(view, /@for \(\$lane = 1; \$lane <= 4; \$lane\+\+\)/);
  assert.match(view, /\$warehouseLane = \$lane <= 2/);
  assert.match(stylesheet, /@media \(max-width: 820px\) and \(orientation: portrait\)[\s\S]*?\.lir-lanes \{[^}]*grid-template-columns: 1fr 1fr/);
  assert.match(stylesheet, /\.lir-lane\.is-warehouse \{ height: 38vh; \}/);
  assert.match(stylesheet, /\.lir-lane\.is-client \{ height: 28vh; \}/);
});

test("los totales se apoyan en el final real de cada columna", () => {
  assert.match(stylesheet, /\.lir-lane \{[^}]*display: grid;[^}]*grid-template-rows: auto minmax\(0, 1fr\) auto;/);
  assert.match(stylesheet, /\.lir-lane-rows, \.lir-lane\.is-warehouse \.lir-lane-rows \{[^}]*height: auto;[^}]*min-height: 0;[^}]*overflow-y: auto;/);
  assert.match(stylesheet, /\.lir-lane footer \{ position: static;/);
  assert.match(stylesheet, /\.lir-selected-total \{ position: static;/);
});

test("la configuración de balanza vive en su propio popup", () => {
  const scalePanel = view.match(/<section class="lir-scale-panel"[\s\S]*?<\/section>/)?.[0] || "";
  const generalSettings = view.match(/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";
  const scaleSettings = view.match(/<form id="liveIntakeScaleSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";

  assert.match(scalePanel, /id="liveIntakeOpenScaleSettings"[\s\S]*?aria-controls="liveIntakeScaleSettingsModal"[\s\S]*?aria-expanded="false"/);
  assert.doesNotMatch(scalePanel, /liveIntakeConnect(?:Ble|Serial)|liveIntakeDisconnectScale/);
  assert.doesNotMatch(generalSettings, /liveIntake(?:ConnectBle|ConnectSerial|DisconnectScale|BaudRate|DataBits|StopBits|Parity|FlowControl)/);
  assert.match(scaleSettings, /role="dialog" aria-modal="true"/);
  assert.match(scaleSettings, /liveIntakeConnectBle/);
  assert.match(scaleSettings, /liveIntakeConnectSerial/);
  assert.match(scaleSettings, /liveIntakeDisconnectScale/);
  assert.match(scaleSettings, /liveIntakeBaudRate/);
  assert.match(source, /function openScaleSettings\(\)/);
  assert.match(source, /elements\.scaleSettingsForm\.addEventListener\("submit", saveScaleSettings\)/);
});

test("el peso manual se abre desde la lectura y no desde la configuración general", () => {
  const scalePanel = view.match(/<section class="lir-scale-panel"[\s\S]*?<\/section>/)?.[0] || "";
  const generalSettings = view.match(/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";
  const manualForm = view.match(/<form id="liveIntakeManualWeightForm"[\s\S]*?<\/form>/)?.[0] || "";

  assert.match(scalePanel, /liveIntakeScaleWeight[\s\S]*?liveIntakeOpenManualWeight/);
  assert.match(scalePanel, /liveIntakeOpenManualWeight[\s\S]*?aria-controls="liveIntakeManualWeightModal"/);
  assert.doesNotMatch(generalSettings, /liveIntakeManualWeight|liveIntakeApplyManualWeight/);
  assert.match(manualForm, /role="dialog" aria-modal="true"/);
  assert.match(manualForm, /id="liveIntakeManualWeight"/);
  assert.match(manualForm, /id="liveIntakeApplyManualWeight"[\s\S]*?type="submit"/);
  assert.match(source, /elements\.manualWeightForm\.addEventListener\("submit", applyManualWeight\)/);
  assert.match(source, /state\.scale\.setManualReading\(elements\.manualWeight\.value\)/);
  assert.match(source, /setMessage\(`Peso manual de \$\{appliedWeight\} kg listo para registrar\.`/);
});

test("el zoom de recepción es independiente y respeta sus niveles", () => {
  assert.match(source, /const ZOOM_LEVELS = \[67, 75, 80, 90, 100, 110, 125, 150\]/);
  assert.match(source, /sistema-pollos-recepcion-pollo-vivo-zoom-v1/);
  assert.match(source, /document\.documentElement\.style\.zoom = String\(normalized \/ 100\)/);
  assert.match(source, /event\.key === ZOOM_STORAGE_KEY/);
});

test("la captura usa una sola balanza y guarda cada pesada inmediatamente", () => {
  assert.match(source, /new RetailScaleController\(/);
  assert.equal((source.match(/new RetailScaleController\(/g) || []).length, 1);
  assert.match(source, /apiRequest\("\/recepcion-pollo-vivo\/pesadas", \{[\s\S]*?method: "POST"/);
  assert.match(source, /BALANZA_RECEPCION_POLLO_VIVO/);
  assert.match(source, /state\.pendingCapture/);
});
