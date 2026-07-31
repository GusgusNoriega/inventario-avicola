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

const { renderClientPrintRows } = await import("../../public/js/tickets-dia.js");

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
  assert.doesNotMatch(html, /VARIOS/);
});

test("mantiene aislada una fila sin precio", () => {
  const html = renderClientPrintRows([{
    client: { id: 2, name: "Cliente dos" },
    print_rows: [{
      chicken_type: { code: "POLLO_VIVO", name: "Pollo vivo" },
      cages: 1,
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
