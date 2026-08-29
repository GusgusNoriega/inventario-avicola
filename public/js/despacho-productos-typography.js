export const PRODUCT_DISPATCH_TYPOGRAPHY_VERSION = 1;

export function flattenTypographyControls(groups = []) {
  return (Array.isArray(groups) ? groups : [])
    .flatMap((group) => Array.isArray(group?.controls) ? group.controls : [])
    .filter((control) => typeof control?.variable === "string" && control.variable.startsWith("--pdd-fs-"));
}

function decimalPlaces(value) {
  const text = String(value);
  if (text.includes("e-")) return Number(text.split("e-")[1]) || 0;
  return (text.split(".")[1] || "").length;
}

function numericInput(value) {
  if (typeof value === "number") return Number.isFinite(value) ? value : null;
  if (typeof value !== "string" || value.trim() === "") return null;
  const text = value.trim();
  if (!/^[+-]?(?:\d+\.?\d*|\.\d+)$/.test(text)) return null;
  const numeric = Number(text);
  return Number.isFinite(numeric) ? numeric : null;
}

export function normalizeTypographyValue(control = {}, value) {
  const rawMinimum = Number(control.min);
  const rawMaximum = Number(control.max);
  const minimum = Number.isFinite(rawMinimum) ? rawMinimum : 8;
  const maximum = Number.isFinite(rawMaximum) && rawMaximum >= minimum ? rawMaximum : minimum;
  const rawStep = Number(control.step);
  const step = Number.isFinite(rawStep) && rawStep > 0 ? rawStep : 1;
  const fallbackInput = numericInput(control.defaultValue);
  const fallback = fallbackInput === null ? minimum : fallbackInput;
  const numeric = numericInput(value) ?? fallback;
  const stepped = minimum + Math.round((numeric - minimum) / step) * step;
  const clamped = Math.min(maximum, Math.max(minimum, stepped));

  return Number(clamped.toFixed(Math.min(6, decimalPlaces(step))));
}

export function defaultTypographyValues(groups = []) {
  return Object.fromEntries(flattenTypographyControls(groups).map((control) => [
    control.variable,
    normalizeTypographyValue(control, control.defaultValue)
  ]));
}

export function sanitizeTypographyValues(groups = [], values = {}) {
  const source = values && typeof values === "object" && !Array.isArray(values) ? values : {};

  return Object.fromEntries(flattenTypographyControls(groups).map((control) => [
    control.variable,
    Object.hasOwn(source, control.variable)
      ? normalizeTypographyValue(control, source[control.variable])
      : normalizeTypographyValue(control, control.defaultValue)
  ]));
}

export function buildTypographyPresetValues(groups = [], options = {}) {
  const factor = Number.isFinite(Number(options.factor)) && Number(options.factor) > 0
    ? Number(options.factor)
    : 1;
  const readableFloor = Number.isFinite(Number(options.readableFloor))
    ? Number(options.readableFloor)
    : null;

  return Object.fromEntries(flattenTypographyControls(groups).map((control) => {
    const base = normalizeTypographyValue(control, control.defaultValue);
    const scaled = readableFloor === null ? base * factor : Math.max(base * factor, readableFloor);
    return [control.variable, normalizeTypographyValue(control, scaled)];
  }));
}

export function typographyValuesEqual(groups = [], left = {}, right = {}) {
  return flattenTypographyControls(groups).every((control) => (
    normalizeTypographyValue(control, left?.[control.variable])
      === normalizeTypographyValue(control, right?.[control.variable])
  ));
}

export function typographyChangedCount(groups = [], values = {}) {
  const defaults = defaultTypographyValues(groups);
  const normalized = sanitizeTypographyValues(groups, values);

  return flattenTypographyControls(groups).reduce((total, control) => (
    total + (normalized[control.variable] === defaults[control.variable] ? 0 : 1)
  ), 0);
}

export function serializeTypographyPreferences(groups = [], values = {}, preset = "custom") {
  return JSON.stringify({
    version: PRODUCT_DISPATCH_TYPOGRAPHY_VERSION,
    preset: typeof preset === "string" ? preset : "custom",
    values: sanitizeTypographyValues(groups, values),
    updated_at: Date.now()
  });
}

export function parseTypographyPreferences(groups = [], serialized = null) {
  const defaults = defaultTypographyValues(groups);
  if (serialized === null || serialized === undefined || serialized === "") {
    return { valid: true, preset: "standard", values: defaults };
  }

  try {
    const parsed = typeof serialized === "string" ? JSON.parse(serialized) : serialized;
    if (
      !parsed
      || typeof parsed !== "object"
      || Array.isArray(parsed)
      || parsed.version !== PRODUCT_DISPATCH_TYPOGRAPHY_VERSION
      || !parsed.values
      || typeof parsed.values !== "object"
      || Array.isArray(parsed.values)
    ) {
      return { valid: false, preset: "standard", values: defaults };
    }

    return {
      valid: true,
      preset: typeof parsed.preset === "string" ? parsed.preset : "custom",
      values: sanitizeTypographyValues(groups, parsed.values)
    };
  } catch {
    return { valid: false, preset: "standard", values: defaults };
  }
}
