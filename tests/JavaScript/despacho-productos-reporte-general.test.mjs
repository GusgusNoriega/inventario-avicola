import assert from "node:assert/strict";
import test from "node:test";
import {
  buildProductDispatchGeneralPdfUrl,
  buildProductDispatchGeneralQuery,
  escapeProductDispatchGeneralHtml,
  formatProductDispatchGeneralDate,
  formatProductDispatchGeneralNumber,
  mountProductDispatchGeneralReport,
  normalizeProductDispatchGeneralReport,
  productDispatchGeneralFiltersMatch,
  renderProductDispatchGeneralAmounts,
  renderProductDispatchGeneralDay,
  validateProductDispatchGeneralFilters,
} from "../../public/js/despacho-productos-reporte-general.js";

function report(from = "2026-09-05", to = from, quantity = "24") {
  const totals = {
    product_count: 1, ticket_count: 2, weighing_count: 3, quantity,
    read_weight_kg: "50.250", waste_weight_kg: "1.100", tare_weight_kg: "2.150",
    net_weight_kg: "47.000", amounts: [{ currency: "PEN", amount: "470.00" }, { currency: "USD", amount: "2.00" }],
  };
  return { data: {
    period: { from, to }, today: "2026-09-05", generated_at: "2026-09-05T16:30:00Z",
    branch: { id: 7, name: "Principal", code: "P01", timezone: "America/Lima" },
    summary: { ...totals, day_count: 1 },
    days: [{ ...totals, date: from, products: [{ ...totals, product_id: 1, product_name: "Pollo" }] }],
  } };
}

class FakeElement {
  constructor() {
    this.value = "";
    this.textContent = "";
    this.innerHTML = "";
    this.hidden = false;
    this.disabled = false;
    this.attributes = new Map();
    this.listeners = new Map();
    this.classList = { toggle() {} };
    this.span = { textContent: "" };
  }
  querySelector() { return this.span; }
  setAttribute(key, value) { this.attributes.set(key, value); }
  removeAttribute(key) { this.attributes.delete(key); }
  addEventListener(event, listener) { this.listeners.set(event, listener); }
  focus() { this.focused = true; }
  emit(event) { this.listeners.get(event)?.({ preventDefault() {} }); }
}

function mounted(apiRequest) {
  const elements = new Map();
  const root = {
    dataset: { apiBase: "/despacho-productos/reporte-general", pdfUrl: "/despacho-productos/reporte-general/pdf" },
    querySelector(selector) {
      if (!elements.has(selector)) elements.set(selector, new FakeElement());
      return elements.get(selector);
    },
  };
  const controller = mountProductDispatchGeneralReport(root, { apiRequest, authSession: { getToken: () => "test-report-token", clear() {} } });
  return { ...controller, get: (name) => root.querySelector(`#pdgr${name}`) };
}

test("el valor predeterminado consulta hoy en el servidor sin enviar fechas del navegador", () => {
  assert.equal(buildProductDispatchGeneralQuery({}, { allowDefault: true }), "");
  assert.deepEqual(validateProductDispatchGeneralFilters({}, { allowDefault: true }).values, {});
  assert.equal(validateProductDispatchGeneralFilters({}).valid, false);
  assert.throws(() => buildProductDispatchGeneralQuery({}), /Desde/);
  assert.equal(validateProductDispatchGeneralFilters({ date_to: "2026-09-05" }, { allowDefault: true }).valid, false);
});

test("Hasta vacío se normaliza a Desde y acepta rangos inclusivos o un solo día", () => {
  const validation = validateProductDispatchGeneralFilters({ date_from: "2026-09-05", date_to: "" });
  assert.equal(validation.valid, true);
  assert.deepEqual(validation.values, { date_from: "2026-09-05", date_to: "2026-09-05" });
  assert.equal(buildProductDispatchGeneralQuery({ date_from: "2026-09-05" }), "date_from=2026-09-05&date_to=2026-09-05");
  assert.equal(validateProductDispatchGeneralFilters({ date_from: "2026-09-01", date_to: "2026-09-05" }).valid, true);
});

test("rechaza fechas inexistentes, rangos invertidos y Desde vacío sin sustituirlos silenciosamente", () => {
  assert.equal(validateProductDispatchGeneralFilters({ date_from: "2026-02-29" }).valid, false);
  assert.equal(validateProductDispatchGeneralFilters({ date_from: "2024-02-29" }).valid, true);
  assert.match(validateProductDispatchGeneralFilters({ date_from: "2026-09-05", date_to: "2026-09-04" }).errors.date_to, /posterior/);
  assert.match(validateProductDispatchGeneralFilters({ date_from: "2026-09-05", date_to: "2026-04-31" }).errors.date_to, /válida/);
  assert.equal(validateProductDispatchGeneralFilters({ date_from: "" }).valid, false);
});

test("el PDF usa el periodo visible completo y reemplaza parámetros previos", () => {
  const filters = { date_from: "2026-09-01", date_to: "2026-09-05" };
  const url = new URL(buildProductDispatchGeneralPdfUrl("/reporte/pdf?date_from=2020-01-01&date_to=2020-02-01&preview=1#page=1", filters), "https://avicola.test");
  assert.equal(url.searchParams.get("date_from"), "2026-09-01");
  assert.equal(url.searchParams.get("date_to"), "2026-09-05");
  assert.equal(url.searchParams.has("preview"), false);
  assert.equal(url.hash, "#page=1");
  assert.equal(productDispatchGeneralFiltersMatch({ ...filters, date_to: "" }, filters), false);
  assert.equal(productDispatchGeneralFiltersMatch({ date_from: "2026-09-01", date_to: "" }, { date_from: "2026-09-01", date_to: "2026-09-01" }), true);
  assert.equal(productDispatchGeneralFiltersMatch({}, filters), false);
  assert.throws(() => buildProductDispatchGeneralPdfUrl("/reporte/pdf", null), TypeError);
});

test("formatea cantidades grandes sin perder precisión y conserva tres decimales en pesos", () => {
  assert.equal(formatProductDispatchGeneralNumber("9007199254740993123"), "9,007,199,254,740,993,123");
  assert.equal(formatProductDispatchGeneralNumber("1250.010", 3), "1,250.010");
  assert.equal(formatProductDispatchGeneralNumber("9007199254740993.21", 2), "9,007,199,254,740,993.21");
  assert.equal(formatProductDispatchGeneralNumber("9.2", 3), "9.200");
  assert.equal(formatProductDispatchGeneralNumber(null, 3), "0.000");
  assert.match(formatProductDispatchGeneralDate("2026-09-05", true), /sábado.*5.*se(?:p)?tiembre.*2026/);
  assert.equal(formatProductDispatchGeneralDate("2026-02-30"), "—");
});

test("normaliza y ordena días y productos, mantiene cada moneda y escapa etiquetas externas", () => {
  const source = report();
  source.data.days[0].products.push({ ...source.data.days[0].products[0], product_id: 2, product_name: '<img src="x" onerror="alert(1)">' });
  source.data.days[0].products.push({ ...source.data.days[0].products[0], product_id: 3, product_name: "Águila" });
  source.data.days.unshift({ ...source.data.days[0], date: "2026-09-06" });
  const result = normalizeProductDispatchGeneralReport(source);
  assert.deepEqual(result.days.map((day) => day.date), ["2026-09-05", "2026-09-06"]);
  assert.deepEqual(result.days[0].products.map((product) => product.product_id), [2, 3, 1]);
  assert.deepEqual(result.summary.amounts, [{ currency: "PEN", amount: "470.00" }, { currency: "USD", amount: "2.00" }]);
  const html = renderProductDispatchGeneralDay(result.days[0]);
  assert.match(html, /TOTAL DEL DÍA/);
  assert.match(html, /&lt;img src=&quot;x&quot;/);
  assert.doesNotMatch(html, /<img/);
  assert.match(html, /PEN 470\.00/);
  assert.match(html, /USD 2\.00/);
  assert.equal(escapeProductDispatchGeneralHtml("<&'\""), "&lt;&amp;&#039;&quot;");
  assert.doesNotMatch(renderProductDispatchGeneralAmounts([{ currency: '<script>', amount: 0 }]), /<script>/);
});

test("no presenta una respuesta incompleta como un reporte vacío válido", () => {
  assert.throws(() => normalizeProductDispatchGeneralReport({ data: {} }), /incompleto/);
});

test("muestra el producto y cada subproducto por separado, incluidos los históricos, conservando los totales", () => {
  const source = report();
  const base = source.data.days[0].products[0];
  source.data.days[0].product_count = 4;
  source.data.days[0].products = [
    { ...base, variation_id: 11, variation_name: "Pechuga", display_name: "Pollo · Pechuga", quantity: "6" },
    { ...base, variation_id: null, variation_name: "Menudencia <especial>", quantity: "5" },
    { ...base, variation_id: null, variation_name: null, display_name: "Pollo", quantity: "10" },
    { ...base, variation_id: 12, variation_name: "Alas", display_name: "Pollo · Alas", quantity: "3" },
  ];
  const normalized = normalizeProductDispatchGeneralReport(source);
  const day = normalized.days[0];
  assert.deepEqual(day.products.map((product) => product.display_name), [
    "Pollo", "Pollo · Alas", "Pollo · Menudencia <especial>", "Pollo · Pechuga",
  ]);
  assert.deepEqual(day.products.map((product) => product.quantity), ["10", "3", "5", "6"]);
  assert.equal(day.products[2].variation_id, null);
  assert.equal(day.products[2].variation_name, "Menudencia <especial>");
  assert.equal(day.product_count, 4);
  assert.equal(day.quantity, "24");
  assert.equal(normalized.summary.quantity, "24");
  const html = renderProductDispatchGeneralDay(day);
  assert.match(html, /<th scope="row">Pollo<\/th>/);
  assert.match(html, /<th scope="row">Pollo · Alas<\/th>/);
  assert.match(html, /<th scope="row">Pollo · Pechuga<\/th>/);
  assert.match(html, /Pollo · Menudencia &lt;especial&gt;/);
  assert.doesNotMatch(html, /<especial>/);
  assert.match(html, /TOTAL DEL DÍA<\/th><td class="is-number">24<\/td>/);
});

test("monta hoy de la sucursal dejando Hasta vacío y bloquea descarga cuando los filtros cambian", async () => {
  const requests = [];
  const app = mounted(async (path, options) => { requests.push({ path, options }); return report(); });
  assert.equal(await app.ready, true);
  assert.equal(requests[0].path, "/despacho-productos/reporte-general");
  assert.equal(requests[0].options.cache, "no-store");
  assert.equal(app.get("DateFrom").value, "2026-09-05");
  assert.equal(app.get("DateTo").value, "");
  assert.equal(app.get("DownloadPdf").disabled, false);
  assert.equal(app.get("ReportTitle").textContent, "Los despachos de hoy");
  assert.equal(app.get("NetWeight").textContent, "47.000");
  app.get("DateFrom").value = "2026-09-04";
  app.get("DateFrom").emit("input");
  assert.equal(app.get("DownloadPdf").disabled, true);
  assert.equal(app.get("PdfHint").hidden, false);
  assert.match(app.get("Days").innerHTML, /TOTAL DEL DÍA/);
  await app.downloadPdf();
  assert.equal(app.get("DownloadPdf").disabled, true);
});

test("las respuestas antiguas no sobrescriben la consulta más reciente", async () => {
  const requests = [];
  const app = mounted((path) => new Promise((resolve) => requests.push({ path, resolve })));
  app.get("DateFrom").value = "2026-09-04";
  const recent = app.loadReport({ date_from: "2026-09-04", date_to: "" });
  requests[1].resolve(report("2026-09-04", "2026-09-04", "88"));
  assert.equal(await recent, true);
  requests[0].resolve(report());
  assert.equal(await app.ready, false);
  assert.equal(app.get("Quantity").textContent, "88");
  assert.equal(app.get("DateFrom").value, "2026-09-04");
  assert.equal(app.get("Report").attributes.get("aria-busy"), "false");
  assert.equal(app.get("DownloadPdf").disabled, false);
});

test("distingue errores reintentables de periodos sin movimientos", async () => {
  let attempts = 0;
  const app = mounted(async () => {
    if (++attempts === 1) throw new Error("Servicio temporalmente no disponible");
    const empty = report();
    empty.data.days = [];
    empty.data.summary = { day_count: 0, product_count: 0, ticket_count: 0, weighing_count: 0, quantity: "0", net_weight_kg: "0.000", amounts: [] };
    return empty;
  });
  assert.equal(await app.ready, false);
  assert.equal(app.get("Retry").hidden, false);
  assert.equal(app.get("DownloadPdf").disabled, true);
  assert.equal(app.get("DateFrom").disabled, false);
  assert.equal(await app.loadReport(null), true);
  assert.equal(app.get("Retry").hidden, true);
  assert.equal(app.get("StatusTitle").textContent, "Sin despachos en este periodo");
  assert.equal(app.get("TicketCount").textContent, "0");
  assert.equal(app.get("DownloadPdf").disabled, false);
});

test("descarga PDF autenticado con fechas del reporte visible y nombre del periodo", async () => {
  const originalFetch = globalThis.fetch;
  const originalDocument = globalThis.document;
  const originalWindow = globalThis.window;
  const downloads = [];
  let requested;
  try {
    globalThis.fetch = async (url, options) => {
      requested = { url, options };
      return { ok: true, status: 200, headers: new Headers({ "content-type": "application/pdf" }), blob: async () => new Blob(["%PDF-1.4"], { type: "application/pdf" }) };
    };
    globalThis.document = {
      body: { append() {} },
      createElement: () => ({ click() { downloads.push(this.download); }, remove() {} }),
    };
    globalThis.window = { setTimeout(callback) { callback(); } };
    const app = mounted(async () => report());
    await app.ready;
    await app.downloadPdf();
    assert.equal(requested.url, "/despacho-productos/reporte-general/pdf?date_from=2026-09-05&date_to=2026-09-05");
    assert.equal(requested.options.headers.get("Authorization"), "Bearer test-report-token");
    assert.equal(requested.options.headers.get("Accept"), "application/pdf");
    assert.equal(requested.options.credentials, "same-origin");
    assert.deepEqual(downloads, ["reporte-general_2026-09-05.pdf"]);
    assert.equal(app.get("DownloadPdf").disabled, false);
    assert.match(app.get("Message").textContent, /descarga/);
  } finally {
    globalThis.fetch = originalFetch;
    if (originalDocument === undefined) delete globalThis.document; else globalThis.document = originalDocument;
    if (originalWindow === undefined) delete globalThis.window; else globalThis.window = originalWindow;
  }
});

test("una respuesta HTML en la descarga muestra error y permite reintentar sin crear un PDF falso", async () => {
  const originalFetch = globalThis.fetch;
  try {
    globalThis.fetch = async () => ({ ok: true, status: 200, headers: new Headers({ "content-type": "text/html" }) });
    const app = mounted(async () => report());
    await app.ready;
    await app.downloadPdf();
    assert.match(app.get("Message").textContent, /No se pudo generar el PDF/);
    assert.equal(app.get("DownloadPdf").disabled, false);
    assert.equal(app.get("DateFrom").disabled, false);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
