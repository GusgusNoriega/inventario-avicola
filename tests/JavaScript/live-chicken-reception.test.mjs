import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
  assignDispatchClientToPendingCapture,
  freezeDispatchClientCorrection,
} from "../../public/js/live-chicken-reception-pending.js";
import {
  buildReceptionTicketPayload,
  buildTicketUpdatePayload,
  calculateDraftTotals,
  dispatchDraftFingerprint,
  normalizeDispatchDraft,
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
  assert.match(stylesheet, /\.lir-lane > footer \{ position: static;/);
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
