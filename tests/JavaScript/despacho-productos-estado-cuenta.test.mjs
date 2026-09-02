import assert from "node:assert/strict";
import test from "node:test";

import {
  buildProductDispatchAccountPdfUrl,
  buildProductDispatchAccountQuery,
  escapeProductDispatchAccountHtml,
  filterProductDispatchAccountClients,
  normalizeProductDispatchAccountCatalog,
  normalizeProductDispatchAccountStatement,
  productDispatchAccountDefaultPeriod,
  productDispatchAccountPriceModeLabel,
  validateProductDispatchAccountFilters,
} from "../../public/js/despacho-productos-estado-cuenta.js";

const validFilters = {
  client_id: 18,
  date_from: "2026-08-01",
  date_to: "2026-08-31",
  currency: "pen",
};

test("construye la consulta del estado de cuenta con el contrato esperado", () => {
  const params = new URLSearchParams(buildProductDispatchAccountQuery(validFilters));

  assert.equal(params.get("client_id"), "18");
  assert.equal(params.get("date_from"), "2026-08-01");
  assert.equal(params.get("date_to"), "2026-08-31");
  assert.equal(params.get("currency"), "PEN");
});

test("valida cliente, fechas, orden del periodo y moneda", () => {
  assert.equal(validateProductDispatchAccountFilters(validFilters).valid, true);

  const result = validateProductDispatchAccountFilters({
    client_id: "",
    date_from: "2026-09-20",
    date_to: "2026-09-01",
    currency: "soles",
  });

  assert.equal(result.valid, false);
  assert.match(result.errors.client_id, /cliente/i);
  assert.match(result.errors.date_to, /posterior/i);
  assert.match(result.errors.currency, /moneda/i);
});

test("arma una URL inline para la vista previa y otra sin preview para descargar", () => {
  const preview = new URL(buildProductDispatchAccountPdfUrl(
    "/despacho-productos/estado-cuenta/pdf?origen=modulo",
    validFilters,
    true,
  ), "https://avicola.test");
  const download = new URL(buildProductDispatchAccountPdfUrl(preview.pathname + preview.search, validFilters, false), "https://avicola.test");

  assert.equal(preview.searchParams.get("preview"), "1");
  assert.equal(preview.searchParams.get("origen"), "modulo");
  assert.equal(preview.searchParams.get("client_id"), "18");
  assert.equal(download.searchParams.has("preview"), false);
  assert.equal(download.searchParams.get("currency"), "PEN");
});

test("normaliza catálogo, ordena clientes y conserva la moneda predeterminada", () => {
  const catalog = normalizeProductDispatchAccountCatalog({
    data: {
      clients: [
        { id: "9", name: "Zeta", document_type: "RUC", document: "20999999999" },
        { id: 3, name: "Águila", document_type: "DNI", document: "12345678" },
      ],
      currencies: ["usd", "PEN", "USD", "invalida"],
      default_currency: "PEN",
      branch: { id: 4, name: "Principal" },
    },
  });

  assert.deepEqual(catalog.clients.map((client) => client.id), [3, 9]);
  assert.deepEqual(catalog.currencies, ["USD", "PEN"]);
  assert.equal(catalog.default_currency, "PEN");
  assert.equal(catalog.branch.name, "Principal");
});

test("normaliza totales y filas cronológicas sin convertir vacíos a valores reales", () => {
  const report = normalizeProductDispatchAccountStatement({
    data: {
      client: { id: 7, name: "Cliente Uno", document: "777" },
      period: { date_from: "2026-08-01", date_to: "2026-08-31" },
      currency: "pen",
      opening_balance: "120.50",
      sales_total: "80.25",
      payments_total: "30.00",
      ending_balance: "170.75",
      ticket_count: 2,
      payment_count: 1,
      rows: [{
        kind: "payment",
        payment_type: "descuento_cliente",
        movement_label: "Descuento",
        date: "2026-08-20",
        document: "PG-20",
        product: null,
        quantity: null,
        net_weight_kg: "",
        detail: "EFECTIVO",
        price: null,
        sale: null,
        payment: "30.00",
        balance: "170.75",
        show_balance: true,
      }],
    },
  });

  assert.equal(report.currency, "PEN");
  assert.equal(report.opening_balance, 120.5);
  assert.equal(report.ending_balance, 170.75);
  assert.equal(report.rows[0].kind, "PAYMENT");
  assert.equal(report.rows[0].payment_type, "DESCUENTO_CLIENTE");
  assert.equal(report.rows[0].movement_label, "Descuento");
  assert.equal(report.rows[0].quantity, null);
  assert.equal(report.rows[0].net_weight_kg, null);
  assert.equal(report.rows[0].payment, 30);
  assert.equal(report.rows[0].show_balance, true);
});

test("normaliza deudas anteriores y sus totales de cargos", () => {
  const report = normalizeProductDispatchAccountStatement({
    data: {
      currency: "pen",
      sales_total: "80.25",
      prior_debt_total: "125.50",
      charges_total: "205.75",
      prior_debt_count: "2",
      rows: [{
        kind: "prior_debt",
        date: "2026-08-05",
        document: "DA-00000021",
        movement_label: "Deuda anterior",
        detail: "Saldo pendiente antes del sistema",
        sale: "125.50",
        payment: "0.00",
        balance: "125.50",
        show_balance: true,
      }],
    },
  });

  assert.equal(report.prior_debt_total, 125.5);
  assert.equal(report.charges_total, 205.75);
  assert.equal(report.prior_debt_count, 2);
  assert.equal(report.rows[0].kind, "PRIOR_DEBT");
  assert.equal(report.rows[0].movement_label, "Deuda anterior");
  assert.equal(report.rows[0].sale, 125.5);

  const fallback = normalizeProductDispatchAccountStatement({
    data: {
      sales_total: "10.25",
      prior_debt_total: "4.75",
    },
  });

  assert.equal(fallback.charges_total, 15);
});

test("busca clientes sin distinguir mayúsculas ni acentos simples", () => {
  const clients = [
    { id: 1, name: "Mercado Central", document_type: "RUC", document: "201234" },
    { id: 2, name: "Avícola Norte", document_type: "DNI", document: "876543" },
  ];

  assert.deepEqual(filterProductDispatchAccountClients(clients, "central").map((client) => client.id), [1]);
  assert.deepEqual(filterProductDispatchAccountClients(clients, "avicola").map((client) => client.id), [2]);
  assert.deepEqual(filterProductDispatchAccountClients(clients, "876").map((client) => client.id), [2]);
});

test("expone etiquetas, escape seguro y periodo mensual predeterminado", () => {
  assert.equal(productDispatchAccountPriceModeLabel("POR_UNIDAD"), "Por unidad");
  assert.equal(productDispatchAccountPriceModeLabel("POR_KG"), "Por kilogramo");
  assert.equal(escapeProductDispatchAccountHtml('<b data-x="1">'), "&lt;b data-x=&quot;1&quot;&gt;");
  assert.deepEqual(productDispatchAccountDefaultPeriod(new Date(2026, 8, 2, 12)), {
    date_from: "2026-09-01",
    date_to: "2026-09-02",
  });
});
