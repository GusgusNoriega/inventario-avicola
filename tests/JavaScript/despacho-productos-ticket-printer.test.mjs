import assert from "node:assert/strict";
import test from "node:test";

import {
  buildProductDispatchTicketHtml,
  groupTicketItems
} from "../../public/js/despacho-productos-ticket-printer.js";

test("la plantilla exclusiva de productos imprime control, lista y sus dos tablas", () => {
  const html = buildProductDispatchTicketHtml({
    data: {
      code: "PD-20260828-001",
      registered_at: "2026-08-28T10:00:00-05:00",
      product_ticket_title: "LA CENTRAL DEL\nPALACIO DE LOS POLLOS",
      ticket_title: "TÍTULO GENERAL QUE NO DEBE USARSE",
      client: { name: "Venta al público" },
      list_number: 3,
      currency: "S/",
      weighings: [
        {
          product_id: 7,
          variation_id: 71,
          quantity: 2,
          product: { id: 7, name: "Pavo" },
          variation: { id: 71, name: "Grande" },
          price_mode: "POR_KG",
          unit_price: "21.0000",
          read_weight_kg: "99.111",
          waste_total_grams: 850,
          tare_grams: 500,
          net_weight_kg: "10.080",
          amount: "211.68"
        },
        {
          product_id: 7,
          variation_id: 71,
          quantity: 1,
          product: { id: 7, name: "Pavo" },
          variation: { id: 71, name: "Grande" },
          price_mode: "POR_KG",
          unit_price: "21.00",
          net_weight_kg: "0.920",
          amount: "19.32"
        },
        {
          product_id: 9,
          quantity: 6,
          product: { id: 9, name: "Huevo" },
          price_mode: "POR_UNIDAD",
          unit_price: "0.8000",
          net_weight_kg: "1.012",
          amount: "4.80"
        }
      ],
      totals: { amount: "235.80" },
      ticket_message: "Gracias por su compra"
    }
  });

  assert.match(html, /LA CENTRAL DEL\nPALACIO DE LOS POLLOS/);
  assert.doesNotMatch(html, /TÍTULO GENERAL QUE NO DEBE USARSE/);
  assert.match(html, /CONTROL DE DESPACHO/);
  assert.match(html, /CONTROL DE PESO/);
  assert.match(html, /PD-20260828-001/);
  assert.match(html, /VENTA AL PÚBLICO - 3/);

  assert.equal((html.match(/<table/g) || []).length, 2);
  assert.match(html, /<table class="summary">[\s\S]*<th>TIPO<\/th><th>C\/A<\/th><th>P NETO<\/th>/);
  assert.match(html, /<table class="details">[\s\S]*<th>PRODUCTO<\/th><th>C\/A<\/th><th>P NETO<\/th><th>PRECIO<\/th><th>TOTAL<\/th>/);

  assert.equal((html.match(/Grande/g) || []).length, 3);
  assert.doesNotMatch(html, />Pavo · Grande</);
  assert.match(html, /10\.08/);
  assert.match(html, /0\.92/);
  assert.match(html, /<table class="details">[\s\S]*Grande<\/td>\s*<td class="number">3<\/td>\s*<td class="number">11<\/td>\s*<td class="number price">21<\/td>\s*<td class="number strong">231\.00<\/td>/);
  assert.doesNotMatch(html, /\/kg|\/und/);
  assert.match(html, /1\.012/);
  assert.match(html, /TOT S\/<\/span><span>235\.80/);
  assert.match(html, /<p class="footer">Gracias por su compra<\/p>/);
  assert.doesNotMatch(html, />Merma</);
  assert.doesNotMatch(html, />Tara</);
  assert.doesNotMatch(html, /99\.111/);
  assert.doesNotMatch(html, /850|500/);
  assert.doesNotMatch(html, /merma|tara/i);
  assert.doesNotMatch(html, /Peso le[ií]do/i);
  assert.match(html, /body\{[^}]*font:10\.5px\/1\.15/);
  assert.match(html, /h1\{[^}]*font-size:15px/);
});

test("el resumen agrupa solo pesadas equivalentes y conserva su orden", () => {
  const grouped = groupTicketItems([
    {
      product: { id: 1, name: "Pollo" },
      variation: { id: 10, name: "Gallo" },
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 5.5,
      net_weight_kg: 1.2,
      amount: 6.6
    },
    {
      product: { id: 1, name: "Pollo" },
      variation: { id: 10, name: "Gallo" },
      quantity: 2,
      price_mode: "POR_KG",
      unit_price: 5.5,
      net_weight_kg: 2.345,
      amount: 12.9
    },
    {
      product: { id: 1, name: "Pollo" },
      variation: { id: 10, name: "Gallo" },
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 6,
      net_weight_kg: 1,
      amount: 6
    },
    {
      product: { id: 1, name: "Pollo" },
      variation: { id: 11, name: "Milanesa" },
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 5.5,
      net_weight_kg: 0.5,
      amount: 2.75
    },
    {
      product: { id: 2, name: "Pollo" },
      variation: { id: 10, name: "Gallo" },
      quantity: 1,
      price_mode: "POR_KG",
      unit_price: 5.5,
      net_weight_kg: 0.4,
      amount: 2.2
    },
    {
      product: { id: 3, name: "Huevo" },
      quantity: 2,
      price_mode: "POR_UNIDAD",
      unit_price: 0.8,
      net_weight_kg: 0.4,
      amount: 1.6
    },
    {
      product: { id: 3, name: "Huevo" },
      quantity: 3,
      price_mode: "POR_UNIDAD",
      unit_price: 0.8,
      net_weight_kg: 0.6,
      amount: 2.4
    }
  ]);

  assert.equal(grouped.length, 5);
  assert.equal(grouped[0].variation.name, "Gallo");
  assert.equal(grouped[0].quantity, 3);
  assert.equal(grouped[0].net_weight_kg, 3.545);
  assert.equal(grouped[0].amount, 19.5);
  assert.equal(grouped[1].unit_price, 6);
  assert.equal(grouped[2].variation.name, "Milanesa");
  assert.equal(grouped[3].product.id, 2);
  assert.equal(grouped[4].quantity, 5);
  assert.equal(grouped[4].net_weight_kg, 1);
  assert.equal(grouped[4].amount, 4);
});

test("el título propio y los datos del ticket se escapan antes de imprimir", () => {
  const html = buildProductDispatchTicketHtml({
    data: {
      code: "PD-002",
      registered_at: "2026-08-28T10:00:00-05:00",
      product_ticket_title: "<img src=x onerror=alert(1)>",
      customer_label: "Venta <b>especial</b>",
      list_number: 1,
      weighings: [{
        quantity: 1,
        product: { name: "<script>alert('producto')</script>" },
        price_mode: "POR_UNIDAD",
        unit_price: 0.8,
        net_weight_kg: 1.012,
        amount: 0.8
      }],
      totals: { amount: 0.8 }
    }
  }, {
    productTicketTitle: "TÍTULO DE RESPALDO"
  });

  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /VENTA &lt;B&gt;ESPECIAL&lt;\/B&gt; - 1/);
  assert.match(html, /&lt;script&gt;alert\(&#039;producto&#039;\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /TÍTULO DE RESPALDO/);
  assert.doesNotMatch(html, /<img src=x onerror=alert\(1\)>/);
  assert.doesNotMatch(html, /<script>alert\('producto'\)<\/script>/);
});

test("el impresor tolera una respuesta envuelta como data.ticket", () => {
  const html = buildProductDispatchTicketHtml({ data: { ticket: {
    code: "PD-ENVUELTO",
    product_ticket_title: "CONTROL AVÍCOLA",
    customer_label: "Venta al público",
    list_number: 5,
    registered_at: "2026-08-28T10:00:00-05:00",
    weighings: [],
    totals: { amount: 0 }
  } } });

  assert.match(html, /CONTROL AVÍCOLA/);
  assert.match(html, /VENTA AL PÚBLICO - 5/);
  assert.match(html, /PD-ENVUELTO/);
  assert.equal((html.match(/Sin detalle/g) || []).length, 2);
});
