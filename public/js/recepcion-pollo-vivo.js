import { apiRequest } from "./api-client.js";
import {
  RetailScaleController,
  RETAIL_SCALE_SERIAL_DEFAULTS,
} from "./despacho-minorista-balanza.js";
import { normalizeRetailClientSearch } from "./retail-client-search.js";
import {
  assignDispatchClientToPendingCapture,
  freezeDispatchClientCorrection,
} from "./live-chicken-reception-pending.js";
import { printWeightControlTicket } from "./ticket-printer.js";
import {
  buildReceptionTicketPayload,
  buildReceptionTicketPrintData,
  buildTicketUpdatePayload,
  calculateDraftTotals,
  createEmptyDispatchDraft,
  dispatchDraftFingerprint,
  dispatchDraftWeighingFingerprint,
  isDispatchTicketRecord,
  normalizeDispatchDraft,
  normalizeDraftWeighing,
  normalizeFullTicket,
  normalizeReceptionSex,
  remainingDispatchDraftAfterRegistration,
  receptionRecordLane,
  ticketRecordId,
} from "./live-chicken-reception-tickets.js";

const ZOOM_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
const ZOOM_STORAGE_KEY = "sistema-pollos-recepcion-pollo-vivo-zoom-v1";
const SCALE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-balanza-v1";
const LEGACY_PENDING_CAPTURE_V2_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v2";
const LEGACY_PENDING_CAPTURE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v3";
const PENDING_CAPTURE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v4";
const DISPATCH_DRAFT_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-tickets-v1";
const LAYOUT_VERSION = 4;
const LEGACY_PENDING_BLOCKED_MESSAGE = "Hay una pesada pendiente de la versión anterior y no hay un cliente activo para recuperarla. Registra o activa un cliente y vuelve a esta vista; la pesada se recuperará automáticamente. Las nuevas pesadas están bloqueadas para evitar duplicados.";
const LANE_NUMBERS = [1, 2, 3, 4, 5, 6];
const FALLBACK_LANE_PROFILES = {
  1: { type: "ALMACEN", ownerType: "PROPIA", sex: "MACHO" },
  2: { type: "ALMACEN", ownerType: "PROPIA", sex: "HEMBRA" },
  3: { type: "ALMACEN", ownerType: "EXTERNA", sex: "MACHO" },
  4: { type: "ALMACEN", ownerType: "EXTERNA", sex: "HEMBRA" },
  5: { type: "CLIENTE", ownerType: "PROPIA", sex: null },
  6: { type: "CLIENTE", ownerType: "PROPIA", sex: null },
};

const elements = {
  main: document.getElementById("liveIntakeMain"),
  operatingDate: document.getElementById("liveIntakeOperatingDate"),
  openSettings: document.getElementById("liveIntakeOpenSettings"),
  settingsModal: document.getElementById("liveIntakeSettingsModal"),
  settingsForm: document.getElementById("liveIntakeSettingsForm"),
  settingsMessage: document.getElementById("liveIntakeSettingsMessage"),
  openScaleSettings: document.getElementById("liveIntakeOpenScaleSettings"),
  scaleSettingsModal: document.getElementById("liveIntakeScaleSettingsModal"),
  scaleSettingsForm: document.getElementById("liveIntakeScaleSettingsForm"),
  scaleSettingsMessage: document.getElementById("liveIntakeScaleSettingsMessage"),
  openManualWeight: document.getElementById("liveIntakeOpenManualWeight"),
  manualWeightModal: document.getElementById("liveIntakeManualWeightModal"),
  manualWeightForm: document.getElementById("liveIntakeManualWeightForm"),
  manualWeightMessage: document.getElementById("liveIntakeManualWeightMessage"),
  clientModal: document.getElementById("liveIntakeClientModal"),
  clientModalTitle: document.getElementById("liveIntakeClientModalTitle"),
  clientModalHelp: document.getElementById("liveIntakeClientModalHelp"),
  clientSearch: document.getElementById("liveIntakeClientSearch"),
  clientOptions: document.getElementById("liveIntakeClientOptions"),
  clientMessage: document.getElementById("liveIntakeClientMessage"),
  clientPickerButtons: Array.from(document.querySelectorAll("[data-live-choose-client]")),
  registerTicketButtons: Array.from(document.querySelectorAll("[data-live-register-ticket]")),
  defaultExternalOwner: document.getElementById("liveIntakeDefaultExternalOwner"),
  laneDestinations: [
    document.getElementById("liveIntakeLane1Destination"),
    document.getElementById("liveIntakeLane2Destination"),
    document.getElementById("liveIntakeLane3Destination"),
    document.getElementById("liveIntakeLane4Destination"),
    document.getElementById("liveIntakeLane5Destination"),
    document.getElementById("liveIntakeLane6Destination"),
  ],
  laneLabels: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneDestination${lane}`)),
  laneProfileLabels: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneProfile${lane}`)),
  laneRows: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneRows${lane}`)),
  lanes: Array.from(document.querySelectorAll("[data-live-lane]")),
  laneButtons: Array.from(document.querySelectorAll("[data-live-select-lane]")),
  capturePanel: document.querySelector(".lir-capture-panel"),
  assignmentTitle: document.getElementById("liveIntakeAssignmentTitle"),
  assignmentHelp: document.getElementById("liveIntakeAssignmentHelp"),
  sexChoice: document.getElementById("liveIntakeSexChoice"),
  sexButtons: Array.from(document.querySelectorAll("[data-live-sex]")),
  birdsPerCage: document.getElementById("liveIntakeBirdsPerCage"),
  cageCount: document.getElementById("liveIntakeCageCount"),
  cageType: document.getElementById("liveIntakeCageType"),
  capture: document.getElementById("liveIntakeCapture"),
  captureLabel: document.getElementById("liveIntakeCaptureLabel"),
  activeLaneNumber: document.getElementById("liveIntakeActiveLaneNumber"),
  message: document.getElementById("liveIntakeMessage"),
  scaleStatus: document.getElementById("liveIntakeScaleStatus"),
  scaleWeight: document.getElementById("liveIntakeScaleWeight"),
  scaleRaw: document.getElementById("liveIntakeScaleRaw"),
  connectBle: document.getElementById("liveIntakeConnectBle"),
  connectSerial: document.getElementById("liveIntakeConnectSerial"),
  disconnectScale: document.getElementById("liveIntakeDisconnectScale"),
  manualWeight: document.getElementById("liveIntakeManualWeight"),
  baudRate: document.getElementById("liveIntakeBaudRate"),
  dataBits: document.getElementById("liveIntakeDataBits"),
  stopBits: document.getElementById("liveIntakeStopBits"),
  parity: document.getElementById("liveIntakeParity"),
  flowControl: document.getElementById("liveIntakeFlowControl"),
  zoomOut: document.getElementById("liveIntakeZoomOut"),
  zoomIn: document.getElementById("liveIntakeZoomIn"),
  zoomReset: document.getElementById("liveIntakeZoomReset"),
  zoomValue: document.getElementById("liveIntakeZoomValue"),
  dailyWeighings: document.getElementById("liveIntakeDailyWeighings"),
  dailyCages: document.getElementById("liveIntakeDailyCages"),
  dailyBirds: document.getElementById("liveIntakeDailyBirds"),
  dailyNet: document.getElementById("liveIntakeDailyNet"),
  ownBirds: document.getElementById("liveIntakeOwnBirds"),
  externalSummaryLabel: document.getElementById("liveIntakeExternalSummaryLabel"),
  externalBirds: document.getElementById("liveIntakeExternalBirds"),
  laneCages: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneCages${lane}`)),
  laneBirds: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneBirds${lane}`)),
  laneNet: LANE_NUMBERS.map((lane) => document.getElementById(`liveIntakeLaneNet${lane}`)),
  selectedLaneLabel: document.getElementById("liveIntakeSelectedLaneLabel"),
  selectedWeighings: document.getElementById("liveIntakeSelectedWeighings"),
  selectedCages: document.getElementById("liveIntakeSelectedCages"),
  selectedBirds: document.getElementById("liveIntakeSelectedBirds"),
  selectedGross: document.getElementById("liveIntakeSelectedGross"),
  selectedNet: document.getElementById("liveIntakeSelectedNet"),
  deliveryTruckModal: document.getElementById("liveIntakeDeliveryTruckModal"),
  deliveryTruckHelp: document.getElementById("liveIntakeDeliveryTruckHelp"),
  deliveryTruckSearch: document.getElementById("liveIntakeDeliveryTruckSearch"),
  deliveryTruckOptions: document.getElementById("liveIntakeDeliveryTruckOptions"),
  deliveryDriverModal: document.getElementById("liveIntakeDeliveryDriverModal"),
  deliveryDriverHelp: document.getElementById("liveIntakeDeliveryDriverHelp"),
  deliveryDriverSearch: document.getElementById("liveIntakeDeliveryDriverSearch"),
  deliveryDriverOptions: document.getElementById("liveIntakeDeliveryDriverOptions"),
  weighingEditorModal: document.getElementById("liveIntakeWeighingEditorModal"),
  weighingEditorForm: document.getElementById("liveIntakeWeighingEditorForm"),
  weighingEditorCaption: document.getElementById("liveIntakeWeighingEditorCaption"),
  weighingEditorTitle: document.getElementById("liveIntakeWeighingEditorTitle"),
  weighingEditorMessage: document.getElementById("liveIntakeWeighingEditorMessage"),
  editSex: document.getElementById("liveIntakeEditSex"),
  editCageType: document.getElementById("liveIntakeEditCageType"),
  editBirdsPerCage: document.getElementById("liveIntakeEditBirdsPerCage"),
  editCageCount: document.getElementById("liveIntakeEditCageCount"),
  editWeight: document.getElementById("liveIntakeEditWeight"),
  editWeighedAt: document.getElementById("liveIntakeEditWeighedAt"),
  editReason: document.getElementById("liveIntakeEditReason"),
  ticketEditorModal: document.getElementById("liveIntakeTicketEditorModal"),
  ticketEditorForm: document.getElementById("liveIntakeTicketEditorForm"),
  ticketEditorTitle: document.getElementById("liveIntakeTicketEditorTitle"),
  ticketEditorClient: document.getElementById("liveIntakeTicketEditorClient"),
  ticketEditorSummary: document.getElementById("liveIntakeTicketEditorSummary"),
  ticketEditorRows: document.getElementById("liveIntakeTicketEditorRows"),
  ticketEditorMessage: document.getElementById("liveIntakeTicketEditorMessage"),
  ticketEditReason: document.getElementById("liveIntakeTicketEditReason"),
  printTicket: document.getElementById("liveIntakePrintTicket"),
  saveTicket: document.getElementById("liveIntakeSaveTicket"),
};

const state = {
  data: null,
  activeLane: 1,
  sex: "MACHO",
  busy: false,
  pendingCapture: null,
  pendingCaptureStorageKey: null,
  legacyPendingCaptureStorageKey: null,
  legacyV2PendingCaptureStorageKey: null,
  dispatchDraftStorageKey: null,
  pendingUpgradeBlocked: false,
  pendingUpgradeLane: null,
  directClientIds: { 5: null, 6: null },
  directClientReselectionRequired: { 5: false, 6: false },
  draftClientInvalid: { 5: false, 6: false },
  pendingDispatchClientCorrection: false,
  clientPickerLane: null,
  clientPickerTrigger: null,
  dispatchDrafts: {
    5: createEmptyDispatchDraft(5, createUuid()),
    6: createEmptyDispatchDraft(6, createUuid()),
  },
  dispatchDraftsHydrated: false,
  dispatchDraftOperatingDate: null,
  expiredDispatchDrafts: null,
  expiredDraftOperatingDate: null,
  dispatchDraftsBlocked: false,
  deliverySelection: null,
  editingRecord: null,
  editingTicket: null,
  ticketEditorTrigger: null,
  lastRegisteredTicket: null,
  zoom: readZoom(),
  scale: null,
};

function createUuid() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    const value = character === "x" ? random : (random & 0x3) | 0x8;
    return value.toString(16);
  });
}

function readZoom() {
  try {
    const saved = Number(localStorage.getItem(ZOOM_STORAGE_KEY));
    return ZOOM_LEVELS.includes(saved) ? saved : 100;
  } catch {
    return 100;
  }
}

function persistPendingCapture(payload) {
  if (!state.pendingCaptureStorageKey) return false;
  try {
    localStorage.setItem(state.pendingCaptureStorageKey, JSON.stringify(payload));
    return true;
  } catch {
    // El aviso de salida sigue protegiendo la captura si no hay almacenamiento.
    return false;
  }
}

function clearPendingCapture() {
  state.pendingCapture = null;
  state.pendingUpgradeBlocked = false;
  state.pendingUpgradeLane = null;
  state.pendingDispatchClientCorrection = false;
  if (!state.pendingCaptureStorageKey) return;
  try {
    localStorage.removeItem(state.pendingCaptureStorageKey);
    if (state.legacyPendingCaptureStorageKey) {
      localStorage.removeItem(state.legacyPendingCaptureStorageKey);
    }
    if (state.legacyV2PendingCaptureStorageKey) {
      localStorage.removeItem(state.legacyV2PendingCaptureStorageKey);
    }
  } catch {
    // La memoria de la pestaña se limpia aunque el almacenamiento esté bloqueado.
  }
}

function pendingCaptureStorageKey(prefix, companyId, branchId, userId) {
  return `${prefix}-company-${companyId}-branch-${branchId}-user-${userId}`;
}

function configureReceptionStorageKeys(companyId, branchId) {
  const userId = Number(elements.main?.dataset.liveUserId);
  if (!Number.isInteger(userId) || userId < 1) return false;
  state.pendingCaptureStorageKey = pendingCaptureStorageKey(PENDING_CAPTURE_STORAGE_PREFIX, companyId, branchId, userId);
  state.legacyPendingCaptureStorageKey = pendingCaptureStorageKey(LEGACY_PENDING_CAPTURE_STORAGE_PREFIX, companyId, branchId, userId);
  state.legacyV2PendingCaptureStorageKey = pendingCaptureStorageKey(LEGACY_PENDING_CAPTURE_V2_STORAGE_PREFIX, companyId, branchId, userId);
  state.dispatchDraftStorageKey = pendingCaptureStorageKey(DISPATCH_DRAFT_STORAGE_PREFIX, companyId, branchId, userId);
  return true;
}

function dispatchDraft(lane) {
  const laneNumber = [5, 6].includes(Number(lane)) ? Number(lane) : 5;
  if (!state.dispatchDrafts[laneNumber]) {
    state.dispatchDrafts[laneNumber] = createEmptyDispatchDraft(laneNumber, createUuid());
  }
  return state.dispatchDrafts[laneNumber];
}

async function withDispatchDraftLock(_lane, operation) {
  if (!navigator.locks?.request || !state.dispatchDraftStorageKey) {
    return { acquired: true, value: await operation() };
  }

  return navigator.locks.request(
    `${state.dispatchDraftStorageKey}-mutation-lock`,
    { mode: "exclusive", ifAvailable: true },
    async (lock) => (lock
      ? { acquired: true, value: await operation() }
      : { acquired: false, value: null }),
  );
}

function dispatchDraftHasPendingRegistration(lane) {
  return Boolean(dispatchDraft(lane).registration_attempt);
}

function registrationAttemptDelivery(attempt = {}) {
  return {
    clientId: Number(attempt.dispatch_client_id) || null,
    vehicleId: Number(attempt.delivery_vehicle_id) || null,
    driverId: Number(attempt.delivery_driver_id) || null,
  };
}

function persistDispatchDrafts() {
  if (!state.dispatchDraftStorageKey || state.dispatchDraftsBlocked) return false;
  try {
    localStorage.setItem(state.dispatchDraftStorageKey, JSON.stringify({
      version: 1,
      operating_date: state.data?.operating_date || state.dispatchDraftOperatingDate,
      drafts: {
        5: dispatchDraft(5),
        6: dispatchDraft(6),
      },
    }));
    return true;
  } catch {
    return false;
  }
}

function hydrateDispatchDrafts(companyId, branchId, force = false) {
  if (!configureReceptionStorageKeys(companyId, branchId)) return;
  const operatingDate = String(state.data?.operating_date || "");
  if (state.dispatchDraftsHydrated && state.dispatchDraftOperatingDate === operatingDate && !force) return;
  let stored = null;
  try {
    stored = JSON.parse(localStorage.getItem(state.dispatchDraftStorageKey) || "null");
  } catch {
    stored = null;
  }
  const storedOperatingDate = String(stored?.operating_date || operatingDate);
  const storedDrafts = stored?.drafts || stored || {};
  const hasStoredWeighings = [5, 6].some((lane) => {
    const candidate = storedDrafts[lane] || storedDrafts[String(lane)];
    return Array.isArray(candidate?.weighings) && candidate.weighings.length > 0;
  });
  if (storedOperatingDate && operatingDate && storedOperatingDate !== operatingDate && hasStoredWeighings) {
    state.expiredDispatchDrafts = storedDrafts;
    state.expiredDraftOperatingDate = storedOperatingDate;
    state.dispatchDraftsBlocked = true;
    state.dispatchDrafts = {
      5: createEmptyDispatchDraft(5, createUuid()),
      6: createEmptyDispatchDraft(6, createUuid()),
    };
    state.dispatchDraftsHydrated = true;
    state.dispatchDraftOperatingDate = operatingDate;
    return;
  }
  state.expiredDispatchDrafts = null;
  state.expiredDraftOperatingDate = null;
  state.dispatchDraftsBlocked = false;
  const drafts = storedDrafts;
  state.dispatchDrafts = {
    5: normalizeDispatchDraft(drafts[5] || drafts["5"], 5, createUuid),
    6: normalizeDispatchDraft(drafts[6] || drafts["6"], 6, createUuid),
  };
  [5, 6].forEach((lane) => {
    const draft = dispatchDraft(lane);
    draft.weighings = draft.weighings.map((weighing, index) => {
      const cageType = catalogItem("cage_types", weighing.cage_type_id);
      const storedCageWeight = Number(weighing.cage_weight_kg ?? weighing.cage_type?.weight_kg);
      const catalogCageWeight = Number(cageType?.weight_kg ?? cageType?.peso_kg ?? 0);
      const cageWeight = Number.isFinite(storedCageWeight) && storedCageWeight > 0
        ? storedCageWeight
        : catalogCageWeight;
      const tare = Number(weighing.tare_weight_kg) > 0
        ? Number(weighing.tare_weight_kg)
        : Math.round(cageWeight * Number(weighing.cage_count) * 1000) / 1000;
      return normalizeDraftWeighing({
        ...weighing,
        cage_type_name: weighing.cage_type_name || cageType?.name,
        cage_weight_kg: cageWeight,
        tare_weight_kg: tare,
        net_weight_kg: Math.round((Number(weighing.read_weight_kg) - tare) * 1000) / 1000,
      }, index);
    });
    if (draft.dispatch_client_id) state.directClientIds[lane] = Number(draft.dispatch_client_id);
  });
  state.dispatchDraftsHydrated = true;
  state.dispatchDraftOperatingDate = operatingDate;
}

async function discardExpiredDispatchDrafts() {
  if (!state.dispatchDraftsBlocked || state.busy) return;
  const hasUnconfirmedTicket = [5, 6].some((lane) => Boolean(
    (state.expiredDispatchDrafts?.[lane] || state.expiredDispatchDrafts?.[String(lane)])?.registration_attempt,
  ));
  const warning = hasUnconfirmedTicket
    ? " Hay al menos un ticket cuya respuesta no fue confirmada; compruébalo primero para evitar ocultar un despacho ya registrado."
    : "";
  if (!window.confirm(`¿Descartar los borradores pendientes de la jornada ${state.expiredDraftOperatingDate}?${warning} Esta acción no se puede deshacer.`)) return;
  let lockResult;
  try {
    lockResult = await withDispatchDraftLock(null, async () => {
      state.dispatchDraftsBlocked = false;
      state.expiredDispatchDrafts = null;
      state.expiredDraftOperatingDate = null;
      state.dispatchDrafts = {
        5: createEmptyDispatchDraft(5, createUuid()),
        6: createEmptyDispatchDraft(6, createUuid()),
      };
      reconcileDirectClientSelections(true);
      if (!persistDispatchDrafts()) throw new Error("No se pudieron descartar los borradores en esta tablet.");
    });
  } catch (error) {
    state.dispatchDraftsHydrated = false;
    hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
    renderLaneAssignments();
    renderRecords();
    renderTotals();
    setMessage(error.message || "No se pudieron descartar los borradores.", "error");
    return;
  }
  if (!lockResult.acquired) {
    setMessage("Otra pestaña está comprobando o registrando un ticket. Espera antes de descartar.", "error");
    return;
  }
  renderLaneAssignments();
  renderRecords();
  renderTotals();
  updateCaptureAvailability();
  setMessage("Borradores vencidos descartados. Las columnas 5 y 6 están listas para la jornada actual.", "success");
}

async function retryExpiredDispatchTicket(lane, trigger) {
  const laneNumber = Number(lane);
  const rawDraft = state.expiredDispatchDrafts?.[laneNumber] || state.expiredDispatchDrafts?.[String(laneNumber)];
  const draft = normalizeDispatchDraft(rawDraft, laneNumber, createUuid);
  const attempt = draft.registration_attempt;
  if (!state.dispatchDraftsBlocked || !attempt) return;
  if (attempt.fingerprint !== dispatchDraftFingerprint(draft)) {
    setMessage("El ticket vencido ya no coincide con el intento original. No se descartó ninguna pesada.", "error");
    return;
  }

  try {
    const lockResult = await withDispatchDraftLock(laneNumber, async () => {
      state.busy = true;
      trigger.disabled = true;
      setMessage(`Comprobando el ticket pendiente de la columna ${laneNumber}…`);
      try {
        const response = await apiRequest("/recepcion-pollo-vivo/tickets", {
          method: "POST",
          body: JSON.stringify(buildReceptionTicketPayload(draft, registrationAttemptDelivery(attempt))),
        });
        const registeredTicket = normalizeFullTicket(response.ticket || response.data?.ticket || {});
        let stored = null;
        try { stored = JSON.parse(localStorage.getItem(state.dispatchDraftStorageKey) || "null"); } catch { stored = null; }
        const latestDrafts = stored?.drafts || stored
          || JSON.parse(JSON.stringify(state.expiredDispatchDrafts || {}));
        const latestTarget = normalizeDispatchDraft(latestDrafts[laneNumber] || latestDrafts[String(laneNumber)], laneNumber, createUuid);
        if (latestTarget.draft_id === draft.draft_id) {
          latestDrafts[laneNumber] = createEmptyDispatchDraft(laneNumber, createUuid());
        }
        const hasExpiredWeighings = [5, 6].some((candidateLane) => {
          const candidate = latestDrafts[candidateLane] || latestDrafts[String(candidateLane)];
          return Array.isArray(candidate?.weighings) && candidate.weighings.length > 0;
        });
        localStorage.setItem(state.dispatchDraftStorageKey, JSON.stringify({
          version: 1,
          operating_date: hasExpiredWeighings ? state.expiredDraftOperatingDate : state.data.operating_date,
          drafts: hasExpiredWeighings ? latestDrafts : {
            5: createEmptyDispatchDraft(5, createUuid()),
            6: createEmptyDispatchDraft(6, createUuid()),
          },
        }));
        state.dispatchDraftsHydrated = false;
        renderData(response.data);
        state.lastRegisteredTicket = registeredTicket.id ? registeredTicket : null;
        setMessage(response.message || `${registeredTicket.code || "Ticket"} confirmado; sus pesadas vencidas se retiraron del borrador.`, "success");
        if (registeredTicket.id) {
          state.ticketEditorTrigger = trigger;
          state.editingTicket = registeredTicket;
          state.ticketEditorCatalog = { cage_types: state.data?.catalog?.cage_types || [] };
          openDialog(elements.ticketEditorModal, trigger, elements.printTicket);
          renderTicketEditor();
          setTicketEditorMessage(`${registeredTicket.code} confirmado. Ya puedes imprimirlo.`, "success");
        }
      } catch (error) {
        setMessage(`${firstValidationMessage(error)} El borrador vencido se conservó sin cambios.`, "error");
      } finally {
        state.busy = false;
        trigger.disabled = false;
        updateScaleUi();
      }
    });
    if (!lockResult.acquired) {
      setMessage("Otra pestaña está comprobando este ticket. Espera e inténtalo nuevamente.", "error");
    }
  } catch (error) {
    state.busy = false;
    trigger.disabled = false;
    setMessage(error.message || "No se pudo comprobar el ticket vencido.", "error");
    updateScaleUi();
  }
}

function resetDispatchDraft(lane) {
  const laneNumber = Number(lane);
  state.dispatchDrafts[laneNumber] = createEmptyDispatchDraft(laneNumber, createUuid());
  const configuredClientId = Number(laneConfiguration(laneNumber)?.destination_id);
  const configuredClient = catalogItem("clients", configuredClientId);
  if (configuredClient) {
    state.dispatchDrafts[laneNumber].dispatch_client_id = configuredClientId;
    state.dispatchDrafts[laneNumber].dispatch_client_name = String(configuredClient.name);
    state.directClientIds[laneNumber] = configuredClientId;
  }
  persistDispatchDrafts();
}

function migrateLegacyPendingCapture(payload) {
  if (![2, 3, 4].includes(Number(payload?.layout_version))) return payload;
  const lane = Number(payload?.lane);
  const migrated = { ...payload, layout_version: LAYOUT_VERSION };

  if (Number(payload?.layout_version) === 2 && [5, 6].includes(lane)) {
    const defaultClientId = Number(laneConfiguration(lane)?.destination_id);
    const defaultClient = catalogItem("clients", defaultClientId);
    if (!defaultClient) {
      const error = new Error("No se pudo recuperar el cliente de la captura anterior.");
      error.code = "LEGACY_CLIENT_UNAVAILABLE";
      error.lane = lane;
      throw error;
    }
    migrated.dispatch_client_id = Number(defaultClient.id);
    migrated.dispatch_client_name = String(defaultClient.name);
  }

  return migrated;
}

function validatePendingCapturePayload(payload) {
  const lane = Number(payload?.lane);
  const birdsPerCage = Number(payload?.birds_per_cage);
  const cageCount = Number(payload?.cage_count);
  const cageTypeId = Number(payload?.cage_type_id);
  const readWeight = Number(payload?.read_weight_kg);
  if (Number(payload?.layout_version) !== LAYOUT_VERSION
    || typeof payload?.idempotency_key !== "string"
    || !Number.isInteger(lane) || lane < 1 || lane > 6
    || !Number.isInteger(birdsPerCage) || birdsPerCage < 1 || birdsPerCage > 1000
    || !Number.isInteger(cageCount) || cageCount < 1 || cageCount > 10000
    || !Number.isInteger(cageTypeId) || cageTypeId < 1
    || !Number.isFinite(readWeight) || readWeight <= 0
    || ([5, 6].includes(lane) && !["MACHO", "HEMBRA"].includes(payload.sex))) {
    throw new Error("Captura pendiente incompatible");
  }
}

function migratePendingDispatchToDraft(payload, sourceKey) {
  const lane = Number(payload.lane);
  const clientId = Number(payload.dispatch_client_id);
  const client = catalogItem("clients", clientId);
  if (!Number.isInteger(clientId) || clientId < 1 || !client) {
    const error = new Error("El cliente anterior ya no está disponible.");
    error.code = "LEGACY_CLIENT_UNAVAILABLE";
    error.lane = lane;
    throw error;
  }
  const draft = dispatchDraft(lane);
  const existingClientId = Number(draft.dispatch_client_id);
  if (draft.weighings.length && existingClientId && existingClientId !== clientId) {
    const error = new Error(`La columna ${lane} ya tiene otro cliente. Registra primero ese borrador para recuperar esta pesada.`);
    error.code = "LEGACY_DRAFT_CONFLICT";
    error.lane = lane;
    throw error;
  }
  draft.dispatch_client_id = clientId;
  draft.dispatch_client_name = String(payload.dispatch_client_name || client.name);
  state.directClientIds[lane] = clientId;
  const alreadyMigrated = draft.weighings.some((item) => String(item.local_id) === String(payload.idempotency_key));
  if (!alreadyMigrated) {
    const cageType = catalogItem("cage_types", payload.cage_type_id);
    const cages = Number(payload.cage_count);
    const cageWeight = Number(cageType?.weight_kg ?? cageType?.peso_kg ?? 0);
    const gross = Number(payload.read_weight_kg);
    const tare = Math.round(cageWeight * cages * 1000) / 1000;
    draft.weighings.push(normalizeDraftWeighing({
      ...payload,
      local_id: payload.idempotency_key,
      idempotency_key: payload.idempotency_key,
      cage_type_name: cageType?.name || `Java #${payload.cage_type_id}`,
      cage_weight_kg: cageWeight,
      gross_weight_kg: gross,
      tare_weight_kg: tare,
      net_weight_kg: Math.round((gross - tare) * 1000) / 1000,
      birds: Number(payload.birds_per_cage) * cages,
    }, draft.weighings.length));
  }
  if (!persistDispatchDrafts()) {
    throw new Error("No se pudo guardar el borrador recuperado en esta tablet.");
  }
  localStorage.removeItem(sourceKey);
  state.migratedDraftLane = lane;
  state.activeLane = lane;
  state.sex = normalizeReceptionSex(payload.sex);
}

async function restorePendingCapture(companyId, branchId) {
  if (!configureReceptionStorageKeys(companyId, branchId)) return false;
  state.migratedDraftLane = null;
  let restoringLegacy = false;
  let sourceKey = state.pendingCaptureStorageKey;
  let rawPendingPayload = null;
  try {
    let stored = localStorage.getItem(state.pendingCaptureStorageKey);
    if (!stored) {
      stored = localStorage.getItem(state.legacyPendingCaptureStorageKey);
      restoringLegacy = Boolean(stored);
      sourceKey = state.legacyPendingCaptureStorageKey;
    }
    if (!stored) {
      stored = localStorage.getItem(state.legacyV2PendingCaptureStorageKey);
      restoringLegacy = Boolean(stored);
      sourceKey = state.legacyV2PendingCaptureStorageKey;
    }
    if (!stored) {
      state.pendingUpgradeBlocked = false;
      state.pendingUpgradeLane = null;
      return false;
    }
    rawPendingPayload = JSON.parse(stored);
    const payload = migrateLegacyPendingCapture(rawPendingPayload);
    validatePendingCapturePayload(payload);
    const lane = Number(payload?.lane);
    const birdsPerCage = Number(payload?.birds_per_cage);
    const cageCount = Number(payload?.cage_count);
    const cageTypeId = Number(payload?.cage_type_id);
    const readWeight = Number(payload?.read_weight_kg);
    const requiresSex = [5, 6].includes(lane);
    const requiresClient = [5, 6].includes(lane);
    const needsDispatchClientReselection = payload?.requires_dispatch_client_reselection === true;
    const dispatchClientId = Number(payload?.dispatch_client_id);
    if (requiresClient && !needsDispatchClientReselection) {
      const lockResult = await withDispatchDraftLock(lane, async () => {
        hydrateDispatchDrafts(companyId, branchId, true);
        reconcileDirectClientSelections();
        migratePendingDispatchToDraft(payload, sourceKey);
      });
      if (!lockResult.acquired) {
        state.pendingUpgradeBlocked = true;
        state.pendingUpgradeLane = lane;
        state.pendingUpgradeMessage = "Otra pestaña está modificando los tickets. La pesada anterior se conserva y se recuperará al reintentar.";
        return false;
      }
      state.pendingCapture = null;
      state.pendingUpgradeBlocked = false;
      renderLaneAssignments();
      renderRecords();
      renderTotals();
      selectLane(lane);
      return false;
    }

    state.pendingCapture = payload;
    state.pendingUpgradeBlocked = false;
    state.pendingUpgradeLane = null;
    state.pendingDispatchClientCorrection = needsDispatchClientReselection;
    state.activeLane = lane;
    if (requiresClient) {
      state.directClientIds[lane] = needsDispatchClientReselection ? null : dispatchClientId;
      state.directClientReselectionRequired[lane] = needsDispatchClientReselection;
    }
    if (["MACHO", "HEMBRA"].includes(payload.sex)) state.sex = payload.sex;
    elements.birdsPerCage.value = String(birdsPerCage);
    elements.cageCount.value = String(cageCount);
    if (!Array.from(elements.cageType.options).some((option) => Number(option.value) === cageTypeId)) {
      const pendingOption = document.createElement("option");
      pendingOption.value = String(cageTypeId);
      pendingOption.textContent = `Tipo de java #${cageTypeId} · captura pendiente`;
      elements.cageType.append(pendingOption);
    }
    elements.cageType.value = String(cageTypeId);
    if (state.data) renderLaneAssignments();
    selectLane(lane);
    if (payload.sex) selectSex(payload.sex);

    if (restoringLegacy && persistPendingCapture(payload)) {
      localStorage.removeItem(sourceKey);
    }

    return true;
  } catch (error) {
    state.pendingCapture = null;
    if (error?.code === "LEGACY_CLIENT_UNAVAILABLE" && [5, 6].includes(Number(error.lane))) {
      const recoverable = {
        ...(rawPendingPayload || {}),
        layout_version: LAYOUT_VERSION,
      };
      state.pendingCapture = freezeDispatchClientCorrection(recoverable);
      state.pendingDispatchClientCorrection = true;
      state.directClientIds[Number(error.lane)] = null;
      state.directClientReselectionRequired[Number(error.lane)] = true;
      state.activeLane = Number(error.lane);
      state.pendingUpgradeBlocked = false;
      state.pendingUpgradeLane = null;
      state.pendingUpgradeMessage = error.message;
      persistPendingCapture(state.pendingCapture);
      if (sourceKey !== state.pendingCaptureStorageKey) {
        try { localStorage.removeItem(sourceKey); } catch { /* El peso sigue en la clave nueva. */ }
      }
      if (state.data) {
        renderLaneAssignments();
        selectLane(error.lane);
      }
      return true;
    }
    if (error?.code === "LEGACY_DRAFT_CONFLICT") {
      state.pendingUpgradeBlocked = true;
      state.pendingUpgradeLane = Number(error.lane) || null;
      state.pendingUpgradeMessage = error.message;
      return false;
    }
    clearPendingCapture();
    return false;
  }
}

function applyZoom(value, persist = true) {
  const normalized = ZOOM_LEVELS.includes(Number(value)) ? Number(value) : 100;
  state.zoom = normalized;
  document.documentElement.style.zoom = String(normalized / 100);
  elements.zoomValue.textContent = `${normalized} %`;
  elements.zoomOut.disabled = normalized === ZOOM_LEVELS[0];
  elements.zoomIn.disabled = normalized === ZOOM_LEVELS.at(-1);

  if (persist) {
    try {
      localStorage.setItem(ZOOM_STORAGE_KEY, String(normalized));
    } catch {
      // La vista sigue funcionando aunque el navegador bloquee preferencias.
    }
  }
}

function stepZoom(direction) {
  const currentIndex = Math.max(0, ZOOM_LEVELS.indexOf(state.zoom));
  const nextIndex = Math.min(ZOOM_LEVELS.length - 1, Math.max(0, currentIndex + direction));
  applyZoom(ZOOM_LEVELS[nextIndex]);
}

function setMessage(message, tone = "") {
  elements.message.textContent = message;
  elements.message.classList.toggle("is-error", tone === "error");
  elements.message.classList.toggle("is-success", tone === "success");
}

function setSettingsMessage(message, tone = "") {
  elements.settingsMessage.textContent = message;
  elements.settingsMessage.classList.toggle("is-error", tone === "error");
  elements.settingsMessage.classList.toggle("is-success", tone === "success");
}

function setScaleSettingsMessage(message, tone = "") {
  elements.scaleSettingsMessage.textContent = message;
  elements.scaleSettingsMessage.classList.toggle("is-error", tone === "error");
  elements.scaleSettingsMessage.classList.toggle("is-success", tone === "success");
}

function setManualWeightMessage(message, tone = "") {
  elements.manualWeightMessage.textContent = message;
  elements.manualWeightMessage.classList.toggle("is-error", tone === "error");
  elements.manualWeightMessage.classList.toggle("is-success", tone === "success");
}

function setClientMessage(message, tone = "") {
  elements.clientMessage.textContent = message;
  elements.clientMessage.classList.toggle("is-error", tone === "error");
  elements.clientMessage.classList.toggle("is-success", tone === "success");
}

function syncModalEnvironment() {
  const modalOpen = [
    elements.settingsModal,
    elements.scaleSettingsModal,
    elements.manualWeightModal,
    elements.clientModal,
    elements.deliveryTruckModal,
    elements.deliveryDriverModal,
    elements.weighingEditorModal,
    elements.ticketEditorModal,
  ].some((modal) => !modal.hidden);
  document.body.classList.toggle("lir-modal-open", modalOpen);
  elements.main.inert = modalOpen;
}

function openDialog(modal, trigger, initialFocus) {
  modal.hidden = false;
  trigger.setAttribute("aria-expanded", "true");
  syncModalEnvironment();
  globalThis.setTimeout(() => initialFocus?.focus({ preventScroll: true }), 0);
}

function closeDialog(modal, trigger) {
  if (modal.hidden) return;
  modal.hidden = true;
  trigger.setAttribute("aria-expanded", "false");
  syncModalEnvironment();
  const focusTarget = trigger.disabled ? elements.capture : trigger;
  focusTarget?.focus({ preventScroll: true });
}

function trapDialogFocus(event, modal) {
  if (event.key !== "Tab") return;
  const controls = Array.from(modal.querySelectorAll(
    'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter((control) => !control.hidden);
  if (!controls.length) return;
  const first = controls[0];
  const last = controls.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function firstValidationMessage(error) {
  const errors = error?.data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat().find(Boolean);
    if (first) return String(first);
  }
  return error?.message || "No se pudo completar la solicitud.";
}

function hasValidationError(error, field) {
  const errors = error?.data?.errors;
  return Boolean(errors && Object.prototype.hasOwnProperty.call(errors, field));
}

function formatKg(value) {
  return `${(Number(value) || 0).toFixed(3)} kg`;
}

function formatTime(value) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return "--:--";
  return new Intl.DateTimeFormat("es", { hour: "2-digit", minute: "2-digit" }).format(date);
}

function formatOperatingDate(value) {
  const [year, month, day] = String(value || "").split("-").map(Number);
  if (!year || !month || !day) return "Jornada de hoy";
  return new Intl.DateTimeFormat("es", { weekday: "short", day: "2-digit", month: "short" })
    .format(new Date(year, month - 1, day));
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function optionMarkup(items, placeholder) {
  return [
    `<option value="">${escapeHtml(placeholder)}</option>`,
    ...items.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`),
  ].join("");
}

function setSelectOptions(select, items, placeholder, selectedId) {
  select.innerHTML = optionMarkup(items, placeholder);
  select.value = selectedId ? String(selectedId) : "";
}

function catalogItem(kind, id) {
  const items = state.data?.catalog?.[kind] || [];
  return items.find((item) => Number(item.id) === Number(id)) || null;
}

function laneConfiguration(lane) {
  return state.data?.configuration?.lanes?.[String(lane)] || null;
}

function laneProfile(lane) {
  const laneNumber = Number(lane);
  const fallback = FALLBACK_LANE_PROFILES[laneNumber] || FALLBACK_LANE_PROFILES[1];
  const configuration = laneConfiguration(laneNumber);
  const hasConfiguredSex = Object.prototype.hasOwnProperty.call(configuration || {}, "sex");

  return {
    type: String(configuration?.type || fallback.type).toUpperCase(),
    ownerType: String(configuration?.owner_type || fallback.ownerType).toUpperCase(),
    sex: hasConfiguredSex
      ? (configuration.sex ? String(configuration.sex).toUpperCase() : null)
      : fallback.sex,
  };
}

function laneDestination(lane) {
  const configuration = laneConfiguration(lane);
  if (laneProfile(lane).type === "ALMACEN") {
    return catalogItem("warehouses", configuration?.destination_id);
  }

  const draftForLane = [5, 6].includes(Number(lane)) ? dispatchDraft(lane) : null;
  const pendingForLane = Number(state.pendingCapture?.lane) === Number(lane)
    ? state.pendingCapture
    : null;
  const selectedId = pendingForLane?.dispatch_client_id
    ?? draftForLane?.dispatch_client_id
    ?? state.directClientIds[Number(lane)];
  const client = catalogItem("clients", selectedId);
  if (client) return client;
  if (draftForLane?.dispatch_client_id) {
    return {
      id: Number(draftForLane.dispatch_client_id),
      name: draftForLane.dispatch_client_name || `Cliente #${draftForLane.dispatch_client_id}`,
      document_number: null,
      unavailable: true,
    };
  }
  if (!pendingForLane?.dispatch_client_id) return null;

  return {
    id: Number(pendingForLane.dispatch_client_id),
    name: pendingForLane.dispatch_client_name || `Cliente #${pendingForLane.dispatch_client_id}`,
    document_number: null,
    pending: true,
  };
}

function reconcileDirectClientSelections(reset = false) {
  [5, 6].forEach((lane) => {
    const draft = dispatchDraft(lane);
    if (draft.weighings.length) {
      const draftClientAvailable = Boolean(catalogItem("clients", draft.dispatch_client_id));
      state.directClientIds[lane] = Number(draft.dispatch_client_id) || null;
      state.draftClientInvalid[lane] = !draftClientAvailable;
      return;
    }
    state.draftClientInvalid[lane] = false;
    if (Number(state.pendingCapture?.lane) === lane) {
      const correctingClient = state.pendingDispatchClientCorrection;
      state.directClientIds[lane] = correctingClient
        ? null
        : Number(state.pendingCapture.dispatch_client_id);
      state.directClientReselectionRequired[lane] = correctingClient;
      return;
    }

    if (state.directClientReselectionRequired[lane] && !reset) {
      state.directClientIds[lane] = null;
      return;
    }

    if (reset) state.directClientReselectionRequired[lane] = false;

    const currentId = draft.dispatch_client_id || state.directClientIds[lane];
    if (!reset && catalogItem("clients", currentId)) return;
    const defaultId = laneConfiguration(lane)?.destination_id;
    state.directClientIds[lane] = catalogItem("clients", defaultId)
      ? Number(defaultId)
      : null;
    draft.dispatch_client_id = state.directClientIds[lane];
    draft.dispatch_client_name = catalogItem("clients", state.directClientIds[lane])?.name || "";
  });
}

function renderLaneAssignments() {
  if (!state.data) return;
  const externalOwner = catalogItem(
    "external_owners",
    state.data.configuration.default_external_owner_id,
  );

  LANE_NUMBERS.forEach((lane) => {
    const destination = laneDestination(lane);
    elements.laneLabels[lane - 1].textContent = destination
      ? `${destination.name}${state.draftClientInvalid[lane] ? " · no disponible" : ""}`
      : (
      laneProfile(lane).type === "CLIENTE" ? "Sin cliente" : "Sin configurar"
    );
    const profile = laneProfile(lane);
    const ownerLabel = profile.ownerType === "EXTERNA"
      ? (externalOwner?.name || "Empresa externa sin configurar")
      : "Mi empresa";
    const sexLabel = profile.sex === "HEMBRA" ? "Hembra" : (profile.sex === "MACHO" ? "Macho" : "Sexo al registrar");
    elements.laneProfileLabels[lane - 1].textContent = [5, 6].includes(lane)
      ? (state.dispatchDraftsBlocked
          ? `Mayorista 1 · Borrador vencido (${state.expiredDraftOperatingDate})`
          : (dispatchDraftHasPendingRegistration(lane)
              ? `Mayorista 1 · Confirmación pendiente · ${dispatchDraft(lane).weighings.length} pesadas`
              : `Mayorista 1 · ${dispatchDraft(lane).weighings.length || "Borrador vacío"}${dispatchDraft(lane).weighings.length ? " pesadas" : ""}`))
      : `${ownerLabel} · ${sexLabel}`;
  });

  elements.clientPickerButtons.forEach((button) => {
    const lane = Number(button.dataset.liveChooseClient);
    const client = laneDestination(lane);
    const needsCorrection = state.draftClientInvalid[lane];
    const pendingRegistration = dispatchDraftHasPendingRegistration(lane);
    const locked = dispatchDraft(lane).weighings.length > 0 && !needsCorrection;
    button.textContent = pendingRegistration
      ? "Registro pendiente"
      : (needsCorrection ? "Corregir cliente" : (locked ? "Cliente bloqueado" : (client ? "Cambiar cliente" : "Elegir cliente")));
    button.setAttribute(
      "aria-label",
      client
        ? `${needsCorrection ? "Corregir cliente no disponible" : (locked ? "Cliente bloqueado" : "Cambiar cliente")} de la columna ${lane}. Cliente actual: ${client.name}`
        : `Elegir cliente de despacho para la columna ${lane}`,
    );
  });
}

function populateConfiguration() {
  if (!state.data) return;
  const { catalog, configuration } = state.data;
  setSelectOptions(
    elements.defaultExternalOwner,
    catalog.external_owners || [],
    "Sin empresa externa predeterminada",
    configuration.default_external_owner_id,
  );
  setSelectOptions(elements.laneDestinations[0], catalog.warehouses || [], "Seleccionar almacén", laneConfiguration(1)?.destination_id);
  setSelectOptions(elements.laneDestinations[1], catalog.warehouses || [], "Seleccionar almacén", laneConfiguration(2)?.destination_id);
  setSelectOptions(elements.laneDestinations[2], catalog.warehouses || [], "Seleccionar almacén", laneConfiguration(3)?.destination_id);
  setSelectOptions(elements.laneDestinations[3], catalog.warehouses || [], "Seleccionar almacén", laneConfiguration(4)?.destination_id);
  setSelectOptions(elements.laneDestinations[4], catalog.clients || [], "Seleccionar cliente", laneConfiguration(5)?.destination_id);
  setSelectOptions(elements.laneDestinations[5], catalog.clients || [], "Seleccionar cliente", laneConfiguration(6)?.destination_id);

  const cageTypeId = elements.cageType.value;
  const activeCageTypes = (catalog.cage_types || []).filter((item) => item.active !== false);
  elements.cageType.innerHTML = optionMarkup(activeCageTypes, "Seleccionar tipo de java");
  const preferredCage = activeCageTypes.some((item) => String(item.id) === cageTypeId)
    ? cageTypeId
    : String(activeCageTypes[0]?.id || "");
  elements.cageType.value = preferredCage;

  elements.externalSummaryLabel.textContent = "Empresa externa";

  renderLaneAssignments();

  selectLane(state.activeLane);
}

function matchingDispatchClients(search = "") {
  const query = normalizeRetailClientSearch(search);
  const clients = state.data?.catalog?.clients || [];
  if (!query) return clients.slice(0, 100);

  return clients
    .map((client, index) => {
      const name = normalizeRetailClientSearch(client.name);
      const documentNumber = normalizeRetailClientSearch(client.document_number);
      const nameStarts = name.split(" ").some((word) => word.startsWith(query));
      const rank = nameStarts || documentNumber.startsWith(query)
        ? 0
        : (name.includes(query) || documentNumber.includes(query) ? 1 : Number.POSITIVE_INFINITY);
      return { client, index, rank };
    })
    .filter((entry) => Number.isFinite(entry.rank))
    .sort((left, right) => left.rank - right.rank || left.index - right.index)
    .slice(0, 100)
    .map((entry) => entry.client);
}

function renderClientOptions(search = "") {
  const lane = Number(state.clientPickerLane);
  const selectedId = laneDestination(lane)?.id;
  const clients = matchingDispatchClients(search);
  elements.clientOptions.innerHTML = clients.length
    ? clients.map((client) => {
      const selected = Number(client.id) === Number(selectedId);
      return `
        <button class="lir-client-option ${selected ? "is-selected" : ""}" type="button" data-live-client-option="${client.id}" aria-pressed="${selected}">
          <span><strong>${escapeHtml(client.name)}</strong><small>${escapeHtml(client.document_number || "Sin documento")}</small></span>
          <b>${selected ? "Elegido" : "Elegir"}</b>
        </button>`;
    }).join("")
    : '<p class="lir-client-empty">No hay clientes que coincidan con la búsqueda.</p>';

  if (!(state.data?.catalog?.clients || []).length) {
    setClientMessage("No hay clientes activos disponibles. Registra o activa un cliente antes de continuar.", "error");
  } else if ((state.data.catalog.clients || []).length > 100 && !String(search).trim()) {
    setClientMessage("Se muestran los primeros 100 clientes. Escribe un nombre o documento para encontrar otro.");
  } else {
    setClientMessage("");
  }
}

function openClientPicker(laneNumber, trigger) {
  const lane = Number(laneNumber);
  if (![5, 6].includes(lane)) return;
  if (dispatchDraftHasPendingRegistration(lane)) {
    setMessage("Este ticket está pendiente de confirmación y no puede cambiar de cliente. Pulsa Reintentar ticket.", "error");
    return;
  }
  if (dispatchDraft(lane).weighings.length && !state.draftClientInvalid[lane]) {
    setMessage(`El cliente de la columna ${lane} quedó bloqueado porque el ticket ya tiene pesadas. Registra el ticket o elimina todas sus pesadas para cambiarlo.`, "error");
    return;
  }
  const correctingPendingClient = state.pendingDispatchClientCorrection
    && Number(state.pendingCapture?.lane) === lane;
  if (state.busy || (state.pendingCapture && !correctingPendingClient)) {
    setMessage("Termina primero la pesada pendiente antes de cambiar el cliente.", "error");
    return;
  }

  selectLane(lane);
  state.clientPickerLane = lane;
  state.clientPickerTrigger = trigger;
  elements.clientModalTitle.textContent = `Elegir cliente para columna ${lane}`;
  elements.clientModalHelp.textContent = "La pesada se recibirá para mi empresa y, en el mismo registro, se despachará al cliente que elijas.";
  elements.clientSearch.value = "";
  renderClientOptions();
  openDialog(elements.clientModal, trigger, elements.clientSearch);
}

function closeClientPicker() {
  if (elements.clientModal.hidden) return;
  const trigger = state.clientPickerTrigger
    || elements.clientPickerButtons.find((button) => Number(button.dataset.liveChooseClient) === Number(state.clientPickerLane));
  closeDialog(elements.clientModal, trigger);
  state.clientPickerLane = null;
  state.clientPickerTrigger = null;
}

async function assignDispatchClient(clientId) {
  const lane = Number(state.clientPickerLane);
  const correctingPendingClient = state.pendingDispatchClientCorrection
    && Number(state.pendingCapture?.lane) === lane;
  if (state.busy || (state.pendingCapture && !correctingPendingClient)) return;
  const client = catalogItem("clients", clientId);
  if (![5, 6].includes(lane) || !client) return;

  try {
    const lockResult = await withDispatchDraftLock(lane, async () => {
      hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
      reconcileDirectClientSelections();
      const draft = dispatchDraft(lane);
      if (draft.registration_attempt) throw new Error("El ticket está pendiente de confirmación y no puede cambiar de cliente.");
      if (draft.weighings.length && !state.draftClientInvalid[lane] && !correctingPendingClient) {
        throw new Error("Otra pestaña agregó una pesada. El cliente ya quedó bloqueado.");
      }

      state.directClientIds[lane] = Number(client.id);
      state.directClientReselectionRequired[lane] = false;
      state.draftClientInvalid[lane] = false;
      if (!correctingPendingClient) {
        draft.dispatch_client_id = Number(client.id);
        draft.dispatch_client_name = String(client.name);
        if (!persistDispatchDrafts()) throw new Error("No se pudo guardar el cliente del borrador en esta tablet.");
        return;
      }

      state.pendingCapture = assignDispatchClientToPendingCapture(state.pendingCapture, client);
      state.pendingDispatchClientCorrection = false;
      persistPendingCapture(state.pendingCapture);
      migratePendingDispatchToDraft(state.pendingCapture, state.pendingCaptureStorageKey);
      clearPendingCapture();
    });
    if (!lockResult.acquired) {
      setMessage("Otra pestaña está modificando este ticket. Intenta elegir el cliente nuevamente.", "error");
      return;
    }
  } catch (error) {
    state.pendingUpgradeMessage = error.message;
    setMessage(error.message, "error");
    return;
  }
  renderLaneAssignments();
  closeClientPicker();
  selectLane(lane);
  setMessage(
    correctingPendingClient
      ? `${client.name} reemplazó al cliente anterior y la pesada congelada se recuperó dentro del borrador.`
      : `${client.name} quedó elegido en la columna ${lane}. Las próximas pesadas formarán un solo ticket para este cliente.`,
    "success",
  );
}

function renderReceptionRecord(record) {
  const ownerExternal = record.owner?.type === "EXTERNA";
  const sexLabel = record.sex === "HEMBRA" ? "H" : "M";
  const ownerLabel = ownerExternal ? record.owner?.name : "Mi empresa";
  const legacyDirect = String(record.record_kind || "").toLowerCase() === "legacy_direct_weighing";
  const editable = !legacyDirect;
  const previousLayout = record.uses_previous_layout
    ? '<small class="lir-record-legacy">Registro anterior a esta distribución</small>'
    : "";
  return `
    <article class="lir-weighing-row ${ownerExternal ? "is-external" : "is-own"} ${editable ? "is-clickable" : "is-readonly"}"
      ${editable ? `role="button" tabindex="0" data-live-edit-weighing="${record.id}"` : ""}>
      <header>
        <span>#${record.number} · ${formatTime(record.weighed_at)}</span>
        ${editable
          ? `<button type="button" data-live-delete-weighing="${record.id}" aria-label="Anular pesada ${record.number}">×</button>`
          : '<small class="lir-readonly-chip">Histórico</small>'}
      </header>
      <strong title="${escapeHtml(ownerLabel)}">${escapeHtml(ownerLabel)}</strong>
      ${previousLayout}
      <small class="lir-record-destination" title="${escapeHtml(record.destination?.name || "Sin destino")}">Destino: ${escapeHtml(record.destination?.name || "Sin destino")}</small>
      <div><span>${sexLabel}</span><span>${record.cages} J × ${record.birds_per_cage}</span><span>${record.birds} aves</span></div>
      <footer><small>Tara ${formatKg(record.tare_weight_kg)}</small><b>${formatKg(record.net_weight_kg)}</b></footer>
    </article>`;
}

function renderDispatchTicketRecord(record) {
  const ticketId = ticketRecordId(record);
  const ticket = record.ticket || {};
  const totals = record.totals || record.summary || record;
  const sex = normalizeReceptionSex(record.sex || record.chicken_sex);
  const code = record.ticket_code || ticket.code || `Ticket #${ticketId}`;
  const client = record.client || record.destination || ticket.destination || {};
  const status = record.ticket_status || ticket.status || "CERRADO";
  const weighings = Number(
    totals.weighing_count
      ?? record.weighing_count
      ?? (Array.isArray(totals.weighings) ? totals.weighings.length : totals.weighings)
      ?? 0,
  );
  const cages = Number(totals.cages ?? record.cages ?? 0);
  const birds = Number(totals.birds ?? record.birds ?? 0);
  const gross = Number(totals.gross_weight_kg ?? record.gross_weight_kg ?? 0);
  const tare = Number(totals.tare_weight_kg ?? record.tare_weight_kg ?? 0);
  const net = Number(totals.net_weight_kg ?? record.net_weight_kg ?? 0);
  return `
    <article class="lir-ticket-record is-clickable" role="button" tabindex="0" data-live-open-ticket="${ticketId}" aria-label="Abrir ${escapeHtml(code)} completo">
      <header><span class="lir-ticket-status">${escapeHtml(status === "CERRADO" ? "Despachado" : status)}</span><small>${sex === "HEMBRA" ? "Hembra" : "Macho"}</small></header>
      <strong>${escapeHtml(code)}</strong>
      <span class="lir-ticket-client" title="${escapeHtml(client.name || "Sin cliente")}">${escapeHtml(client.name || "Sin cliente")}</span>
      <div><span>${weighings} pesadas</span><span>${cages} javas</span><span>${birds} pollos</span></div>
      <footer><small>Bruto ${formatKg(gross)} · Tara ${formatKg(tare)}</small><b>${formatKg(net)}</b></footer>
    </article>`;
}

function renderDraftWeighing(weighing, lane, index) {
  const normalized = normalizeDraftWeighing(weighing, index);
  const registrationPending = dispatchDraftHasPendingRegistration(lane);
  return `
    <article class="lir-weighing-row lir-draft-weighing ${registrationPending ? "is-readonly" : "is-clickable"}"
      ${registrationPending ? "" : `role="button" tabindex="0" data-live-edit-draft-weighing="${escapeHtml(normalized.local_id)}"`} data-live-draft-lane="${lane}">
      <header><span>Pesada ${index + 1} · ${formatTime(normalized.weighed_at)}</span>${registrationPending
        ? '<small class="lir-readonly-chip">Confirmando</small>'
        : `<button type="button" data-live-delete-draft-weighing="${escapeHtml(normalized.local_id)}" data-live-draft-lane="${lane}" aria-label="Quitar pesada ${index + 1}">×</button>`}</header>
      <strong>${normalized.sex === "HEMBRA" ? "Hembra" : "Macho"} · ${escapeHtml(normalized.cage_type_name)}</strong>
      <div><span>${normalized.cage_count} javas</span><span>${normalized.birds_per_cage} aves/java</span><span>${normalized.birds} pollos</span></div>
      <footer><small>Tara ${formatKg(normalized.tare_weight_kg)}</small><b>${formatKg(normalized.net_weight_kg)}</b></footer>
    </article>`;
}

function renderRecords() {
  const records = state.data?.records || [];
  LANE_NUMBERS.forEach((lane) => {
    const laneRecords = records.filter((record) => receptionRecordLane(record) === lane);
    const recordMarkup = laneRecords.map((record) => (
      isDispatchTicketRecord(record) ? renderDispatchTicketRecord(record) : renderReceptionRecord(record)
    ));
    if ([5, 6].includes(lane)) {
      if (state.dispatchDraftsBlocked) {
        const expiredDraft = state.expiredDispatchDrafts?.[lane] || state.expiredDispatchDrafts?.[String(lane)] || {};
        const hasRegistrationAttempt = Boolean(expiredDraft.registration_attempt);
        recordMarkup.unshift(`
          <div class="lir-expired-draft" role="alert">
            <strong>Borrador de otra jornada</strong>
            <span>Hay pesadas pendientes del ${escapeHtml(state.expiredDraftOperatingDate)}. No se mezclarán con la jornada actual.</span>
            ${hasRegistrationAttempt
              ? `<button type="button" data-live-retry-expired-ticket="${lane}">Comprobar ticket antes de descartar</button>`
              : ""}
            <button type="button" data-live-discard-expired-drafts>${hasRegistrationAttempt ? "Descartar sin comprobar" : "Descartar borradores vencidos"}</button>
          </div>`);
      } else {
        recordMarkup.unshift(...dispatchDraft(lane).weighings.map((weighing, index) => renderDraftWeighing(weighing, lane, index)));
      }
    }
    elements.laneRows[lane - 1].innerHTML = recordMarkup.length
      ? recordMarkup.join("")
      : `<p class="lir-empty-lane">${[5, 6].includes(lane) ? "Agrega la primera pesada del ticket" : "Aún no hay pesadas"}</p>`;
  });
}

function renderTotals() {
  const daily = state.data?.totals?.daily || {};
  const own = state.data?.totals?.own || {};
  const external = state.data?.totals?.external || {};
  elements.dailyWeighings.textContent = String(daily.weighings || 0);
  elements.dailyCages.textContent = String(daily.cages || 0);
  elements.dailyBirds.textContent = String(daily.birds || 0);
  elements.dailyNet.textContent = formatKg(daily.net_weight_kg);
  elements.ownBirds.textContent = `${own.birds || 0} pollos · ${formatKg(own.net_weight_kg)}`;
  elements.externalBirds.textContent = `${external.birds || 0} pollos · ${formatKg(external.net_weight_kg)}`;

  LANE_NUMBERS.forEach((lane) => {
    const totals = [5, 6].includes(lane)
      ? calculateDraftTotals(dispatchDraft(lane).weighings)
      : (state.data?.totals?.lanes?.[String(lane)] || {});
    elements.laneCages[lane - 1].textContent = String(totals.cages || 0);
    elements.laneBirds[lane - 1].textContent = String(totals.birds || 0);
    elements.laneNet[lane - 1].textContent = formatKg(totals.net_weight_kg);
  });
  renderSelectedTotals();
}

function renderSelectedTotals() {
  const totals = [5, 6].includes(state.activeLane)
    ? calculateDraftTotals(dispatchDraft(state.activeLane).weighings)
    : (state.data?.totals?.lanes?.[String(state.activeLane)] || {});
  const profile = laneProfile(state.activeLane);
  const destination = laneDestination(state.activeLane);
  const action = profile.type === "ALMACEN" ? "Entrada" : "Borrador de ticket";
  const scope = profile.type === "ALMACEN"
    ? (destination?.name || "Sin configurar")
    : "Totales de toda la columna";
  elements.selectedLaneLabel.textContent = `${state.activeLane} · ${action} · ${scope}`;
  elements.selectedWeighings.textContent = String(totals.weighings || 0);
  elements.selectedCages.textContent = String(totals.cages || 0);
  elements.selectedBirds.textContent = String(totals.birds || 0);
  elements.selectedGross.textContent = formatKg(totals.gross_weight_kg);
  elements.selectedNet.textContent = formatKg(totals.net_weight_kg);
}

function renderData(data, { resetDirectClients = false } = {}) {
  const firstLoad = state.data === null;
  state.data = {
    ...data,
    records: Array.isArray(data?.records) ? data.records : (Array.isArray(data?.rows) ? data.rows : []),
    catalog: {
      ...(data?.catalog || {}),
      delivery_trucks: data?.catalog?.delivery_trucks || [],
      delivery_drivers: data?.catalog?.delivery_drivers || [],
    },
  };
  if (data?.company?.id && data?.branch?.id) {
    hydrateDispatchDrafts(data.company.id, data.branch.id);
  }
  reconcileDirectClientSelections(firstLoad || resetDirectClients);
  elements.operatingDate.textContent = formatOperatingDate(state.data.operating_date);
  populateConfiguration();
  renderRecords();
  renderTotals();
  updateCaptureAvailability();
}

function selectLane(laneNumber) {
  const requestedLane = Math.min(6, Math.max(1, Number(laneNumber) || 1));
  if (state.pendingCapture && requestedLane !== Number(state.pendingCapture.lane)) {
    setMessage(`Reintenta primero la pesada pendiente de la columna ${state.pendingCapture.lane}.`, "error");
    return;
  }
  state.activeLane = requestedLane;
  const profile = laneProfile(state.activeLane);
  const externalOwner = catalogItem("external_owners", state.data?.configuration?.default_external_owner_id);
  const ownerLabel = profile.ownerType === "EXTERNA"
    ? (externalOwner?.name || "Empresa externa sin configurar")
    : "Mi empresa";
  const resolvedSex = profile.sex || state.sex;
  const sexLabel = resolvedSex === "HEMBRA" ? "Hembra" : "Macho";
  const destination = laneDestination(state.activeLane);

  elements.lanes.forEach((lane) => lane.classList.toggle("is-active", Number(lane.dataset.liveLane) === state.activeLane));
  elements.laneButtons.forEach((button) => {
    const selected = Number(button.dataset.liveSelectLane) === state.activeLane;
    button.setAttribute("aria-pressed", String(selected));
  });
  elements.activeLaneNumber.textContent = String(state.activeLane);
  elements.capturePanel.classList.toggle("is-direct-lane", !profile.sex);
  elements.sexChoice.hidden = Boolean(profile.sex);
  elements.assignmentTitle.textContent = `${ownerLabel} · ${sexLabel}`;
  elements.assignmentHelp.textContent = profile.sex
    ? `La columna ${state.activeLane} define automáticamente el propietario y el sexo.`
    : (destination
        ? `Agrega pesadas al ticket de ${destination.name}; el cliente quedará bloqueado desde la primera.`
        : `Elige el cliente del ticket de despacho de la columna ${state.activeLane}.`);
  if (state.data && [5, 6].includes(state.activeLane) && state.dispatchDraftsBlocked) {
    setMessage(`Hay borradores pendientes de la jornada ${state.expiredDraftOperatingDate}. Descártalos desde las columnas de ticket antes de continuar.`, "error");
  } else if (state.data && [5, 6].includes(state.activeLane) && state.draftClientInvalid[state.activeLane]) {
    setMessage(`El cliente guardado en la columna ${state.activeLane} ya no está disponible. Pulsa Corregir cliente sin perder las pesadas del borrador.`, "error");
  } else if (state.data && profile.ownerType === "EXTERNA" && !externalOwner) {
    setMessage("Configura la empresa externa antes de registrar en las columnas 3 y 4.", "error");
  } else if (state.data && !destination) {
    setMessage(
      profile.type === "CLIENTE"
        ? `Elige el cliente del ticket de la columna ${state.activeLane} antes de agregar pesadas.`
        : `Configura el destino de la columna ${state.activeLane} antes de registrar.`,
      "error",
    );
  } else if (state.data) {
    setMessage([5, 6].includes(state.activeLane)
      ? `Borrador ${state.activeLane} listo para agregar la siguiente pesada.`
      : `Columna ${state.activeLane} lista para la siguiente pesada.`);
  }
  renderSelectedTotals();
  updateCaptureAvailability();
}

function selectSex(sex) {
  if (state.pendingCapture && sex !== state.pendingCapture.sex) {
    setMessage(`Reintenta primero la pesada pendiente de la columna ${state.pendingCapture.lane}.`, "error");
    return;
  }
  state.sex = sex;
  elements.sexButtons.forEach((button) => {
    const selected = button.dataset.liveSex === sex;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
  if (!laneProfile(state.activeLane).sex) {
    elements.assignmentTitle.textContent = `Mi empresa · ${sex === "HEMBRA" ? "Hembra" : "Macho"}`;
  }
}

function updateScaleUi(payload = state.scale?.getState()) {
  const scaleState = payload?.state || payload;
  if (!scaleState) return;
  const captureLocked = state.busy || Boolean(state.pendingCapture);
  const displayedWeight = state.pendingCapture?.read_weight_kg ?? scaleState.currentWeightKg;
  const hasWeight = displayedWeight !== null
    && displayedWeight !== undefined
    && displayedWeight !== ""
    && Number.isFinite(Number(displayedWeight));
  const weight = hasWeight ? Number(displayedWeight) : null;
  elements.scaleWeight.innerHTML = hasWeight
    ? `${weight.toFixed(3)} <small>kg</small>`
    : '--- <small>kg</small>';
  if (state.pendingCapture) {
    elements.scaleRaw.textContent = `Peso congelado para reintento · ${state.pendingCapture.weight_source === "MANUAL" ? "Manual" : "Balanza"}`;
  } else {
    elements.scaleRaw.textContent = `Trama: ${scaleState.readingRaw || scaleState.lastRaw || "--"}`;
  }
  elements.scaleStatus.className = `lir-status-chip ${scaleState.isConnected ? "is-connected" : "is-offline"}`;
  elements.scaleStatus.innerHTML = `<i></i> ${escapeHtml(scaleState.statusMessage || "Sin conexión")}`;
  elements.connectBle.disabled = captureLocked || scaleState.isConnecting || !scaleState.capabilities.bluetooth;
  elements.connectSerial.disabled = captureLocked || scaleState.isConnecting || !scaleState.capabilities.serial;
  elements.disconnectScale.disabled = captureLocked || (!scaleState.isConnected && !scaleState.isConnecting);
  elements.openScaleSettings.disabled = captureLocked;
  elements.openManualWeight.disabled = captureLocked;
  elements.openSettings.disabled = captureLocked;
  updateCaptureAvailability();
}

function updateCaptureAvailability() {
  const scaleState = state.scale?.getState();
  const pendingCapture = state.pendingCapture;
  const controlsLocked = state.busy || Boolean(pendingCapture);
  const profile = laneProfile(state.activeLane);
  const quantityOk = Number(elements.birdsPerCage.value) > 0 && Number(elements.cageCount.value) > 0;
  const configuredLane = Boolean(laneDestination(state.activeLane));
  const ownerOk = profile.ownerType !== "EXTERNA" || Boolean(state.data?.configuration?.default_external_owner_id);
  elements.laneButtons.forEach((button) => { button.disabled = controlsLocked; });
  elements.clientPickerButtons.forEach((button) => {
    const correctingThisLane = state.pendingDispatchClientCorrection
      && Number(state.pendingCapture?.lane) === Number(button.dataset.liveChooseClient);
    const lane = Number(button.dataset.liveChooseClient);
    button.disabled = state.busy
      || !state.data
      || state.dispatchDraftsBlocked
      || dispatchDraftHasPendingRegistration(lane)
      || (dispatchDraft(lane).weighings.length > 0 && !state.draftClientInvalid[lane])
      || (Boolean(pendingCapture) && !correctingThisLane);
  });
  elements.registerTicketButtons.forEach((button) => {
    const lane = Number(button.dataset.liveRegisterTicket);
    const draft = dispatchDraft(lane);
    button.disabled = state.busy
      || Boolean(pendingCapture)
      || state.dispatchDraftsBlocked
      || !draft.dispatch_client_id
      || (state.draftClientInvalid[lane] && !draft.registration_attempt)
      || draft.weighings.length === 0;
    button.textContent = state.busy && Number(state.deliverySelection?.lane) === lane
      ? "Registrando…"
      : (draft.registration_attempt ? "Reintentar ticket" : "Registrar ticket");
  });
  elements.sexButtons.forEach((button) => { button.disabled = controlsLocked; });
  elements.birdsPerCage.disabled = controlsLocked;
  elements.cageCount.disabled = controlsLocked;
  elements.cageType.disabled = controlsLocked;
  elements.captureLabel.textContent = state.busy && pendingCapture
    ? "Guardando en columna"
    : (pendingCapture
        ? "Reintentar en columna"
        : ([5, 6].includes(state.activeLane) ? "Agregar al ticket" : "Guardar en columna"));
  elements.activeLaneNumber.textContent = String(pendingCapture?.lane || state.activeLane);
  elements.capture.disabled = pendingCapture
    ? state.busy || !state.data || state.pendingDispatchClientCorrection
    : state.busy
      || !state.data
      || !scaleState?.isCaptureReady
      || !elements.cageType.value
      || !quantityOk
      || !configuredLane
      || !ownerOk
      || ([5, 6].includes(state.activeLane) && state.dispatchDraftsBlocked)
      || ([5, 6].includes(state.activeLane) && state.draftClientInvalid[state.activeLane])
      || ([5, 6].includes(state.activeLane) && dispatchDraftHasPendingRegistration(state.activeLane))
      || state.pendingUpgradeBlocked;
}

function scalePayload(scaleState) {
  const physical = ["ble", "serial"].includes(scaleState.readingSource);
  return {
    weight_source: physical ? "BALANZA_RECEPCION_POLLO_VIVO" : "MANUAL",
    read_weight_kg: Number(scaleState.currentWeightKg),
    ...(physical ? {
      scale_reading: {
        raw_frame: scaleState.readingRaw || scaleState.lastRaw || null,
        connection_mode: String(scaleState.readingSource).toUpperCase(),
        device_name: scaleState.deviceName || null,
        captured_at: scaleState.readingAt || new Date().toISOString(),
      },
    } : {}),
  };
}

function capturePayload() {
  if (state.pendingCapture) return state.pendingCapture;

  const scaleState = state.scale.getState();
  const profile = laneProfile(state.activeLane);
  const dispatchClient = profile.type === "CLIENTE" ? laneDestination(state.activeLane) : null;
  return {
    layout_version: LAYOUT_VERSION,
    idempotency_key: createUuid(),
    lane: state.activeLane,
    ...(profile.sex ? {} : { sex: state.sex }),
    ...(dispatchClient ? {
      dispatch_client_id: Number(dispatchClient.id),
      dispatch_client_name: String(dispatchClient.name),
    } : {}),
    cage_type_id: Number(elements.cageType.value),
    birds_per_cage: Number(elements.birdsPerCage.value),
    cage_count: Number(elements.cageCount.value),
    weighed_at: new Date().toISOString(),
    ...scalePayload(scaleState),
  };
}

function captureRequestPayload(payload) {
  const requestPayload = { ...payload };
  delete requestPayload.dispatch_client_name;
  delete requestPayload.requires_dispatch_client_reselection;
  return requestPayload;
}

function freezePendingCaptureForClientCorrection(payload) {
  state.pendingCapture = freezeDispatchClientCorrection(payload);
  state.pendingDispatchClientCorrection = true;
  persistPendingCapture(state.pendingCapture);
}

async function refreshAfterInvalidDispatchClient(lane, clientId, validationMessage) {
  state.directClientReselectionRequired[lane] = true;
  state.directClientIds[lane] = null;
  if (state.data?.catalog?.clients) {
    state.data.catalog.clients = state.data.catalog.clients.filter(
      (client) => Number(client.id) !== Number(clientId),
    );
  }
  renderLaneAssignments();
  selectLane(lane);

  try {
    const response = await apiRequest("/recepcion-pollo-vivo");
    renderData(response.data);
    selectLane(lane);
    setMessage(`${validationMessage} Elige otro cliente para volver a registrar esta misma lectura.`, "error");
  } catch {
    setMessage(
      `${validationMessage} Se quitó ese cliente de la columna, pero no se pudo actualizar la lista. Recarga la vista antes de elegir otro.`,
      "error",
    );
  }
}

function addCaptureToDispatchDraft(payload) {
  const lane = Number(payload.lane);
  const draft = dispatchDraft(lane);
  const client = laneDestination(lane);
  const cageType = catalogItem("cage_types", payload.cage_type_id);
  const cages = Number(payload.cage_count);
  const cageWeight = Number(cageType?.weight_kg ?? cageType?.peso_kg ?? 0);
  const gross = Number(payload.read_weight_kg);
  const tare = Math.round(cageWeight * cages * 1000) / 1000;
  const net = Math.round((gross - tare) * 1000) / 1000;
  if (!client) throw new Error(`Elige el cliente del ticket de la columna ${lane}.`);
  if (draft.registration_attempt) throw new Error("Este ticket está pendiente de confirmación. Reintenta el registro antes de agregar otra pesada.");
  if (state.draftClientInvalid[lane] || client.unavailable) throw new Error("Corrige el cliente no disponible antes de agregar otra pesada.");
  if (Number(payload.dispatch_client_id) !== Number(client.id)) throw new Error("El cliente cambió en otra pestaña. Revisa el borrador y vuelve a capturar esta misma lectura.");
  if (!cageType || cageType.active === false) throw new Error("Selecciona un tipo de java activo.");
  if (net <= 0) throw new Error("El peso leído debe ser mayor que la tara total de las javas.");

  draft.dispatch_client_id = Number(client.id);
  draft.dispatch_client_name = String(client.name);
  draft.weighings.push(normalizeDraftWeighing({
    ...payload,
    local_id: payload.idempotency_key,
    idempotency_key: payload.idempotency_key,
    cage_type_name: cageType.name,
    cage_weight_kg: cageWeight,
    gross_weight_kg: gross,
    tare_weight_kg: tare,
    net_weight_kg: net,
    birds: Number(payload.birds_per_cage) * cages,
  }, draft.weighings.length));
  if (!persistDispatchDrafts()) {
    draft.weighings.pop();
    throw new Error("No se pudo guardar el borrador en esta tablet. Libera espacio del navegador e inténtalo nuevamente.");
  }
  state.scale.clearReading();
  elements.cageCount.value = "1";
  renderLaneAssignments();
  renderRecords();
  renderTotals();
  updateScaleUi();
  setMessage(`Pesada agregada al ticket de ${client.name}. El cliente quedó bloqueado hasta registrar o vaciar el borrador.`, "success");
}

async function performCaptureWeighing() {
  const payload = capturePayload();
  if ([5, 6].includes(Number(payload.lane)) && !state.pendingCapture) {
    try {
      const lockResult = await withDispatchDraftLock(payload.lane, async () => {
        hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
        reconcileDirectClientSelections();
        addCaptureToDispatchDraft(payload);
      });
      if (!lockResult.acquired) {
        setMessage("Otra pestaña está modificando este ticket. La lectura sigue disponible; inténtalo nuevamente.", "error");
      }
    } catch (error) {
      setMessage(error.message || "No se pudo agregar la pesada al ticket.", "error");
    }
    return;
  }
  state.pendingCapture = payload;
  persistPendingCapture(payload);
  state.busy = true;
  updateScaleUi();
  setMessage(payload.dispatch_client_name
    ? `Recibiendo y despachando a ${payload.dispatch_client_name} en la columna ${payload.lane}…`
    : `Guardando la pesada en la columna ${payload.lane}…`);

  try {
    const response = await apiRequest("/recepcion-pollo-vivo/pesadas", {
      method: "POST",
      body: JSON.stringify(captureRequestPayload(payload)),
    });
    const confirmedRecord = response.data?.records?.find(
      (record) => Number(record.id) === Number(response.weighing_id),
    );
    const confirmedClientName = payload.dispatch_client_id
      ? (confirmedRecord?.destination?.name || payload.dispatch_client_name)
      : null;
    renderData(response.data);
    clearPendingCapture();
    state.scale.clearReading();
    elements.cageCount.value = "1";
    setMessage(confirmedClientName
      ? `Recepción para mi empresa y despacho a ${confirmedClientName} registrados al mismo tiempo.`
      : (response.message || "Pesada registrada correctamente."), "success");
  } catch (error) {
    const status = Number(error?.status);
    const deterministicClientError = status >= 400 && status < 500 && status !== 408;
    if (deterministicClientError) {
      const invalidDispatchClient = [5, 6].includes(Number(payload.lane))
        && hasValidationError(error, "dispatch_client_id");
      if (invalidDispatchClient) {
        freezePendingCaptureForClientCorrection(payload);
        await refreshAfterInvalidDispatchClient(
          Number(payload.lane),
          Number(payload.dispatch_client_id),
          firstValidationMessage(error),
        );
      } else {
        clearPendingCapture();
        setMessage(firstValidationMessage(error), "error");
      }
    } else {
      setMessage(
        `${firstValidationMessage(error)} La pesada quedó pendiente: reintenta sin cambiar sus datos.`,
        "error",
      );
    }
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

async function captureWeighing() {
  if (elements.capture.disabled) return;

  if (!state.pendingCapture && state.data) {
    const restoredPendingCapture = await restorePendingCapture(
      state.data.company.id,
      state.data.branch.id,
    );
    if (restoredPendingCapture) {
      updateScaleUi();
      setMessage(
        `Otra pestaña dejó una pesada pendiente en la columna ${state.pendingCapture.lane}. Revisa los datos congelados y pulsa Reintentar.`,
        "error",
      );
      return;
    }
    if (state.pendingUpgradeBlocked) {
      updateScaleUi();
      setMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
      return;
    }
  }

  if (!navigator.locks?.request
    || !state.pendingCaptureStorageKey
    || !state.legacyPendingCaptureStorageKey) {
    await performCaptureWeighing();
    return;
  }

  await navigator.locks.request(
    `${state.legacyPendingCaptureStorageKey}-request-lock`,
    { mode: "exclusive", ifAvailable: true },
    async (legacyLock) => {
      if (!legacyLock) {
        setMessage("Otra pestaña está guardando una pesada. Espera su confirmación antes de continuar.", "error");
        return;
      }
      await navigator.locks.request(
        `${state.pendingCaptureStorageKey}-request-lock`,
        { mode: "exclusive", ifAvailable: true },
        async (currentLock) => {
          if (!currentLock) {
            setMessage("Otra pestaña está guardando una pesada. Espera su confirmación antes de continuar.", "error");
            return;
          }
          await performCaptureWeighing();
        },
      );
    },
  );
}

async function deleteWeighing(id) {
  const record = state.data?.records?.find((item) => !isDispatchTicketRecord(item) && Number(item.id) === Number(id));
  if (!record) return;
  const confirmed = window.confirm(
    `¿Anular la pesada #${record.number} de ${record.birds} aves y ${formatKg(record.net_weight_kg)}?`,
  );
  if (!confirmed) return;

  state.busy = true;
  updateScaleUi();
  setMessage("Anulando la pesada y corrigiendo los totales…");
  try {
    const response = await apiRequest(`/recepcion-pollo-vivo/pesadas/${record.id}`, { method: "DELETE" });
    renderData(response.data);
    setMessage(response.message || "Pesada anulada.", "success");
  } catch (error) {
    setMessage(firstValidationMessage(error), "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

function toDateTimeLocal(value) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return "";
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 19);
}

function editorCageTypes() {
  return state.ticketEditorCatalog?.cage_types || state.data?.catalog?.cage_types || [];
}

function cageTypeOptionMarkup(selectedId, currentCageType = null) {
  const items = editorCageTypes().filter((item) => item.active !== false || Number(item.id) === Number(selectedId));
  if (selectedId && !items.some((item) => Number(item.id) === Number(selectedId))) {
    items.unshift({
      id: Number(selectedId),
      name: `${currentCageType?.name || `Java #${selectedId}`} · inactivo, conservar`,
      weight_kg: currentCageType?.weight_kg,
    });
  }
  return items.map((item) => `
    <option value="${item.id}" ${Number(item.id) === Number(selectedId) ? "selected" : ""}>${escapeHtml(
      item.active === false ? `${item.name} · inactivo, conservar` : item.name,
    )}</option>
  `).join("");
}

function historicalCageWeight(record = {}) {
  const directWeight = Number(
    record.cage_weight_kg
      ?? record.cage_type?.weight_kg
      ?? record.java_type?.weight_kg,
  );
  if (Number.isFinite(directWeight) && directWeight > 0) return directWeight;

  const cages = Number(record.cage_count ?? record.cages);
  const tare = Number(record.tare_weight_kg ?? record.tare_kg);
  if (Number.isFinite(tare) && Number.isFinite(cages) && cages > 0) return tare / cages;
  return directWeight === 0 ? 0 : null;
}

function editedCageType(record, selectedId) {
  const normalizedRecord = normalizeDraftWeighing(record || {});
  const id = Number(selectedId);
  if (Number(normalizedRecord.cage_type_id) === id) {
    const snapshotWeight = historicalCageWeight(record || normalizedRecord);
    const catalogCageType = editorCageTypes().find((item) => Number(item.id) === id);
    return {
      ...(catalogCageType || {}),
      id,
      name: normalizedRecord.cage_type_name || catalogCageType?.name || `Java #${id}`,
      weight_kg: snapshotWeight ?? catalogCageType?.weight_kg ?? catalogCageType?.peso_kg,
    };
  }

  return editorCageTypes().find((item) => Number(item.id) === id && item.active !== false)
    || null;
}

function setWeighingEditorMessage(message, tone = "") {
  elements.weighingEditorMessage.textContent = message || "";
  elements.weighingEditorMessage.classList.toggle("is-error", tone === "error");
  elements.weighingEditorMessage.classList.toggle("is-success", tone === "success");
}

function openWeighingEditor(record, context = {}) {
  const normalized = normalizeDraftWeighing(record);
  state.editingRecord = {
    kind: context.kind || "weighing",
    lane: Number(context.lane || record.lane),
    localId: context.localId || normalized.local_id,
    record,
    originalFingerprint: context.kind === "draft" ? dispatchDraftWeighingFingerprint(record) : null,
    trigger: context.trigger || elements.capture,
  };
  elements.weighingEditorCaption.textContent = context.kind === "draft" ? "Borrador de ticket" : "Pesada de recepción";
  elements.weighingEditorTitle.textContent = context.kind === "draft"
    ? `Editar pesada del ticket ${context.lane}`
    : `Editar pesada #${record.number || record.id}`;
  elements.editCageType.innerHTML = cageTypeOptionMarkup(normalized.cage_type_id, record.cage_type || {
    name: normalized.cage_type_name,
    weight_kg: record.cage_weight_kg ?? record.cage_type?.weight_kg,
  });
  elements.editSex.value = normalized.sex;
  elements.editBirdsPerCage.value = String(normalized.birds_per_cage);
  elements.editCageCount.value = String(normalized.cage_count);
  elements.editWeight.value = Number(normalized.read_weight_kg || normalized.gross_weight_kg).toFixed(3);
  elements.editWeighedAt.value = toDateTimeLocal(normalized.weighed_at);
  elements.editReason.value = "";
  elements.editReason.required = context.kind !== "draft";
  elements.editReason.closest("label").hidden = context.kind === "draft";
  setWeighingEditorMessage(context.kind === "draft"
    ? "Los cambios se guardarán únicamente en este borrador."
    : "El peso corregido se registrará como manual y quedará auditado.");
  openDialog(elements.weighingEditorModal, context.trigger || elements.capture, elements.editBirdsPerCage);
}

function closeWeighingEditor() {
  if (state.busy) return;
  const trigger = state.editingRecord?.trigger || elements.capture;
  state.editingRecord = null;
  closeDialog(elements.weighingEditorModal, trigger);
}

function editedWeighingValues() {
  const original = state.editingRecord?.record || {};
  const cageType = editedCageType(original, elements.editCageType.value);
  const cages = Number(elements.editCageCount.value);
  const birdsPerCage = Number(elements.editBirdsPerCage.value);
  const readWeight = Number(elements.editWeight.value);
  const tare = Math.round(Number(cageType?.weight_kg ?? cageType?.peso_kg ?? 0) * cages * 1000) / 1000;
  const net = Math.round((readWeight - tare) * 1000) / 1000;
  if (!cageType) throw new Error("Selecciona un tipo de java válido.");
  if (!Number.isInteger(cages) || cages < 1) throw new Error("Ingresa una cantidad válida de javas.");
  if (!Number.isInteger(birdsPerCage) || birdsPerCage < 1) throw new Error("Ingresa una cantidad válida de aves por java.");
  if (!Number.isFinite(readWeight) || readWeight <= tare) throw new Error("El peso leído debe ser mayor que la tara total.");
  return normalizeDraftWeighing({
    sex: elements.editSex.value,
    cage_type_id: Number(cageType.id),
    cage_type_name: cageType.name,
    birds_per_cage: birdsPerCage,
    cage_count: cages,
    birds: birdsPerCage * cages,
    read_weight_kg: readWeight,
    gross_weight_kg: readWeight,
    tare_weight_kg: tare,
    net_weight_kg: net,
    cage_weight_kg: Number(cageType.weight_kg ?? cageType.peso_kg ?? 0),
    weight_source: Math.abs(readWeight - Number(original.read_weight_kg ?? original.gross_weight_kg)) > 0.0005
      ? "MANUAL"
      : String(original.weight_source || "MANUAL"),
    scale_reading: Math.abs(readWeight - Number(original.read_weight_kg ?? original.gross_weight_kg)) > 0.0005
      ? null
      : original.scale_reading,
    weighed_at: elements.editWeighedAt.value,
  });
}

async function saveWeighingEditor(event) {
  event.preventDefault();
  if (!state.editingRecord || state.busy) return;
  let values;
  try {
    values = editedWeighingValues();
  } catch (error) {
    setWeighingEditorMessage(error.message, "error");
    return;
  }
  const originalWeighedAt = state.editingRecord.record?.weighed_at;
  if (originalWeighedAt && elements.editWeighedAt.value === toDateTimeLocal(originalWeighedAt)) {
    values.weighed_at = originalWeighedAt;
  }

  if (state.editingRecord.kind === "draft") {
    const editingContext = { ...state.editingRecord };
    try {
      const lockResult = await withDispatchDraftLock(editingContext.lane, async () => {
        hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
        reconcileDirectClientSelections();
        const draft = dispatchDraft(editingContext.lane);
        if (draft.registration_attempt) throw new Error("El ticket está pendiente de confirmación y no admite cambios.");
        const index = draft.weighings.findIndex((item) => String(item.local_id) === String(editingContext.localId));
        if (index < 0) throw new Error("La pesada ya no existe en el borrador.");
        if (dispatchDraftWeighingFingerprint(draft.weighings[index]) !== editingContext.originalFingerprint) {
          throw new Error("La pesada cambió en otra pestaña. Vuelve a abrirla para editar la versión actual.");
        }
        draft.weighings[index] = normalizeDraftWeighing({
          ...draft.weighings[index],
          ...values,
          local_id: draft.weighings[index].local_id,
          idempotency_key: draft.weighings[index].idempotency_key || draft.weighings[index].local_id,
        }, index);
        if (!persistDispatchDrafts()) throw new Error("No se pudo guardar el cambio en esta tablet.");
      });
      if (!lockResult.acquired) {
        setWeighingEditorMessage("Otra pestaña está modificando este ticket. Inténtalo nuevamente.", "error");
        return;
      }
    } catch (error) {
      setWeighingEditorMessage(error.message, "error");
      return;
    }
    state.editingRecord = null;
    elements.weighingEditorModal.hidden = true;
    syncModalEnvironment();
    renderRecords();
    renderTotals();
    renderLaneAssignments();
    updateCaptureAvailability();
    setMessage("Pesada del borrador actualizada.", "success");
    return;
  }

  const reason = elements.editReason.value.trim();
  if (reason.length < 3) {
    setWeighingEditorMessage("Escribe un motivo de corrección de al menos 3 caracteres.", "error");
    return;
  }
  state.busy = true;
  updateScaleUi();
  setWeighingEditorMessage("Guardando la corrección…");
  const record = state.editingRecord.record;
  const weightChanged = Math.abs(Number(values.read_weight_kg) - Number(record.read_weight_kg ?? record.gross_weight_kg)) > 0.0005;
  try {
    const updatePayload = {
      layout_version: 4,
      expected_updated_at: record.updated_at,
      correction_reason: reason,
      sex: values.sex,
      cage_type_id: values.cage_type_id,
      birds_per_cage: values.birds_per_cage,
      cage_count: values.cage_count,
      read_weight_kg: values.read_weight_kg,
      weighed_at: values.weighed_at,
      ...(weightChanged ? { weight_source: "MANUAL" } : {}),
    };
    const response = await apiRequest(`/recepcion-pollo-vivo/pesadas/${record.id}`, {
      method: "PUT",
      body: JSON.stringify(updatePayload),
    });
    renderData(response.data);
    state.editingRecord = null;
    elements.weighingEditorModal.hidden = true;
    syncModalEnvironment();
    setMessage(response.message || "Pesada actualizada correctamente.", "success");
  } catch (error) {
    setWeighingEditorMessage(firstValidationMessage(error), "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

async function deleteDraftWeighing(lane, localId) {
  const laneNumber = Number(lane);
  const draft = dispatchDraft(laneNumber);
  const weighing = draft.weighings.find((item) => String(item.local_id) === String(localId));
  if (!weighing) return;
  if (draft.registration_attempt) {
    setMessage("El ticket está pendiente de confirmación y no admite cambios.", "error");
    return;
  }
  if (!window.confirm(`¿Quitar esta pesada de ${weighing.birds} pollos del borrador?`)) return;
  const originalFingerprint = dispatchDraftWeighingFingerprint(weighing);
  let remainingCount = draft.weighings.length;
  try {
    const lockResult = await withDispatchDraftLock(laneNumber, async () => {
      hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
      reconcileDirectClientSelections();
      const currentDraft = dispatchDraft(laneNumber);
      if (currentDraft.registration_attempt) throw new Error("El ticket está pendiente de confirmación y no admite cambios.");
      const current = currentDraft.weighings.find((item) => String(item.local_id) === String(localId));
      if (!current) throw new Error("La pesada ya fue retirada en otra pestaña.");
      if (dispatchDraftWeighingFingerprint(current) !== originalFingerprint) {
        throw new Error("La pesada cambió en otra pestaña. Revísala antes de eliminarla.");
      }
      currentDraft.weighings = currentDraft.weighings.filter((item) => String(item.local_id) !== String(localId));
      if (!currentDraft.weighings.length) {
        currentDraft.delivery_vehicle_id = null;
        currentDraft.delivery_driver_id = null;
      }
      if (!persistDispatchDrafts()) throw new Error("No se pudo actualizar el borrador en esta tablet.");
      remainingCount = currentDraft.weighings.length;
    });
    if (!lockResult.acquired) {
      setMessage("Otra pestaña está modificando este ticket. Intenta quitar la pesada nuevamente.", "error");
      return;
    }
  } catch (error) {
    setMessage(error.message, "error");
    return;
  }
  renderRecords();
  renderTotals();
  renderLaneAssignments();
  updateCaptureAvailability();
  setMessage(remainingCount
    ? "Pesada retirada del borrador."
    : "Borrador vacío; ya puedes cambiar el cliente.", "success");
}

function setTicketEditorMessage(message, tone = "") {
  elements.ticketEditorMessage.textContent = message || "";
  elements.ticketEditorMessage.classList.toggle("is-error", tone === "error");
  elements.ticketEditorMessage.classList.toggle("is-success", tone === "success");
}

function renderTicketEditor() {
  const ticket = state.editingTicket;
  if (!ticket) return;
  const totals = calculateDraftTotals(ticket.weighings);
  elements.ticketEditorTitle.textContent = ticket.code;
  elements.ticketEditorClient.textContent = `${ticket.client.name} · Cliente conservado`;
  elements.ticketEditorSummary.innerHTML = `
    <span><small>Pesadas</small><strong>${totals.weighings}</strong></span>
    <span><small>Javas</small><strong>${totals.cages}</strong></span>
    <span><small>Pollos</small><strong>${totals.birds}</strong></span>
    <span><small>Bruto</small><strong>${formatKg(totals.gross_weight_kg)}</strong></span>
    <span><small>Tara</small><strong>${formatKg(totals.tare_weight_kg)}</strong></span>
    <span class="is-net"><small>Neto</small><strong>${formatKg(totals.net_weight_kg)}</strong></span>`;
  elements.ticketEditorRows.innerHTML = ticket.weighings.length
    ? ticket.weighings.map((weighing, index) => `
      <fieldset class="lir-ticket-weighing-editor" data-live-ticket-weighing="${weighing.id}">
        <legend><span>Pesada ${weighing.number || index + 1}</span><b>${weighing.sex === "HEMBRA" ? "Hembra" : "Macho"}</b></legend>
        <label><span>Sexo</span><select data-ticket-field="sex"><option value="MACHO" ${weighing.sex === "MACHO" ? "selected" : ""}>Macho</option><option value="HEMBRA" ${weighing.sex === "HEMBRA" ? "selected" : ""}>Hembra</option></select></label>
        <label><span>Tipo de java</span><select data-ticket-field="cage_type_id">${cageTypeOptionMarkup(weighing.cage_type_id, weighing.cage_type)}</select></label>
        <label><span>Aves/java</span><input data-ticket-field="birds_per_cage" type="number" min="1" max="1000" step="1" value="${weighing.birds_per_cage}"></label>
        <label><span>Javas</span><input data-ticket-field="cage_count" type="number" min="1" max="10000" step="1" value="${weighing.cage_count}"></label>
        <label><span>Peso leído (kg)</span><input data-ticket-field="read_weight_kg" type="number" min="0.001" step="0.001" value="${Number(weighing.read_weight_kg || weighing.gross_weight_kg).toFixed(3)}"></label>
        <label><span>Fecha y hora</span><input data-ticket-field="weighed_at" type="datetime-local" step="1" value="${toDateTimeLocal(weighing.weighed_at)}"></label>
      </fieldset>`).join("")
    : '<p class="lir-client-empty">Este ticket no tiene pesadas editables.</p>';
  elements.ticketEditReason.value = "";
  elements.printTicket.disabled = ticket.weighings.length === 0;
  elements.saveTicket.disabled = ticket.weighings.length === 0 || ticket.editable === false;
  setTicketEditorMessage(ticket.editable === false
    ? (ticket.edit_restriction || "Este ticket está disponible únicamente para consulta.")
    : "Al guardar, todas las pesadas se validarán y actualizarán en una sola operación.", ticket.editable === false ? "error" : "");
}

async function openTicketEditor(ticketId, trigger) {
  const id = Number(ticketId);
  if (!Number.isInteger(id) || id < 1 || state.busy) return;
  state.ticketEditorTrigger = trigger || elements.capture;
  state.editingTicket = null;
  elements.ticketEditorTitle.textContent = `Ticket #${id}`;
  elements.ticketEditorClient.textContent = "Cargando información completa…";
  elements.ticketEditorSummary.innerHTML = "";
  elements.ticketEditorRows.innerHTML = '<p class="lir-client-empty">Cargando pesadas…</p>';
  elements.printTicket.disabled = true;
  elements.saveTicket.disabled = true;
  setTicketEditorMessage("");
  openDialog(elements.ticketEditorModal, state.ticketEditorTrigger, elements.ticketEditorModal.querySelector("[data-live-close-ticket-editor]"));
  try {
    const response = await apiRequest(`/recepcion-pollo-vivo/tickets/${id}`);
    state.ticketEditorCatalog = response.data?.catalog || response.catalog || {};
    state.editingTicket = normalizeFullTicket(response.data?.ticket || response.ticket || response.data);
    renderTicketEditor();
  } catch (error) {
    elements.ticketEditorRows.innerHTML = '<p class="lir-client-empty">No se pudo cargar el ticket.</p>';
    setTicketEditorMessage(firstValidationMessage(error), "error");
  }
}

function closeTicketEditor() {
  if (state.busy) return;
  const trigger = state.ticketEditorTrigger || elements.capture;
  state.editingTicket = null;
  state.ticketEditorCatalog = null;
  state.ticketEditorTrigger = null;
  closeDialog(elements.ticketEditorModal, trigger);
}

function ticketEditorWeighings() {
  return Array.from(elements.ticketEditorRows.querySelectorAll("[data-live-ticket-weighing]")).map((row) => {
    const original = state.editingTicket.weighings.find((item) => Number(item.id) === Number(row.dataset.liveTicketWeighing));
    if (!original) throw new Error("Una pesada del ticket ya no está disponible. Vuelve a abrir el ticket.");
    const cageTypeId = Number(row.querySelector('[data-ticket-field="cage_type_id"]').value);
    const cageType = editedCageType(original, cageTypeId);
    if (!cageType) throw new Error("Selecciona un tipo de java activo o conserva el tipo histórico de la pesada.");
    const birdsPerCage = Number(row.querySelector('[data-ticket-field="birds_per_cage"]').value);
    const cageCount = Number(row.querySelector('[data-ticket-field="cage_count"]').value);
    const readWeight = Number(row.querySelector('[data-ticket-field="read_weight_kg"]').value);
    const tare = Math.round(Number(cageType.weight_kg ?? cageType.peso_kg ?? 0) * cageCount * 1000) / 1000;
    if (!Number.isInteger(birdsPerCage) || birdsPerCage < 1) throw new Error("Revisa la cantidad de aves por java en todas las pesadas.");
    if (!Number.isInteger(cageCount) || cageCount < 1) throw new Error("Revisa la cantidad de javas en todas las pesadas.");
    if (!Number.isFinite(readWeight) || readWeight <= tare) throw new Error("Cada peso leído debe ser mayor que la tara histórica o seleccionada.");
    const weightChanged = Math.abs(readWeight - Number(original.read_weight_kg ?? original.gross_weight_kg)) > 0.0005;
    return normalizeDraftWeighing({
      ...original,
      id: Number(row.dataset.liveTicketWeighing),
      sex: row.querySelector('[data-ticket-field="sex"]').value,
      cage_type_id: cageTypeId,
      cage_type_name: cageType.name,
      cage_weight_kg: Number(cageType.weight_kg ?? cageType.peso_kg ?? 0),
      birds_per_cage: birdsPerCage,
      cage_count: cageCount,
      cages: cageCount,
      birds: birdsPerCage * cageCount,
      read_weight_kg: readWeight,
      gross_weight_kg: readWeight,
      tare_weight_kg: tare,
      net_weight_kg: Math.round((readWeight - tare) * 1000) / 1000,
      weighed_at: row.querySelector('[data-ticket-field="weighed_at"]').value === toDateTimeLocal(original.weighed_at)
        ? original.weighed_at
        : row.querySelector('[data-ticket-field="weighed_at"]').value,
      weight_source: weightChanged ? "MANUAL" : original.weight_source,
      preserve_weight_source: !weightChanged,
    });
  });
}

async function saveTicketEditor(event) {
  event.preventDefault();
  if (!state.editingTicket || state.busy || elements.saveTicket.disabled) return;
  const reason = elements.ticketEditReason.value.trim();
  if (reason.length < 3) {
    setTicketEditorMessage("Escribe un motivo de corrección de al menos 3 caracteres.", "error");
    return;
  }
  let weighings;
  try {
    weighings = ticketEditorWeighings();
  } catch (error) {
    setTicketEditorMessage(error.message, "error");
    return;
  }
  state.busy = true;
  elements.saveTicket.disabled = true;
  setTicketEditorMessage("Actualizando el ticket completo…");
  try {
    const response = await apiRequest(`/recepcion-pollo-vivo/tickets/${state.editingTicket.id}`, {
      method: "PUT",
      body: JSON.stringify(buildTicketUpdatePayload(state.editingTicket, weighings, reason)),
    });
    renderData(response.data);
    state.editingTicket = normalizeFullTicket(response.ticket || response.data?.ticket || state.editingTicket);
    renderTicketEditor();
    setTicketEditorMessage(response.message || "Ticket actualizado completamente.", "success");
    setMessage(response.message || "Ticket actualizado completamente.", "success");
  } catch (error) {
    setTicketEditorMessage(firstValidationMessage(error), "error");
  } finally {
    state.busy = false;
    if (state.editingTicket) elements.saveTicket.disabled = state.editingTicket.editable === false;
    updateScaleUi();
  }
}

function printRegisteredTicket(ticket = state.editingTicket) {
  if (!ticket?.weighings?.length) {
    setTicketEditorMessage("El ticket no tiene pesadas para imprimir.", "error");
    return;
  }
  printWeightControlTicket(buildReceptionTicketPrintData(ticket, {
    ticketTitle: state.data?.catalog?.ticket_title,
    ticketMessage: state.data?.catalog?.ticket_message,
  }), {
    frameTitle: `Impresión de ${ticket.code}`,
    onSuccess: () => setTicketEditorMessage(`${ticket.code} enviado a impresión.`, "success"),
    onError: () => setTicketEditorMessage("No se pudo iniciar la impresión del ticket.", "error"),
  });
}

function deliveryTrucks() {
  return Array.isArray(state.data?.catalog?.delivery_trucks) ? state.data.catalog.delivery_trucks : [];
}

function deliveryDrivers() {
  return Array.isArray(state.data?.catalog?.delivery_drivers) ? state.data.catalog.delivery_drivers : [];
}

function renderDeliveryTrucks() {
  const query = normalizeRetailClientSearch(elements.deliveryTruckSearch.value);
  const trucks = deliveryTrucks().filter((truck) => normalizeRetailClientSearch([
    truck.plate, truck.brand, truck.model, truck.color, truck.description,
  ].filter(Boolean).join(" ")).includes(query));
  elements.deliveryTruckOptions.innerHTML = trucks.length
    ? trucks.map((truck) => `
      <button class="lir-fleet-option" type="button" role="option" data-live-delivery-truck="${truck.id}">
        <strong>${escapeHtml(truck.plate || "Sin placa")}</strong>
        <small>${escapeHtml([truck.brand, truck.model, truck.color, truck.description].filter(Boolean).join(" · ") || "Camión propio")}</small>
      </button>`).join("")
    : `<p class="lir-client-empty">${query ? "No hay camiones que coincidan." : "No hay camiones activos disponibles."}</p>`;
}

function renderDeliveryDrivers() {
  const query = normalizeRetailClientSearch(elements.deliveryDriverSearch.value);
  const drivers = deliveryDrivers().filter((driver) => normalizeRetailClientSearch([
    driver.name, driver.document_type, driver.document_number, driver.phone,
  ].filter(Boolean).join(" ")).includes(query));
  elements.deliveryDriverOptions.innerHTML = drivers.length
    ? drivers.map((driver) => `
      <button class="lir-fleet-option" type="button" role="option" data-live-delivery-driver="${driver.id}">
        <strong>${escapeHtml(driver.name || "Chofer sin nombre")}</strong>
        <small>${escapeHtml([[driver.document_type, driver.document_number].filter(Boolean).join(" "), driver.phone].filter(Boolean).join(" · ") || "Chofer activo")}</small>
      </button>`).join("")
    : `<p class="lir-client-empty">${query ? "No hay choferes que coincidan." : "No hay choferes activos disponibles."}</p>`;
}

function closeDeliverySelection({ restoreFocus = true } = {}) {
  const trigger = state.deliverySelection?.trigger || elements.capture;
  elements.deliveryTruckModal.hidden = true;
  elements.deliveryDriverModal.hidden = true;
  state.deliverySelection = null;
  syncModalEnvironment();
  if (restoreFocus) trigger?.focus?.({ preventScroll: true });
}

function clientIsInternal(client) {
  return client?.internal === true
    || client?.is_internal === true
    || client?.is_internal_client === true
    || String(client?.type || client?.client_type || "").toUpperCase() === "INTERNO";
}

function beginTicketRegistration(lane, trigger) {
  const laneNumber = Number(lane);
  const draft = dispatchDraft(laneNumber);
  if (state.busy || state.pendingCapture) return;
  if (!draft.weighings.length) {
    setMessage(`Agrega al menos una pesada al ticket de la columna ${laneNumber}.`, "error");
    return;
  }
  if (draft.registration_attempt) {
    const attempt = draft.registration_attempt;
    if (attempt.fingerprint !== dispatchDraftFingerprint(draft)) {
      setMessage("El borrador pendiente ya no coincide con el intento registrado. Recarga la vista y solicita revisión antes de continuar.", "error");
      return;
    }
    state.deliverySelection = {
      lane: laneNumber,
      ...registrationAttemptDelivery(attempt),
      draftFingerprint: attempt.fingerprint,
      trigger,
    };
    void submitDispatchTicket(laneNumber, { ...state.deliverySelection });
    return;
  }
  const client = laneDestination(laneNumber);
  if (!client) {
    setMessage(`Elige el cliente del ticket de la columna ${laneNumber}.`, "error");
    return;
  }
  if (state.draftClientInvalid[laneNumber] || client.unavailable) {
    setMessage(`El cliente guardado en la columna ${laneNumber} ya no está disponible. Pulsa Corregir cliente antes de registrar.`, "error");
    return;
  }
  state.deliverySelection = {
    lane: laneNumber,
    clientId: Number(client.id),
    vehicleId: null,
    driverId: null,
    draftFingerprint: dispatchDraftFingerprint(draft),
    trigger,
  };
  if (clientIsInternal(client)) {
    void submitDispatchTicket(laneNumber, { ...state.deliverySelection });
    return;
  }
  if (!deliveryTrucks().length || !deliveryDrivers().length) {
    state.deliverySelection = null;
    setMessage("Registra al menos un camión y un chofer activos antes de despachar este ticket.", "error");
    return;
  }
  elements.deliveryTruckHelp.textContent = `Ticket para ${client.name} · Selecciona el camión de entrega.`;
  elements.deliveryTruckSearch.value = "";
  renderDeliveryTrucks();
  openDialog(elements.deliveryTruckModal, trigger, elements.deliveryTruckSearch);
}

function chooseDeliveryTruck(vehicleId) {
  const truck = deliveryTrucks().find((item) => Number(item.id) === Number(vehicleId));
  if (!state.deliverySelection || !truck) return;
  state.deliverySelection.vehicleId = Number(truck.id);
  elements.deliveryTruckModal.hidden = true;
  elements.deliveryDriverHelp.textContent = `Camión ${truck.plate || "sin placa"} · Selecciona el chofer responsable.`;
  elements.deliveryDriverSearch.value = "";
  renderDeliveryDrivers();
  elements.deliveryDriverModal.hidden = false;
  syncModalEnvironment();
  globalThis.setTimeout(() => elements.deliveryDriverSearch.focus({ preventScroll: true }), 0);
}

function chooseDeliveryDriver(driverId) {
  const driver = deliveryDrivers().find((item) => Number(item.id) === Number(driverId));
  if (!state.deliverySelection || !driver) return;
  const selection = { ...state.deliverySelection, driverId: Number(driver.id) };
  const lane = selection.lane;
  closeDeliverySelection({ restoreFocus: false });
  void submitDispatchTicket(lane, selection);
}

function completeRegisteredDispatchDraft(lane, submittedDraft) {
  const laneNumber = Number(lane);
  hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
  reconcileDirectClientSelections();
  const current = dispatchDraft(laneNumber);
  const completion = remainingDispatchDraftAfterRegistration(current, submittedDraft, createUuid);
  if (!completion.handled) return { preservedWeighings: completion.preservedWeighings };
  if (!completion.draft) {
    resetDispatchDraft(laneNumber);
    return { preservedWeighings: 0 };
  }

  state.dispatchDrafts[laneNumber] = completion.draft;
  if (!persistDispatchDrafts()) {
    throw new Error("El ticket se registró, pero no se pudieron conservar las pesadas nuevas en esta tablet.");
  }
  return { preservedWeighings: completion.preservedWeighings };
}

function clearDeterministicRegistrationAttempt(lane, submittedDraft) {
  hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
  reconcileDirectClientSelections();
  const current = dispatchDraft(lane);
  if (String(current.draft_id) !== String(submittedDraft.draft_id)) return;
  if (current.registration_attempt?.fingerprint !== submittedDraft.registration_attempt?.fingerprint) return;
  current.registration_attempt = null;
  persistDispatchDrafts();
}

async function submitDispatchTicketWithinLock(laneNumber, delivery, trigger) {
  if (state.data?.company?.id && state.data?.branch?.id) {
    hydrateDispatchDrafts(state.data.company.id, state.data.branch.id, true);
    reconcileDirectClientSelections();
    renderLaneAssignments();
    renderRecords();
    renderTotals();
    updateCaptureAvailability();
  }
  const draft = dispatchDraft(laneNumber);
  if (state.dispatchDraftsBlocked || !draft.weighings.length) {
    throw new Error("El borrador cambió en otra pestaña. Revísalo nuevamente antes de registrar.");
  }
  const currentFingerprint = dispatchDraftFingerprint(draft);
  const existingAttempt = draft.registration_attempt;
  if (existingAttempt) {
    if (existingAttempt.fingerprint !== currentFingerprint) {
      throw new Error("El contenido cambió después del intento anterior. Recarga la vista y solicita revisión.");
    }
    delivery = { ...delivery, ...registrationAttemptDelivery(existingAttempt) };
  } else {
    if (delivery?.draftFingerprint !== currentFingerprint) {
      throw new Error("Las pesadas del ticket cambiaron mientras elegías el transporte. Revísalas y pulsa Registrar ticket nuevamente.");
    }
    if (state.draftClientInvalid[laneNumber]
      || Number(delivery?.clientId) !== Number(draft.dispatch_client_id)) {
      throw new Error("El cliente del borrador cambió en otra pestaña. Revisa el ticket antes de registrar.");
    }
    draft.registration_attempt = {
      fingerprint: currentFingerprint,
      dispatch_client_id: Number(draft.dispatch_client_id),
      delivery_vehicle_id: Number(delivery?.vehicleId) || null,
      delivery_driver_id: Number(delivery?.driverId) || null,
      attempted_at: new Date().toISOString(),
    };
    if (!persistDispatchDrafts()) {
      draft.registration_attempt = null;
      throw new Error("No se pudo proteger el ticket para su registro en esta tablet.");
    }
  }
  const submittedDraft = normalizeDispatchDraft(JSON.parse(JSON.stringify(draft)), laneNumber, createUuid);
  const effectiveDelivery = existingAttempt ? registrationAttemptDelivery(existingAttempt) : delivery;
  state.busy = true;
  state.deliverySelection = { lane: laneNumber, trigger };
  updateScaleUi();
  setMessage(`Registrando el ticket completo de la columna ${laneNumber}…`);
  try {
    const response = await apiRequest("/recepcion-pollo-vivo/tickets", {
      method: "POST",
      body: JSON.stringify(buildReceptionTicketPayload(submittedDraft, effectiveDelivery)),
    });
    const registeredTicket = normalizeFullTicket(response.ticket || response.data?.ticket || {});
    const completion = completeRegisteredDispatchDraft(laneNumber, submittedDraft);
    renderData(response.data);
    state.lastRegisteredTicket = registeredTicket.id ? registeredTicket : null;
    renderRecords();
    renderTotals();
    renderLaneAssignments();
    const preservationMessage = completion.preservedWeighings
      ? ` ${completion.preservedWeighings} pesada(s) agregadas durante el registro se conservaron en un borrador nuevo.`
      : "";
    setMessage(`${response.message || `${registeredTicket.code || "Ticket"} registrado correctamente.`}${preservationMessage}`, "success");
    if (registeredTicket.id) {
      state.ticketEditorTrigger = trigger;
      state.editingTicket = registeredTicket;
      state.ticketEditorCatalog = { cage_types: state.data?.catalog?.cage_types || [] };
      openDialog(elements.ticketEditorModal, trigger, elements.printTicket);
      renderTicketEditor();
      setTicketEditorMessage(`${registeredTicket.code} registrado. Puedes imprimirlo o revisar sus pesadas.`, "success");
    }
  } catch (error) {
    const status = Number(error?.status);
    const deterministicClientError = status >= 400 && status < 500 && ![408, 409].includes(status);
    if (deterministicClientError) {
      clearDeterministicRegistrationAttempt(laneNumber, submittedDraft);
      setMessage(`${firstValidationMessage(error)} El borrador quedó habilitado para corregirlo.`, "error");
    } else {
      setMessage(`${firstValidationMessage(error)} No se pudo confirmar el resultado: el ticket quedó congelado. Pulsa Reintentar ticket sin modificar sus pesadas ni transporte.`, "error");
    }
  } finally {
    state.busy = false;
    state.deliverySelection = null;
    renderLaneAssignments();
    renderRecords();
    renderTotals();
    updateScaleUi();
  }
}

async function submitDispatchTicket(lane, delivery) {
  const laneNumber = Number(lane);
  const trigger = delivery?.trigger
    || elements.registerTicketButtons.find((button) => Number(button.dataset.liveRegisterTicket) === laneNumber)
    || elements.capture;
  try {
    const lockResult = await withDispatchDraftLock(
      laneNumber,
      () => submitDispatchTicketWithinLock(laneNumber, delivery, trigger),
    );
    if (!lockResult.acquired) {
      state.deliverySelection = null;
      setMessage("Otra pestaña está modificando o registrando este ticket. Espera y pulsa Reintentar ticket.", "error");
    }
  } catch (error) {
    state.deliverySelection = null;
    setMessage(error.message || "No se pudo iniciar el registro del ticket.", "error");
    updateCaptureAvailability();
  }
}

function serialOptionsFromForm() {
  return {
    baudRate: Number(elements.baudRate.value),
    dataBits: Number(elements.dataBits.value),
    stopBits: Number(elements.stopBits.value),
    parity: elements.parity.value,
    flowControl: elements.flowControl.value,
  };
}

function populateSerialForm() {
  const serial = state.scale?.getState().serialOptions || RETAIL_SCALE_SERIAL_DEFAULTS;
  elements.baudRate.value = String(serial.baudRate);
  elements.dataBits.value = String(serial.dataBits);
  elements.stopBits.value = String(serial.stopBits);
  elements.parity.value = serial.parity;
  elements.flowControl.value = serial.flowControl;
}

async function connectScale(mode) {
  state.busy = true;
  updateScaleUi();
  setScaleSettingsMessage(`Conectando por ${mode === "ble" ? "Bluetooth" : "puerto serial"}…`);
  try {
    state.scale.configureSerial(serialOptionsFromForm());
    if (mode === "ble") await state.scale.connectBle();
    else await state.scale.connectSerial({ serialOptions: serialOptionsFromForm() });
    setScaleSettingsMessage("Balanza conectada correctamente.", "success");
  } catch (error) {
    setScaleSettingsMessage(error.message || "No se pudo conectar la balanza.", "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

async function refreshLegacyPendingFromSettings() {
  setSettingsMessage("Actualizando la lista de clientes…");
  try {
    const response = await apiRequest("/recepcion-pollo-vivo");
    renderData(response.data, { resetDirectClients: true });
    const restoredPendingCapture = await restorePendingCapture(
      response.data.company.id,
      response.data.branch.id,
    );
    updateScaleUi();

    if (restoredPendingCapture) {
      setSettingsMessage("Cliente activo encontrado y pesada pendiente recuperada.", "success");
      setMessage(
        `La pesada anterior de la columna ${state.pendingCapture.lane} quedó lista. Revisa sus datos congelados y pulsa Reintentar.`,
        "error",
      );
      globalThis.setTimeout(closeSettings, 700);
      return;
    }

    setSettingsMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
    setMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
  } catch (error) {
    setSettingsMessage(firstValidationMessage(error), "error");
  }
}

function openSettings() {
  populateConfiguration();
  setSettingsMessage(state.pendingUpgradeBlocked ? "Actualizando la lista de clientes…" : "");
  openDialog(elements.settingsModal, elements.openSettings, elements.defaultExternalOwner);
  if (state.pendingUpgradeBlocked) void refreshLegacyPendingFromSettings();
}

function closeSettings() {
  closeDialog(elements.settingsModal, elements.openSettings);
}

function openScaleSettings() {
  populateSerialForm();
  setScaleSettingsMessage("");
  const initialFocus = !elements.connectBle.disabled
    ? elements.connectBle
    : (!elements.connectSerial.disabled ? elements.connectSerial : elements.baudRate);
  openDialog(elements.scaleSettingsModal, elements.openScaleSettings, initialFocus);
}

function closeScaleSettings() {
  closeDialog(elements.scaleSettingsModal, elements.openScaleSettings);
}

function saveScaleSettings(event) {
  event.preventDefault();
  try {
    state.scale.configureSerial(serialOptionsFromForm());
    setMessage("Configuración de balanza guardada en esta tablet.", "success");
    closeScaleSettings();
  } catch (error) {
    setScaleSettingsMessage(error.message || "No se pudo guardar la configuración.", "error");
  }
}

function openManualWeight() {
  setManualWeightMessage("");
  openDialog(elements.manualWeightModal, elements.openManualWeight, elements.manualWeight);
}

function closeManualWeight() {
  closeDialog(elements.manualWeightModal, elements.openManualWeight);
}

function applyManualWeight(event) {
  event.preventDefault();
  try {
    state.scale.setManualReading(elements.manualWeight.value);
    const appliedWeight = Number(elements.manualWeight.value).toFixed(3);
    elements.manualWeight.value = "";
    updateScaleUi();
    closeManualWeight();
    setMessage(`Peso manual de ${appliedWeight} kg listo para registrar.`, "success");
  } catch (error) {
    setManualWeightMessage(error.message || "Ingresa un peso manual válido.", "error");
  }
}

async function saveSettings(event) {
  event.preventDefault();
  const payload = {
    default_external_owner_id: elements.defaultExternalOwner.value
      ? Number(elements.defaultExternalOwner.value)
      : null,
    lane_1_warehouse_id: Number(elements.laneDestinations[0].value),
    lane_2_warehouse_id: Number(elements.laneDestinations[1].value),
    lane_3_warehouse_id: Number(elements.laneDestinations[2].value),
    lane_4_warehouse_id: Number(elements.laneDestinations[3].value),
    lane_5_client_id: Number(elements.laneDestinations[4].value),
    lane_6_client_id: Number(elements.laneDestinations[5].value),
  };
  state.busy = true;
  setSettingsMessage("Guardando configuración…");
  try {
    const response = await apiRequest("/recepcion-pollo-vivo/configuracion", {
      method: "PUT",
      body: JSON.stringify(payload),
    });
    const recoverLegacyPending = state.pendingUpgradeBlocked;
    renderData(response.data, { resetDirectClients: true });
    const restoredPendingCapture = recoverLegacyPending
      ? await restorePendingCapture(response.data.company.id, response.data.branch.id)
      : false;

    if (restoredPendingCapture) {
      updateScaleUi();
      setSettingsMessage("Configuración guardada y pesada pendiente recuperada.", "success");
      setMessage(
        `La pesada anterior de la columna ${state.pendingCapture.lane} quedó lista. Revisa sus datos congelados y pulsa Reintentar.`,
        "error",
      );
      globalThis.setTimeout(closeSettings, 700);
    } else if (state.pendingUpgradeBlocked) {
      setSettingsMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
      setMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
    } else {
      setSettingsMessage(response.message || "Configuración guardada.", "success");
      globalThis.setTimeout(closeSettings, 500);
    }
  } catch (error) {
    setSettingsMessage(firstValidationMessage(error), "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

async function loadReception() {
  setMessage("Cargando el camión y la jornada del día…");
  try {
    const response = await apiRequest("/recepcion-pollo-vivo");
    renderData(response.data);
    state.scale.setStorageKey(`${SCALE_STORAGE_PREFIX}-branch-${response.data.branch.id}`, {
      persistCurrent: false,
    });
    const restoredPendingCapture = await restorePendingCapture(response.data.company.id, response.data.branch.id);
    populateSerialForm();
    updateScaleUi();
    const migratedDraftLane = state.migratedDraftLane;
    const loadMessage = restoredPendingCapture
      ? (state.pendingDispatchClientCorrection
          ? `La pesada de la columna ${state.pendingCapture.lane} conserva su lectura, pero necesita otro cliente antes de reintentar.`
          : `Hay una pesada pendiente en la columna ${state.pendingCapture.lane}. Reintenta para confirmar si ya fue registrada.`)
      : (state.pendingUpgradeBlocked
          ? (state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE)
          : (migratedDraftLane
              ? `La pesada pendiente se recuperó dentro del borrador de ticket de la columna ${migratedDraftLane}.`
              : "Selecciona una columna y registra la siguiente pesada."));
    setMessage(loadMessage, restoredPendingCapture || state.pendingUpgradeBlocked ? "error" : (migratedDraftLane ? "success" : ""));
    void state.scale.restoreAuthorizedConnection();
  } catch (error) {
    setMessage(firstValidationMessage(error), "error");
  }
}

state.scale = new RetailScaleController({
  storageKey: `${SCALE_STORAGE_PREFIX}-pending`,
  onReading: updateScaleUi,
  onStatus: updateScaleUi,
  onRaw(payload) {
    if (state.pendingCapture) return;
    elements.scaleRaw.textContent = `Trama: ${payload?.raw || payload?.rawText || state.scale.getState().lastRaw || "--"}`;
  },
});

elements.laneButtons.forEach((button) => button.addEventListener("click", () => selectLane(button.dataset.liveSelectLane)));
elements.clientPickerButtons.forEach((button) => button.addEventListener("click", () => {
  openClientPicker(button.dataset.liveChooseClient, button);
}));
elements.registerTicketButtons.forEach((button) => button.addEventListener("click", () => {
  beginTicketRegistration(button.dataset.liveRegisterTicket, button);
}));
elements.clientSearch.addEventListener("input", () => renderClientOptions(elements.clientSearch.value));
elements.clientOptions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-live-client-option]");
  if (option) void assignDispatchClient(option.dataset.liveClientOption);
});
elements.deliveryTruckSearch.addEventListener("input", renderDeliveryTrucks);
elements.deliveryDriverSearch.addEventListener("input", renderDeliveryDrivers);
elements.deliveryTruckOptions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-live-delivery-truck]");
  if (option) chooseDeliveryTruck(option.dataset.liveDeliveryTruck);
});
elements.deliveryDriverOptions.addEventListener("click", (event) => {
  const option = event.target.closest("[data-live-delivery-driver]");
  if (option) chooseDeliveryDriver(option.dataset.liveDeliveryDriver);
});
document.querySelectorAll("[data-live-close-delivery]").forEach((button) => button.addEventListener("click", () => closeDeliverySelection()));
document.querySelectorAll("[data-live-close-client-picker]").forEach((button) => button.addEventListener("click", closeClientPicker));
elements.sexButtons.forEach((button) => button.addEventListener("click", () => selectSex(button.dataset.liveSex)));
elements.capture.addEventListener("click", captureWeighing);
elements.birdsPerCage.addEventListener("input", updateCaptureAvailability);
elements.cageCount.addEventListener("input", updateCaptureAvailability);
elements.cageType.addEventListener("change", updateCaptureAvailability);
elements.openSettings.addEventListener("click", openSettings);
elements.settingsForm.addEventListener("submit", saveSettings);
document.querySelectorAll("[data-live-close-settings]").forEach((button) => button.addEventListener("click", closeSettings));
elements.openScaleSettings.addEventListener("click", openScaleSettings);
elements.scaleSettingsForm.addEventListener("submit", saveScaleSettings);
document.querySelectorAll("[data-live-close-scale-settings]").forEach((button) => button.addEventListener("click", closeScaleSettings));
elements.openManualWeight.addEventListener("click", openManualWeight);
elements.manualWeightForm.addEventListener("submit", applyManualWeight);
document.querySelectorAll("[data-live-close-manual-weight]").forEach((button) => button.addEventListener("click", closeManualWeight));
elements.weighingEditorForm.addEventListener("submit", saveWeighingEditor);
document.querySelectorAll("[data-live-close-weighing-editor]").forEach((button) => button.addEventListener("click", closeWeighingEditor));
elements.ticketEditorForm.addEventListener("submit", saveTicketEditor);
elements.printTicket.addEventListener("click", () => printRegisteredTicket());
document.querySelectorAll("[data-live-close-ticket-editor]").forEach((button) => button.addEventListener("click", closeTicketEditor));
elements.connectBle.addEventListener("click", () => connectScale("ble"));
elements.connectSerial.addEventListener("click", () => connectScale("serial"));
elements.disconnectScale.addEventListener("click", async () => {
  state.busy = true;
  setScaleSettingsMessage("Desconectando balanza…");
  try {
    await state.scale.disconnect();
    setScaleSettingsMessage("Balanza desconectada.", "success");
  } catch (error) {
    setScaleSettingsMessage(error.message || "No se pudo desconectar la balanza.", "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
});
elements.zoomOut.addEventListener("click", () => stepZoom(-1));
elements.zoomIn.addEventListener("click", () => stepZoom(1));
elements.zoomReset.addEventListener("click", () => applyZoom(100));
document.addEventListener("click", (event) => {
  const retryExpiredButton = event.target.closest("[data-live-retry-expired-ticket]");
  if (retryExpiredButton) {
    void retryExpiredDispatchTicket(retryExpiredButton.dataset.liveRetryExpiredTicket, retryExpiredButton);
    return;
  }
  const discardExpiredButton = event.target.closest("[data-live-discard-expired-drafts]");
  if (discardExpiredButton) {
    void discardExpiredDispatchDrafts();
    return;
  }
  const deleteButton = event.target.closest("[data-live-delete-weighing]");
  if (deleteButton) {
    event.stopPropagation();
    void deleteWeighing(deleteButton.dataset.liveDeleteWeighing);
    return;
  }
  const deleteDraftButton = event.target.closest("[data-live-delete-draft-weighing]");
  if (deleteDraftButton) {
    event.stopPropagation();
    void deleteDraftWeighing(deleteDraftButton.dataset.liveDraftLane, deleteDraftButton.dataset.liveDeleteDraftWeighing);
    return;
  }
  const draftRow = event.target.closest("[data-live-edit-draft-weighing]");
  if (draftRow) {
    const lane = Number(draftRow.dataset.liveDraftLane);
    const weighing = dispatchDraft(lane).weighings.find((item) => String(item.local_id) === String(draftRow.dataset.liveEditDraftWeighing));
    if (weighing) openWeighingEditor(weighing, { kind: "draft", lane, localId: weighing.local_id, trigger: draftRow });
    return;
  }
  const ticketRow = event.target.closest("[data-live-open-ticket]");
  if (ticketRow) {
    void openTicketEditor(ticketRow.dataset.liveOpenTicket, ticketRow);
    return;
  }
  const weighingRow = event.target.closest("[data-live-edit-weighing]");
  if (weighingRow) {
    const record = state.data?.records?.find((item) => !isDispatchTicketRecord(item) && Number(item.id) === Number(weighingRow.dataset.liveEditWeighing));
    if (record) openWeighingEditor(record, { kind: "weighing", lane: record.lane, trigger: weighingRow });
  }
});
document.addEventListener("keydown", (event) => {
  const openModal = [
    elements.ticketEditorModal,
    elements.weighingEditorModal,
    elements.deliveryDriverModal,
    elements.deliveryTruckModal,
    elements.clientModal,
    elements.manualWeightModal,
    elements.scaleSettingsModal,
    elements.settingsModal,
  ].find((modal) => !modal.hidden);
  if (!openModal) {
    if (["Enter", " "].includes(event.key)) {
      const row = event.target.closest("[data-live-open-ticket], [data-live-edit-weighing], [data-live-edit-draft-weighing]");
      if (row) {
        event.preventDefault();
        row.click();
      }
    }
    return;
  }
  if (event.key === "Escape") {
    if (openModal === elements.ticketEditorModal) closeTicketEditor();
    else if (openModal === elements.weighingEditorModal) closeWeighingEditor();
    else if ([elements.deliveryTruckModal, elements.deliveryDriverModal].includes(openModal)) closeDeliverySelection();
    else if (openModal === elements.clientModal) closeClientPicker();
    else if (openModal === elements.manualWeightModal) closeManualWeight();
    else if (openModal === elements.scaleSettingsModal) closeScaleSettings();
    else closeSettings();
    return;
  }
  trapDialogFocus(event, openModal);
});
[elements.settingsModal, elements.scaleSettingsModal, elements.manualWeightModal, elements.clientModal,
  elements.deliveryTruckModal, elements.deliveryDriverModal, elements.weighingEditorModal, elements.ticketEditorModal].forEach((modal) => {
  modal.addEventListener("click", (event) => {
    if (event.target !== modal) return;
    if (modal === elements.ticketEditorModal) closeTicketEditor();
    else if (modal === elements.weighingEditorModal) closeWeighingEditor();
    else if ([elements.deliveryTruckModal, elements.deliveryDriverModal].includes(modal)) closeDeliverySelection();
    else if (modal === elements.clientModal) closeClientPicker();
    else if (modal === elements.manualWeightModal) closeManualWeight();
    else if (modal === elements.scaleSettingsModal) closeScaleSettings();
    else closeSettings();
  });
});
window.addEventListener("storage", async (event) => {
  if (event.key === ZOOM_STORAGE_KEY) {
    applyZoom(Number(event.newValue), false);
    return;
  }
  if (event.key === state.dispatchDraftStorageKey) {
    hydrateDispatchDrafts(state.data?.company?.id, state.data?.branch?.id, true);
    reconcileDirectClientSelections();
    renderLaneAssignments();
    renderRecords();
    renderTotals();
    updateCaptureAvailability();
    setMessage("Los borradores se actualizaron desde otra pestaña.");
    return;
  }
  const currentPendingEvent = Boolean(state.pendingCaptureStorageKey)
    && event.key === state.pendingCaptureStorageKey;
  const legacyPendingEvent = Boolean(state.legacyPendingCaptureStorageKey)
    && event.key === state.legacyPendingCaptureStorageKey;
  const legacyV2PendingEvent = Boolean(state.legacyV2PendingCaptureStorageKey)
    && event.key === state.legacyV2PendingCaptureStorageKey;
  if (!currentPendingEvent && !legacyPendingEvent && !legacyV2PendingEvent) return;

  if (event.newValue && state.data) {
    if (!elements.clientModal.hidden) closeClientPicker();
    const restoredPendingCapture = await restorePendingCapture(
      state.data.company.id,
      state.data.branch.id,
    );
    renderLaneAssignments();
    updateScaleUi();
    if (restoredPendingCapture) {
      const pendingMessage = state.pendingDispatchClientCorrection
        ? `Otra pestaña dejó la pesada de la columna ${state.pendingCapture.lane} esperando otro cliente. La lectura y los demás datos siguen congelados.`
        : `Otra pestaña dejó una pesada pendiente en la columna ${state.pendingCapture.lane}. Solo se reintentará con los mismos datos.`;
      setMessage(pendingMessage, "error");
    } else if (state.pendingUpgradeBlocked) {
      setMessage(state.pendingUpgradeMessage || LEGACY_PENDING_BLOCKED_MESSAGE, "error");
    }
    return;
  }

  if (!event.newValue && currentPendingEvent && state.pendingCapture) {
    const pendingLane = Number(state.pendingCapture.lane);
    state.pendingCapture = null;
    state.pendingDispatchClientCorrection = false;
    if ([5, 6].includes(pendingLane)) {
      state.directClientReselectionRequired[pendingLane] = false;
    }
    updateScaleUi();
    void loadReception();
    return;
  }

  if (!event.newValue && (legacyPendingEvent || legacyV2PendingEvent) && state.pendingUpgradeBlocked) {
    state.pendingUpgradeBlocked = false;
    state.pendingUpgradeLane = null;
    updateScaleUi();
    void loadReception();
  }
});
window.addEventListener("beforeunload", (event) => {
  if (!state.pendingCapture && !state.pendingUpgradeBlocked) return;
  event.preventDefault();
  event.returnValue = "";
});
window.addEventListener("pagehide", () => {
  void state.scale.destroy();
}, { once: true });
window.addEventListener("auth:expired", () => {
  setMessage("La sesión venció. Vuelve al menú e inicia sesión nuevamente.", "error");
});

applyZoom(state.zoom, false);
selectLane(1);
selectSex("MACHO");
updateScaleUi();
void loadReception();
