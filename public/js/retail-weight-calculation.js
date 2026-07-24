export const RETAIL_PROCESSED_CHICKEN_CODE = "POLLO_BENEFICIADO";

export function calculateRetailBirdCount(trayCount, birdsPerTray) {
  const trays = Number(trayCount);
  const birds = Number(birdsPerTray);

  if (!Number.isFinite(trays) || !Number.isFinite(birds)) return 0;

  return birds * Math.max(trays, 1);
}

export function retailAdjustmentGramsForChicken(chickenTypeCode, configuredGrams) {
  if (String(chickenTypeCode || "").toUpperCase() === RETAIL_PROCESSED_CHICKEN_CODE) {
    return 0;
  }

  const grams = Number(configuredGrams);
  return Number.isFinite(grams) ? grams : 0;
}

export function calculateRetailWeightAdjustment({
  chickenTypeCode,
  trayCount,
  birdsPerTray,
  configuredAdjustmentGrams
}) {
  const birds = calculateRetailBirdCount(trayCount, birdsPerTray);
  const adjustmentGrams = retailAdjustmentGramsForChicken(
    chickenTypeCode,
    configuredAdjustmentGrams
  );

  return {
    birds,
    adjustmentGrams,
    totalAdjustmentGrams: adjustmentGrams * birds,
    appliesAdjustment: String(chickenTypeCode || "").toUpperCase() !== RETAIL_PROCESSED_CHICKEN_CODE
  };
}
