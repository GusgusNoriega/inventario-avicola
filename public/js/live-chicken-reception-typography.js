import { normalizeTypographyValue } from "./despacho-productos-typography.js";
import { RECEPTION_TYPOGRAPHY_GROUPS } from "./live-chicken-reception-typography-catalog.js";

export { normalizeTypographyValue };
export const RECEPTION_TYPOGRAPHY_VERSION = 1;
export const RECEPTION_TYPOGRAPHY_PRESETS = {
  compact: { label: "Compacta", factor: 0.9 },
  standard: { label: "Original", factor: 1 },
  large: { label: "Grande", factor: 1.2 },
  accessible: { label: "Muy grande", factor: 1.35, readableFloor: 14 },
};

export function flattenTypographyControls(groups = []) {
  return (Array.isArray(groups) ? groups : [])
    .flatMap((group) => Array.isArray(group?.controls) ? group.controls : [])
    .filter((control) => /^--lir-fs-[a-z0-9-]+$/.test(control?.variable || ""));
}

export function defaultTypographyValues(groups = []) {
  return Object.fromEntries(flattenTypographyControls(groups).map((control) => [
    control.variable, normalizeTypographyValue(control, control.defaultValue),
  ]));
}

export function resolveTypographyGroups(groups, readFontSize) {
  return groups.map((group) => ({
    ...group,
    controls: group.controls.map((control) => {
      const measured = control.selector ? Number.parseFloat(readFontSize(control.selector)) : NaN;
      return { ...control, defaultValue: normalizeTypographyValue(control, Number.isFinite(measured) ? measured : control.defaultValue) };
    }),
  }));
}

export function sanitizeTypographyValues(groups = [], values = {}) {
  const source = values && typeof values === "object" && !Array.isArray(values) ? values : {};
  return Object.fromEntries(flattenTypographyControls(groups).map((control) => [
    control.variable,
    normalizeTypographyValue(control, Object.hasOwn(source, control.variable) ? source[control.variable] : control.defaultValue),
  ]));
}

export function buildTypographyPresetValues(groups = [], { factor = 1, readableFloor = 0 } = {}) {
  const scale = Number.isFinite(Number(factor)) && Number(factor) > 0 ? Number(factor) : 1;
  const floor = Number.isFinite(Number(readableFloor)) ? Number(readableFloor) : 0;
  return Object.fromEntries(flattenTypographyControls(groups).map((control) => [
    control.variable, normalizeTypographyValue(control, Math.max(control.defaultValue * scale, floor)),
  ]));
}

export function serializeTypographyPreferences(groups, values, preset = "custom") {
  const defaults = defaultTypographyValues(groups);
  const overrides = Object.fromEntries(Object.entries(sanitizeTypographyValues(groups, values))
    .filter(([variable, value]) => value !== defaults[variable]));
  return JSON.stringify({ version: RECEPTION_TYPOGRAPHY_VERSION, preset, values: overrides });
}

export function parseTypographyPreferences(groups, serialized = null) {
  const fallback = { valid: true, preset: "standard", values: defaultTypographyValues(groups) };
  if (serialized === null || serialized === undefined || serialized === "") return fallback;
  try {
    const data = typeof serialized === "string" ? JSON.parse(serialized) : serialized;
    if (!data || data.version !== RECEPTION_TYPOGRAPHY_VERSION || !data.values
      || typeof data.values !== "object" || Array.isArray(data.values)) return { ...fallback, valid: false };
    return { valid: true, preset: typeof data.preset === "string" ? data.preset : "custom", values: sanitizeTypographyValues(groups, data.values) };
  } catch {
    return { ...fallback, valid: false };
  }
}

const searchText = (value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
export function filterTypographyGroups(groups, query = "") {
  const words = searchText(query).trim().split(/\s+/).filter(Boolean);
  return groups.map((group) => ({
    ...group,
    controls: group.controls.filter((control) => {
      const text = searchText(`${group.title} ${group.description || ""} ${control.label} ${control.description || ""}`);
      return words.every((word) => text.includes(word));
    }),
  })).filter((group) => group.controls.length);
}

export function typographyStorageKey(userId) {
  return `sistema-pollos-live-chicken-reception-typography-v1-user-${String(userId || "guest")}`;
}

// Only this view's whitelisted font variables and browser preference are modified.
export function createReceptionTypographyPreferences(groups, {
  userId, storage, style, onChange = () => {}, onStatus = () => {},
  setTimer = globalThis.setTimeout, clearTimer = globalThis.clearTimeout,
} = {}) {
  const controls = flattenTypographyControls(groups);
  const byVariable = new Map(controls.map((control) => [control.variable, control]));
  const defaults = defaultTypographyValues(groups);
  const storageKey = typographyStorageKey(userId);
  let values = { ...defaults };
  let timer = null;
  let dirty = false;
  const preset = () => Object.keys(RECEPTION_TYPOGRAPHY_PRESETS).find((id) => {
    const expected = buildTypographyPresetValues(groups, RECEPTION_TYPOGRAPHY_PRESETS[id]);
    return controls.every(({ variable }) => values[variable] === expected[variable]);
  }) || "custom";
  const cancelPending = () => {
    if (timer !== null) clearTimer(timer);
    timer = null;
    dirty = false;
  };
  const apply = () => {
    controls.forEach(({ variable }) => {
      // Removing defaults preserves the original responsive rem/clamp sizes.
      if (values[variable] === defaults[variable]) style?.removeProperty(variable);
      else style?.setProperty(variable, `${values[variable]}px`);
    });
    onChange({ ...values });
  };
  const unavailable = () => onStatus("Activos solo durante esta visita: el navegador no permite guardarlos.", "error");
  const flush = () => {
    if (!dirty) return;
    cancelPending();
    try {
      if (!storage) throw new Error("Storage unavailable");
      storage.setItem(storageKey, serializeTypographyPreferences(groups, values, preset()));
      onStatus("Guardado en este navegador", "saved");
    } catch { unavailable(); }
  };
  const changed = () => {
    apply();
    if (timer !== null) clearTimer(timer);
    dirty = true;
    onStatus("Guardando…", "saving");
    timer = setTimer(flush, 180);
  };
  const setValue = (variable, value) => {
    const control = byVariable.get(variable);
    if (!control) return;
    const normalized = normalizeTypographyValue(control, value);
    if (values[variable] === normalized) return;
    values[variable] = normalized;
    changed();
  };
  const reload = (fallback = null) => {
    cancelPending();
    let serialized = fallback;
    let readable = true;
    try {
      if (!storage) throw new Error("Storage unavailable");
      // Storage events can arrive after another tab has written again.
      serialized = storage.getItem(storageKey);
    } catch { readable = false; }
    const parsed = parseTypographyPreferences(groups, serialized);
    values = parsed.values;
    apply();
    if (!readable) unavailable();
    else onStatus(parsed.valid ? "Actualizado desde otra pestaña" : "Preferencias dañadas: se usan los tamaños originales.", parsed.valid ? "saved" : "error");
  };
  try {
    if (!storage) throw new Error("Storage unavailable");
    const parsed = parseTypographyPreferences(groups, storage.getItem(storageKey));
    values = parsed.values;
    onStatus(parsed.valid ? "Guardado en este navegador" : "Preferencias dañadas: se usan los tamaños originales.", parsed.valid ? "saved" : "error");
  } catch { unavailable(); }
  apply();

  return {
    storageKey,
    get values() { return { ...values }; },
    get preset() { return preset(); },
    setValue,
    resetControl(variable) { if (byVariable.has(variable)) setValue(variable, defaults[variable]); },
    resetGroup(groupId) {
      const group = groups.find(({ id }) => id === groupId);
      if (!group) return;
      group.controls.forEach(({ variable }) => { if (byVariable.has(variable)) values[variable] = defaults[variable]; });
      changed();
    },
    resetAll() {
      cancelPending();
      values = { ...defaults };
      apply();
      try {
        if (!storage) throw new Error("Storage unavailable");
        storage.removeItem(storageKey);
        onStatus("Tamaños originales restablecidos", "saved");
      } catch { unavailable(); }
    },
    applyPreset(id) {
      if (!Object.hasOwn(RECEPTION_TYPOGRAPHY_PRESETS, id)) return;
      values = buildTypographyPresetValues(groups, RECEPTION_TYPOGRAPHY_PRESETS[id]);
      changed();
    },
    flush,
    reload() { reload(serializeTypographyPreferences(groups, values, preset())); },
    syncStorage(event) {
      if (event.key !== null && event.key !== storageKey) return;
      if (event.storageArea && event.storageArea !== storage) return;
      reload(event.key === null ? null : event.newValue);
    },
  };
}

const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);

export function initializeReceptionTypography({ document: doc = globalThis.document, window: win = globalThis.window, beforeOpen = () => {} } = {}) {
  const get = (suffix) => doc.getElementById(`liveIntakeTypography${suffix}`);
  const panel = get("Panel");
  const trigger = doc.getElementById("liveIntakeOpenTypography");
  if (!panel || !trigger) return null;
  // Measure the unmodified view so Compacta also reduces responsive text on small screens.
  const groups = resolveTypographyGroups(RECEPTION_TYPOGRAPHY_GROUPS, (selector) => {
    const target = doc.querySelector(selector);
    return target ? win.getComputedStyle(target).fontSize : null;
  });
  const controls = flattenTypographyControls(groups);
  const byVariable = new Map(controls.map((control) => [control.variable, control]));
  const container = get("Controls");
  const search = get("Search");
  let currentVariable = controls[0]?.variable;
  let preferences;
  let highlightTimer;
  const clearHighlight = () => {
    win.clearTimeout(highlightTimer);
    doc.querySelectorAll(".lir-typography-highlight").forEach((element) => element.classList.remove("lir-typography-highlight"));
  };
  const preview = (variable, highlight = false) => {
    const control = byVariable.get(variable);
    if (!control || !preferences) return;
    currentVariable = variable;
    get("PreviewLabel").textContent = control.label;
    get("PreviewValue").textContent = control.preview || "Aa 123,45";
    get("PreviewValue").style.fontSize = `${preferences.values[variable]}px`;
    get("PreviewSize").textContent = `${preferences.values[variable]} px`;
    if (!highlight) return;
    clearHighlight();
    if (control.selector) doc.querySelectorAll(control.selector).forEach((element) => {
      if (!panel.contains(element)) element.classList.add("lir-typography-highlight");
    });
    highlightTimer = win.setTimeout(clearHighlight, 1000);
  };
  const sync = (values) => {
    if (!preferences) return;
    container.querySelectorAll("[data-font-value]").forEach((input) => {
      // Keep an unfinished numeric entry (e.g. 12.) intact until change/blur.
      if (input !== doc.activeElement || input.type !== "number") input.value = values[input.dataset.fontValue];
      input.setAttribute("aria-valuetext", `${values[input.dataset.fontValue]} píxeles`);
    });
    container.querySelectorAll("[data-font-output]").forEach((output) => { output.textContent = `${values[output.dataset.fontOutput]} px`; });
    container.querySelectorAll("[data-font-row]").forEach((row) => row.classList.toggle("is-small", values[row.dataset.fontRow] < 10));
    const count = controls.filter((control) => values[control.variable] !== normalizeTypographyValue(control, control.defaultValue)).length;
    const name = RECEPTION_TYPOGRAPHY_PRESETS[preferences.preset]?.label || "Personalizada";
    get("Profile").textContent = name;
    get("Summary").textContent = `${name} · ${count} de ${controls.length} tamaños ajustados`;
    panel.querySelectorAll("[data-font-preset]").forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.fontPreset === preferences.preset)));
    preview(currentVariable);
  };
  let storage;
  try { storage = win.localStorage; } catch { /* The preferences remain usable without storage. */ }
  preferences = createReceptionTypographyPreferences(groups, {
    userId: doc.getElementById("liveIntakeMain")?.dataset.liveUserId,
    storage,
    style: doc.documentElement.style,
    onChange: sync,
    onStatus(message, tone) {
      get("SaveStatus").textContent = message;
      get("SaveStatus").dataset.tone = tone;
    },
  });
  const controlMarkup = (control) => {
    const variable = escapeHtml(control.variable);
    const label = escapeHtml(control.label);
    const value = preferences.values[control.variable];
    return `<div class="lir-typography-control" data-font-row="${variable}">
      <div class="lir-typography-control-head"><label for="${variable}-range">${label}</label><output data-font-output="${variable}">${value} px</output></div>
      ${control.description ? `<small>${escapeHtml(control.description)}</small>` : ""}
      <div class="lir-typography-inputs">
        <button type="button" data-font-step="-1" data-font-variable="${variable}" aria-label="Reducir ${label}">−</button>
        <input id="${variable}-range" type="range" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" data-font-value="${variable}">
        <label class="lir-typography-number"><input type="number" min="${control.min}" max="${control.max}" step="${control.step}" value="${value}" data-font-value="${variable}" aria-label="${label}, tamaño en píxeles"><span>px</span></label>
        <button type="button" data-font-step="1" data-font-variable="${variable}" aria-label="Aumentar ${label}">+</button>
        <button type="button" data-font-reset="${variable}" aria-label="Restablecer ${label}" title="Tamaño original">↺</button>
      </div><small class="lir-typography-warning">Este tamaño puede ser difícil de leer.</small>
    </div>`;
  };
  const expanded = new Set([groups[0]?.id]);
  const render = () => {
    const found = filterTypographyGroups(groups, search.value);
    container.innerHTML = found.map((group) => `<details class="lir-typography-group" data-font-group="${escapeHtml(group.id)}" ${search.value.trim() || expanded.has(group.id) ? "open" : ""}>
      <summary><span><b>${escapeHtml(group.title)}</b><small>${group.controls.length} ajustes</small></span></summary>
      <div class="lir-typography-group-body"><button type="button" data-font-reset-group="${escapeHtml(group.id)}">Restablecer este grupo</button>
      ${group.controls.map(controlMarkup).join("")}</div></details>`).join("") || '<p class="lir-typography-empty">No hay textos que coincidan. Prueba con «balanza», «columna 1» o «javas».</p>';
    container.querySelectorAll("[data-font-group]").forEach((detail) => detail.addEventListener("toggle", () => {
      if (!search.value.trim()) {
        if (detail.open) expanded.add(detail.dataset.fontGroup);
        else expanded.delete(detail.dataset.fontGroup);
      }
    }));
    sync(preferences.values);
  };
  const main = doc.getElementById("liveIntakeMain");
  const mobilePanel = win.matchMedia("(max-width: 520px)");
  const syncPanelMode = () => {
    const covering = !panel.hidden && mobilePanel.matches;
    panel.setAttribute("aria-modal", String(covering));
    doc.body.classList.toggle("lir-typography-covering", covering);
    main.inert = covering || Boolean(doc.querySelector(".lir-modal:not([hidden])"));
  };
  const close = ({ restoreFocus = true } = {}) => {
    if (panel.hidden) return;
    preferences.flush();
    clearHighlight();
    panel.hidden = true;
    panel.setAttribute("aria-hidden", "true");
    trigger.setAttribute("aria-expanded", "false");
    syncPanelMode();
    if (restoreFocus) doc.getElementById("liveIntakeOpenSettings")?.focus({ preventScroll: true });
  };
  const open = () => {
    beforeOpen();
    panel.hidden = false;
    panel.setAttribute("aria-hidden", "false");
    trigger.setAttribute("aria-expanded", "true");
    syncPanelMode();
    render();
    search.focus({ preventScroll: true });
  };
  trigger.addEventListener("click", open);
  panel.querySelectorAll("[data-font-close]").forEach((button) => button.addEventListener("click", () => close()));
  search.addEventListener("input", render);
  panel.addEventListener("keydown", (event) => {
    if (event.key === "Escape") { event.preventDefault(); event.stopPropagation(); close(); }
    if (event.key === "Tab" && mobilePanel.matches) {
      const focusable = Array.from(panel.querySelectorAll("button,input,summary"))
        .filter((element) => !element.disabled && element.getClientRects().length);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  mobilePanel.addEventListener("change", () => { if (!panel.hidden) syncPanelMode(); });
  panel.addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    const { fontStep, fontVariable, fontReset, fontResetGroup, fontPreset, fontExpand } = button.dataset;
    if (fontStep && byVariable.has(fontVariable)) {
      preferences.setValue(fontVariable, preferences.values[fontVariable] + Number(fontStep) * byVariable.get(fontVariable).step);
      preview(fontVariable, true);
    } else if (fontReset) { preferences.resetControl(fontReset); preview(fontReset, true); }
    else if (fontResetGroup) preferences.resetGroup(fontResetGroup);
    else if (fontPreset) preferences.applyPreset(fontPreset);
    else if (button.hasAttribute("data-font-reset-all")) preferences.resetAll();
    else if (fontExpand) container.querySelectorAll("details").forEach((detail) => { detail.open = fontExpand === "all"; });
  });
  const updateInput = (event) => {
    const input = event.target;
    const variable = input.dataset.fontValue;
    const control = byVariable.get(variable);
    if (!control) return;
    if (event.type === "input" && input.type === "number"
      && (input.value.trim() === "" || !Number.isFinite(Number(input.value)) || Number(input.value) < control.min || Number(input.value) > control.max)) return;
    preferences.setValue(variable, input.value);
    if (event.type === "change") input.value = preferences.values[variable];
    preview(variable, true);
  };
  container.addEventListener("input", updateInput);
  container.addEventListener("change", updateInput);
  container.addEventListener("focusin", (event) => {
    const row = event.target.closest("[data-font-row]");
    if (row) preview(row.dataset.fontRow, true);
  });
  win.addEventListener("storage", (event) => preferences.syncStorage(event));
  win.addEventListener("pagehide", () => preferences.flush());
  win.addEventListener("pageshow", (event) => { if (event.persisted) preferences.reload(); });
  doc.addEventListener("visibilitychange", () => { if (doc.visibilityState === "hidden") preferences.flush(); });
  // Regular dialogs retain their existing focus/scroll behavior; this sidebar is nonmodal.
  if (win.MutationObserver) {
    const observer = new win.MutationObserver(() => {
      if (!panel.hidden && doc.querySelector(".lir-modal:not([hidden])")) close({ restoreFocus: false });
    });
    doc.querySelectorAll(".lir-modal").forEach((modal) => observer.observe(modal, { attributes: true, attributeFilter: ["hidden"] }));
  }
  render();
  return { preferences, open, close };
}
