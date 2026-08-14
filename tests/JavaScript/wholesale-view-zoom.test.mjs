import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = readFileSync(
  new URL("../../public/js/app.js", import.meta.url),
  "utf8"
);

function loadZoomHelpers() {
  const start = source.indexOf("export function normalizeViewZoomLevel");
  const end = source.indexOf("function loadViewZoomPreference", start);

  assert.notEqual(start, -1, "No se encontró normalizeViewZoomLevel.");
  assert.notEqual(end, -1, "No se encontró loadViewZoomPreference.");

  const helpers = source
    .slice(start, end)
    .replaceAll("export function", "function");

  return new Function(`
    const VIEW_ZOOM_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
    const DEFAULT_VIEW_ZOOM = 100;
    ${helpers}
    return { normalizeViewZoomLevel, getAdjacentViewZoomLevel };
  `)();
}

const zoom = loadZoomHelpers();

test("el zoom mayorista acepta únicamente los niveles disponibles", () => {
  assert.equal(zoom.normalizeViewZoomLevel("75"), 75);
  assert.equal(zoom.normalizeViewZoomLevel(125), 125);
  assert.equal(zoom.normalizeViewZoomLevel("83"), 100);
  assert.equal(zoom.normalizeViewZoomLevel(null), 100);
});

test("los botones de zoom avanzan por niveles y respetan los límites", () => {
  assert.equal(zoom.getAdjacentViewZoomLevel(100, -1), 90);
  assert.equal(zoom.getAdjacentViewZoomLevel(100, 1), 110);
  assert.equal(zoom.getAdjacentViewZoomLevel(67, -1), 67);
  assert.equal(zoom.getAdjacentViewZoomLevel(150, 1), 150);
  assert.equal(zoom.getAdjacentViewZoomLevel(100, 0), 100);
});
