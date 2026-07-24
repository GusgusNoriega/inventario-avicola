import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = readFileSync(
  new URL("../../public/js/pantalla-cliente.js", import.meta.url),
  "utf8"
);

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.notEqual(start, -1, `No se encontró ${startMarker}`);
  assert.notEqual(end, -1, `No se encontró ${endMarker}`);
  return source.slice(start, end);
}

function createClassList() {
  const values = new Set();

  return {
    contains(className) {
      return values.has(className);
    },
    remove(className) {
      values.delete(className);
    },
    toggle(className, force) {
      if (force === true) {
        values.add(className);
        return true;
      }
      if (force === false) {
        values.delete(className);
        return false;
      }
      if (values.has(className)) {
        values.delete(className);
        return false;
      }
      values.add(className);
      return true;
    }
  };
}

function createElement(initialClasses = []) {
  const element = {
    textContent: "",
    classList: createClassList()
  };
  initialClasses.forEach((className) => element.classList.toggle(className, true));
  return element;
}

function loadDisplayRenderer(producerId = "producer-a") {
  const renderBlock = sourceBetween(
    "function normalizeCount",
    "function readStoredState"
  );
  const elements = {
    name: createElement(),
    ticket: createElement(),
    scales: {
      1: {
        card: createElement(),
        weight: createElement(),
        status: createElement(["is-waiting"])
      },
      2: {
        card: createElement(),
        weight: createElement(),
        status: createElement(["is-waiting"])
      }
    },
    records: createElement(),
    cages: createElement(),
    birds: createElement(),
    announcement: createElement(),
    status: createElement(["is-waiting"])
  };

  const renderPayload = new Function("elements", "PRODUCER_ID", `
    let lastUpdateAt = 0;
    let lastPayloadTimestamp = 0;
    let lastRevision = 0;
    let lastProducerInstance = 0;
    ${renderBlock}
    return renderPayload;
  `)(elements, producerId);

  return { elements, renderPayload };
}

function payload(overrides = {}) {
  return {
    type: "customer-display-state",
    producerId: "producer-a",
    producerInstance: 100,
    revision: 1,
    updatedAt: "2026-07-23T18:00:00.000Z",
    customerName: "Cliente Uno",
    weightSource: "1",
    ticket: {
      label: "Ticket 1",
      records: 2,
      javas: 5,
      birds: 43
    },
    scales: {
      1: { weightKg: 12.345 },
      2: { weightKg: null }
    },
    ...overrides
  };
}

test("renderiza cliente, ticket, ambas balanzas y aves en una sola actualización", () => {
  const { elements, renderPayload } = loadDisplayRenderer();

  renderPayload(payload());

  assert.equal(elements.name.textContent, "Cliente Uno");
  assert.equal(elements.ticket.textContent, "Ticket 1");
  assert.equal(elements.scales[1].weight.textContent, "12.35");
  assert.equal(elements.scales[2].weight.textContent, "---");
  assert.equal(elements.scales[1].status.textContent, "Seleccionada");
  assert.equal(elements.scales[2].status.textContent, "Sin lectura");
  assert.equal(elements.records.textContent, "2");
  assert.equal(elements.cages.textContent, "5");
  assert.equal(elements.birds.textContent, "43");
  assert.equal(
    elements.announcement.textContent,
    "Cliente Uno. Ticket 1. 43 aves en el ticket."
  );
  assert.equal(elements.status.textContent, "En vivo");
  assert.equal(elements.scales[1].card.classList.contains("is-selected"), true);
  assert.equal(elements.scales[1].card.classList.contains("has-reading"), true);
  assert.equal(elements.scales[2].card.classList.contains("has-reading"), false);
});

test("una revisión nueva reemplaza los datos y una antigua u otro productor no los pisa", () => {
  const { elements, renderPayload } = loadDisplayRenderer();

  renderPayload(payload({
    revision: 4,
    updatedAt: "2026-07-23T18:04:00.000Z"
  }));
  renderPayload(payload({
    revision: 5,
    updatedAt: "2026-07-23T18:05:00.000Z",
    customerName: "Cliente Dos",
    weightSource: "2",
    ticket: { label: "Ticket 2", records: 3, javas: 7, birds: 61 },
    scales: {
      1: { weightKg: 10 },
      2: { weightKg: 20.5 }
    }
  }));

  assert.equal(elements.name.textContent, "Cliente Dos");
  assert.equal(elements.ticket.textContent, "Ticket 2");
  assert.equal(elements.scales[1].weight.textContent, "10.00");
  assert.equal(elements.scales[2].weight.textContent, "20.50");
  assert.equal(elements.birds.textContent, "61");
  assert.equal(elements.scales[2].card.classList.contains("is-selected"), true);

  renderPayload(payload({
    revision: 4,
    updatedAt: "2026-07-23T18:04:30.000Z",
    customerName: "Dato antiguo"
  }));
  renderPayload(payload({
    producerId: "producer-b",
    revision: 9,
    updatedAt: "2026-07-23T18:09:00.000Z",
    customerName: "Otro productor"
  }));

  assert.equal(elements.name.textContent, "Cliente Dos");
  assert.equal(elements.birds.textContent, "61");
});

test("una instancia nueva acepta revisión reiniciada y bloquea mensajes tardíos de la anterior", () => {
  const { elements, renderPayload } = loadDisplayRenderer();

  renderPayload(payload({
    producerInstance: 100,
    revision: 20,
    updatedAt: "2026-07-23T18:20:00.000Z"
  }));
  renderPayload(payload({
    producerInstance: 101,
    revision: 1,
    updatedAt: "2026-07-23T18:21:00.000Z",
    customerName: "Operación recargada",
    ticket: { label: "Ticket 4", records: 1, javas: 2, birds: 18 }
  }));

  assert.equal(elements.name.textContent, "Operación recargada");
  assert.equal(elements.ticket.textContent, "Ticket 4");
  assert.equal(elements.birds.textContent, "18");

  renderPayload(payload({
    producerInstance: 100,
    revision: 99,
    updatedAt: "2026-07-23T18:22:00.000Z",
    customerName: "Instancia anterior"
  }));

  assert.equal(elements.name.textContent, "Operación recargada");
  assert.equal(elements.birds.textContent, "18");
});

test("conserva compatibilidad con el payload anterior y no convierte null en cero", () => {
  const { elements, renderPayload } = loadDisplayRenderer();

  renderPayload(payload({
    producerInstance: undefined,
    scales: undefined,
    weightSource: "2",
    weightKg: 7.5,
    ticket: undefined,
    ticketLabel: "Ticket legado",
    records: 1,
    cages: 3,
    birds: 27
  }));

  assert.equal(elements.scales[1].weight.textContent, "---");
  assert.equal(elements.scales[2].weight.textContent, "7.50");
  assert.equal(elements.ticket.textContent, "Ticket legado");
  assert.equal(elements.birds.textContent, "27");

  renderPayload(payload({
    producerInstance: undefined,
    revision: 2,
    updatedAt: "2026-07-23T18:00:01.000Z",
    scales: undefined,
    weightSource: "1",
    weightKg: null
  }));

  assert.equal(elements.scales[1].weight.textContent, "---");
});
