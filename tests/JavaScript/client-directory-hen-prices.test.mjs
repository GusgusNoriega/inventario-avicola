import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const directorySource = readFileSync(
  new URL("../../public/js/clientes.js", import.meta.url),
  "utf8"
);
const directoryView = readFileSync(
  new URL("../../resources/views/directorio.blade.php", import.meta.url),
  "utf8"
);

test("client directory exposes both optional hen prices", () => {
  assert.match(
    directorySource,
    /key:\s*"gallina_roja",[\s\S]*?apiKey:\s*"GALLINA_ROJA",[\s\S]*?inputId:\s*"priceGallinaRoja",[\s\S]*?clientOnly:\s*true/
  );
  assert.match(
    directorySource,
    /key:\s*"gallina_doble",[\s\S]*?apiKey:\s*"GALLINA_DOBLE",[\s\S]*?inputId:\s*"priceGallinaDoble",[\s\S]*?clientOnly:\s*true/
  );
  assert.match(
    directorySource,
    /function getPriceFields\(type = activeType\) \{[\s\S]*?!field\.clientOnly \|\| type === "clientes"/
  );

  for (const inputId of ["priceGallinaRoja", "priceGallinaDoble"]) {
    assert.match(
      directoryView,
      new RegExp(`id="${inputId}"[^>]*min="0\\.01"[^>]*step="0\\.01"[^>]*placeholder="Vacío: usa el precio del ticket"`)
    );
  }
});

test("hen prices stay out of providers and join client-wide adjustments", () => {
  assert.match(
    directorySource,
    /const prices = getPriceFields\(\)\.reduce\([\s\S]*?acc\[field\.apiKey\]/
  );
  assert.match(
    directorySource,
    /field\.hidden = !isClient;[\s\S]*?option\.hidden = !isClient;[\s\S]*?option\.disabled = !isClient;/
  );
  assert.match(
    directorySource,
    /const activePriceFields = getPriceFields\(\);[\s\S]*?tipo_pollo:\s*field\.apiKey/
  );

  assert.match(
    directoryView,
    /<option value="gallina_roja" data-client-price-option>Gallina roja<\/option>/
  );
  assert.match(
    directoryView,
    /<option value="gallina_doble" data-client-price-option>Gallina doble<\/option>/
  );
});
