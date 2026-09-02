export function normalizeProductDispatchClient(source = {}) {
  const externalValue = source.is_external;

  return {
    id: String(source.id ?? ""),
    documentType: String(
      source.document_type
      ?? source.tipo_documento
      ?? ""
    ).trim(),
    document: String(
      source.document
      ?? source.numero_documento
      ?? source.dni
      ?? ""
    ).trim(),
    name: String(
      source.name
      ?? source.nombre
      ?? source.nombre_razon_social
      ?? ""
    ).trim(),
    address: String(source.address ?? source.direccion ?? "").trim(),
    createdAt: source.created_at ?? source.createdAt ?? null,
    isExternal: externalValue === undefined ? true : Boolean(externalValue)
  };
}

export function normalizeProductDispatchClientForm(values = {}) {
  return {
    name: String(values.name ?? values.nombre_razon_social ?? "")
      .trim()
      .toLocaleUpperCase("es-PE"),
    document: String(values.document ?? values.numero_documento ?? "")
      .replace(/\D+/g, ""),
    address: String(values.address ?? values.direccion ?? "").trim()
  };
}

export function validateProductDispatchClientForm(values = {}) {
  const normalized = normalizeProductDispatchClientForm(values);
  const errors = {};

  if (!normalized.name) {
    errors.name = "Ingresa el nombre o razón social.";
  } else if (normalized.name.length > 180) {
    errors.name = "El nombre no puede superar los 180 caracteres.";
  }

  if (![8, 11].includes(normalized.document.length)) {
    errors.document = "El DNI debe tener 8 dígitos y el RUC 11 dígitos.";
  }

  if (!normalized.address) {
    errors.address = "Ingresa la dirección.";
  } else if (normalized.address.length > 250) {
    errors.address = "La dirección no puede superar los 250 caracteres.";
  }

  return {
    valid: Object.keys(errors).length === 0,
    values: normalized,
    errors
  };
}

export function buildProductDispatchClientPayload(values = {}) {
  const normalized = normalizeProductDispatchClientForm(values);

  return {
    nombre_razon_social: normalized.name,
    numero_documento: normalized.document,
    direccion: normalized.address
  };
}

export function buildProductDispatchClientListPath(apiBase, search = "") {
  const base = String(apiBase || "/despacho-productos/clientes").replace(/\/$/, "");
  const query = String(search || "").trim();

  if (!query) {
    return base;
  }

  return `${base}?${new URLSearchParams({ buscar: query }).toString()}`;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function initials(name) {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  return (parts.length > 1 ? `${parts[0][0]}${parts[1][0]}` : parts[0]?.slice(0, 2) || "CL")
    .toLocaleUpperCase("es-PE");
}

function firstErrorMessage(error) {
  const validation = error?.data?.errors;
  const firstValidationError = validation
    ? Object.values(validation).flat().find(Boolean)
    : null;

  return String(firstValidationError || error?.message || "No se pudo completar la operación.");
}

const root = typeof document !== "undefined"
  ? document.querySelector("#productDispatchQuickClients")
  : null;

if (root) {
  const { apiRequest } = await import("./api-client.js");
  const apiBase = String(root.dataset.apiBase || "/despacho-productos/clientes").replace(/\/$/, "");
  const elements = {
    form: document.querySelector("#pdqcForm"),
    formTitle: document.querySelector("#pdqcFormTitle"),
    formIntro: document.querySelector("#pdqcFormIntro"),
    editBadge: document.querySelector("#pdqcEditBadge"),
    name: document.querySelector("#pdqcName"),
    document: document.querySelector("#pdqcDocument"),
    address: document.querySelector("#pdqcAddress"),
    save: document.querySelector("#pdqcSave"),
    clear: document.querySelector("#pdqcClear"),
    cancelEdit: document.querySelector("#pdqcCancelEdit"),
    formMessage: document.querySelector("#pdqcFormMessage"),
    search: document.querySelector("#pdqcSearch"),
    listMessage: document.querySelector("#pdqcListMessage"),
    list: document.querySelector("#pdqcClientList"),
    count: document.querySelector("#pdqcClientCount")
  };
  const state = {
    clients: [],
    editingId: null,
    deletingId: null,
    requestSequence: 0,
    searchTimer: null,
    loading: false,
    saving: false
  };

  function setMessage(element, text = "", tone = "") {
    element.textContent = text;
    element.classList.toggle("is-error", tone === "error");
    element.classList.toggle("is-success", tone === "success");
  }

  function setFieldErrors(errors = {}) {
    elements.name.setAttribute("aria-invalid", errors.name ? "true" : "false");
    elements.document.setAttribute("aria-invalid", errors.document ? "true" : "false");
    elements.address.setAttribute("aria-invalid", errors.address ? "true" : "false");
  }

  function setSaving(saving) {
    state.saving = saving;
    elements.save.disabled = saving;
    elements.clear.disabled = saving;
    elements.cancelEdit.disabled = saving;
    elements.save.querySelector("span").textContent = saving
      ? "Guardando…"
      : (state.editingId ? "Guardar cambios" : "Guardar cliente");
  }

  function renderFormMode() {
    const editing = Boolean(state.editingId);
    elements.formTitle.textContent = editing ? "Editar cliente" : "Nuevo cliente";
    elements.formIntro.textContent = editing
      ? "Corrige los datos básicos y guarda los cambios."
      : "Completa los tres datos y guarda. El formulario quedará listo para registrar al siguiente cliente.";
    elements.editBadge.hidden = !editing;
    elements.clear.hidden = editing;
    elements.cancelEdit.hidden = !editing;
    elements.save.querySelector("span").textContent = state.saving
      ? "Guardando…"
      : (editing ? "Guardar cambios" : "Guardar cliente");
  }

  function resetForm({ focus = true, clearMessage = true } = {}) {
    state.editingId = null;
    elements.form.reset();
    setFieldErrors();
    renderFormMode();

    if (clearMessage) {
      setMessage(elements.formMessage);
    }
    if (focus) {
      elements.name.focus();
    }
  }

  function currentFormValues() {
    return {
      name: elements.name.value,
      document: elements.document.value,
      address: elements.address.value
    };
  }

  function renderLoading() {
    elements.list.innerHTML = Array.from({ length: 4 }, () => `
      <div class="pdqc-client-card pdqc-skeleton" aria-hidden="true"></div>
    `).join("");
  }

  function clientCard(client) {
    const busy = state.deletingId === client.id;

    return `
      <article class="pdqc-client-card" role="listitem" data-client-id="${escapeHtml(client.id)}">
        <span class="pdqc-client-avatar" aria-hidden="true">${escapeHtml(initials(client.name))}</span>
        <div class="pdqc-client-data">
          <strong>${escapeHtml(client.name || "Cliente sin nombre")}</strong>
          <span>${escapeHtml([client.documentType, client.document].filter(Boolean).join(" ") || "Sin documento")}</span>
          <address>${escapeHtml(client.address || "Sin dirección registrada")}</address>
        </div>
        <div class="pdqc-client-actions" role="group" aria-label="Acciones para ${escapeHtml(client.name || "cliente")}">
          <button class="pdqc-client-action" type="button" data-action="edit" data-client-id="${escapeHtml(client.id)}" aria-label="Editar a ${escapeHtml(client.name || "cliente")}" ${busy ? "disabled" : ""}>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M4 20h4l11-11-4-4L4 16z"></path><path d="m13 7 4 4"></path>
            </svg>
            <span>Editar</span>
          </button>
          <button class="pdqc-client-action is-danger" type="button" data-action="delete" data-client-id="${escapeHtml(client.id)}" aria-label="Eliminar a ${escapeHtml(client.name || "cliente")}" ${busy ? "disabled" : ""}>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M4 7h16M9 7V5h6v2M8 11v7M16 11v7M6 7l1 13h10l1-13"></path>
            </svg>
            <span>${busy ? "Eliminando…" : "Eliminar"}</span>
          </button>
        </div>
      </article>
    `;
  }

  function renderList() {
    elements.list.setAttribute("aria-busy", state.loading ? "true" : "false");

    if (state.loading) {
      renderLoading();
      return;
    }

    if (!state.clients.length) {
      const searching = Boolean(elements.search.value.trim());
      elements.list.innerHTML = `
        <div class="pdqc-empty">
          <strong>${searching ? "No encontramos coincidencias." : "Aún no hay clientes externos."}</strong>
          <span>${searching ? "Prueba con otro nombre o número de documento." : "Usa el formulario para registrar el primero."}</span>
        </div>
      `;
      return;
    }

    elements.list.innerHTML = state.clients.map(clientCard).join("");
  }

  function updateCount(total = state.clients.length) {
    const count = Number.isFinite(Number(total)) ? Number(total) : state.clients.length;
    elements.count.textContent = `${count} ${count === 1 ? "cliente" : "clientes"}`;
  }

  function responseRecords(response) {
    if (Array.isArray(response?.data)) {
      return response.data;
    }
    if (Array.isArray(response?.data?.clients)) {
      return response.data.clients;
    }
    if (Array.isArray(response?.clients)) {
      return response.clients;
    }

    return [];
  }

  async function loadClients(query = elements.search.value.trim()) {
    const requestId = ++state.requestSequence;
    let loaded = false;
    state.loading = true;
    setMessage(elements.listMessage);
    renderList();

    try {
      const response = await apiRequest(buildProductDispatchClientListPath(apiBase, query));
      if (requestId !== state.requestSequence) {
        return;
      }

      state.clients = responseRecords(response)
        .map(normalizeProductDispatchClient)
        .filter((client) => client.id && client.isExternal);
      updateCount(response?.meta?.total ?? response?.data?.total ?? state.clients.length);
      loaded = true;
    } catch (error) {
      if (requestId !== state.requestSequence) {
        return;
      }
      state.clients = [];
      updateCount(0);
      setMessage(elements.listMessage, firstErrorMessage(error), "error");
    } finally {
      if (requestId === state.requestSequence) {
        state.loading = false;
        renderList();
      }
    }

    return loaded;
  }

  function beginEdit(clientId) {
    const client = state.clients.find((item) => item.id === String(clientId));
    if (!client) {
      return;
    }

    state.editingId = client.id;
    elements.name.value = client.name;
    elements.document.value = client.document;
    elements.address.value = client.address;
    setFieldErrors();
    setMessage(elements.formMessage);
    renderFormMode();
    const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    elements.formTitle.scrollIntoView({ behavior: reducedMotion ? "auto" : "smooth", block: "center" });
    elements.name.focus({ preventScroll: true });
  }

  async function saveClient(event) {
    event.preventDefault();
    const validation = validateProductDispatchClientForm(currentFormValues());
    setFieldErrors(validation.errors);

    if (!validation.valid) {
      const firstError = Object.values(validation.errors)[0];
      setMessage(elements.formMessage, firstError, "error");
      const firstInvalid = elements.form.querySelector('[aria-invalid="true"]');
      firstInvalid?.focus();
      return;
    }

    const editingId = state.editingId;
    const endpoint = editingId ? `${apiBase}/${encodeURIComponent(editingId)}` : apiBase;
    setSaving(true);
    setMessage(elements.formMessage, editingId ? "Guardando cambios…" : "Guardando cliente…");

    try {
      const response = await apiRequest(endpoint, {
        method: editingId ? "PUT" : "POST",
        body: JSON.stringify(buildProductDispatchClientPayload(validation.values))
      });
      const successMessage = response?.message
        || (editingId ? "Cliente actualizado correctamente." : "Cliente registrado correctamente.");

      if (elements.search.value) {
        elements.search.value = "";
      }
      resetForm({ focus: false, clearMessage: false });
      setMessage(elements.formMessage, successMessage, "success");
      await loadClients("");
      elements.name.focus();
    } catch (error) {
      setMessage(elements.formMessage, firstErrorMessage(error), "error");
    } finally {
      setSaving(false);
      renderFormMode();
    }
  }

  async function deleteClient(clientId) {
    const client = state.clients.find((item) => item.id === String(clientId));
    if (!client || state.deletingId) {
      return;
    }

    const confirmed = window.confirm(`¿Eliminar al cliente ${client.name}?`);
    if (!confirmed) {
      return;
    }

    state.deletingId = client.id;
    setMessage(elements.listMessage, `Eliminando a ${client.name}…`);
    renderList();

    try {
      const response = await apiRequest(`${apiBase}/${encodeURIComponent(client.id)}`, {
        method: "DELETE"
      });
      const successMessage = response?.message || "Cliente eliminado correctamente.";
      if (state.editingId === client.id) {
        resetForm({ focus: false });
      }
      const refreshed = await loadClients();
      if (refreshed) {
        setMessage(elements.listMessage, successMessage, "success");
      }
    } catch (error) {
      setMessage(elements.listMessage, firstErrorMessage(error), "error");
    } finally {
      state.deletingId = null;
      renderList();
      elements.search.focus({ preventScroll: true });
    }
  }

  elements.form.addEventListener("submit", saveClient);
  elements.name.addEventListener("blur", () => {
    elements.name.value = elements.name.value.trim().toLocaleUpperCase("es-PE");
  });
  elements.document.addEventListener("input", () => {
    elements.document.value = elements.document.value.replace(/\D+/g, "").slice(0, 11);
    elements.document.setAttribute("aria-invalid", "false");
  });
  elements.name.addEventListener("input", () => elements.name.setAttribute("aria-invalid", "false"));
  elements.address.addEventListener("input", () => elements.address.setAttribute("aria-invalid", "false"));
  elements.clear.addEventListener("click", () => resetForm());
  elements.cancelEdit.addEventListener("click", () => resetForm());
  elements.search.addEventListener("input", () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(() => {
      void loadClients(elements.search.value.trim());
    }, 300);
  });
  elements.list.addEventListener("click", (event) => {
    const action = event.target.closest("[data-action][data-client-id]");
    if (!action) {
      return;
    }

    const clientId = action.dataset.clientId;
    if (action.dataset.action === "edit") {
      beginEdit(clientId);
      return;
    }
    if (action.dataset.action === "delete") {
      void deleteClient(clientId);
    }
  });
  window.addEventListener("auth:expired", () => {
    setMessage(elements.formMessage, "La sesión venció. Inicia sesión nuevamente.", "error");
  });

  renderFormMode();
  await loadClients();
}
