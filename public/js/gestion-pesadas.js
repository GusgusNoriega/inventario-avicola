import { apiRequest } from "./api-client.js";

const root = document.querySelector("[data-weighing-management]");

const elements = {
  message: document.getElementById("weighingManagementMessage"),
  searchForm: document.getElementById("ticketSearchForm"),
  searchInput: document.getElementById("ticketSearchInput"),
  searchClear: document.getElementById("ticketSearchClear"),
  resultCount: document.getElementById("ticketResultsCount"),
  resultList: document.getElementById("ticketResultsList"),
  selectedPanel: document.getElementById("selectedTicketPanel"),
  editModal: document.getElementById("editWeighingModal"),
  editForm: document.getElementById("editWeighingForm"),
  editClose: document.getElementById("editWeighingClose"),
  editCancel: document.getElementById("editWeighingCancel"),
  editMessage: document.getElementById("editWeighingMessage"),
  chickenTypeField: document.getElementById("editChickenTypeField"),
  chickenType: document.getElementById("editChickenType"),
  chickenConditionField: document.getElementById("editChickenConditionField"),
  chickenCondition: document.getElementById("editChickenCondition"),
  birdsPerCage: document.getElementById("editBirdsPerCage"),
  cages: document.getElementById("editCages"),
  cageType: document.getElementById("editCageType"),
  grossWeight: document.getElementById("editGrossWeight"),
  weightSource: document.getElementById("editWeightSource"),
  weighedAt: document.getElementById("editWeighedAt"),
  weightPreview: document.getElementById("editWeightPreview"),
  deleteModal: document.getElementById("deleteWeighingModal"),
  deleteForm: document.getElementById("deleteWeighingForm"),
  deleteCopy: document.getElementById("deleteWeighingCopy"),
  deleteReason: document.getElementById("deleteWeighingReason"),
  deleteMessage: document.getElementById("deleteWeighingMessage"),
  deleteCancel: document.getElementById("deleteWeighingCancel")
};

const state = {
  tickets: [],
  selectedTicket: null,
  catalogs: { chicken_types: [], cage_types: [] },
  editingWeighing: null,
  deletingWeighing: null,
  searchTimer: null,
  searching: false,
  saving: false
};

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function formatNumber(value) {
  return new Intl.NumberFormat("es-CO").format(Number(value || 0));
}

function formatWeight(value) {
  return `${Number(value || 0).toFixed(3)} kg`;
}

function formatDate(value, includeTime = false) {
  if (!value) {
    return "--";
  }

  if (includeTime) {
    const parts = String(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (parts) {
      return `${parts[3]}/${parts[2]}/${parts[1]}, ${parts[4]}:${parts[5]}`;
    }
  }

  const normalized = `${value}T12:00:00`;
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("es-CO", includeTime
    ? { dateStyle: "short", timeStyle: "short" }
    : { dateStyle: "medium" }
  ).format(date);
}

function operationLabel(value) {
  return value === "DEVOLUCION" ? "Devolución" : "Despacho";
}

function setMessage(text, isError = false) {
  elements.message.textContent = text || "";
  elements.message.hidden = !text;
  elements.message.classList.toggle("is-error", Boolean(isError));
}

function setModalMessage(element, text, isError = false) {
  element.textContent = text || "";
  element.classList.toggle("is-error", Boolean(isError));
}

function getErrorMessage(error, fallback = "No se pudo completar la solicitud.") {
  const validation = error?.data?.errors
    ? Object.values(error.data.errors).flat().find(Boolean)
    : null;

  return validation || error?.message || fallback;
}

function renderTicketResults() {
  elements.resultCount.textContent = formatNumber(state.tickets.length);

  if (!state.tickets.length) {
    elements.resultList.innerHTML = `
      <div class="weighing-results-empty">
        <strong>Sin resultados</strong>
        <span>Prueba con otro número de ticket o nombre de cliente.</span>
      </div>
    `;
    return;
  }

  elements.resultList.innerHTML = state.tickets.map((ticket) => {
    const active = Number(ticket.id) === Number(state.selectedTicket?.id);
    const readOnly = ticket.editable === false;
    return `
      <button class="weighing-ticket-option${active ? " is-active" : ""}${readOnly ? " is-readonly" : ""}" type="button" data-ticket-id="${ticket.id}">
        <span class="weighing-ticket-option-head">
          <strong>${escapeHtml(ticket.code)}</strong>
          <span class="weighing-ticket-option-tags">
            <small>${escapeHtml(operationLabel(ticket.operation_type))}</small>
            ${readOnly ? '<span class="weighing-readonly-badge">Solo lectura</span>' : ""}
          </span>
        </span>
        <span>${escapeHtml(ticket.destination?.name || "Sin destino")}</span>
        <small>${escapeHtml(formatDate(ticket.operating_date))} · ${formatNumber(ticket.weighings_count)} pesada${Number(ticket.weighings_count) === 1 ? "" : "s"}</small>
      </button>
    `;
  }).join("");
}

function renderSelectedTicket() {
  const ticket = state.selectedTicket;

  renderTicketResults();
  if (!ticket) {
    elements.selectedPanel.innerHTML = `
      <div class="directory-empty card">
        <strong>Selecciona un ticket</strong>
        <span>Solo se mostrarán las pesadas del ticket seleccionado.</span>
      </div>
    `;
    return;
  }

  const summary = ticket.summary || {};
  const rows = (ticket.weighings || []).map((weighing) => {
    const actions = ticket.editable
      ? `
          <button class="btn btn-secondary" type="button" data-edit-weighing="${weighing.id}">Editar</button>
          <button class="btn btn-danger" type="button" data-delete-weighing="${weighing.id}">Eliminar</button>
        `
      : '<span class="weighing-readonly-badge">Solo lectura</span>';

    return `
      <tr>
      <td><strong>#${formatNumber(weighing.number)}</strong></td>
      <td>
        <div class="customer-record-type">
          <strong>${escapeHtml(weighing.chicken_type?.name || "Sin tipo")}</strong>
          <span class="customer-chicken-condition${weighing.chicken_condition === "MUERTO" ? " customer-chicken-condition-muerto" : ""}">${escapeHtml(weighing.chicken_condition || "VIVO")}</span>
        </div>
      </td>
      <td>${escapeHtml(weighing.origin || "--")}<small>${weighing.plate ? `<br>${escapeHtml(weighing.plate)}` : ""}</small></td>
      <td>${escapeHtml(weighing.cage_type?.name || "--")}</td>
      <td>${formatNumber(weighing.cages)}</td>
      <td>${formatNumber(weighing.birds_per_cage)}</td>
      <td>${formatNumber(weighing.birds)}</td>
      <td>${formatWeight(weighing.gross_weight_kg)}</td>
      <td>${formatWeight(weighing.tare_weight_kg)}</td>
      <td><strong>${formatWeight(weighing.net_weight_kg)}</strong></td>
      <td>${escapeHtml(formatDate(weighing.weighed_at, true))}</td>
      <td>
        <div class="weighing-row-actions">
          ${actions}
        </div>
      </td>
      </tr>
    `;
  }).join("");

  elements.selectedPanel.innerHTML = `
    <article class="weighing-ticket-detail card">
      <header class="weighing-ticket-detail-head">
        <div>
          <div class="customer-ticket-badges">
            <span class="customer-operation-tag ${ticket.operation_type === "DEVOLUCION" ? "customer-operation-return" : "customer-operation-dispatch"}">${escapeHtml(operationLabel(ticket.operation_type))}</span>
            <span class="directory-record-tag">${escapeHtml(formatDate(ticket.operating_date))}</span>
            ${ticket.editable ? "" : '<span class="weighing-readonly-badge">Solo lectura</span>'}
          </div>
          <h2>${escapeHtml(ticket.code)}</h2>
          <p>${escapeHtml(ticket.destination?.name || "Sin destino registrado")}</p>
        </div>
        <button class="btn btn-ghost" type="button" data-refresh-ticket>Actualizar</button>
      </header>
      ${ticket.editable ? "" : `
        <div class="weighing-readonly-notice" role="note">
          ${escapeHtml(ticket.edit_restriction || "Este ticket pertenece a una jornada anterior y solo puede consultarse en esta vista.")}
        </div>
      `}
      <div class="weighing-ticket-stats">
        <span><small>Pesadas</small><strong>${formatNumber(summary.weighings)}</strong></span>
        <span><small>Javas</small><strong>${formatNumber(summary.cages)}</strong></span>
        <span><small>Aves</small><strong>${formatNumber(summary.birds)}</strong></span>
        <span><small>Peso bruto</small><strong>${formatWeight(summary.gross_weight_kg)}</strong></span>
        <span><small>Tara</small><strong>${formatWeight(summary.tare_weight_kg)}</strong></span>
        <span class="is-accent"><small>Peso neto</small><strong>${formatWeight(summary.net_weight_kg)}</strong></span>
      </div>
      <div class="customer-history-table-wrap weighing-table-wrap">
        <table class="customer-history-table weighing-records-table">
          <thead>
            <tr>
              <th>N.º</th><th>Tipo</th><th>Origen</th><th>Java</th><th>Javas</th><th>Aves/java</th><th>Aves</th><th>Bruto</th><th>Tara</th><th>Neto</th><th>Fecha</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>${rows || `<tr><td colspan="12" class="customer-history-empty-cell">Este ticket ya no tiene pesadas activas.</td></tr>`}</tbody>
        </table>
      </div>
    </article>
  `;
}

async function searchTickets(showStatus = true) {
  if (state.searching) {
    return;
  }

  state.searching = true;
  elements.resultList.classList.add("is-loading");
  if (showStatus) {
    setMessage("Buscando tickets...");
  }

  try {
    const query = elements.searchInput.value.trim();
    const suffix = query ? `?search=${encodeURIComponent(query)}` : "";
    const response = await apiRequest(`/operacion/gestion-pesadas${suffix}`);
    state.tickets = response.data?.tickets || [];
    renderTicketResults();
    if (showStatus) {
      setMessage("");
    }
  } catch (error) {
    state.tickets = [];
    renderTicketResults();
    setMessage(getErrorMessage(error, "No se pudieron buscar los tickets."), true);
  } finally {
    state.searching = false;
    elements.resultList.classList.remove("is-loading");
  }
}

async function selectTicket(ticketId, showStatus = true) {
  if (!ticketId) {
    return;
  }

  if (showStatus) {
    setMessage("Cargando pesadas del ticket...");
  }
  elements.selectedPanel.classList.add("is-loading");

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas`);
    state.selectedTicket = response.data?.ticket || null;
    state.catalogs = response.data?.catalogs || { chicken_types: [], cage_types: [] };
    renderSelectedTicket();
    setMessage("");
  } catch (error) {
    setMessage(getErrorMessage(error, "No se pudo cargar el ticket."), true);
  } finally {
    elements.selectedPanel.classList.remove("is-loading");
  }
}

function findSelectedWeighing(id) {
  return state.selectedTicket?.weighings?.find((item) => Number(item.id) === Number(id)) || null;
}

function renderEditOptions() {
  elements.chickenType.innerHTML = (state.catalogs.chicken_types || [])
    .map((type) => `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)}</option>`)
    .join("");
  elements.cageType.innerHTML = (state.catalogs.cage_types || [])
    .map((type) => `<option value="${escapeHtml(type.code)}">${escapeHtml(type.name)} (${Number(type.weight_kg).toFixed(3)} kg)</option>`)
    .join("");
}

function updateWeightPreview() {
  const cage = (state.catalogs.cage_types || []).find((item) => item.code === elements.cageType.value);
  const cages = Math.max(0, Number(elements.cages.value) || 0);
  const birdsPerCage = Math.max(0, Number(elements.birdsPerCage.value) || 0);
  const gross = Math.max(0, Number(elements.grossWeight.value) || 0);
  const tare = cages * Number(cage?.weight_kg || 0);
  const net = gross - tare;
  const birds = birdsPerCage * Math.max(cages, 1);

  elements.weightPreview.innerHTML = `
    <span><small>Aves totales</small><strong>${formatNumber(birds)}</strong></span>
    <span><small>Tara calculada</small><strong>${formatWeight(tare)}</strong></span>
    <span class="${net <= 0 ? "is-invalid" : ""}"><small>Peso neto</small><strong>${formatWeight(net)}</strong></span>
  `;
}

function openEditModal(weighingId) {
  if (state.selectedTicket?.editable === false) {
    setMessage("Este ticket pertenece a una jornada anterior y solo puede consultarse.", true);
    return;
  }

  const weighing = findSelectedWeighing(weighingId);
  if (!weighing) {
    setMessage("La pesada seleccionada ya no existe.", true);
    return;
  }

  state.editingWeighing = weighing;
  renderEditOptions();
  const isReturn = state.selectedTicket.operation_type === "DEVOLUCION";
  elements.chickenTypeField.hidden = isReturn;
  elements.chickenConditionField.hidden = !isReturn;
  elements.chickenType.value = weighing.chicken_type?.code || "";
  elements.chickenCondition.value = weighing.chicken_condition || "VIVO";
  elements.birdsPerCage.value = weighing.birds_per_cage;
  elements.cages.value = weighing.cages;
  elements.cageType.value = weighing.cage_type?.code || "";
  elements.grossWeight.value = Number(weighing.gross_weight_kg).toFixed(3);
  elements.weightSource.value = [...elements.weightSource.options].some((option) => option.value === weighing.weight_source)
    ? weighing.weight_source
    : "MANUAL";
  elements.weighedAt.value = String(weighing.weighed_at || "").slice(0, 16);
  setModalMessage(elements.editMessage, "");
  updateWeightPreview();
  elements.editModal.hidden = false;
  elements.birdsPerCage.focus();
}

function closeEditModal() {
  if (state.saving) {
    return;
  }
  state.editingWeighing = null;
  elements.editModal.hidden = true;
  setModalMessage(elements.editMessage, "");
}

async function saveWeighing(event) {
  event.preventDefault();
  if (!state.editingWeighing || state.saving) {
    return;
  }

  state.saving = true;
  setModalMessage(elements.editMessage, "Guardando cambios...");
  const ticketId = state.selectedTicket.id;
  const weighingId = state.editingWeighing.id;
  const payload = {
    chicken_type_code: elements.chickenType.value || state.editingWeighing.chicken_type?.code,
    chicken_condition: elements.chickenCondition.value,
    cage_type_code: elements.cageType.value,
    weight_source: elements.weightSource.value,
    birds_per_cage: Number(elements.birdsPerCage.value),
    cages: Number(elements.cages.value),
    gross_weight_kg: Number(elements.grossWeight.value),
    weighed_at: elements.weighedAt.value
  };

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas/${weighingId}`, {
      method: "PUT",
      body: JSON.stringify(payload)
    });
    state.selectedTicket = response.data?.ticket || state.selectedTicket;
    state.editingWeighing = null;
    elements.editModal.hidden = true;
    renderSelectedTicket();
    setMessage(response.message || "Pesada actualizada correctamente.");
    await searchTickets(false);
  } catch (error) {
    setModalMessage(elements.editMessage, getErrorMessage(error, "No se pudo actualizar la pesada."), true);
  } finally {
    state.saving = false;
  }
}

function openDeleteModal(weighingId) {
  if (state.selectedTicket?.editable === false) {
    setMessage("Este ticket pertenece a una jornada anterior y solo puede consultarse.", true);
    return;
  }

  const weighing = findSelectedWeighing(weighingId);
  if (!weighing) {
    setMessage("La pesada seleccionada ya no existe.", true);
    return;
  }

  state.deletingWeighing = weighing;
  elements.deleteCopy.textContent = `Se eliminará la pesada #${weighing.number} del ticket ${state.selectedTicket.code}. Esta acción quedará registrada.`;
  elements.deleteReason.value = "";
  setModalMessage(elements.deleteMessage, "");
  elements.deleteModal.hidden = false;
  elements.deleteReason.focus();
}

function closeDeleteModal() {
  if (state.saving) {
    return;
  }
  state.deletingWeighing = null;
  elements.deleteModal.hidden = true;
  setModalMessage(elements.deleteMessage, "");
}

async function deleteWeighing(event) {
  event.preventDefault();
  if (!state.deletingWeighing || state.saving) {
    return;
  }

  state.saving = true;
  setModalMessage(elements.deleteMessage, "Eliminando pesada...");
  const ticketId = state.selectedTicket.id;
  const weighingId = state.deletingWeighing.id;

  try {
    const response = await apiRequest(`/operacion/tickets/${ticketId}/pesadas/${weighingId}`, {
      method: "DELETE",
      body: JSON.stringify({ reason: elements.deleteReason.value.trim() })
    });
    state.selectedTicket = response.data?.ticket || state.selectedTicket;
    state.deletingWeighing = null;
    elements.deleteModal.hidden = true;
    renderSelectedTicket();
    setMessage(response.message || "Pesada eliminada correctamente.");
    await searchTickets(false);
  } catch (error) {
    setModalMessage(elements.deleteMessage, getErrorMessage(error, "No se pudo eliminar la pesada."), true);
  } finally {
    state.saving = false;
  }
}

function bindEvents() {
  elements.searchForm.addEventListener("submit", (event) => {
    event.preventDefault();
    window.clearTimeout(state.searchTimer);
    void searchTickets();
  });
  elements.searchInput.addEventListener("input", () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(() => void searchTickets(false), 350);
  });
  elements.searchClear.addEventListener("click", () => {
    elements.searchInput.value = "";
    elements.searchInput.focus();
    void searchTickets();
  });
  elements.resultList.addEventListener("click", (event) => {
    const option = event.target.closest("[data-ticket-id]");
    if (option) {
      void selectTicket(option.dataset.ticketId);
    }
  });
  elements.selectedPanel.addEventListener("click", (event) => {
    const editButton = event.target.closest("[data-edit-weighing]");
    const deleteButton = event.target.closest("[data-delete-weighing]");
    if (editButton) {
      openEditModal(editButton.dataset.editWeighing);
    } else if (deleteButton) {
      openDeleteModal(deleteButton.dataset.deleteWeighing);
    } else if (event.target.closest("[data-refresh-ticket]")) {
      void selectTicket(state.selectedTicket?.id);
    }
  });
  elements.editForm.addEventListener("submit", saveWeighing);
  elements.editClose.addEventListener("click", closeEditModal);
  elements.editCancel.addEventListener("click", closeEditModal);
  elements.deleteForm.addEventListener("submit", deleteWeighing);
  elements.deleteCancel.addEventListener("click", closeDeleteModal);
  [elements.cages, elements.birdsPerCage, elements.grossWeight, elements.cageType]
    .forEach((control) => control.addEventListener("input", updateWeightPreview));
  elements.editModal.addEventListener("click", (event) => {
    if (event.target === elements.editModal) {
      closeEditModal();
    }
  });
  elements.deleteModal.addEventListener("click", (event) => {
    if (event.target === elements.deleteModal) {
      closeDeleteModal();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }
    if (!elements.deleteModal.hidden) {
      closeDeleteModal();
    } else if (!elements.editModal.hidden) {
      closeEditModal();
    }
  });
}

if (root) {
  bindEvents();
  void searchTickets();
}
