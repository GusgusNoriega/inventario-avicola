import assert from "node:assert/strict";
import test from "node:test";

import {
  PRODUCT_DISPATCH_DRAFT_COUNT,
  PRODUCT_DISPATCH_SCALE_CODE,
  PRODUCT_PRICE_MODE_KG,
  PRODUCT_PRICE_MODE_UNIT,
  buildDraftCollection,
  buildTicketPayload,
  calculateDraft,
  calculateLine,
  effectiveProduct,
  normalizeCatalog,
  normalizeDraft,
  normalizeWastePresets,
  validateUnitPrice
} from "../../public/js/despacho-productos-despacho-utils.js";

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
    net_weight_kg: 9.9,
    unit_price: 21,
    price_mode: PRODUCT_PRICE_MODE_KG,
    amount: 207.9
  });
  assert.equal(unitLine.net_weight_kg, 1.976);
  assert.equal(unitLine.amount, 9);
  assert.deepEqual(calculateDraft([kgLine, unitLine]), {
    weighings: 2,
    quantity: 14,
    read_weight_kg: 12,
    waste_total_grams: 124,
    tare_grams: 0,
    net_weight_kg: 11.876,
    amount: 216.9
  });
});

test("la recuperación limpia listas dañadas sin convertir una pesada manual en lectura física", () => {
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
        unit_price: 18,
        price_mode: PRODUCT_PRICE_MODE_KG,
        weight_source: "MANUAL",
        scale_reading: { raw_frame: "no debe sobrevivir" }
      },
      { product_id: 8, quantity: 0, read_weight_kg: 3, unit_price: 1 }
    ]
  }, 3);

  assert.equal(recovered.number, 3);
  assert.equal(recovered.client_id, 9);
  assert.equal(Object.hasOwn(recovered, "price_overrides"), false);
  assert.equal(recovered.items.length, 1);
  assert.equal(recovered.items[0].read_weight_kg, 4.555);
  assert.equal(recovered.items[0].weight_source, "MANUAL");
  assert.equal(recovered.items[0].scale_reading, null);
});

test("el payload conserva precisión y evidencia solo para la balanza de productos", () => {
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
  });

  assert.deepEqual(buildTicketPayload(draft), {
    draft_id: "22222222-2222-4222-8222-222222222222",
    client_id: 5,
    weighings: [{
      product_id: 7,
      variation_id: 71,
      quantity: 2,
      price_mode: PRODUCT_PRICE_MODE_KG,
      unit_price: "20.5000",
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
    ticket_title: "AVÍCOLA DE PRUEBA"
  } });

  assert.equal(catalog.products[0].price, 0.75);
  assert.equal(catalog.products[0].price_mode, PRODUCT_PRICE_MODE_UNIT);
  assert.equal(catalog.clients[0].document, "901234567");
  assert.equal(catalog.ticket_title, "AVÍCOLA DE PRUEBA");
  assert.deepEqual(catalog.waste_presets, [0, 50, 100]);
});

test("la merma por unidad y la tara se descuentan juntas sin modificar la merma total", () => {
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
  assert.equal(line.net_weight_kg, 4.8);
  assert.equal(line.amount, 48);
  assert.equal(calculateDraft([line]).tare_grams, 80);
});

test("los tres presets del catálogo son enteros seguros y tienen valores de respaldo", () => {
  assert.deepEqual(normalizeWastePresets([10, 20, 30]), [10, 20, 30]);
  assert.deepEqual(normalizeWastePresets([10, -1, 30]), [0, 50, 100]);
  assert.deepEqual(normalizeWastePresets([10, 20]), [0, 50, 100]);
  assert.deepEqual(normalizeWastePresets([10, 20, 1_000_001]), [0, 50, 100]);
});

test("el precio de una pesada debe ser positivo, acotado y usar máximo cuatro decimales", () => {
  assert.equal(validateUnitPrice("12.3456"), "");
  assert.equal(validateUnitPrice("0.0001"), "");
  assert.match(validateUnitPrice("0"), /mayor que cero/);
  assert.match(validateUnitPrice("12.34567"), /hasta 4 decimales/);
  assert.match(validateUnitPrice("100.0001", 100), /máximo permitido/);
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
  assert.deepEqual(buildTicketPayload(draft).weighings.map((item) => item.unit_price), ["10.0000", "12.5000"]);
});
