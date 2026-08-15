import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
  buildWeightControlTicketHtml as buildWholesaleTwoTicketHtml
} from "../../public/js/despacho-mayorista-2-ticket-printer.js";

const wholesaleTwoSource = readFileSync(
  new URL("../../public/js/despacho-mayorista-2.js", import.meta.url),
  "utf8"
);
const wholesaleOneSource = readFileSync(
  new URL("../../public/js/app.js", import.meta.url),
  "utf8"
);
const wholesaleTwoView = readFileSync(
  new URL("../../resources/views/despacho-mayorista-2.blade.php", import.meta.url),
  "utf8"
);
const wholesaleTwoCss = readFileSync(
  new URL("../../public/css/despacho-mayorista-2.css", import.meta.url),
  "utf8"
);

const dressedVariantCodes = [
  "MACHO_ABIERTO",
  "MACHO_CERRADO",
  "HEMBRA_ABIERTA",
  "HEMBRA_CERRADA",
  "POLLO_BENEFICIADO"
];

test("mayorista 2 expone M/H solo para vivo y cinco clasificaciones para pelado", () => {
  assert.match(wholesaleTwoView, /id="liveSexSelector"[\s\S]*data-sex="macho"[\s\S]*data-sex="hembra"/);
  assert.match(wholesaleTwoView, /id="dressedVariantSelector"[^>]*hidden/);
  assert.match(wholesaleTwoView, /id="editDressedVariantSelector"[^>]*hidden/);

  for (const code of dressedVariantCodes) {
    assert.match(wholesaleTwoView, new RegExp(`data-dressed-variant="${code}"`));
    assert.match(wholesaleTwoView, new RegExp(`data-edit-dressed-variant="${code}"`));
    assert.match(wholesaleTwoSource, new RegExp(`code: "${code}"`));
  }

  assert.match(wholesaleTwoCss, /\.dressed-variant-buttons[\s\S]*repeat\(5,/);
});

test("el payload M2 usa chicken_variant_code y beneficiado admite sexo nulo", () => {
  assert.match(wholesaleTwoSource, /chicken_variant_code:\s*chickenVariant\.code/);
  assert.match(wholesaleTwoSource, /chicken_sex:\s*chickenVariant\.sex\s*\?[^\n]+:\s*null/);
  assert.match(wholesaleTwoSource, /typeId:\s*"pollo_beneficiado",\s*sex:\s*null,\s*presentation:\s*null/);
  assert.match(wholesaleTwoSource, /code:\s*"MACHO"[^\n]+presentation:\s*null/);
  assert.match(wholesaleTwoSource, /code:\s*"HEMBRA"[^\n]+presentation:\s*null/);
  assert.match(wholesaleTwoSource, /gross_weight_kg:\s*roundBusinessWeight/);
  assert.match(wholesaleTwoSource, /read_weight_kg:\s*roundBusinessWeight/);
});

test("la vista M2 configura seis mermas y mantiene beneficiado bloqueado en cero", () => {
  assert.match(wholesaleTwoView, /id="openWeightAdjustmentSettingsBtn"/);
  assert.match(wholesaleTwoView, /id="weightAdjustmentSettingsModal"/);
  assert.equal((wholesaleTwoView.match(/data-weight-adjustment-variant=/g) || []).length, 6);
  assert.equal((wholesaleTwoView.match(/max="1000000"[^>]*data-weight-adjustment-variant=/g) || []).length, 6);

  for (const code of [
    "MACHO",
    "HEMBRA",
    "MACHO_ABIERTO",
    "MACHO_CERRADO",
    "HEMBRA_ABIERTA",
    "HEMBRA_CERRADA"
  ]) {
    assert.match(wholesaleTwoView, new RegExp(`data-weight-adjustment-variant="${code}"`));
  }

  assert.match(wholesaleTwoView, /PB · Pollo beneficiado[\s\S]*value="0" disabled/);
  assert.match(wholesaleTwoSource, /apiRequest\("\/despacho-mayorista-2\/configuracion-mermas"/);
  assert.match(wholesaleTwoSource, /code:\s*String\(input\.dataset\.weightAdjustmentVariant/);
  assert.match(wholesaleTwoSource, /additional_grams:\s*grams/);
  assert.match(wholesaleTwoSource, /adjustments\.length !== 6/);
  assert.match(wholesaleTwoSource, /recalculatePendingWeightAdjustments/);
  assert.match(wholesaleTwoCss, /\.weight-adjustment-settings-grid/);
});

test("los totales inferiores separan conteos y explican la formula del peso neto", () => {
  assert.match(wholesaleTwoSource, /class="selected-truck-counts"/);
  assert.match(wholesaleTwoSource, /class="selected-truck-weight-flow"/);
  assert.match(wholesaleTwoSource, /class="selected-truck-operator" aria-hidden="true">\+</);
  assert.match(wholesaleTwoSource, /class="selected-truck-operator" aria-hidden="true">−</);
  assert.match(wholesaleTwoSource, /class="selected-truck-stat is-net"/);
  assert.match(wholesaleTwoSource, /classList\.toggle\(\s*"has-type-breakdown"/);
  assert.match(wholesaleTwoCss, /#selectedTruckDetails[\s\S]*grid-template-areas:\s*"ticket summary actions"/);
  assert.match(wholesaleTwoCss, /\.selected-truck-weight-flow[\s\S]*grid-template-columns:/);
  assert.match(wholesaleTwoCss, /\.selected-truck-stat\.is-net[\s\S]*border-color:/);
  assert.doesNotMatch(wholesaleTwoCss, /\.selected-truck-summary\s*\{\s*grid-template-columns:\s*repeat\(8,/);
  assert.match(
    wholesaleTwoCss,
    /@media \(min-width: 1280px\) and \(max-width: 1365px\) \{\s*\.truck-grid \{\s*grid-template-columns:\s*repeat\(10, calc\(25% - 6px\)\);/
  );
  assert.match(
    wholesaleTwoCss,
    /@media \(max-width: 1279px\) \{[\s\S]*?#selectedTruckDetails \{[\s\S]*?"ticket actions"\s*"summary summary"/
  );
  assert.doesNotMatch(
    wholesaleTwoCss,
    /@media \(max-width: 1365px\) \{\s*#selectedTruckDetails/
  );
});

test("despacho directo omite origin y solo exige jornada cuando hay orígenes", () => {
  assert.match(wholesaleTwoView, /Despacho directo/);
  assert.match(wholesaleTwoView, /Sin proveedor ni placa/);
  assert.match(wholesaleTwoSource, /if \(hasOrigin\) \{\s*payload\.origin\s*=/);
  assert.match(wholesaleTwoSource, /truckHasMerchandiseOrigins\(truck\) && !isJourneyConfigured\(\)/);
  assert.match(wholesaleTwoSource, /const available = !receptionMode \|\| isJourneyConfigured\(\)/);
  assert.match(
    wholesaleTwoSource,
    /function captureWeightForRegistration[\s\S]*entryOriginRequiresJourney\(\) && !isJourneyConfigured\(\)/
  );
  assert.match(
    wholesaleTwoSource,
    /function addCage[\s\S]*entryOriginRequiresJourney\(\) && !isJourneyConfigured\(\)/
  );
  assert.match(wholesaleTwoSource, /originId:\s*origin\?\.id \|\| null/);
  assert.doesNotMatch(
    wholesaleTwoSource,
    /Selecciona un proveedor o almacén de origen antes de agregar la pesada/
  );
});

test("tabla e impresión distinguen las siete clasificaciones sin alterar mayorista 1", () => {
  for (const code of ["PV-M", "PV-H", "MA", "MC", "HA", "HC", "PB"]) {
    assert.match(wholesaleTwoSource, new RegExp(`shortLabel: "${code}"`));
  }

  assert.match(wholesaleTwoSource, /getTicketTypeCode\(cage, operationType\)/);
  assert.match(wholesaleTwoSource, /getCageChickenVariantMeta\(cage\)\.shortLabel/);
  assert.match(wholesaleTwoSource, /cage\.chickenVariantCode\s*=\s*chickenVariant\.code/);
  assert.match(wholesaleTwoSource, /typeCode:\s*getTicketTypeCode\(cage, operationType\)/);
  assert.match(wholesaleTwoSource, /`\$\{typeMeta\.shortLabel\} · \$\{typeMeta\.label\}`/);
  assert.match(wholesaleTwoSource, /\$\{item\.shortLabel\} · \$\{item\.label\}/);
  assert.doesNotMatch(wholesaleOneSource, /chicken_variant_code\s*:/);
  assert.doesNotMatch(wholesaleOneSource, /DRESSED_CHICKEN_VARIANTS/);
});

test("el impresor exclusivo M2 conserva los códigos iniciales y editados", () => {
  const classificationCodes = ["PV-M", "PV-H", "MA", "MC", "HA", "HC", "PB"];
  const html = buildWholesaleTwoTicketHtml({
    code: "M2-PRUEBA",
    operationType: "DESPACHO",
    destinationName: "Cliente",
    records: classificationCodes.map((typeCode) => ({
      typeCode,
      birds: 1,
      birdsPerCage: 1,
      cages: 1,
      readWeight: 10,
      grossWeight: 10,
      tareWeight: 1,
      netWeight: 9
    }))
  });

  for (const code of classificationCodes) {
    assert.match(html, new RegExp(`>${code}<`));
  }
});

test("el impresor exclusivo M2 separa lectura, merma, tara y neto", () => {
  const html = buildWholesaleTwoTicketHtml({
    code: "M2-MERMA",
    operationType: "DESPACHO",
    destinationName: "Cliente",
    records: [{
      typeCode: "MA",
      birdsPerCage: 7,
      cages: 1,
      readWeight: 20,
      adjustmentGrams: 100,
      adjustmentWeight: 0.7,
      grossWeight: 20.7,
      tareWeight: 7,
      netWeight: 13.7
    }]
  });

  assert.match(html, /<th>LECT\.<\/th><th>MERMA<\/th><th>TARA<\/th><th>NETO<\/th>/);
  assert.match(html, />20\.000</);
  assert.match(html, />0\.700</);
  assert.match(html, />7\.000</);
  assert.match(html, />13\.700</);
});
