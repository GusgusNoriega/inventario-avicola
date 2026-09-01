import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const consumerSource = readFileSync(
  new URL("../../public/js/despacho-productos-pantalla-cliente.js", import.meta.url),
  "utf8"
);
const contractSource = readFileSync(
  new URL("../../public/js/product-dispatch-customer-display.js", import.meta.url),
  "utf8"
);

test("la pantalla de productos usa un contrato distinto al de Minorista", () => {
  assert.match(
    consumerSource,
    /from "\.\/product-dispatch-customer-display\.js"/
  );
  assert.doesNotMatch(consumerSource, /retail-customer-display\.js/);
  assert.doesNotMatch(consumerSource, /pantalla-cliente-minorista/);
  assert.match(
    contractSource,
    /"product-dispatch-customer-display-state"/
  );
  assert.match(
    contractSource,
    /"product-dispatch-customer-display-request"/
  );
  assert.match(
    contractSource,
    /"product-dispatch-customer-display-reset"/
  );
  assert.match(
    contractSource,
    /"sistema-pollos-pantalla-cliente-productos"/
  );
  assert.doesNotMatch(contractSource, /retail|minorista/i);
});

test("aísla la conexión por sucursal, usuario autenticado y origen", () => {
  assert.match(consumerSource, /query\.get\("branch"\)/);
  assert.match(consumerSource, /query\.get\("user"\)/);
  assert.match(consumerSource, /query\.get\("source"\)/);
  assert.match(
    consumerSource,
    /document\.body\.dataset\.authenticatedUserId/
  );
  assert.match(
    consumerSource,
    /USER_ID === AUTHENTICATED_USER_ID/
  );
  assert.match(
    consumerSource,
    /buildProductDispatchCustomerDisplayChannelName\(\s*BRANCH_ID,\s*USER_ID,\s*PRODUCER_ID/s
  );
  assert.match(
    consumerSource,
    /buildProductDispatchCustomerDisplayStorageKey\(\s*BRANCH_ID,\s*USER_ID,\s*PRODUCER_ID/s
  );
  assert.match(
    consumerSource,
    /productDispatchCustomerDisplayPayloadMatches\(payload, \{\s*branchId: BRANCH_ID,\s*userId: USER_ID,\s*producerId: PRODUCER_ID/s
  );
  assert.match(
    consumerSource,
    /payload\?\.type === PRODUCT_DISPATCH_CUSTOMER_DISPLAY_RESET_TYPE[\s\S]*payload\.branchId[\s\S]*payload\.userId[\s\S]*payload\.producerId/
  );
});

test("sincroniza por BroadcastChannel y conserva localStorage como respaldo", () => {
  assert.match(consumerSource, /new BroadcastChannel\(CHANNEL_NAME\)/);
  assert.match(consumerSource, /channel\.addEventListener\("message"/);
  assert.match(consumerSource, /globalThis\.addEventListener\("storage"/);
  assert.match(consumerSource, /event\.key !== STORAGE_KEY/);
  assert.match(consumerSource, /localStorage\.getItem\(STORAGE_KEY\)/);
  assert.match(consumerSource, /localStorage\.removeItem\(STORAGE_KEY\)/);
  assert.match(
    consumerSource,
    /channel\?\.postMessage\(\{\s*type: PRODUCT_DISPATCH_CUSTOMER_DISPLAY_REQUEST_TYPE,\s*branchId: BRANCH_ID,\s*userId: USER_ID,\s*producerId: PRODUCER_ID/s
  );
  assert.match(consumerSource, /globalThis\.setInterval\(\(\) => \{/);
  assert.match(consumerSource, /requestCurrentState\(\)/);
});

test("descarta datos vencidos o anteriores del mismo productor", () => {
  assert.match(consumerSource, /const DISPLAY_TTL_MS = 8000/);
  assert.match(
    consumerSource,
    /Date\.now\(\) - payloadTimestamp > DISPLAY_TTL_MS/
  );
  assert.match(consumerSource, /payloadRevision <= lastRevision/);
  assert.match(
    consumerSource,
    /payloadProducerInstance < lastProducerInstance/
  );
  assert.match(
    consumerSource,
    /payloadTimestamp < lastPayloadTimestamp/
  );
  assert.match(
    consumerSource,
    /Date\.now\(\) - lastUpdateAt > DISPLAY_TTL_MS/
  );
});

test("renderiza título, neto, importe, lista y total sin inyectar HTML", () => {
  for (const elementName of [
    "title",
    "liveNet",
    "liveAmount",
    "listHeading",
    "customer",
    "listNet",
    "listAmount",
    "announcement"
  ]) {
    assert.match(
      consumerSource,
      new RegExp(`elements\\.${elementName}\\.textContent\\s*=`)
    );
  }

  assert.match(consumerSource, /document\.createElement\("tr"\)/);
  assert.match(consumerSource, /document\.createElement\("td"\)/);
  assert.match(consumerSource, /product\.textContent =/);
  assert.match(consumerSource, /quantity\.textContent =/);
  assert.match(consumerSource, /net\.textContent =/);
  assert.match(consumerSource, /amount\.textContent =/);
  assert.match(consumerSource, /elements\.rows\.replaceChildren\(/);
  assert.match(consumerSource, /previewAmount\s*===\s*null\s*\?\s*`\$\{currencyLabel\(currency\)\}\s*--`/);
  assert.doesNotMatch(consumerSource, /\.innerHTML\s*=/);
  assert.doesNotMatch(consumerSource, /insertAdjacentHTML/);
  assert.doesNotMatch(consumerSource, /rawWeight|grossWeight/i);
});

test("ofrece pantalla completa y selección del monitor del cliente", () => {
  assert.match(consumerSource, /globalThis\.getScreenDetails\(\)/);
  assert.match(
    consumerSource,
    /document\.documentElement\.requestFullscreen\(\{ navigationUI: "hide", screen \}\)/
  );
  assert.match(consumerSource, /elements\.screenList\.replaceChildren\(\)/);
  assert.match(consumerSource, /requestFullscreenOnScreen\(screen, index\)/);
  assert.match(consumerSource, /document\.exitFullscreen\(\)/);
});
