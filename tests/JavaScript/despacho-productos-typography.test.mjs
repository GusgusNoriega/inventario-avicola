import assert from "node:assert/strict";
import test from "node:test";

import {
  PRODUCT_DISPATCH_TYPOGRAPHY_VERSION,
  buildTypographyPresetValues,
  defaultTypographyValues,
  normalizeTypographyValue,
  parseTypographyPreferences,
  sanitizeTypographyValues,
  serializeTypographyPreferences,
  typographyChangedCount,
  typographyValuesEqual
} from "../../public/js/despacho-productos-typography.js";

const groups = [
  {
    id: "sample",
    controls: [
      { variable: "--pdd-fs-small", defaultValue: 10.5, min: 9, max: 18, step: 0.5 },
      { variable: "--pdd-fs-weight", defaultValue: 70, min: 32, max: 96, step: 2 }
    ]
  }
];

test("normaliza tamaños, respeta el paso y limita los extremos", () => {
  const small = groups[0].controls[0];
  const weight = groups[0].controls[1];

  assert.equal(normalizeTypographyValue(small, "11.74"), 11.5);
  assert.equal(normalizeTypographyValue(small, "11.76"), 12);
  assert.equal(normalizeTypographyValue(small, -50), 9);
  assert.equal(normalizeTypographyValue(small, 999), 18);
  assert.equal(normalizeTypographyValue(weight, 71), 72);
});

test("rechaza entradas no numéricas y usa el valor predeterminado seguro", () => {
  const control = groups[0].controls[0];

  [null, true, {}, [], "", "18px", "Infinity"].forEach((value) => {
    assert.equal(normalizeTypographyValue(control, value), 10.5);
  });
});

test("restaura sólo variables permitidas e ignora claves desconocidas", () => {
  const unsafe = JSON.parse('{"--pdd-fs-small":"14.4","--pdd-fs-weight":200,"--evil":"999","__proto__":{"polluted":true}}');
  const values = sanitizeTypographyValues(groups, unsafe);

  assert.deepEqual(values, {
    "--pdd-fs-small": 14.5,
    "--pdd-fs-weight": 96
  });
  assert.equal(Object.hasOwn(values, "--evil"), false);
  assert.equal({}.polluted, undefined);
});

test("serializa y recupera una preferencia versionada sin perder valores", () => {
  const serialized = serializeTypographyPreferences(groups, {
    "--pdd-fs-small": 13,
    "--pdd-fs-weight": 84
  }, "large");
  const parsed = parseTypographyPreferences(groups, serialized);

  assert.equal(JSON.parse(serialized).version, PRODUCT_DISPATCH_TYPOGRAPHY_VERSION);
  assert.equal(parsed.valid, true);
  assert.equal(parsed.preset, "large");
  assert.deepEqual(parsed.values, {
    "--pdd-fs-small": 13,
    "--pdd-fs-weight": 84
  });

  assert.equal(parseTypographyPreferences(groups, "{mal json").valid, false);
  assert.deepEqual(parseTypographyPreferences(groups, JSON.stringify({ version: 99, values: {} })).values, defaultTypographyValues(groups));
});

test("genera perfiles completos y detecta cambios frente al estándar", () => {
  const standard = buildTypographyPresetValues(groups, { factor: 1 });
  const large = buildTypographyPresetValues(groups, { factor: 1.15 });
  const accessible = buildTypographyPresetValues(groups, { factor: 1.22, readableFloor: 13 });

  assert.deepEqual(standard, defaultTypographyValues(groups));
  assert.deepEqual(large, {
    "--pdd-fs-small": 12,
    "--pdd-fs-weight": 80
  });
  assert.equal(accessible["--pdd-fs-small"], 13);
  assert.equal(typographyValuesEqual(groups, standard, defaultTypographyValues(groups)), true);
  assert.equal(typographyChangedCount(groups, large), 2);
});
