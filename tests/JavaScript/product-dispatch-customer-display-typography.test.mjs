import assert from "node:assert/strict";
import test from "node:test";

import { PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS } from "../../public/js/product-dispatch-customer-display-typography-catalog.js";
import {
  PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS,
  buildProductCustomerDisplayTypographyPresetValues,
  createProductCustomerDisplayTypographyPreferences,
  defaultProductCustomerDisplayTypographyValues,
  filterProductCustomerDisplayTypographyGroups,
  flattenProductCustomerDisplayTypographyControls,
  parseProductCustomerDisplayTypographyPreferences,
  productCustomerDisplayTypographyStorageKey,
  resolveProductCustomerDisplayTypographyGroups,
  sanitizeProductCustomerDisplayTypographyValues,
  serializeProductCustomerDisplayTypographyPreferences
} from "../../public/js/product-dispatch-customer-display-typography.js";

const controls = flattenProductCustomerDisplayTypographyControls(
  PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS
);
const sampleGroups = [{
  id: "sample",
  title: "Peso y tabla",
  description: "Tamaños visibles",
  controls: [
    { variable: "--pdcd-fs-live-weight", label: "Peso", defaultValue: 120, min: 48, max: 240, step: 2, selector: "#weight" },
    { variable: "--pdcd-fs-row-product", label: "Producto", defaultValue: 24, min: 12, max: 52, step: 0.5, selector: "#product" }
  ]
}];

function harness(initial = {}) {
  const data = new Map(Object.entries(initial));
  const styles = new Map([["--unrelated", "keep"]]);
  const timers = new Map();
  const calls = { writes: [], removals: [], statuses: [] };
  let sequence = 0;
  const storage = {
    failRead: false,
    failWrite: false,
    getItem(key) {
      if (this.failRead) throw new Error("No se puede leer");
      return data.get(key) ?? null;
    },
    setItem(key, value) {
      if (this.failWrite) throw new Error("No se puede guardar");
      calls.writes.push(key);
      data.set(key, value);
    },
    removeItem(key) {
      if (this.failWrite) throw new Error("No se puede borrar");
      calls.removals.push(key);
      data.delete(key);
    }
  };
  const create = (branchId = 4, userId = 17) => createProductCustomerDisplayTypographyPreferences(
    sampleGroups,
    {
      branchId,
      userId,
      storage,
      style: {
        setProperty: (key, value) => styles.set(key, value),
        removeProperty: (key) => styles.delete(key)
      },
      onStatus: (...status) => calls.statuses.push(status),
      setTimer(callback, delay) {
        const id = ++sequence;
        timers.set(id, { callback, delay });
        return id;
      },
      clearTimer: (id) => timers.delete(id)
    }
  );
  return {
    data,
    styles,
    timers,
    calls,
    storage,
    create,
    runTimers() {
      for (const [id, { callback }] of [...timers]) {
        timers.delete(id);
        callback();
      }
    }
  };
}

test("el catálogo cubre todos los textos visibles con variables exclusivas", () => {
  assert.equal(controls.length, 28);
  assert.equal(new Set(controls.map(({ variable }) => variable)).size, controls.length);
  assert.equal(new Set(controls.map(({ selector }) => selector)).size, controls.length);
  controls.forEach((control) => {
    assert.match(control.variable, /^--pdcd-fs-[a-z0-9-]+$/);
    assert.ok(control.selector);
    assert.ok(control.label);
    assert.ok(Number(control.min) < Number(control.max));
  });

  const mixed = [...sampleGroups, { controls: [
    { variable: "--pdd-fs-live-weight" },
    { variable: "--pdcd-color" },
    { variable: "font-size" }
  ] }];
  assert.equal(flattenProductCustomerDisplayTypographyControls(mixed).length, 2);
});

test("mide los tamaños responsive originales sin modificar el catálogo", () => {
  const original = structuredClone(sampleGroups);
  const measured = resolveProductCustomerDisplayTypographyGroups(
    sampleGroups,
    (selector) => ({ "#weight": "93.7px", "#product": "19.4px" })[selector]
  );

  assert.equal(measured[0].controls[0].defaultValue, 94);
  assert.equal(measured[0].controls[1].defaultValue, 19.5);
  assert.deepEqual(sampleGroups, original);
});

test("serializa únicamente cambios permitidos y rechaza datos inseguros", () => {
  const defaults = defaultProductCustomerDisplayTypographyValues(sampleGroups);
  const malicious = JSON.parse('{"--pdcd-fs-live-weight":"180","--pdd-fs-live-weight":999,"__proto__":{"polluted":true}}');
  assert.deepEqual(sanitizeProductCustomerDisplayTypographyValues(sampleGroups, malicious), {
    "--pdcd-fs-live-weight": 180,
    "--pdcd-fs-row-product": 24
  });
  assert.equal({}.polluted, undefined);

  const encoded = serializeProductCustomerDisplayTypographyPreferences(
    sampleGroups,
    { ...defaults, "--pdcd-fs-row-product": 30 },
    "custom"
  );
  assert.deepEqual(JSON.parse(encoded).values, { "--pdcd-fs-row-product": 30 });
  assert.deepEqual(
    parseProductCustomerDisplayTypographyPreferences(sampleGroups, encoded).values,
    { ...defaults, "--pdcd-fs-row-product": 30 }
  );
  assert.equal(parseProductCustomerDisplayTypographyPreferences(sampleGroups, "{roto").valid, false);
});

test("la preferencia se aísla por sucursal y usuario pero no por ventana de origen", () => {
  const key = productCustomerDisplayTypographyStorageKey(4, 17);
  assert.equal(key, productCustomerDisplayTypographyStorageKey("4", "17"));
  assert.notEqual(key, productCustomerDisplayTypographyStorageKey(5, 17));
  assert.notEqual(key, productCustomerDisplayTypographyStorageKey(4, 18));
  assert.doesNotMatch(key, /source|producer/i);
});

test("aplica al instante y guarda una sola vez sin tocar otras preferencias", () => {
  const unrelated = {
    "sistema-pollos-product-dispatch-typography-v1-user-17": "estación",
    "otra-clave": "conservar"
  };
  const h = harness(unrelated);
  const preferences = h.create();
  preferences.setValue("--pdcd-fs-live-weight", 180);
  preferences.setValue("--pdcd-fs-live-weight", 184);

  assert.equal(h.styles.get("--pdcd-fs-live-weight"), "184px");
  assert.equal(h.timers.size, 1);
  assert.equal([...h.timers.values()][0].delay, 180);
  h.runTimers();
  assert.deepEqual(h.calls.writes, [preferences.storageKey]);
  assert.equal(
    parseProductCustomerDisplayTypographyPreferences(
      sampleGroups,
      h.data.get(preferences.storageKey)
    ).values["--pdcd-fs-live-weight"],
    184
  );
  Object.entries(unrelated).forEach(([key, value]) => assert.equal(h.data.get(key), value));
  assert.equal(h.styles.get("--unrelated"), "keep");
});

test("restablecer quita variables personalizadas y solo borra su propia clave", () => {
  const h = harness({ "otra-clave": "conservar" });
  const preferences = h.create();
  preferences.setValue("--pdcd-fs-live-weight", 180);
  preferences.setValue("--pdcd-fs-row-product", 32);
  preferences.resetControl("--pdcd-fs-live-weight");
  assert.equal(h.styles.has("--pdcd-fs-live-weight"), false);
  assert.equal(h.styles.get("--pdcd-fs-row-product"), "32px");

  preferences.resetAll();
  assert.deepEqual(preferences.values, defaultProductCustomerDisplayTypographyValues(sampleGroups));
  assert.equal(h.styles.has("--pdcd-fs-row-product"), false);
  assert.equal(h.data.get("otra-clave"), "conservar");
  assert.deepEqual(h.calls.removals, [preferences.storageKey]);
  h.runTimers();
  assert.equal(h.data.has(preferences.storageKey), false);
});

test("los perfiles permanecen dentro de límites y Original recupera el CSS responsive", () => {
  const h = harness();
  const preferences = h.create();
  for (const [preset, options] of Object.entries(PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS)) {
    preferences.applyPreset(preset);
    assert.deepEqual(
      preferences.values,
      buildProductCustomerDisplayTypographyPresetValues(sampleGroups, options)
    );
    assert.equal(preferences.preset, preset);
  }
  preferences.applyPreset("standard");
  assert.equal(h.styles.has("--pdcd-fs-live-weight"), false);
  assert.equal(h.styles.has("--pdcd-fs-row-product"), false);
});

test("los perfiles se recalculan al cambiar de resolución y los ajustes manuales se conservan", () => {
  const resizedGroups = structuredClone(sampleGroups);
  resizedGroups[0].controls[0].defaultValue = 160;
  resizedGroups[0].controls[1].defaultValue = 30;
  const h = harness();
  const preferences = h.create();

  preferences.applyPreset("large");
  preferences.rebase(resizedGroups);
  assert.deepEqual(
    preferences.values,
    buildProductCustomerDisplayTypographyPresetValues(
      resizedGroups,
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS.large
    )
  );
  assert.equal(preferences.preset, "large");

  const savedPreset = serializeProductCustomerDisplayTypographyPreferences(
    sampleGroups,
    buildProductCustomerDisplayTypographyPresetValues(
      sampleGroups,
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS.large
    ),
    "large"
  );
  assert.deepEqual(JSON.parse(savedPreset).values, {});
  assert.deepEqual(
    parseProductCustomerDisplayTypographyPreferences(resizedGroups, savedPreset).values,
    buildProductCustomerDisplayTypographyPresetValues(
      resizedGroups,
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_PRESETS.large
    )
  );

  preferences.applyPreset("standard");
  preferences.setValue("--pdcd-fs-live-weight", 210);
  const secondResolution = structuredClone(sampleGroups);
  secondResolution[0].controls[0].defaultValue = 140;
  secondResolution[0].controls[1].defaultValue = 20;
  preferences.rebase(secondResolution);
  assert.equal(preferences.values["--pdcd-fs-live-weight"], 210);
  assert.equal(preferences.values["--pdcd-fs-row-product"], 20);
  assert.equal(h.styles.get("--pdcd-fs-live-weight"), "210px");
  assert.equal(h.styles.has("--pdcd-fs-row-product"), false);
});

test("un tamaño manual no se pierde cuando coincide con el original de otro monitor", () => {
  const h = harness();
  const preferences = h.create();
  const matchingResolution = structuredClone(sampleGroups);
  matchingResolution[0].controls[0].defaultValue = 140;
  const laterResolution = structuredClone(sampleGroups);
  laterResolution[0].controls[0].defaultValue = 160;

  preferences.setValue("--pdcd-fs-live-weight", 140);
  preferences.rebase(matchingResolution);
  assert.equal(preferences.preset, "custom");
  assert.deepEqual(preferences.overrideVariables, ["--pdcd-fs-live-weight"]);
  assert.equal(h.styles.has("--pdcd-fs-live-weight"), false);

  h.runTimers();
  assert.deepEqual(
    JSON.parse(h.data.get(preferences.storageKey)).values,
    { "--pdcd-fs-live-weight": 140 }
  );

  preferences.rebase(laterResolution);
  assert.equal(preferences.values["--pdcd-fs-live-weight"], 140);
  assert.equal(h.styles.get("--pdcd-fs-live-weight"), "140px");
  preferences.resetControl("--pdcd-fs-live-weight");
  assert.equal(preferences.preset, "standard");
  assert.deepEqual(preferences.overrideVariables, []);
});

test("busca grupos y controles sin depender de mayúsculas ni tildes", () => {
  assert.equal(
    filterProductCustomerDisplayTypographyGroups(
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS,
      "TÍTULO EMPRESA"
    ).flatMap(({ controls: found }) => found).length,
    1
  );
  assert.equal(
    filterProductCustomerDisplayTypographyGroups(
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS,
      "peso producto"
    ).flatMap(({ controls: found }) => found).length,
    1
  );
  assert.deepEqual(
    filterProductCustomerDisplayTypographyGroups(
      PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS,
      "no existe"
    ),
    []
  );
});

test("sin almacenamiento los controles siguen funcionando durante la visita", () => {
  const h = harness();
  h.storage.failRead = true;
  const preferences = h.create();
  h.storage.failWrite = true;
  preferences.setValue("--pdcd-fs-live-weight", 188);
  assert.doesNotThrow(() => preferences.flush());
  assert.equal(preferences.values["--pdcd-fs-live-weight"], 188);
  assert.equal(h.styles.get("--pdcd-fs-live-weight"), "188px");
  assert.ok(h.calls.statuses.some(([message, tone]) => tone === "error" && /visita/i.test(message)));
});
