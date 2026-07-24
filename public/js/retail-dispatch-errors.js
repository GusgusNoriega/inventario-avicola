const FIELD_LABELS = {
  draft_id: "Identificador del ticket",
  client_id: "Cliente",
  operation_type: "Tipo de operación",
  delivery: "Transporte",
  "delivery.vehicle_id": "Camión de entrega",
  "delivery.driver_id": "Chofer de entrega",
  payments: "Pago",
  weighings: "Pesadas",
  price_overrides: "Precios"
};

const PAYMENT_FIELD_LABELS = {
  idempotency_key: "identificador",
  metodo_pago_id: "método de pago",
  cuenta_destino_id: "cuenta o caja",
  moneda: "moneda",
  importe: "importe",
  referencia: "referencia",
  observaciones: "observaciones",
  fecha_hora: "fecha y hora"
};

const WEIGHING_FIELD_LABELS = {
  local_id: "identificador",
  chicken_type_code: "tipo de pollo",
  adjustment_code: "ajuste de peso",
  tray_type_code: "tipo de bandeja",
  weight_source: "origen del peso",
  scale_reading: "lectura de balanza",
  birds_per_tray: "aves por bandeja",
  tray_count: "cantidad de bandejas",
  read_weight_kg: "peso leído",
  weighed_at: "fecha y hora"
};

const CHICKEN_TYPE_LABELS = {
  POLLO_PELADO: "Pollo pelado",
  POLLO_BENEFICIADO: "Pollo beneficiado"
};

function titleCase(value) {
  const text = String(value || "")
    .replaceAll("_", " ")
    .replaceAll(".", " · ")
    .trim();

  return text ? `${text.charAt(0).toUpperCase()}${text.slice(1)}` : "Dato";
}

export function retailDispatchFieldLabel(field) {
  const key = String(field || "");
  if (FIELD_LABELS[key]) return FIELD_LABELS[key];

  const payment = key.match(/^payments\.(\d+)\.([^.]+)$/);
  if (payment) {
    return `Pago ${Number(payment[1]) + 1} · ${PAYMENT_FIELD_LABELS[payment[2]] || titleCase(payment[2])}`;
  }

  const weighing = key.match(/^weighings\.(\d+)\.([^.]+)(?:\..+)?$/);
  if (weighing) {
    return `Pesada ${Number(weighing[1]) + 1} · ${WEIGHING_FIELD_LABELS[weighing[2]] || titleCase(weighing[2])}`;
  }

  const price = key.match(/^price_overrides\.([^.]+)$/);
  if (price) {
    return `Precio · ${CHICKEN_TYPE_LABELS[price[1]] || titleCase(price[1])}`;
  }

  return titleCase(key);
}

function validationDetails(errors) {
  if (!errors || typeof errors !== "object" || Array.isArray(errors)) return [];

  return Object.entries(errors)
    .flatMap(([field, messages]) => {
      const values = Array.isArray(messages) ? messages : [messages];
      return values.map((message) => ({
        label: retailDispatchFieldLabel(field),
        value: String(message || "").trim()
      }));
    })
    .filter((detail) => detail.value);
}

export function getRetailDispatchErrorPresentation(error) {
  const status = Number(error?.status || 0);
  const details = validationDetails(error?.data?.errors);

  if (details.length && status < 500) {
    return {
      caption: "Datos por corregir",
      title: "Revisa los datos del ticket",
      message: "No se guardó el despacho. Corrige los motivos indicados e inténtalo nuevamente.",
      summary: details[0].value,
      details
    };
  }

  if (status === 401 || status === 419) {
    const value = "La sesión venció. Inicia sesión nuevamente y vuelve a grabar; la lista continúa en esta pantalla.";
    return {
      caption: "Sesión vencida",
      title: "La sesión ya no está activa",
      message: value,
      summary: value,
      details: [{ label: "Sesión", value }],
      action: "login"
    };
  }

  if (status === 403) {
    const value = "Tu usuario no tiene permiso para registrar despachos minoristas.";
    return {
      caption: "Acceso denegado",
      title: "No se pudo guardar el ticket",
      message: value,
      summary: value,
      details: [{ label: "Permiso", value }]
    };
  }

  if (status === 429) {
    const value = "Se realizaron demasiados intentos. Espera un momento y vuelve a presionar Grabar.";
    return {
      caption: "Demasiados intentos",
      title: "Espera antes de volver a intentar",
      message: value,
      summary: value,
      details: [{ label: "Servidor", value }]
    };
  }

  if (status >= 500) {
    const value = `El servidor encontró un problema interno (HTTP ${status}) y no guardó el despacho. Inténtalo nuevamente o comunica este mensaje al administrador.`;
    return {
      caption: "Error del servidor",
      title: "No se pudo guardar el ticket",
      message: "Ocurrió un error interno mientras se grababa el despacho.",
      summary: value,
      details: [{ label: "Servidor", value }]
    };
  }

  if (!status) {
    const value = "No fue posible comunicarse con el servidor. Revisa la conexión e inténtalo nuevamente.";
    return {
      caption: "Problema de conexión",
      title: "Sin conexión con el servidor",
      message: value,
      summary: value,
      details: [{ label: "Conexión", value }]
    };
  }

  const value = String(error?.message || "No se pudo completar la solicitud.");
  return {
    caption: "Solicitud rechazada",
    title: "No se pudo guardar el ticket",
    message: value,
    summary: value,
    details: [{ label: `Solicitud HTTP ${status}`, value }]
  };
}
