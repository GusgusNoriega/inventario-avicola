import assert from "node:assert/strict";
import test from "node:test";

import {
  financeDateRangeContains,
  financeDateRangePhrase,
  formatFinanceFilterDate,
  isValidFinanceFilterDate
} from "../../public/js/finanzas-date-range.js";

test("formatea fechas del filtro sin depender de la zona horaria", () => {
  assert.equal(formatFinanceFilterDate("2026-07-31"), "31/07/2026");
});

test("valida fechas reales con el formato esperado por la API", () => {
  assert.equal(isValidFinanceFilterDate("2026-07-31"), true);
  assert.equal(isValidFinanceFilterDate("2026-02-29"), false);
  assert.equal(isValidFinanceFilterDate("31/07/2026"), false);
  assert.equal(isValidFinanceFilterDate(""), false);
});

test("considera inclusivos ambos extremos del rango", () => {
  assert.equal(financeDateRangeContains("2026-07-01", "2026-07-01", "2026-07-31"), true);
  assert.equal(financeDateRangeContains("2026-07-31T23:59:59", "2026-07-01", "2026-07-31"), true);
  assert.equal(financeDateRangeContains("2026-06-30", "2026-07-01", "2026-07-31"), false);
  assert.equal(financeDateRangeContains("2026-08-01", "2026-07-01", "2026-07-31"), false);
});

test("describe hoy, un solo día y un rango con textos claros", () => {
  assert.equal(financeDateRangePhrase("2026-07-31", "2026-07-31", "2026-07-31"), "de hoy");
  assert.equal(financeDateRangePhrase("2026-07-30", "2026-07-30", "2026-07-31"), "del 30/07/2026");
  assert.equal(financeDateRangePhrase("2026-07-01", "2026-07-31", "2026-07-31"), "del 01/07/2026 al 31/07/2026");
});
