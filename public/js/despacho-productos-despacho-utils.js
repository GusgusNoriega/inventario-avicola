export const PRODUCT_DISPATCH_DRAFT_COUNT = 8;
export const PRODUCT_DISPATCH_SCALE_CODE = "BALANZA_DESPACHO_PRODUCTOS";
export const PRODUCT_PRICE_MODE_KG = "POR_KG";
export const PRODUCT_PRICE_MODE_UNIT = "POR_UNIDAD";
export const PRODUCT_DISPATCH_DEFAULT_WASTE_PRESETS = [0, 50, 100];
export const PRODUCT_DISPATCH_MAX_WASTE_TOTAL_GRAMS = 1_000_000_000;
export const PRODUCT_DISPATCH_MAX_UNIT_PRICE = 9_999_999_999.99;

export function createUuid() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();

  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    const value = character === "x" ? random : (random & 0x3) | 0x8;
    return value.toString(16);
  });
}

export function roundTo(value, decimals = 2) {
  const factor = 10 ** decimals;
  return Math.round((Number(value) + Number.EPSILON) * factor) / factor;
}

export function clampNumber(value, minimum, maximum, fallback = minimum) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.min(maximum, Math.max(minimum, number));
}

export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function productInitial(name) {
  const words = String(name || "Producto").trim().split(/\s+/).filter(Boolean);
  return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join("") || "P";
}

export function normalizePriceMode(value) {
  return value === PRODUCT_PRICE_MODE_UNIT ? PRODUCT_PRICE_MODE_UNIT : PRODUCT_PRICE_MODE_KG;
}

export function normalizeWastePresets(value) {
  if (!Array.isArray(value) || value.length !== 3) {
    return [...PRODUCT_DISPATCH_DEFAULT_WASTE_PRESETS];
  }

  const presets = value.map(Number);
  if (presets.some((preset) => (
    !Number.isSafeInteger(preset)
    || preset < 0
    || preset > 1_000_000
  ))) {
    return [...PRODUCT_DISPATCH_DEFAULT_WASTE_PRESETS];
  }

  return presets;
}

export function normalizeQuickProductIds(value, products = []) {
  const availableIds = (Array.isArray(products) ? products : [])
    .map((product) => Number(product?.id))
    .filter((id) => Number.isInteger(id) && id > 0);
  const available = new Set(availableIds);
  const target = Math.min(4, availableIds.length);
  const normalized = [];

  (Array.isArray(value) ? value : []).forEach((rawId) => {
    const id = Number(rawId);
    if (normalized.length < target && available.has(id) && !normalized.includes(id)) {
      normalized.push(id);
    }
  });

  availableIds.forEach((id) => {
    if (normalized.length < target && !normalized.includes(id)) normalized.push(id);
  });

  return normalized;
}

export function normalizeCatalog(payload = {}) {
  const source = payload?.data && !Array.isArray(payload.data) ? payload.data : payload;
  const productsSource = source.products || source.productos || [];
  const clientsSource = source.clients || source.clientes || [];

  const products = (Array.isArray(productsSource) ? productsSource : []).map((product) => ({
    id: Number(product.id),
    name: String(product.name ?? product.nombre ?? "Producto"),
    description: String(product.description ?? product.descripcion ?? ""),
    image_url: product.image_url ?? product.imagen_url ?? null,
    price: Number(product.price ?? product.precio ?? 0),
    price_mode: normalizePriceMode(product.price_mode ?? product.modo_precio),
    waste_grams_per_unit: Math.max(0, Math.round(Number(
      product.waste_grams_per_unit ?? product.merma_gramos_unidad ?? 0
    ))),
    variations: (product.variations || product.variaciones || []).map((variation) => ({
      id: Number(variation.id),
      product_id: Number(product.id),
      name: String(variation.name ?? variation.nombre ?? "Variación"),
      image_url: variation.image_url ?? variation.imagen_url ?? null,
      price: Number(variation.price ?? variation.precio ?? 0),
      price_mode: normalizePriceMode(variation.price_mode ?? variation.modo_precio),
      waste_grams_per_unit: Math.max(0, Math.round(Number(
        variation.waste_grams_per_unit ?? variation.merma_gramos_unidad ?? 0
      )))
    }))
  })).filter((product) => Number.isInteger(product.id) && product.id > 0);

  const clients = (Array.isArray(clientsSource) ? clientsSource : []).map((client) => ({
    id: Number(client.id),
    name: String(client.name ?? client.nombre ?? client.razon_social ?? "Cliente"),
    document: String(
      client.document ?? client.document_number ?? client.numero_documento ?? client.identification ?? ""
    ),
    phone: String(client.phone ?? client.telefono ?? "")
  })).filter((client) => Number.isInteger(client.id) && client.id > 0);

  return {
    products,
    clients,
    branch: source.branch || source.sucursal || null,
    company: source.company || source.empresa || null,
    user: source.user || source.usuario || null,
    currency: String(source.currency || source.moneda || "S/").trim() || "S/",
    ticket_title: String(source.ticket_title || source.titulo_ticket || "DESPACHO DE PRODUCTOS"),
    ticket_message: String(source.ticket_message || source.mensaje_ticket || "Gracias por su compra"),
    scale: source.scale || source.balanza || null,
    waste_presets: normalizeWastePresets(source.waste_presets),
    quick_product_ids: normalizeQuickProductIds(source.quick_product_ids, products)
  };
}

export function effectiveProduct(product, variation = null) {
  if (!product) return null;

  return {
    product_id: Number(product.id),
    variation_id: variation ? Number(variation.id) : null,
    product_name: String(product.name || "Producto"),
    variation_name: variation ? String(variation.name || "Variación") : null,
    display_name: variation ? `${product.name} · ${variation.name}` : String(product.name),
    image_url: variation ? (variation.image_url || null) : (product.image_url || null),
    price: Number(variation?.price ?? product.price ?? 0),
    price_mode: normalizePriceMode(variation?.price_mode ?? product.price_mode),
    waste_grams_per_unit: Math.max(0, Math.round(Number(
      variation?.waste_grams_per_unit ?? product.waste_grams_per_unit ?? 0
    )))
  };
}

export const resolveEffectiveProduct = effectiveProduct;

export function validateUnitPrice(value, maximum = PRODUCT_DISPATCH_MAX_UNIT_PRICE) {
  const raw = String(value ?? "").trim().replace(",", ".");
  if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) {
    return "Ingresa un precio válido con hasta 2 decimales.";
  }

  const price = Number(raw);
  if (!Number.isFinite(price) || price < 0.01) return "El precio mínimo es 0.01.";
  if (price > Number(maximum)) return "El precio supera el máximo permitido.";
  return "";
}

export function calculateLine(input = {}) {
  const quantity = Math.max(1, Math.round(Number(input.quantity || 1)));
  const readWeightKg = Math.max(0, roundTo(input.read_weight_kg, 3));
  const legacyWasteTotal = Math.max(0, Math.round(Number(input.waste_total_grams || 0)));
  const wasteGramsPerUnit = Math.max(0, Math.round(Number(
    input.waste_grams_per_unit ?? (legacyWasteTotal / quantity)
  ) || 0));
  const wasteTotalGrams = wasteGramsPerUnit * quantity;
  const tareGrams = Math.max(0, Math.round(Number(input.tare_grams || 0)));
  const wasteWeightKg = roundTo(wasteTotalGrams / 1000, 3);
  const tareWeightKg = roundTo(tareGrams / 1000, 3);
  const netWeightKg = Math.max(0, roundTo(readWeightKg + wasteWeightKg - tareWeightKg, 3));
  const unitPrice = Math.max(0, roundTo(input.unit_price, 2));
  const priceMode = normalizePriceMode(input.price_mode);
  const basis = priceMode === PRODUCT_PRICE_MODE_UNIT ? quantity : netWeightKg;

  return {
    quantity,
    read_weight_kg: readWeightKg,
    waste_grams_per_unit: wasteGramsPerUnit,
    waste_total_grams: wasteTotalGrams,
    waste_weight_kg: wasteWeightKg,
    tare_grams: tareGrams,
    tare_weight_kg: tareWeightKg,
    net_weight_kg: netWeightKg,
    unit_price: unitPrice,
    price_mode: priceMode,
    amount: roundTo(basis * unitPrice, 2)
  };
}

export function calculateDraft(items = []) {
  return (Array.isArray(items) ? items : []).reduce((totals, item) => {
    const line = calculateLine(item);
    totals.weighings += 1;
    totals.quantity += line.quantity;
    totals.read_weight_kg += line.read_weight_kg;
    totals.waste_total_grams += line.waste_total_grams;
    totals.tare_grams += line.tare_grams;
    totals.net_weight_kg += line.net_weight_kg;
    totals.amount += line.amount;
    totals.read_weight_kg = roundTo(totals.read_weight_kg, 3);
    totals.net_weight_kg = roundTo(totals.net_weight_kg, 3);
    totals.amount = roundTo(totals.amount, 2);
    return totals;
  }, {
    weighings: 0,
    quantity: 0,
    read_weight_kg: 0,
    waste_total_grams: 0,
    tare_grams: 0,
    net_weight_kg: 0,
    amount: 0
  });
}

export function createEmptyDraft(number = 1) {
  return {
    id: createUuid(),
    number: Number(number) || 1,
    client_id: null,
    items: [],
    updated_at: new Date().toISOString()
  };
}

export function normalizeDraft(raw, number = 1) {
  const clean = createEmptyDraft(number);
  if (!raw || typeof raw !== "object") return clean;

  clean.id = /^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(String(raw.id || ""))
    ? String(raw.id)
    : clean.id;
  clean.client_id = Number.isInteger(Number(raw.client_id)) && Number(raw.client_id) > 0
    ? Number(raw.client_id)
    : null;
  clean.items = (Array.isArray(raw.items) ? raw.items : []).filter((item) => (
    Number(item?.product_id) > 0
    && Number(item?.quantity) > 0
    && Number(item?.read_weight_kg) > 0
    && Number(item?.unit_price) > 0
  )).slice(0, 100).map((item) => ({
    ...item,
    local_id: String(item.local_id || createUuid()),
    product_id: Number(item.product_id),
    variation_id: item.variation_id ? Number(item.variation_id) : null,
    ...calculateLine(item),
    weighed_at: item.weighed_at || new Date().toISOString(),
    weight_source: item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
      ? PRODUCT_DISPATCH_SCALE_CODE
      : "MANUAL",
    scale_reading: item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
      ? (item.scale_reading || null)
      : null
  }));
  clean.updated_at = String(raw.updated_at || new Date().toISOString());
  return clean;
}

export function buildDraftCollection(rawDrafts) {
  return Array.from({ length: PRODUCT_DISPATCH_DRAFT_COUNT }, (_, index) => (
    normalizeDraft(Array.isArray(rawDrafts) ? rawDrafts[index] : null, index + 1)
  ));
}

export function createEmptyDrafts(count = PRODUCT_DISPATCH_DRAFT_COUNT) {
  const safeCount = Math.max(1, Math.round(Number(count) || PRODUCT_DISPATCH_DRAFT_COUNT));
  return Array.from({ length: safeCount }, (_, index) => createEmptyDraft(index + 1));
}

export function searchClients(clients = [], query = "") {
  const normalizedQuery = String(query || "").trim().toLocaleLowerCase("es");
  if (!normalizedQuery) return [...clients];

  return clients.filter((client) => [client.name, client.document, client.phone]
    .some((value) => String(value || "").toLocaleLowerCase("es").includes(normalizedQuery)));
}

export function updateWeighing(items = [], localId, changes = {}) {
  return items.map((item) => {
    if (String(item.local_id) !== String(localId)) return item;
    return {
      ...item,
      ...changes,
      ...calculateLine({ ...item, ...changes })
    };
  });
}

export function buildTicketPayload(draft) {
  return {
    draft_id: draft.id,
    client_id: draft.client_id || null,
    weighings: draft.items.map((item) => ({
      product_id: Number(item.product_id),
      variation_id: item.variation_id ? Number(item.variation_id) : null,
      quantity: Math.max(1, Math.round(Number(item.quantity))),
      price_mode: normalizePriceMode(item.price_mode),
      unit_price: roundTo(item.unit_price, 2).toFixed(2),
      waste_grams_per_unit: Math.max(0, Math.round(Number(item.waste_grams_per_unit || 0))),
      waste_total_grams: Math.max(0, Math.round(Number(item.waste_total_grams))),
      tare_grams: Math.max(0, Math.round(Number(item.tare_grams || 0))),
      weight_source: item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
        ? PRODUCT_DISPATCH_SCALE_CODE
        : "MANUAL",
      read_weight_kg: roundTo(item.read_weight_kg, 3).toFixed(3),
      weighed_at: item.weighed_at || new Date().toISOString(),
      scale_reading: item.weight_source === PRODUCT_DISPATCH_SCALE_CODE
        ? (item.scale_reading || null)
        : null
    }))
  };
}

export function formatMoney(value, currency = "S/") {
  return `${currencyLabel(currency)} ${Number(value || 0).toLocaleString("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })}`;
}

export function currencyLabel(currency = "S/") {
  const normalized = String(currency || "S/").trim().toUpperCase();
  if (normalized === "PEN") return "S/";
  if (normalized === "COP") return "$";
  return String(currency || "S/").trim() || "S/";
}

export function formatWeight(value) {
  return `${Number(value || 0).toLocaleString("es-PE", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3
  })} kg`;
}

export function priceModeLabel(value) {
  return normalizePriceMode(value) === PRODUCT_PRICE_MODE_UNIT ? "por unidad" : "por kg";
}
