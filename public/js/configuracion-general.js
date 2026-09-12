import { apiRequest } from "./api-client.js";

const elements = {
  form: document.getElementById("generalSettingsForm"),
  company: document.getElementById("settingsCompany"),
  currentCutoff: document.getElementById("settingsCurrentCutoff"),
  timezone: document.getElementById("settingsTimezone"),
  cutoff: document.getElementById("settingsCutoff"),
  example: document.getElementById("settingsWindowExample"),
  status: document.getElementById("settingsStatus"),
  save: document.getElementById("settingsSave"),
  reload: document.getElementById("settingsReload")
};

let currentCutoff = null;
let busy = false;

function isValidTime(value) {
  return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || "");
}

function formatTime(value) {
  const [hour, minute] = value.split(":").map(Number);
  return `${hour % 12 || 12}:${String(minute).padStart(2, "0")} ${hour < 12 ? "a. m." : "p. m."}`;
}

function setStatus(message, isError = false) {
  elements.status.textContent = message;
  elements.status.classList.toggle("is-error", isError);
}

function updateControls() {
  elements.form.setAttribute("aria-busy", String(busy));
  elements.cutoff.disabled = busy || currentCutoff === null;
  elements.save.disabled = busy || currentCutoff === null
    || !isValidTime(elements.cutoff.value);
  elements.reload.disabled = busy;
}

function renderExample() {
  if (!isValidTime(elements.cutoff.value)) {
    elements.example.textContent = "Selecciona una hora para ver el horario de la jornada.";
    return;
  }

  const time = formatTime(elements.cutoff.value);
  elements.example.textContent = `De las ${time} del día anterior a las ${time} del día de la jornada.`;
}

function renderSettings(data, preserveDraft = false) {
  if (!isValidTime(data?.cutoff)) {
    throw new Error("No se pudo leer el horario vigente. Vuelve a cargar la configuración.");
  }

  currentCutoff = data.cutoff;
  elements.company.textContent = data.company_name || "Tu empresa";
  elements.currentCutoff.textContent = `${formatTime(currentCutoff)} (${currentCutoff})`;
  elements.timezone.textContent = data.timezone || "—";
  if (!preserveDraft) elements.cutoff.value = currentCutoff;
  elements.reload.hidden = true;
  renderExample();
}

function recalculationSummary(data) {
  const summary = data?.reclassified;
  if (!summary || typeof summary !== "object") {
    return "Horario guardado y jornadas recalculadas correctamente.";
  }

  const count = (key) => Math.max(0, Number(summary[key]) || 0);
  const tickets = count("tickets") + count("product_tickets") + count("synced_tickets");
  const receptions = count("reception_weighings");
  const journeys = count("journeys");
  const details = [];
  if (tickets) details.push(`${tickets} ${tickets === 1 ? "ticket o registro actualizado" : "tickets y registros actualizados"}`);
  if (receptions) details.push(`${receptions} ${receptions === 1 ? "pesada de recepción actualizada" : "pesadas de recepción actualizadas"}`);
  if (journeys) details.push(`${journeys} ${journeys === 1 ? "jornada actualizada" : "jornadas actualizadas"}`);
  if (details.length) return `Recalculación completada: ${details.join(", ")}.`;
  if (Object.keys(summary).some((key) => count(key) > 0)) {
    return "Horario guardado. Se actualizaron los registros relacionados con las jornadas.";
  }
  return "Horario guardado. El historial ya coincide con este horario.";
}

async function loadSettings() {
  if (busy) return;
  busy = true;
  currentCutoff = null;
  setStatus("Cargando configuración...");
  updateControls();

  try {
    const response = await apiRequest("/configuracion-general");
    renderSettings(response.data);
    setStatus("Guarda para recalcular las jornadas de todas las sucursales, incluidos los tickets anteriores.");
  } catch (error) {
    elements.reload.hidden = false;
    elements.currentCutoff.textContent = "No disponible";
    elements.company.textContent = "—";
    elements.timezone.textContent = "—";
    elements.example.textContent = "Vuelve a cargar la configuración para consultar el horario.";
    setStatus(error.message, true);
  } finally {
    busy = false;
    updateControls();
  }
}

async function saveSettings(event) {
  event.preventDefault();
  if (busy || currentCutoff === null || !elements.form.reportValidity()) return;
  if (!isValidTime(elements.cutoff.value)) return;

  const cutoff = elements.cutoff.value;
  busy = true;
  elements.save.textContent = "Recalculando jornadas...";
  setStatus("Recalculando jornadas y tickets anteriores. Espera a que termine el proceso.");
  updateControls();

  try {
    const response = await apiRequest("/configuracion-general", {
      method: "PUT",
      body: JSON.stringify({ cutoff, expected_cutoff: currentCutoff })
    });
    renderSettings(response.data);
    setStatus(recalculationSummary(response.data));
  } catch (error) {
    const validationMessage = Object.values(error.data?.errors || {})
      .flat().filter((message) => typeof message === "string").join(" ");
    const conflict = Boolean(error.data?.errors?.expected_cutoff);
    if (conflict) {
      try {
        const response = await apiRequest("/configuracion-general");
        renderSettings(response.data, true);
        setStatus(`${validationMessage || error.message} Se actualizó la hora vigente. Revisa tu selección antes de guardar nuevamente.`, true);
      } catch {
        currentCutoff = null;
        elements.reload.hidden = false;
        setStatus("El horario cambió en otra sesión. Vuelve a cargar la configuración antes de guardar.", true);
      }
    } else if (!error.status || error.status >= 500) {
      currentCutoff = null;
      elements.reload.hidden = false;
      elements.currentCutoff.textContent = "Pendiente de confirmar";
      setStatus("No se pudo confirmar el resultado del recálculo. Vuelve a cargar la configuración para consultar el horario vigente antes de guardar otra vez.", true);
    } else {
      setStatus(validationMessage || error.message, true);
    }
  } finally {
    busy = false;
    elements.save.textContent = "Guardar y recalcular jornadas";
    updateControls();
  }
}

elements.cutoff.addEventListener("input", () => {
  renderExample();
  updateControls();
  setStatus(elements.cutoff.value === currentCutoff
    ? "Este horario ya está vigente. Puedes guardarlo para recalcular el historial."
    : "Hay cambios sin guardar.");
});
elements.form.addEventListener("submit", saveSettings);
elements.reload.addEventListener("click", loadSettings);

loadSettings();
