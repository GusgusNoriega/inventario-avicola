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
          <tr><th>Cliente</th><th>Ave</th><th>Num. javas</th><th>Bandejas</th><th>Cant. aves</th><th>Peso bruto</th><th>Tara</th><th>Devoluciones</th><th>Peso neto</th></tr>
        </thead>
        <tbody>
          <tr data-print-price="8.5000" data-print-amount="1095.00">
            <td>Cliente uno</td><td>P V</td><td>2</td><td>3</td><td>50</td>
            <td data-print-weight="114.567">114.567 kg</td>
            <td data-print-weight="14">14.000 kg</td>
            <td class="daily-client-return" data-print-weight="3.5"><strong>3.500 kg</strong></td>
            <td class="daily-client-net" data-print-weight="97.067"><strong>97.067 kg</strong></td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="daily-summary-total" data-print-price="" data-print-amount="1095.00">
            <td colspan="2"><strong>TOTAL GENERAL</strong></td><td>2</td><td>3</td><td>50</td>
            <td data-print-weight="114.567"><strong>114.567 kg</strong></td>
            <td data-print-weight="14"><strong>14.000 kg</strong></td>
            <td data-print-weight="3.5"><strong>3.500 kg</strong></td>
            <td data-print-weight="97.067"><strong>97.067 kg</strong></td>
          </tr>
        </tfoot>
      </table>
    `
  });

  assert.match(html, /<h1>Resumen de la jornada<\/h1>/);
  assert.match(html, /Fecha: <strong>31 de julio de 2026<\/strong>/);
  assert.match(html, /Horario:<\/strong> Desde 30\/07\/2026 a las 21:00 hasta 31\/07\/2026 a las 21:00 \(hora final no incluida\)\./);
  assert.match(html, /<table class="daily-client-table">/);
  assert.match(html, /Cliente uno/);
  assert.match(html, /<th>Javas<\/th><th>Band\.<\/th><th>Aves<\/th><th>P\. bruto<\/th><th>Tara<\/th><th>Dev\.<\/th><th>P\. neto<\/th>/);
  assert.match(html, /<th>Precio<\/th><th>Importe<\/th>/);
  assert.match(html, />114\.57<\/td>/);
  assert.match(html, />14\.00<\/td>/);
  assert.match(html, />3\.50<\/td>/);
  assert.match(html, />97\.07<\/td>/);
  assert.match(html, /<td class="daily-client-price">8\.50<\/td><td class="daily-client-amount">1095\.00<\/td>/);
  assert.match(html, /class="daily-summary-total"/);
  assert.match(html, /TOTAL GENERAL/);
  assert.match(html, /<td class="daily-client-price">--<\/td><td class="daily-client-amount">1095\.00<\/td><\/tr>/);
  assert.doesNotMatch(html, /data-print-(?:price|amount|weight)=/);
  assert.doesNotMatch(html, /\d(?:\.\d+)? kg\b|Peso bruto|Peso neto|Devoluciones|Num\. javas|Bandejas|Cant\. aves/);
  assert.doesNotMatch(html, /Menú|Jornada a consultar|Administrar tickets/);
});

test("la impresión conserva filas separadas por tipo y precio sin usar VARIOS", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "31 de julio de 2026",
    windowLabel: "Desde 30/07/2026 a las 21:00 hasta 31/07/2026 a las 21:00.",
    tableHtml: `
      <table>
        <thead><tr><th>Cliente</th><th>Ave</th><th>Num. javas</th><th>Cant. aves</th><th>Peso bruto</th><th>Tara</th><th>Devoluciones</th><th>Peso neto</th></tr></thead>
        <tbody>
          <tr data-print-price="8.5000" data-print-amount="85.00"><td>Cliente mixto</td><td>P V</td></tr>
          <tr data-print-price="8.5040" data-print-amount="85.04"><td>Cliente mixto</td><td>P V</td></tr>
          <tr data-print-price="10.0000" data-print-amount="500.00"><td>Cliente mixto</td><td>P P</td></tr>
          <tr data-print-price="SIN PRECIO" data-print-amount=""><td>Cliente incompleto</td><td>P V</td></tr>
        </tbody>
      </table>
    `
  });

  assert.match(html, /Cliente mixto<\/td><td>P V<\/td><td class="daily-client-price">8\.50<\/td><td class="daily-client-amount">85\.00<\/td>/);
  assert.match(html, /Cliente mixto<\/td><td>P V<\/td><td class="daily-client-price">8\.5040<\/td><td class="daily-client-amount">85\.04<\/td>/);
  assert.match(html, /Cliente mixto<\/td><td>P P<\/td><td class="daily-client-price">10\.00<\/td><td class="daily-client-amount">500\.00<\/td>/);
  assert.match(html, /<td class="daily-client-price">SIN PRECIO<\/td><td class="daily-client-amount">--<\/td>/);
  assert.equal((html.match(/class="daily-client-price"/g) || []).length, 4);
  assert.doesNotMatch(html, /VARIOS/);
  assert.doesNotMatch(html, /data-print-(?:price|amount)=/);
});

test("el total no presenta un subtotal engañoso cuando falta un precio", () => {
  const html = buildDailySummaryPrintHtml({
    dateLabel: "31 de julio de 2026",
    windowLabel: "Desde 30/07/2026 a las 21:00 hasta 31/07/2026 a las 21:00.",
    tableHtml: `
      <table>
        <thead><tr><th>Cliente</th><th>Ave</th><th>Num. javas</th><th>Bandejas</th><th>Cant. aves</th><th>Peso bruto</th><th>Tara</th><th>Devoluciones</th><th>Peso neto</th></tr></thead>
        <tbody></tbody>
        <tfoot>
          <tr class="daily-summary-total" data-print-price="" data-print-amount="SIN PRECIO">
            <td colspan="2">TOTAL GENERAL</td><td>0</td><td>1</td><td>10</td><td>10.00</td><td>0.00</td><td>0.00</td><td>10.00</td>
          </tr>
        </tfoot>
      </table>
    `
  });

  assert.match(html, /<td class="daily-client-price">--<\/td><td class="daily-client-amount">SIN PRECIO<\/td>/);
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
