export const PRICE_MODE_KG = "POR_KG";
export const PRICE_MODE_UNIT = "POR_UNIDAD";

export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function productInitial(name) {
  const words = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  return words.slice(0, 2).map((word) => word[0]).join("").toUpperCase() || "AV";
}

export function formatSalePrice(value, mode) {
  const amount = Number(value || 0);
  const formatted = new Intl.NumberFormat("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4
  }).format(Number.isFinite(amount) ? amount : 0);
  const unit = mode === PRICE_MODE_UNIT ? "unidad" : "kg";

  return `S/ ${formatted} / ${unit}`;
}

export function imageFileError(file, maxBytes = 4 * 1024 * 1024) {
  if (!file) {
    return "";
  }

  if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
    return "La imagen debe estar en formato JPG, PNG o WEBP.";
  }

  if (file.size > maxBytes) {
    return "La imagen no puede pesar más de 4 MB.";
  }

  return "";
}
