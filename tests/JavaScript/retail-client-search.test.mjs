import test from "node:test";
import assert from "node:assert/strict";

import {
  filterAndRankRetailClients,
  normalizeRetailClientSearch
} from "../../public/js/retail-client-search.js";

test("prioriza nombres y apellidos que empiezan con la búsqueda", () => {
  const clients = [
    { id: 1, name: "Luz Espinoza", document: "44112233" },
    { id: 2, name: "Carlos Tapia", document: "77889900" },
    { id: 3, name: "Pilar Gómez", document: "11223344" },
    { id: 4, name: "Juan Pineda", document: "55667788" }
  ];

  assert.deepEqual(
    filterAndRankRetailClients(clients, "pi").map((client) => client.id),
    [3, 4, 1, 2]
  );
});

test("prioriza el inicio del documento antes de coincidencias internas", () => {
  const clients = [
    { id: 1, name: "María Espinoza", document: "70990011" },
    { id: 2, name: "Ana Torres", document: "PI-2040" },
    { id: 3, name: "Roberto Tapia", document: "40505050" }
  ];

  assert.deepEqual(
    filterAndRankRetailClients(clients, "pi").map((client) => client.id),
    [2, 1, 3]
  );
});

test("ignora mayúsculas y tildes al comparar", () => {
  const clients = [
    { id: 1, name: "Lucía Tapia", document: "" },
    { id: 2, name: "Pía Núñez", document: "" }
  ];

  assert.equal(normalizeRetailClientSearch("  PÍÁ  "), "pia");
  assert.deepEqual(
    filterAndRankRetailClients(clients, "pia").map((client) => client.id),
    [2, 1]
  );
});

test("sin búsqueda conserva el orden original y sin coincidencias devuelve vacío", () => {
  const clients = [
    { id: 2, name: "Segundo Cliente", document: "20" },
    { id: 1, name: "Primer Cliente", document: "10" }
  ];

  assert.deepEqual(filterAndRankRetailClients(clients, "").map((client) => client.id), [2, 1]);
  assert.deepEqual(filterAndRankRetailClients(clients, "xyz"), []);
});
