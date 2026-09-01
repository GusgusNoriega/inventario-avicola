import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS } from "../../public/js/product-dispatch-customer-display-typography-catalog.js";

const consumerSource = readFileSync(
  new URL("../../public/js/despacho-productos-pantalla-cliente.js", import.meta.url),
  "utf8"
);
const contractSource = readFileSync(
  new URL("../../public/js/product-dispatch-customer-display.js", import.meta.url),
  "utf8"
);
const displayView = readFileSync(
  new URL("../../resources/views/despacho-productos-pantalla-cliente.blade.php", import.meta.url),
  "utf8"
);
const displayStyles = readFileSync(
  new URL("../../public/css/despacho-productos-pantalla-cliente.css", import.meta.url),
  "utf8"
);
const typographySource = readFileSync(
  new URL("../../public/js/product-dispatch-customer-display-typography.js", import.meta.url),
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

test("ofrece una barra lateral táctil para configurar toda la tipografía", () => {
  assert.match(displayView, /id="productCustomerDisplayOpenTypography"/);
  assert.match(displayView, /aria-controls="productCustomerDisplayTypographyPanel"/);
  assert.match(displayView, /aria-expanded="false"/);
  assert.match(displayView, /aria-label="Configurar tipografía"/);
  assert.match(displayView, /id="productCustomerDisplayTypographyPanel"/);
  assert.match(displayView, /role="dialog"/);
  assert.match(displayView, /aria-hidden="true"/);
  assert.match(displayView, /id="productCustomerDisplayTypographySearch"/);
  assert.match(displayView, /id="productCustomerDisplayTypographyControls"/);
  assert.equal((displayView.match(/data-pdcd-font-preset=/g) || []).length, 4);
  assert.match(displayView, /data-pdcd-font-reset-all/);
  assert.match(displayView, /data-pdcd-font-close/);
  assert.match(consumerSource, /initializeProductCustomerDisplayTypography/);
  assert.match(consumerSource, /branchId:\s*BRANCH_ID/);
  assert.match(consumerSource, /userId:\s*USER_ID/);
  assert.match(consumerSource, /enabled:\s*scopeIsValid/);
  assert.match(consumerSource, /typographySettings\?\.close\(\{ restoreFocus: false \}\)/);
});

test("cada texto visible usa una variable independiente sin fijar los tamaños responsive", () => {
  const controls = PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS.flatMap((group) => group.controls);
  assert.equal(controls.length, 28);
  controls.forEach(({ variable }) => {
    assert.match(displayStyles, new RegExp(`var\\(${variable},`));
  });
  assert.match(typographySource, /resolveProductCustomerDisplayTypographyGroups/);
  assert.match(typographySource, /getComputedStyle\(target\)\.fontSize/);
  assert.match(typographySource, /rootStyle\.removeProperty\(variable\)/);
  assert.match(typographySource, /style\?\.removeProperty\(variable\)/);
  assert.match(typographySource, /window\.addEventListener\("resize", scheduleRemeasure\)/);
  assert.match(typographySource, /document\.addEventListener\("fullscreenchange", scheduleRemeasure\)/);
  assert.match(typographySource, /preferences\.rebase\(groups\)/);
  assert.match(displayStyles, /font-size:\s*var\(--pdcd-fs-company-title,\s*min\(7\.5vw,\s*42px\)\)/);
  assert.match(displayStyles, /font-size:\s*var\(--pdcd-fs-live-weight,\s*min\(12vw,\s*180px\)\)/);
  assert.doesNotMatch(displayStyles, /font-size:\s*min\(var\(--pdcd-fs-/);
  assert.doesNotMatch(typographySource, /\.innerHTML\s*=/);
  assert.doesNotMatch(typographySource, /insertAdjacentHTML/);
});

test("el editor no desplaza la pantalla y conserva controles utilizables", () => {
  assert.match(
    displayStyles,
    /\.pdcd-typography-panel\s*\{[^}]*position:\s*fixed;[^}]*right:\s*0;[^}]*height:\s*100dvh;[^}]*font-size:\s*14px;/
  );
  assert.match(displayStyles, /html,\s*\nbody\s*\{[^}]*overflow:\s*hidden;/);
  assert.match(displayStyles, /\.pdcd-shell\s*\{[^}]*height:\s*100dvh;[^}]*overflow:\s*hidden;/);
  assert.match(displayStyles, /\.pdcd-typography-body\s*\{[^}]*overflow-y:\s*auto;/);
  assert.match(displayStyles, /\.pdcd-typography-inputs\s*\{[^}]*grid-template-columns:/);
  assert.match(displayStyles, /\.pdcd-typography-inputs\s*>\s*button\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/);
  assert.match(displayStyles, /\[data-pdcd-font-reset\]\s*\{[^}]*grid-column:\s*1\s*\/\s*-1;/);
  assert.match(displayStyles, /\.pdcd-actions button\s*\{[^}]*font-size:\s*var\(--pdcd-fs-actions,\s*17px\);/);
  assert.doesNotMatch(displayStyles, /\.pdcd-actions button\s*\{[^}]*font-size:\s*0;/);
  assert.match(displayStyles, /\.pdcd-monitor-trigger::after/);
  assert.match(displayStyles, /\.pdcd-fullscreen-trigger::after/);
  assert.doesNotMatch(displayStyles, /button:first-of-type::after|button:last-of-type::after/);
  assert.match(typographySource, /event\.key === "Escape"/);
  assert.match(typographySource, /trigger\.focus\(\{ preventScroll: true \}\)/);
  assert.match(typographySource, /window\.addEventListener\("storage"/);
  assert.match(typographySource, /window\.addEventListener\("pageshow"/);
  assert.match(typographySource, /window\.addEventListener\("pagehide"/);
  assert.match(
    typographySource,
    /if \(!event\.persisted\) return;\s*remeasure\(\);\s*preferences\.reload\(\);/
  );
});
