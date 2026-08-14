export const WHOLESALE_TWO_PROCESSED_VARIANT_CODE = "POLLO_BENEFICIADO";

function roundWeight(value) {
  return Math.round((Number(value || 0) + Number.EPSILON) * 1000) / 1000;
}

function nonNegativeInteger(value) {
  const numeric = Math.trunc(Number(value));
  return Number.isFinite(numeric) && numeric >= 0 ? numeric : 0;
}

export function wholesaleTwoAdjustmentGramsForVariant(
  variantCode,
  configuredGrams,
  operationType = "DESPACHO"
) {
  if (
    String(operationType || "").toUpperCase() !== "DESPACHO"
    || String(variantCode || "").toUpperCase() === WHOLESALE_TWO_PROCESSED_VARIANT_CODE
  ) {
    return 0;
  }

  return nonNegativeInteger(configuredGrams);
}

export function calculateWholesaleTwoWeightBreakdown({
  variantCode,
  operationType = "DESPACHO",
  readWeightKg,
  cageCount,
  birdsPerCage,
  crateWeightKg,
  configuredAdjustmentGrams
}) {
  const readWeight = roundWeight(readWeightKg);
  const cages = nonNegativeInteger(cageCount);
  const birdsPerJava = nonNegativeInteger(birdsPerCage);
  const birds = birdsPerJava * Math.max(cages, 1);
  const crateWeight = roundWeight(crateWeightKg);
  const adjustmentGrams = wholesaleTwoAdjustmentGramsForVariant(
    variantCode,
    configuredAdjustmentGrams,
    operationType
  );
  const totalAdjustmentGrams = adjustmentGrams * birds;
  const adjustmentWeightKg = roundWeight(totalAdjustmentGrams / 1000);
  const grossWeight = roundWeight(readWeight + adjustmentWeightKg);
  const tareWeightKg = roundWeight(cages * crateWeight);
  const netWeightKg = roundWeight(grossWeight - tareWeightKg);

  return {
    readWeightKg: readWeight,
    scaleWeightKg: readWeight,
    grossWeight,
    cageCount: cages,
    javaCount: cages,
    birdsPerCage: birdsPerJava,
    birdCount: birds,
    crateWeightKg: crateWeight,
    adjustmentGrams,
    totalAdjustmentGrams,
    adjustmentWeightKg,
    tareWeightKg,
    netWeightKg,
    appliesAdjustment: adjustmentGrams > 0
  };
}
