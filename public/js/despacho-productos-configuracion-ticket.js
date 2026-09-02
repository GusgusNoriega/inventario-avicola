import { apiRequest } from "./api-client.js";

const root = document.querySelector("#productTicketConfiguration");

if (root) {
  const apiBase = root.dataset.apiBase || "/despacho-productos";
  const form = document.querySelector("#productTicketTitleForm");
  const input = document.querySelector("#productTicketTitle");
  const preview = document.querySelector("#productTicketTitlePreview");
  const counter = document.querySelector("#productTicketTitleCount");
  const status = document.querySelector("#productTicketTitleStatus");
  const save = document.querySelector("#productTicketTitleSave");

  function updatePreview() {
    const title = input.value.trim() || "DESPACHO DE PRODUCTOS";
    preview.textContent = title;
    counter.textContent = String(input.value.length);
  }

  function setStatus(message, type = "") {
    status.textContent = message;
    status.classList.toggle("is-success", type === "success");
    status.classList.toggle("is-error", type === "error");
  }

  function errorMessage(error) {
    const errors = error?.data?.errors;
    const first = errors && Object.values(errors).flat().find(Boolean);
    return String(first || error?.message || "No se pudo guardar el título.");
  }

  async function loadConfiguration() {
    save.disabled = true;
    setStatus("Cargando…");

    try {
      const response = await apiRequest(`${apiBase}/catalogo`);
      const data = response?.data || response || {};
      input.value = String(
        data.product_ticket_title
        || data.ticket_title
        || "DESPACHO DE PRODUCTOS"
      ).slice(0, 180);
      updatePreview();
      setStatus("Listo para editar.");
      save.disabled = false;
    } catch (error) {
      setStatus(errorMessage(error), "error");
    }
  }

  input.addEventListener("input", updatePreview);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const title = input.value.trim();

    if (!title) {
      setStatus("Escribe el título del ticket.", "error");
      input.focus();
      return;
    }

    save.disabled = true;
    setStatus("Guardando…");

    try {
      const response = await apiRequest(`${apiBase}/configuracion`, {
        method: "PUT",
        body: JSON.stringify({ product_ticket_title: title })
      });
      input.value = String(response?.data?.product_ticket_title || title).slice(0, 180);
      updatePreview();
      setStatus("Título guardado.", "success");
    } catch (error) {
      setStatus(errorMessage(error), "error");
    } finally {
      save.disabled = false;
    }
  });

  updatePreview();
  loadConfiguration();
}
