import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  buildProductDispatchAdjustmentPayload,
  buildProductDispatchPaymentPayload,
  buildProductDispatchPaymentsPath,
  mountProductDispatchPayments,
  productDispatchBalancePreview,
} from "../../public/js/despacho-productos-pagos.js";

test("un pago o crédito reduce la deuda y conserva el excedente a favor", () => {
  for (const kind of ["PAYMENT", "CREDIT"]) {
    assert.equal(productDispatchBalancePreview("100.00", "35.25", kind), "64.75");
    assert.equal(productDispatchBalancePreview("100.00", "100", kind), "0.00");
    assert.equal(productDispatchBalancePreview("100.00", "125.35", kind), "-25.35");
    assert.equal(productDispatchBalancePreview("-25.35", "10.15", kind), "-35.50");
    assert.equal(productDispatchBalancePreview("0.30", "0.10", kind), "0.20");
  }
});

test("la deuda anterior consume el saldo a favor antes de generar deuda", () => {
  assert.equal(productDispatchBalancePreview("100.20", "20.35", "PRIOR_DEBT"), "120.55");
  assert.equal(productDispatchBalancePreview("-100", "35.25", "PRIOR_DEBT"), "-64.75");
  assert.equal(productDispatchBalancePreview("-100", "120.25", "PRIOR_DEBT"), "20.25");
});

test("al editar se revierte el movimiento original antes de aplicar el nuevo importe", () => {
  assert.equal(productDispatchBalancePreview("70.00", "50.00", "PAYMENT", { sale: "0.00", payment: "30.00" }), "50.00");
  assert.equal(productDispatchBalancePreview("-20.00", "10.00", "CREDIT", { sale: "0.00", payment: "40.00" }), "10.00");
  assert.equal(productDispatchBalancePreview("150.00", "20.00", "PRIOR_DEBT", { sale: "50.00", payment: "0.00" }), "120.00");
});

test("la vista previa descarta importes vacíos, negativos, no finitos o fracciones menores al centavo", () => {
  for (const invalid of ["", " ", "0", "0.00", "-1", "1.001", "1,25", "1e2", "Infinity", NaN, null, "1000000000000"]) {
    assert.equal(productDispatchBalancePreview("100", invalid, "PAYMENT"), null, String(invalid));
  }
  assert.equal(productDispatchBalancePreview("bad", "1", "PAYMENT"), null);
  assert.equal(productDispatchBalancePreview("100", "1", "SALE"), null);
});

test("los ajustes envían solo sus datos contables, sin método ni cuenta de efectivo", () => {
  const values = {
    cliente_id: "7", tipo: "CREDIT", importe: " 25.50 ", moneda: " pen ",
    fecha_hora: "2026-09-05T14:15", metodo_pago_id: "8", cuenta_destino_id: "13",
    referencia: "  NC-01 ", observaciones: "  Saldo reconocido ",
  };
  assert.deepEqual(buildProductDispatchAdjustmentPayload(values), {
    cliente_id: 7, tipo: "CREDIT", importe: "25.50", moneda: "PEN",
    fecha_hora: "2026-09-05T14:15", observaciones: "Saldo reconocido",
  });
  const payment = buildProductDispatchPaymentPayload(values);
  assert.equal(payment.metodo_pago_id, 8);
  assert.equal(payment.cuenta_destino_id, 13);
  assert.equal(Object.hasOwn(payment, "tipo"), false);
});

test("la consulta mantiene cliente, moneda, periodo y búsqueda codificados", () => {
  const path = buildProductDispatchPaymentsPath("/despacho-productos/pagos/cuenta", {
    cliente_id: 7, moneda: "PEN", buscar: " NC & 01 ", date_from: "2026-09-01", date_to: "",
  }, 2);
  const query = new URL(path, "https://avicola.test").searchParams;
  assert.deepEqual(Object.fromEntries(query), {
    page: "2", cliente_id: "7", moneda: "PEN", buscar: "NC & 01", date_from: "2026-09-01",
  });
});

// Minimal DOM fixture: exercises the controller's state transitions against the
// actual Blade IDs without requiring a browser or installing DOM dependencies.
class Element {
  constructor(tag = "div") {
    this.tagName = tag.toUpperCase();
    this.children = [];
    this.dataset = {};
    this.attributes = new Map();
    this.listeners = new Map();
    this.value = "";
    this.defaultValue = "";
    this.disabled = false;
    this.hidden = false;
    this.text = "";
    this.classNames = new Set();
    this.classList = {
      toggle: (name, enabled) => enabled ? this.classNames.add(name) : this.classNames.delete(name),
      contains: (name) => this.classNames.has(name),
    };
  }
  set textContent(value) { this.text = String(value); this.children = []; }
  get textContent() { return this.text + this.children.map((child) => child.textContent).join(""); }
  get options() { return this.children.filter((child) => child.tagName === "OPTION"); }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  removeAttribute(name) { this.attributes.delete(name); }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) {
    this.children = [...children];
    this.text = "";
    if (this.tagName === "SELECT") this.value = children[0]?.value || "";
  }
  addEventListener(name, callback) {
    const callbacks = this.listeners.get(name) || [];
    callbacks.push(callback);
    this.listeners.set(name, callbacks);
  }
  async dispatch(name, details = {}) {
    const event = { target: this, preventDefault() {}, ...details };
    for (const callback of this.listeners.get(name) || []) await callback(event);
  }
  querySelectorAll(selector) {
    const tags = selector.split(",").map((tag) => tag.trim().toUpperCase());
    return this.children.flatMap((child) => [...(tags.includes(child.tagName) ? [child] : []), ...child.querySelectorAll(selector)]);
  }
  querySelector(selector) {
    if (selector === '[aria-invalid="true"]') return this.children.find((child) => child.attributes.get("aria-invalid") === "true") || null;
    return this.querySelectorAll(selector)[0] || null;
  }
  closest(selector) {
    if (selector === "label") return this.label ||= new Element("label");
    if (this.tagName === "BUTTON" && selector.startsWith("button")) return this;
    return null;
  }
  reset() { for (const field of this.formFields || []) field.value = field.defaultValue; }
  reportValidity() { return true; }
  focus() {}
  scrollIntoView() {}
  showModal() { this.setAttribute("open", ""); }
  close() { this.removeAttribute("open"); }
}

const blade = await readFile(new URL("../../resources/views/despacho-productos-pagos.blade.php", import.meta.url), "utf8");

function fixture(search = "") {
  const ui = {};
  for (const match of blade.matchAll(/<(\w+)\b[^>]*\bid="pdpy([^"]+)"[^>]*>/g)) {
    assert.equal(ui[match[2]], undefined, `Duplicate Blade ID: pdpy${match[2]}`);
    ui[match[2]] = new Element(match[1]);
  }
  ui.MovementType.value = ui.MovementType.defaultValue = "PAYMENT";
  ui.Currency.value = ui.Currency.defaultValue = "PEN";
  ui.Form.formFields = ["Client", "MovementType", "Amount", "Currency", "Method", "DateTime", "Reference", "Account", "Notes"].map((id) => ui[id]);
  ui.Form.children = ui.Form.formFields;
  ui.Filters.formFields = [ui.Search, ui.DateFrom, ui.DateTo];
  ui.Filters.children = [...ui.Filters.formFields, new Element("button")];
  const location = { pathname: "/despacho-productos/pagos", search, origin: "https://avicola.test" };
  const win = {
    location,
    history: { replaceState(_state, _unused, value) { location.search = new URL(value, location.origin).search; } },
    addEventListener() {},
    confirm() { return true; },
  };
  const doc = {
    defaultView: win,
    getElementById(id) { assert.ok(ui[id.slice(4)], `Missing Blade element ${id}`); return ui[id.slice(4)]; },
    createElement(tag) { return new Element(tag); },
  };
  const refreshedAmounts = [];
  doc[Symbol.for("avicola.productDispatchKeyboards")] = {
    numeric: { refreshLabel(input) { refreshedAmounts.push(input.value); } },
  };
  const root = { ownerDocument: doc, dataset: { apiBase: "/despacho-productos/pagos" } };
  return { ui, root, win, refreshedAmounts };
}

function catalog() {
  return { data: {
    methods: [{ id: 3, name: "Efectivo" }], accounts: [], currencies: ["PEN", "USD"], currency: "PEN",
    now: "2026-09-05T14:15", branch: { name: "Principal" },
    clients: [{ id: 7, name: "Águila", document_type: "DNI", document: "12345678" }, { id: 9, name: "Norte", document_type: "RUC", document: "20987654321" }],
  } };
}

function account(balance = "100.00", data = []) {
  return { data, summary: { balance, charges_total: "100.00", payments_total: "0.00", currency: "PEN" }, meta: { total: data.length, current_page: 1, last_page: 1 } };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

test("seleccionar cliente carga automáticamente saldo e historial y recupera el cliente de la URL", async () => {
  const { root, ui } = fixture("?cliente_id=7&moneda=PEN");
  const calls = [];
  const controller = mountProductDispatchPayments(root, async (path) => {
    calls.push(path);
    return path.endsWith("/catalogo") ? catalog() : account();
  });
  await controller.ready;
  assert.equal(ui.Client.value, "7");
  assert.equal(ui.ClientButtonTitle.textContent, "Águila");
  assert.equal(ui.BalanceLabel.textContent, "Deuda actual");
  assert.equal(ui.Save.disabled, false);
  assert.equal(calls.length, 2);
  assert.match(calls[1], /\/cuenta\?page=1&cliente_id=7&moneda=PEN$/);

  await controller.selectClient(9);
  assert.equal(ui.Client.value, "9");
  assert.match(calls[2], /cliente_id=9/);
  assert.equal(ui.ClientButtonTitle.textContent, "Norte");
});

test("guardar un saldo a favor conserva cliente y moneda y actualiza la deuda", async () => {
  const { root, ui, refreshedAmounts } = fixture();
  const mutations = [];
  const controller = mountProductDispatchPayments(root, async (path, options) => {
    if (path.endsWith("/catalogo")) return catalog();
    if (options?.method === "POST") { mutations.push({ path, payload: JSON.parse(options.body) }); return { message: "Guardado" }; }
    return account(mutations.length ? "-25.00" : "100.00");
  });
  await controller.ready;
  await controller.selectClient(7);
  ui.MovementType.value = "CREDIT";
  await ui.MovementType.dispatch("change");
  ui.Amount.value = "125.00";
  await ui.Amount.dispatch("input");
  assert.equal(ui.MethodField.hidden, true);
  assert.equal(ui.Method.required, false);
  assert.match(ui.BalancePreview.textContent, /Saldo a favor/);
  assert.equal(refreshedAmounts.at(-1), "125.00", "La etiqueta accesible debe reflejar el importe ingresado");
  await ui.Form.dispatch("submit");
  assert.equal(mutations.length, 1);
  assert.equal(mutations[0].path, "/despacho-productos/pagos/ajustes");
  assert.equal(mutations[0].payload.tipo, "CREDIT");
  assert.equal(mutations[0].payload.cliente_id, 7);
  assert.equal(Object.hasOwn(mutations[0].payload, "metodo_pago_id"), false);
  assert.equal(Object.hasOwn(mutations[0].payload, "cuenta_destino_id"), false);
  assert.match(mutations[0].payload.idempotency_key, /^[0-9a-f-]{36}$/);
  assert.equal(ui.Client.value, "7");
  assert.equal(ui.Currency.value, "PEN");
  assert.equal(ui.MovementType.value, "PAYMENT");
  assert.equal(ui.BalanceLabel.textContent, "Saldo a favor");
  assert.equal(ui.BalancePanel.classList.contains("is-credit"), true);
  assert.equal(ui.Save.disabled, false);
  assert.equal(refreshedAmounts.at(-1), "", "Al guardar debe eliminarse el importe anterior de la etiqueta del teclado");
});

test("una respuesta tardía del cliente anterior nunca reemplaza el saldo del seleccionado", async () => {
  const { root, ui } = fixture();
  const first = deferred();
  const second = deferred();
  const controller = mountProductDispatchPayments(root, async (path) => {
    if (path.endsWith("/catalogo")) return catalog();
    return path.includes("cliente_id=7") ? first.promise : second.promise;
  });
  await controller.ready;
  const loadFirst = controller.selectClient(7);
  const loadSecond = controller.selectClient(9);
  second.resolve(account("-18.20"));
  await loadSecond;
  const selectedBalance = ui.BalanceAmount.textContent;
  first.resolve(account("9000.00"));
  await loadFirst;
  assert.equal(ui.Client.value, "9");
  assert.equal(ui.BalanceAmount.textContent, selectedBalance);
  assert.equal(ui.BalanceLabel.textContent, "Saldo a favor");
  assert.equal(ui.Save.disabled, false);
});

test("editar un pago revierte su abono anterior y actualiza el registro sin crear otro", async () => {
  const { root, ui, refreshedAmounts } = fixture();
  const movement = {
    id: 21, kind: "PAYMENT", code: "PG-00021", date_time: "2026-09-04 13:25:00",
    amount: "30.00", sale: "0.00", payment: "30.00", balance: "70.00", currency: "PEN",
    payment_method: { id: 3, name: "Efectivo" }, can_edit: true, can_delete: true,
  };
  const mutations = [];
  const controller = mountProductDispatchPayments(root, async (path, options) => {
    if (path.endsWith("/catalogo")) return catalog();
    if (options) { mutations.push({ path, options, payload: JSON.parse(options.body) }); return { message: "Actualizado" }; }
    return account(mutations.length ? "50.00" : "70.00", [movement]);
  });
  await controller.ready;
  await controller.selectClient(7);
  const edit = ui.Rows.querySelectorAll("button").find((button) => button.dataset.action === "edit");
  assert.ok(edit, "El pago debe ofrecer su acción de edición");
  await ui.Rows.dispatch("click", { target: edit });
  assert.equal(ui.Amount.value, "30.00");
  assert.equal(refreshedAmounts.at(-1), "30.00", "Al editar, el teclado debe anunciar el importe del movimiento cargado");
  assert.equal(ui.Method.value, "3");
  assert.equal(ui.DateTime.value, "2026-09-04T13:25");
  assert.equal(ui.MovementType.disabled, true);
  ui.Amount.value = "50.00";
  await ui.Amount.dispatch("input");
  assert.match(ui.BalancePreview.textContent, /50[.,]00/);
  await ui.Form.dispatch("submit");
  assert.equal(mutations.length, 1);
  assert.equal(mutations[0].path, "/despacho-productos/pagos/21");
  assert.equal(mutations[0].options.method, "PUT");
  assert.equal(mutations[0].payload.importe, "50.00");
  assert.equal(mutations[0].payload.metodo_pago_id, 3);
  assert.equal(Object.hasOwn(mutations[0].payload, "idempotency_key"), false);
  assert.equal(ui.Client.value, "7");
  assert.equal(ui.MovementType.disabled, false);
});

test("si falla la carga se bloquea guardar hasta recuperar el saldo", async () => {
  const { root, ui } = fixture();
  let fail = true;
  const controller = mountProductDispatchPayments(root, async (path) => {
    if (path.endsWith("/catalogo")) return catalog();
    if (fail) throw new Error("Sin conexión");
    return account("0.00");
  });
  await controller.ready;
  await controller.selectClient(7);
  assert.equal(ui.Save.disabled, true);
  assert.match(ui.ListMessage.textContent, /Sin conexión/);
  fail = false;
  await controller.refresh();
  assert.equal(ui.BalanceLabel.textContent, "Cliente al día");
  assert.equal(ui.Save.disabled, false);
});

test("deudas históricas y ajustes con el mismo ID conservan su identidad y URL al editar", async () => {
  const common = {
    id: 21, kind: "PRIOR_DEBT", date_time: "2026-09-04T13:25", payment: "0.00",
    balance: "150.00", currency: "PEN", can_edit: true, can_delete: true,
  };
  const adjustment = {
    ...common, key: "PRIOR_DEBT:adjustment-21", code: "DA-AJUSTE", amount: "30.00", sale: "30.00",
    edit_url: "/despacho-productos/pagos/ajustes/21",
  };
  const legacyDebt = {
    ...common, key: "PRIOR_DEBT:document-21", code: "DA-HISTORICA", amount: "120.00", sale: "120.00",
    edit_url: "/despacho-productos/pagos/deudas/21",
  };
  for (const movements of [[adjustment, legacyDebt], [legacyDebt, adjustment]]) {
    const { root, ui } = fixture();
    const mutations = [];
    const controller = mountProductDispatchPayments(root, async (path, options) => {
      if (path.endsWith("/catalogo")) return catalog();
      if (options) { mutations.push({ path, method: options.method, payload: JSON.parse(options.body) }); return { message: "Actualizado" }; }
      return account("150.00", movements);
    });
    await controller.ready;
    await controller.selectClient(7);
    const editButtons = ui.Rows.querySelectorAll("button").filter((button) => button.dataset.action === "edit");
    assert.deepEqual(editButtons.map((button) => button.dataset.key), movements.map((movement) => movement.key));
    const selected = movements[1];
    await ui.Rows.dispatch("click", { target: editButtons[1] });
    assert.equal(ui.Amount.value, selected.amount, `${selected.key} debe cargar su propio importe`);
    assert.match(ui.FormMessage.textContent, new RegExp(selected.code));
    ui.Amount.value = "45.25";
    await ui.Form.dispatch("submit");
    assert.equal(mutations.length, 1);
    assert.equal(mutations[0].path, selected.edit_url, `${selected.key} debe conservar su ruta de origen`);
    assert.equal(mutations[0].method, "PUT");
    assert.equal(mutations[0].payload.importe, "45.25");
    assert.equal(mutations[0].payload.tipo, "PRIOR_DEBT");
  }
});

test("reintentar un pago tras una respuesta incierta reutiliza la misma clave para evitar duplicados", async () => {
  const { root, ui } = fixture();
  const requests = [];
  const controller = mountProductDispatchPayments(root, async (path, options) => {
    if (path.endsWith("/catalogo")) return catalog();
    if (options?.method === "POST") {
      requests.push({ path, payload: JSON.parse(options.body) });
      if (requests.length === 1) throw new Error("La respuesta del servidor se interrumpió");
      return { message: "Pago recuperado" };
    }
    return account("100.00");
  });
  await controller.ready;
  await controller.selectClient(7);
  ui.Method.value = "3";
  ui.Amount.value = "20.00";
  await ui.Form.dispatch("submit");
  assert.equal(requests.length, 1);
  assert.equal(ui.Amount.value, "20.00");
  assert.equal(ui.Save.disabled, false);
  assert.match(ui.FormMessage.textContent, /interrumpió/);
  await ui.Form.dispatch("submit");
  assert.equal(requests.length, 2);
  assert.deepEqual(requests[1], requests[0]);
  assert.match(requests[0].payload.idempotency_key, /^[0-9a-f-]{36}$/);
  assert.equal(ui.Client.value, "7");
});
