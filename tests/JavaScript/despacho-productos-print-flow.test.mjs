import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";

const dispatchSource = readFileSync(new URL("../../public/js/despacho-productos-despacho.js", import.meta.url), "utf8");
const printSetting = dispatchSource.match(/^const PRODUCT_DISPATCH_PRINT_COPIES = .*;$/m)?.[0];
const start = dispatchSource.indexOf("function showTicketToast(");
const end = dispatchSource.indexOf("function serialOptions()", start);
assert.ok(printSetting && start >= 0 && end > start);

function dispatchHarness() {
  const ticket = { code: "PD-DOS-COPIAS", totals: { amount: 385 } };
  const requests = [];
  const printJobs = [];
  const state = {
    activeIndex: 0,
    drafts: [{ id: "draft-1", items: [{ product_id: 1, amount: 385 }] }],
    catalog: { currency: "S/", branch: { timezone: "America/Lima" } },
    pendingPrintTicket: null,
    saving: false
  };
  const elements = { lastTicket: {}, lastTicketTitle: {}, lastTicketDetail: {}, retryPrint: {} };
  const context = vm.createContext({
    state,
    elements,
    apiBase: "/despacho-productos",
    activeDraft: () => state.drafts[state.activeIndex],
    buildTicketPayload: (draft) => ({ items: draft.items }),
    apiRequest: async (url, options) => {
      requests.push({ url, ...options });
      return { data: { ticket } };
    },
    createEmptyDraft: () => ({ id: "draft-2", items: [] }),
    persistDrafts() {},
    renderLists() {},
    renderActiveSummary() {},
    setMessage() {},
    errorMessage: (error) => error.message,
    printProductDispatchTicket: (data, options) => printJobs.push({ ticket: data, options })
  });
  vm.runInContext(`${printSetting}\n${dispatchSource.slice(start, end)}`, context);
  return { context, state, elements, ticket, requests, printJobs };
}

test("Guardar e imprimir registra una sola venta y solicita dos trabajos del mismo ticket", async () => {
  const h = dispatchHarness();
  await h.context.saveActiveDraft(true);

  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].method, "POST");
  assert.equal(h.requests[0].url, "/despacho-productos/tickets");
  assert.equal(h.printJobs.length, 1);
  assert.equal(h.printJobs[0].ticket, h.ticket);
  assert.equal(h.printJobs[0].options.copies, 2);
  assert.equal(h.state.drafts[0].items.length, 0);
  assert.equal(h.state.saving, false);

  h.printJobs[0].options.onSuccess();
  assert.match(h.elements.lastTicketTitle.textContent, /2 copias/);
  assert.equal(h.state.pendingPrintTicket, null);
});

test("Guardar sin imprimir registra la venta sin solicitar ningún trabajo", async () => {
  const h = dispatchHarness();
  await h.context.saveActiveDraft(false);

  assert.equal(h.requests.length, 1);
  assert.equal(h.printJobs.length, 0);
  assert.equal(h.elements.lastTicketTitle.textContent, "Ticket guardado sin imprimir");
});

test("el reintento tras fallar la segunda copia solo envía la pendiente y no vuelve a guardar", async () => {
  const h = dispatchHarness();
  await h.context.saveActiveDraft(true);
  h.printJobs[0].options.onError(new Error("Falló el segundo trabajo"), { remainingCopies: 1 });
  assert.equal(h.state.pendingPrintTicket.copies, 1);
  assert.equal(h.elements.retryPrint.hidden, false);

  h.context.retryPrint();
  h.context.retryPrint();
  assert.equal(h.requests.length, 1);
  assert.equal(h.printJobs.length, 2, "Un doble clic no debe duplicar el reintento.");
  assert.equal(h.printJobs[1].ticket, h.ticket);
  assert.equal(h.printJobs[1].options.copies, 1);
  assert.equal(h.elements.retryPrint.hidden, true);

  h.printJobs[1].options.onError(new Error("Sigue pendiente"), { remainingCopies: 1 });
  h.context.retryPrint();
  assert.equal(h.printJobs[2].options.copies, 1);
  h.printJobs[2].options.onSuccess();
  assert.equal(h.state.pendingPrintTicket, null);
  assert.equal(h.requests.length, 1);
});

test("un fallo antes de enviar la primera copia conserva ambos trabajos para reintentar", async () => {
  const h = dispatchHarness();
  await h.context.saveActiveDraft(true);
  h.printJobs[0].options.onError(new Error("No se pudo preparar"), { remainingCopies: 2 });
  h.context.retryPrint();

  assert.equal(h.printJobs[1].options.copies, 2);
  assert.equal(h.requests.length, 1);
});
