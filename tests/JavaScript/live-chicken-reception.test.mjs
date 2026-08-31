import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { normalizeRetailClientSearch } from "../../public/js/retail-client-search.js";
import {
  assignDispatchClientToPendingCapture,
  freezeDispatchClientCorrection,
} from "../../public/js/live-chicken-reception-pending.js";
import {
  buildReceptionTicketPayload,
  buildTicketUpdatePayload,
  calculateDraftTotals,
  dispatchDraftFingerprint,
  newestReceptionRowsFirst,
  nextDraftWeighingNumber,
  normalizeDispatchDraft,
  receptionSummaryRows,
  remainingDispatchDraftAfterRegistration,
  receptionRecordLane,
} from "../../public/js/live-chicken-reception-tickets.js";

const source = readFileSync(
  new URL("../../public/js/recepcion-pollo-vivo.js", import.meta.url),
  "utf8",
);
const view = readFileSync(
  new URL("../../resources/views/recepcion-pollo-vivo.blade.php", import.meta.url),
  "utf8",
);
const stylesheet = readFileSync(
  new URL("../../public/css/recepcion-pollo-vivo.css", import.meta.url),
  "utf8",
);

test("cada columna define el propietario y las entradas definen también el sexo", () => {
  assert.doesNotMatch(view, /data-live-owner=/);
  assert.match(source, /const LANE_NUMBERS = \[1, 2, 3, 4, 5, 6\]/);
  assert.match(source, /1: \{ type: "ALMACEN", ownerType: "PROPIA", sex: "MACHO" \}/);
  assert.match(source, /2: \{ type: "ALMACEN", ownerType: "PROPIA", sex: "HEMBRA" \}/);
  assert.match(source, /3: \{ type: "ALMACEN", ownerType: "EXTERNA", sex: "MACHO" \}/);
  assert.match(source, /4: \{ type: "ALMACEN", ownerType: "EXTERNA", sex: "HEMBRA" \}/);
  assert.match(source, /5: \{ type: "CLIENTE", ownerType: "PROPIA", sex: null \}/);
  assert.match(source, /elements\.sexChoice\.hidden = Boolean\(profile\.sex\)/);
  assert.match(source, /\.\.\.\(profile\.sex \? \{\} : \{ sex: state\.sex \}\)/);
  assert.match(source, /layout_version: LAYOUT_VERSION/);
  assert.match(source, /const LAYOUT_VERSION = 4/);
  assert.match(source, /totals\?\.external/);
  assert.match(view, /id="liveIntakeExternalBirds"/);
});

test("las columnas identifican sexo y propietario con señales visuales independientes", () => {
  assert.match(view, /\{\{ \$ownLane \? 'is-own-lane' : 'is-external-lane' \}\}/);
  assert.match(view, /\{\{ \$maleLane \? 'is-male-lane' : 'is-female-lane' \}\}/);
  assert.match(view, /id="lir-icon-rooster"/);
  assert.match(view, /id="lir-icon-hen"/);
  assert.match(view, /class="lir-lane-sex-icon" aria-hidden="true" focusable="false"/);
  assert.match(view, /href="#\{\{ \$maleLane \? 'lir-icon-rooster' : 'lir-icon-hen' \}\}"/);
  assert.match(view, /\{\{ \$maleLane \? 'Gallo · Macho' : 'Gallina · Hembra' \}\}/);
  assert.match(view, /class="lir-lane-owner-badge"/);
  assert.match(source, /\? `Externa · \$\{externalOwner\?\.name \|\| "sin configurar"\}`/);
  assert.match(source, /elements\.laneProfileLabels\[lane - 1\]\.title = profileLabel/);
  assert.match(stylesheet, /\.lir-lane\.is-own-lane \{[^}]*--lir-lane-owner-accent: var\(--lir-green\)/);
  assert.match(stylesheet, /\.lir-lane\.is-external-lane \{[^}]*--lir-lane-owner-accent: var\(--lir-amber\)/);
  assert.match(stylesheet, /\.lir-lane\.is-male-lane \{[^}]*--lir-lane-sex-accent: var\(--lir-blue\)/);
  assert.match(stylesheet, /\.lir-lane\.is-female-lane \{[^}]*--lir-lane-sex-accent: var\(--lir-pink\)/);
  assert.match(stylesheet, /\.lir-lane\.is-warehouse \{[^}]*border-color: var\(--lir-lane-owner-accent\)/);
  assert.match(stylesheet, /\.lir-lane\.is-warehouse \.lir-lane-select \{[^}]*border-top: 4px solid var\(--lir-lane-sex-accent\)/);
  assert.match(stylesheet, /\.lir-lane\.is-active \{[^}]*outline: 5px solid rgba\(245, 248, 251, \.96\);[^}]*outline-offset: -5px;[^}]*var\(--lir-lane-owner-accent\)/);
  assert.match(stylesheet, /\.lir-sex-chip\.is-female \{[^}]*color: var\(--lir-pink\)/);
});

test("cada borrador permite elegir un cliente distinto y lo bloquea al tener pesadas", () => {
  assert.match(view, /data-live-choose-client="\{\{ \$lane \}\}"/);
  assert.match(view, /id="liveIntakeClientModal"[\s\S]*?role="dialog" aria-modal="true"/);
  assert.match(view, /id="liveIntakeClientSearch" type="search"/);
  assert.match(view, /id="liveIntakeClientOptions"[\s\S]*?role="region"/);
  assert.match(source, /data-live-client-option="\$\{client\.id\}" aria-pressed="\$\{selected\}"/);
  assert.match(view, /Columna 5 · Cliente predeterminado/);
  assert.match(view, /Columna 6 · Cliente predeterminado/);
  assert.match(source, /directClientIds: \{ 5: null, 6: null \}/);
  assert.match(source, /function openClientPicker\(laneNumber, trigger\)/);
  assert.match(source, /normalizeRetailClientSearch\(client\.document_number\)/);
  assert.match(source, /state\.directClientIds\[lane\] = Number\(client\.id\)/);
  assert.match(source, /dispatch_client_id: Number\(dispatchClient\.id\)/);
  assert.match(source, /dispatch_client_name: String\(dispatchClient\.name\)/);
  assert.match(source, /delete requestPayload\.dispatch_client_name/);
  assert.match(source, /state\.directClientIds\[lane\] = needsDispatchClientReselection \? null : dispatchClientId/);
  assert.match(source, /const correctingThisLane = state\.pendingDispatchClientCorrection/);
  assert.match(stylesheet, /\.lir-client-picker-trigger \{[^}]*min-height: 48px;/);
  assert.match(stylesheet, /\.lir-client-options \{[^}]*overflow-y: auto;/);
  assert.match(stylesheet, /\.lir-modal-card\.is-client-picker \{[^}]*display: grid;[^}]*grid-template-rows: auto minmax\(0, 1fr\) auto;[^}]*overflow: hidden;/);
  assert.doesNotMatch(stylesheet, /\.lir-modal-card\.is-client-picker \{[^}]*max-height: calc\(100dvh - 16px\)/);
  assert.match(stylesheet, /\.lir-client-picker-body \{[^}]*min-height: 0;[^}]*grid-template-rows: auto auto minmax\(0, 1fr\);[^}]*overflow: hidden;/);
  assert.match(stylesheet, /\.lir-modal-card > header button \{[^}]*width: 44px;[^}]*min-height: 44px;/);
  assert.match(source, /"Totales de toda la columna"/);
  assert.match(source, /dispatchDraft\(lane\)\.weighings\.length > 0/);
  assert.match(source, /Cliente bloqueado/);
  assert.match(view, /data-live-register-ticket="\{\{ \$lane \}\}"/);
});

test("el selector muestra todo el catálogo y no recorta las búsquedas de más de cien clientes", () => {
  const clients = [
    ...Array.from({ length: 150 }, (_, index) => ({
      id: index + 1,
      name: `Agrogránja ${index + 1}`,
      document_number: String(90000000 + index),
    })),
    { id: 151, name: "Gránja Principal", document_number: "90100000" },
    { id: 152, name: "GRÁNJA Interna", document_number: "90200000", is_internal_client: true },
  ];
  const originalIds = clients.map(({ id }) => id);
  const state = { data: { catalog: { clients } }, clientPickerLane: 5 };
  const elements = { clientOptions: { innerHTML: "" } };
  let message;
  // Ejecuta las funciones de la página sin iniciar la balanza ni sus eventos del navegador.
  const pickerSource = source.slice(
    source.indexOf("function matchingDispatchClients("),
    source.indexOf("function openClientPicker("),
  );
  const { matchingDispatchClients, renderClientOptions } = new Function(
    "state", "elements", "normalizeRetailClientSearch", "laneDestination", "escapeHtml", "setClientMessage",
    `${pickerSource}\nreturn { matchingDispatchClients, renderClientOptions };`,
  )(state, elements, normalizeRetailClientSearch, () => clients.at(-1), String, (text) => { message = text; });

  assert.deepEqual(matchingDispatchClients().map(({ id }) => id), originalIds);
  assert.deepEqual(matchingDispatchClients("  ").map(({ id }) => id), originalIds);
  assert.deepEqual(
    matchingDispatchClients("GRANJA").map(({ id }) => id),
    [151, 152, ...originalIds.slice(0, 150)],
  );
  assert.deepEqual(
    matchingDispatchClients("900").map(({ id }) => id),
    originalIds.slice(0, 150),
  );
  assert.deepEqual(matchingDispatchClients("90000149").map(({ id }) => id), [150]);
  assert.deepEqual(clients.map(({ id }) => id), originalIds);

  renderClientOptions();
  assert.equal([...elements.clientOptions.innerHTML.matchAll(/data-live-client-option=/g)].length, 152);
  assert.match(elements.clientOptions.innerHTML, /data-live-client-option="152" aria-pressed="true"/);
  assert.equal(message, "");

  renderClientOptions("900");
  assert.equal([...elements.clientOptions.innerHTML.matchAll(/data-live-client-option=/g)].length, 150);
  assert.match(elements.clientOptions.innerHTML, /data-live-client-option="150"/);
  assert.equal(message, "");
});

test("las cuatro entradas se deslizan horizontalmente y los dos despachos permanecen aparte", () => {
  assert.match(view, /@for \(\$lane = 1; \$lane <= 4; \$lane\+\+\)/);
  assert.match(view, /@for \(\$lane = 5; \$lane <= 6; \$lane\+\+\)/);
  assert.match(view, /class="lir-lane-track"/);
  assert.match(stylesheet, /body \{ min-width: 0; overflow-x: hidden; \}/);
  assert.match(stylesheet, /\.lir-lane-track \{[^}]*overflow-x: auto;[^}]*scroll-snap-type: x mandatory;[^}]*touch-action: pan-x pan-y pinch-zoom;/);
  assert.match(stylesheet, /\.lir-lanes\.is-warehouse-lanes \{[^}]*width: calc\(200% \+ 8px\);[^}]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
  assert.match(stylesheet, /\.lir-lanes\.is-client-lanes \{[^}]*grid-template-columns: repeat\(2,/);
  assert.match(stylesheet, /\.lir-lane\.is-warehouse \{ height: 38vh; \}/);
  assert.match(stylesheet, /\.lir-lane\.is-client \{ height: 28vh; \}/);
});

test("los totales se apoyan en el final real de cada columna", () => {
  assert.match(stylesheet, /\.lir-lane \{[^}]*display: grid;[^}]*grid-template-rows: auto minmax\(0, 1fr\) auto;/);
  assert.match(stylesheet, /\.lir-lane-rows, \.lir-lane\.is-warehouse \.lir-lane-rows \{[^}]*height: auto;[^}]*min-width: 0;[^}]*min-height: 0;[^}]*overflow: auto;[^}]*overscroll-behavior-inline: contain;[^}]*touch-action: pan-x pan-y pinch-zoom;/);
  assert.match(stylesheet, /\.lir-lane > footer \{ position: static;/);
  assert.match(stylesheet, /\.lir-selected-total \{ position: static;/);
});

test("cada columna presenta registros grandes en una tabla desplazable", () => {
  const recordTableBlock = source.match(
    /function renderRecordTable\(lane, rows, emptyMessage\)[\s\S]*?(?=\nfunction renderRecords)/,
  )?.[0] || "";
  const headers = [...recordTableBlock.matchAll(/<th scope="col">([^<]+)<\/th>/g)]
    .map(([, label]) => label);
  const rowRenderers = [
    source.match(/function renderReceptionRecord\(record\)[\s\S]*?(?=\nfunction renderDispatchTicketRecord)/)?.[0] || "",
    source.match(/function renderDispatchTicketRecord\(record\)[\s\S]*?(?=\nfunction renderDraftWeighing)/)?.[0] || "",
    source.match(/function renderDraftWeighing\(weighing, lane, index\)[\s\S]*?(?=\nconst RECORD_TABLE_COLUMN_COUNT)/)?.[0] || "",
  ];

  assert.match(view, /role="region" tabindex="0" aria-label="Tabla desplazable de registros de la columna/);
  assert.match(view, /dentro de cada tabla, mueve a los lados para ver todos los datos/);
  assert.match(source, /<table class="lir-record-table">/);
  assert.deepEqual(headers, [
    "Peso bruto",
    "Sexo",
    "Destino",
    "Hora",
    "Propietario",
    "Tipo de java",
    "Javas",
    "Aves/java",
    "Pollos",
    "Tara",
    "Peso neto",
    "Acciones",
    "Registro",
  ]);
  rowRenderers.forEach((renderer) => {
    const cellClasses = [...renderer.matchAll(/<td(?: class="([^"]*)")?>/g)]
      .map(([, className]) => className || "");
    const cellPositions = [
      renderer.indexOf('class="lir-weight-cell is-gross"'),
      renderer.indexOf('class="lir-sex-chip'),
      renderer.indexOf('class="lir-record-destination"'),
      renderer.indexOf('class="lir-record-actions"'),
      renderer.indexOf('class="lir-record-identity"'),
    ];
    assert.equal(cellClasses.length, 13);
    assert.equal(cellClasses[0], "lir-weight-cell is-gross");
    assert.equal(cellClasses[11], "lir-record-actions");
    assert.equal(cellClasses[12], "lir-record-identity");
    assert.ok(cellPositions.every((position) => position >= 0));
    assert.deepEqual([...cellPositions].sort((left, right) => left - right), cellPositions);
  });
  assert.match(source, /const RECORD_TABLE_COLUMN_COUNT = 13/);
  assert.match(stylesheet, /\.lir-record-table \{[^}]*min-width: 1410px;[^}]*font-size: \.86rem;/);
  assert.match(stylesheet, /\.lir-record-table thead th \{[^}]*position: sticky;/);
  assert.match(stylesheet, /\.lir-record-table tbody td:first-child \{[^}]*position: sticky;/);
  assert.match(stylesheet, /\.lir-record-table th:nth-child\(1\), \.lir-record-table td:nth-child\(1\) \{[^}]*text-align: right;/);
  assert.match(stylesheet, /\.lir-record-table th:nth-child\(13\), \.lir-record-table td:nth-child\(13\) \{[^}]*text-align: center;/);
  assert.match(stylesheet, /\.lir-weight-cell\.is-gross \{[^}]*font-size: 1rem;/);
  assert.match(stylesheet, /\.lir-weight-cell\.is-net \{[^}]*font-size: 1rem;/);
  assert.doesNotMatch(stylesheet, /\.lir-record-destination \{[^}]*text-overflow: ellipsis;/);
  assert.doesNotMatch(source, /<tr[^>]*role="button"/);
  assert.match(source, /class="lir-row-action is-edit"[^>]*data-live-edit-weighing/);
  assert.match(source, /class="lir-row-action is-edit"[^>]*data-live-edit-draft-weighing/);
  assert.doesNotMatch(source, /event\.target\.closest\("\[data-live-open-ticket\], \[data-live-edit-weighing\], \[data-live-edit-draft-weighing\]"\)/);
});

test("las pesadas se ordenan por su fecha real con las más recientes arriba", () => {
  const rows = [
    { id: "anterior", weighed_at: "2026-08-26T23:55:00-05:00" },
    { id: "reciente", weighed_at: "2026-08-27T00:10:00-05:00" },
    { id: "sin-fecha", weighed_at: "fecha-invalida" },
  ];
  const originalOrder = [...rows];

  assert.deepEqual(
    newestReceptionRowsFirst(rows).map(({ id }) => id),
    ["reciente", "anterior", "sin-fecha"],
  );
  assert.deepEqual(rows, originalOrder);
  assert.deepEqual(
    newestReceptionRowsFirst([
      { id: "primera", weighed_at: "2026-08-27T00:10:00-05:00", sort_tie: 1 },
      { id: "agregada-después", weighed_at: "2026-08-27T00:10:00-05:00", sort_tie: 2 },
    ]).map(({ id }) => id),
    ["agregada-después", "primera"],
  );
  assert.match(source, /newestReceptionRowsFirst\(rows\)/);
  assert.match(source, /<strong>Pesada \$\{normalized\.number\}<\/strong>/);
});

test("el resumen expande cada pesada de los tickets y filtra los tres alcances", () => {
  const records = [
    {
      record_kind: "reception_weighing",
      id: 1,
      number: 1,
      owner: { type: "PROPIA", name: "Mi empresa" },
      destination: { type: "ALMACEN", name: "Almacén 1" },
      sex: "MACHO",
      cages: 1,
      birds_per_cage: 7,
      birds: 7,
      gross_weight_kg: 20,
      tare_weight_kg: 2,
      net_weight_kg: 18,
      weighed_at: "2026-08-27T10:00:00-05:00",
    },
    {
      record_kind: "legacy_direct_weighing",
      id: 2,
      number: 2,
      owner: { type: "EXTERNA", name: "Empresa externa" },
      destination: { type: "ALMACEN", name: "Almacén 2" },
      sex: "HEMBRA",
      cages: 2,
      birds_per_cage: 6,
      birds: 12,
      gross_weight_kg: 30,
      tare_weight_kg: 4,
      net_weight_kg: 26,
      weighed_at: "2026-08-27T11:00:00-05:00",
    },
    {
      record_kind: "dispatch_ticket",
      row_key: "ticket:30:MACHO",
      ticket_id: 30,
      ticket_code: "PV-030",
      source_lane: 5,
      owner: { type: "PROPIA", name: "Mi empresa" },
      destination: { type: "CLIENTE", name: "Cliente A" },
      weighings: [
        { id: 31, number: 3, sex: "MACHO", cages: 1, birds_per_cage: 8, birds: 8, gross_weight_kg: 25, tare_weight_kg: 2, net_weight_kg: 23, weighed_at: "2026-08-27T12:00:00-05:00" },
        { id: 32, number: 4, sex: "MACHO", cages: 1, birds_per_cage: 8, birds: 8, gross_weight_kg: 24, tare_weight_kg: 2, net_weight_kg: 22, weighed_at: "2026-08-27T09:00:00-05:00" },
      ],
    },
  ];
  const snapshot = JSON.parse(JSON.stringify(records));
  const daily = receptionSummaryRows(records, "daily");
  const own = receptionSummaryRows(records, "own");
  const external = receptionSummaryRows(records, "external");

  assert.deepEqual(daily.map(({ id }) => id), [31, 2, 1, 32]);
  assert.deepEqual(own.map(({ id }) => id), [31, 1, 32]);
  assert.deepEqual(external.map(({ id }) => id), [2]);
  assert.equal(daily.filter(({ record_kind }) => record_kind === "dispatch_ticket_weighing").length, 2);
  assert.equal(daily[0].ticket_code, "PV-030");
  assert.equal(daily[0].destination.name, "Cliente A");
  assert.equal(daily[0].owner.type, "PROPIA");
  assert.equal(daily[0].summary_focus_key, "ticket-weighing:31");
  assert.equal(daily.find(({ id }) => id === 2).editable_mode, "readonly");
  assert.equal(daily.find(({ id }) => id === 2).summary_focus_key, "reception-weighing:2");
  assert.deepEqual(records, snapshot);
});

test("los tres grupos del resumen abren el mismo popup accesible y adaptable", () => {
  const dailySummary = view.match(/<section class="lir-daily-summary"[\s\S]*?<\/section>/)?.[0] || "";
  const summaryModal = view.match(/<div id="liveIntakeSummaryModal"[\s\S]*?(?=\n\s*<div id="liveIntakeSettingsModal")/)?.[0] || "";
  const summaryTriggers = [...dailySummary.matchAll(/<button class="lir-summary-trigger"([^>]*)>/g)];
  const modalEnvironment = source.match(/function syncModalEnvironment\(\)[\s\S]*?(?=\nfunction openDialog)/)?.[0] || "";
  const summaryRenderer = source.match(/function renderSummaryDetail\([\s\S]*?(?=\nfunction openSummaryDetail)/)?.[0] || "";
  const tabletStyles = stylesheet.match(/@media \(max-width: 820px\) \{[\s\S]*?(?=\n@media \(max-width: 560px\))/)?.[0] || "";

  assert.equal(summaryTriggers.length, 6);
  assert.deepEqual(summaryTriggers.map(([, attributes]) => attributes.match(/data-live-summary-scope="([^"]+)"/)?.[1]), [
    "daily", "daily", "daily", "daily", "own", "external",
  ]);
  summaryTriggers.forEach(([, attributes]) => {
    assert.match(attributes, /type="button"/);
    assert.match(attributes, /aria-haspopup="dialog"/);
    assert.match(attributes, /aria-controls="liveIntakeSummaryModal"/);
    assert.match(attributes, /aria-expanded="false"/);
    assert.doesNotMatch(attributes, /aria-label=/);
  });
  assert.match(summaryModal, /role="dialog" aria-modal="true" aria-labelledby="liveIntakeSummaryTitle"/);
  assert.match(summaryModal, /id="liveIntakeSummaryRows"[^>]*role="region" tabindex="0"/);
  assert.match(summaryModal, /class="lir-summary-intro"[\s\S]*?id="liveIntakeSummaryHelp"[\s\S]*?id="liveIntakeSummaryMessage"/);
  ["weighings", "cages", "birds", "gross_weight_kg", "tare_weight_kg", "net_weight_kg"].forEach((key) => {
    assert.match(summaryModal, new RegExp(`data-live-summary-total="${key}"`));
  });
  assert.match(source, /elements\.summaryTriggers\.forEach\(\(button\) => button\.addEventListener\("click", \(\) => \{[\s\S]*?openSummaryDetail\(button\.dataset\.liveSummaryScope, button\)/);
  assert.match(source, /querySelectorAll\("\[data-live-close-summary\]"\)[\s\S]*?addEventListener\("click", closeSummaryDetail\)/);
  assert.match(modalEnvironment, /elements\.summaryModal/);
  assert.match(source, /receptionSummaryRows\(state\.data\?\.records \|\| \[\], normalizedScope\)/);
  assert.match(summaryRenderer, /state\.data\?\.totals\?\.\[normalizedScope\]/);
  assert.match(summaryRenderer, /summaryTotals\.weighings\.textContent = String\(totals\.weighings \|\| 0\)/);
  assert.match(summaryRenderer, /summaryTotals\.gross_weight_kg\.textContent = formatKg\(totals\.gross_weight_kg\)/);
  assert.match(summaryRenderer, /summaryTotals\.net_weight_kg\.textContent = formatKg\(totals\.net_weight_kg\)/);
  assert.match(source, /function renderSummaryTable\(rows, title\)[\s\S]*?<tbody>\$\{rows\.map\(renderSummaryRow\)\.join\(""\)\}<\/tbody>/);
  assert.match(source, /function renderSummaryRow\(row\)[\s\S]*?row\?\.gross_weight_kg[\s\S]*?row\?\.tare_weight_kg[\s\S]*?row\?\.net_weight_kg/);
  assert.match(source, /const multipleExternalOwners = recordedExternalOwners\.length > 1/);
  assert.match(source, /multipleExternalOwners \? "Pesadas de empresas externas"/);
  assert.match(source, /openDialog\(elements\.summaryModal, trigger, elements\.summaryClose\)/);
  assert.match(source, /closeDialog\(elements\.summaryModal, trigger\)/);
  assert.match(source, /openModal === elements\.summaryModal\) closeSummaryDetail\(\)/);
  assert.match(source, /\[elements\.summaryModal,[\s\S]*?if \(modal === elements\.summaryModal\) closeSummaryDetail\(\)/);
  assert.match(source, /if \(state\.summaryScope && !elements\.summaryModal\.hidden\) \{[\s\S]*?renderSummaryDetail\(state\.summaryScope\)/);
  assert.match(stylesheet, /\.lir-modal-card\.is-summary-detail \{[^}]*height: min\(860px, calc\(100dvh - 32px\)\);[^}]*grid-template-rows: auto minmax\(0, 1fr\) auto;[^}]*overflow: hidden;/);
  assert.match(stylesheet, /\.lir-summary-detail-body \{[^}]*grid-template-rows: auto auto minmax\(0, 1fr\);/);
  assert.match(stylesheet, /\.lir-summary-table-scroll \{[^}]*min-height: 0;[^}]*overflow: auto;[^}]*overscroll-behavior: contain;[^}]*touch-action: pan-x pan-y pinch-zoom;/);
  assert.match(tabletStyles, /\.lir-summary-totals \{ grid-template-columns: repeat\(3,/);
});

test("cada fila del resumen abre su detalle desde cualquier celda con un botón real en peso bruto", () => {
  const rowRenderer = source.match(/function renderSummaryRow\(row\)[\s\S]*?(?=\nfunction renderSummaryTable)/)?.[0] || "";
  const tableRenderer = source.match(/function renderSummaryTable\(rows, title\)[\s\S]*?(?=\nfunction setSummaryMessage)/)?.[0] || "";
  const header = tableRenderer.match(/<thead><tr>([\s\S]*?)<\/tr><\/thead>/)?.[1] || "";
  const labels = [...header.matchAll(/<th scope="col">([^<]+)<\/th>/g)].map(([, label]) => label);

  assert.deepEqual(labels, [
    "Peso bruto", "Fecha y hora", "Sexo", "Propietario", "Destino", "Tipo de java",
    "Javas", "Aves/java", "Pollos", "Tara", "Peso neto", "Origen", "Registro",
  ]);
  assert.match(rowRenderer, /<tr class="[\s\S]*?data-live-summary-row/);
  assert.doesNotMatch(rowRenderer, /<tr[^>]*role="button"/);
  assert.match(rowRenderer, /<td class="lir-summary-weight lir-summary-gross"><button class="lir-summary-row-open" type="button" data-live-open-summary-row aria-haspopup="dialog"/);
  assert.match(rowRenderer, /aria-controls="\$\{controlledModal\}" aria-expanded="false" aria-label=/);
  assert.match(source, /const summaryRow = event\.target\.closest\("\[data-live-summary-row\]"\);[\s\S]*?openSummaryRow\(summaryRow\);[\s\S]*?return;/);
  assert.equal((source.match(/openSummaryRow\(summaryRow\)/g) || []).length, 1);
  assert.match(stylesheet, /\.lir-summary-table tbody td:first-child, \.lir-summary-table thead th:first-child \{[^}]*position: sticky;[^}]*left: 0;/);
  assert.match(stylesheet, /\.lir-summary-row-open \{[^}]*width: 100%;[^}]*min-height: 50px;/);
});

test("el resumen enruta pesadas nativas, tickets e históricos sin ofrecer acciones indebidas", () => {
  const rowRenderer = source.match(/function renderSummaryRow\(row\)[\s\S]*?(?=\nfunction renderSummaryTable)/)?.[0] || "";
  const opener = source.match(/function openSummaryRow\(row\)[\s\S]*?(?=\nfunction renderTotals)/)?.[0] || "";
  const weighingEditor = source.match(/function openWeighingEditor\(record, context = \{\}\)[\s\S]*?(?=\nfunction closeWeighingEditor)/)?.[0] || "";
  const saveWeighing = source.match(/async function saveWeighingEditor\(event\)[\s\S]*?(?=\nasync function deleteDraftWeighing)/)?.[0] || "";

  assert.ok(opener.indexOf("if (ticketWeighing)") < opener.indexOf("state.data?.records?.find"));
  assert.match(opener, /openTicketEditor\(ticketId, trigger, \{ focusWeighingId: weighingId \}\)/);
  assert.match(opener, /!isDispatchTicketRecord\(item\) && Number\(item\.id\) === weighingId/);
  assert.match(opener, /kind: readonly \? "readonly" : "weighing"/);
  assert.match(weighingEditor, /readonly \? `Detalle de pesada/);
  assert.match(weighingEditor, /deleteWeighingButton\.hidden = readonly/);
  assert.match(weighingEditor, /saveWeighingButton\.hidden = readonly/);
  assert.match(saveWeighing, /state\.editingRecord\.kind === "readonly"/);
  assert.match(rowRenderer, /readonly \? "Consultar" : "Editar pesada"/);
});

test("el editor individual limita propietario y muestra la asignación automática", () => {
  const ownerOptions = source.match(/function populateWeighingOwnerOptions\([\s\S]*?(?=\nfunction updateWeighingEditorAssignment)/)?.[0] || "";
  const assignment = source.match(/function updateWeighingEditorAssignment\([\s\S]*?(?=\nfunction reconcileDirectClientSelections)/)?.[0] || "";
  const saveWeighing = source.match(/async function saveWeighingEditor\(event\)[\s\S]*?(?=\nasync function deleteDraftWeighing)/)?.[0] || "";
  const saveTicket = source.match(/async function saveTicketEditor\(event\)[\s\S]*?(?=\nfunction printRegisteredTicket)/)?.[0] || "";

  assert.match(view, /id="liveIntakeEditOwner"/);
  assert.match(view, /id="liveIntakeEditAssignment"/);
  assert.match(ownerOptions, /<option value="PROPIA">Mi empresa<\/option>/);
  assert.match(ownerOptions, /<option value="EXTERNA"/);
  assert.match(ownerOptions, /configuredExternalOwner\(\)/);
  assert.match(ownerOptions, /historicalExternalName \|\| externalOwner\?\.name/);
  assert.match(ownerOptions, /externalOwner \|\| keepsHistoricalExternal/);
  assert.match(ownerOptions, /elements\.editOwner\.disabled = readonly/);
  assert.doesNotMatch(ownerOptions, /elements\.editOwner\.disabled = readonly \|\|/);
  assert.match(source, /function warehouseLaneFor\(ownerType, sex\)[\s\S]*?female \? 4 : 3[\s\S]*?female \? 2 : 1/);
  assert.match(assignment, /`Columna \$\{lane\} · \$\{destination\?\.name \|\| "Sin almacén configurado"\}`/);
  assert.match(assignment, /keepsHistoricalExternal = ownerType === "EXTERNA" && recordOwnerType\(record\) === "EXTERNA"/);
  assert.match(assignment, /sameLane = lane === Number\(record\.source_lane \?\? record\.lane\)/);
  assert.match(assignment, /sameLane \? \(historicalDestination \|\| laneDestination\(lane\)\) : laneDestination\(lane\)/);
  assert.match(source, /editOwnerField\.hidden = kind === "draft"/);
  assert.match(source, /editAssignmentField\.hidden = kind === "draft"/);
  assert.match(assignment, /saveWeighingButton\.disabled = !ownerReady/);
  assert.match(saveWeighing, /owner_type: values\.owner_type/);
  assert.doesNotMatch(saveTicket, /owner_type/);
  assert.match(view, /id="liveIntakeTicketEditorOwner">Propietario: Mi empresa · fijo/);
});

test("cerrar, guardar o borrar vuelve al mismo resumen, fila y desplazamiento", () => {
  const suspension = source.match(/function suspendSummaryDetailForEditor\(row\)[\s\S]*?(?=\nfunction restoreSummaryDetailAfterEditor)/)?.[0] || "";
  const restoration = source.match(/function restoreSummaryDetailAfterEditor\([\s\S]*?(?=\nfunction openSummaryRow)/)?.[0] || "";
  const closeWeighing = source.match(/function closeWeighingEditor\(\)[\s\S]*?(?=\nfunction finishWeighingEditor)/)?.[0] || "";
  const finishWeighing = source.match(/function finishWeighingEditor\([\s\S]*?(?=\nfunction editedWeighingValues)/)?.[0] || "";
  const closeTicket = source.match(/function closeTicketEditor\(\)[\s\S]*?(?=\nfunction finishTicketEditor)/)?.[0] || "";
  const finishTicket = source.match(/function finishTicketEditor\([\s\S]*?(?=\nfunction ticketEditorWeighings)/)?.[0] || "";

  assert.match(view, /id="liveIntakeSummaryMessage"[^>]*role="status" aria-live="polite"/);
  assert.match(suspension, /scope: state\.summaryScope \|\| "daily"/);
  assert.match(suspension, /focusKey: row\.dataset\.liveSummaryFocusKey/);
  assert.match(suspension, /scrollLeft: elements\.summaryRows\.scrollLeft/);
  assert.match(suspension, /scrollTop: elements\.summaryRows\.scrollTop/);
  assert.match(suspension, /hideDialogWithoutFocus\(elements\.summaryModal/);
  assert.match(restoration, /renderSummaryDetail\(returnState\.scope\)/);
  assert.match(restoration, /dataset\.liveSummaryFocusKey === returnState\.focusKey/);
  assert.match(restoration, /elements\.summaryRows\.scrollLeft = returnState\.scrollLeft/);
  assert.match(restoration, /elements\.summaryRows\.scrollTop = returnState\.scrollTop/);
  assert.match(closeWeighing, /restoreSummaryDetailAfterEditor\(\)/);
  assert.match(finishWeighing, /restoreSummaryDetailAfterEditor\(message, tone\)/);
  assert.match(closeTicket, /restoreSummaryDetailAfterEditor\(\)/);
  assert.match(finishTicket, /restoreSummaryDetailAfterEditor\(message, tone\)/);
  assert.match(source, /finishWeighingEditor\(response\.message \|\| "Pesada actualizada correctamente\."/);
  assert.match(source, /finishWeighingEditor\(result\.message, "success"\)/);
  assert.match(source, /if \(state\.summaryEditorReturn\) \{[\s\S]*?finishTicketEditor\(successMessage, "success"\)/);
});

test("el ticket enfoca la pesada elegida y bloquea todos los campos si es de consulta", () => {
  const ticketRenderer = source.match(/function renderTicketEditor\(\)[\s\S]*?(?=\nasync function openTicketEditor)/)?.[0] || "";
  assert.match(ticketRenderer, /is-summary-target/);
  assert.match(ticketRenderer, /tabindex="-1"/);
  assert.match(ticketRenderer, /focusTarget\.focus\(\{ preventScroll: true \}\)/);
  assert.match(ticketRenderer, /scrollIntoView\(\{ block: "center", inline: "nearest" \}\)/);
  assert.match(ticketRenderer, /querySelectorAll\("input, select"\)\.forEach\(\(control\) => \{ control\.disabled = readonly; \}\)/);
  assert.match(ticketRenderer, /ticketEditReason\.closest\("label"\)\.hidden = readonly/);
  assert.match(ticketRenderer, /saveTicket\.hidden = readonly/);
  assert.match(stylesheet, /\.lir-ticket-weighing-editor\.is-summary-target \{[^}]*scroll-margin-block: 80px;/);
});

test("la numeración del borrador se conserva al editar y no se repite después de quitar", () => {
  const draftSaveBlock = source.match(
    /if \(state\.editingRecord\.kind === "draft"\)[\s\S]*?(?=\n  const reason = elements\.editReason)/,
  )?.[0] || "";

  assert.equal(nextDraftWeighingNumber([]), 1);
  assert.equal(nextDraftWeighingNumber([{ number: 1 }, { number: 3 }]), 4);
  assert.equal(nextDraftWeighingNumber([{}, {}]), 3);
  assert.equal(source.match(/number: nextDraftWeighingNumber\(draft\.weighings\)/g)?.length, 2);
  assert.match(
    draftSaveBlock,
    /\.\.\.values,[\s\S]*?number: draft\.weighings\[index\]\.number \?\? draft\.weighings\[index\]\.numero \?\? index \+ 1/,
  );
});

test("la configuración de balanza vive en su propio popup", () => {
  const scalePanel = view.match(/<section class="lir-scale-panel"[\s\S]*?<\/section>/)?.[0] || "";
  const generalSettings = view.match(/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";
  const scaleSettings = view.match(/<form id="liveIntakeScaleSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";

  assert.match(scalePanel, /id="liveIntakeOpenScaleSettings"[\s\S]*?aria-controls="liveIntakeScaleSettingsModal"[\s\S]*?aria-expanded="false"/);
  assert.doesNotMatch(scalePanel, /liveIntakeConnect(?:Ble|Serial)|liveIntakeDisconnectScale/);
  assert.doesNotMatch(generalSettings, /liveIntake(?:ConnectBle|ConnectSerial|DisconnectScale|BaudRate|DataBits|StopBits|Parity|FlowControl)/);
  assert.match(scaleSettings, /role="dialog" aria-modal="true"/);
  assert.match(scaleSettings, /liveIntakeConnectBle/);
  assert.match(scaleSettings, /liveIntakeConnectSerial/);
  assert.match(scaleSettings, /liveIntakeDisconnectScale/);
  assert.match(scaleSettings, /liveIntakeBaudRate/);
  assert.match(source, /function openScaleSettings\(\)/);
  assert.match(source, /elements\.scaleSettingsForm\.addEventListener\("submit", saveScaleSettings\)/);
});

test("el peso manual se abre desde la lectura y no desde la configuración general", () => {
  const scalePanel = view.match(/<section class="lir-scale-panel"[\s\S]*?<\/section>/)?.[0] || "";
  const generalSettings = view.match(/<form id="liveIntakeSettingsForm"[\s\S]*?<\/form>/)?.[0] || "";
  const manualForm = view.match(/<form id="liveIntakeManualWeightForm"[\s\S]*?<\/form>/)?.[0] || "";

  assert.match(scalePanel, /liveIntakeScaleWeight[\s\S]*?liveIntakeOpenManualWeight/);
  assert.match(scalePanel, /liveIntakeOpenManualWeight[\s\S]*?aria-controls="liveIntakeManualWeightModal"/);
  assert.doesNotMatch(generalSettings, /liveIntakeManualWeight|liveIntakeApplyManualWeight/);
  assert.match(manualForm, /role="dialog" aria-modal="true"/);
  assert.match(manualForm, /id="liveIntakeManualWeight"/);
  assert.match(manualForm, /id="liveIntakeApplyManualWeight"[\s\S]*?type="submit"/);
  assert.match(source, /elements\.manualWeightForm\.addEventListener\("submit", applyManualWeight\)/);
  assert.match(source, /state\.scale\.setManualReading\(elements\.manualWeight\.value\)/);
  assert.match(source, /setMessage\(`Peso manual de \$\{appliedWeight\} kg listo para registrar\.`/);
});

test("el zoom de recepción es independiente y respeta sus niveles", () => {
  assert.match(view, /content="width=device-width, initial-scale=1, minimum-scale=1, viewport-fit=cover"/);
  assert.match(source, /const ZOOM_LEVELS = \[100, 110, 125, 150\]/);
  assert.match(source, /sistema-pollos-recepcion-pollo-vivo-zoom-v1/);
  assert.match(source, /document\.documentElement\.style\.removeProperty\("zoom"\)/);
  assert.match(source, /elements\.main\.style\.removeProperty\("zoom"\)/);
  assert.match(source, /elements\.zoomSurface\.style\.removeProperty\("width"\)/);
  assert.match(source, /elements\.zoomSurface\.style\.zoom = String\(scale\)/);
  assert.doesNotMatch(source, /elements\.main\.style\.zoom\s*=/);
  assert.doesNotMatch(source, /elements\.zoomSurface\.style\.width\s*=/);
  assert.doesNotMatch(source, /document\.documentElement\.style\.zoom\s*=/);
  assert.match(source, /event\.key === ZOOM_STORAGE_KEY/);
});

test("el editor de pesada conserva tamaño completo y cabe en modo vertical", () => {
  const mainMarkup = view.match(/<main[\s\S]*?<\/main>/)?.[0] || "";
  assert.match(mainMarkup, /id="liveIntakeZoomSurface" class="lir-zoom-surface"/);
  assert.ok(view.indexOf("</main>") < view.indexOf('id="liveIntakeWeighingEditorModal"'));
  assert.doesNotMatch(mainMarkup, /class="lir-modal"/);
  assert.equal((view.match(/class="lir-modal"/g) || []).length, 9);
  assert.match(stylesheet, /\.lir-shell \{[^}]*max-width: 100vw;[^}]*overflow-x: hidden;[^}]*overflow-x: clip;/);
  assert.match(stylesheet, /\.lir-zoom-surface \{[^}]*width: 100%;[^}]*min-width: 0;[^}]*padding: 12px;/);
  assert.match(stylesheet, /\.lir-modal-card \{[^}]*max-height: calc\(100dvh - 32px\);[^}]*overflow: auto;/);
  assert.match(stylesheet, /\.lir-modal > \.lir-modal-card \{[^}]*max-height: min\(calc\(100dvh - 32px\), 100%\);/);
  assert.match(stylesheet, /\.lir-editor-grid label \{[^}]*min-width: 0;[^}]*max-width: 100%;/);
  assert.match(stylesheet, /\.lir-modal-card\.is-weighing-editor input,[\s\S]*?\.lir-modal-card\.is-weighing-editor select \{[^}]*min-width: 0;[^}]*max-width: 100%;/);
  assert.match(stylesheet, /@media \(max-width: 360px\) \{[\s\S]*?\.lir-modal-card\.is-weighing-editor > footer \{[^}]*flex-wrap: wrap;/);
});

test("el editor permite eliminar la pesada abierta y conserva los errores dentro del popup", () => {
  const editor = view.match(/<form id="liveIntakeWeighingEditorForm"[\s\S]*?<\/form>/)?.[0] || "";
  const deleteEditor = source.match(/async function deleteEditingWeighing\(\)[\s\S]*?(?=\nfunction setTicketEditorMessage)/)?.[0] || "";
  const finishEditor = source.match(/function finishWeighingEditor\([\s\S]*?(?=\nfunction editedWeighingValues)/)?.[0] || "";
  assert.match(editor, /id="liveIntakeDeleteWeighing"[^>]*class="is-danger lir-editor-delete"[^>]*type="button"/);
  assert.match(source, /deleteWeighingButton: document\.getElementById\("liveIntakeDeleteWeighing"\)/);
  assert.match(deleteEditor, /editingContext\.kind === "draft"/);
  assert.match(deleteEditor, /deleteDraftWeighing\([\s\S]*?editingContext\.originalFingerprint/);
  assert.match(deleteEditor, /deleteWeighing\([\s\S]*?editingContext\.record\.updated_at/);
  assert.match(source, /expectedFingerprint && visibleFingerprint !== expectedFingerprint/);
  assert.match(source, /expected_updated_at: expectedUpdatedAt/);
  assert.match(deleteEditor, /if \(!result\) return;[\s\S]*?finishWeighingEditor\(result\.message, "success"\)/);
  assert.match(finishEditor, /elements\.weighingEditorModal\.hidden = true/);
  assert.match(finishEditor, /elements\.laneRows\[Number\(editingContext\?\.lane\) - 1\]\?\.focus\(\{ preventScroll: true \}\)/);
  assert.match(source, /elements\.deleteWeighingButton\.addEventListener\("click"/);
  assert.match(stylesheet, /\.lir-modal-card button\.is-danger \{[^}]*color: var\(--lir-red\);/);
});

test("la captura usa una sola balanza, guarda entradas y agrega despachos al borrador", () => {
  assert.match(source, /new RetailScaleController\(/);
  assert.equal((source.match(/new RetailScaleController\(/g) || []).length, 1);
  assert.match(source, /apiRequest\("\/recepcion-pollo-vivo\/pesadas", \{[\s\S]*?method: "POST"/);
  assert.match(source, /BALANZA_RECEPCION_POLLO_VIVO/);
  assert.match(source, /state\.pendingCapture/);
  assert.match(source, /function addCaptureToDispatchDraft\(payload\)/);
  assert.match(source, /\[5, 6\]\.includes\(Number\(payload\.lane\)\)/);
  assert.match(source, /apiRequest\("\/recepcion-pollo-vivo\/tickets", \{[\s\S]*?method: "POST"/);
});

test("un reintento conserva y bloquea la columna y los datos de la pesada pendiente", () => {
  assert.match(source, /if \(state\.pendingCapture && requestedLane !== Number\(state\.pendingCapture\.lane\)\)/);
  assert.match(source, /const controlsLocked = state\.busy \|\| Boolean\(pendingCapture\)/);
  assert.match(source, /elements\.laneButtons\.forEach\(\(button\) => \{ button\.disabled = controlsLocked; \}\)/);
  assert.match(source, /elements\.birdsPerCage\.disabled = controlsLocked/);
  assert.match(source, /elements\.cageCount\.disabled = controlsLocked/);
  assert.match(source, /elements\.cageType\.disabled = controlsLocked/);
  assert.match(source, /state\.busy && pendingCapture[\s\S]*?"Guardando en columna"[\s\S]*?"Reintentar en columna"/);
  assert.match(source, /La pesada quedó pendiente: reintenta sin cambiar sus datos/);
  assert.match(source, /PENDING_CAPTURE_STORAGE_PREFIX/);
  assert.match(source, /persistPendingCapture\(payload\)/);
  assert.match(view, /data-live-user-id="\{\{ auth\(\)->id\(\) \}\}"/);
  assert.match(source, /return `\$\{prefix\}-company-\$\{companyId\}-branch-\$\{branchId\}-user-\$\{userId\}`/);
  assert.match(source, /restorePendingCapture\(response\.data\.company\.id, response\.data\.branch\.id\)/);
  assert.match(source, /elements\.birdsPerCage\.value = String\(birdsPerCage\)/);
  assert.match(source, /elements\.cageCount\.value = String\(cageCount\)/);
  assert.match(source, /elements\.cageType\.value = String\(cageTypeId\)/);
  assert.match(source, /const deterministicClientError = status >= 400 && status < 500 && !\[408, 409\]\.includes\(status\)/);
  assert.match(source, /if \(deterministicClientError\)[\s\S]*?clearPendingCapture\(\)/);
  assert.match(source, /state\.pendingCapture\?\.read_weight_kg \?\? scaleState\.currentWeightKg/);
  assert.match(source, /Peso congelado para reintento/);
  assert.match(source, /navigator\.locks\?\.request/);
  assert.match(source, /\{ mode: "exclusive", ifAvailable: true \}/);
  assert.match(source, /event\.key === state\.pendingCaptureStorageKey/);
  assert.match(source, /Otra pestaña dejó una pesada pendiente/);
  assert.match(source, /window\.addEventListener\("beforeunload"/);
});

test("una captura pendiente de la versión anterior conserva su identidad al actualizar", () => {
  const renderDataBlock = source.match(/function renderData\(data,[\s\S]*?(?=\nfunction selectLane)/)?.[0] || "";
  assert.match(source, /LEGACY_PENDING_CAPTURE_V2_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v2"/);
  assert.match(source, /LEGACY_PENDING_CAPTURE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v3"/);
  assert.match(source, /function migrateLegacyPendingCapture\(payload\)/);
  assert.match(source, /!\[2, 3, 4\]\.includes\(Number\(payload\?\.layout_version\)\)/);
  assert.match(source, /const migrated = \{ \.\.\.payload, layout_version: LAYOUT_VERSION \}/);
  assert.match(source, /migrated\.dispatch_client_id = Number\(defaultClient\.id\)/);
  assert.match(source, /restoringLegacy && persistPendingCapture\(payload\)/);
  assert.match(source, /localStorage\.removeItem\(sourceKey\)/);
  assert.match(source, /state\.pendingUpgradeBlocked/);
  assert.match(source, /\|\| state\.pendingUpgradeBlocked/);
  assert.match(source, /currentPendingEvent[\s\S]*legacyPendingEvent/);
  assert.match(source, /`\$\{state\.legacyPendingCaptureStorageKey\}-request-lock`/);
  assert.match(source, /`\$\{state\.pendingCaptureStorageKey\}-request-lock`/);
  assert.match(source, /recoverLegacyPending[\s\S]*restorePendingCapture\(response\.data\.company\.id, response\.data\.branch\.id\)/);
  assert.match(source, /!event\.newValue && \(legacyPendingEvent \|\| legacyV2PendingEvent\) && state\.pendingUpgradeBlocked/);
  assert.match(source, /Registra o activa un cliente y vuelve a esta vista/);
  assert.match(source, /async function refreshLegacyPendingFromSettings\(\)/);
  assert.match(source, /if \(state\.pendingUpgradeBlocked\) void refreshLegacyPendingFromSettings\(\)/);
  assert.match(source, /const lockResult = await withDispatchDraftLock\(lane,[\s\S]*?migratePendingDispatchToDraft\(payload, sourceKey\)/);
  assert.doesNotMatch(renderDataBlock, /persistDispatchDrafts\(\)/);
});

test("los tickets registrados se proyectan por sexo y abren el editor completo", () => {
  assert.equal(receptionRecordLane({ record_kind: "dispatch_ticket", sex: "MACHO", lane: 5 }), 1);
  assert.equal(receptionRecordLane({ record_kind: "dispatch_ticket", sex: "HEMBRA", lane: 6 }), 2);
  assert.match(source, /data-live-open-ticket="\$\{ticketId\}"/);
  assert.match(source, /apiRequest\(`\/recepcion-pollo-vivo\/tickets\/\$\{id\}`\)/);
  assert.match(source, /buildTicketUpdatePayload\(state\.editingTicket, weighings, reason\)/);
  assert.match(view, /id="liveIntakeTicketEditorModal"/);
  assert.match(view, /id="liveIntakeTicketEditReason"[^>]*minlength="3"/);
});

test("el borrador local genera el payload completo y calcula tara y neto conservados", () => {
  const draft = normalizeDispatchDraft({
    draft_id: "9d431e59-f96d-4d66-acce-08f57800579f",
    dispatch_client_id: 22,
    weighings: [{
      idempotency_key: "d6aacdc6-6009-42bd-b638-03ce482b4ea5",
      sex: "HEMBRA",
      cage_type_id: 3,
      birds_per_cage: 7,
      cage_count: 4,
      read_weight_kg: 58,
      gross_weight_kg: 58,
      tare_weight_kg: 8,
      net_weight_kg: 50,
      weight_source: "BALANZA_RECEPCION_POLLO_VIVO",
      weighed_at: "2026-08-26T10:00:00.000Z",
    }],
  }, 5);
  const totals = calculateDraftTotals(draft.weighings);
  assert.equal(totals.cages, 4);
  assert.equal(totals.birds, 28);
  assert.equal(totals.net_weight_kg, 50);
  const payload = buildReceptionTicketPayload(draft, { vehicleId: 8, driverId: 9 });
  assert.equal(payload.layout_version, 4);
  assert.equal(payload.lane, 5);
  assert.equal(payload.delivery_vehicle_id, 8);
  assert.equal(payload.weighings[0].idempotency_key, "d6aacdc6-6009-42bd-b638-03ce482b4ea5");
});

test("la hidratación regenera identificadores dañados como UUID antes del POST", () => {
  const generated = [
    "11111111-1111-4111-8111-111111111111",
    "22222222-2222-4222-8222-222222222222",
  ];
  const draft = normalizeDispatchDraft({
    draft_id: "draft-1",
    dispatch_client_id: 22,
    weighings: [{
      local_id: "draft-1",
      idempotency_key: "invalid-idempotency",
      sex: "MACHO",
      cage_type_id: 1,
      birds_per_cage: 7,
      cage_count: 1,
      read_weight_kg: 20,
    }],
  }, 5, () => generated.shift());
  assert.equal(draft.draft_id, "11111111-1111-4111-8111-111111111111");
  assert.equal(draft.weighings[0].idempotency_key, "22222222-2222-4222-8222-222222222222");
});

test("una pesada agregada durante un POST se conserva en un borrador nuevo", () => {
  const draftId = "11111111-1111-4111-8111-111111111111";
  const firstId = "22222222-2222-4222-8222-222222222222";
  const secondId = "33333333-3333-4333-8333-333333333333";
  const nextDraftId = "44444444-4444-4444-8444-444444444444";
  const base = {
    draft_id: draftId,
    lane: 5,
    dispatch_client_id: 22,
    weighings: [{
      idempotency_key: firstId,
      sex: "MACHO",
      cage_type_id: 1,
      birds_per_cage: 7,
      cage_count: 1,
      read_weight_kg: 20,
    }],
  };
  const submitted = normalizeDispatchDraft(base, 5);
  const current = normalizeDispatchDraft({
    ...base,
    registration_attempt: { fingerprint: dispatchDraftFingerprint(submitted) },
    weighings: [...base.weighings, {
      idempotency_key: secondId,
      sex: "HEMBRA",
      cage_type_id: 1,
      birds_per_cage: 7,
      cage_count: 1,
      read_weight_kg: 19,
    }],
  }, 5);
  const result = remainingDispatchDraftAfterRegistration(current, submitted, () => nextDraftId);
  assert.equal(result.handled, true);
  assert.equal(result.preservedWeighings, 1);
  assert.equal(result.draft.draft_id, nextDraftId);
  assert.equal(result.draft.registration_attempt, null);
  assert.equal(result.draft.weighings[0].idempotency_key, secondId);
});

test("una actualización mixta solo vuelve manual la pesada cuyo peso cambió", () => {
  const payload = buildTicketUpdatePayload({ id: 7, link_revision: 4, weighings: [] }, [
    {
      id: 70,
      sex: "MACHO",
      cage_type_id: 1,
      birds_per_cage: 7,
      cage_count: 2,
      read_weight_kg: 30,
      weighed_at: "2026-08-26T10:00:00.000Z",
      updated_at: "2026-08-26T10:01:00.000Z",
      weight_source: "BALANZA_RECEPCION_POLLO_VIVO",
      preserve_weight_source: true,
    },
    {
      id: 71,
      sex: "HEMBRA",
      cage_type_id: 1,
      birds_per_cage: 7,
      cage_count: 2,
      read_weight_kg: 31,
      weighed_at: "2026-08-26T10:02:00.000Z",
      weight_source: "MANUAL",
    },
  ], "Corrección controlada");
  assert.equal(payload.layout_version, 4);
  assert.equal(payload.expected_revision, 4);
  assert.equal(payload.weighings[0].weight_source, undefined);
  assert.equal(payload.weighings[0].expected_updated_at, "2026-08-26T10:01:00.000Z");
  assert.equal(payload.weighings[1].weight_source, "MANUAL");
  assert.match(source, /toISOString\(\)\.slice\(0, 19\)/);
  assert.match(source, /value === toDateTimeLocal\(original\.weighed_at\)[\s\S]*?\? original\.weighed_at/);
  assert.match(view, /liveIntakeEditWeighedAt" type="datetime-local" step="1"/);
  assert.match(source, /\? null\s*: original\.scale_reading/);
});

test("un intento ambiguo congela el borrador y reutiliza transporte y huella exactos", () => {
  assert.match(source, /draft\.registration_attempt = \{/);
  assert.match(source, /delivery_vehicle_id: Number\(delivery\?\.vehicleId\) \|\| null/);
  assert.match(source, /const deterministicClientError = status >= 400 && status < 500 && status !== 408/);
  assert.match(source, /el ticket quedó congelado\. Pulsa Reintentar ticket/);
  assert.match(source, /withDispatchDraftLock\(\s*laneNumber/);
  assert.match(source, /`\$\{state\.dispatchDraftStorageKey\}-mutation-lock`/);
  assert.doesNotMatch(source, /dispatchDraftStorageKey\}-lane-\$\{laneNumber\}/);
  assert.match(source, /draftFingerprint: attempt\.fingerprint/);
});

test("los editores conservan la tara histórica mientras no cambie el tipo de java", () => {
  assert.match(source, /function historicalCageWeight\(record = \{\}\)/);
  assert.match(source, /if \(Number\(normalizedRecord\.cage_type_id\) === id\)/);
  assert.match(source, /weight_kg: snapshotWeight \?\? catalogCageType\?\.weight_kg/);
  assert.match(source, /const cageType = editedCageType\(original, cageTypeId\)/);
  assert.match(source, /tare_weight_kg: tare/);
  assert.match(source, /readWeight <= tare/);
});

test("los borradores se aíslan por jornada y requieren descarte explícito", () => {
  assert.match(source, /operating_date: state\.data\?\.operating_date/);
  assert.match(source, /storedOperatingDate !== operatingDate && hasStoredWeighings/);
  assert.match(source, /state\.dispatchDraftsBlocked = true/);
  assert.match(source, /function discardExpiredDispatchDrafts\(\)/);
  assert.match(source, /data-live-discard-expired-drafts/);
  assert.match(source, /data-live-retry-expired-ticket/);
  assert.match(source, /function retryExpiredDispatchTicket\(lane, trigger\)/);
});

test("un cliente invalidado durante la pesada obliga a elegir otro sin perder la lectura", () => {
  const recovery = source.match(
    /async function refreshAfterInvalidDispatchClient[\s\S]*?(?=\nfunction addCaptureToDispatchDraft)/,
  )?.[0] || "";

  assert.match(source, /hasValidationError\(error, "dispatch_client_id"\)/);
  assert.match(source, /freezePendingCaptureForClientCorrection\(payload\)/);
  assert.match(source, /delete requestPayload\.requires_dispatch_client_reselection/);
  assert.match(source, /state\.pendingCapture\?\.read_weight_kg \?\? scaleState\.currentWeightKg/);
  assert.match(recovery, /async function refreshAfterInvalidDispatchClient\(lane, clientId, validationMessage\)/);
  assert.match(recovery, /state\.directClientReselectionRequired\[lane\] = true/);
  assert.match(recovery, /state\.directClientIds\[lane\] = null/);
  assert.match(recovery, /apiRequest\("\/recepcion-pollo-vivo"\)/);
  assert.match(recovery, /Elige otro cliente para volver a registrar esta misma lectura/);
  assert.doesNotMatch(recovery, /state\.scale\.clearReading\(\)/);
  assert.match(source, /la pesada congelada se recuperó dentro del borrador/);
});

test("corregir el cliente conserva exactamente la identidad y la lectura congelada", () => {
  const original = {
    layout_version: 3,
    idempotency_key: "9d431e59-f96d-4d66-acce-08f57800579f",
    lane: 5,
    sex: "HEMBRA",
    dispatch_client_id: 10,
    dispatch_client_name: "Cliente anterior",
    cage_type_id: 2,
    birds_per_cage: 7,
    cage_count: 3,
    weighed_at: "2026-08-26T18:00:00.000Z",
    weight_source: "BALANZA_RECEPCION_POLLO_VIVO",
    read_weight_kg: 51.375,
    scale_reading: { raw_frame: "ST,GS,+0051.375kg" },
  };

  const frozen = freezeDispatchClientCorrection(original);
  assert.equal(frozen.requires_dispatch_client_reselection, true);
  assert.equal(frozen.dispatch_client_id, undefined);
  assert.equal(frozen.dispatch_client_name, undefined);
  assert.equal(frozen.idempotency_key, original.idempotency_key);
  assert.equal(frozen.read_weight_kg, original.read_weight_kg);
  assert.deepEqual(frozen.scale_reading, original.scale_reading);

  const corrected = assignDispatchClientToPendingCapture(frozen, { id: 22, name: "Cliente nuevo" });
  assert.equal(corrected.dispatch_client_id, 22);
  assert.equal(corrected.dispatch_client_name, "Cliente nuevo");
  assert.equal(corrected.requires_dispatch_client_reselection, undefined);
  assert.equal(corrected.idempotency_key, original.idempotency_key);
  assert.equal(corrected.weighed_at, original.weighed_at);
  assert.equal(corrected.read_weight_kg, original.read_weight_kg);
  assert.deepEqual(corrected.scale_reading, original.scale_reading);
  assert.equal(original.dispatch_client_id, 10);
});
