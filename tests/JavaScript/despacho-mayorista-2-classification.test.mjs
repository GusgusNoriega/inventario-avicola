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
const wholesaleOneView = readFileSync(
  new URL("../../resources/views/operacion.blade.php", import.meta.url),
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

test("mayorista 1 y 2 ofrecen la java de 6.80 kg con el mismo codigo de API", () => {
  const java680Definition = /\{\s*id:\s*"java_680",\s*apiCode:\s*"JAVA_680",\s*label:\s*"Java 6\.80 kg",\s*weightKg:\s*6\.8\s*\}/;
  const java680Option = /<option value="java_680">Java 6\.80 kg<\/option>/;

  assert.match(wholesaleOneSource, java680Definition);
  assert.match(wholesaleTwoSource, java680Definition);
  assert.match(wholesaleOneView, java680Option);
  assert.match(wholesaleTwoView, java680Option);
});

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

test("mayorista 2 agrega Gallina Roja/Doble y Otros sin modificar mayorista 1", () => {
  assert.match(wholesaleTwoView, /data-type="gallina"[^>]*>Gallina</);
  assert.match(wholesaleTwoView, /data-type="otros"[^>]*>Otros</);
  assert.match(wholesaleTwoView, /id="henVariantSelector"[^>]*hidden[\s\S]*data-hen-variant="GALLINA_ROJA"[\s\S]*data-hen-variant="GALLINA_DOBLE"/);
  assert.match(wholesaleTwoView, /id="editHenVariantSelector"[^>]*hidden[\s\S]*data-edit-hen-variant="GALLINA_ROJA"[\s\S]*data-edit-hen-variant="GALLINA_DOBLE"/);
  assert.match(wholesaleTwoSource, /code: "GALLINA_ROJA"[^\n]+shortLabel: "GR"/);
  assert.match(wholesaleTwoSource, /code: "GALLINA_DOBLE"[^\n]+shortLabel: "GD"/);
  assert.match(wholesaleTwoSource, /const OTHER_PRODUCT_VARIANT = \{[\s\S]*?code: "OTROS"[\s\S]*?shortLabel: "OT"[\s\S]*?\};/);
  assert.doesNotMatch(wholesaleOneView, /data-type="gallina"|data-type="otros"/);
  assert.doesNotMatch(wholesaleOneSource, /GALLINA_ROJA|GALLINA_DOBLE/);
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

test("la vista M2 configura ocho mermas y mantiene beneficiado y otros sin merma", () => {
  assert.match(wholesaleTwoView, /id="openWeightAdjustmentSettingsBtn"/);
  assert.match(wholesaleTwoView, /id="weightAdjustmentSettingsModal"/);
  assert.equal((wholesaleTwoView.match(/data-weight-adjustment-variant=/g) || []).length, 8);
  assert.equal((wholesaleTwoView.match(/max="1000000"[^>]*data-weight-adjustment-variant=/g) || []).length, 8);

  for (const code of [
    "MACHO",
    "HEMBRA",
    "MACHO_ABIERTO",
    "MACHO_CERRADO",
    "HEMBRA_ABIERTA",
    "HEMBRA_CERRADA",
    "GALLINA_ROJA",
    "GALLINA_DOBLE"
  ]) {
    assert.match(wholesaleTwoView, new RegExp(`data-weight-adjustment-variant="${code}"`));
  }

  assert.match(wholesaleTwoView, /PB · Pollo beneficiado[\s\S]*value="0" disabled/);
  assert.match(wholesaleTwoView, /OT · Otros[\s\S]*sin opciones adicionales ni merma/i);
  assert.match(wholesaleTwoSource, /apiRequest\("\/despacho-mayorista-2\/configuracion-mermas"/);
  assert.match(wholesaleTwoSource, /code:\s*String\(input\.dataset\.weightAdjustmentVariant/);
  assert.match(wholesaleTwoSource, /additional_grams:\s*grams/);
  assert.match(wholesaleTwoSource, /expectedConfigurableCodes/);
  assert.match(wholesaleTwoSource, /recalculatePendingWeightAdjustments/);
  assert.match(wholesaleTwoCss, /\.weight-adjustment-settings-grid/);
});

test("los totales inferiores omiten el desglose redundante y explican la formula del peso neto", () => {
  assert.match(wholesaleTwoSource, /class="selected-truck-counts"/);
  assert.match(wholesaleTwoSource, /class="selected-truck-weight-flow"/);
  assert.match(wholesaleTwoSource, /class="selected-truck-operator" aria-hidden="true">\+</);
  assert.match(wholesaleTwoSource, /class="selected-truck-operator" aria-hidden="true">−</);
  assert.match(wholesaleTwoSource, /class="selected-truck-stat is-net"/);
  assert.doesNotMatch(wholesaleTwoSource, /class="selected-truck-types"/);
  assert.doesNotMatch(wholesaleTwoSource, /class="selected-truck-type /);
  assert.doesNotMatch(wholesaleTwoSource, /has-type-breakdown/);
  assert.match(wholesaleTwoSource, /class="selected-truck-actions"/);
  assert.match(wholesaleTwoSource, /data-register-ticket=/);
  assert.match(wholesaleTwoSource, /data-print-ticket=/);
  assert.doesNotMatch(wholesaleTwoCss, /#selectedTruckDetails\.has-type-breakdown/);
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

test("tabla e impresión distinguen las diez clasificaciones sin alterar mayorista 1", () => {
  for (const code of ["PV-M", "PV-H", "MA", "MC", "HA", "HC", "PB", "GR", "GD", "OT"]) {
    assert.match(wholesaleTwoSource, new RegExp(`shortLabel: "${code}"`));
  }

  assert.match(wholesaleTwoSource, /getTicketTypeCode\(cage, operationType\)/);
  assert.match(wholesaleTwoSource, /getCageChickenVariantMeta\(cage\)\.shortLabel/);
  assert.match(wholesaleTwoSource, /cage\.chickenVariantCode\s*=\s*chickenVariant\.code/);
  assert.match(wholesaleTwoSource, /typeCode:\s*getTicketTypeCode\(cage, operationType\)/);
  assert.match(wholesaleTwoSource, /`\$\{typeMeta\.shortLabel\} · \$\{typeMeta\.label\}`/);
  assert.doesNotMatch(wholesaleOneSource, /chicken_variant_code\s*:/);
  assert.doesNotMatch(wholesaleOneSource, /DRESSED_CHICKEN_VARIANTS/);
});

test("el impresor exclusivo M2 conserva los códigos iniciales y editados", () => {
  const classificationCodes = ["PV-M", "PV-H", "MA", "MC", "HA", "HC", "PB", "GR", "GD", "OT"];
  const manualPriceCodes = new Set(["GR", "GD", "OT"]);
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
      netWeight: 9,
      ...(manualPriceCodes.has(typeCode) ? { priceKg: 5, amount: 45 } : {})
    }))
  });

  for (const code of classificationCodes) {
    assert.match(html, new RegExp(`>${code}<`));
  }
});

test("el impresor M2 muestra precio, subtotal y total de gallinas y otros", () => {
  const html = buildWholesaleTwoTicketHtml({
    code: "M2-PRECIOS",
    operationType: "DESPACHO",
    destinationName: "Cliente",
    records: [
      { typeCode: "GR", birdsPerCage: 1, cages: 0, readWeight: 10, grossWeight: 10, tareWeight: 0, netWeight: 10, priceKg: 8, amount: 80 },
      { typeCode: "OT", birdsPerCage: 1, cages: 0, readWeight: 2, grossWeight: 2, tareWeight: 0, netWeight: 2, priceKg: 5, amount: 10 }
    ]
  });

  assert.match(html, /PRECIOS ASIGNADOS/);
  assert.match(html, /PRECIO\/KG/);
  assert.match(html, /S\/ 8\.00/);
  assert.match(html, /S\/ 5\.00/);
  assert.match(html, /TOTAL VALORIZADO[\s\S]*S\/ 90\.00/);
});

test("el impresor exclusivo M2 conserva el título predeterminado exacto", () => {
  const html = buildWholesaleTwoTicketHtml({
    code: "M2-TITULO-DEFAULT",
    operationType: "DESPACHO",
    destinationName: "Cliente",
    records: []
  });

  assert.match(
    html,
    /<h1 class="business-name">DISTRIBUIDORA DIEGO ALBERTO<\/h1>/
  );
});

test("el impresor exclusivo M2 escapa el título personalizado", () => {
  const html = buildWholesaleTwoTicketHtml({
    code: "M2-TITULO-PERSONALIZADO",
    operationType: "DESPACHO",
    destinationName: "Cliente",
    ticketTitle: 'Pollos <Norte> & "Sur" \'Uno\'',
    records: []
  });

  assert.match(
    html,
    /<h1 class="business-name">Pollos &lt;Norte&gt; &amp; &quot;Sur&quot; &#39;Uno&#39;<\/h1>/
  );
  assert.doesNotMatch(html, /<Norte>/);
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
