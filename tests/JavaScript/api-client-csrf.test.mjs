import assert from "node:assert/strict";
import test from "node:test";

function memoryStorage() {
  const values = new Map();

  return {
    getItem(key) {
      return values.has(key) ? values.get(key) : null;
    },
    setItem(key, value) {
      values.set(key, String(value));
    },
    removeItem(key) {
      values.delete(key);
    }
  };
}

for (const clientFile of ["api-client.js", "despacho-mayorista-2-api-client.js"]) {
  test(`${clientFile} sends CSRF proof with a bearer token on same-origin writes`, async () => {
    const requests = [];

    globalThis.window = {
      SISTEMA_POLLOS_API_URL: "/api/v1",
      dispatchEvent() {}
    };
    globalThis.document = { cookie: "XSRF-TOKEN=csrf-token" };
    globalThis.sessionStorage = memoryStorage();
    globalThis.fetch = async (url, options) => {
      requests.push({ url, options });

      return {
        status: 200,
        ok: true,
        headers: { get: () => "application/json" },
        json: async () => ({ data: { saved: true } })
      };
    };

    const moduleUrl = new URL(`../../public/js/${clientFile}`, import.meta.url);
    moduleUrl.searchParams.set("test", `${Date.now()}-${clientFile}`);
    const { apiRequest, authSession } = await import(moduleUrl.href);

    authSession.save("token-prueba", "2099-01-01T00:00:00.000Z");

    const response = await apiRequest("/recurso", {
      method: "POST",
      body: JSON.stringify({ value: 1 })
    });

    assert.deepEqual(response, { data: { saved: true } });
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, "/api/v1/recurso");
    assert.equal(requests[0].options.headers.get("Authorization"), "Bearer token-prueba");
    assert.equal(requests[0].options.headers.get("X-XSRF-TOKEN"), "csrf-token");
  });
}
