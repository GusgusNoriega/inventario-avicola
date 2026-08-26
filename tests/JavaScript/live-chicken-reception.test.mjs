import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
  assignDispatchClientToPendingCapture,
  freezeDispatchClientCorrection,
} from "../../public/js/live-chicken-reception-pending.js";

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
  assert.match(source, /const LAYOUT_VERSION = 3/);
  assert.match(source, /totals\?\.external/);
  assert.match(view, /id="liveIntakeExternalBirds"/);
});

test("cada despacho permite elegir y congelar un cliente distinto", () => {
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
  assert.match(stylesheet, /\.lir-lane-rows, \.lir-lane\.is-warehouse \.lir-lane-rows \{[^}]*height: auto;[^}]*min-height: 0;[^}]*overflow-y: auto;/);
  assert.match(stylesheet, /\.lir-lane footer \{ position: static;/);
  assert.match(stylesheet, /\.lir-selected-total \{ position: static;/);
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
  assert.match(source, /const ZOOM_LEVELS = \[67, 75, 80, 90, 100, 110, 125, 150\]/);
  assert.match(source, /sistema-pollos-recepcion-pollo-vivo-zoom-v1/);
  assert.match(source, /document\.documentElement\.style\.zoom = String\(normalized \/ 100\)/);
  assert.match(source, /event\.key === ZOOM_STORAGE_KEY/);
});

test("la captura usa una sola balanza y guarda cada pesada inmediatamente", () => {
  assert.match(source, /new RetailScaleController\(/);
  assert.equal((source.match(/new RetailScaleController\(/g) || []).length, 1);
  assert.match(source, /apiRequest\("\/recepcion-pollo-vivo\/pesadas", \{[\s\S]*?method: "POST"/);
  assert.match(source, /BALANZA_RECEPCION_POLLO_VIVO/);
  assert.match(source, /state\.pendingCapture/);
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
  assert.match(source, /const deterministicClientError = status >= 400 && status < 500 && status !== 408/);
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
  assert.match(source, /LEGACY_PENDING_CAPTURE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v2"/);
  assert.match(source, /function migrateLegacyPendingCapture\(payload\)/);
  assert.match(source, /Number\(payload\?\.layout_version\) !== 2/);
  assert.match(source, /const migrated = \{ \.\.\.payload, layout_version: LAYOUT_VERSION \}/);
  assert.match(source, /migrated\.dispatch_client_id = Number\(defaultClient\.id\)/);
  assert.match(source, /restoringLegacy && persistPendingCapture\(payload\)/);
  assert.match(source, /localStorage\.removeItem\(state\.legacyPendingCaptureStorageKey\)/);
  assert.match(source, /state\.pendingUpgradeBlocked/);
  assert.match(source, /\|\| state\.pendingUpgradeBlocked/);
  assert.match(source, /currentPendingEvent[\s\S]*legacyPendingEvent/);
  assert.match(source, /`\$\{state\.legacyPendingCaptureStorageKey\}-request-lock`/);
  assert.match(source, /`\$\{state\.pendingCaptureStorageKey\}-request-lock`/);
  assert.match(source, /recoverLegacyPending[\s\S]*restorePendingCapture\(response\.data\.company\.id, response\.data\.branch\.id\)/);
  assert.match(source, /!event\.newValue && legacyPendingEvent && state\.pendingUpgradeBlocked/);
  assert.match(source, /Registra o activa un cliente y vuelve a esta vista/);
  assert.match(source, /async function refreshLegacyPendingFromSettings\(\)/);
  assert.match(source, /if \(state\.pendingUpgradeBlocked\) void refreshLegacyPendingFromSettings\(\)/);
});

test("un cliente invalidado durante la pesada obliga a elegir otro sin perder la lectura", () => {
  const recovery = source.match(
    /async function refreshAfterInvalidDispatchClient[\s\S]*?(?=\nasync function performCaptureWeighing)/,
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
  assert.match(source, /La lectura sigue congelada; pulsa Reintentar/);
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
