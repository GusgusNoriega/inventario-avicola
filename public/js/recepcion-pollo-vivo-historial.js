import { apiRequest } from "./api-client.js";
import {
  buildHistoryQuery,
  buildHistoryReportUrl,
  formatHistoryNumber,
  formatHistoryWeight,
  normalizeHistoryPayload,
  renderHistoryRow,
} from "./live-chicken-reception-history.js";

const root = document.querySelector("[data-live-reception-history]");

if (root) {
  const elements = {
    form: document.getElementById("liveHistoryFilters"),
    journey: document.getElementById("liveHistoryJourneyFilter"),
    status: document.getElementById("liveHistoryStatusFilter"),
    source: document.getElementById("liveHistorySourceFilter"),
    submit: document.getElementById("liveHistoryFilterSubmit"),
    reset: document.getElementById("liveHistoryFilterReset"),
    returnCurrent: document.getElementById("liveHistoryReturnCurrent"),
    message: document.getElementById("liveHistoryMessage"),
    journeyTitle: document.getElementById("liveHistoryJourneyTitle"),
    journeyWindow: document.getElementById("liveHistoryJourneyWindow"),
    branch: document.getElementById("liveHistoryBranch"),
    journeyBadge: document.getElementById("liveHistoryJourneyBadge"),
    activeWeighings: document.getElementById("liveHistoryActiveWeighings"),
    activeCages: document.getElementById("liveHistoryActiveCages"),
    activeBirds: document.getElementById("liveHistoryActiveBirds"),
    activeGross: document.getElementById("liveHistoryActiveGross"),
    activeTare: document.getElementById("liveHistoryActiveTare"),
    activeNet: document.getElementById("liveHistoryActiveNet"),
    voidedWeighings: document.getElementById("liveHistoryVoidedWeighings"),
    voidedCages: document.getElementById("liveHistoryVoidedCages"),
    voidedBirds: document.getElementById("liveHistoryVoidedBirds"),
    voidedGross: document.getElementById("liveHistoryVoidedGross"),
    voidedTare: document.getElementById("liveHistoryVoidedTare"),
    voidedNet: document.getElementById("liveHistoryVoidedNet"),
    rows: document.getElementById("liveHistoryRows"),
    recordRange: document.getElementById("liveHistoryRecordRange"),
    pagination: document.getElementById("liveHistoryPagination"),
    pagePrevious: document.getElementById("liveHistoryPagePrevious"),
    pageNext: document.getElementById("liveHistoryPageNext"),
    pageStatus: document.getElementById("liveHistoryPageStatus"),
    reportPdf: document.getElementById("liveHistoryReportPdf"),
    reportImages: document.getElementById("liveHistoryReportImages"),
  };

  const journeyDateFormatter = new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
  const windowPointFormatter = new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });

  const state = {
    data: null,
    loading: false,
    currentPage: 1,
    lastRequest: null,
  };

  function parseOperatingDate(value) {
    if (!value) return null;
    const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function formatJourneyDate(value) {
    const date = parseOperatingDate(value);
    return date ? journeyDateFormatter.format(date) : String(value || "Sin fecha");
  }

  function formatWindowPoint(value) {
    if (!value) return "--";
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : windowPointFormatter.format(date);
  }

  function journeyStatusLabel(status) {
    const normalized = String(status || "").toUpperCase();
    if (normalized === "ABIERTA") return "Abierta";
    if (normalized === "CERRADA") return "Cerrada";
    return normalized ? String(status) : "Sin estado";
  }

  function setMessage(message, type = "") {
    elements.message.textContent = message;
    elements.message.classList.toggle("is-error", type === "error");
    elements.message.classList.toggle("is-success", type === "success");
  }

  function setControlsDisabled(disabled) {
    elements.journey.disabled = disabled || !state.data?.selected_journey?.id;
    elements.status.disabled = disabled;
    elements.source.disabled = disabled;
    elements.submit.disabled = disabled;
    elements.reset.disabled = disabled;
    elements.returnCurrent.disabled = disabled;
    elements.pagePrevious.disabled = disabled;
    elements.pageNext.disabled = disabled;
  }

  function updateReportLinks(journeyId = elements.journey.value) {
    [
      [elements.reportPdf, buildHistoryReportUrl("pdf", journeyId)],
      [elements.reportImages, buildHistoryReportUrl("images", journeyId)],
    ].forEach(([link, url]) => {
      if (url) {
        link.href = url;
        link.setAttribute("aria-disabled", "false");
        link.removeAttribute("tabindex");
        return;
      }

      link.removeAttribute("href");
      link.setAttribute("aria-disabled", "true");
      link.setAttribute("tabindex", "-1");
    });
  }

  function renderTableState(message, { error = false, retry = false } = {}) {
    elements.rows.innerHTML = `
      <tr class="live-history-state-row ${error ? "is-error" : ""}">
        <td colspan="12">
          ${message}
          ${retry ? `
            <div class="live-history-state-actions">
              <button class="btn btn-ghost" type="button" data-live-history-retry>Intentar nuevamente</button>
            </div>
          ` : ""}
        </td>
      </tr>
    `;
  }

  function setLoading(loading) {
    state.loading = loading;
    setControlsDisabled(loading);

    if (loading) {
      setMessage("Cargando totales y pesadas de la jornada…");
      renderTableState("Cargando pesadas de la jornada…");
      elements.pagination.hidden = true;
      return;
    }

    setControlsDisabled(false);
    if (state.data) renderPagination(state.data.pagination);
  }

  function selectedOptionExists(select, value) {
    return [...select.options].some((option) => option.value === String(value ?? ""));
  }

  function journeyOptionLabel(journey, currentJourneyId) {
    const current = Number(journey.id) === Number(currentJourneyId) ? " · Actual" : "";
    return `${formatJourneyDate(journey.operating_date)}${current} · ${journeyStatusLabel(journey.status)}`;
  }

  function renderJourneyOptions(data) {
    const journeys = data.catalog?.journeys || [];
    const selected = data.selected_journey;
    const options = journeys.map((journey) => {
      const option = document.createElement("option");
      option.value = String(journey.id);
      option.textContent = journeyOptionLabel(journey, data.current_journey_id);
      return option;
    });

    if (selected?.id && !journeys.some((journey) => Number(journey.id) === Number(selected.id))) {
      const selectedOption = document.createElement("option");
      selectedOption.value = String(selected.id);
      selectedOption.textContent = journeyOptionLabel(selected, data.current_journey_id);
      options.push(selectedOption);
    }

    if (!options.length) {
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = "No hay jornadas de recepción registradas";
      options.push(empty);
    }

    elements.journey.replaceChildren(...options);
    elements.journey.value = selected?.id ? String(selected.id) : "";
    elements.journey.disabled = state.loading || !selected?.id;
    updateReportLinks(elements.journey.value);
  }

  function renderFilterValues(data) {
    const status = data.applied_filters?.status || "";
    const source = data.applied_filters?.source || "";
    elements.status.value = selectedOptionExists(elements.status, status) ? status : "";
    elements.source.value = selectedOptionExists(elements.source, source) ? source : "";
  }

  function renderContext(data) {
    const journey = data.selected_journey;
    elements.branch.textContent = data.branch?.name || "--";
    elements.journeyTitle.textContent = journey
      ? `Jornada del ${formatJourneyDate(journey.operating_date)}`
      : "Sin jornada de recepción";

    if (journey) {
      const window = journey.starts_at && journey.ends_at
        ? `${formatWindowPoint(journey.starts_at)} a ${formatWindowPoint(journey.ends_at)}`
        : "Horario no registrado";
      elements.journeyWindow.textContent = `${window} · Estado: ${journeyStatusLabel(journey.status)}`;
    } else {
      elements.journeyWindow.textContent = "Las jornadas aparecerán después de registrar una recepción.";
    }

    elements.journeyBadge.textContent = data.is_current_journey
      ? "Jornada actual"
      : (journey ? "Jornada histórica" : "Sin jornada");
    elements.journeyBadge.classList.toggle("is-historical", !data.is_current_journey);
    elements.returnCurrent.hidden = Boolean(
      !data.current_journey_id
      || data.is_current_journey
      || Number(data.current_journey_id) === Number(journey?.id),
    );
  }

  function renderTotalsInto(totals, prefix) {
    elements[`${prefix}Weighings`].textContent = formatHistoryNumber(totals.weighings);
    elements[`${prefix}Cages`].textContent = formatHistoryNumber(totals.cages);
    elements[`${prefix}Birds`].textContent = formatHistoryNumber(totals.birds);
    elements[`${prefix}Gross`].textContent = formatHistoryWeight(totals.gross_weight_kg);
    elements[`${prefix}Tare`].textContent = formatHistoryWeight(totals.tare_weight_kg);
    elements[`${prefix}Net`].textContent = formatHistoryWeight(totals.net_weight_kg);
  }

  function renderTotals(summary) {
    renderTotalsInto(summary.active, "active");
    renderTotalsInto(summary.voided, "voided");
  }

  function renderRecords(records) {
    if (!records.length) {
      renderTableState("No se encontraron pesadas con los filtros seleccionados.");
      return;
    }

    elements.rows.innerHTML = records.map((record) => renderHistoryRow(record)).join("");
  }

  function renderPagination(pagination) {
    const total = Number(pagination.total || 0);
    const currentPage = Number(pagination.current_page || 1);
    const lastPage = Number(pagination.last_page || 1);
    state.currentPage = currentPage;

    elements.recordRange.textContent = total
      ? `${formatHistoryNumber(pagination.from)}–${formatHistoryNumber(pagination.to)} de ${formatHistoryNumber(total)} pesadas`
      : "0 pesadas";
    elements.pagination.hidden = lastPage <= 1;
    elements.pageStatus.textContent = `Página ${formatHistoryNumber(currentPage)} de ${formatHistoryNumber(lastPage)}`;
    elements.pagePrevious.disabled = state.loading || currentPage <= 1;
    elements.pageNext.disabled = state.loading || currentPage >= lastPage;
  }

  function appliedFilterDescription(data) {
    const labels = [];
    if (data.applied_filters?.status === "ACTIVA") labels.push("solo activas");
    if (data.applied_filters?.status === "ANULADA") labels.push("solo anuladas");
    if (data.applied_filters?.source === "RECEPCION") labels.push("entradas de recepción");
    if (data.applied_filters?.source === "TICKET") labels.push("tickets de despacho");
    return labels.length ? ` · Filtro: ${labels.join(" y ")}` : "";
  }

  function renderResultMessage(data) {
    const total = Number(data.pagination?.total || 0);
    const journey = data.selected_journey;
    const filters = appliedFilterDescription(data);

    if (!journey) {
      setMessage("Todavía no hay jornadas con registros de Recepción de pollo vivo.");
      return;
    }
    if (!total) {
      setMessage(`La jornada del ${formatJourneyDate(journey.operating_date)} no tiene pesadas que coincidan con los filtros.${filters}`);
      return;
    }

    setMessage(
      `${formatHistoryNumber(total)} ${total === 1 ? "pesada encontrada" : "pesadas encontradas"} en la jornada del ${formatJourneyDate(journey.operating_date)}${filters}.`,
      "success",
    );
  }

  function renderHistory(data) {
    state.data = data;
    renderJourneyOptions(data);
    renderFilterValues(data);
    renderContext(data);
    renderTotals(data.summary);
    renderRecords(data.records);
    renderPagination(data.pagination);
    renderResultMessage(data);
  }

  function filtersFromForm() {
    return {
      journey_id: elements.journey.value,
      status: elements.status.value,
      source: elements.source.value,
    };
  }

  function initialFilters() {
    const params = new URLSearchParams(window.location.search);
    return {
      journey_id: params.get("journey_id") || "",
      status: params.get("status") || "",
      source: params.get("source") || "",
      page: Math.max(1, Number(params.get("page") || 1)),
    };
  }

  function updateUrl(data) {
    const params = new URLSearchParams();
    if (data.selected_journey?.id) params.set("journey_id", String(data.selected_journey.id));
    if (data.applied_filters?.status) params.set("status", data.applied_filters.status);
    if (data.applied_filters?.source) params.set("source", data.applied_filters.source);
    if (Number(data.pagination?.current_page || 1) > 1) {
      params.set("page", String(data.pagination.current_page));
    }
    const query = params.toString();
    window.history.replaceState({}, "", `${window.location.pathname}${query ? `?${query}` : ""}`);
  }

  function errorMessage(error) {
    const validation = error?.data?.errors;
    if (validation && typeof validation === "object") {
      const first = Object.values(validation).flat().find(Boolean);
      if (first) return String(first);
    }
    return error?.data?.message || error?.message || "No se pudo cargar el historial.";
  }

  async function loadHistory({ page = 1, filters = filtersFromForm() } = {}) {
    if (state.loading) return;

    const request = {
      journey_id: filters.journey_id || "",
      status: filters.status || "",
      source: filters.source || "",
      page: Math.max(1, Number(page) || 1),
      per_page: 30,
    };
    state.lastRequest = request;
    setLoading(true);

    try {
      const response = await apiRequest(
        `/recepcion-pollo-vivo/historial?${buildHistoryQuery(request)}`,
      );
      const data = normalizeHistoryPayload(response);
      renderHistory(data);
      updateUrl(data);
    } catch (error) {
      console.error(error);
      state.data = null;
      setMessage(errorMessage(error), "error");
      renderTableState("No se pudo cargar el detalle de pesadas.", { error: true, retry: true });
      elements.recordRange.textContent = "Sin datos";
      elements.pagination.hidden = true;
    } finally {
      setLoading(false);
    }
  }

  elements.form.addEventListener("submit", (event) => {
    event.preventDefault();
    void loadHistory({ page: 1 });
  });

  elements.journey.addEventListener("change", () => {
    updateReportLinks(elements.journey.value);
    void loadHistory({ page: 1 });
  });

  elements.reset.addEventListener("click", () => {
    elements.status.value = "";
    elements.source.value = "";
    const journeyId = state.data?.current_journey_id || state.data?.selected_journey?.id || "";
    elements.journey.value = selectedOptionExists(elements.journey, journeyId)
      ? String(journeyId)
      : elements.journey.value;
    updateReportLinks(elements.journey.value);
    void loadHistory({ page: 1 });
  });

  elements.returnCurrent.addEventListener("click", () => {
    const currentJourneyId = state.data?.current_journey_id || "";
    if (!currentJourneyId) return;
    elements.journey.value = String(currentJourneyId);
    elements.status.value = "";
    elements.source.value = "";
    updateReportLinks(elements.journey.value);
    void loadHistory({
      page: 1,
      filters: { journey_id: currentJourneyId, status: "", source: "" },
    });
  });

  elements.pagePrevious.addEventListener("click", () => {
    if (state.currentPage > 1) void loadHistory({ page: state.currentPage - 1 });
  });

  elements.pageNext.addEventListener("click", () => {
    const lastPage = Number(state.data?.pagination?.last_page || 1);
    if (state.currentPage < lastPage) void loadHistory({ page: state.currentPage + 1 });
  });

  elements.rows.addEventListener("click", (event) => {
    if (event.target.closest("[data-live-history-retry]")) {
      const retry = state.lastRequest || initialFilters();
      void loadHistory({ page: retry.page || 1, filters: retry });
    }
  });

  const initial = initialFilters();
  updateReportLinks("");
  void loadHistory({ page: initial.page, filters: initial });
}
