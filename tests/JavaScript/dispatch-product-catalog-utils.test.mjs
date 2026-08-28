import assert from "node:assert/strict";
import test from "node:test";

import {
  PRICE_MODE_KG,
  PRICE_MODE_UNIT,
  escapeHtml,
  formatSalePrice,
  imageFileError,
  productInitial
} from "../../public/js/dispatch-product-catalog-utils.js";

test("el precio del catálogo indica claramente si se cobra por kg o unidad", () => {
  assert.equal(formatSalePrice("8.5000", PRICE_MODE_KG), "S/ 8.50 / kg");
  assert.equal(formatSalePrice("0.7500", PRICE_MODE_UNIT), "S/ 0.75 / unidad");
});

test("los nombres y textos se renderizan sin permitir HTML", () => {
  assert.equal(productInitial("gallina roja"), "GR");
  assert.equal(escapeHtml('<img src=x onerror="alert(1)">'), "&lt;img src=x onerror=&quot;alert(1)&quot;&gt;");
});

test("la validación de imágenes acepta formatos seguros y limita el tamaño", () => {
  assert.equal(imageFileError({ type: "image/webp", size: 1024 }), "");
  assert.match(imageFileError({ type: "image/svg+xml", size: 1024 }), /JPG, PNG o WEBP/);
  assert.match(imageFileError({ type: "image/png", size: 5 * 1024 * 1024 }), /4 MB/);
});
