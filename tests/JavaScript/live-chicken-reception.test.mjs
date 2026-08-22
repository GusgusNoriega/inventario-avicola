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
  assert.match(stylesheet, /\.lir-lane\.is-warehouse \{ min-height: 38vh; \}/);
  assert.match(stylesheet, /\.lir-lane\.is-client \{ min-height: 28vh; \}/);
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
