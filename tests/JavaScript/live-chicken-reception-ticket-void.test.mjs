import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
  calculateDraftTotals,
  normalizeFullTicket,
  normalizeReceptionSex,
  isDispatchTicketRecord,
} from "../../public/js/live-chicken-reception-tickets.js";

const source = readFileSync(new URL("../../public/js/recepcion-pollo-vivo.js", import.meta.url), "utf8")
  .replaceAll("\r\n", "\n");

function functionSource(name) {
  const start = source.search(new RegExp(`^(?:async )?function ${name}\\(`, "m"));
  const end = source.indexOf("\n}", start);
  assert.ok(start >= 0 && end > start, `No se encontró la función ${name}`);
  return source.slice(start, end + 2);
}

function node(properties = {}) {
  const classes = new Set();
  const attributes = new Map();
  const parent = { hidden: false };
  return {
    value: "", disabled: false, hidden: false, textContent: "", innerHTML: "", dataset: {},
    focused: false, focus() { this.focused = true; }, scrollIntoView() {},
    classList: {
      contains: (value) => classes.has(value),
      add: (...values) => values.forEach((value) => classes.add(value)),
      remove: (...values) => values.forEach((value) => classes.delete(value)),
      toggle(value, force = !classes.has(value)) { if (force) classes.add(value); else classes.delete(value); },
    },
    setAttribute: (key, value) => attributes.set(key, String(value)),
    getAttribute: (key) => attributes.get(key) ?? null,
    removeAttribute: (key) => attributes.delete(key),
    closest: () => parent, querySelector: () => null, querySelectorAll: () => [],
    ...properties,
  };
}

function rowSurface() {
  let markup = "";
  let rows = [];
  const surface = node({
    querySelector(selector) {
      if (selector === ".is-summary-target") return rows.find((row) => row.targeted) || null;
      return rows.find((row) => selector.includes(`"${row.dataset.liveTicketWeighing}"`)) || null;
    },
    querySelectorAll(selector) {
      if (selector === "[data-live-ticket-weighing]") return rows;
      if (selector.includes("data-live-void-ticket-weighing")) return rows.flatMap((row) => row.buttons);
      return rows.flatMap((row) => Object.values(row.fields));
    },
  });
  Object.defineProperty(surface, "innerHTML", {
    get: () => markup,
    set(value) {
      markup = value;
      rows = [...value.matchAll(/<fieldset\b([^>]*)>([\s\S]*?)<\/fieldset>/g)].map(([, attributes, body]) => {
        const id = attributes.match(/data-live-ticket-weighing="([^"]+)"/)?.[1];
        const fields = {};
        for (const [, tag] of body.matchAll(/(<input\b[^>]*>)/g)) {
          const name = tag.match(/data-ticket-field="([^"]+)"/)?.[1];
          if (name) fields[name] = node({ value: tag.match(/value="([^"]*)"/)?.[1] || "" });
        }
        for (const [, attrs, content] of body.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)) {
          const name = attrs.match(/data-ticket-field="([^"]+)"/)?.[1];
          const options = [...content.matchAll(/<option\b([^>]*)>/g)].map(([, option]) => option);
          const selected = options.find((option) => /\bselected\b/.test(option)) || options[0] || "";
          if (name) fields[name] = node({ value: selected.match(/value="([^"]*)"/)?.[1] || "" });
        }
        const buttons = [...body.matchAll(/<button\b([^>]*)>/g)]
          .filter(([, attrs]) => attrs.includes("data-live-void-ticket-weighing"))
          .map(([, attrs]) => node({
            disabled: /\bdisabled\b/.test(attrs),
            dataset: { liveVoidTicketWeighing: attrs.match(/data-live-void-ticket-weighing="([^"]+)"/)?.[1] },
          }));
        return node({
          dataset: { liveTicketWeighing: id }, targeted: attributes.includes("is-summary-target"), fields, buttons,
          querySelector(selector) { return fields[selector.match(/data-ticket-field="([^"]+)"/)?.[1]] || null; },
          querySelectorAll() { return Object.values(fields); },
        });
      });
    },
  });
  return surface;
}

function sampleTicket(overrides = {}) {
  return normalizeFullTicket({
    id: 90, code: "T-090", link_revision: 7, editable: true, can_void_last_weighing: true,
    client: { id: 4, name: "Cliente conservado" },
    weighings: [
      { id: 501, number: 1, sex: "MACHO", cage_count: 2, birds_per_cage: 7, read_weight_kg: 60,
        gross_weight_kg: 60, tare_weight_kg: 13.6, net_weight_kg: 46.4 },
      { id: 502, number: 2, sex: "HEMBRA", cage_count: 3, birds_per_cage: 9, read_weight_kg: 80,
        gross_weight_kg: 80, tare_weight_kg: 20.4, net_weight_kg: 59.6 },
    ].map((weighing) => ({
      ...weighing, cage_type_id: 1, cage_type: { id: 1, name: "Java 6.80", weight_kg: 6.8 },
      weighed_at: "2026-08-31T14:00:00.000Z", updated_at: "2026-08-31T14:01:00.000Z",
      weight_source: "BALANZA_RECEPCION_POLLO_VIVO",
    })),
    ...overrides,
  });
}

function createHarness(ticket = sampleTicket()) {
  const state = {
    busy: false, editingTicket: ticket, ticketVoidWeighingId: null, ticketEditorFocusWeighingId: null,
    ticketEditorCatalog: {}, ticketEditorRequestId: 0, summaryEditorReturn: null,
    data: { totals: { daily: calculateDraftTotals(ticket.weighings) }, records: [] },
    manualWeightOverride: { id: "manual-pending", weightKg: 88 },
    dispatchDrafts: { 5: { weighings: [{ local_id: "unregistered", read_weight_kg: 32 }] } },
  };
  const elements = Object.fromEntries([
    "ticketEditorTitle", "ticketEditorOwner", "ticketEditorClient", "ticketEditorHelp", "ticketEditorSummary",
    "ticketEditorMessage", "ticketEditReason", "printTicket", "saveTicket", "cancelTicket", "ticketEditorModal",
    "ticketVoidConfirmation", "ticketVoidTitle", "ticketVoidHelp", "ticketVoidReason", "confirmTicketVoid",
    "cancelTicketVoid", "capture",
  ].map((name) => [name, node()]));
  elements.ticketEditorRows = rowSurface();
  elements.ticketVoidConfirmation.hidden = true;
  elements.ticketEditorModal.querySelector = () => node();
  const calls = { requests: [], rendered: [], finished: [], messages: [], scaleUpdates: 0 };
  let responder = async () => ({ data: state.data, ticket: state.editingTicket, voided_weighing_id: 502 });
  const functions = [
    "toDateTimeLocal", "setTicketEditorMessage", "renderTicketEditor", "ticketEditorHasUnsavedChanges",
    "beginTicketWeighingVoid", "cancelTicketWeighingVoid", "syncTicketEditorControls", "submitTicketWeighingVoid",
  ];
  const dependencies = {
    state, elements, LAYOUT_VERSION: 4, calculateDraftTotals, normalizeFullTicket,
    escapeHtml: String, formatKg: (value) => `${Number(value).toFixed(3)} kg`,
    cageTypeOptionMarkup: (id) => `<option value="${id}" selected>Java histórica</option>`,
    globalThis: { requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() },
    firstValidationMessage: (error) => error.message,
    updateScaleUi() { calls.scaleUpdates += 1; },
    setMessage(...args) { calls.messages.push(args); },
    renderData(data) { calls.rendered.push(data); state.data = data; },
    finishTicketEditor(...args) { calls.finished.push(args); state.editingTicket = null; },
    apiRequest(path, options) {
      calls.requests.push({ path, method: options.method, payload: JSON.parse(options.body) });
      return responder(path, options);
    },
  };
  const api = new Function(...Object.keys(dependencies), `
    ${functions.map(functionSource).join("\n")}
    return { ${functions.join(", ")} };
  `)(...Object.values(dependencies));
  api.renderTicketEditor();
  return {
    state, elements, calls, api,
    respondWith(value) { responder = typeof value === "function" ? value : async () => value; },
    row(id) { return elements.ticketEditorRows.querySelectorAll("[data-live-ticket-weighing]")
      .find((row) => Number(row.dataset.liveTicketWeighing) === id); },
    start(id = 502, reason = "Pesada duplicada") {
      api.beginTicketWeighingVoid(id);
      elements.ticketVoidReason.value = reason;
    },
  };
}

test("el ticket editable ofrece una anulación identificada por pesada y conserva los campos originales", () => {
  const harness = createHarness();
  assert.deepEqual(harness.elements.ticketEditorRows.querySelectorAll("[data-live-void-ticket-weighing]")
    .map((button) => button.dataset.liveVoidTicketWeighing), ["501", "502"]);
  assert.equal(harness.api.ticketEditorHasUnsavedChanges(), false);
  harness.row(501).fields.cage_count.value = "4";
  assert.equal(harness.api.ticketEditorHasUnsavedChanges(), true);
});

test("la fecha sin segundos que normaliza el navegador no impide anular una pesada sin cambios", () => {
  const harness = createHarness();
  for (const row of harness.elements.ticketEditorRows.querySelectorAll("[data-live-ticket-weighing]")) {
    assert.match(row.fields.weighed_at.value, /:00$/);
    row.fields.weighed_at.value = row.fields.weighed_at.value.slice(0, -3);
  }
  assert.equal(harness.api.ticketEditorHasUnsavedChanges(), false);
  harness.api.beginTicketWeighingVoid(502);
  assert.equal(harness.state.ticketVoidWeighingId, 502);
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, false);
  assert.equal(harness.elements.confirmTicketVoid.disabled, false);
});

test("cambiar un segundo de la fecha sí exige guardar la corrección antes de anular", () => {
  const harness = createHarness();
  const dateField = harness.row(501).fields.weighed_at;
  assert.match(dateField.value, /:00$/);
  dateField.value = `${dateField.value.slice(0, -2)}01`;
  assert.equal(harness.api.ticketEditorHasUnsavedChanges(), true);
  harness.api.beginTicketWeighingVoid(502);
  assert.equal(harness.state.ticketVoidWeighingId, null);
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, true);
  assert.equal(harness.calls.requests.length, 0);
});

test("anular exige resolver los cambios sin guardar y no pierde los valores escritos", () => {
  const harness = createHarness();
  harness.row(501).fields.read_weight_kg.value = "75.300";
  harness.api.beginTicketWeighingVoid(502);
  assert.equal(harness.state.ticketVoidWeighingId, null);
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, true);
  assert.equal(harness.row(501).fields.read_weight_kg.value, "75.300");
  assert.equal(harness.calls.requests.length, 0);
  assert.match(harness.elements.ticketEditorMessage.textContent, /guardar|guard[a-zá]+|correcci[oó]n/i);
});

test("un ticket de consulta no ofrece ni permite anular sus pesadas", async () => {
  const harness = createHarness(sampleTicket({ editable: false }));
  const buttons = harness.elements.ticketEditorRows.querySelectorAll("[data-live-void-ticket-weighing]");
  assert.ok(buttons.length === 0 || buttons.every((button) => button.disabled));
  harness.start();
  await harness.api.submitTicketWeighingVoid();
  assert.equal(harness.calls.requests.length, 0);
  assert.equal(harness.state.ticketVoidWeighingId, null);
});

test("sin permiso para la última pesada conserva el ticket registrado", async () => {
  const ticket = sampleTicket();
  const harness = createHarness(sampleTicket({ weighings: [ticket.weighings[0]], can_void_last_weighing: false }));
  harness.start(501);
  await harness.api.submitTicketWeighingVoid();
  assert.equal(harness.calls.requests.length, 0);
  assert.equal(harness.state.editingTicket.weighings.length, 1);
  assert.equal(harness.state.ticketVoidWeighingId, null);
});

test("cancelar la confirmación no modifica pesadas, conteos ni captura pendiente", () => {
  const harness = createHarness();
  const before = structuredClone(harness.state);
  harness.start();
  assert.equal(harness.state.ticketVoidWeighingId, 502);
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, false);
  harness.api.cancelTicketWeighingVoid();
  assert.equal(harness.state.ticketVoidWeighingId, null);
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, true);
  assert.deepEqual(harness.state.editingTicket, before.editingTicket);
  assert.deepEqual(harness.state.data, before.data);
  assert.deepEqual(harness.state.manualWeightOverride, before.manualWeightOverride);
  assert.deepEqual(harness.state.dispatchDrafts, before.dispatchDrafts);
  assert.equal(harness.calls.requests.length, 0);
});

test("el motivo debe tener entre 3 y 250 caracteres antes de llamar al servidor", async () => {
  for (const reason of ["", " x ", "a".repeat(251)]) {
    const harness = createHarness();
    harness.start(502, reason);
    await harness.api.submitTicketWeighingVoid();
    assert.equal(harness.calls.requests.length, 0, `Motivo inválido de longitud ${reason.length}`);
    assert.equal(harness.state.busy, false);
    assert.equal(harness.state.editingTicket.weighings.length, 2);
  }
});

test("DELETE incluye revisión del ticket y fecha de pesada sin mutar conteos hasta recibir confirmación", async () => {
  const harness = createHarness();
  const before = structuredClone(harness.state);
  let resolveRequest;
  harness.respondWith(() => new Promise((resolve) => { resolveRequest = resolve; }));
  harness.start(502, "  Pesada duplicada  ");
  const submitted = harness.api.submitTicketWeighingVoid();
  assert.equal(harness.state.busy, true);
  assert.equal(harness.elements.confirmTicketVoid.disabled, true);
  assert.deepEqual(harness.calls.requests, [{
    path: "/recepcion-pollo-vivo/tickets/90/pesadas/502", method: "DELETE",
    payload: { layout_version: 4, expected_revision: 7, expected_updated_at: "2026-08-31T14:01:00.000Z", reason: "Pesada duplicada" },
  }]);
  assert.deepEqual(harness.state.data, before.data);
  await harness.api.submitTicketWeighingVoid();
  assert.equal(harness.calls.requests.length, 1);
  const remainingTicket = sampleTicket({ link_revision: 8, weighings: [before.editingTicket.weighings[0]] });
  const overview = { records: [], totals: { daily: calculateDraftTotals(remainingTicket.weighings) } };
  resolveRequest({ data: overview, ticket: remainingTicket, voided_weighing_id: 502, ticket_voided: false,
    message: "Pesada anulada y totales corregidos." });
  await submitted;
  assert.equal(harness.state.busy, false);
  assert.equal(harness.state.editingTicket.link_revision, 8);
  assert.deepEqual(harness.state.editingTicket.weighings.map((weighing) => weighing.id), [501]);
  assert.equal(harness.state.data.totals.daily.weighings, 1);
  assert.equal(harness.state.data.totals.daily.birds, 14);
  assert.equal(harness.state.data.totals.daily.net_weight_kg, 46.4);
  assert.deepEqual(harness.state.manualWeightOverride, before.manualWeightOverride);
  assert.deepEqual(harness.state.dispatchDrafts, before.dispatchDrafts);
});

test("un conflicto conserva el ticket y el motivo, permite reintentar y no aplica totales incompletos", async () => {
  const harness = createHarness();
  const before = structuredClone(harness.state.editingTicket);
  harness.respondWith(async () => { throw Object.assign(new Error("Otra sesión modificó este ticket."), { status: 409 }); });
  harness.start();
  await harness.api.submitTicketWeighingVoid();
  assert.deepEqual(harness.state.editingTicket, before);
  assert.equal(harness.calls.rendered.length, 0);
  assert.equal(harness.state.busy, false);
  assert.equal(harness.elements.ticketVoidReason.value, "Pesada duplicada");
  assert.equal(harness.elements.ticketVoidConfirmation.hidden, false);
  assert.equal(harness.elements.confirmTicketVoid.disabled, false);
  assert.match(harness.elements.ticketEditorMessage.textContent, /Otra sesión/);
});

test("si se abrió desde totales, al confirmar se devuelve el resumen actualizado", async () => {
  const harness = createHarness();
  const returnState = { scope: "own", rowIndex: 1, scrollLeft: 230, scrollTop: 440, focusKey: "ticket-weighing:502" };
  harness.state.summaryEditorReturn = returnState;
  const remainingTicket = sampleTicket({ link_revision: 8, weighings: [harness.state.editingTicket.weighings[0]] });
  harness.respondWith({ data: { totals: { daily: calculateDraftTotals(remainingTicket.weighings) } },
    ticket: remainingTicket, voided_weighing_id: 502, message: "Pesada anulada." });
  harness.start();
  await harness.api.submitTicketWeighingVoid();
  assert.equal(harness.calls.rendered.length, 1);
  assert.deepEqual(harness.calls.finished, [["Pesada anulada.", "success"]]);
  assert.equal(harness.state.summaryEditorReturn, returnState);
});

test("anular la última pesada autorizada deja el ticket vacío sin habilitar guardar ni imprimir", async () => {
  const first = sampleTicket().weighings[0];
  const harness = createHarness(sampleTicket({ weighings: [first] }));
  const empty = sampleTicket({ editable: false, weighings: [], link_revision: 8, status: "ANULADO" });
  harness.respondWith({ data: { totals: { daily: calculateDraftTotals([]) } }, ticket: empty,
    voided_weighing_id: 501, ticket_voided: true, message: "Ticket anulado." });
  harness.start(501);
  await harness.api.submitTicketWeighingVoid();
  assert.equal(harness.state.data.totals.daily.weighings, 0);
  assert.equal(harness.state.data.totals.daily.birds, 0);
  assert.equal(harness.elements.saveTicket.disabled, true);
  assert.equal(harness.elements.printTicket.disabled, true);
});

test("cada fila del resumen ofrece anular la pesada del ticket sin cambiar el ID por su grupo de sexo", () => {
  const dependencies = {
    normalizeReceptionSex, escapeHtml: String, formatKg: String, formatDateTime: String,
  };
  const render = new Function(...Object.keys(dependencies), `
    ${functionSource("summaryWeightSource")}
    ${functionSource("renderSummaryRow")}
    return renderSummaryRow;
  `)(...Object.values(dependencies));
  for (const weighing of sampleTicket().weighings) {
    const markup = render({ ...weighing, record_kind: "dispatch_ticket_weighing", ticket_id: 90,
      ticket_code: "T-090", owner: { type: "PROPIA" }, editable_mode: "ticket" });
    assert.match(markup, new RegExp(`data-live-summary-weighing-id="${weighing.id}"`));
    assert.match(markup, /data-live-summary-ticket-id="90"/);
    assert.match(markup, /<button[^>]*data-live-summary-void-weighing[^>]*aria-controls="liveIntakeTicketEditorModal"/);
    assert.equal((markup.match(/<td\b/g) || []).length, 13);
  }
  assert.doesNotMatch(render({ ...sampleTicket().weighings[0], record_kind: "legacy_direct_weighing",
    editable_mode: "readonly" }), /data-live-summary-void-weighing/);
});

test("el acceso Anular del resumen abre el ticket completo e identifica únicamente la pesada seleccionada", () => {
  const opened = [];
  const state = { busy: false, data: { records: [] } };
  const trigger = node();
  const dependencies = {
    state, isDispatchTicketRecord, setSummaryMessage() {},
    suspendSummaryDetailForEditor: () => trigger,
    openTicketEditor: (...args) => opened.push(args),
    openWeighingEditor() { assert.fail("Una pesada de ticket no debe abrir el editor de pesadas sueltas."); },
  };
  const open = new Function(...Object.keys(dependencies), `${functionSource("openSummaryRow")}\nreturn openSummaryRow;`)
    (...Object.values(dependencies));
  const row = node({ dataset: {
    liveSummaryRecordKind: "dispatch_ticket_weighing", liveSummaryWeighingId: "502",
    liveSummaryTicketId: "90", liveSummaryRecordId: "501",
  } });
  open(row, { voidWeighing: true });
  assert.deepEqual(opened, [[90, trigger, { focusWeighingId: 502, voidWeighingId: 502 }]]);
  open(row);
  assert.deepEqual(opened[1], [90, trigger, { focusWeighingId: 502 }]);
  state.busy = true;
  open(row, { voidWeighing: true });
  assert.equal(opened.length, 2);
});

test("el clic en Anular del resumen se procesa una vez y no dispara la apertura general de la fila", () => {
  const callbacks = new Map();
  const opened = [];
  const start = source.indexOf('document.addEventListener("click", (event) => {');
  const end = source.indexOf('\ndocument.addEventListener("keydown"', start);
  assert.ok(start >= 0 && end > start);
  new Function("document", "openSummaryRow", source.slice(start, end))({
    addEventListener: (event, callback) => callbacks.set(event, callback),
  }, (...args) => opened.push(args));
  const row = node();
  const button = node({ closest: () => row });
  let prevented = 0;
  callbacks.get("click")({
    target: { closest: (selector) => selector === "[data-live-summary-void-weighing]" ? button : row },
    preventDefault() { prevented += 1; },
  });
  assert.deepEqual(opened, [[row, { voidWeighing: true }]]);
  assert.equal(prevented, 1);
});

test("después de anular desde totales, restaura el grupo y desplazamiento y enfoca la fila vecina", () => {
  for (const remainingIds of [[501, 503], []]) {
    const topTrigger = node();
    const rowTrigger = node();
    const state = {
      summaryEditorReturn: { scope: "own", topTrigger, focusKey: "ticket-weighing:502", rowIndex: 1,
        scrollLeft: 230, scrollTop: 440, message: "", tone: "" },
      editingTicket: sampleTicket(), ticketEditorTrigger: rowTrigger, ticketEditorRequestId: 2,
      ticketEditorFocusWeighingId: 502, ticketVoidWeighingId: 502,
    };
    const buttons = remainingIds.map((id) => node({
      closest: () => ({ dataset: { liveSummaryFocusKey: `ticket-weighing:${id}` } }),
    }));
    const elements = {
      capture: node(), ticketEditorModal: node(), ticketVoidConfirmation: node(), ticketVoidReason: node(),
      summaryModal: node({ hidden: true }),
      summaryRows: node({ scrollLeft: 0, scrollTop: 0, querySelectorAll: () => buttons }),
    };
    const renderedScopes = [];
    const messages = [];
    const dependencies = {
      state, elements,
      globalThis: { requestAnimationFrame: (callback) => callback(), setTimeout: (callback) => callback() },
      syncModalEnvironment() {}, setMessage() {},
      setSummaryMessage: (...args) => messages.push(args),
      renderSummaryDetail: (scope) => renderedScopes.push(scope),
    };
    const finish = new Function(...Object.keys(dependencies), `
      ${functionSource("restoreSummaryDetailAfterEditor")}
      ${functionSource("finishTicketEditor")}
      return finishTicketEditor;
    `)(...Object.values(dependencies));
    finish("Pesada anulada.", "success");
    assert.deepEqual(renderedScopes, ["own"]);
    assert.deepEqual(messages, [["Pesada anulada.", "success"]]);
    assert.equal(state.editingTicket, null);
    assert.equal(state.summaryEditorReturn, null);
    assert.equal(elements.summaryModal.hidden, false);
    assert.equal(elements.ticketEditorModal.hidden, true);
    assert.equal(elements.summaryRows.scrollLeft, 230);
    assert.equal(elements.summaryRows.scrollTop, 440);
    assert.equal(topTrigger.getAttribute("aria-expanded"), "true");
    assert.equal((buttons[1] || elements.summaryRows).focused, true);
  }
});
