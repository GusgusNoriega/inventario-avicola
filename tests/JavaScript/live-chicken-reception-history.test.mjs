import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
  buildHistoryQuery,
  buildHistoryReportUrl,
  historySourceLabel,
  normalizeHistoryPayload,
  renderHistoryRow,
} from "../../public/js/live-chicken-reception-history.js";

const view = readFileSync(
  new URL("../../resources/views/recepcion-pollo-vivo-historial.blade.php", import.meta.url),
  "utf8",
);
const source = readFileSync(
  new URL("../../public/js/recepcion-pollo-vivo-historial.js", import.meta.url),
  "utf8",
);
const stylesheet = readFileSync(
  new URL("../../public/css/recepcion-pollo-vivo-historial.css", import.meta.url),
  "utf8",
);

test("el historial consulta una jornada con estado, origen y paginación sin parámetros vacíos", () => {
  assert.equal(
    buildHistoryQuery({
      journey_id: 84,
      status: "anulada",
      source: "ticket",
      page: 3,
      per_page: 30,
    }),
    "page=3&per_page=30&journey_id=84&status=ANULADA&source=TICKET",
  );
  assert.equal(
    buildHistoryQuery({ journey_id: "", status: "", source: "" }),
    "page=1&per_page=30",
  );
  assert.match(source, /\/recepcion-pollo-vivo\/historial\?\$\{buildHistoryQuery\(request\)\}/);
  assert.doesNotMatch(source, /method:\s*["'](?:PUT|POST|PATCH|DELETE)["']/);
});

test("los reportes descargables usan únicamente la jornada seleccionada y rechazan identificadores inválidos", () => {
  assert.equal(
    buildHistoryReportUrl("pdf", 84),
    "/recepcion-pollo-vivo/historial/reporte/pdf?journey_id=84",
  );
  assert.equal(
    buildHistoryReportUrl("images", "92"),
    "/recepcion-pollo-vivo/historial/reporte/imagenes?journey_id=92",
  );
  assert.equal(buildHistoryReportUrl("pdf", ""), "");
  assert.equal(buildHistoryReportUrl("images", 0), "");
  assert.equal(buildHistoryReportUrl("spreadsheet", 84), "");

  assert.match(view, /id="liveHistoryReportPdf"[^>]*aria-disabled="true"/);
  assert.match(view, /id="liveHistoryReportImages"[^>]*aria-disabled="true"/);
  assert.match(source, /function updateReportLinks\(journeyId = elements\.journey\.value\)/);
  assert.match(source, /elements\.journey\.addEventListener\("change", \(\) => \{\s*updateReportLinks\(elements\.journey\.value\)/s);
  assert.match(stylesheet, /@media \(max-width:\s*1024px\)[\s\S]*?\.live-history-report-actions\s*\{[^}]*width:\s*100%/);
  assert.match(stylesheet, /\.live-history-report-btn\[aria-disabled="true"\]\s*\{[^}]*pointer-events:\s*none/);
});

test("normaliza por separado los totales activos, anulados y generales de la jornada", () => {
  const data = normalizeHistoryPayload({
    data: {
      current_journey_id: 92,
      selected_journey: { id: 84, operating_date: "2026-08-20", status: "CERRADA" },
      is_current_journey: false,
      catalog: { journeys: [{ id: 84, operating_date: "2026-08-20" }] },
      applied_filters: { status: "anulada", source: "recepcion" },
      summary: {
        active: { weighings: 3, cages: 8, birds: 56, gross_weight_kg: 112.5, tare_weight_kg: 16, net_weight_kg: 96.5 },
        voided: { weighings: 1, cages: 2, birds: 14, gross_weight_kg: 28, tare_weight_kg: 4, net_weight_kg: 24 },
        total: { weighings: 4, cages: 10, birds: 70, gross_weight_kg: 140.5, tare_weight_kg: 20, net_weight_kg: 120.5 },
      },
      records: [],
      pagination: { current_page: 1, last_page: 1, per_page: 30, total: 0, from: null, to: null },
    },
  });

  assert.equal(data.current_journey_id, 92);
  assert.equal(data.catalog.journeys[0].id, 84);
  assert.equal(data.applied_filters.status, "ANULADA");
  assert.equal(data.applied_filters.source, "RECEPCION");
  assert.deepEqual(data.summary.active, {
    weighings: 3,
    cages: 8,
    birds: 56,
    gross_weight_kg: 112.5,
    tare_weight_kg: 16,
    net_weight_kg: 96.5,
  });
  assert.equal(data.summary.voided.weighings, 1);
  assert.equal(data.summary.total.net_weight_kg, 120.5);

  const unfiltered = normalizeHistoryPayload({
    data: { applied_filters: { status: "TODAS", source: "TODAS" } },
  });
  assert.equal(unfiltered.applied_filters.status, "");
  assert.equal(unfiltered.applied_filters.source, "");
});

test("cada fila física empieza con peso bruto, termina con registro y escapa datos externos", () => {
  const row = renderHistoryRow({
    id: 501,
    record_kind: "DISPATCH_TICKET_WEIGHING",
    source: "TICKET",
    status: "ANULADA",
    number: 7,
    lane: 6,
    weighed_at: "2026-08-20T15:30:00-05:00",
    owner: { type: "PROPIA", name: "Avícola <Central>" },
    destination: { type: "CLIENTE", name: "Cliente & Mercado" },
    sex: "HEMBRA",
    cage_type: { code: "JAVA-7", name: "Java 7 kg" },
    birds_per_cage: 7,
    cages: 4,
    birds: 28,
    gross_weight_kg: 60,
    tare_weight_kg: 8,
    net_weight_kg: 52,
    weight_source: "BALANZA_RECEPCION_POLLO_VIVO",
    ticket: { id: 90, code: "T-<090>" },
  });

  const grossIndex = row.indexOf('data-label="Peso bruto"');
  const registrationIndex = row.indexOf('data-label="Registro"');
  assert.ok(grossIndex >= 0);
  assert.ok(registrationIndex > grossIndex);
  assert.equal(row.indexOf('data-label="Registro"', registrationIndex + 1), -1);
  assert.match(row, /class="is-voided"/);
  assert.match(row, /Ticket de despacho/);
  assert.match(row, /T-&lt;090&gt; · Pesada #7/);
  assert.match(row, /Avícola &lt;Central&gt;/);
  assert.match(row, /Cliente &amp; Mercado/);
  assert.doesNotMatch(row, /T-<090>|Avícola <Central>|Cliente & Mercado/);
  assert.equal(historySourceLabel("RECEPCION"), "Entrada de recepción");
});

test("la vista ofrece estados de carga, vacío y error y se adapta a tablet y móvil", () => {
  const headers = [...view.matchAll(/<th scope="col">([^<]+)<\/th>/g)].map((match) => match[1]);
  assert.deepEqual(headers, [
    "Peso bruto",
    "Tara",
    "Peso neto",
    "Javas",
    "Pollos",
    "Sexo",
    "Propietario",
    "Destino",
    "Tipo de java",
    "Origen",
    "Estado",
    "Registro",
  ]);
  assert.match(view, /Cargando pesadas de la jornada/);
  assert.match(source, /No se encontraron pesadas con los filtros seleccionados/);
  assert.match(source, /No se pudo cargar el detalle de pesadas/);
  assert.match(source, /data-live-history-retry/);
  assert.match(stylesheet, /\.live-history-table-wrap\s*\{[^}]*overflow:\s*auto;[^}]*touch-action:\s*pan-x pan-y pinch-zoom;/s);
  assert.match(stylesheet, /\.live-history-table\s*\{[^}]*min-width:\s*1580px;/s);
  assert.match(stylesheet, /@media \(max-width:\s*820px\)/);
  assert.match(stylesheet, /@media \(max-width:\s*700px\)[\s\S]*?\.live-history-table td::before/);
  assert.match(stylesheet, /@media \(pointer:\s*coarse\)[\s\S]*?min-height:\s*52px/);
});
