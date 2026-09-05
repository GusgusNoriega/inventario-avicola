import assert from "node:assert/strict";
import test from "node:test";

import {
  PRODUCT_DISPATCH_DRAFT_COUNT,
  PRODUCT_DISPATCH_SCALE_CODE,
  PRODUCT_PRICE_MODE_KG,
  PRODUCT_PRICE_MODE_UNIT,
  buildDraftCollection,
  buildTicketPayload,
  calculationInputForWeightSource,
  calculateDraft,
  calculateLine,
  effectiveProduct,
  formatTareKilograms,
  formatWeight,
  formatWeightValue,
  normalizeCatalog,
  normalizeDraft,
  normalizeQuickProductIds,
  normalizeWastePresets,
  resolveWeightInput,
  tareKilogramsToGrams,
  validateUnitPrice
} from "../../public/js/despacho-productos-despacho-utils.js";

test("los pesos se muestran con dos decimales sin reducir su precisión de cálculo", () => {
  assert.equal(formatWeightValue(1.234), "1.23");
  assert.equal(formatWeightValue(1.235), "1.24");
  assert.equal(formatWeightValue(1234.567), "1234.57");
  assert.equal(formatWeightValue(0), "0.00");
  assert.equal(formatWeight(1234.567), "1,234.57 kg");

  const line = calculateLine({
    quantity: 1,
    read_weight_kg: 1.234,
    unit_price: 100,
    price_mode: PRODUCT_PRICE_MODE_KG
  });
  assert.equal(formatWeight(line.net_weight_kg), "1.23 kg");
  assert.equal(line.read_weight_kg, 1.234);
  assert.equal(line.net_weight_kg, 1.234);
  assert.equal(line.amount, 123.4);
});

test("editar otro campo conserva los gramos originales detrás del peso visible", () => {
  assert.equal(resolveWeightInput("1.23", 1.234), 1.234);
  assert.equal(resolveWeightInput("1.230", 1.234), 1.234);
  assert.equal(resolveWeightInput("1.25", 1.234), 1.25);
  assert.equal(resolveWeightInput("0", 1.234), 0);
  assert.equal(resolveWeightInput("", 0.001), 0, "Vaciar el campo no debe recuperar el peso original.");
  assert.equal(resolveWeightInput("  ", 0.001), 0);

  const editedLine = calculateLine({
    quantity: 1,
    read_weight_kg: resolveWeightInput("1.23", 1.234),
    unit_price: 200,
    price_mode: PRODUCT_PRICE_MODE_KG
  });
  assert.equal(editedLine.read_weight_kg, 1.234);
  assert.equal(editedLine.amount, 246.8);
});

test("los límites redondeados del campo conservan el peso original al guardarlo sin cambio", () => {
  assert.equal(formatWeightValue(0.001), "0.00");
  assert.equal(resolveWeightInput("0.00", 0.001), 0.001);
  assert.equal(formatWeightValue(999999999.999), "1000000000.00");
  assert.equal(resolveWeightInput("1000000000.00", 999999999.999), 999999999.999);
  assert.equal(resolveWeightInput("0.01", 0.001), 0.01);
  assert.equal(resolveWeightInput("999999999.99", 999999999.999), 999999999.99);
});

test("la tara en kilogramos conserva gramos exactos con punto o coma decimal", () => {
  for (const [input, grams] of [
    ["0.000", 0],
    ["0.001", 1],
    ["0.050", 50],
    ["1", 1000],
    ["1.250", 1250],
    ["1,250", 1250],
    ["1.25", 1250],
    ["1.5", 1500],
    [" 12.345 ", 12345],
    ["999999.999", 999999999],
    ["1000000", 1000000000]
  ]) {
    assert.equal(tareKilogramsToGrams(input), grams, `Tara ${input}`);
    assert.equal(tareKilogramsToGrams(formatTareKilograms(grams)), grams);
  }
  assert.equal(formatTareKilograms(0), "0.000");
  assert.equal(formatTareKilograms(1), "0.001");
  assert.equal(formatTareKilograms(1250), "1.250");
  assert.equal(formatTareKilograms(1500), "1.500");
});

test("la tara no redondea valores inválidos ni recorta el valor antes de validar su límite", () => {
  for (const input of ["", "  ", null, undefined, "-1", "1.2501", "0.0001", "1e3", "1.2.3", "1,2,3", "abc", Infinity, NaN]) {
    assert.ok(Number.isNaN(tareKilogramsToGrams(input)), `Debe rechazar ${String(input)}`);
  }
  assert.equal(tareKilogramsToGrams("1000000.001"), 1000000001);
});

test("capturar y recuperar tara decimal descuenta kilogramos y conserva gramos en el ticket", () => {
  const draft = normalizeDraft({
    id: "44444444-4444-4444-8444-444444444444",
    items: [{
      product_id: 7,
      quantity: 2,
      read_weight_kg: 10,
      waste_grams_per_unit: 150,
      tare_grams: tareKilogramsToGrams("1.250"),
      unit_price: 10,
      price_mode: PRODUCT_PRICE_MODE_KG,
      weight_source: PRODUCT_DISPATCH_SCALE_CODE
    }]
  });
  const line = draft.items[0];

  assert.equal(line.tare_grams, 1250);
  assert.equal(line.tare_weight_kg, 1.25);
  assert.equal(line.waste_total_grams, 300);
  assert.equal(line.net_weight_kg, 9.05);
  assert.equal(line.amount, 90.5);
  assert.equal(buildTicketPayload(draft).weighings[0].tare_grams, 1250);
  assert.equal(buildTicketPayload(draft).weighings[0].waste_grams_per_unit, 150);

  const edited = calculateLine({ ...line, tare_grams: tareKilogramsToGrams("1,501") });
  assert.equal(edited.tare_grams, 1501);
  assert.equal(edited.net_weight_kg, 8.799);
  assert.equal(edited.amount, 87.99);
});

test("la estación siempre mantiene ocho listas independientes y recuperables", () => {
  const drafts = buildDraftCollection();

  assert.equal(drafts.length, PRODUCT_DISPATCH_DRAFT_COUNT);
  assert.deepEqual(drafts.map((draft) => draft.number), [1, 2, 3, 4, 5, 6, 7, 8]);
  assert.equal(new Set(drafts.map((draft) => draft.id)).size, PRODUCT_DISPATCH_DRAFT_COUNT);
  drafts.forEach((draft) => {
    assert.match(draft.id, /^[0-9a-f]{8}-[0-9a-f-]{27}$/i);
    assert.deepEqual(draft.items, []);
    assert.equal(draft.client_id, null);
  });
});

test("una variación reemplaza precio merma nombre e imagen y muestra su propio respaldo visual", () => {
  const product = {
    id: 7,
    name: "Pavo",
    image_url: "/pavo.jpg",
    price: 18,
    price_mode: PRODUCT_PRICE_MODE_KG,
    waste_grams_per_unit: 25
  };
  const withImage = effectiveProduct(product, {
    id: 71,
    name: "Grande",
    image_url: "/pavo-grande.jpg",
    price: 20,
    price_mode: PRODUCT_PRICE_MODE_UNIT,
    waste_grams_per_unit: 30
  });
  const withoutImage = effectiveProduct(product, {
    id: 72,
    name: "Mediano",
    image_url: null,
    price: 19,
    price_mode: PRODUCT_PRICE_MODE_KG,
    waste_grams_per_unit: 28
  });

  assert.deepEqual(withImage, {
    product_id: 7,
    variation_id: 71,
    product_name: "Pavo",
    variation_name: "Grande",
    display_name: "Pavo · Grande",
    image_url: "/pavo-grande.jpg",
    price: 20,
    price_mode: PRODUCT_PRICE_MODE_UNIT,
    waste_grams_per_unit: 30
  });
  assert.equal(withoutImage.image_url, null);
});

test("los importes por kg usan peso neto y los importes por unidad usan cantidad", () => {
  const kgLine = calculateLine({
    quantity: 2,
    read_weight_kg: 10,
    waste_total_grams: 100,
    unit_price: 21,
    price_mode: PRODUCT_PRICE_MODE_KG
  });
  const unitLine = calculateLine({
    quantity: 12,
    read_weight_kg: 2,
    waste_total_grams: 24,
    unit_price: 0.75,
    price_mode: PRODUCT_PRICE_MODE_UNIT
  });

  assert.deepEqual(kgLine, {
    quantity: 2,
    read_weight_kg: 10,
    waste_grams_per_unit: 50,
    waste_total_grams: 100,
    waste_weight_kg: 0.1,
    tare_grams: 0,
    tare_weight_kg: 0,
    net_weight_kg: 10.1,
    unit_price: 21,
    price_mode: PRODUCT_PRICE_MODE_KG,
    amount: 212.1
  });
  assert.equal(unitLine.net_weight_kg, 2.024);
  assert.equal(unitLine.amount, 9);
  assert.deepEqual(calculateDraft([kgLine, unitLine]), {
    weighings: 2,
    quantity: 14,
    read_weight_kg: 12,
    waste_total_grams: 124,
    tare_grams: 0,
    net_weight_kg: 12.124,
    amount: 221.1
  });
});

test("la merma solo se aplica cuando el peso leído es mayor que cero", () => {
  const withoutScaleWeight = calculateLine({
    quantity: 5,
    read_weight_kg: 0,
    waste_grams_per_unit: 50,
    unit_price: 18,
    price_mode: PRODUCT_PRICE_MODE_KG
  });
  const belowScalePrecision = calculateLine({
    quantity: 5,
    read_weight_kg: 0.0004,
    waste_grams_per_unit: 50,
    unit_price: 18,
    price_mode: PRODUCT_PRICE_MODE_KG
  });
  const withScaleWeight = calculateLine({
    quantity: 5,
    read_weight_kg: 0.001,
    waste_grams_per_unit: 50,
    unit_price: 18,
    price_mode: PRODUCT_PRICE_MODE_KG
  });

  assert.equal(withoutScaleWeight.waste_grams_per_unit, 50);
  assert.equal(withoutScaleWeight.waste_total_grams, 0);
  assert.equal(withoutScaleWeight.net_weight_kg, 0);
  assert.equal(withoutScaleWeight.amount, 0);
  assert.equal(belowScalePrecision.read_weight_kg, 0);
  assert.equal(belowScalePrecision.waste_total_grams, 0);
  assert.equal(belowScalePrecision.net_weight_kg, 0);
  assert.equal(withScaleWeight.waste_total_grams, 250);
  assert.equal(withScaleWeight.net_weight_kg, 0.251);
});

test("el peso manual es el neto exacto aunque existan valores de merma o tara", () => {
  const line = calculateLine(calculationInputForWeightSource({
    quantity: 3,
    read_weight_kg: 4.555,
    waste_grams_per_unit: 80,
    waste_total_grams: 240,
    tare_grams: 125,
    unit_price: 18,
    price_mode: PRODUCT_PRICE_MODE_KG,
    weight_source: "MANUAL"
  }));

  assert.equal(line.read_weight_kg, 4.555);
  assert.equal(line.net_weight_kg, 4.555);
  assert.equal(line.waste_grams_per_unit, 0);
  assert.equal(line.waste_total_grams, 0);
  assert.equal(line.tare_grams, 0);
});

test("la recuperación conserva cantidad cero y limpia ajustes de una pesada manual", () => {
  const recovered = normalizeDraft({
    id: "11111111-1111-4111-8111-111111111111",
    client_id: "9",
    items: [
      {
        local_id: "manual-1",
        product_id: "7",
        variation_id: "",
        quantity: 2,
        read_weight_kg: 4.5554,
        waste_total_grams: 50,
        tare_grams: 25,
        unit_price: 18,
        price_mode: PRODUCT_PRICE_MODE_KG,
        weight_source: "MANUAL",
        scale_reading: { raw_frame: "no debe sobrevivir" }
      },
      { product_id: 8, quantity: 0, read_weight_kg: 3, unit_price: 1 },
      { product_id: 9, quantity: null, read_weight_kg: 2, unit_price: 1 }
    ]
  }, 3);

  assert.equal(recovered.number, 3);
  assert.equal(recovered.client_id, 9);
  assert.equal(Object.hasOwn(recovered, "price_overrides"), false);
  assert.equal(recovered.items.length, 2);
  assert.equal(recovered.items[0].read_weight_kg, 4.555);
  assert.equal(recovered.items[0].net_weight_kg, 4.555);
  assert.equal(recovered.items[0].waste_total_grams, 0);
  assert.equal(recovered.items[0].tare_grams, 0);
  assert.equal(recovered.items[0].weight_source, "MANUAL");
  assert.equal(recovered.items[0].scale_reading, null);
  assert.equal(recovered.items[1].quantity, 0);
  assert.equal(buildTicketPayload(recovered).weighings[1].quantity, 0);
});

test("el payload conserva dos decimales de precio y evidencia solo para la balanza de productos", () => {
  const draft = normalizeDraft({
    id: "22222222-2222-4222-8222-222222222222",
    client_id: 5,
    items: [{
      product_id: 7,
      variation_id: 71,
      quantity: 2,
      unit_price: 20.5,
      read_weight_kg: 4.321,
      waste_total_grams: 60,
      price_mode: PRODUCT_PRICE_MODE_KG,
      weight_source: PRODUCT_DISPATCH_SCALE_CODE,
      weighed_at: "2026-08-28T10:00:00-05:00",
      scale_reading: {
        raw_frame: "ST,+004.321kg",
        connection_mode: "serial",
        device_name: "COM7"
      }
    }]
  }, 4);

  assert.deepEqual(buildTicketPayload(draft), {
    draft_id: "22222222-2222-4222-8222-222222222222",
    list_number: 4,
    client_id: 5,
    weighings: [{
      product_id: 7,
      variation_id: 71,
      quantity: 2,
      price_mode: PRODUCT_PRICE_MODE_KG,
      unit_price: "20.50",
      waste_grams_per_unit: 30,
      waste_total_grams: 60,
      tare_grams: 0,
      weight_source: PRODUCT_DISPATCH_SCALE_CODE,
      read_weight_kg: "4.321",
      weighed_at: "2026-08-28T10:00:00-05:00",
      scale_reading: {
        raw_frame: "ST,+004.321kg",
        connection_mode: "serial",
        device_name: "COM7"
      }
    }]
  });
});

test("el catálogo acepta el contrato del API y normaliza clientes para búsqueda por documento", () => {
  const catalog = normalizeCatalog({ data: {
    products: [{
      id: 1,
      name: "Huevo",
      price: "0.7500",
      price_mode: PRODUCT_PRICE_MODE_UNIT,
      waste_grams_per_unit: 2,
      variations: []
    }],
    clients: [{ id: 4, name: "Tienda Norte", document_number: "901234567" }],
    currency: "S/",
    product_ticket_title: "CONTROL DE DESPACHO AVÍCOLA",
    ticket_title: "TÍTULO GENERAL QUE NO DEBE USARSE",
    customer_display_title: "LA CENTRAL DE LOS POLLOS"
  } });

  assert.equal(catalog.products[0].price, 0.75);
  assert.equal(catalog.products[0].price_mode, PRODUCT_PRICE_MODE_UNIT);
  assert.equal(catalog.clients[0].document, "901234567");
  assert.equal(catalog.product_ticket_title, "CONTROL DE DESPACHO AVÍCOLA");
  assert.equal(catalog.ticket_title, "CONTROL DE DESPACHO AVÍCOLA");
  assert.equal(catalog.customer_display_title, "LA CENTRAL DE LOS POLLOS");
  assert.deepEqual(catalog.waste_presets, [0, 50, 100, 150]);
});

test("el título propio del ticket se limita y conserva un alias compatible", () => {
  assert.equal(normalizeCatalog().product_ticket_title, "DESPACHO DE PRODUCTOS");

  const catalog = normalizeCatalog({ product_ticket_title: `  ${"T".repeat(200)}  ` });
  assert.equal(catalog.product_ticket_title, "T".repeat(180));
  assert.equal(catalog.ticket_title, catalog.product_ticket_title);
});

test("el título de pantalla cliente se limita y tiene un valor seguro por defecto", () => {
  assert.equal(normalizeCatalog().customer_display_title, "Despacho de productos");
  assert.equal(
    normalizeCatalog({ customer_display_title: `  ${"A".repeat(140)}  ` }).customer_display_title,
    "A".repeat(120)
  );
});

test("los productos rápidos conservan cuatro ids activos únicos y su orden configurado", () => {
  const products = [1, 2, 3, 4, 5].map((id) => ({ id }));

  assert.deepEqual(normalizeQuickProductIds([4, "2", 4, 999], products), [4, 2, 1, 3]);
  assert.deepEqual(normalizeQuickProductIds(undefined, products), [1, 2, 3, 4]);
  assert.deepEqual(normalizeQuickProductIds([8], [{ id: 7 }, { id: 8 }]), [8, 7]);

  const catalog = normalizeCatalog({
    data: {
      products: products.map(({ id }) => ({ id, name: `Producto ${id}`, variations: [] })),
      quick_product_ids: [5, 3, 2, 1]
    }
  });
  assert.deepEqual(catalog.quick_product_ids, [5, 3, 2, 1]);
});

test("la merma por unidad se suma y la tara se descuenta sin modificar la merma total", () => {
  const line = calculateLine({
    quantity: 3,
    read_weight_kg: 5,
    waste_grams_per_unit: 40,
    tare_grams: 80,
    unit_price: 10,
    price_mode: PRODUCT_PRICE_MODE_KG
  });

  assert.equal(line.waste_total_grams, 120);
  assert.equal(line.tare_weight_kg, 0.08);
  assert.equal(line.net_weight_kg, 5.04);
  assert.equal(line.amount, 50.4);
  assert.equal(calculateDraft([line]).tare_grams, 80);
});

test("los cuatro presets conservan las tres mermas existentes y agregan el cuarto valor", () => {
  const existingPresets = [350, 400, 500];
  assert.deepEqual(normalizeWastePresets(existingPresets), [350, 400, 500, 150]);
  assert.deepEqual(existingPresets, [350, 400, 500], "La compatibilidad no debe mutar la configuración recibida.");
  assert.deepEqual(normalizeWastePresets([10, 20, 30, 75]), [10, 20, 30, 75]);
  assert.deepEqual(normalizeWastePresets([0, 50, 100, 1_000_000]), [0, 50, 100, 1_000_000]);
  assert.deepEqual(normalizeCatalog({ waste_presets: [350, 400, 500, 600] }).waste_presets, [350, 400, 500, 600]);
});

test("las mermas predeterminadas siguen siendo gramos enteros con valores de respaldo seguros", () => {
  for (const presets of [undefined, [10, -1, 30], [10, 20], [10, 20, 1_000_001], [10, 20, 30, 1.5], [10, 20, 30, -1], [10, 20, 30, 1_000_001], [0, 50, 100, 150, 200]]) {
    assert.deepEqual(normalizeWastePresets(presets), [0, 50, 100, 150]);
  }
});

test("el precio de una pesada usa entre 0.01 y el máximo con solo dos decimales", () => {
  assert.equal(validateUnitPrice("12.34"), "");
  assert.equal(validateUnitPrice("0.01"), "");
  assert.match(validateUnitPrice("0"), /mínimo es 0.01/);
  assert.match(validateUnitPrice("12.345"), /hasta 2 decimales/);
  assert.match(validateUnitPrice("100.01", 100), /máximo permitido/);
  assert.match(validateUnitPrice(""), /precio válido/);
});

test("dos pesadas del mismo ticket conservan precios e importes independientes", () => {
  const draft = normalizeDraft({
    id: "33333333-3333-4333-8333-333333333333",
    items: [
      { product_id: 7, quantity: 1, read_weight_kg: 2, unit_price: 10, price_mode: PRODUCT_PRICE_MODE_KG },
      { product_id: 7, quantity: 1, read_weight_kg: 2, unit_price: 12.5, price_mode: PRODUCT_PRICE_MODE_KG }
    ]
  });

  assert.deepEqual(draft.items.map((item) => item.unit_price), [10, 12.5]);
  assert.deepEqual(draft.items.map((item) => item.amount), [20, 25]);
  assert.equal(buildTicketPayload(draft).list_number, 1);
  assert.deepEqual(buildTicketPayload(draft).weighings.map((item) => item.unit_price), ["10.00", "12.50"]);
});
