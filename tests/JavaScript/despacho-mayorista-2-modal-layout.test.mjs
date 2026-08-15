import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const stylesheet = readFileSync(
  new URL("../../public/css/despacho-mayorista-2.css", import.meta.url),
  "utf8"
);

function declarationsFor(selector) {
  const start = stylesheet.indexOf(`${selector} {`);
  assert.notEqual(start, -1, `No se encontró la regla ${selector}.`);

  const bodyStart = stylesheet.indexOf("{", start) + 1;
  const bodyEnd = stylesheet.indexOf("}", bodyStart);
  return stylesheet.slice(bodyStart, bodyEnd);
}

test("el editor de pesadas mantiene sus acciones visibles y desplaza todos los campos", () => {
  const card = declarationsFor(".item-modal-card");
  const form = declarationsFor(".item-form");
  const fields = declarationsFor(".item-form-grid");

  assert.match(card, /width:\s*min\(900px,\s*100%\)/);
  assert.match(card, /max-height:\s*100%/);
  assert.match(card, /height:\s*min\(760px,\s*100%\)/);
  assert.match(card, /grid-template-rows:\s*auto auto minmax\(0,\s*1fr\)/);
  assert.match(card, /overflow:\s*hidden/);

  assert.match(form, /grid-template-rows:\s*minmax\(0,\s*1fr\) auto auto/);
  assert.match(form, /overflow:\s*hidden/);

  assert.match(fields, /overflow-x:\s*hidden/);
  assert.match(fields, /overflow-y:\s*auto/);
  assert.match(fields, /-webkit-overflow-scrolling:\s*touch/);
  assert.match(fields, /overscroll-behavior:\s*contain/);
});

test("el editor conserva dos columnas en tablet y evita desborde horizontal en móvil", () => {
  const tabletStart = stylesheet.indexOf("@media (min-width: 641px) and (max-width: 980px)");
  const mobileStart = stylesheet.indexOf("@media (max-width: 640px)", tabletStart);

  assert.notEqual(tabletStart, -1, "Falta el ajuste específico para tablet.");
  assert.notEqual(mobileStart, -1, "Falta el ajuste específico para móvil.");

  const tabletRules = stylesheet.slice(tabletStart, mobileStart);
  const mobileRules = stylesheet.slice(mobileStart, stylesheet.indexOf("/* Cliente por camión */", mobileStart));

  assert.match(tabletRules, /\.item-highlight\s*\{[^}]*repeat\(3,/s);
  assert.match(tabletRules, /\.item-form-grid\s*\{[^}]*repeat\(2,/s);
  assert.match(tabletRules, /\.item-form-actions\s*\{[^}]*flex-direction:\s*row/s);
  assert.match(mobileRules, /\.item-form-grid \.weight-adjustment-preview\s*\{[^}]*grid-column:\s*1 \/ -1/s);
});
