import assert from "node:assert/strict";
import test from "node:test";

import { printProductDispatchTicket } from "../../public/js/despacho-productos-ticket-printer.js";

const ticket = {
  code: "PD-DOS-TRABAJOS",
  registered_at: "2026-09-05T14:11:38-05:00",
  customer_label: "Venta al público",
  list_number: 1,
  weighings: [{
    product_id: 1,
    product_name: "Pollo",
    quantity: 2,
    price_mode: "POR_KG",
    unit_price: 5.5,
    net_weight_kg: 70,
    amount: 385
  }],
  totals: { amount: 385 }
};

function eventTarget() {
  const listeners = new Map();
  return {
    addEventListener(type, callback, options = {}) {
      const entries = listeners.get(type) || [];
      entries.push({ callback, once: options.once === true });
      listeners.set(type, entries);
    },
    removeEventListener(type, callback) {
      listeners.set(type, (listeners.get(type) || []).filter((entry) => entry.callback !== callback));
    },
    emit(type) {
      for (const entry of [...(listeners.get(type) || [])]) {
        if (entry.once) this.removeEventListener(type, entry.callback);
        entry.callback();
      }
    }
  };
}

function mockPrinting(t, { printBehavior, missingWindowAt } = {}) {
  const previousDocument = globalThis.document;
  const previousWindow = globalThis.window;
  const timers = new Map();
  const frames = [];
  const jobs = [];
  const successes = [];
  const errors = [];
  let nextTimerId = 1;
  let clock = 0;

  globalThis.window = {
    setTimeout(callback, delay = 0) {
      const id = nextTimerId++;
      timers.set(id, { callback, due: clock + delay, delay });
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    }
  };
  globalThis.document = {
    createElement(tagName) {
      assert.equal(tagName, "iframe");
      const index = frames.length;
      const frame = Object.assign(eventTarget(), {
        className: "",
        title: "",
        srcdoc: "",
        attributes: {},
        attached: false,
        removals: 0,
        setAttribute(name, value) { this.attributes[name] = value; },
        remove() {
          this.removals += 1;
          this.attached = false;
        }
      });
      frame.contentWindow = index === missingWindowAt ? null : Object.assign(eventTarget(), {
        focuses: 0,
        printCalls: 0,
        focus() { this.focuses += 1; },
        print() {
          this.printCalls += 1;
          jobs.push(frame);
          printBehavior?.(frame, index);
        }
      });
      frames.push(frame);
      return frame;
    },
    body: {
      appendChild(frame) {
        frame.attached = true;
        return frame;
      }
    }
  };
  t.after(() => {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
    if (previousWindow === undefined) delete globalThis.window;
    else globalThis.window = previousWindow;
  });

  function runTimer(predicate) {
    const next = [...timers.entries()]
      .filter(([, timer]) => predicate(timer))
      .sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
    if (!next) return false;
    const [id, timer] = next;
    timers.delete(id);
    clock = Math.max(clock, timer.due);
    timer.callback();
    return true;
  }

  function flushShortTimers() {
    let count = 0;
    while (runTimer((timer) => timer.delay < 1000)) {
      assert.ok(++count < 25, "La secuencia no debe crear un bucle de timers.");
    }
  }

  return {
    frames, jobs, successes, errors, timers,
    start(options = {}) {
      return printProductDispatchTicket(ticket, {
        onSuccess(...args) { successes.push(args); },
        onError(error, context) { errors.push({ error, context }); },
        ...options
      });
    },
    load(index) {
      assert.ok(frames[index], `Debe existir el marco ${index + 1}.`);
      frames[index].emit("load");
    },
    afterprint(index) { frames[index].contentWindow.emit("afterprint"); },
    flushShortTimers,
    expireTimeout() {
      assert.equal(runTimer((timer) => timer.delay >= 1000), true, "Debe existir un tiempo límite de impresión.");
    }
  };
}

function assertClean(mock) {
  assert.ok(mock.frames.every((frame) => !frame.attached), "Los marcos usados deben retirarse.");
  assert.ok(mock.frames.every((frame) => frame.removals === 1), "Cada marco se limpia una sola vez.");
  assert.equal(mock.timers.size, 0, "No deben quedar timers de impresión pendientes.");
}

test("dos copias son dos trabajos con un ticket cada uno y esperan sus respectivos afterprint", (t) => {
  const mock = mockPrinting(t);
  assert.equal(mock.start({ copies: 2 }), true);
  assert.equal(mock.frames.length, 1);
  mock.load(0);
  assert.equal(mock.jobs.length, 0, "La carga debe diferir la impresión hasta un timer.");
  mock.flushShortTimers();
  assert.deepEqual(mock.jobs, [mock.frames[0]]);
  assert.equal(mock.successes.length, 0);
  assert.equal(mock.frames.length, 1, "No debe prepararse otra impresión antes de afterprint.");

  mock.afterprint(0);
  assert.equal(mock.jobs.length, 1, "afterprint no debe ejecutar la segunda impresión dentro del evento.");
  assert.equal(mock.successes.length, 0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 2);
  assert.notEqual(mock.frames[0], mock.frames[1]);
  assert.equal(mock.frames[0].srcdoc, mock.frames[1].srcdoc);
  for (const frame of mock.frames) {
    assert.equal((frame.srcdoc.match(/<!doctype html>/gi) || []).length, 1);
    assert.equal((frame.srcdoc.match(/<body>/g) || []).length, 1);
    assert.equal(frame.className, "ticket-print-frame");
    assert.equal(frame.attributes["aria-hidden"], "true");
  }

  mock.load(1);
  mock.flushShortTimers();
  assert.deepEqual(mock.jobs, [mock.frames[0], mock.frames[1]]);
  assert.equal(mock.successes.length, 0);
  mock.afterprint(1);
  mock.flushShortTimers();
  assert.equal(mock.successes.length, 1);
  assert.equal(mock.errors.length, 0);
  assert.ok(mock.frames.every((frame) => frame.contentWindow.printCalls === 1));
  assertClean(mock);
});

test("afterprint síncrono durante print no adelanta ni duplica la siguiente copia", (t) => {
  let mock;
  mock = mockPrinting(t, {
    printBehavior(frame, index) {
      frame.contentWindow.emit("afterprint");
      frame.contentWindow.emit("afterprint");
      if (index === 0) {
        assert.equal(mock.jobs.length, 1);
        assert.equal(mock.successes.length, 0);
      }
    }
  });
  mock.start({ copies: 2 });
  mock.load(0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 2);
  assert.equal(mock.jobs.length, 1);
  mock.load(1);
  mock.flushShortTimers();
  assert.equal(mock.jobs.length, 2);
  assert.equal(mock.successes.length, 1);
  assert.equal(mock.errors.length, 0);
  assertClean(mock);
});

test("load y afterprint repetidos no generan trabajos ni avisos adicionales", (t) => {
  const mock = mockPrinting(t);
  mock.start({ copies: 2 });
  mock.load(0);
  mock.load(0);
  mock.flushShortTimers();
  assert.equal(mock.jobs.length, 1);
  mock.afterprint(0);
  mock.afterprint(0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 2);
  mock.load(0);
  mock.load(1);
  mock.load(1);
  mock.flushShortTimers();
  assert.equal(mock.jobs.length, 2);
  mock.afterprint(0);
  mock.afterprint(1);
  mock.afterprint(1);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 2);
  assert.equal(mock.successes.length, 1);
  assert.equal(mock.errors.length, 0);
  assertClean(mock);
});

test("sin copies conserva un solo trabajo y anuncia éxito después de afterprint", (t) => {
  const mock = mockPrinting(t);
  mock.start();
  mock.load(0);
  mock.flushShortTimers();
  assert.equal(mock.jobs.length, 1);
  assert.equal(mock.successes.length, 0);
  mock.afterprint(0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 1);
  assert.equal(mock.successes.length, 1);
  assert.equal(mock.errors.length, 0);
  assertClean(mock);
});

for (const failedIndex of [0, 1]) {
  test(`un error al imprimir la copia ${failedIndex + 1} detiene la cola y conserva las copias pendientes`, (t) => {
    const failure = new Error("Impresión no disponible");
    const mock = mockPrinting(t, {
      printBehavior(frame, index) {
        if (index === failedIndex) throw failure;
      }
    });
    mock.start({ copies: 2 });
    mock.load(0);
    mock.flushShortTimers();
    if (failedIndex === 1) {
      mock.afterprint(0);
      mock.flushShortTimers();
      mock.load(1);
      mock.flushShortTimers();
    }
    assert.equal(mock.jobs.length, failedIndex + 1);
    assert.equal(mock.successes.length, 0);
    assert.equal(mock.errors.length, 1);
    assert.equal(mock.errors[0].error, failure);
    assert.equal(mock.errors[0].context.remainingCopies, 2 - failedIndex);
    mock.afterprint(failedIndex);
    mock.flushShortTimers();
    assert.equal(mock.frames.length, failedIndex + 1);
    assert.equal(mock.errors.length, 1);
    assert.equal(mock.successes.length, 0);
    assertClean(mock);
  });

  test(`si la copia ${failedIndex + 1} no emite afterprint, el tiempo límite no avanza ni anuncia éxito`, (t) => {
    const mock = mockPrinting(t);
    mock.start({ copies: 2 });
    mock.load(0);
    mock.flushShortTimers();
    if (failedIndex === 1) {
      mock.afterprint(0);
      mock.flushShortTimers();
      mock.load(1);
      mock.flushShortTimers();
    }
    mock.expireTimeout();
    mock.flushShortTimers();
    assert.equal(mock.jobs.length, failedIndex + 1);
    assert.equal(mock.successes.length, 0);
    assert.equal(mock.errors.length, 1);
    assert.ok(mock.errors[0].error instanceof Error);
    assert.equal(mock.errors[0].context.remainingCopies, 2 - failedIndex);
    mock.afterprint(failedIndex);
    mock.flushShortTimers();
    assert.equal(mock.jobs.length, failedIndex + 1, "Un afterprint tardío no debe reanudar la cola cancelada.");
    assert.equal(mock.successes.length, 0);
    assert.equal(mock.errors.length, 1);
    assertClean(mock);
  });
}

test("un marco sin ventana informa ambas copias pendientes y no intenta imprimir", (t) => {
  const mock = mockPrinting(t, { missingWindowAt: 0 });
  mock.start({ copies: 2 });
  mock.load(0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 1);
  assert.equal(mock.jobs.length, 0);
  assert.equal(mock.successes.length, 0);
  assert.equal(mock.errors.length, 1);
  assert.ok(mock.errors[0].error instanceof Error);
  assert.equal(mock.errors[0].context.remainingCopies, 2);
  assertClean(mock);
});

test("si el marco no carga, el tiempo límite cancela la secuencia incluso ante un load tardío", (t) => {
  const mock = mockPrinting(t);
  mock.start({ copies: 2 });
  mock.expireTimeout();
  assert.equal(mock.jobs.length, 0);
  assert.equal(mock.successes.length, 0);
  assert.equal(mock.errors.length, 1);
  assert.ok(mock.errors[0].error instanceof Error);
  assert.equal(mock.errors[0].context.remainingCopies, 2);
  mock.load(0);
  mock.flushShortTimers();
  assert.equal(mock.frames.length, 1);
  assert.equal(mock.jobs.length, 0);
  assert.equal(mock.errors.length, 1);
  assertClean(mock);
});
