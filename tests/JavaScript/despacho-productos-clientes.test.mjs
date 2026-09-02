import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  buildProductDispatchClientListPath,
  buildProductDispatchClientPayload,
  normalizeProductDispatchClient,
  normalizeProductDispatchClientForm,
  validateProductDispatchClientForm
} from "../../public/js/despacho-productos-clientes.js";

const javascript = await readFile(
  new URL("../../public/js/despacho-productos-clientes.js", import.meta.url),
  "utf8"
);

test("normaliza los datos mínimos de un cliente externo", () => {
  assert.deepEqual(
    normalizeProductDispatchClient({
      id: 17,
      document_type: " RUC ",
      document: "20123456789",
      name: " COMERCIAL EL SOL ",
      address: " Av. Principal 123 ",
      created_at: "2026-09-02T12:30:00.000000Z",
      is_external: true
    }),
    {
      id: "17",
      documentType: "RUC",
      document: "20123456789",
      name: "COMERCIAL EL SOL",
      address: "Av. Principal 123",
      createdAt: "2026-09-02T12:30:00.000000Z",
      isExternal: true
    }
  );
});

test("limpia el formulario y valida DNI, RUC y campos obligatorios", () => {
  assert.deepEqual(
    normalizeProductDispatchClientForm({
      name: "  Comercial Águila  ",
      document: "20-123-456-789",
      address: "  Jr. Norte 40  "
    }),
    {
      name: "COMERCIAL ÁGUILA",
      document: "20123456789",
      address: "Jr. Norte 40"
    }
  );

  assert.equal(validateProductDispatchClientForm({
    name: "Cliente DNI",
    document: "12345678",
    address: "Calle Uno"
  }).valid, true);
  assert.equal(validateProductDispatchClientForm({
    name: "Cliente RUC",
    document: "20123456789",
    address: "Calle Dos"
  }).valid, true);

  const invalid = validateProductDispatchClientForm({
    name: "",
    document: "1234567",
    address: ""
  });
  assert.equal(invalid.valid, false);
  assert.match(invalid.errors.name, /nombre/i);
  assert.match(invalid.errors.document, /8 dígitos.*11 dígitos/i);
  assert.match(invalid.errors.address, /dirección/i);
});

test("construye un payload cerrado a los tres datos básicos", () => {
  const payload = buildProductDispatchClientPayload({
    name: "  Cliente rápido ",
    document: "12 345 678",
    address: " Av. Mercado 5 ",
    precios: { POLLO_VIVO: 9 },
    es_cliente_interno: true,
    motivo: "no debe enviarse"
  });

  assert.deepEqual(payload, {
    nombre_razon_social: "CLIENTE RÁPIDO",
    numero_documento: "12345678",
    direccion: "Av. Mercado 5"
  });
  assert.deepEqual(Object.keys(payload), [
    "nombre_razon_social",
    "numero_documento",
    "direccion"
  ]);
});

test("genera la consulta del listado sin interrogación vacía y codifica la búsqueda", () => {
  assert.equal(
    buildProductDispatchClientListPath("/despacho-productos/clientes/", ""),
    "/despacho-productos/clientes"
  );
  assert.equal(
    buildProductDispatchClientListPath("/despacho-productos/clientes", " Sol Norte "),
    "/despacho-productos/clientes?buscar=Sol+Norte"
  );
});

test("la interfaz usa POST o PUT, confirma DELETE y nunca envía precio, clasificación ni motivo", () => {
  assert.match(javascript, /method:\s*editingId\s*\?\s*"PUT"\s*:\s*"POST"/);
  assert.match(javascript, /method:\s*"DELETE"/);
  assert.match(javascript, /window\.confirm\(`/);
  assert.match(javascript, /data-action="edit"/);
  assert.match(javascript, /data-action="delete"/);
  assert.match(javascript, /aria-label="Editar a /);
  assert.match(javascript, /aria-label="Eliminar a /);
  assert.match(javascript, /elements\.search\.focus\(\{ preventScroll: true \}\)/);
  assert.doesNotMatch(javascript, /\bprecios\b/);
  assert.doesNotMatch(javascript, /es_cliente_interno/);
  assert.doesNotMatch(javascript.toLocaleLowerCase("es-PE"), /\bmotivo\b/);
});
