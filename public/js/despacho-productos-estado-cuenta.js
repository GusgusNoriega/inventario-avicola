const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const CURRENCY_PATTERN = /^[A-Z]{3}$/;

function positiveInteger(value) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : null;
}

function nullableNumber(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function numberValue(value) {
  return nullableNumber(value) ?? 0;
}

function normalizeDate(value) {
  const date = String(value || "").trim();
  if (!ISO_DATE_PATTERN.test(date)) return "";
  const parsed = new Date(`${date}T00:00:00Z`);
  return Number.isNaN(parsed.getTime()) || parsed.toISOString().slice(0, 10) !== date ? "" : date;
}

function localIsoDate(date = new Date()) {
  const pad = (value) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function firstDayOfMonth(date = new Date()) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-01`;
}

function responseData(response = {}) {
  return response?.data && !Array.isArray(response.data) ? response.data : response;
}

export function escapeProductDispatchAccountHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function productDispatchAccountDefaultPeriod(date = new Date()) {
  return {
    date_from: firstDayOfMonth(date),
    date_to: localIsoDate(date),
  };
}

export function validateProductDispatchAccountFilters(filters = {}) {
  const values = {
    client_id: positiveInteger(filters.client_id),
    date_from: normalizeDate(filters.date_from),
    date_to: normalizeDate(filters.date_to),
    currency: String(filters.currency || "").trim().toUpperCase(),
  };
  const errors = {};

  if (!values.client_id) errors.client_id = "Elige un cliente para generar el estado de cuenta.";
  if (!values.date_from) errors.date_from = "Selecciona una fecha inicial válida.";
  if (!values.date_to) errors.date_to = "Selecciona una fecha final válida.";
  if (values.date_from && values.date_to && values.date_to < values.date_from) {
    errors.date_to = "La fecha final debe ser igual o posterior a la fecha inicial.";
  }
  if (!CURRENCY_PATTERN.test(values.currency)) {
    errors.currency = "Selecciona una moneda válida.";
  }

  return {
    valid: Object.keys(errors).length === 0,
    values,
    errors,
  };
}

export function buildProductDispatchAccountQuery(filters = {}) {
  const validation = validateProductDispatchAccountFilters(filters);
  const params = new URLSearchParams();
  const values = validation.values;

  if (values.client_id) params.set("client_id", String(values.client_id));
  if (values.date_from) params.set("date_from", values.date_from);
  if (values.date_to) params.set("date_to", values.date_to);
  if (CURRENCY_PATTERN.test(values.currency)) params.set("currency", values.currency);

  return params.toString();
}

export function buildProductDispatchAccountPdfUrl(baseUrl, filters = {}, preview = false) {
  const rawBase = String(baseUrl || "").trim();
  const hashIndex = rawBase.indexOf("#");
  const hash = hashIndex >= 0 ? rawBase.slice(hashIndex) : "";
  const withoutHash = hashIndex >= 0 ? rawBase.slice(0, hashIndex) : rawBase;
  const queryIndex = withoutHash.indexOf("?");
  const path = queryIndex >= 0 ? withoutHash.slice(0, queryIndex) : withoutHash;
  const params = new URLSearchParams(queryIndex >= 0 ? withoutHash.slice(queryIndex + 1) : "");
  const reportParams = new URLSearchParams(buildProductDispatchAccountQuery(filters));

  reportParams.forEach((value, key) => params.set(key, value));
  if (preview) params.set("preview", "1");
  else params.delete("preview");

  const query = params.toString();
  return `${path}${query ? `?${query}` : ""}${hash}`;
}

export function normalizeProductDispatchAccountCatalog(response = {}) {
  const source = responseData(response);
  const clients = (Array.isArray(source?.clients) ? source.clients : [])
    .map((client) => ({
      id: positiveInteger(client?.id),
      name: String(client?.name ?? client?.nombre ?? "Cliente").trim() || "Cliente",
      document_type: String(client?.document_type ?? client?.tipo_documento ?? "").trim(),
      document: String(client?.document ?? client?.numero_documento ?? "").trim(),
    }))
    .filter((client) => client.id)
    .sort((left, right) => left.name.localeCompare(right.name, "es", { sensitivity: "base" }));
  const currencies = [...new Set((Array.isArray(source?.currencies) ? source.currencies : [])
    .map((currency) => String(currency || "").trim().toUpperCase())
    .filter((currency) => CURRENCY_PATTERN.test(currency)))];
  const requestedDefault = String(source?.default_currency || "").trim().toUpperCase();
  const defaultCurrency = CURRENCY_PATTERN.test(requestedDefault)
    ? requestedDefault
    : (currencies[0] || "PEN");

  if (!currencies.includes(defaultCurrency)) currencies.unshift(defaultCurrency);

  return {
    clients,
    currencies,
    default_currency: defaultCurrency,
    branch: source?.branch && typeof source.branch === "object"
      ? {
        id: positiveInteger(source.branch.id),
        name: String(source.branch.name ?? source.branch.nombre ?? "Sucursal").trim() || "Sucursal",
      }
      : null,
  };
}

export function normalizeProductDispatchAccountStatement(response = {}) {
  const source = responseData(response);
  const currency = String(source?.currency || "PEN").trim().toUpperCase();
  const rows = (Array.isArray(source?.rows) ? source.rows : []).map((row) => {
    const rawKind = String(row?.kind || "SALE").trim().toUpperCase();
    const kind = ["PAYMENT", "PAGO", "COBRO", "COLLECTION"].includes(rawKind)
      ? "PAYMENT"
      : "SALE";

    return {
      kind,
      date: normalizeDate(row?.date),
      document: String(row?.document || "").trim(),
      product: String(row?.product || "").trim(),
      variation: String(row?.variation || "").trim(),
      quantity: nullableNumber(row?.quantity),
      net_weight_kg: nullableNumber(row?.net_weight_kg),
      detail: String(row?.detail || "").trim(),
      price: nullableNumber(row?.price),
      price_mode: String(row?.price_mode || "").trim().toUpperCase(),
      payment_type: String(row?.payment_type || "").trim().toUpperCase(),
      movement_label: String(row?.movement_label || "").trim(),
      sale: numberValue(row?.sale),
      payment: numberValue(row?.payment),
      balance: nullableNumber(row?.balance),
      show_balance: Boolean(row?.show_balance),
    };
  });

  return {
    client: source?.client && typeof source.client === "object"
      ? {
        id: positiveInteger(source.client.id),
        name: String(source.client.name ?? source.client.nombre ?? "Cliente").trim() || "Cliente",
        document_type: String(source.client.document_type ?? source.client.tipo_documento ?? "").trim(),
        document: String(source.client.document ?? source.client.numero_documento ?? "").trim(),
      }
      : null,
    period: {
      date_from: normalizeDate(source?.period?.date_from ?? source?.period?.from),
      date_to: normalizeDate(source?.period?.date_to ?? source?.period?.to),
    },
    branch: source?.branch && typeof source.branch === "object"
      ? {
        id: positiveInteger(source.branch.id),
        name: String(source.branch.name ?? source.branch.nombre ?? "Sucursal").trim() || "Sucursal",
      }
      : null,
    currency: CURRENCY_PATTERN.test(currency) ? currency : "PEN",
    opening_balance: numberValue(source?.opening_balance),
    sales_total: numberValue(source?.sales_total),
    payments_total: numberValue(source?.payments_total),
    ending_balance: numberValue(source?.ending_balance),
    ticket_count: Math.max(0, Number(source?.ticket_count) || 0),
    payment_count: Math.max(0, Number(source?.payment_count) || 0),
    rows,
  };
}

export function filterProductDispatchAccountClients(clients = [], query = "") {
  const searchable = (value) => String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es");
  const normalized = searchable(String(query || "").trim());
  if (!normalized) return [...clients];

  return clients.filter((client) => [client.name, client.document_type, client.document]
    .some((value) => searchable(value).includes(normalized)));
}

export function productDispatchAccountPriceModeLabel(mode) {
  return String(mode || "").toUpperCase() === "POR_UNIDAD" ? "Por unidad" : "Por kilogramo";
}

function formatMoney(value, currency) {
  try {
    return new Intl.NumberFormat("es-PE", {
      style: "currency",
      currency: CURRENCY_PATTERN.test(currency) ? currency : "PEN",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(numberValue(value));
  } catch {
    return `${currency || ""} ${numberValue(value).toFixed(2)}`.trim();
  }
}

function formatNumber(value, maximumFractionDigits = 3) {
  const number = nullableNumber(value);
  if (number === null) return "—";
  return new Intl.NumberFormat("es-PE", {
    minimumFractionDigits: 0,
    maximumFractionDigits,
  }).format(number);
}

function formatDate(value) {
  const normalized = normalizeDate(value);
  if (!normalized) return "—";
  const [year, month, day] = normalized.split("-");
  return `${day}/${month}/${year}`;
}

function plural(count, singular, pluralForm) {
  return `${count} ${count === 1 ? singular : pluralForm}`;
}

function openDialog(dialog, focusTarget = null) {
  if (!dialog) return;
  if (typeof dialog.showModal === "function") dialog.showModal();
  else dialog.setAttribute("open", "");
  if (focusTarget) requestAnimationFrame(() => focusTarget.focus());
}

function closeDialog(dialog) {
  if (!dialog) return;
  if (typeof dialog.close === "function") dialog.close();
  else dialog.removeAttribute("open");
}

function mountProductDispatchAccountStatement() {
  if (typeof document === "undefined") return;
  const root = document.querySelector("#productDispatchAccountStatement");
  if (!root) return;

  import("./api-client.js").then(({ apiRequest }) => {
    const apiBase = root.dataset.apiBase || "/despacho-productos/estado-cuenta";
    const pdfBaseUrl = root.dataset.pdfUrl || "/despacho-productos/estado-cuenta/pdf";
    const elements = {
      filters: document.querySelector("#pdasFilters"),
      chooseClient: document.querySelector("#pdasChooseClient"),
      clientButtonTitle: document.querySelector("#pdasClientButtonTitle"),
      clientButtonDetail: document.querySelector("#pdasClientButtonDetail"),
      selectedClient: document.querySelector("#pdasSelectedClient"),
      selectedClientName: document.querySelector("#pdasSelectedClientName"),
      selectedClientDocument: document.querySelector("#pdasSelectedClientDocument"),
      dateFrom: document.querySelector("#pdasDateFrom"),
      dateTo: document.querySelector("#pdasDateTo"),
      currency: document.querySelector("#pdasCurrency"),
      consult: document.querySelector("#pdasConsult"),
      branchLabel: document.querySelector("#pdasBranchLabel"),
      message: document.querySelector("#pdasMessage"),
      report: document.querySelector("#pdasReport"),
      reportTitle: document.querySelector("#pdasReportTitle"),
      reportPeriod: document.querySelector("#pdasReportPeriod"),
      previewPdf: document.querySelector("#pdasPreviewPdf"),
      downloadPdf: document.querySelector("#pdasDownloadPdf"),
      openingBalance: document.querySelector("#pdasOpeningBalance"),
      salesTotal: document.querySelector("#pdasSalesTotal"),
      paymentsTotal: document.querySelector("#pdasPaymentsTotal"),
      endingBalanceLabel: document.querySelector("#pdasEndingBalanceLabel"),
      endingBalance: document.querySelector("#pdasEndingBalance"),
      ticketCount: document.querySelector("#pdasTicketCount"),
      paymentCount: document.querySelector("#pdasPaymentCount"),
      rowCount: document.querySelector("#pdasRowCount"),
      rows: document.querySelector("#pdasRows"),
      clientDialog: document.querySelector("#pdasClientDialog"),
      clientSearch: document.querySelector("#pdasClientSearch"),
      clientList: document.querySelector("#pdasClientList"),
      pdfDialog: document.querySelector("#pdasPdfDialog"),
      pdfSubtitle: document.querySelector("#pdasPdfSubtitle"),
      pdfLoading: document.querySelector("#pdasPdfLoading"),
      pdfFrame: document.querySelector("#pdasPdfFrame"),
      openPdfTab: document.querySelector("#pdasOpenPdfTab"),
    };
    const query = new URLSearchParams(window.location.search);
    const defaults = productDispatchAccountDefaultPeriod();
    const state = {
      catalog: { clients: [], currencies: [], default_currency: "PEN", branch: null },
      clientId: positiveInteger(query.get("client_id")),
      loadingCatalog: true,
      refreshingCatalog: false,
      loadingReport: false,
      reportFilters: null,
      report: null,
    };

    elements.dateFrom.value = normalizeDate(query.get("date_from")) || defaults.date_from;
    elements.dateTo.value = normalizeDate(query.get("date_to")) || defaults.date_to;

    function selectedClient() {
      return state.catalog.clients.find((client) => client.id === state.clientId) || null;
    }

    function clientDocumentLabel(client) {
      if (!client?.document) return "Sin documento registrado";
      return [client.document_type, client.document].filter(Boolean).join(" ");
    }

    function setMessage(message = "", type = "") {
      elements.message.textContent = message;
      elements.message.classList.toggle("is-error", type === "error");
      elements.message.classList.toggle("is-success", type === "success");
    }

    function markValidation(errors = {}) {
      elements.chooseClient.toggleAttribute("aria-invalid", Boolean(errors.client_id));
      elements.dateFrom.toggleAttribute("aria-invalid", Boolean(errors.date_from));
      elements.dateTo.toggleAttribute("aria-invalid", Boolean(errors.date_to));
      elements.currency.toggleAttribute("aria-invalid", Boolean(errors.currency));
    }

    function setPdfLink(link, href = "") {
      if (!href) {
        link.removeAttribute("href");
        link.setAttribute("aria-disabled", "true");
        link.classList.add("is-disabled");
        link.tabIndex = -1;
        return;
      }

      link.href = href;
      link.removeAttribute("aria-disabled");
      link.classList.remove("is-disabled");
      link.removeAttribute("tabindex");
    }

    function updatePdfActions() {
      const enabled = Boolean(state.reportFilters && state.report && !state.loadingReport);
      elements.previewPdf.disabled = !enabled;
      setPdfLink(
        elements.downloadPdf,
        enabled ? buildProductDispatchAccountPdfUrl(pdfBaseUrl, state.reportFilters, false) : "",
      );
    }

    function renderSelectedClient() {
      const client = selectedClient();
      elements.selectedClient.hidden = !client;
      elements.chooseClient.toggleAttribute("aria-invalid", false);
      elements.clientButtonTitle.textContent = client?.name || "Elegir cliente";
      elements.clientButtonDetail.textContent = client
        ? clientDocumentLabel(client)
        : "Busca por nombre o documento";
      elements.selectedClientName.textContent = client?.name || "—";
      elements.selectedClientDocument.textContent = client ? clientDocumentLabel(client) : "—";
      elements.consult.disabled = state.loadingCatalog || state.loadingReport || !client;
    }

    function renderCurrencyOptions(preferred = "") {
      const requested = String(preferred || "").trim().toUpperCase();
      elements.currency.replaceChildren(...state.catalog.currencies.map((currency) => {
        const option = document.createElement("option");
        option.value = currency;
        option.textContent = currency === "PEN" ? "Soles (PEN)" : (currency === "USD" ? "Dólares (USD)" : currency);
        return option;
      }));
      elements.currency.value = state.catalog.currencies.includes(requested)
        ? requested
        : state.catalog.default_currency;
      elements.currency.disabled = state.loadingCatalog || state.loadingReport;
    }

    function renderClientList(search = "") {
      const matches = filterProductDispatchAccountClients(state.catalog.clients, search);
      if (!matches.length) {
        elements.clientList.innerHTML = `<div class="pdas-client-empty"><strong>No encontramos clientes</strong><span>Prueba con otro nombre o documento.</span></div>`;
        return;
      }

      const visible = matches.slice(0, 120);
      elements.clientList.innerHTML = visible.map((client) => `
        <button
          class="pdas-client-option"
          type="button"
          data-selected="${client.id === state.clientId ? "true" : "false"}"
          data-pdas-client-id="${client.id}"
        >
          <span aria-hidden="true">${escapeProductDispatchAccountHtml(client.name.charAt(0).toUpperCase() || "C")}</span>
          <span>
            <strong>${escapeProductDispatchAccountHtml(client.name)}</strong>
            <small>${escapeProductDispatchAccountHtml(clientDocumentLabel(client))}</small>
          </span>
          <em>Elegir</em>
        </button>
      `).join("");
    }

    function selectClient(clientId) {
      state.clientId = positiveInteger(clientId);
      state.reportFilters = null;
      state.report = null;
      resetReportPresentation("Pulsa Consultar para generar el estado de cuenta de este cliente.");
      updatePdfActions();
      renderSelectedClient();
      closeDialog(elements.clientDialog);
      setMessage("");
    }

    function emptyRows(title, description) {
      elements.rows.innerHTML = `
        <tr class="pdas-empty-row">
          <td colspan="10">
            <strong>${escapeProductDispatchAccountHtml(title)}</strong>
            <span>${escapeProductDispatchAccountHtml(description)}</span>
          </td>
        </tr>`;
    }

    function resetReportPresentation(instruction = "Elige un cliente y un periodo para ver el estado de cuenta.") {
      const client = selectedClient();
      elements.reportTitle.textContent = client ? `Estado de cuenta de ${client.name}` : "Estado de cuenta";
      elements.reportPeriod.textContent = instruction;
      elements.openingBalance.textContent = "—";
      elements.salesTotal.textContent = "—";
      elements.paymentsTotal.textContent = "—";
      elements.endingBalanceLabel.textContent = "Deuda final";
      elements.endingBalance.textContent = "—";
      elements.ticketCount.textContent = "0 tickets";
      elements.paymentCount.textContent = "0 abonos";
      elements.rowCount.textContent = "Sin consulta";
      emptyRows("Aún no hay una consulta", instruction);
    }

    function rowProduct(row) {
      if (row.kind === "PAYMENT") {
        return {
          title: row.movement_label || "Abono aplicado",
          subtitle: row.payment_type === "DESCUENTO_CLIENTE"
            ? "Sin movimiento de dinero"
            : "Aplicado a este módulo",
        };
      }
      return {
        title: [row.product, row.variation].filter(Boolean).join(" · ") || "Venta",
        subtitle: row.price_mode ? productDispatchAccountPriceModeLabel(row.price_mode) : "Salida de producto",
      };
    }

    function renderRows(rows, currency) {
      if (!rows.length) {
        emptyRows("Sin movimientos en este periodo", "El saldo anterior se conserva en el resumen.");
        return;
      }

      let previousDate = null;
      elements.rows.innerHTML = rows.map((row) => {
        const product = rowProduct(row);
        const dayStart = previousDate !== null && previousDate !== row.date;
        previousDate = row.date;
        const sale = row.sale > 0 ? formatMoney(row.sale, currency) : "—";
        const payment = row.payment > 0 ? formatMoney(row.payment, currency) : "—";
        const balance = row.show_balance && row.balance !== null
          ? formatMoney(row.balance, currency)
          : "—";

        return `<tr class="${row.kind === "PAYMENT" ? "is-payment" : "is-sale"}${dayStart ? " is-day-start" : ""}">
          <td>${escapeProductDispatchAccountHtml(formatDate(row.date))}</td>
          <td class="pdas-document-cell">${escapeProductDispatchAccountHtml(row.document || "—")}</td>
          <td class="pdas-kind-cell"><strong>${escapeProductDispatchAccountHtml(product.title)}</strong><small>${escapeProductDispatchAccountHtml(product.subtitle)}</small></td>
          <td class="is-number">${escapeProductDispatchAccountHtml(formatNumber(row.quantity, 2))}</td>
          <td class="is-number">${escapeProductDispatchAccountHtml(formatNumber(row.net_weight_kg, 3))}</td>
          <td>${escapeProductDispatchAccountHtml(row.detail || "—")}</td>
          <td class="is-number">${row.price !== null ? escapeProductDispatchAccountHtml(formatMoney(row.price, currency)) : "—"}</td>
          <td class="is-number is-sale-value">${escapeProductDispatchAccountHtml(sale)}</td>
          <td class="is-number is-payment-value">${escapeProductDispatchAccountHtml(payment)}</td>
          <td class="is-number is-balance-value">${escapeProductDispatchAccountHtml(balance)}</td>
        </tr>`;
      }).join("");
    }

    function renderReport(report) {
      const client = report.client || selectedClient();
      const from = report.period.date_from || state.reportFilters?.date_from;
      const to = report.period.date_to || state.reportFilters?.date_to;
      const branch = report.branch?.name || state.catalog.branch?.name || "Sucursal actual";

      elements.reportTitle.textContent = client ? `Estado de cuenta de ${client.name}` : "Estado de cuenta";
      elements.reportPeriod.textContent = `Periodo ${formatDate(from)} al ${formatDate(to)} · ${branch} · ${report.currency}`;
      elements.openingBalance.textContent = formatMoney(report.opening_balance, report.currency);
      elements.salesTotal.textContent = formatMoney(report.sales_total, report.currency);
      elements.paymentsTotal.textContent = formatMoney(report.payments_total, report.currency);
      elements.endingBalanceLabel.textContent = report.ending_balance < 0 ? "Saldo a favor" : "Deuda final";
      elements.endingBalance.textContent = formatMoney(Math.abs(report.ending_balance), report.currency);
      elements.ticketCount.textContent = plural(report.ticket_count, "ticket", "tickets");
      elements.paymentCount.textContent = plural(report.payment_count, "abono", "abonos");
      elements.rowCount.textContent = plural(report.rows.length, "movimiento", "movimientos");
      renderRows(report.rows, report.currency);
    }

    function filtersFromForm() {
      return {
        client_id: state.clientId,
        date_from: elements.dateFrom.value,
        date_to: elements.dateTo.value,
        currency: elements.currency.value,
      };
    }

    function setReportLoading(loading) {
      state.loadingReport = loading;
      elements.report.setAttribute("aria-busy", loading ? "true" : "false");
      elements.chooseClient.disabled = state.loadingCatalog || loading;
      elements.dateFrom.disabled = loading;
      elements.dateTo.disabled = loading;
      elements.currency.disabled = state.loadingCatalog || loading;
      elements.consult.disabled = state.loadingCatalog || loading || !selectedClient();
      elements.consult.querySelector("span").textContent = loading ? "Consultando…" : "Consultar";
      updatePdfActions();
    }

    function updateAddressBar(filters) {
      const queryString = buildProductDispatchAccountQuery(filters);
      window.history.replaceState({}, "", `${window.location.pathname}${queryString ? `?${queryString}` : ""}`);
    }

    async function loadReport(filters = filtersFromForm()) {
      if (state.loadingReport) return;
      const validation = validateProductDispatchAccountFilters(filters);
      markValidation(validation.errors);
      if (!validation.valid) {
        setMessage(Object.values(validation.errors)[0], "error");
        return;
      }

      state.reportFilters = null;
      state.report = null;
      resetReportPresentation("Consultando las ventas y pagos del periodo…");
      setReportLoading(true);
      setMessage("Consultando las ventas y abonos de este módulo…");
      elements.rowCount.textContent = "Consultando…";
      emptyRows("Consultando movimientos…", "Estamos preparando el estado de cuenta.");

      try {
        const response = await apiRequest(`${apiBase}?${buildProductDispatchAccountQuery(validation.values)}`);
        const report = normalizeProductDispatchAccountStatement(response);
        state.reportFilters = validation.values;
        state.report = report;
        renderReport(report);
        updateAddressBar(validation.values);
        setMessage("Estado de cuenta actualizado. Ya puedes revisar o descargar el PDF.", "success");
      } catch (error) {
        state.reportFilters = null;
        state.report = null;
        elements.rowCount.textContent = "Error de consulta";
        emptyRows("No pudimos cargar el estado de cuenta", error.message || "Intenta nuevamente.");
        setMessage(error.message || "No se pudo consultar el estado de cuenta.", "error");
      } finally {
        setReportLoading(false);
      }
    }

    async function refreshClientCatalog() {
      if (state.loadingCatalog || state.refreshingCatalog) return;

      state.refreshingCatalog = true;
      elements.clientList.setAttribute("aria-busy", "true");
      const previousClientId = state.clientId;
      const preferredCurrency = elements.currency.value;

      try {
        const response = await apiRequest(`${apiBase}/catalogo`, { cache: "no-store" });
        state.catalog = normalizeProductDispatchAccountCatalog(response);
        elements.branchLabel.textContent = state.catalog.branch?.name
          ? `Sucursal: ${state.catalog.branch.name}`
          : "Sucursal actual";
        renderCurrencyOptions(preferredCurrency);

        if (!selectedClient()) {
          state.clientId = null;
          if (previousClientId) {
            state.reportFilters = null;
            state.report = null;
            resetReportPresentation("El cliente seleccionado ya no está disponible. Elige otro cliente.");
            updatePdfActions();
          }
        }

        renderSelectedClient();
        renderClientList(elements.clientSearch.value);
      } catch (error) {
        setMessage(
          error.message || "No pudimos actualizar los clientes. Mostramos la lista disponible.",
          "error",
        );
      } finally {
        state.refreshingCatalog = false;
        elements.clientList.removeAttribute("aria-busy");
      }
    }

    function openClientSelector() {
      elements.clientSearch.value = "";
      renderClientList();
      openDialog(elements.clientDialog, elements.clientSearch);
      void refreshClientCatalog();
    }

    function openPdfPreview() {
      if (!state.reportFilters || !state.report) return;
      const previewUrl = buildProductDispatchAccountPdfUrl(pdfBaseUrl, state.reportFilters, true);
      const client = state.report.client || selectedClient();
      elements.pdfSubtitle.textContent = client
        ? `${client.name} · ${formatDate(state.reportFilters.date_from)} al ${formatDate(state.reportFilters.date_to)}`
        : "Estado de cuenta";
      elements.pdfLoading.hidden = false;
      elements.pdfFrame.src = previewUrl;
      elements.openPdfTab.href = previewUrl;
      openDialog(elements.pdfDialog);
    }

    function closePdfPreview() {
      closeDialog(elements.pdfDialog);
      elements.pdfFrame.src = "about:blank";
      elements.pdfLoading.hidden = false;
    }

    async function loadCatalog() {
      setMessage("Cargando clientes y monedas disponibles…");
      try {
        const response = await apiRequest(`${apiBase}/catalogo`, { cache: "no-store" });
        state.catalog = normalizeProductDispatchAccountCatalog(response);
        state.loadingCatalog = false;
        elements.branchLabel.textContent = state.catalog.branch?.name
          ? `Sucursal: ${state.catalog.branch.name}`
          : "Sucursal actual";
        elements.chooseClient.disabled = false;
        renderCurrencyOptions(query.get("currency"));

        if (!selectedClient()) state.clientId = null;
        renderSelectedClient();
        setMessage("");

        const initial = validateProductDispatchAccountFilters(filtersFromForm());
        if (initial.valid) await loadReport(initial.values);
        else if (!state.catalog.clients.length) {
          setMessage("No hay clientes disponibles para consultar.", "error");
        }
      } catch (error) {
        state.loadingCatalog = false;
        elements.branchLabel.textContent = "No disponible";
        elements.chooseClient.disabled = true;
        elements.currency.disabled = true;
        elements.consult.disabled = true;
        setMessage(error.message || "No se pudo cargar el catálogo del reporte.", "error");
      }
    }

    elements.filters.addEventListener("submit", (event) => {
      event.preventDefault();
      void loadReport();
    });
    elements.chooseClient.addEventListener("click", openClientSelector);
    elements.clientSearch.addEventListener("input", () => renderClientList(elements.clientSearch.value));
    elements.clientList.addEventListener("click", (event) => {
      const option = event.target.closest("[data-pdas-client-id]");
      if (option) selectClient(option.dataset.pdasClientId);
    });
    document.querySelectorAll("[data-pdas-close-client]").forEach((button) => {
      button.addEventListener("click", () => closeDialog(elements.clientDialog));
    });
    elements.previewPdf.addEventListener("click", openPdfPreview);
    elements.pdfFrame.addEventListener("load", () => {
      if (elements.pdfFrame.src !== "about:blank") elements.pdfLoading.hidden = true;
    });
    document.querySelectorAll("[data-pdas-close-pdf]").forEach((button) => {
      button.addEventListener("click", closePdfPreview);
    });
    elements.pdfDialog.addEventListener("close", () => {
      elements.pdfFrame.src = "about:blank";
      elements.pdfLoading.hidden = false;
    });
    [elements.dateFrom, elements.dateTo, elements.currency].forEach((field) => {
      field.addEventListener("change", () => {
        field.removeAttribute("aria-invalid");
        state.reportFilters = null;
        state.report = null;
        resetReportPresentation("Los filtros cambiaron. Pulsa Consultar para actualizar el reporte.");
        setMessage("");
        updatePdfActions();
      });
    });

    updatePdfActions();
    void loadCatalog();
  }).catch((error) => {
    const message = document.querySelector("#pdasMessage");
    if (message) {
      message.textContent = error.message || "No se pudo iniciar la vista de estado de cuenta.";
      message.classList.add("is-error");
    }
  });
}

mountProductDispatchAccountStatement();
