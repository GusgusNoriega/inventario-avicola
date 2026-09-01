import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const stylesheet = readFileSync(
  new URL("../../public/css/despacho-productos.css", import.meta.url),
  "utf8"
);

function declarationsFor(selector) {
  const start = stylesheet.indexOf(`${selector} {`);
  assert.notEqual(start, -1, `No se encontró la regla ${selector}.`);

  const bodyStart = stylesheet.indexOf("{", start) + 1;
  const bodyEnd = stylesheet.indexOf("}", bodyStart);
  return stylesheet.slice(bodyStart, bodyEnd);
}

test("el catálogo permite desplazar la página cuando crece la lista de productos", () => {
  const catalogPage = declarationsFor("body.product-catalog-page");

  assert.match(stylesheet, /html\.product-dispatch-root,\s*body\.product-dispatch-menu-page,\s*body\.product-catalog-page\s*\{/);
  assert.match(catalogPage, /height:\s*auto/);
  assert.match(catalogPage, /overflow-x:\s*hidden/);
  assert.match(catalogPage, /overflow-y:\s*auto/);
});

test("el formulario de producto conserva su desplazamiento interno", () => {
  const editorScroll = declarationsFor(".product-editor-scroll");

  assert.match(editorScroll, /overflow-y:\s*auto/);
});
