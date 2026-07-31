import test from "node:test";
import assert from "node:assert/strict";

import { buildDailySummaryPrintHtml } from "../../public/js/daily-summary-printer.js";

test("la impresión de jornada contiene título, fecha, horario y tabla completa", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "31 de julio de 2026",
    windowLabel: "Desde 30/07/2026 a las 21:00 hasta 31/07/2026 a las 21:00 (hora final no incluida).",
    tableHtml: `
      <table class="daily-client-table">
        <thead><tr><th>Cliente</th><th>Peso neto</th></tr></thead>
        <tbody><tr><td>Cliente uno</td><td>125.000 kg</td></tr></tbody>
      </table>
    `
  });

  assert.match(html, /<h1>Resumen de la jornada<\/h1>/);
  assert.match(html, /Fecha: <strong>31 de julio de 2026<\/strong>/);
  assert.match(html, /Horario:<\/strong> Desde 30\/07\/2026 a las 21:00 hasta 31\/07\/2026 a las 21:00 \(hora final no incluida\)\./);
  assert.match(html, /<table class="daily-client-table">/);
  assert.match(html, /Cliente uno/);
  assert.match(html, /125\.000 kg/);
  assert.doesNotMatch(html, /Menú|Jornada a consultar|Administrar tickets/);
});

test("la impresión usa orientación horizontal y letra de 18 px", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "30/07/2026",
    windowLabel: "Desde 29/07/2026 a las 21:00 hasta 30/07/2026 a las 21:00.",
    tableHtml: "<table><tbody></tbody></table>"
  });

  assert.match(html, /@page \{[\s\S]*size: landscape;/);
  assert.match(html, /body \{[\s\S]*font-size: 18px;/);
  assert.match(html, /table \{[\s\S]*font-size: 18px;/);
  assert.match(html, /thead \{[\s\S]*display: table-header-group;/);
  assert.match(html, /tr \{[\s\S]*page-break-inside: avoid;/);
});

test("la fecha impresa se escapa antes de insertarla en el documento", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "<script>alert('x')</script>",
    windowLabel: "<img src=x onerror=alert('x')>",
    tableHtml: "<table><tbody></tbody></table>"
  });

  assert.doesNotMatch(html, /<script>alert/);
  assert.match(html, /&lt;script&gt;alert\(&#39;x&#39;\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<img src=x/);
  assert.match(html, /&lt;img src=x onerror=alert\(&#39;x&#39;\)&gt;/);
});
