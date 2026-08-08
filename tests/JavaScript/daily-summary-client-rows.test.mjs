import test from "node:test";
import assert from "node:assert/strict";

globalThis.document = {
  querySelector: () => null,
  getElementById: () => null,
  cookie: "",
  body: {
    classList: {
      add() {},
      remove() {}
    }
  }
};
globalThis.window = {
  SISTEMA_POLLOS_API_URL: "/api/v1",
  addEventListener() {}
};
globalThis.sessionStorage = {
  getItem: () => null,
  setItem() {},
  removeItem() {}
};

const {
  renderClientPrintRows,
  renderDailySummaryTotalRow
} = await import("../../public/js/tickets-dia.js");

test("genera una fila impresa por tipo y precio", () => {
  const html = renderClientPrintRows([{
    client: { id: 1, name: "Cliente uno" },
    chicken_types: [
      { code: "POLLO_VIVO", name: "Pollo vivo" },
      { code: "POLLO_PELADO", name: "Pollo pelado" }
    ],
    net_weight_kg: 120,
    print_rows: [
      {
        chicken_type: { code: "POLLO_VIVO", name: "Pollo vivo" },
        cages: 2,
        trays: 0,
        birds: 50,
        gross_weight_kg: 114,
        tare_weight_kg: 14,
        return_net_weight_kg: 30,
        net_weight_kg: 70,
        price_kg: "8.5000",
        amount: "595.00"
      },
      {
        chicken_type: { code: "POLLO_PELADO", name: "Pollo pelado" },
        cages: 1,
        trays: 3,
        birds: 20,
        gross_weight_kg: 57,
        tare_weight_kg: 7,
        return_net_weight_kg: 0,
        net_weight_kg: 50,
        price_kg: "10.0000",
        amount: "500.00"
      }
    ]
  }]);

  assert.equal((html.match(/<tr /g) || []).length, 2);
  assert.equal((html.match(/Cliente uno/g) || []).length, 2);
  assert.match(html, /data-print-price="8\.5000" data-print-amount="595"/);
  assert.match(html, /data-print-price="10\.0000" data-print-amount="500"/);
  assert.match(html, /title="Pollo vivo">P V<\/span>/);
  assert.match(html, /title="Pollo pelado">P P<\/span>/);
  assert.match(html, /<td>3<\/td>\s*<td>20<\/td>/);
  assert.doesNotMatch(html, /VARIOS/);
});

test("mantiene aislada una fila sin precio", () => {
  const html = renderClientPrintRows([{
    client: { id: 2, name: "Cliente dos" },
    print_rows: [{
      chicken_type: { code: "POLLO_VIVO", name: "Pollo vivo" },
      cages: 1,
      trays: 0,
      birds: 10,
      gross_weight_kg: 17,
      tare_weight_kg: 7,
      return_net_weight_kg: 0,
      net_weight_kg: 10,
      price_kg: null,
      amount: null
    }]
  }]);

  assert.match(html, /data-print-price="SIN PRECIO" data-print-amount=""/);
});

test("genera el total general con javas, bandejas, pesos e importe", () => {
  const html = renderDailySummaryTotalRow({
    cages: 3,
    trays: 4,
    birds: 80,
    gross_weight_kg: 191,
    tare_weight_kg: 28,
    return_net_weight_kg: 30,
    net_weight_kg: 133,
    amount: "1095.00",
    amount_complete: true
  });

  assert.match(html, /class="daily-summary-total"/);
  assert.match(html, /data-print-price="" data-print-amount="1095"/);
  assert.match(html, /colspan="2"><strong>TOTAL GENERAL<\/strong>/);
  assert.match(html, /<td><strong>3<\/strong><\/td>\s*<td><strong>4<\/strong><\/td>\s*<td><strong>80<\/strong><\/td>/);
  assert.match(html, /data-print-weight="30"><strong>30\.000 kg<\/strong>/);
  assert.match(html, /data-print-weight="133"><strong>133\.000 kg<\/strong>/);
});

test("marca el importe total como incompleto cuando falta algún precio", () => {
  const html = renderDailySummaryTotalRow({
    amount: null,
    amount_complete: false
  });

  assert.match(html, /data-print-amount="SIN PRECIO"/);
});
