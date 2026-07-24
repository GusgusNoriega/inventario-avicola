import test from "node:test";
import assert from "node:assert/strict";

import { newestRecordsFirst } from "../../public/js/record-order.js";

test("las columnas muestran primero la pesada más reciente sin mutar el orden cronológico", () => {
  const records = [
    { id: 1, weight: 10 },
    { id: 2, weight: 20 },
    { id: 3, weight: 30 }
  ];

  const displayedRecords = newestRecordsFirst(records);

  assert.deepEqual(displayedRecords.map((record) => record.id), [3, 2, 1]);
  assert.deepEqual(records.map((record) => record.id), [1, 2, 3]);
  assert.notEqual(displayedRecords, records);
});

test("el orden visual tolera colecciones ausentes", () => {
  assert.deepEqual(newestRecordsFirst(null), []);
  assert.deepEqual(newestRecordsFirst(undefined), []);
});
