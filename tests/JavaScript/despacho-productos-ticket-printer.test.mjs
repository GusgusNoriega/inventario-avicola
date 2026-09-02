import assert from "node:assert/strict";
import test from "node:test";

import { buildProductDispatchTicketHtml } from "../../public/js/despacho-productos-ticket-printer.js";

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
          quantity: 2,
          product: { name: "Pavo" },
          variation: { name: "Grande" },
          price_mode: "POR_KG",
          unit_price: "21.0000",
          read_weight_kg: "99.111",
          waste_total_grams: 850,
          tare_grams: 500,
          net_weight_kg: "10.0804",
          amount: "211.68"
        },
        {
          quantity: 6,
          product: { name: "Huevo" },
          price_mode: "POR_UNIDAD",
          unit_price: "0.8000",
          net_weight_kg: "1.0124",
          amount: "4.80"
        }
      ],
      totals: { amount: "216.48" },
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
  assert.match(html, /<table class="summary">[\s\S]*<th>PROD\.<\/th><th>CANT\.<\/th><th>P\. NETO<\/th>/);
  assert.match(html, /<table class="details">[\s\S]*<th>PRODUCTO<\/th><th>C\/A<\/th><th>P\. NETO<\/th><th>PRECIO<\/th><th>TOTAL<\/th>/);

  assert.equal((html.match(/Pavo · Grande/g) || []).length, 2);
  assert.equal((html.match(/10\.080/g) || []).length, 2);
  assert.match(html, /21\.00<small>\/kg<\/small>/);
  assert.match(html, /0\.80<small>\/und<\/small>/);
  assert.match(html, /1\.012/);
  assert.match(html, /TOT S\/<\/span><span>216\.48/);
  assert.match(html, /<p class="footer">Gracias por su compra<\/p>/);
  assert.doesNotMatch(html, />Merma</);
  assert.doesNotMatch(html, />Tara</);
  assert.doesNotMatch(html, /99\.111/);
  assert.doesNotMatch(html, /850|500/);
  assert.doesNotMatch(html, /merma|tara/i);
  assert.doesNotMatch(html, /Peso le[ií]do/i);
  assert.match(html, /body\{[^}]*font:8\.5px\/1\.12/);
  assert.match(html, /h1\{[^}]*font-size:12px/);
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
