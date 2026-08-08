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

test("genera una sola fila por cliente y marca VARIOS cuando hay precios diferentes", () => {
  const html = renderClientPrintRows([{
    client: { id: 1, name: "Cliente uno" },
    chicken_types: [
      { code: "POLLO_VIVO", name: "Pollo vivo" },
      { code: "POLLO_PELADO", name: "Pollo pelado" }
    ],
    cages: 3,
    trays: 3,
    birds: 70,
    gross_weight_kg: 171,
    tare_weight_kg: 21,
    return_net_weight_kg: 30,
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

  assert.equal((html.match(/<tr /g) || []).length, 1);
  assert.equal((html.match(/Cliente uno/g) || []).length, 1);
  assert.match(html, /data-print-price="VARIOS" data-print-amount="1095"/);
  assert.match(html, /title="Pollo vivo">P V<\/span>/);
  assert.match(html, /title="Pollo pelado">P P<\/span>/);
  assert.match(html, /<td>3<\/td>\s*<td>3<\/td>\s*<td>70<\/td>/);
  assert.match(html, /data-print-weight="30"><strong>30\.000 kg<\/strong>/);
  assert.match(html, /data-print-weight="120"><strong>120\.000 kg<\/strong>/);
});

test("mantiene aislada una fila sin precio", () => {
  const html = renderClientPrintRows([{
    client: { id: 2, name: "Cliente dos" },
    chicken_types: [{ code: "POLLO_VIVO", name: "Pollo vivo" }],
    cages: 1,
    trays: 0,
    birds: 10,
    gross_weight_kg: 17,
    tare_weight_kg: 7,
    return_net_weight_kg: 0,
    net_weight_kg: 10,
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

test("mantiene el precio cuando todas las filas detalladas usan el mismo valor", () => {
  const html = renderClientPrintRows([{
    client: { id: 3, name: "Cliente precio único" },
    chicken_types: [{ code: "POLLO_VIVO", name: "Pollo vivo" }],
    cages: 2,
    trays: 0,
    birds: 20,
    gross_weight_kg: 34,
    tare_weight_kg: 14,
    return_net_weight_kg: 0,
    net_weight_kg: 20,
    print_rows: [
      { price_kg: "8.5000", amount: "85.00" },
      { price_kg: "8.5000", amount: "85.00" }
    ]
  }]);

  assert.match(html, /data-print-price="8\.5000" data-print-amount="170"/);
  assert.doesNotMatch(html, /VARIOS/);
});

test("consolida cambios de precio del mismo tipo sin perder centavos", () => {
  const html = renderClientPrintRows([{
    client: { id: 4, name: "Cliente con cambios" },
    chicken_types: [{ code: "POLLO_VIVO", name: "Pollo vivo" }],
    cages: 3,
    trays: 0,
    birds: 40,
    gross_weight_kg: 61,
    tare_weight_kg: 21,
    return_net_weight_kg: 0,
    net_weight_kg: 40,
    print_rows: [
      { price_kg: "8.5000", amount: "85.00" },
      { price_kg: "8.5040", amount: "85.04" },
      { price_kg: "9.0000", amount: "180.00" }
    ]
  }]);

  assert.equal((html.match(/<tr /g) || []).length, 1);
  assert.match(html, /data-print-price="VARIOS" data-print-amount="350\.04"/);
  assert.equal((html.match(/Cliente con cambios/g) || []).length, 1);
});

test("prioriza SIN PRECIO y no muestra un importe parcial", () => {
  const html = renderClientPrintRows([{
    client: { id: 5, name: "Cliente incompleto" },
    chicken_types: [{ code: "POLLO_VIVO", name: "Pollo vivo" }],
    cages: 2,
    trays: 0,
    birds: 20,
    gross_weight_kg: 34,
    tare_weight_kg: 14,
    return_net_weight_kg: 0,
    net_weight_kg: 20,
    print_rows: [
      { price_kg: "8.5000", amount: "85.00" },
      { price_kg: null, amount: null }
    ]
  }]);

  assert.match(html, /data-print-price="SIN PRECIO" data-print-amount=""/);
  assert.doesNotMatch(html, /data-print-amount="85"/);
});

test("omite clientes sin movimientos imprimibles", () => {
  const html = renderClientPrintRows([{
    client: { id: 6, name: "Cliente sin pesadas activas" },
    chicken_types: [],
    print_rows: []
  }]);

  assert.equal(html, "");
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
