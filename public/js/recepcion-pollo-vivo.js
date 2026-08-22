import { apiRequest } from "./api-client.js";
import {
  RetailScaleController,
  RETAIL_SCALE_SERIAL_DEFAULTS,
} from "./despacho-minorista-balanza.js";

const ZOOM_LEVELS = [67, 75, 80, 90, 100, 110, 125, 150];
const ZOOM_STORAGE_KEY = "sistema-pollos-recepcion-pollo-vivo-zoom-v1";
const SCALE_STORAGE_PREFIX = "sistema-pollos-recepcion-pollo-vivo-balanza-v1";

const elements = {
  operatingDate: document.getElementById("liveIntakeOperatingDate"),
  openSettings: document.getElementById("liveIntakeOpenSettings"),
  settingsModal: document.getElementById("liveIntakeSettingsModal"),
  settingsForm: document.getElementById("liveIntakeSettingsForm"),
  settingsMessage: document.getElementById("liveIntakeSettingsMessage"),
  defaultExternalOwner: document.getElementById("liveIntakeDefaultExternalOwner"),
  externalOwnerButton: document.getElementById("liveIntakeExternalOwnerButton"),
  laneDestinations: [
    document.getElementById("liveIntakeLane1Destination"),
    document.getElementById("liveIntakeLane2Destination"),
    document.getElementById("liveIntakeLane3Destination"),
    document.getElementById("liveIntakeLane4Destination"),
  ],
  laneLabels: [1, 2, 3, 4].map((lane) => document.getElementById(`liveIntakeLaneDestination${lane}`)),
  laneRows: [1, 2, 3, 4].map((lane) => document.getElementById(`liveIntakeLaneRows${lane}`)),
  lanes: Array.from(document.querySelectorAll("[data-live-lane]")),
  laneButtons: Array.from(document.querySelectorAll("[data-live-select-lane]")),
  ownerButtons: Array.from(document.querySelectorAll("[data-live-owner]")),
  sexButtons: Array.from(document.querySelectorAll("[data-live-sex]")),
  birdsPerCage: document.getElementById("liveIntakeBirdsPerCage"),
  cageCount: document.getElementById("liveIntakeCageCount"),
  cageType: document.getElementById("liveIntakeCageType"),
  capture: document.getElementById("liveIntakeCapture"),
  activeLaneNumber: document.getElementById("liveIntakeActiveLaneNumber"),
  message: document.getElementById("liveIntakeMessage"),
  scaleStatus: document.getElementById("liveIntakeScaleStatus"),
  scaleWeight: document.getElementById("liveIntakeScaleWeight"),
  scaleRaw: document.getElementById("liveIntakeScaleRaw"),
  connectBle: document.getElementById("liveIntakeConnectBle"),
  connectSerial: document.getElementById("liveIntakeConnectSerial"),
  disconnectScale: document.getElementById("liveIntakeDisconnectScale"),
  manualWeight: document.getElementById("liveIntakeManualWeight"),
  applyManualWeight: document.getElementById("liveIntakeApplyManualWeight"),
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
  laneCages: [1, 2, 3, 4].map((lane) => document.getElementById(`liveIntakeLaneCages${lane}`)),
  laneBirds: [1, 2, 3, 4].map((lane) => document.getElementById(`liveIntakeLaneBirds${lane}`)),
  laneNet: [1, 2, 3, 4].map((lane) => document.getElementById(`liveIntakeLaneNet${lane}`)),
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
  ownerType: "PROPIA",
  sex: "MACHO",
  busy: false,
  pendingCapture: null,
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

function laneDestination(lane) {
  const configuration = laneConfiguration(lane);
  const kind = lane <= 2 ? "warehouses" : "clients";
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
  setSelectOptions(elements.laneDestinations[2], catalog.clients || [], "Seleccionar cliente externo", laneConfiguration(3)?.destination_id);
  setSelectOptions(elements.laneDestinations[3], catalog.clients || [], "Seleccionar cliente externo", laneConfiguration(4)?.destination_id);

  const cageTypeId = elements.cageType.value;
  elements.cageType.innerHTML = optionMarkup(catalog.cage_types || [], "Seleccionar tipo de java");
  const preferredCage = (catalog.cage_types || []).some((item) => String(item.id) === cageTypeId)
    ? cageTypeId
    : String(catalog.cage_types?.[0]?.id || "");
  elements.cageType.value = preferredCage;

  const externalOwner = catalogItem("external_owners", configuration.default_external_owner_id);
  elements.externalOwnerButton.disabled = !externalOwner;
  elements.externalOwnerButton.textContent = externalOwner?.name || "Empresa externa";
  elements.externalSummaryLabel.textContent = externalOwner?.name || "Empresa externa";

  if (!externalOwner && state.ownerType === "EXTERNA") selectOwner("PROPIA");

  [1, 2, 3, 4].forEach((lane) => {
    const destination = laneDestination(lane);
    elements.laneLabels[lane - 1].textContent = destination?.name || "Sin configurar";
  });
}

function renderRecord(record) {
  const ownerExternal = record.owner?.type === "EXTERNA";
  const sexLabel = record.sex === "HEMBRA" ? "H" : "M";
  const ownerLabel = ownerExternal ? record.owner?.name : "Mi empresa";
  return `
    <article class="lir-weighing-row ${ownerExternal ? "is-external" : "is-own"}">
      <header>
        <span>#${record.number} · ${formatTime(record.weighed_at)}</span>
        <button type="button" data-live-delete-weighing="${record.id}" aria-label="Anular pesada ${record.number}">×</button>
      </header>
      <strong title="${escapeHtml(ownerLabel)}">${escapeHtml(ownerLabel)}</strong>
      <div><span>${sexLabel}</span><span>${record.cages} J × ${record.birds_per_cage}</span><span>${record.birds} aves</span></div>
      <footer><small>Tara ${formatKg(record.tare_weight_kg)}</small><b>${formatKg(record.net_weight_kg)}</b></footer>
    </article>`;
}

function renderRecords() {
  const records = state.data?.records || [];
  [1, 2, 3, 4].forEach((lane) => {
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

  [1, 2, 3, 4].forEach((lane) => {
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
  const action = state.activeLane <= 2 ? "Entrada" : "Despacho";
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
  state.activeLane = Math.min(4, Math.max(1, Number(laneNumber) || 1));
  elements.lanes.forEach((lane) => lane.classList.toggle("is-active", Number(lane.dataset.liveLane) === state.activeLane));
  elements.laneButtons.forEach((button) => {
    const selected = Number(button.dataset.liveSelectLane) === state.activeLane;
    button.setAttribute("aria-pressed", String(selected));
  });
  elements.activeLaneNumber.textContent = String(state.activeLane);
  renderSelectedTotals();
  updateCaptureAvailability();
}

function selectOwner(ownerType) {
  if (ownerType === "EXTERNA" && elements.externalOwnerButton.disabled) {
    openSettings();
    setSettingsMessage("Selecciona primero la empresa externa predeterminada.", "error");
    return;
  }
  state.ownerType = ownerType;
  elements.ownerButtons.forEach((button) => {
    const selected = button.dataset.liveOwner === ownerType;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
}

function selectSex(sex) {
  state.sex = sex;
  elements.sexButtons.forEach((button) => {
    const selected = button.dataset.liveSex === sex;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });
}

function updateScaleUi(payload = state.scale?.getState()) {
  const scaleState = payload?.state || payload;
  if (!scaleState) return;
  const hasWeight = Number.isFinite(scaleState.currentWeightKg);
  const weight = hasWeight ? Number(scaleState.currentWeightKg) : null;
  elements.scaleWeight.innerHTML = hasWeight
    ? `${weight.toFixed(3)} <small>kg</small>`
    : '--- <small>kg</small>';
  elements.scaleStatus.className = `lir-status-chip ${scaleState.isConnected ? "is-connected" : "is-offline"}`;
  elements.scaleStatus.innerHTML = `<i></i> ${escapeHtml(scaleState.statusMessage || "Sin conexión")}`;
  elements.connectBle.disabled = state.busy || scaleState.isConnecting || !scaleState.capabilities.bluetooth;
  elements.connectSerial.disabled = state.busy || scaleState.isConnecting || !scaleState.capabilities.serial;
  elements.disconnectScale.disabled = state.busy || (!scaleState.isConnected && !scaleState.isConnecting);
  updateCaptureAvailability();
}

function updateCaptureAvailability() {
  const scaleState = state.scale?.getState();
  const quantityOk = Number(elements.birdsPerCage.value) > 0 && Number(elements.cageCount.value) > 0;
  const configuredLane = Boolean(laneDestination(state.activeLane));
  const ownerOk = state.ownerType === "PROPIA" || Boolean(state.data?.configuration?.default_external_owner_id);
  elements.capture.disabled = state.busy
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
  const defaultExternalOwnerId = state.data?.configuration?.default_external_owner_id;
  return {
    idempotency_key: createUuid(),
    lane: state.activeLane,
    owner_type: state.ownerType,
    external_owner_id: state.ownerType === "EXTERNA" ? defaultExternalOwnerId : null,
    sex: state.sex,
    cage_type_id: Number(elements.cageType.value),
    birds_per_cage: Number(elements.birdsPerCage.value),
    cage_count: Number(elements.cageCount.value),
    weighed_at: new Date().toISOString(),
    ...scalePayload(scaleState),
  };
}

async function captureWeighing() {
  if (elements.capture.disabled) return;
  const payload = capturePayload();
  state.pendingCapture = payload;
  state.busy = true;
  updateScaleUi();
  setMessage(`Guardando la pesada en la columna ${state.activeLane}…`);

  try {
    const response = await apiRequest("/recepcion-pollo-vivo/pesadas", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    renderData(response.data);
    state.pendingCapture = null;
    state.scale.clearReading();
    elements.cageCount.value = "1";
    setMessage(response.message || "Pesada registrada correctamente.", "success");
  } catch (error) {
    if (error?.status) state.pendingCapture = null;
    setMessage(firstValidationMessage(error), "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
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
  try {
    state.scale.configureSerial(serialOptionsFromForm());
    if (mode === "ble") await state.scale.connectBle();
    else await state.scale.connectSerial({ serialOptions: serialOptionsFromForm() });
  } catch (error) {
    setMessage(error.message || "No se pudo conectar la balanza.", "error");
  } finally {
    state.busy = false;
    updateScaleUi();
  }
}

function openSettings() {
  populateConfiguration();
  populateSerialForm();
  setSettingsMessage("");
  elements.settingsModal.hidden = false;
  elements.defaultExternalOwner.focus({ preventScroll: true });
}

function closeSettings() {
  elements.settingsModal.hidden = true;
  elements.openSettings.focus({ preventScroll: true });
}

async function saveSettings(event) {
  event.preventDefault();
  const payload = {
    default_external_owner_id: elements.defaultExternalOwner.value
      ? Number(elements.defaultExternalOwner.value)
      : null,
    lane_1_warehouse_id: Number(elements.laneDestinations[0].value),
    lane_2_warehouse_id: Number(elements.laneDestinations[1].value),
    lane_3_client_id: Number(elements.laneDestinations[2].value),
    lane_4_client_id: Number(elements.laneDestinations[3].value),
  };
  state.busy = true;
  setSettingsMessage("Guardando configuración…");
  try {
    state.scale.configureSerial(serialOptionsFromForm());
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
    populateSerialForm();
    updateScaleUi();
    setMessage("Selecciona una columna y registra la siguiente pesada.");
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
    elements.scaleRaw.textContent = `Trama: ${payload?.raw || payload?.rawText || state.scale.getState().lastRaw || "--"}`;
  },
});

elements.laneButtons.forEach((button) => button.addEventListener("click", () => selectLane(button.dataset.liveSelectLane)));
elements.ownerButtons.forEach((button) => button.addEventListener("click", () => selectOwner(button.dataset.liveOwner)));
elements.sexButtons.forEach((button) => button.addEventListener("click", () => selectSex(button.dataset.liveSex)));
elements.capture.addEventListener("click", captureWeighing);
elements.birdsPerCage.addEventListener("input", updateCaptureAvailability);
elements.cageCount.addEventListener("input", updateCaptureAvailability);
elements.cageType.addEventListener("change", updateCaptureAvailability);
elements.openSettings.addEventListener("click", openSettings);
elements.settingsForm.addEventListener("submit", saveSettings);
document.querySelectorAll("[data-live-close-settings]").forEach((button) => button.addEventListener("click", closeSettings));
elements.connectBle.addEventListener("click", () => connectScale("ble"));
elements.connectSerial.addEventListener("click", () => connectScale("serial"));
elements.disconnectScale.addEventListener("click", async () => {
  state.busy = true;
  try {
    await state.scale.disconnect();
  } finally {
    state.busy = false;
    updateScaleUi();
  }
});
elements.applyManualWeight.addEventListener("click", () => {
  try {
    state.scale.setManualReading(elements.manualWeight.value);
    elements.manualWeight.value = "";
    setSettingsMessage("Lectura manual aplicada; ya puedes cerrar y capturar.", "success");
  } catch (error) {
    setSettingsMessage(error.message, "error");
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
  if (event.key === "Escape" && !elements.settingsModal.hidden) closeSettings();
});
window.addEventListener("storage", (event) => {
  if (event.key === ZOOM_STORAGE_KEY) applyZoom(Number(event.newValue), false);
});
window.addEventListener("pagehide", () => {
  void state.scale.destroy();
}, { once: true });
window.addEventListener("auth:expired", () => {
  setMessage("La sesión venció. Vuelve al menú e inicia sesión nuevamente.", "error");
});

applyZoom(state.zoom, false);
selectLane(1);
selectOwner("PROPIA");
selectSex("MACHO");
updateScaleUi();
void loadReception();
