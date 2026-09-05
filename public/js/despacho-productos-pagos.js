import { filterProductDispatchAccountClients } from "./despacho-productos-estado-cuenta.js";

export function buildProductDispatchPaymentPayload(values) {
  return {
    cliente_id: Number(values.cliente_id),
    importe: String(values.importe ?? "").trim(),
    metodo_pago_id: Number(values.metodo_pago_id),
    fecha_hora: String(values.fecha_hora ?? ""),
    moneda: String(values.moneda ?? "").trim().toUpperCase(),
    cuenta_destino_id: values.cuenta_destino_id ? Number(values.cuenta_destino_id) : null,
    referencia: String(values.referencia ?? "").trim() || null,
    observaciones: String(values.observaciones ?? "").trim() || null,
  };
}

export function buildProductDispatchAdjustmentPayload(values) {
  const payment = buildProductDispatchPaymentPayload(values);
  return {
    cliente_id: payment.cliente_id, tipo: values.tipo, importe: payment.importe,
    moneda: payment.moneda, fecha_hora: payment.fecha_hora,
    observaciones: payment.observaciones,
  };
}

export function buildProductDispatchPaymentsPath(base, filters = {}, page = 1) {
  const query = new URLSearchParams({ page: String(page) });
  for (const name of ["cliente_id", "moneda", "buscar", "date_from", "date_to"]) {
    const value = String(filters[name] ?? "").trim();
    if (value) query.set(name, value);
  }
  return `${base}?${query.toString()}`;
}

function cents(value) {
  const match = String(value ?? "").trim().match(/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/);
  if (!match) return null;
  return (match[1] ? -1 : 1) * (Number(match[2]) * 100 + Number((match[3] || "").padEnd(2, "0")));
}

// Reverse the old movement before previewing an edit. The server owns the final balance.
export function productDispatchBalancePreview(balance, amount, kind, original = null) {
  const current = cents(balance);
  const entered = cents(amount);
  if (current === null || entered === null || entered <= 0 || !["PAYMENT", "PRIOR_DEBT", "CREDIT"].includes(kind)) return null;
  const previous = original ? (cents(original.sale) ?? 0) - (cents(original.payment) ?? 0) : 0;
  return ((current - previous + (kind === "PRIOR_DEBT" ? entered : -entered)) / 100).toFixed(2);
}

function errorMessage(error) {
  return String(Object.values(error?.data?.errors ?? {}).flat().find(Boolean)
    || error?.message || "No se pudo completar la operación. Intenta nuevamente.");
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
  const parts = String(value ?? "").match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}:\d{2}))?/);
  return parts ? `${parts[3]}/${parts[2]}/${parts[1]}${parts[4] ? ` ${parts[4]}` : ""}` : "—";
}

const labels = { PAYMENT: "Pago", PRIOR_DEBT: "Deuda anterior", CREDIT: "Saldo a favor", SALE: "Venta", APPLIED_PAYMENT: "Abono aplicado" };
const transactionKey = (row) => row.key || `${row.kind}:${row.id}`;

export function mountProductDispatchPayments(root, apiRequest) {
  const doc = root.ownerDocument;
  const win = doc.defaultView;
  const apiBase = root.dataset.apiBase || "/despacho-productos/pagos";
  const ids = ["Form", "Fields", "FormTitle", "EditBadge", "ChooseClient", "ClientButtonTitle", "ClientButtonDetail", "ClientDialog", "CloseClientDialog", "ClientSearch", "ClientList", "ClientCount", "Client", "ClientHelp", "BalancePanel", "BalanceLabel", "BalanceAmount", "BalanceHelp", "TotalCharges", "TotalCredits", "BalancePreview", "MovementType", "MovementHelp", "Amount", "Currency", "Method", "MethodField", "DateTime", "Reference", "Account", "AccountField", "Notes", "Save", "Reset", "FormMessage", "RetryCatalog", "Filters", "Search", "DateFrom", "DateTo", "ClearFilters", "ListSubtitle", "ListMessage", "Reload", "TableContainer", "Rows", "Count", "Previous", "Next", "Page", "Branch"];
  const ui = Object.fromEntries(ids.map((id) => [id, doc.getElementById(`pdpy${id}`)]));
  const state = {
    catalog: { methods: [], accounts: [], clients: [], currencies: [], currency: "PEN" },
    catalogReady: false, catalogLoading: false, catalogLoadedAt: 0,
    selectedClient: null, summary: null, transactions: [], editing: null,
    saving: false, deletingKey: null, loading: false, listFailed: false,
    sequence: 0, page: 1, lastPage: 1, filters: {}, createAttempt: null,
  };
  const fields = { cliente_id: ui.Client, importe: ui.Amount, metodo_pago_id: ui.Method, fecha_hora: ui.DateTime, moneda: ui.Currency, cuenta_destino_id: ui.Account, referencia: ui.Reference, observaciones: ui.Notes };

  function node(tag, text = "", className = "") {
    const element = doc.createElement(tag);
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
    [...Object.values(fields), ui.ChooseClient, ui.MovementType].forEach((field) => field.removeAttribute("aria-invalid"));
  }

  function controls() {
    const busy = state.saving || Boolean(state.deletingKey);
    const payment = ui.MovementType.value === "PAYMENT";
    const ready = state.catalogReady && Boolean(state.selectedClient);
    ui.Fields.disabled = busy || !ready;
    ui.ChooseClient.disabled = busy || !state.catalogReady;
    ui.Save.disabled = busy || !ready || state.loading || !state.summary || (payment && !state.catalog.methods.length);
    ui.Reset.disabled = busy || !ready;
    ui.MovementType.disabled = Boolean(state.editing);
    ui.Method.required = payment;
    ui.Method.disabled = !payment;
    ui.Account.disabled = !payment;
    ui.MethodField.hidden = !payment;
    ui.AccountField.hidden = !payment;
    ui.Reference.disabled = !payment;
    ui.Reference.closest("label").hidden = !payment;
    ui.Notes.maxLength = ui.MovementType.value === "PRIOR_DEBT" ? 250 : 2000;
    ui.Form.setAttribute("aria-busy", String(state.saving || state.catalogLoading));
    const label = labels[ui.MovementType.value] || "Movimiento";
    ui.Save.textContent = state.saving ? "Guardando…" : state.editing ? "Guardar cambios" : `Guardar ${label.toLowerCase()}`;
    ui.Reset.textContent = state.editing ? "Cancelar edición" : "Limpiar formulario";
    ui.FormTitle.textContent = state.editing ? `Editar ${label.toLowerCase()}` : `Nuevo ${label.toLowerCase()}`;
    if (!state.editing && ui.MovementType.value === "PRIOR_DEBT") ui.FormTitle.textContent = "Nueva deuda anterior";
    ui.EditBadge.hidden = !state.editing;
    ui.Previous.disabled = busy || state.loading || state.page <= 1;
    ui.Next.disabled = busy || state.loading || state.page >= state.lastPage;
    ui.Reload.disabled = busy || state.loading || !state.selectedClient;
    ui.Filters.querySelectorAll("input, button").forEach((field) => { field.disabled = busy || !ready; });
    ui.Rows.querySelectorAll("button").forEach((button) => { button.disabled = busy || state.loading; });
    ui.MovementHelp.textContent = payment
      ? "Registra dinero recibido. Reduce la deuda y el excedente queda a favor del cliente."
      : ui.MovementType.value === "PRIOR_DEBT"
        ? "Agrega una deuda pendiente de una fecha anterior. Se suma al saldo del cliente."
        : "Reduce primero la deuda. Si sobra un importe, queda a favor del cliente. No registra ingreso de dinero.";
  }

  function currentDateTime() {
    const serverNow = Date.parse(`${String(state.catalog.now || "").slice(0, 16)}:00Z`);
    if (Number.isFinite(serverNow)) return new Date(serverNow + Date.now() - state.catalogLoadedAt).toISOString().slice(0, 16);
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }

  function renderCurrencies(selected = state.catalog.currency) {
    const codes = [...new Set([state.catalog.currency, ...state.catalog.currencies, "PEN", "USD", ...state.catalog.accounts.map((account) => account.currency), selected].filter(Boolean))];
    ui.Currency.replaceChildren(...codes.map((currency) => option(currency, currency === "PEN" ? "Soles (PEN)" : currency === "USD" ? "Dólares (USD)" : currency)));
    ui.Currency.value = selected;
  }

  function renderAccounts(selected = "", existing = null) {
    const accounts = state.catalog.accounts.filter((account) => account.currency === ui.Currency.value);
    if (existing && !accounts.some((account) => String(account.id) === String(existing.id))) accounts.push(existing);
    ui.Account.replaceChildren(option("", "Sin asignar cuenta"), ...accounts.map((account) => option(account.id, account.name)));
    ui.Account.value = String(selected ?? "");
  }

  function clientDocument(client) {
    return client.document ? [client.document_type, client.document].filter(Boolean).join(" ") : "Sin documento registrado";
  }

  function renderClientList() {
    const clients = filterProductDispatchAccountClients(state.catalog.clients, ui.ClientSearch.value);
    ui.ClientCount.textContent = `${clients.length} ${clients.length === 1 ? "cliente" : "clientes"}`;
    ui.ClientHelp.textContent = clients.length > 120 ? "Se muestran los primeros 120. Escribe el nombre o documento para precisar la búsqueda." : "Elige un cliente para consultar su saldo y sus movimientos.";
    ui.ClientList.replaceChildren(...clients.slice(0, 120).map((client) => {
      const button = node("button", "", "pdpy-client-option");
      button.type = "button";
      button.dataset.clientId = String(client.id);
      button.dataset.selected = String(String(state.selectedClient?.id) === String(client.id));
      const identity = node("span");
      identity.append(node("strong", client.name), node("small", clientDocument(client)));
      const initial = node("span", client.name.charAt(0).toLocaleUpperCase("es") || "C");
      initial.setAttribute("aria-hidden", "true");
      button.append(initial, identity, node("em", button.dataset.selected === "true" ? "Elegido" : "Elegir"));
      return button;
    }));
    if (!clients.length) ui.ClientList.append(node("p", "No encontramos clientes. Prueba con otro nombre o documento.", "pdpy-client-empty"));
  }

  function renderSelectedClient() {
    const client = state.selectedClient;
    ui.Client.value = client ? String(client.id) : "";
    ui.ClientButtonTitle.textContent = client?.name || "Elegir cliente";
    ui.ClientButtonDetail.textContent = client ? clientDocument(client) : "Busca por nombre o documento";
    ui.ListSubtitle.textContent = client ? `Movimientos de ${client.name} · ${ui.Currency.value}` : "Selecciona un cliente para ver todas sus transacciones.";
  }

  function balanceText(value) {
    const number = Number(value);
    return number > 0 ? `Deuda: ${money(number, ui.Currency.value)}`
      : number < 0 ? `Saldo a favor: ${money(-number, ui.Currency.value)}` : "Sin deuda ni saldo a favor";
  }

  function renderPreview() {
    doc[Symbol.for("avicola.productDispatchKeyboards")]?.numeric?.refreshLabel(ui.Amount);
    const original = state.editing && state.editing.currency === ui.Currency.value ? state.editing : null;
    const preview = !state.loading && state.summary
      ? productDispatchBalancePreview(state.summary.balance, ui.Amount.value, ui.MovementType.value, original) : null;
    ui.BalancePreview.textContent = preview === null ? "" : `Saldo después de guardar · ${balanceText(preview)}`;
    ui.BalancePreview.hidden = preview === null;
  }

  function renderBalance() {
    const summary = state.summary;
    const balance = Number(summary?.balance || 0);
    ui.BalancePanel.setAttribute("aria-busy", String(state.loading));
    ui.BalancePanel.classList.toggle("is-debt", Boolean(summary) && balance > 0);
    ui.BalancePanel.classList.toggle("is-credit", Boolean(summary) && balance < 0);
    ui.BalancePanel.classList.toggle("is-settled", Boolean(summary) && balance === 0);
    ui.BalanceLabel.textContent = !summary ? "Saldo del cliente" : balance > 0 ? "Deuda actual" : balance < 0 ? "Saldo a favor" : "Cliente al día";
    ui.BalanceAmount.textContent = summary ? money(Math.abs(balance), summary.currency) : "—";
    ui.BalanceHelp.textContent = state.loading ? "Consultando saldo…" : summary
      ? "Saldo de toda la cuenta en esta moneda. Los filtros solo cambian el historial."
      : state.listFailed ? "No se pudo consultar el saldo. Pulsa Actualizar para reintentar." : "Selecciona un cliente para consultar su deuda o saldo a favor.";
    ui.TotalCharges.textContent = summary ? money(summary.charges_total, summary.currency) : "—";
    ui.TotalCredits.textContent = summary ? money(summary.payments_total, summary.currency) : "—";
    renderPreview();
  }

  function emptyRow(text) {
    const row = node("tr");
    const cell = node("td", text, "pdpy-empty");
    cell.colSpan = 6;
    row.append(cell);
    return row;
  }

  function actionButton(row, action, label) {
    const button = node("button", label, action === "delete" ? "is-danger" : "");
    button.type = "button";
    button.dataset.action = action;
    button.dataset.key = transactionKey(row);
    button.setAttribute("aria-label", `${label} ${row.code || labels[row.kind] || "movimiento"}`);
    return button;
  }

  function renderRows() {
    ui.TableContainer.setAttribute("aria-busy", String(state.loading));
    if (!state.selectedClient) ui.Rows.replaceChildren(emptyRow("Elige un cliente para ver sus transacciones."));
    else if (state.loading) ui.Rows.replaceChildren(emptyRow("Cargando transacciones y saldo…"));
    else if (state.listFailed) ui.Rows.replaceChildren(emptyRow("No se pudieron cargar las transacciones. Pulsa Actualizar para reintentar."));
    else if (!state.transactions.length) ui.Rows.replaceChildren(emptyRow(Object.values(state.filters).some(Boolean) ? "No hay transacciones que coincidan con los filtros." : "Este cliente aún no tiene movimientos en esta moneda."));
    else ui.Rows.replaceChildren(...state.transactions.map((movement) => {
      const row = node("tr");
      const dateCell = node("td", "", "pdpy-date");
      dateCell.append(node("strong", displayDate(movement.date_time)), node("small", movement.movement_label || labels[movement.kind] || "Movimiento"));
      const detail = node("td", "", "pdpy-wrap");
      detail.append(node("strong", movement.code || "—"));
      const description = [movement.detail, movement.payment_method?.name, movement.account?.name, movement.reference, movement.notes].filter(Boolean);
      detail.append(node("small", [...new Set(description)].join(" · ") || labels[movement.kind]));
      const actionCell = node("td");
      const actions = node("div", "", "pdpy-row-actions");
      if (movement.can_edit) actions.append(actionButton(movement, "edit", movement.kind === "SALE" ? "Editar ticket" : "Editar"));
      if (movement.can_delete) actions.append(actionButton(movement, "delete", state.deletingKey === transactionKey(movement) ? "Eliminando…" : "Eliminar"));
      if (!movement.can_edit && (movement.origin_url || movement.manage_url)) actions.append(actionButton(movement, "origin", "Abrir origen"));
      if (!movement.can_edit || !movement.can_delete) actions.append(node("small", movement.action_reason || "Consulta el movimiento en su sección de origen."));
      actionCell.append(actions);
      const balanceCell = node("td", "", "pdpy-money pdpy-balance");
      balanceCell.append(node("strong", money(Math.abs(Number(movement.balance)), movement.currency)), node("small", Number(movement.balance) < 0 ? "A favor" : Number(movement.balance) > 0 ? "Deuda" : "Al día"));
      row.append(dateCell, detail,
        node("td", Number(movement.sale) ? money(movement.sale, movement.currency) : "—", "pdpy-money pdpy-charge"),
        node("td", Number(movement.payment) ? money(movement.payment, movement.currency) : "—", "pdpy-money pdpy-credit"),
        balanceCell, actionCell);
      return row;
    }));
    ui.Page.textContent = `Página ${state.page} de ${state.lastPage}`;
    controls();
  }

  async function loadAccount(page = 1) {
    const sequence = ++state.sequence;
    if (!state.selectedClient) return false;
    state.loading = true;
    state.listFailed = false;
    state.summary = null;
    message(ui.ListMessage);
    renderBalance();
    renderRows();
    try {
      const response = await apiRequest(buildProductDispatchPaymentsPath(`${apiBase}/cuenta`, {
        ...state.filters, cliente_id: state.selectedClient.id, moneda: ui.Currency.value,
      }, page));
      if (sequence !== state.sequence) return false;
      const lastPage = Math.max(1, Number(response.meta?.last_page) || 1);
      if (page > lastPage) return await loadAccount(lastPage);
      state.transactions = Array.isArray(response.data) ? response.data : [];
      state.summary = response.summary;
      state.page = Number(response.meta?.current_page) || page;
      state.lastPage = lastPage;
      const total = Number(response.meta?.total) || 0;
      ui.Count.textContent = `${total} ${total === 1 ? "transacción" : "transacciones"}`;
      return true;
    } catch (error) {
      if (sequence !== state.sequence) return false;
      state.transactions = [];
      state.listFailed = true;
      ui.Count.textContent = "— transacciones";
      message(ui.ListMessage, errorMessage(error), "error");
      return false;
    } finally {
      if (sequence === state.sequence) {
        state.loading = false;
        renderBalance();
        renderRows();
      }
    }
  }

  function resetForm(focus = true) {
    const currency = ui.Currency.value || state.catalog.currency;
    state.editing = null;
    state.createAttempt = null;
    ui.Form.reset();
    ui.MovementType.value = "PAYMENT";
    ui.DateTime.value = currentDateTime();
    renderCurrencies(currency);
    renderAccounts();
    renderSelectedClient();
    clearErrors();
    message(ui.FormMessage);
    controls();
    renderPreview();
    if (focus) ui.Amount.focus();
  }

  function closeClientDialog() {
    if (typeof ui.ClientDialog.close === "function") ui.ClientDialog.close();
    else ui.ClientDialog.removeAttribute("open");
    ui.ChooseClient.focus({ preventScroll: true });
  }

  async function selectClient(id) {
    const client = state.catalog.clients.find((item) => String(item.id) === String(id));
    if (!client || state.saving || state.deletingKey) return;
    state.selectedClient = client;
    state.summary = null;
    state.transactions = [];
    state.filters = {};
    ui.Filters.reset();
    resetForm(false);
    closeClientDialog();
    const params = new URLSearchParams({ cliente_id: String(client.id), moneda: ui.Currency.value });
    win.history.replaceState({}, "", `${win.location.pathname}?${params}`);
    await loadAccount(1);
  }

  async function loadCatalog() {
    if (state.catalogLoading) return;
    state.catalogLoading = true;
    ui.RetryCatalog.hidden = true;
    message(ui.FormMessage, "Cargando clientes y opciones…");
    controls();
    try {
      const response = await apiRequest(`${apiBase}/catalogo`);
      state.catalog = response.data;
      state.catalogLoadedAt = Date.now();
      for (const field of ["methods", "accounts", "clients", "currencies"]) state.catalog[field] = Array.isArray(state.catalog[field]) ? state.catalog[field] : [];
      ui.Method.replaceChildren(option("", "Selecciona un método"), ...state.catalog.methods.map((method) => option(method.id, method.name)));
      const query = new URLSearchParams(win.location.search);
      const currency = query.get("moneda");
      renderCurrencies(/^[A-Z]{3}$/.test(currency || "") ? currency : state.catalog.currency);
      renderAccounts();
      ui.DateTime.value = currentDateTime();
      ui.Branch.textContent = state.catalog.branch?.name ? `Sucursal: ${state.catalog.branch.name}` : "";
      state.catalogReady = true;
      renderClientList();
      message(ui.FormMessage);
      if (query.get("cliente_id")) await selectClient(query.get("cliente_id"));
    } catch (error) {
      state.catalogReady = false;
      message(ui.FormMessage, errorMessage(error), "error");
      ui.RetryCatalog.hidden = false;
    } finally {
      state.catalogLoading = false;
      controls();
    }
  }

  function beginEdit(movement) {
    if (!movement.can_edit || state.saving || state.deletingKey) return;
    if (movement.kind === "SALE" || movement.kind === "APPLIED_PAYMENT") {
      openOrigin(movement);
      return;
    }
    state.editing = movement;
    state.createAttempt = null;
    ui.MovementType.value = movement.kind;
    ui.Amount.value = movement.amount;
    renderCurrencies(movement.currency);
    renderAccounts(movement.account?.id ?? "", movement.account);
    if (movement.payment_method && !Array.from(ui.Method.options).some((item) => item.value === String(movement.payment_method.id))) {
      ui.Method.append(option(movement.payment_method.id, movement.payment_method.name));
    }
    ui.Method.value = String(movement.payment_method?.id ?? "");
    ui.DateTime.value = String(movement.date_time || "").replace(" ", "T").slice(0, 16);
    ui.Reference.value = movement.reference || "";
    ui.Notes.value = movement.notes || "";
    clearErrors();
    message(ui.FormMessage, `Editando ${movement.code || labels[movement.kind]}. El saldo se recalculará al guardar.`);
    controls();
    renderPreview();
    ui.FormTitle.scrollIntoView({ block: "center", behavior: "auto" });
    ui.Amount.focus({ preventScroll: true });
  }

  function mutationUrl(movement, action) {
    return movement?.[`${action}_url`] || (movement?.kind === "PAYMENT"
      ? `${apiBase}/${encodeURIComponent(movement.id)}` : `${apiBase}/ajustes/${encodeURIComponent(movement.id)}`);
  }

  function openOrigin(movement) {
    const path = movement.origin_url || movement.manage_url || movement.edit_url;
    if (!path || !path.startsWith("/") || path.startsWith("//")) return;
    const url = new URL(path, win.location.origin);
    if (movement.kind === "SALE") {
      url.searchParams.set("edit_ticket", String(movement.ticket_id || movement.id));
      url.searchParams.set("return_client", String(state.selectedClient.id));
      url.searchParams.set("moneda", ui.Currency.value);
    }
    win.location.assign(url.pathname + url.search);
  }

  async function saveMovement(event) {
    event.preventDefault();
    if (ui.Save.disabled || state.saving || state.deletingKey) return;
    clearErrors();
    if (!state.selectedClient) {
      message(ui.FormMessage, "Selecciona un cliente.", "error");
      ui.ChooseClient.focus();
      return;
    }
    if (!ui.Form.reportValidity()) return;
    const values = Object.fromEntries(Object.entries(fields).map(([name, field]) => [name, field.value]));
    values.cliente_id = state.selectedClient.id;
    values.tipo = ui.MovementType.value;
    const payload = values.tipo === "PAYMENT" ? buildProductDispatchPaymentPayload(values) : buildProductDispatchAdjustmentPayload(values);
    const editing = state.editing;
    const path = editing ? mutationUrl(editing, "edit") : values.tipo === "PAYMENT" ? apiBase : `${apiBase}/ajustes`;
    if (!editing) {
      const fingerprint = JSON.stringify({ path, payload });
      if (state.createAttempt?.fingerprint !== fingerprint) state.createAttempt = { fingerprint, key: paymentKey() };
      payload.idempotency_key = state.createAttempt.key;
    }
    state.saving = true;
    controls();
    message(ui.FormMessage, "Guardando movimiento…");
    try {
      const response = await apiRequest(path, { method: editing ? "PUT" : "POST", body: JSON.stringify(payload) });
      resetForm(false);
      message(ui.FormMessage, response.message || "Movimiento guardado correctamente.", "success");
      ui.Filters.reset();
      state.filters = {};
      await loadAccount(1);
    } catch (error) {
      message(ui.FormMessage, errorMessage(error), "error");
      state.saving = false;
      controls();
      for (const key of Object.keys(error?.data?.errors ?? {})) {
        (key === "cliente_id" ? ui.ChooseClient : key === "tipo" ? ui.MovementType : fields[key])?.setAttribute("aria-invalid", "true");
      }
      ui.Form.querySelector('[aria-invalid="true"]')?.focus();
    } finally {
      state.saving = false;
      controls();
    }
  }

  async function deleteMovement(movement) {
    if (!movement.can_delete || state.saving || state.deletingKey) return;
    if (!win.confirm(`¿Eliminar ${movement.code || labels[movement.kind]} por ${money(movement.amount, movement.currency)} de ${state.selectedClient.name}? Se recalculará la deuda o el saldo a favor del cliente.`)) return;
    state.deletingKey = transactionKey(movement);
    message(ui.ListMessage, "Eliminando movimiento…");
    renderRows();
    try {
      const body = movement.kind === "SALE" ? JSON.stringify({ version: movement.version }) : null;
      const response = await apiRequest(mutationUrl(movement, "delete"), { method: "DELETE", ...(body ? { body } : {}) });
      if (state.editing && transactionKey(state.editing) === transactionKey(movement)) resetForm(false);
      const loaded = await loadAccount(state.page);
      if (loaded) message(ui.ListMessage, response?.message || "Movimiento eliminado correctamente.", "success");
    } catch (error) {
      message(ui.ListMessage, errorMessage(error), "error");
    } finally {
      state.deletingKey = null;
      controls();
      ui.Reload.focus({ preventScroll: true });
    }
  }

  ui.Form.addEventListener("submit", saveMovement);
  ui.Reset.addEventListener("click", () => resetForm());
  ui.RetryCatalog.addEventListener("click", () => { void loadCatalog(); });
  ui.ChooseClient.addEventListener("click", () => {
    ui.ClientSearch.value = "";
    renderClientList();
    if (typeof ui.ClientDialog.showModal === "function") ui.ClientDialog.showModal();
    else ui.ClientDialog.setAttribute("open", "");
  });
  ui.CloseClientDialog.addEventListener("click", closeClientDialog);
  ui.ClientDialog.addEventListener("click", (event) => { if (event.target === ui.ClientDialog) closeClientDialog(); });
  ui.ClientSearch.addEventListener("input", renderClientList);
  ui.ClientList.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-client-id]");
    if (button) void selectClient(button.dataset.clientId);
  });
  ui.MovementType.addEventListener("change", () => { state.createAttempt = null; controls(); renderPreview(); });
  ui.Amount.addEventListener("input", renderPreview);
  ui.Currency.addEventListener("change", () => {
    renderAccounts();
    renderSelectedClient();
    const params = new URLSearchParams(win.location.search);
    params.set("moneda", ui.Currency.value);
    win.history.replaceState({}, "", `${win.location.pathname}?${params}`);
    void loadAccount(1);
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
    void loadAccount(1);
  });
  ui.ClearFilters.addEventListener("click", () => { ui.Filters.reset(); state.filters = {}; void loadAccount(1); });
  ui.Reload.addEventListener("click", () => { void loadAccount(state.page); });
  ui.Previous.addEventListener("click", () => { if (state.page > 1) void loadAccount(state.page - 1); });
  ui.Next.addEventListener("click", () => { if (state.page < state.lastPage) void loadAccount(state.page + 1); });
  ui.Rows.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action][data-key]");
    if (!button || button.disabled) return;
    const movement = state.transactions.find((item) => transactionKey(item) === button.dataset.key);
    if (!movement) return;
    if (button.dataset.action === "edit") beginEdit(movement);
    if (button.dataset.action === "delete") void deleteMovement(movement);
    if (button.dataset.action === "origin") openOrigin(movement);
  });
  win.addEventListener("auth:expired", () => message(ui.FormMessage, "La sesión venció. Inicia sesión nuevamente.", "error"));
  win.addEventListener("pageshow", (event) => { if (event.persisted && state.selectedClient) void loadAccount(state.page); });
  renderBalance();
  renderRows();
  return { ready: loadCatalog(), selectClient, refresh: loadAccount };
}

const root = typeof document !== "undefined" ? document.querySelector("#productDispatchPayments") : null;
if (root) {
  const { apiRequest } = await import("./api-client.js");
  mountProductDispatchPayments(root, apiRequest);
}
