export function buildProductDispatchPaymentPayload(values) {
  return {
    cliente_id: Number(values.cliente_id),
    importe: String(values.importe ?? "").trim(),
    metodo_pago_id: Number(values.metodo_pago_id),
    fecha_hora: String(values.fecha_hora ?? ""),
    moneda: String(values.moneda ?? "").trim().toUpperCase(),
    cuenta_destino_id: values.cuenta_destino_id ? Number(values.cuenta_destino_id) : null,
    referencia: String(values.referencia ?? "").trim() || null,
    observaciones: String(values.observaciones ?? "").trim() || null
  };
}

export function buildProductDispatchPaymentsPath(base, filters = {}, page = 1) {
  const query = new URLSearchParams({ page: String(page) });
  for (const name of ["buscar", "date_from", "date_to"]) {
    const value = String(filters[name] ?? "").trim();
    if (value) query.set(name, value);
  }
  return `${base}?${query.toString()}`;
}

function errorMessage(error) {
  const first = Object.values(error?.data?.errors ?? {}).flat().find(Boolean);
  return String(first || error?.message || "No se pudo completar la operación. Intenta nuevamente.");
}

function paymentKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function money(amount, currency) {
  try {
    return new Intl.NumberFormat("es-PE", { style: "currency", currency, minimumFractionDigits: 2 }).format(Number(amount));
  } catch {
    return `${currency || ""} ${Number(amount).toFixed(2)}`;
  }
}

function displayDate(value) {
  const parts = String(value ?? "").match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}:\d{2})/);
  return parts ? `${parts[3]}/${parts[2]}/${parts[1]} ${parts[4]}` : String(value ?? "—");
}

const root = typeof document !== "undefined" ? document.querySelector("#productDispatchPayments") : null;

if (root) {
  const { apiRequest } = await import("./api-client.js");
  const apiBase = root.dataset.apiBase || "/despacho-productos/pagos";
  const ids = ["Form", "Fields", "FormTitle", "EditBadge", "ClientSearch", "Client", "ClientHelp", "Amount", "Currency", "Method", "DateTime", "Reference", "Account", "Notes", "Save", "Reset", "FormMessage", "RetryCatalog", "Filters", "Search", "DateFrom", "DateTo", "ClearFilters", "ListMessage", "Reload", "TableContainer", "Rows", "Count", "Previous", "Next", "Page", "Branch"];
  const ui = Object.fromEntries(ids.map((id) => [id, document.getElementById(`pdpy${id}`)]));
  const state = {
    catalog: { methods: [], accounts: [], currency: "PEN" },
    catalogReady: false,
    catalogLoading: false,
    catalogLoadedAt: 0,
    payments: [],
    clients: [],
    selectedClient: null,
    editingId: null,
    saving: false,
    deletingId: null,
    loading: false,
    listFailed: false,
    listSequence: 0,
    clientSequence: 0,
    clientTimer: null,
    page: 1,
    lastPage: 1,
    filters: {},
    createAttempt: null
  };
  const fields = { cliente_id: ui.Client, importe: ui.Amount, metodo_pago_id: ui.Method, fecha_hora: ui.DateTime, moneda: ui.Currency, cuenta_destino_id: ui.Account, referencia: ui.Reference, observaciones: ui.Notes };

  function node(tag, text = "", className = "") {
    const element = document.createElement(tag);
    element.textContent = String(text ?? "");
    if (className) element.className = className;
    return element;
  }

  function option(value, text) {
    const element = node("option", text);
    element.value = String(value ?? "");
    return element;
  }

  function message(element, text = "", tone = "") {
    element.textContent = text;
    element.classList.toggle("is-error", tone === "error");
    element.classList.toggle("is-success", tone === "success");
  }

  function clearErrors() {
    Object.values(fields).forEach((field) => field.removeAttribute("aria-invalid"));
  }

  function setErrors(error) {
    for (const key of Object.keys(error?.data?.errors ?? {})) {
      fields[key]?.setAttribute("aria-invalid", "true");
    }
    ui.Form.querySelector('[aria-invalid="true"]')?.focus();
  }

  function controls() {
    const busy = state.saving || Boolean(state.deletingId);
    ui.Fields.disabled = busy || !state.catalogReady;
    ui.Save.disabled = busy || !state.catalogReady;
    ui.Reset.disabled = busy || !state.catalogReady;
    ui.Form.setAttribute("aria-busy", String(state.saving || state.catalogLoading));
    ui.Save.textContent = state.saving ? "Guardando…" : state.editingId ? "Guardar cambios" : "Guardar pago";
    ui.Reset.textContent = state.editingId ? "Cancelar edición" : "Limpiar";
    ui.FormTitle.textContent = state.editingId ? "Editar pago" : "Nuevo pago";
    ui.EditBadge.hidden = !state.editingId;
    ui.Previous.disabled = busy || state.loading || state.page <= 1;
    ui.Next.disabled = busy || state.loading || state.page >= state.lastPage;
    ui.Reload.disabled = busy || state.loading;
    ui.Rows.querySelectorAll("button").forEach((button) => { button.disabled = busy || (button.dataset.action === "edit" && !state.catalogReady); });
  }

  function currentDateTime() {
    const serverNow = Date.parse(`${String(state.catalog.now || "").slice(0, 16)}:00Z`);
    if (Number.isFinite(serverNow)) return new Date(serverNow + Date.now() - state.catalogLoadedAt).toISOString().slice(0, 16);
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }

  function renderCurrencies(selected = state.catalog.currency) {
    const codes = [...new Set([state.catalog.currency, "PEN", "USD", ...state.catalog.accounts.map((account) => account.currency), selected].filter(Boolean))];
    ui.Currency.replaceChildren(...codes.map((currency) => option(currency, currency === "PEN" ? "Soles (PEN)" : currency === "USD" ? "Dólares (USD)" : currency)));
    ui.Currency.value = selected;
  }

  function renderAccounts(selected = "", existing = null) {
    const accounts = state.catalog.accounts.filter((account) => account.currency === ui.Currency.value);
    if (existing && !accounts.some((account) => String(account.id) === String(existing.id))) accounts.push(existing);
    ui.Account.replaceChildren(option("", "Sin asignar cuenta"), ...accounts.map((account) => option(account.id, account.name)));
    ui.Account.value = String(selected ?? "");
  }

  function renderClients() {
    const records = [...state.clients];
    const selected = state.selectedClient;
    if (selected && !records.some((client) => String(client.id) === String(selected.id))) records.unshift(selected);
    ui.Client.replaceChildren(option("", "Selecciona un cliente"), ...records.map((client) => option(client.id, `${client.name}${client.document ? ` · ${client.document}` : ""}`)));
    ui.Client.value = selected ? String(selected.id) : "";
  }

  async function loadClients(query = ui.ClientSearch.value.trim()) {
    const sequence = ++state.clientSequence;
    ui.ClientHelp.textContent = "Buscando clientes…";
    ui.Client.setAttribute("aria-busy", "true");
    try {
      const response = await apiRequest(`/despacho-productos/clientes?${new URLSearchParams({ buscar: query })}`);
      if (sequence !== state.clientSequence) return;
      state.clients = Array.isArray(response?.data) ? response.data : [];
      renderClients();
      ui.ClientHelp.textContent = state.clients.length ? `${state.clients.length} clientes disponibles. Selecciona uno de la lista${state.clients.length >= 100 ? " o precisa la búsqueda" : ""}.` : "No se encontraron clientes. Prueba otra búsqueda.";
    } catch (error) {
      if (sequence !== state.clientSequence) return;
      state.clients = [];
      renderClients();
      ui.ClientHelp.textContent = `${errorMessage(error)} Vuelve a buscar para reintentar.`;
    } finally {
      if (sequence === state.clientSequence) ui.Client.setAttribute("aria-busy", "false");
    }
  }

  async function loadCatalog() {
    if (state.catalogLoading) return;
    state.catalogLoading = true;
    ui.RetryCatalog.hidden = true;
    message(ui.FormMessage, "Cargando métodos de pago…");
    controls();
    try {
      const response = await apiRequest(`${apiBase}/catalogo`);
      state.catalog = response.data;
      state.catalogLoadedAt = Date.now();
      state.catalog.methods = Array.isArray(state.catalog.methods) ? state.catalog.methods : [];
      state.catalog.accounts = Array.isArray(state.catalog.accounts) ? state.catalog.accounts : [];
      if (!state.catalog.methods.length) throw new Error("No hay métodos de pago disponibles. Configura un método y vuelve a cargar.");
      ui.Method.replaceChildren(option("", "Selecciona un método"), ...state.catalog.methods.map((method) => option(method.id, method.name)));
      renderCurrencies();
      renderAccounts();
      ui.DateTime.value = currentDateTime();
      ui.Branch.textContent = state.catalog.branch?.name ? `Sucursal: ${state.catalog.branch.name}` : "";
      state.catalogReady = true;
      message(ui.FormMessage);
    } catch (error) {
      state.catalogReady = false;
      message(ui.FormMessage, errorMessage(error), "error");
      ui.RetryCatalog.hidden = false;
    } finally {
      state.catalogLoading = false;
      controls();
    }
  }

  function emptyRow(text) {
    const row = node("tr");
    const cell = node("td", text, "pdpy-empty");
    cell.colSpan = 7;
    row.append(cell);
    return row;
  }

  function renderRows() {
    ui.TableContainer.setAttribute("aria-busy", String(state.loading));
    if (state.loading) {
      ui.Rows.replaceChildren(emptyRow("Cargando pagos…"));
    } else if (state.listFailed) {
      ui.Rows.replaceChildren(emptyRow("No se pudieron cargar los pagos. Usa Actualizar para reintentar."));
    } else if (!state.payments.length) {
      ui.Rows.replaceChildren(emptyRow(Object.values(state.filters).some(Boolean) ? "No hay pagos que coincidan con los filtros." : "Aún no hay pagos registrados. Agrega el primero con el formulario."));
    } else {
      ui.Rows.replaceChildren(...state.payments.map((payment) => {
        const row = node("tr");
        const dateCell = node("td", "", "pdpy-date");
        dateCell.append(node("strong", displayDate(payment.date_time)), node("small", payment.code || `Pago ${payment.id}`));
        const clientCell = node("td", "", "pdpy-wrap pdpy-client");
        clientCell.append(node("strong", payment.client?.name || "Sin cliente"), node("small", payment.client?.document || "Sin documento"));
        const amountCell = node("td", "", "pdpy-money");
        amountCell.append(node("strong", money(payment.amount, payment.currency)), node("small", payment.currency));
        const methodCell = node("td", "", "pdpy-wrap");
        methodCell.append(node("strong", payment.payment_method?.name || "—"), node("small", payment.account?.name || "Sin cuenta asignada"));
        const actionCell = node("td");
        const actions = node("div", "", "pdpy-row-actions");
        for (const [action, label] of [["edit", "Editar"], ["delete", "Eliminar"]]) {
          const button = node("button", state.deletingId === String(payment.id) && action === "delete" ? "Eliminando…" : label, action === "delete" ? "is-danger" : "");
          button.type = "button";
          button.dataset.action = action;
          button.dataset.paymentId = String(payment.id);
          button.setAttribute("aria-label", `${label} pago ${payment.code || payment.id} de ${payment.client?.name || "cliente"}`);
          actions.append(button);
        }
        actionCell.append(actions);
        row.append(dateCell, clientCell, amountCell, methodCell, node("td", payment.reference || "—", "pdpy-wrap"), node("td", payment.notes || "—", "pdpy-wrap"), actionCell);
        return row;
      }));
    }
    ui.Page.textContent = `Página ${state.page} de ${state.lastPage}`;
    controls();
  }

  async function loadPayments(page = state.page) {
    const sequence = ++state.listSequence;
    state.loading = true;
    state.listFailed = false;
    message(ui.ListMessage);
    renderRows();
    try {
      const response = await apiRequest(buildProductDispatchPaymentsPath(apiBase, state.filters, page));
      if (sequence !== state.listSequence) return false;
      const lastPage = Math.max(1, Number(response.meta?.last_page) || 1);
      if (page > lastPage) return await loadPayments(lastPage);
      state.payments = Array.isArray(response.data) ? response.data : [];
      state.page = Number(response.meta?.current_page) || page;
      state.lastPage = lastPage;
      const total = Number(response.meta?.total) || 0;
      ui.Count.textContent = `${total} ${total === 1 ? "pago" : "pagos"}`;
      return true;
    } catch (error) {
      if (sequence !== state.listSequence) return false;
      state.payments = [];
      state.listFailed = true;
      ui.Count.textContent = "— pagos";
      message(ui.ListMessage, errorMessage(error), "error");
      return false;
    } finally {
      if (sequence === state.listSequence) {
        state.loading = false;
        renderRows();
      }
    }
  }

  function resetForm(focus = true) {
    state.editingId = null;
    state.selectedClient = null;
    state.createAttempt = null;
    ui.Form.reset();
    ui.DateTime.value = currentDateTime();
    renderCurrencies();
    renderAccounts();
    renderClients();
    clearErrors();
    message(ui.FormMessage);
    controls();
    if (focus) ui.ClientSearch.focus();
    window.clearTimeout(state.clientTimer);
    void loadClients("");
  }

  function beginEdit(payment) {
    if (!state.catalogReady || state.saving || state.deletingId) return;
    state.editingId = String(payment.id);
    state.createAttempt = null;
    state.selectedClient = payment.client;
    ui.ClientSearch.value = "";
    window.clearTimeout(state.clientTimer);
    ++state.clientSequence;
    renderClients();
    ui.Client.setAttribute("aria-busy", "false");
    ui.ClientHelp.textContent = "Cliente del pago seleccionado. Puedes buscar otro para cambiarlo.";
    ui.Amount.value = payment.amount;
    renderCurrencies(payment.currency);
    renderAccounts(payment.account?.id ?? "", payment.account);
    if (payment.payment_method && !Array.from(ui.Method.options).some((item) => item.value === String(payment.payment_method.id))) {
      ui.Method.append(option(payment.payment_method.id, payment.payment_method.name));
    }
    ui.Method.value = String(payment.payment_method?.id ?? "");
    ui.DateTime.value = String(payment.date_time || "").replace(" ", "T").slice(0, 16);
    ui.Reference.value = payment.reference || "";
    ui.Notes.value = payment.notes || "";
    clearErrors();
    message(ui.FormMessage, `Editando ${payment.code || `pago ${payment.id}`}. Puedes modificar todos los datos.`);
    controls();
    ui.FormTitle.scrollIntoView({ block: "center", behavior: "auto" });
    ui.ClientSearch.focus({ preventScroll: true });
  }

  async function savePayment(event) {
    event.preventDefault();
    if (state.saving || state.deletingId || !state.catalogReady) return;
    clearErrors();
    if (!ui.Form.reportValidity()) return;
    const payload = buildProductDispatchPaymentPayload(Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, field.value])));
    const editingId = state.editingId;
    if (!editingId) {
      const fingerprint = JSON.stringify(payload);
      if (state.createAttempt?.fingerprint !== fingerprint) state.createAttempt = { fingerprint, key: paymentKey() };
      payload.idempotency_key = state.createAttempt.key;
    }
    state.saving = true;
    controls();
    message(ui.FormMessage, "Guardando pago…");
    let saved = false;
    try {
      const response = await apiRequest(editingId ? `${apiBase}/${encodeURIComponent(editingId)}` : apiBase, { method: editingId ? "PUT" : "POST", body: JSON.stringify(payload) });
      resetForm(false);
      saved = true;
      message(ui.FormMessage, response.message || (editingId ? "Pago actualizado correctamente." : "Pago registrado correctamente."), "success");
      if (!editingId) {
        ui.Filters.reset();
        state.filters = {};
      }
      await loadPayments(editingId ? state.page : 1);
    } catch (error) {
      message(ui.FormMessage, errorMessage(error), "error");
      // Keep the creation key after a failed request so retrying cannot duplicate the payment.
      state.saving = false;
      controls();
      setErrors(error);
    } finally {
      state.saving = false;
      controls();
      if (saved) ui.ClientSearch.focus();
    }
  }

  async function deletePayment(payment) {
    if (state.saving || state.deletingId) return;
    if (!window.confirm(`¿Eliminar por completo el pago ${payment.code || payment.id} de ${payment.client?.name || "este cliente"} por ${money(payment.amount, payment.currency)}? El pago se retirará del estado de cuenta del cliente.`)) return;
    state.deletingId = String(payment.id);
    message(ui.ListMessage, "Eliminando pago…");
    renderRows();
    try {
      const response = await apiRequest(`${apiBase}/${encodeURIComponent(payment.id)}`, { method: "DELETE" });
      if (state.editingId === String(payment.id)) resetForm(false);
      const loaded = await loadPayments();
      if (loaded) message(ui.ListMessage, response?.message || "Pago eliminado correctamente.", "success");
    } catch (error) {
      message(ui.ListMessage, errorMessage(error), "error");
    } finally {
      state.deletingId = null;
      renderRows();
      ui.Reload.focus({ preventScroll: true });
    }
  }

  ui.Form.addEventListener("submit", savePayment);
  ui.Reset.addEventListener("click", () => resetForm());
  ui.RetryCatalog.addEventListener("click", () => { void loadCatalog(); });
  ui.Currency.addEventListener("change", () => renderAccounts());
  ui.Client.addEventListener("change", () => {
    state.selectedClient = state.clients.find((client) => String(client.id) === ui.Client.value) || (String(state.selectedClient?.id) === ui.Client.value ? state.selectedClient : null);
  });
  ui.ClientSearch.addEventListener("input", () => {
    window.clearTimeout(state.clientTimer);
    ++state.clientSequence;
    state.selectedClient = null;
    state.clients = [];
    renderClients();
    ui.ClientHelp.textContent = "Buscando clientes…";
    state.clientTimer = window.setTimeout(() => { void loadClients(); }, 300);
  });
  Object.values(fields).forEach((field) => {
    field.addEventListener("input", () => field.removeAttribute("aria-invalid"));
    field.addEventListener("change", () => field.removeAttribute("aria-invalid"));
  });
  ui.Filters.addEventListener("submit", (event) => {
    event.preventDefault();
    if (ui.DateFrom.value && ui.DateTo.value && ui.DateFrom.value > ui.DateTo.value) {
      message(ui.ListMessage, "La fecha Desde no puede ser posterior a Hasta.", "error");
      ui.DateTo.focus();
      return;
    }
    state.filters = { buscar: ui.Search.value.trim(), date_from: ui.DateFrom.value, date_to: ui.DateTo.value };
    void loadPayments(1);
  });
  ui.ClearFilters.addEventListener("click", () => {
    ui.Filters.reset();
    state.filters = {};
    void loadPayments(1);
  });
  ui.Reload.addEventListener("click", () => { void loadPayments(); });
  ui.Previous.addEventListener("click", () => { if (state.page > 1) void loadPayments(state.page - 1); });
  ui.Next.addEventListener("click", () => { if (state.page < state.lastPage) void loadPayments(state.page + 1); });
  ui.Rows.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action][data-payment-id]");
    if (!button || button.disabled) return;
    const payment = state.payments.find((item) => String(item.id) === button.dataset.paymentId);
    if (!payment) return;
    if (button.dataset.action === "edit") beginEdit(payment);
    if (button.dataset.action === "delete") void deletePayment(payment);
  });
  window.addEventListener("auth:expired", () => message(ui.FormMessage, "La sesión venció. Inicia sesión nuevamente.", "error"));
  await Promise.allSettled([loadCatalog(), loadClients(""), loadPayments(1)]);
}
