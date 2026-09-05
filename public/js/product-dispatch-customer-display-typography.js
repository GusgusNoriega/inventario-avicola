import { normalizeTypographyValue } from "./despacho-productos-typography.js";
import { PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS } from "./product-dispatch-customer-display-typography-catalog.js";

export const PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_VERSION = 1;
export const PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS = {
  compact: { label: "Pequeña", factor: 0.86 },
  standard: { label: "Original", factor: 1 },
  large: { label: "Grande", factor: 1.2 },
  accessible: { label: "Máxima", factor: 1.38, readableFloor: 14 }
};

export function flattenProductCustomerDisplayTypographyControls(groups = []) {
  return (Array.isArray(groups) ? groups : [])
    .flatMap((group) => Array.isArray(group?.controls) ? group.controls : [])
    .filter((control) => /^--pdcd-fs-[a-z0-9-]+$/.test(control?.variable || ""));
}

export function defaultProductCustomerDisplayTypographyValues(groups = []) {
  return Object.fromEntries(
    flattenProductCustomerDisplayTypographyControls(groups).map((control) => [
      control.variable,
      normalizeTypographyValue(control, control.defaultValue)
    ])
  );
}

export function resolveProductCustomerDisplayTypographyGroups(groups = [], readFontSize = () => null) {
  return groups.map((group) => ({
    ...group,
    controls: group.controls.map((control) => {
      const measured = control.selector
        ? Number.parseFloat(readFontSize(control.selector))
        : Number.NaN;
      return {
        ...control,
        defaultValue: normalizeTypographyValue(
          control,
          Number.isFinite(measured) ? measured : control.defaultValue
        )
      };
    })
  }));
}

export function sanitizeProductCustomerDisplayTypographyValues(groups = [], values = {}) {
  const source = values && typeof values === "object" && !Array.isArray(values) ? values : {};
  return Object.fromEntries(
    flattenProductCustomerDisplayTypographyControls(groups).map((control) => [
      control.variable,
      normalizeTypographyValue(
        control,
        Object.hasOwn(source, control.variable) ? source[control.variable] : control.defaultValue
      )
    ])
  );
}

export function buildProductCustomerDisplayTypographyPresetValues(
  groups = [],
  { factor = 1, readableFloor = 0 } = {}
) {
  const scale = Number.isFinite(Number(factor)) && Number(factor) > 0 ? Number(factor) : 1;
  const floor = Number.isFinite(Number(readableFloor)) ? Number(readableFloor) : 0;
  return Object.fromEntries(
    flattenProductCustomerDisplayTypographyControls(groups).map((control) => [
      control.variable,
      normalizeTypographyValue(control, Math.max(control.defaultValue * scale, floor))
    ])
  );
}

export function serializeProductCustomerDisplayTypographyPreferences(
  groups = [],
  values = {},
  preset = "custom",
  overrideVariables = null
) {
  const defaults = defaultProductCustomerDisplayTypographyValues(groups);
  const safePreset = Object.hasOwn(PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS, preset)
    ? preset
    : "custom";
  const explicitOverrides = Array.isArray(overrideVariables) || overrideVariables instanceof Set
    ? new Set(overrideVariables)
    : null;
  const overrides = safePreset === "custom"
    ? Object.fromEntries(
      Object.entries(sanitizeProductCustomerDisplayTypographyValues(groups, values))
        .filter(([variable, value]) => explicitOverrides
          ? explicitOverrides.has(variable)
          : value !== defaults[variable])
    )
    : {};
  return JSON.stringify({
    version: PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_VERSION,
    preset: safePreset,
    values: overrides
  });
}

export function parseProductCustomerDisplayTypographyPreferences(groups = [], serialized = null) {
  const fallback = {
    valid: true,
    preset: "standard",
    overrideVariables: [],
    values: defaultProductCustomerDisplayTypographyValues(groups)
  };
  if (serialized === null || serialized === undefined || serialized === "") return fallback;

  try {
    const parsed = typeof serialized === "string" ? JSON.parse(serialized) : serialized;
    if (
      !parsed
      || typeof parsed !== "object"
      || Array.isArray(parsed)
      || parsed.version !== PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_VERSION
      || !parsed.values
      || typeof parsed.values !== "object"
      || Array.isArray(parsed.values)
    ) return { ...fallback, valid: false };

    const parsedPreset = Object.hasOwn(PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS, parsed.preset)
      ? parsed.preset
      : "custom";
    const allowedVariables = new Set(
      flattenProductCustomerDisplayTypographyControls(groups).map(({ variable }) => variable)
    );
    const overrideVariables = parsedPreset === "custom"
      ? Object.keys(parsed.values).filter((variable) => allowedVariables.has(variable))
      : [];
    const preset = parsedPreset === "custom" && overrideVariables.length === 0
      ? "standard"
      : parsedPreset;
    return {
      valid: true,
      preset,
      overrideVariables,
      values: preset === "custom"
        ? sanitizeProductCustomerDisplayTypographyValues(groups, parsed.values)
        : buildProductCustomerDisplayTypographyPresetValues(
          groups,
          PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS[preset]
        )
    };
  } catch {
    return { ...fallback, valid: false };
  }
}

function searchableText(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es");
}

export function filterProductCustomerDisplayTypographyGroups(groups = [], query = "") {
  const words = searchableText(query).trim().split(/\s+/).filter(Boolean);
  return groups.map((group) => ({
    ...group,
    controls: group.controls.filter((control) => {
      const text = searchableText(
        `${group.title} ${group.description || ""} ${control.label} ${control.description || ""}`
      );
      return words.every((word) => text.includes(word));
    })
  })).filter((group) => group.controls.length > 0);
}

function scopeToken(value) {
  return encodeURIComponent(String(value || "sin-id").trim().slice(0, 80) || "sin-id");
}

export function productCustomerDisplayTypographyStorageKey(branchId, userId) {
  return [
    "sistema-pollos-product-dispatch-customer-display-typography-v1",
    `branch-${scopeToken(branchId)}`,
    `user-${scopeToken(userId)}`
  ].join(":");
}

export function createProductCustomerDisplayTypographyPreferences(initialGroups = [], {
  branchId,
  userId,
  storage,
  style,
  onChange = () => {},
  onStatus = () => {},
  setTimer = globalThis.setTimeout,
  clearTimer = globalThis.clearTimeout
} = {}) {
  let groups = initialGroups;
  let controls = flattenProductCustomerDisplayTypographyControls(groups);
  let byVariable = new Map(controls.map((control) => [control.variable, control]));
  let defaults = defaultProductCustomerDisplayTypographyValues(groups);
  const storageKey = productCustomerDisplayTypographyStorageKey(branchId, userId);
  let values = { ...defaults };
  let activePreset = "standard";
  let explicitOverrides = new Set();
  let timer = null;
  let dirty = false;

  const currentPreset = () => activePreset;

  const startCustom = () => {
    if (activePreset === "custom") return;
    explicitOverrides = activePreset === "standard"
      ? new Set()
      : new Set(controls.map(({ variable }) => variable));
    activePreset = "custom";
  };

  const cancelPending = () => {
    if (timer !== null) clearTimer(timer);
    timer = null;
    dirty = false;
  };

  const apply = () => {
    controls.forEach(({ variable }) => {
      if (values[variable] === defaults[variable]) style?.removeProperty(variable);
      else style?.setProperty(variable, `${values[variable]}px`);
    });
    onChange({ ...values });
  };

  const storageUnavailable = () => {
    onStatus("Cambios activos sólo durante esta visita", "error");
  };

  const flush = () => {
    if (!dirty) return;
    cancelPending();
    try {
      if (!storage) throw new Error("Storage unavailable");
      storage.setItem(
        storageKey,
        serializeProductCustomerDisplayTypographyPreferences(
          groups,
          values,
          currentPreset(),
          explicitOverrides
        )
      );
      onStatus("Guardado", "saved");
    } catch {
      storageUnavailable();
    }
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
    startCustom();
    values[variable] = normalized;
    explicitOverrides.add(variable);
    changed();
  };

  const reload = (fallback = null) => {
    cancelPending();
    let serialized = fallback;
    let readable = true;
    try {
      if (!storage) throw new Error("Storage unavailable");
      serialized = storage.getItem(storageKey);
    } catch {
      readable = false;
    }
    const parsed = parseProductCustomerDisplayTypographyPreferences(groups, serialized);
    values = parsed.values;
    activePreset = parsed.preset;
    explicitOverrides = new Set(parsed.overrideVariables);
    apply();
    if (!readable) storageUnavailable();
    else if (!parsed.valid) onStatus("Se recuperaron los tamaños originales", "error");
    else onStatus("Actualizado", "saved");
  };

  try {
    if (!storage) throw new Error("Storage unavailable");
    const parsed = parseProductCustomerDisplayTypographyPreferences(
      groups,
      storage.getItem(storageKey)
    );
    values = parsed.values;
    activePreset = parsed.preset;
    explicitOverrides = new Set(parsed.overrideVariables);
    onStatus(parsed.valid ? "Guardado en este equipo" : "Se recuperaron los tamaños originales", parsed.valid ? "saved" : "error");
  } catch {
    storageUnavailable();
  }
  apply();

  return {
    storageKey,
    get values() { return { ...values }; },
    get preset() { return currentPreset(); },
    get overrideVariables() { return [...explicitOverrides]; },
    setValue,
    resetControl(variable) {
      if (!byVariable.has(variable)) return;
      startCustom();
      explicitOverrides.delete(variable);
      values[variable] = defaults[variable];
      if (explicitOverrides.size === 0) activePreset = "standard";
      changed();
    },
    resetGroup(groupId) {
      const group = groups.find((candidate) => candidate.id === groupId);
      if (!group) return;
      startCustom();
      group.controls.forEach(({ variable }) => {
        if (!byVariable.has(variable)) return;
        explicitOverrides.delete(variable);
        values[variable] = defaults[variable];
      });
      if (explicitOverrides.size === 0) activePreset = "standard";
      changed();
    },
    resetAll() {
      cancelPending();
      values = { ...defaults };
      activePreset = "standard";
      explicitOverrides.clear();
      apply();
      try {
        if (!storage) throw new Error("Storage unavailable");
        storage.removeItem(storageKey);
        onStatus("Tamaños originales", "saved");
      } catch {
        storageUnavailable();
      }
    },
    applyPreset(presetId) {
      if (!Object.hasOwn(PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS, presetId)) return;
      values = buildProductCustomerDisplayTypographyPresetValues(
        groups,
        PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS[presetId]
      );
      activePreset = presetId;
      explicitOverrides.clear();
      changed();
    },
    rebase(nextGroups) {
      const previousValues = values;
      const previousOverrides = new Set(explicitOverrides);
      groups = nextGroups;
      controls = flattenProductCustomerDisplayTypographyControls(groups);
      byVariable = new Map(controls.map((control) => [control.variable, control]));
      defaults = defaultProductCustomerDisplayTypographyValues(groups);

      if (Object.hasOwn(PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS, activePreset)) {
        values = buildProductCustomerDisplayTypographyPresetValues(
          groups,
          PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS[activePreset]
        );
      } else {
        values = { ...defaults };
        controls.forEach((control) => {
          if (previousOverrides.has(control.variable)) {
            values[control.variable] = normalizeTypographyValue(
              control,
              previousValues[control.variable]
            );
          }
        });
        explicitOverrides = new Set(
          controls
            .map(({ variable }) => variable)
            .filter((variable) => previousOverrides.has(variable))
        );
      }
      apply();
    },
    flush,
    reload() {
      reload(serializeProductCustomerDisplayTypographyPreferences(
        groups,
        values,
        currentPreset(),
        explicitOverrides
      ));
    },
    syncStorage(event) {
      if (event.key !== null && event.key !== storageKey) return;
      if (event.storageArea && event.storageArea !== storage) return;
      reload(event.key === null ? null : event.newValue);
    }
  };
}

function createElement(document, tagName, className = "", text = "") {
  const element = document.createElement(tagName);
  if (className) element.className = className;
  if (text) element.textContent = text;
  return element;
}

function createActionButton(document, text, label) {
  const button = createElement(document, "button", "", text);
  button.type = "button";
  if (label) button.setAttribute("aria-label", label);
  return button;
}

export function initializeProductCustomerDisplayTypography({
  document = globalThis.document,
  window = globalThis.window,
  branchId = "",
  userId = "",
  enabled = true,
  beforeOpen = () => {}
} = {}) {
  const get = (suffix) => document.getElementById(`productCustomerDisplayTypography${suffix}`);
  const panel = get("Panel");
  const trigger = document.getElementById("productCustomerDisplayOpenTypography");
  const container = get("Controls");
  const search = get("Search");
  if (!panel || !trigger || !container || !search) return null;
  if (!enabled) {
    trigger.disabled = true;
    trigger.setAttribute("aria-disabled", "true");
    return null;
  }

  const rootStyle = document.documentElement.style;
  const catalogControls = flattenProductCustomerDisplayTypographyControls(
    PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS
  );
  const measureGroups = () => {
    const previous = catalogControls.map(({ variable }) => ({
      variable,
      value: rootStyle.getPropertyValue(variable),
      priority: rootStyle.getPropertyPriority(variable)
    }));
    previous.forEach(({ variable }) => rootStyle.removeProperty(variable));
    try {
      return resolveProductCustomerDisplayTypographyGroups(
        PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS,
        (selector) => {
          const target = document.querySelector(selector);
          return target ? window.getComputedStyle(target).fontSize : null;
        }
      );
    } finally {
      previous.forEach(({ variable, value, priority }) => {
        if (value) rootStyle.setProperty(variable, value, priority);
      });
    }
  };
  let groups = measureGroups();
  let controls = flattenProductCustomerDisplayTypographyControls(groups);
  let byVariable = new Map(controls.map((control) => [control.variable, control]));
  const expandedGroups = new Set(groups.filter((group) => group.open).map((group) => group.id));
  let currentVariable = controls[0]?.variable || "";
  let preferences = null;
  let highlightTimer = null;
  let remeasureTimer = null;

  const clearHighlight = () => {
    window.clearTimeout(highlightTimer);
    document.querySelectorAll(".pdcd-typography-highlight").forEach((element) => {
      element.classList.remove("pdcd-typography-highlight");
    });
  };

  const preview = (variable, highlight = false) => {
    const control = byVariable.get(variable);
    if (!control || !preferences) return;
    currentVariable = variable;
    get("PreviewLabel").textContent = control.label;
    get("PreviewValue").textContent = control.preview || "Aa 123.45";
    get("PreviewValue").style.fontSize = `${Math.min(72, Math.max(16, preferences.values[variable]))}px`;
    get("PreviewSize").textContent = `${preferences.values[variable]} px`;
    if (!highlight || !control.selector) return;

    clearHighlight();
    document.querySelectorAll(control.selector).forEach((element) => {
      if (!panel.contains(element)) element.classList.add("pdcd-typography-highlight");
    });
    highlightTimer = window.setTimeout(clearHighlight, 900);
  };

  const sync = (values) => {
    if (!preferences) return;
    container.querySelectorAll("[data-pdcd-font-value]").forEach((input) => {
      const variable = input.dataset.pdcdFontValue;
      if (input !== document.activeElement || input.type !== "number") input.value = values[variable];
      input.setAttribute("aria-valuetext", `${values[variable]} píxeles`);
    });
    container.querySelectorAll("[data-pdcd-font-output]").forEach((output) => {
      output.textContent = `${values[output.dataset.pdcdFontOutput]} px`;
    });
    container.querySelectorAll("[data-pdcd-font-row]").forEach((row) => {
      row.classList.toggle("is-small", values[row.dataset.pdcdFontRow] < 12);
    });

    const defaults = defaultProductCustomerDisplayTypographyValues(groups);
    const changed = preferences.preset === "custom"
      ? preferences.overrideVariables.length
      : controls.filter(({ variable }) => values[variable] !== defaults[variable]).length;
    const presetLabel = PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS[preferences.preset]?.label
      || "Personalizada";
    get("Profile").textContent = presetLabel;
    get("Summary").textContent = `${changed} de ${controls.length} tamaños ajustados`;
    panel.querySelectorAll("[data-pdcd-font-preset]").forEach((button) => {
      button.setAttribute(
        "aria-pressed",
        String(button.dataset.pdcdFontPreset === preferences.preset)
      );
    });
    preview(currentVariable);
  };

  let storage;
  try {
    storage = window.localStorage;
  } catch {
    storage = null;
  }
  preferences = createProductCustomerDisplayTypographyPreferences(groups, {
    branchId,
    userId,
    storage,
    style: document.documentElement.style,
    onChange: sync,
    onStatus(message, tone) {
      get("SaveStatus").textContent = message;
      get("SaveStatus").dataset.tone = tone;
    }
  });

  const createControl = (control, groupIndex, controlIndex) => {
    const row = createElement(document, "article", "pdcd-typography-control");
    const heading = createElement(document, "div", "pdcd-typography-control-head");
    const label = createElement(document, "label", "", control.label);
    const output = createElement(
      document,
      "output",
      "",
      `${preferences.values[control.variable]} px`
    );
    const inputs = createElement(document, "div", "pdcd-typography-inputs");
    const rangeId = `pdcdTypographyRange-${groupIndex}-${controlIndex}`;
    const numberId = `pdcdTypographyNumber-${groupIndex}-${controlIndex}`;
    label.htmlFor = rangeId;
    output.dataset.pdcdFontOutput = control.variable;
    heading.append(label, output);

    const decrease = createActionButton(document, "−", `Reducir ${control.label}`);
    decrease.dataset.pdcdFontStep = "-1";
    decrease.dataset.pdcdFontVariable = control.variable;

    const range = document.createElement("input");
    range.id = rangeId;
    range.type = "range";
    range.min = control.min;
    range.max = control.max;
    range.step = control.step;
    range.value = preferences.values[control.variable];
    range.dataset.pdcdFontValue = control.variable;
    range.setAttribute("aria-label", control.label);

    const numberLabel = createElement(document, "label", "pdcd-typography-number");
    numberLabel.htmlFor = numberId;
    const number = document.createElement("input");
    number.id = numberId;
    number.type = "number";
    number.inputMode = "none";
    number.readOnly = true;
    number.dataset.pddKeyboard = "numeric";
    number.setAttribute("virtualkeyboardpolicy", "manual");
    number.min = control.min;
    number.max = control.max;
    number.step = control.step;
    number.value = preferences.values[control.variable];
    number.dataset.pdcdFontValue = control.variable;
    number.setAttribute("aria-label", `${control.label}, tamaño en píxeles`);
    numberLabel.append(number, createElement(document, "span", "", "px"));

    const increase = createActionButton(document, "+", `Aumentar ${control.label}`);
    increase.dataset.pdcdFontStep = "1";
    increase.dataset.pdcdFontVariable = control.variable;

    const reset = createActionButton(document, "↺", `Restablecer ${control.label}`);
    reset.dataset.pdcdFontReset = control.variable;
    reset.title = "Tamaño original";

    inputs.append(decrease, range, numberLabel, increase, reset);
    row.dataset.pdcdFontRow = control.variable;
    row.append(heading, inputs);
    return row;
  };

  const createGroup = (group) => {
    const groupIndex = groups.findIndex((candidate) => candidate.id === group.id);
    const details = createElement(document, "details", "pdcd-typography-group");
    const summary = document.createElement("summary");
    const summaryText = createElement(document, "span");
    summaryText.append(
      createElement(document, "b", "", group.title),
      createElement(document, "small", "", `${group.controls.length} ajustes`)
    );
    summary.append(summaryText);
    details.append(summary);
    details.dataset.pdcdFontGroup = group.id;
    details.open = Boolean(search.value.trim() || expandedGroups.has(group.id));

    const body = createElement(document, "div", "pdcd-typography-group-body");
    const resetGroup = createActionButton(document, "Restablecer grupo");
    resetGroup.className = "pdcd-typography-reset-group";
    resetGroup.dataset.pdcdFontResetGroup = group.id;
    body.append(resetGroup);
    group.controls.forEach((control) => {
      body.append(createControl(control, groupIndex, groups[groupIndex].controls.indexOf(control)));
    });
    details.append(body);
    details.addEventListener("toggle", () => {
      if (search.value.trim()) return;
      if (details.open) expandedGroups.add(group.id);
      else expandedGroups.delete(group.id);
    });
    return details;
  };

  const render = () => {
    const found = filterProductCustomerDisplayTypographyGroups(groups, search.value);
    if (found.length === 0) {
      container.replaceChildren(
        createElement(document, "p", "pdcd-typography-empty", "No encontramos ese texto.")
      );
      return;
    }
    const fragment = document.createDocumentFragment();
    found.forEach((group) => fragment.append(createGroup(group)));
    container.replaceChildren(fragment);
    sync(preferences.values);
  };

  const remeasure = () => {
    const nextGroups = measureGroups();
    groups = nextGroups;
    controls = flattenProductCustomerDisplayTypographyControls(groups);
    byVariable = new Map(controls.map((control) => [control.variable, control]));
    preferences.rebase(groups);
  };

  const scheduleRemeasure = () => {
    window.clearTimeout(remeasureTimer);
    remeasureTimer = window.setTimeout(remeasure, 120);
  };

  const main = document.querySelector(".pdcd-shell");
  const mobilePanel = window.matchMedia("(max-width: 720px)");
  const syncPanelMode = () => {
    const covering = !panel.hidden && mobilePanel.matches;
    panel.setAttribute("aria-modal", String(covering));
    document.body.classList.toggle("pdcd-typography-covering", covering);
    if (main) main.inert = covering;
  };

  const close = ({ restoreFocus = true } = {}) => {
    if (panel.hidden) return;
    preferences.flush();
    clearHighlight();
    panel.hidden = true;
    panel.setAttribute("aria-hidden", "true");
    trigger.setAttribute("aria-expanded", "false");
    syncPanelMode();
    if (restoreFocus) trigger.focus({ preventScroll: true });
  };

  const open = () => {
    beforeOpen();
    remeasure();
    panel.hidden = false;
    panel.setAttribute("aria-hidden", "false");
    trigger.setAttribute("aria-expanded", "true");
    syncPanelMode();
    render();
    search.focus({ preventScroll: true });
  };

  trigger.addEventListener("click", open);
  search.addEventListener("input", render);
  panel.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }
    if (event.key !== "Tab" || !mobilePanel.matches) return;
    const focusable = Array.from(panel.querySelectorAll("button,input,summary"))
      .filter((element) => !element.disabled && element.getClientRects().length > 0);
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  });

  panel.addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.hasAttribute("data-pdcd-font-close")) {
      close();
      return;
    }
    const {
      pdcdFontStep,
      pdcdFontVariable,
      pdcdFontReset,
      pdcdFontResetGroup,
      pdcdFontPreset,
      pdcdFontExpand
    } = button.dataset;
    if (pdcdFontStep && byVariable.has(pdcdFontVariable)) {
      const control = byVariable.get(pdcdFontVariable);
      preferences.setValue(
        pdcdFontVariable,
        preferences.values[pdcdFontVariable] + Number(pdcdFontStep) * Number(control.step)
      );
      preview(pdcdFontVariable, true);
    } else if (pdcdFontReset) {
      preferences.resetControl(pdcdFontReset);
      preview(pdcdFontReset, true);
    } else if (pdcdFontResetGroup) {
      preferences.resetGroup(pdcdFontResetGroup);
    } else if (pdcdFontPreset) {
      preferences.applyPreset(pdcdFontPreset);
    } else if (button.hasAttribute("data-pdcd-font-reset-all")) {
      preferences.resetAll();
    } else if (pdcdFontExpand) {
      container.querySelectorAll("details").forEach((details) => {
        details.open = pdcdFontExpand === "all";
      });
    }
  });

  const updateInput = (event) => {
    const input = event.target;
    const variable = input.dataset.pdcdFontValue;
    const control = byVariable.get(variable);
    if (!control) return;
    if (
      event.type === "input"
      && input.type === "number"
      && (
        input.value.trim() === ""
        || !Number.isFinite(Number(input.value))
        || Number(input.value) < control.min
        || Number(input.value) > control.max
      )
    ) return;
    preferences.setValue(variable, input.value);
    if (event.type === "change") input.value = preferences.values[variable];
    preview(variable, true);
  };
  container.addEventListener("input", updateInput);
  container.addEventListener("change", updateInput);
  container.addEventListener("focusin", (event) => {
    const row = event.target.closest("[data-pdcd-font-row]");
    if (row) preview(row.dataset.pdcdFontRow, true);
  });

  mobilePanel.addEventListener?.("change", () => {
    if (!panel.hidden) syncPanelMode();
  });
  window.addEventListener("resize", scheduleRemeasure);
  document.addEventListener("fullscreenchange", scheduleRemeasure);
  window.addEventListener("storage", (event) => preferences.syncStorage(event));
  window.addEventListener("pagehide", () => preferences.flush());
  window.addEventListener("pageshow", (event) => {
    if (!event.persisted) return;
    remeasure();
    preferences.reload();
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") preferences.flush();
  });

  render();
  return { preferences, open, close, remeasure };
}
