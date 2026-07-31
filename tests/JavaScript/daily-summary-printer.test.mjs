import test from "node:test";
import assert from "node:assert/strict";

import { buildDailySummaryPrintHtml } from "../../public/js/daily-summary-printer.js";

test("la impresión de jornada contiene título, fecha, horario y tabla completa", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "31 de julio de 2026",
    windowLabel: "Desde 30/07/2026 a las 21:00 hasta 31/07/2026 a las 21:00 (hora final no incluida).",
    tableHtml: `
      <table class="daily-client-table">
        <thead>
          <tr><th>Cliente</th><th>Ave</th><th>Num. javas</th><th>Cant. aves</th><th>Peso bruto</th><th>Tara</th><th>Devoluciones</th><th>Peso neto</th></tr>
        </thead>
        <tbody>
          <tr data-print-price="8.5000" data-print-amount="1095.00">
            <td>Cliente uno</td><td>P V</td><td>2</td><td>50</td>
            <td data-print-weight="114.567">114.567 kg</td>
            <td data-print-weight="14">14.000 kg</td>
            <td class="daily-client-return" data-print-weight="3.5"><strong>3.500 kg</strong></td>
            <td class="daily-client-net" data-print-weight="97.067"><strong>97.067 kg</strong></td>
          </tr>
        </tbody>
      </table>
    `
  });

  assert.match(html, /<h1>Resumen de la jornada<\/h1>/);
  assert.match(html, /Fecha: <strong>31 de julio de 2026<\/strong>/);
  assert.match(html, /Horario:<\/strong> Desde 30\/07\/2026 a las 21:00 hasta 31\/07\/2026 a las 21:00 \(hora final no incluida\)\./);
  assert.match(html, /<table class="daily-client-table">/);
  assert.match(html, /Cliente uno/);
  assert.match(html, /<th>Javas<\/th><th>Aves<\/th><th>P\. bruto<\/th><th>Tara<\/th><th>Dev\.<\/th><th>P\. neto<\/th>/);
  assert.match(html, /<th>Precio<\/th><th>Importe<\/th>/);
  assert.match(html, />114\.57<\/td>/);
  assert.match(html, />14\.00<\/td>/);
  assert.match(html, />3\.50<\/td>/);
  assert.match(html, />97\.07<\/td>/);
  assert.match(html, /<td class="daily-client-price">8\.50<\/td><td class="daily-client-amount">1095\.00<\/td>/);
  assert.doesNotMatch(html, /data-print-(?:price|amount|weight)=/);
  assert.doesNotMatch(html, /\d(?:\.\d+)? kg\b|Peso bruto|Peso neto|Devoluciones|Num\. javas|Cant\. aves/);
  assert.doesNotMatch(html, /Menú|Jornada a consultar|Administrar tickets/);
});

test("la impresión distingue precios variados y faltantes sin inventar importes", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "31 de julio de 2026",
    windowLabel: "Desde 30/07/2026 a las 21:00 hasta 31/07/2026 a las 21:00.",
    tableHtml: `
      <table>
        <thead><tr><th>Cliente</th><th>Ave</th><th>Num. javas</th><th>Cant. aves</th><th>Peso bruto</th><th>Tara</th><th>Devoluciones</th><th>Peso neto</th></tr></thead>
        <tbody>
          <tr data-print-price="VARIOS" data-print-amount="123.4"><td>Cliente mixto</td></tr>
          <tr data-print-price="SIN PRECIO" data-print-amount=""><td>Cliente incompleto</td></tr>
        </tbody>
      </table>
    `
  });

  assert.match(html, /<td class="daily-client-price">VARIOS<\/td><td class="daily-client-amount">123\.40<\/td>/);
  assert.match(html, /<td class="daily-client-price">SIN PRECIO<\/td><td class="daily-client-amount">--<\/td>/);
  assert.doesNotMatch(html, /data-print-(?:price|amount)=/);
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
