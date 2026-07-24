import test from "node:test";
import assert from "node:assert/strict";

import {
  calculateRetailBirdCount,
  calculateRetailWeightAdjustment,
  retailAdjustmentGramsForChicken
} from "../../public/js/retail-weight-calculation.js";

test("la cantidad de aves se conserva al retirar todas las bandejas", () => {
  assert.equal(calculateRetailBirdCount(2, 5), 10);
  assert.equal(calculateRetailBirdCount(1, 5), 5);
  assert.equal(calculateRetailBirdCount(0, 5), 5);
});

test("pollo pelado recibe la merma configurada por cada pollo con y sin bandeja", () => {
  assert.deepEqual(
    calculateRetailWeightAdjustment({
      chickenTypeCode: "POLLO_PELADO",
      trayCount: 2,
      birdsPerTray: 5,
      configuredAdjustmentGrams: 250
    }),
    {
      birds: 10,
      adjustmentGrams: 250,
      totalAdjustmentGrams: 2500,
      appliesAdjustment: true
    }
  );

  assert.deepEqual(
    calculateRetailWeightAdjustment({
      chickenTypeCode: "POLLO_PELADO",
      trayCount: 0,
      birdsPerTray: 5,
      configuredAdjustmentGrams: 250
    }),
    {
      birds: 5,
      adjustmentGrams: 250,
      totalAdjustmentGrams: 1250,
      appliesAdjustment: true
    }
  );
});

test("pollo beneficiado nunca recibe merma aunque el ajuste tenga gramos", () => {
  assert.equal(retailAdjustmentGramsForChicken("POLLO_BENEFICIADO", 250), 0);
  assert.deepEqual(
    calculateRetailWeightAdjustment({
      chickenTypeCode: "POLLO_BENEFICIADO",
      trayCount: 2,
      birdsPerTray: 5,
      configuredAdjustmentGrams: 250
    }),
    {
      birds: 10,
      adjustmentGrams: 0,
      totalAdjustmentGrams: 0,
      appliesAdjustment: false
    }
  );
  assert.deepEqual(
    calculateRetailWeightAdjustment({
      chickenTypeCode: "pollo_beneficiado",
      trayCount: 0,
      birdsPerTray: 5,
      configuredAdjustmentGrams: 250
    }),
    {
      birds: 5,
      adjustmentGrams: 0,
      totalAdjustmentGrams: 0,
      appliesAdjustment: false
    }
  );
});
