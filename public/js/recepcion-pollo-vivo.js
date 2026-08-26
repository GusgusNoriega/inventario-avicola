import { apiRequest } from "./api-client.js";
import {
  RetailScaleController,
  RETAIL_SCALE_SERIAL_DEFAULTS,
} from "./despacho-minorista-balanza.js";

const ZOOM_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
const ZOOM_STORAGE_KEY = "sistema-pollos-recepcion-pollo-vivo-zoom-v1";
const SCALE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-balanza-v1";
const PENDING_CAPTURE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-pendiente-v2";
const LAYOUT_VERSION = 2;
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
};

const state = {
  data: null,
  activeLane: 1,
  sex: "MACHO",
  busy: false,
  pendingCapture: null,
  pendingCaptureStorageKey: null,
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
  if (!state.pendingCaptureStorageKey) return;
  try {
    localStorage.setItem(state.pendingCaptureStorageKey, JSON.stringify(payload));
  } catch {
    // El aviso de salida sigue protegiendo la captura si no hay almacenamiento.
  }
}

function clearPendingCapture() {
  state.pendingCapture = null;
  if (!state.pendingCaptureStorageKey) return;
  try {
    localStorage.removeItem(state.pendingCaptureStorageKey);
  } catch {
    // La memoria de la pestaña se limpia aunque el almacenamiento esté bloqueado.
  }
}

function restorePendingCapture(companyId, branchId) {
  const userId = Number(elements.main?.dataset.liveUserId);
  if (!Number.isInteger(userId) || userId < 1) return false;
  state.pendingCaptureStorageKey = `${PENDING_CAPTURE_STORAGE_PREFIX}-company-${companyId}-branch-${branchId}-user-${userId}`;
  try {
    const stored = localStorage.getItem(state.pendingCaptureStorageKey);
    if (!stored) return false;
    const payload = JSON.parse(stored);
    const lane = Number(payload?.lane);
    const birdsPerCage = Number(payload?.birds_per_cage);
    const cageCount = Number(payload?.cage_count);
    const cageTypeId = Number(payload?.cage_type_id);
    const readWeight = Number(payload?.read_weight_kg);
    const requiresSex = [5, 6].includes(lane);
    if (Number(payload?.layout_version) !== LAYOUT_VERSION
      || typeof payload?.idempotency_key !== "string"
      || !Number.isInteger(lane)
      || lane < 1
      || lane > 6
      || !Number.isInteger(birdsPerCage)
      || birdsPerCage < 1
      || birdsPerCage > 1000
      || !Number.isInteger(cageCount)
      || cageCount < 1
      || cageCount > 10000
      || !Number.isInteger(cageTypeId)
      || cageTypeId < 1
      || !Number.isFinite(readWeight)
      || readWeight <= 0
      || (requiresSex && !["MACHO", "HEMBRA"].includes(payload.sex))
      || (!requiresSex && payload.sex !== undefined)) {
      throw new Error("Captura pendiente incompatible");
    }

    state.pendingCapture = payload;
    state.activeLane = lane;
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
    selectLane(lane);
    if (payload.sex) selectSex(payload.sex);

    return true;
  } catch {
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

function syncModalEnvironment() {
  const modalOpen = [
    elements.settingsModal,
    elements.scaleSettingsModal,
    elements.manualWeightModal,
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
  trigger.focus({ preventScroll: true });
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
  const kind = laneProfile(lane).type === "ALMACEN" ? "warehouses" : "clients";
  return catalogItem(kind, configuration?.destination_id);
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
  setSelectOptions(elements.laneDestinations[4], catalog.clients || [], "Seleccionar cliente externo", laneConfiguration(5)?.destination_id);
  setSelectOptions(elements.laneDestinations[5], catalog.clients || [], "Seleccionar cliente externo", laneConfiguration(6)?.destination_id);

  const cageTypeId = elements.cageType.value;
  elements.cageType.innerHTML = optionMarkup(catalog.cage_types || [], "Seleccionar tipo de java");
  const preferredCage = (catalog.cage_types || []).some((item) => String(item.id) === cageTypeId)
    ? cageTypeId
    : String(catalog.cage_types?.[0]?.id || "");
  elements.cageType.value = preferredCage;

  const externalOwner = catalogItem("external_owners", configuration.default_external_owner_id);
  elements.externalSummaryLabel.textContent = "Empresa externa";

  LANE_NUMBERS.forEach((lane) => {
    const destination = laneDestination(lane);
    elements.laneLabels[lane - 1].textContent = destination?.name || "Sin configurar";
    const profile = laneProfile(lane);
    const ownerLabel = profile.ownerType === "EXTERNA"
      ? (externalOwner?.name || "Empresa externa sin configurar")
      : "Mi empresa";
    const sexLabel = profile.sex === "HEMBRA" ? "Hembra" : (profile.sex === "MACHO" ? "Macho" : "Sexo al registrar");
    elements.laneProfileLabels[lane - 1].textContent = `${ownerLabel} · ${sexLabel}`;
  });

  selectLane(state.activeLane);
}

function renderRecord(record) {
  const ownerExternal = record.owner?.type === "EXTERNA";
  const sexLabel = record.sex === "HEMBRA" ? "H" : "M";
  const ownerLabel = ownerExternal ? record.owner?.name : "Mi empresa";
  const previousLayout = record.uses_previous_layout
    ? '<small class="lir-record-legacy">Registro anterior a esta distribución</small>'
    : "";
  return `
    <article class="lir-weighing-row ${ownerExternal ? "is-external" : "is-own"}">
      <header>
        <span>#${record.number} · ${formatTime(record.weighed_at)}</span>
        <button type="button" data-live-delete-weighing="${record.id}" aria-label="Anular pesada ${record.number}">×</button>
      </header>
      <strong title="${escapeHtml(ownerLabel)}">${escapeHtml(ownerLabel)}</strong>
      ${previousLayout}
      <small class="lir-record-destination" title="${escapeHtml(record.destination?.name || "Sin destino")}">Destino: ${escapeHtml(record.destination?.name || "Sin destino")}</small>
      <div><span>${sexLabel}</span><span>${record.cages} J × ${record.birds_per_cage}</span><span>${record.birds} aves</span></div>
      <footer><small>Tara ${formatKg(record.tare_weight_kg)}</small><b>${formatKg(record.net_weight_kg)}</b></footer>
    </article>`;
}

function renderRecords() {
  const records = state.data?.records || [];
  LANE_NUMBERS.forEach((lane) => {
    const laneRecords = records.filter((record) => Number(record.lane) === lane);
    elements.laneRows[lane - 1].innerHTML = laneRecords.length
      ? laneRecords.map(renderRecord).join("")
      : '<p class="lir-empty-lane">Aún no hay pesadas</p>';
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
    const totals = state.data?.totals?.lanes?.[String(lane)] || {};
    elements.laneCages[lane - 1].textContent = String(totals.cages || 0);
    elements.laneBirds[lane - 1].textContent = String(totals.birds || 0);
    elements.laneNet[lane - 1].textContent = formatKg(totals.net_weight_kg);
  });
  renderSelectedTotals();
}

function renderSelectedTotals() {
  const totals = state.data?.totals?.lanes?.[String(state.activeLane)] || {};
  const destination = laneDestination(state.activeLane);
  const action = laneProfile(state.activeLane).type === "ALMACEN" ? "Entrada" : "Recepción + despacho";
  elements.selectedLaneLabel.textContent = `${state.activeLane} · ${action} · ${destination?.name || "Sin configurar"}`;
  elements.selectedWeighings.textContent = String(totals.weighings || 0);
  elements.selectedCages.textContent = String(totals.cages || 0);
  elements.selectedBirds.textContent = String(totals.birds || 0);
  elements.selectedGross.textContent = formatKg(totals.gross_weight_kg);
  elements.selectedNet.textContent = formatKg(totals.net_weight_kg);
}

function renderData(data) {
  state.data = data;
  elements.operatingDate.textContent = formatOperatingDate(data.operating_date);
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

  elements.lanes.forEach((lane) => lane.classList.toggle("is-active", Number(lane.dataset.liveLane) === state.activeLane));
  elements.laneButtons.forEach((button) => {
    const selected = Number(button.dataset.liveSelectLane) === state.activeLane;
    button.setAttribute("aria-pressed", String(selected));
  });
  elements.activeLaneNumber.textContent = String(state.activeLane);
  elements.capturePanel.classList.toggle("is-direct-lane", !profile.sex);
  elements.sexChoice.hidden = Boolean(profile.sex);
  elements.assignmentTitle.textContent = `${ownerLabel} · ${sexLabel}`;
  elements.assignmentHelp.textContent = sexLabel
    ? (profile.sex
        ? `La columna ${state.activeLane} define automáticamente el propietario y el sexo.`
        : `La columna ${state.activeLane} siempre pertenece a mi empresa; el sexo se toma del selector.`)
    : "";
  if (state.data && profile.ownerType === "EXTERNA" && !externalOwner) {
    setMessage("Configura la empresa externa antes de registrar en las columnas 3 y 4.", "error");
  } else if (state.data && !laneDestination(state.activeLane)) {
    setMessage(`Configura el destino de la columna ${state.activeLane} antes de registrar.`, "error");
  } else if (state.data) {
    setMessage(`Columna ${state.activeLane} lista para la siguiente pesada.`);
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
  elements.sexButtons.forEach((button) => { button.disabled = controlsLocked; });
  elements.birdsPerCage.disabled = controlsLocked;
  elements.cageCount.disabled = controlsLocked;
  elements.cageType.disabled = controlsLocked;
  elements.captureLabel.textContent = state.busy && pendingCapture
    ? "Guardando en columna"
    : (pendingCapture ? "Reintentar en columna" : "Guardar en columna");
  elements.activeLaneNumber.textContent = String(pendingCapture?.lane || state.activeLane);
  elements.capture.disabled = pendingCapture
    ? state.busy || !state.data
    : state.busy
      || !state.data
      || !scaleState?.isCaptureReady
      || !elements.cageType.value
      || !quantityOk
      || !configuredLane
      || !ownerOk;
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
  return {
    layout_version: LAYOUT_VERSION,
    idempotency_key: createUuid(),
    lane: state.activeLane,
    ...(profile.sex ? {} : { sex: state.sex }),
    cage_type_id: Number(elements.cageType.value),
    birds_per_cage: Number(elements.birdsPerCage.value),
    cage_count: Number(elements.cageCount.value),
    weighed_at: new Date().toISOString(),
    ...scalePayload(scaleState),
  };
}

async function performCaptureWeighing() {
  const payload = capturePayload();
  state.pendingCapture = payload;
  persistPendingCapture(payload);
  state.busy = true;
  updateScaleUi();
  setMessage(`Guardando la pesada en la columna ${state.activeLane}…`);

  try {
    const response = await apiRequest("/recepcion-pollo-vivo/pesadas", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    renderData(response.data);
    clearPendingCapture();
    state.scale.clearReading();
    elements.cageCount.value = "1";
    setMessage(response.message || "Pesada registrada correctamente.", "success");
  } catch (error) {
    const status = Number(error?.status);
    const deterministicClientError = status >= 400 && status < 500 && status !== 408;
    if (deterministicClientError) {
      clearPendingCapture();
      setMessage(firstValidationMessage(error), "error");
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

  if (!state.pendingCapture && state.data && restorePendingCapture(
    state.data.company.id,
    state.data.branch.id,
  )) {
    updateScaleUi();
    setMessage(
      `Otra pestaña dejó una pesada pendiente en la columna ${state.pendingCapture.lane}. Revisa los datos congelados y pulsa Reintentar.`,
      "error",
    );
    return;
  }

  if (!navigator.locks?.request || !state.pendingCaptureStorageKey) {
    await performCaptureWeighing();
    return;
  }

  await navigator.locks.request(
    `${state.pendingCaptureStorageKey}-request-lock`,
    { mode: "exclusive", ifAvailable: true },
    async (lock) => {
      if (!lock) {
        setMessage("Otra pestaña está guardando una pesada. Espera su confirmación antes de continuar.", "error");
        return;
      }
      await performCaptureWeighing();
    },
  );
}

async function deleteWeighing(id) {
  const record = state.data?.records?.find((item) => Number(item.id) === Number(id));
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

function openSettings() {
  populateConfiguration();
  setSettingsMessage("");
  openDialog(elements.settingsModal, elements.openSettings, elements.defaultExternalOwner);
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
    renderData(response.data);
    setSettingsMessage(response.message || "Configuración guardada.", "success");
    globalThis.setTimeout(closeSettings, 500);
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
    const restoredPendingCapture = restorePendingCapture(response.data.company.id, response.data.branch.id);
    populateSerialForm();
    updateScaleUi();
    setMessage(restoredPendingCapture
      ? `Hay una pesada pendiente en la columna ${state.pendingCapture.lane}. Reintenta para confirmar si ya fue registrada.`
      : "Selecciona una columna y registra la siguiente pesada.",
    restoredPendingCapture ? "error" : "");
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
  const deleteButton = event.target.closest("[data-live-delete-weighing]");
  if (deleteButton) void deleteWeighing(deleteButton.dataset.liveDeleteWeighing);
});
document.addEventListener("keydown", (event) => {
  const openModal = [
    elements.manualWeightModal,
    elements.scaleSettingsModal,
    elements.settingsModal,
  ].find((modal) => !modal.hidden);
  if (!openModal) return;
  if (event.key === "Escape") {
    if (openModal === elements.manualWeightModal) closeManualWeight();
    else if (openModal === elements.scaleSettingsModal) closeScaleSettings();
    else closeSettings();
    return;
  }
  trapDialogFocus(event, openModal);
});
[elements.settingsModal, elements.scaleSettingsModal, elements.manualWeightModal].forEach((modal) => {
  modal.addEventListener("click", (event) => {
    if (event.target !== modal) return;
    if (modal === elements.manualWeightModal) closeManualWeight();
    else if (modal === elements.scaleSettingsModal) closeScaleSettings();
    else closeSettings();
  });
});
window.addEventListener("storage", (event) => {
  if (event.key === ZOOM_STORAGE_KEY) {
    applyZoom(Number(event.newValue), false);
    return;
  }
  if (!state.pendingCaptureStorageKey || event.key !== state.pendingCaptureStorageKey) return;

  if (event.newValue && state.data && restorePendingCapture(
    state.data.company.id,
    state.data.branch.id,
  )) {
    updateScaleUi();
    setMessage(
      `Otra pestaña dejó una pesada pendiente en la columna ${state.pendingCapture.lane}. Solo se reintentará con los mismos datos.`,
      "error",
    );
    return;
  }

  if (!event.newValue && state.pendingCapture) {
    state.pendingCapture = null;
    updateScaleUi();
    void loadReception();
  }
});
window.addEventListener("beforeunload", (event) => {
  if (!state.pendingCapture) return;
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
