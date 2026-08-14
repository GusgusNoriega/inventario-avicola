const API_BASE_URL = window.SISTEMA_POLLOS_API_URL
  || "/api/v1";
const AUTH_TOKEN_KEY = "sistema-pollos-auth-token";
const AUTH_EXPIRES_KEY = "sistema-pollos-auth-expires-at";
let csrfCookiePromise = null;

export const authSession = {
  getToken() {
    const token = sessionStorage.getItem(AUTH_TOKEN_KEY);
    const expiresAt = sessionStorage.getItem(AUTH_EXPIRES_KEY);

    if (!token || !expiresAt || Date.parse(expiresAt) <= Date.now()) {
      this.clear();
      return null;
    }

    return token;
  },

  save(token, expiresAt) {
    sessionStorage.setItem(AUTH_TOKEN_KEY, token);
    sessionStorage.setItem(AUTH_EXPIRES_KEY, expiresAt);
  },

  clear() {
    sessionStorage.removeItem(AUTH_TOKEN_KEY);
    sessionStorage.removeItem(AUTH_EXPIRES_KEY);
  }
};

export async function apiRequest(path, options = {}) {
  const token = authSession.getToken();
  const headers = new Headers(options.headers || {});
  const method = String(options.method || "GET").toUpperCase();

  headers.set("Accept", "application/json");

  if (options.body && !(options.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }

  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  } else if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    await ensureCsrfCookie();
    const csrfToken = cookieValue("XSRF-TOKEN");
    if (csrfToken) headers.set("X-XSRF-TOKEN", csrfToken);
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers,
    credentials: "same-origin"
  });

  const contentType = response.headers.get("content-type") || "";
  const data = response.status === 204
    ? null
    : contentType.includes("application/json")
      ? await response.json()
      : { message: response.ok ? "" : "La sesión no pudo completar la solicitud." };

  if (response.status === 401) {
    authSession.clear();
    window.dispatchEvent(new CustomEvent("auth:expired"));
  }

  if (!response.ok) {
    const error = new Error(data?.message || "No se pudo completar la solicitud.");
    error.status = response.status;
    error.data = data;
    throw error;
  }

  return data;
}

function cookieValue(name) {
  const prefix = `${encodeURIComponent(name)}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));

  if (!cookie) return null;

  try {
    return decodeURIComponent(cookie.slice(prefix.length));
  } catch {
    return cookie.slice(prefix.length);
  }
}

async function ensureCsrfCookie() {
  if (cookieValue("XSRF-TOKEN")) return;

  if (!csrfCookiePromise) {
    csrfCookiePromise = fetch("/sanctum/csrf-cookie", {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    }).finally(() => {
      csrfCookiePromise = null;
    });
  }

  const response = await csrfCookiePromise;
  if (!response.ok) {
    throw new Error("No se pudo preparar la sesión segura.");
  }
}

export async function login(identifier, password, deviceName = "frontend-web") {
  const data = await apiRequest("/auth/login", {
    method: "POST",
    body: JSON.stringify({
      login: identifier,
      password,
      device_name: deviceName
    })
  });

  authSession.save(data.access_token, data.expires_at);

  return data;
}

export async function logout() {
  try {
    await apiRequest("/auth/logout", { method: "POST" });
  } finally {
    authSession.clear();
  }
}
