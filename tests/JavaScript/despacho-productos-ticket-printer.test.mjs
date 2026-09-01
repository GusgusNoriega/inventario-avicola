import assert from "node:assert/strict";
import test from "node:test";

import { buildProductDispatchTicketHtml } from "../../public/js/despacho-productos-ticket-printer.js";

test("el ticket impreso muestra cliente, variación, merma, peso neto y total confirmado", () => {
  const html = buildProductDispatchTicketHtml({
    data: {
      code: "PD-20260828-001",
      registered_at: "2026-08-28T10:00:00-05:00",
      ticket_title: "AVÍCOLA CENTRAL",
      client: { name: "Tienda Norte" },
      currency: "S/",
      weighings: [{
        quantity: 2,
        product: { name: "Pavo" },
        variation: { name: "Grande" },
        price_mode: "POR_KG",
        unit_price: "21.0000",
        waste_grams_per_unit: 50,
        tare_grams: 20,
        net_weight_kg: "9.880",
        amount: "207.48"
      }],
      totals: {
        quantity: 2,
        read_weight_kg: "10.000",
        waste_grams: 100,
        tare_grams: 20,
        net_weight_kg: "9.880",
        amount: "207.48"
      },
      ticket_message: "Gracias por su compra"
    }
  });

  assert.match(html, /PD-20260828-001/);
  assert.match(html, /Cliente:<\/strong> Tienda Norte/);
  assert.match(html, /Pavo · Grande/);
  assert.match(html, /Merma<\/span><strong>100 g/);
  assert.match(html, /M 50 g\/u · T 20 g/);
  assert.match(html, /Tara<\/span><strong>20 g/);
  assert.match(html, /9\.880 kg/);
  assert.match(html, /S\/ 207\.48/);
  assert.match(html, /Gracias por su compra/);
});

test("sin cliente el comprobante dice Venta al público y escapa datos del servidor", () => {
  const html = buildProductDispatchTicketHtml({
    data: {
      code: "PD-002",
      registered_at: "2026-08-28T10:00:00-05:00",
      ticket_title: "<img src=x onerror=alert(1)>",
      customer_label: "Venta al público",
      weighings: [{
        quantity: 6,
        product: { name: "<script>alert('producto')</script>" },
        price_mode: "POR_UNIDAD",
        unit_price: 0.8,
        net_weight_kg: 0.988,
        amount: 4.8
      }],
      totals: { quantity: 6, read_weight_kg: 1, waste_grams: 12, net_weight_kg: 0.988, amount: 4.8 }
    }
  });

  assert.match(html, /Cliente:<\/strong> Venta al público/);
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /&lt;script&gt;alert\(&#039;producto&#039;\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<img src=x onerror=alert\(1\)>/);
  assert.doesNotMatch(html, /<script>alert\('producto'\)<\/script>/);
});

test("el impresor tolera una respuesta envuelta como data.ticket", () => {
  const html = buildProductDispatchTicketHtml({ data: { ticket: {
    code: "PD-ENVUELTO",
    customer_label: "Venta al público",
    registered_at: "2026-08-28T10:00:00-05:00",
    weighings: [],
    totals: { amount: 0 }
  } } });

  assert.match(html, /PD-ENVUELTO/);
  assert.match(html, /Sin detalle/);
});
