const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const METRICS = ["quantity", "read_weight_kg", "waste_weight_kg", "tare_weight_kg", "net_weight_kg"];
const COUNTS = ["day_count", "product_count", "ticket_count", "weighing_count"];

function validDate(value) {
  const text = String(value ?? "").trim();
  if (!DATE_PATTERN.test(text)) return "";
  const date = new Date(`${text}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text ? text : "";
}

export function escapeProductDispatchGeneralHtml(value) {
  return String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
}

export function validateProductDispatchGeneralFilters(filters = {}, { allowDefault = false } = {}) {
  const rawFrom = String(filters.date_from ?? "").trim();
  const rawTo = String(filters.date_to ?? "").trim();
  if (allowDefault && !rawFrom && !rawTo) return { valid: true, values: {}, errors: {} };
  const from = validDate(rawFrom);
  const to = rawTo ? validDate(rawTo) : from;
  const errors = {};
  if (!from) errors.date_from = "Selecciona una fecha Desde válida.";
  if (rawTo && !to) errors.date_to = "Selecciona una fecha Hasta válida o déjala en blanco.";
  if (from && to && to < from) errors.date_to = "La fecha Hasta debe ser igual o posterior a Desde.";
  return { valid: !Object.keys(errors).length, values: { date_from: from, date_to: to }, errors };
}

export function buildProductDispatchGeneralQuery(filters = {}, { allowDefault = false } = {}) {
  const result = validateProductDispatchGeneralFilters(filters, { allowDefault });
  if (!result.valid) throw new Error(Object.values(result.errors)[0]);
  return new URLSearchParams(result.values).toString();
}

export function productDispatchGeneralFiltersMatch(filters, reportFilters) {
  if (!reportFilters) return false;
  const requested = validateProductDispatchGeneralFilters(filters);
  const visible = validateProductDispatchGeneralFilters(reportFilters);
  return requested.valid && visible.valid
    && requested.values.date_from === visible.values.date_from
    && requested.values.date_to === visible.values.date_to;
}

export function buildProductDispatchGeneralPdfUrl(baseUrl, reportFilters) {
  const query = buildProductDispatchGeneralQuery(reportFilters);
  const raw = String(baseUrl || "");
  const hashPosition = raw.indexOf("#");
  const hash = hashPosition < 0 ? "" : raw.slice(hashPosition);
  const base = hashPosition < 0 ? raw : raw.slice(0, hashPosition);
  const queryPosition = base.indexOf("?");
  const path = queryPosition < 0 ? base : base.slice(0, queryPosition);
  const params = new URLSearchParams(queryPosition < 0 ? "" : base.slice(queryPosition + 1));
  params.delete("preview");
  new URLSearchParams(query).forEach((value, key) => params.set(key, value));
  return `${path}?${params.toString()}${hash}`;
}

// Keep decimal strings intact until formatting, including large quantities and amounts.
export function formatProductDispatchGeneralNumber(value, decimals = 0) {
  const raw = String(value ?? "0").trim();
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(raw);
  if (match && (match[3] || "").length <= decimals) {
    const integer = match[2].replace(/^0+(?=\d)/, "").replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const fraction = decimals ? `.${(match[3] || "").padEnd(decimals, "0")}` : "";
    return `${match[1]}${integer}${fraction}`;
  }
  const number = Number(value);
  return new Intl.NumberFormat("es-PE", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(Number.isFinite(number) ? number : 0);
}

export function formatProductDispatchGeneralDate(value, long = false) {
  const normalized = validDate(value);
  if (!normalized) return "—";
  return new Intl.DateTimeFormat("es-PE", long
    ? { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }
    : { day: "2-digit", month: "short", year: "numeric", timeZone: "UTC" })
    .format(new Date(`${normalized}T12:00:00Z`));
}

function normalizedTotals(source = {}) {
  const result = {};
  METRICS.forEach((key) => { result[key] = source[key] ?? "0"; });
  COUNTS.forEach((key) => { result[key] = Math.max(0, Number(source[key]) || 0); });
  result.amounts = (Array.isArray(source.amounts) ? source.amounts : []).map((item) => ({
    currency: String(item.currency ?? "").trim().toUpperCase(),
    amount: item.amount ?? "0",
  }));
  return result;
}

export function normalizeProductDispatchGeneralReport(response = {}) {
  const source = response?.data ?? response;
  const from = validDate(source?.period?.from);
  const to = validDate(source?.period?.to);
  if (!from || !to || to < from || !Array.isArray(source.days) || !source.summary) {
    throw new Error("El reporte recibido está incompleto. Vuelve a consultar.");
  }
  return {
    period: { from, to },
    today: validDate(source.today),
    generated_at: String(source.generated_at ?? ""),
    branch: {
      id: source.branch?.id ?? null,
      name: String(source.branch?.name ?? "Sucursal actual"),
      code: String(source.branch?.code ?? ""),
      timezone: String(source.branch?.timezone ?? ""),
    },
    summary: normalizedTotals(source.summary),
    days: source.days.map((day) => ({
      ...normalizedTotals(day),
      date: validDate(day.date),
      products: (Array.isArray(day.products) ? day.products : []).map((product) => {
        const productName = String(product.product_name ?? "Producto");
        const variationId = product.variation_id ?? null;
        const variationName = String(product.variation_name ?? "").trim()
          || (variationId !== null ? `Subproducto #${variationId}` : null);
        return {
          ...normalizedTotals(product),
          product_id: product.product_id ?? null,
          product_name: productName,
          variation_id: variationId,
          variation_name: variationName,
          display_name: String(product.display_name ?? (variationName ? `${productName} · ${variationName}` : productName)),
        };
      }).sort((a, b) => a.display_name.localeCompare(b.display_name, "es", { sensitivity: "base", numeric: true })),
    })).filter((day) => day.date).sort((a, b) => a.date.localeCompare(b.date)),
  };
}

export function renderProductDispatchGeneralAmounts(amounts = []) {
  if (!amounts.length) return "—";
  return amounts.map(({ currency, amount }) => `<span class="pdgr-money-line">${escapeProductDispatchGeneralHtml(currency)} ${escapeProductDispatchGeneralHtml(formatProductDispatchGeneralNumber(amount, 2))}</span>`).join("");
}

function numericCells(row) {
  return `<td class="is-number">${formatProductDispatchGeneralNumber(row.quantity)}</td>
    <td class="is-number">${formatProductDispatchGeneralNumber(row.read_weight_kg, 3)}</td>
    <td class="is-number">${formatProductDispatchGeneralNumber(row.waste_weight_kg, 3)}</td>
    <td class="is-number">${formatProductDispatchGeneralNumber(row.tare_weight_kg, 3)}</td>
    <td class="is-number is-net">${formatProductDispatchGeneralNumber(row.net_weight_kg, 3)}</td>
    <td class="is-number is-amount">${renderProductDispatchGeneralAmounts(row.amounts)}</td>`;
}

export function renderProductDispatchGeneralDay(day, index = 0) {
  const date = new Date(`${validDate(day.date)}T12:00:00Z`);
  const month = Number.isNaN(date.getTime()) ? "" : new Intl.DateTimeFormat("es-PE", { month: "short", timeZone: "UTC" }).format(date);
  const escape = escapeProductDispatchGeneralHtml;
  return `<article class="pdgr-day pdgr-panel" aria-labelledby="pdgrDayTitle${index}">
    <header class="pdgr-day-heading">
      <div class="pdgr-day-date">
        <span class="pdgr-date-badge" aria-hidden="true"><strong>${escape(day.date.slice(-2))}</strong><span>${escape(month)}</span></span>
        <div><h3 id="pdgrDayTitle${index}">${escape(formatProductDispatchGeneralDate(day.date, true))}</h3>
          <p>${formatProductDispatchGeneralNumber(day.ticket_count)} ${day.ticket_count === 1 ? "ticket" : "tickets"} · ${formatProductDispatchGeneralNumber(day.weighing_count)} ${day.weighing_count === 1 ? "pesada" : "pesadas"}</p></div>
      </div>
      <span class="pdgr-day-count">${formatProductDispatchGeneralNumber(day.product_count)} ${day.product_count === 1 ? "producto" : "productos"}</span>
    </header>
    <div class="pdgr-table-wrap" tabindex="0" role="region" aria-label="Productos del ${escape(formatProductDispatchGeneralDate(day.date))}; tabla desplazable">
      <table class="pdgr-table">
        <thead><tr><th scope="col">Producto</th><th scope="col" class="is-number">Cantidad</th><th scope="col" class="is-number">Peso leído <small>kg</small></th><th scope="col" class="is-number">Merma <small>kg</small></th><th scope="col" class="is-number">Tara <small>kg</small></th><th scope="col" class="is-number">Peso neto <small>kg</small></th><th scope="col" class="is-number">Importe</th></tr></thead>
        <tbody>${day.products.map((product) => `<tr><th scope="row">${escape(product.display_name ?? product.product_name)}</th>${numericCells(product)}</tr>`).join("")}</tbody>
        <tfoot><tr><th scope="row">TOTAL DEL DÍA</th>${numericCells(day)}</tr></tfoot>
      </table>
    </div>
  </article>`;
}

export function mountProductDispatchGeneralReport(root, { apiRequest, authSession }) {
  const find = (name) => root.querySelector(`#pdgr${name}`);
  const fields = { date_from: find("DateFrom"), date_to: find("DateTo") };
  const ui = Object.fromEntries(["Filters", "Today", "Consult", "DownloadPdf", "Branch", "Message", "Report", "ReportTitle", "ReportPeriod", "PdfHint", "Quantity", "ProductCount", "NetWeight", "WeighingCount", "Amounts", "TicketCount", "DayCount", "Days", "Status", "Spinner", "StatusTitle", "StatusDetail", "Retry", "GeneratedAt"].map((name) => [name, find(name)]));
  const state = { requestId: 0, loading: false, downloading: false, report: null, reportFilters: null, lastFilters: null };
  const formFilters = () => ({ date_from: fields.date_from.value, date_to: fields.date_to.value });

  function message(text = "", error = false) {
    ui.Message.textContent = text;
    ui.Message.hidden = !text;
    ui.Message.classList.toggle("is-error", error);
  }

  function updateControls() {
    const busy = state.loading || state.downloading;
    const dirty = Boolean(state.reportFilters && !productDispatchGeneralFiltersMatch(formFilters(), state.reportFilters));
    [fields.date_from, fields.date_to, ui.Consult, ui.Today, ui.Retry].forEach((element) => { element.disabled = busy; });
    ui.DownloadPdf.disabled = busy || !state.report || dirty;
    ui.PdfHint.hidden = !dirty;
    ui.Report.setAttribute("aria-busy", String(state.loading));
    ui.Consult.querySelector("span").textContent = state.loading ? "Consultando…" : "Consultar";
    ui.DownloadPdf.querySelector("span").textContent = state.downloading ? "Preparando PDF…" : "Descargar PDF";
  }

  function status(kind, title = "", detail = "") {
    ui.Status.hidden = !kind;
    ui.Spinner.hidden = kind !== "loading";
    ui.Retry.hidden = kind !== "error";
    ui.StatusTitle.textContent = title;
    ui.StatusDetail.textContent = detail;
  }

  function resetSummary() {
    ["Quantity", "NetWeight", "Amounts", "TicketCount"].forEach((key) => { ui[key].textContent = "—"; });
    ui.ProductCount.textContent = "Productos del periodo";
    ui.WeighingCount.textContent = "Peso final despachado";
    ui.DayCount.textContent = "Días con despachos";
    ui.Days.innerHTML = "";
    ui.GeneratedAt.textContent = "";
  }

  function renderReport(report) {
    const { from, to } = report.period;
    const isToday = from === to && from === report.today;
    ui.ReportTitle.textContent = isToday ? "Los despachos de hoy" : (from === to ? "Los despachos del día" : "Los despachos del periodo");
    ui.ReportPeriod.textContent = from === to
      ? formatProductDispatchGeneralDate(from, true)
      : `${formatProductDispatchGeneralDate(from)} — ${formatProductDispatchGeneralDate(to)}`;
    ui.Branch.textContent = report.branch.name;
    ui.Quantity.textContent = formatProductDispatchGeneralNumber(report.summary.quantity);
    ui.ProductCount.textContent = `${formatProductDispatchGeneralNumber(report.summary.product_count)} ${report.summary.product_count === 1 ? "producto" : "productos"} en el periodo`;
    ui.NetWeight.textContent = formatProductDispatchGeneralNumber(report.summary.net_weight_kg, 3);
    ui.WeighingCount.textContent = `${formatProductDispatchGeneralNumber(report.summary.weighing_count)} ${report.summary.weighing_count === 1 ? "pesada registrada" : "pesadas registradas"}`;
    ui.Amounts.innerHTML = renderProductDispatchGeneralAmounts(report.summary.amounts);
    ui.TicketCount.textContent = formatProductDispatchGeneralNumber(report.summary.ticket_count);
    ui.DayCount.textContent = `${formatProductDispatchGeneralNumber(report.summary.day_count)} ${report.summary.day_count === 1 ? "día" : "días"} con despachos`;
    ui.Days.innerHTML = report.days.map(renderProductDispatchGeneralDay).join("");
    status(report.days.length ? "" : "empty", "Sin despachos en este periodo", "Selecciona otra fecha o amplía el rango para consultar los productos despachados.");
    if (report.generated_at) {
      const generated = new Date(report.generated_at);
      if (!Number.isNaN(generated.getTime())) {
        try {
          ui.GeneratedAt.textContent = `Actualizado: ${new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", ...(report.branch.timezone ? { timeZone: report.branch.timezone } : {}) }).format(generated)}`;
        } catch { ui.GeneratedAt.textContent = ""; }
      }
    }
  }

  async function loadReport(requestedFilters = formFilters()) {
    const defaults = requestedFilters === null;
    const validation = validateProductDispatchGeneralFilters(requestedFilters ?? {}, { allowDefault: defaults });
    Object.entries(fields).forEach(([key, field]) => { field.setAttribute("aria-invalid", String(Boolean(validation.errors[key]))); });
    if (!validation.valid) {
      message(Object.values(validation.errors)[0], true);
      fields[Object.keys(validation.errors)[0]].focus();
      updateControls();
      return false;
    }
    const requestId = ++state.requestId;
    state.lastFilters = defaults ? null : { ...requestedFilters };
    state.loading = true;
    state.report = null;
    state.reportFilters = null;
    message();
    resetSummary();
    status("loading", "Preparando el reporte…", "Estamos reuniendo los productos y sus totales.");
    ui.ReportPeriod.textContent = defaults ? "Consultando el día de hoy en la sucursal…" : "Consultando el periodo seleccionado…";
    updateControls();
    try {
      const query = buildProductDispatchGeneralQuery(validation.values, { allowDefault: defaults });
      const response = await apiRequest(`${root.dataset.apiBase || "/despacho-productos/reporte-general"}${query ? `?${query}` : ""}`, { cache: "no-store" });
      if (requestId !== state.requestId) return false;
      const report = normalizeProductDispatchGeneralReport(response);
      state.report = report;
      state.reportFilters = { date_from: report.period.from, date_to: report.period.to };
      if (defaults) {
        fields.date_from.value = report.period.from;
        fields.date_to.value = "";
      }
      renderReport(report);
      return true;
    } catch (error) {
      if (requestId !== state.requestId) return false;
      status("error", "No pudimos cargar el reporte", error.message || "Vuelve a intentarlo en unos momentos.");
      ui.ReportPeriod.textContent = "La consulta no se completó.";
      message(error.message || "No se pudo consultar el reporte general.", true);
      return false;
    } finally {
      if (requestId === state.requestId) {
        state.loading = false;
        updateControls();
      }
    }
  }

  async function downloadPdf() {
    if (state.loading || state.downloading || !state.report || !productDispatchGeneralFiltersMatch(formFilters(), state.reportFilters)) return;
    state.downloading = true;
    message();
    updateControls();
    try {
      const filters = { ...state.reportFilters };
      const url = buildProductDispatchGeneralPdfUrl(root.dataset.pdfUrl || "/despacho-productos/reporte-general/pdf", filters);
      const headers = new Headers({ Accept: "application/pdf" });
      const token = authSession.getToken();
      if (token) headers.set("Authorization", `Bearer ${token}`);
      const response = await fetch(url, { headers, credentials: "same-origin", cache: "no-store" });
      const contentType = response.headers.get("content-type") || "";
      if (response.status === 401) {
        authSession.clear();
        window.dispatchEvent(new CustomEvent("auth:expired"));
      }
      if (!response.ok || !contentType.toLowerCase().includes("application/pdf")) {
        const error = contentType.includes("application/json") ? await response.json() : {};
        throw new Error(error.message || "No se pudo generar el PDF. Vuelve a intentarlo.");
      }
      const blobUrl = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = blobUrl;
      link.download = `reporte-general_${filters.date_from}${filters.date_to !== filters.date_from ? `_al_${filters.date_to}` : ""}.pdf`;
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
      message("El PDF está listo y se ha iniciado su descarga.");
    } catch (error) {
      message(error.message || "No se pudo descargar el PDF.", true);
    } finally {
      state.downloading = false;
      updateControls();
    }
  }

  ui.Filters.addEventListener("submit", (event) => { event.preventDefault(); void loadReport(); });
  ui.Today.addEventListener("click", () => { void loadReport(null); });
  ui.Retry.addEventListener("click", () => { void loadReport(state.lastFilters); });
  ui.DownloadPdf.addEventListener("click", () => { void downloadPdf(); });
  Object.values(fields).forEach((field) => {
    ["input", "change"].forEach((eventName) => field.addEventListener(eventName, () => {
      field.removeAttribute("aria-invalid");
      message();
      updateControls();
    }));
  });
  return { loadReport, downloadPdf, ready: loadReport(null) };
}

if (typeof document !== "undefined") {
  const root = document.getElementById("productDispatchGeneralReport");
  if (root) {
    import("./api-client.js").then((api) => mountProductDispatchGeneralReport(root, api)).catch(() => {
      const status = root.querySelector("#pdgrStatus");
      status.innerHTML = '<strong>No se pudo iniciar el reporte</strong><p>Recarga la página para volver a intentarlo.</p>';
      root.querySelector("#pdgrReport").setAttribute("aria-busy", "false");
    });
  }
}
