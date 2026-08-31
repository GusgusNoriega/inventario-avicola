import assert from "node:assert/strict";
import test from "node:test";
import {
  buildTypographyPresetValues,
  createReceptionTypographyPreferences,
  defaultTypographyValues,
  filterTypographyGroups,
  flattenTypographyControls,
  normalizeTypographyValue,
  parseTypographyPreferences,
  resolveTypographyGroups,
  sanitizeTypographyValues,
  serializeTypographyPreferences,
  typographyStorageKey,
} from "../../public/js/live-chicken-reception-typography.js";

const groups = [
  {
    id: "scale",
    title: "Balanza y captura",
    description: "Lecturas de peso físico y manual",
    controls: [
      { variable: "--lir-fs-scale-weight", label: "Peso de balanza", description: "Kilogramos recibidos", defaultValue: 54, min: 32, max: 96, step: 2 },
      { variable: "--lir-fs-scale-status", label: "Estado de conexión", description: "Serial y Bluetooth", defaultValue: 12, min: 10, max: 20, step: 0.5 },
    ],
  },
  {
    id: "records",
    title: "Registro y totales",
    description: "Tickets de recepción",
    controls: [
      { variable: "--lir-fs-row-total", label: "Total de aves", description: "Cantidad del ticket", defaultValue: 14, min: 10, max: 28, step: 1 },
    ],
  },
];
const [weight, status, total] = flattenTypographyControls(groups);

function harness(userId = 1, initial = {}) {
  const data = new Map(Object.entries(initial));
  const styles = new Map([["--lir-zoom-scale", "1.25"]]);
  const timers = new Map();
  const calls = { writes: [], removals: [], changes: [], statuses: [] };
  let sequence = 0;
  const storage = {
    failRead: false,
    failWrite: false,
    getItem(key) {
      if (this.failRead) throw new Error("Almacenamiento inaccesible");
      return data.get(key) ?? null;
    },
    setItem(key, value) {
      if (this.failWrite) throw new Error("Almacenamiento lleno");
      calls.writes.push(key);
      data.set(key, value);
    },
    removeItem(key) {
      if (this.failWrite) throw new Error("Almacenamiento inaccesible");
      calls.removals.push(key);
      data.delete(key);
    },
  };
  const create = () => createReceptionTypographyPreferences(groups, {
    userId,
    storage,
    style: {
      setProperty: (key, value) => styles.set(key, value),
      removeProperty: (key) => styles.delete(key),
    },
    onChange: (values) => calls.changes.push(values),
    onStatus: (...args) => calls.statuses.push(args),
    setTimer(callback, delay) {
      const id = ++sequence;
      timers.set(id, { callback, delay });
      return id;
    },
    clearTimer: (id) => timers.delete(id),
  });
  return {
    data, styles, timers, storage, calls, create,
    runTimers() {
      for (const [id, { callback }] of [...timers]) {
        timers.delete(id);
        callback();
      }
    },
  };
}

test("solo admite las variables tipográficas de recepción y normaliza valores inseguros", () => {
  const mixedGroups = [...groups, { controls: [
    { variable: "--pdd-fs-weight" },
    { variable: "--lir-zoom-scale" },
    { variable: "color" },
  ] }];
  assert.deepEqual(flattenTypographyControls(mixedGroups), [weight, status, total]);
  assert.equal(normalizeTypographyValue(status, "11.74"), 11.5);
  assert.equal(normalizeTypographyValue(status, "11.76"), 12);
  assert.equal(normalizeTypographyValue(weight, -10), 32);
  assert.equal(normalizeTypographyValue(weight, 1000), 96);
  for (const value of [null, true, {}, [], "", "18px", "Infinity", "calc(999px)"]) {
    assert.equal(normalizeTypographyValue(status, value), 12);
  }
  const malicious = JSON.parse('{"--lir-fs-scale-weight":"88","--pdd-fs-weight":999,"--lir-zoom-scale":999,"__proto__":{"polluted":true}}');
  assert.deepEqual(sanitizeTypographyValues(groups, malicious), {
    "--lir-fs-scale-weight": 88,
    "--lir-fs-scale-status": 12,
    "--lir-fs-row-total": 14,
  });
  assert.equal({}.polluted, undefined);
  assert.deepEqual(sanitizeTypographyValues(groups, Object.create({ [weight.variable]: 88 })), defaultTypographyValues(groups));
});

test("serializa preferencias versionadas y JSON inválido vuelve a valores seguros", () => {
  const values = { ...defaultTypographyValues(groups), [weight.variable]: 80 };
  const encoded = serializeTypographyPreferences(groups, values, "custom");
  assert.deepEqual(parseTypographyPreferences(groups, encoded), { valid: true, preset: "custom", values });
  for (const encodedValue of ["{json roto", "null", "[]", '{"version":999,"values":{}}', '{"version":1,"values":[]}']) {
    const parsed = parseTypographyPreferences(groups, encodedValue);
    assert.equal(parsed.valid, false);
    assert.deepEqual(parsed.values, defaultTypographyValues(groups));
  }
  assert.deepEqual(parseTypographyPreferences(groups, null).values, defaultTypographyValues(groups));
});

test("los originales usan el tamaño visible de cada selector y el perfil compacto lo reduce", () => {
  const responsiveGroups = [{ id: "screen", controls: [
    { ...weight, selector: "#weight" },
    { ...status, variable: "--lir-fs-title", selector: "#title", defaultValue: 24, max: 32 },
    { ...total },
    { ...status, selector: "#missing" },
  ] }];
  const original = structuredClone(responsiveGroups);
  const measurements = { "#weight": 41.6, "#title": 18.4 };
  const resolved = resolveTypographyGroups(responsiveGroups, (selector) => measurements[selector]);
  const defaults = defaultTypographyValues(resolved);

  assert.equal(defaults[weight.variable], 42);
  assert.equal(defaults["--lir-fs-title"], 18.5);
  assert.equal(defaults[total.variable], total.defaultValue);
  assert.equal(defaults[status.variable], status.defaultValue);
  assert.deepEqual(responsiveGroups, original);
  assert.notEqual(resolved[0], responsiveGroups[0]);
  assert.notEqual(resolved[0].controls[0], responsiveGroups[0].controls[0]);
  const compact = buildTypographyPresetValues(resolved, { factor: 0.9 });
  assert.ok(compact[weight.variable] < defaults[weight.variable]);
  assert.ok(compact["--lir-fs-title"] < defaults["--lir-fs-title"]);
});

test("guardar omite los originales para adaptarlos a otra resolución y conserva solo cambios expresos", () => {
  const values = { ...defaultTypographyValues(groups), [total.variable]: 22 };
  const encoded = serializeTypographyPreferences(groups, values, "custom");
  assert.deepEqual(JSON.parse(encoded).values, { [total.variable]: 22 });
  assert.deepEqual(JSON.parse(serializeTypographyPreferences(groups, defaultTypographyValues(groups), "standard")).values, {});

  const smallerGroups = groups.map((group) => ({
    ...group,
    controls: group.controls.map((control) => ({
      ...control,
      defaultValue: { [weight.variable]: 42, [status.variable]: 10.5, [total.variable]: 12 }[control.variable],
    })),
  }));
  assert.deepEqual(parseTypographyPreferences(smallerGroups, encoded).values, {
    [weight.variable]: 42,
    [status.variable]: 10.5,
    [total.variable]: 22,
  });
});

test("los tamaños cambian inmediatamente y un único guardado diferido usa la clave del usuario", () => {
  const untouched = {
    "sistema-pollos-product-dispatch-typography-v1-user-1": "productos",
    "sistema-pollos-recepcion-pollo-vivo-zoom-v1": "125",
    "recepcion-pending": '{"weight_source":"MANUAL","read_weight_kg":100}',
  };
  const h = harness(1, untouched);
  const preferences = h.create();
  assert.equal(preferences.storageKey, typographyStorageKey(1));
  assert.notEqual(preferences.storageKey, typographyStorageKey(2));
  preferences.setValue(weight.variable, 80);
  preferences.setValue(weight.variable, 82);

  assert.equal(h.styles.get(weight.variable), "82px");
  assert.equal(h.calls.changes.at(-1)[weight.variable], 82);
  assert.equal(h.calls.writes.length, 0);
  assert.equal(h.timers.size, 1);
  assert.equal([...h.timers.values()][0].delay, 180);
  h.runTimers();
  assert.deepEqual(h.calls.writes, [preferences.storageKey]);
  assert.equal(parseTypographyPreferences(groups, h.data.get(preferences.storageKey)).values[weight.variable], 82);
  for (const [key, value] of Object.entries(untouched)) assert.equal(h.data.get(key), value);
  assert.equal(h.styles.get("--lir-zoom-scale"), "1.25");

  const exposed = preferences.values;
  exposed[weight.variable] = 96;
  assert.equal(preferences.values[weight.variable], 82);
  preferences.setValue("--lir-zoom-scale", 999);
  assert.equal(h.styles.get("--lir-zoom-scale"), "1.25");
  assert.equal(h.timers.size, 0);
});

test("restaura preferencias propias sin contaminar otro usuario ni guardar durante la carga", () => {
  const stored = serializeTypographyPreferences(groups, { ...defaultTypographyValues(groups), [weight.variable]: 88 });
  const h = harness(1, { [typographyStorageKey(1)]: stored, [typographyStorageKey(2)]: "otro usuario" });
  const preferences = h.create();
  assert.equal(preferences.values[weight.variable], 88);
  assert.equal(h.styles.get(weight.variable), "88px");
  assert.equal(h.styles.has(status.variable), false);
  assert.equal(h.calls.writes.length, 0);
  preferences.resetAll();
  assert.equal(h.data.get(typographyStorageKey(2)), "otro usuario");
});

test("fallos al leer o escribir storage mantienen controles utilizables durante la visita", () => {
  const h = harness();
  h.storage.failRead = true;
  const preferences = h.create();
  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
  h.storage.failWrite = true;
  preferences.setValue(weight.variable, 84);
  assert.doesNotThrow(() => preferences.flush());
  assert.equal(preferences.values[weight.variable], 84);
  assert.equal(h.styles.get(weight.variable), "84px");
  assert.ok(h.calls.statuses.some(([message, tone]) => tone === "error" && message.includes("durante esta visita")));
  assert.doesNotThrow(() => preferences.resetAll());
  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
});

test("restablece un control, su grupo o todo sin rehacer un guardado pendiente", () => {
  const h = harness();
  const preferences = h.create();
  preferences.setValue(weight.variable, 80);
  preferences.setValue(status.variable, 16);
  preferences.setValue(total.variable, 20);
  preferences.resetControl(weight.variable);
  assert.equal(preferences.values[weight.variable], 54);
  assert.equal(h.styles.has(weight.variable), false);
  assert.equal(preferences.values[status.variable], 16);
  preferences.resetGroup("scale");
  assert.equal(preferences.values[status.variable], 12);
  assert.equal(preferences.values[total.variable], 20);
  preferences.setValue(weight.variable, 88);
  assert.equal(h.timers.size, 1);
  preferences.resetAll();

  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
  assert.equal(h.timers.size, 0);
  assert.equal(h.data.has(preferences.storageKey), false);
  h.runTimers();
  assert.equal(h.data.has(preferences.storageKey), false);
  assert.equal(h.styles.has(total.variable), false);
});

test("los cuatro perfiles producen valores válidos y el estándar recupera CSS original", () => {
  const h = harness();
  const preferences = h.create();
  const options = {
    compact: { factor: 0.9 },
    standard: { factor: 1 },
    large: { factor: 1.2 },
    accessible: { factor: 1.35, readableFloor: 14 },
  };
  assert.deepEqual(buildTypographyPresetValues(groups, options.large), {
    "--lir-fs-scale-weight": 64,
    "--lir-fs-scale-status": 14.5,
    "--lir-fs-row-total": 17,
  });
  for (const [preset, profile] of Object.entries(options)) {
    preferences.applyPreset(preset);
    assert.deepEqual(preferences.values, buildTypographyPresetValues(groups, profile));
    assert.equal(preferences.preset, preset);
  }
  preferences.applyPreset("standard");
  assert.equal(h.styles.has(weight.variable), false);
  assert.equal(h.styles.has(status.variable), false);
  assert.equal(h.styles.has(total.variable), false);
});

test("buscar por control o grupo ignora tildes y no modifica catálogo ni preferencias", () => {
  const snapshot = structuredClone(groups);
  assert.deepEqual(filterTypographyGroups(groups, "BALANZA CAPTURA").flatMap((group) => group.controls), [weight, status]);
  assert.deepEqual(filterTypographyGroups(groups, "CONEXION").flatMap((group) => group.controls), [status]);
  assert.deepEqual(filterTypographyGroups(groups, " RECEPCION ").flatMap((group) => group.controls), [total]);
  assert.deepEqual(filterTypographyGroups(groups, "físico").flatMap((group) => group.controls), [weight, status]);
  assert.deepEqual(filterTypographyGroups(groups, "noexiste"), []);
  assert.deepEqual(filterTypographyGroups(groups, "").flatMap((group) => group.controls), [weight, status, total]);
  assert.deepEqual(groups, snapshot);
});

test("sincroniza otra pestaña y su restablecimiento sin reescribir ni tocar claves ajenas", () => {
  const h = harness();
  const preferences = h.create();
  preferences.setValue(weight.variable, 88);
  const remote = serializeTypographyPreferences(groups, { ...defaultTypographyValues(groups), [total.variable]: 24 });
  preferences.syncStorage({ key: typographyStorageKey(2), newValue: remote });
  assert.equal(preferences.values[weight.variable], 88);
  assert.equal(h.timers.size, 1);
  h.data.set(preferences.storageKey, remote);
  preferences.syncStorage({ key: preferences.storageKey, newValue: remote });
  assert.equal(h.timers.size, 0);
  assert.equal(preferences.values[weight.variable], 54);
  assert.equal(h.styles.get(total.variable), "24px");
  h.runTimers();
  assert.equal(h.calls.writes.length, 0);

  h.data.delete(preferences.storageKey);
  preferences.syncStorage({ key: preferences.storageKey, newValue: null });
  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
  preferences.setValue(weight.variable, 80);
  preferences.syncStorage({ key: null, newValue: null });
  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
  assert.equal(h.timers.size, 0);
  assert.equal(h.calls.writes.length, 0);
});

test("una actualización remota corrupta vuelve a tamaños seguros sin romper la sincronización", () => {
  const h = harness();
  const preferences = h.create();
  preferences.setValue(weight.variable, 88);
  h.data.set(preferences.storageKey, "{json roto");
  assert.doesNotThrow(() => preferences.syncStorage({ key: preferences.storageKey, newValue: "{json roto" }));
  assert.deepEqual(preferences.values, defaultTypographyValues(groups));
  assert.equal(h.timers.size, 0);
  assert.equal(h.calls.writes.length, 0);
});

test("un evento de cambio o borrado obsoleto no sustituye la última preferencia almacenada", () => {
  const h = harness();
  const preferences = h.create();
  const stale = serializeTypographyPreferences(groups, { ...defaultTypographyValues(groups), [weight.variable]: 80 });
  const current = serializeTypographyPreferences(groups, { ...defaultTypographyValues(groups), [weight.variable]: 90 });
  h.data.set(preferences.storageKey, current);
  preferences.syncStorage({ key: preferences.storageKey, newValue: stale });
  assert.equal(preferences.values[weight.variable], 90);
  assert.equal(h.styles.get(weight.variable), "90px");

  preferences.setValue(weight.variable, 84);
  preferences.syncStorage({ key: null, newValue: null });
  assert.equal(preferences.values[weight.variable], 90);
  assert.equal(h.data.get(preferences.storageKey), current);
  assert.equal(h.timers.size, 0);
  assert.equal(h.calls.writes.length, 0);
});

test("recargar al volver desde BFCache aplica el snapshot vigente y cancela el guardado anterior", () => {
  const h = harness();
  const preferences = h.create();
  preferences.setValue(weight.variable, 80);
  h.data.set(preferences.storageKey, serializeTypographyPreferences(groups, {
    ...defaultTypographyValues(groups), [weight.variable]: 90,
  }));

  preferences.reload();

  assert.equal(preferences.values[weight.variable], 90);
  assert.equal(h.styles.get(weight.variable), "90px");
  assert.equal(h.timers.size, 0);
  h.runTimers();
  assert.equal(h.calls.writes.length, 0);
});

test("si no puede leer el snapshot vigente, sincroniza el valor recibido en el evento", () => {
  const h = harness();
  const preferences = h.create();
  h.storage.failRead = true;
  const remote = serializeTypographyPreferences(groups, { ...defaultTypographyValues(groups), [weight.variable]: 80 });

  assert.doesNotThrow(() => preferences.syncStorage({ key: preferences.storageKey, newValue: remote }));

  assert.equal(preferences.values[weight.variable], 80);
  assert.equal(h.styles.get(weight.variable), "80px");
  assert.equal(h.calls.writes.length, 0);
});
