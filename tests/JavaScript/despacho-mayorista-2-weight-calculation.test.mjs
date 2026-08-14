import assert from "node:assert/strict";
import test from "node:test";

import {
  calculateWholesaleTwoWeightBreakdown,
  wholesaleTwoAdjustmentGramsForVariant
} from "../../public/js/despacho-mayorista-2-weight-calculation.js";

const configurableVariants = [
  "MACHO",
  "HEMBRA",
  "MACHO_ABIERTO",
  "MACHO_CERRADO",
  "HEMBRA_ABIERTA",
  "HEMBRA_CERRADA"
];

test("las seis variantes configurables suman la merma por cada ave antes de la tara", () => {
  for (const variantCode of configurableVariants) {
    const result = calculateWholesaleTwoWeightBreakdown({
      variantCode,
      operationType: "DESPACHO",
      readWeightKg: 20,
      cageCount: 2,
      birdsPerCage: 7,
      crateWeightKg: 7,
      configuredAdjustmentGrams: 100
    });

    assert.equal(result.birdCount, 14);
    assert.equal(result.adjustmentGrams, 100);
    assert.equal(result.totalAdjustmentGrams, 1400);
    assert.equal(result.adjustmentWeightKg, 1.4);
    assert.equal(result.grossWeight, 21.4);
    assert.equal(result.tareWeightKg, 14);
    assert.equal(result.netWeightKg, 7.4);
  }
});

test("pollo beneficiado fuerza merma cero aunque llegue un valor configurado", () => {
  const result = calculateWholesaleTwoWeightBreakdown({
    variantCode: "POLLO_BENEFICIADO",
    readWeightKg: 12.345,
    cageCount: 1,
    birdsPerCage: 5,
    crateWeightKg: 2.5,
    configuredAdjustmentGrams: 999999
  });

  assert.equal(result.adjustmentGrams, 0);
  assert.equal(result.totalAdjustmentGrams, 0);
  assert.equal(result.grossWeight, 12.345);
  assert.equal(result.netWeightKg, 9.845);
});

test("las devoluciones no reciben merma y cero javas conserva las aves como total", () => {
  const dispatchWithoutCages = calculateWholesaleTwoWeightBreakdown({
    variantCode: "MACHO",
    operationType: "DESPACHO",
    readWeightKg: 8,
    cageCount: 0,
    birdsPerCage: 7,
    crateWeightKg: 7,
    configuredAdjustmentGrams: 25
  });
  const returned = calculateWholesaleTwoWeightBreakdown({
    variantCode: "HEMBRA",
    operationType: "DEVOLUCION",
    readWeightKg: 8,
    cageCount: 0,
    birdsPerCage: 7,
    crateWeightKg: 7,
    configuredAdjustmentGrams: 25
  });

  assert.equal(dispatchWithoutCages.birdCount, 7);
  assert.equal(dispatchWithoutCages.tareWeightKg, 0);
  assert.equal(dispatchWithoutCages.adjustmentWeightKg, 0.175);
  assert.equal(dispatchWithoutCages.netWeightKg, 8.175);
  assert.equal(returned.adjustmentGrams, 0);
  assert.equal(returned.netWeightKg, 8);
});

test("los gramos inválidos o negativos se normalizan a cero", () => {
  assert.equal(wholesaleTwoAdjustmentGramsForVariant("MACHO", -10), 0);
  assert.equal(wholesaleTwoAdjustmentGramsForVariant("HEMBRA", "no-numero"), 0);
  assert.equal(wholesaleTwoAdjustmentGramsForVariant("MACHO_ABIERTO", 125.9), 125);
});
