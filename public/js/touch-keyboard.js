const keyboard = document.querySelector("[data-touch-keyboard-component]");

if (keyboard) {
  const title = keyboard.querySelector("[data-touch-keyboard-title]");
  const valueOutput = keyboard.querySelector("[data-touch-keyboard-value]");
  const keysContainer = keyboard.querySelector("[data-touch-keyboard-keys]");
  const state = {
    target: null,
    initialValue: "",
    buffer: "",
    uppercase: false,
    symbols: false,
    suppressOpen: false,
  };

  const escapeHtml = value => String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const keyButton = (key, className = "") => {
    const classAttribute = className ? ` class="${className}"` : "";
    const label = key === " " ? "Espacio" : key;
    return `<button type="button"${classAttribute} data-touch-keyboard-key="${escapeHtml(key)}">${escapeHtml(label)}</button>`;
  };

  function displayValue() {
    if (!state.target) return;
    const isPassword = state.target.type === "password";
    valueOutput.textContent = state.buffer
      ? (isPassword ? "•".repeat(state.buffer.length) : state.buffer)
      : "Campo vacío";
    valueOutput.classList.toggle("is-password", isPassword);
  }

  function renderRows(rows) {
    return rows.map(row => `
      <div class="touch-keyboard-row">
        ${row.map(key => keyButton(key)).join("")}
      </div>
    `).join("");
  }

  function renderKeyboard() {
    if (state.symbols) {
      keysContainer.className = "touch-keyboard-keys is-symbols";
      keysContainer.setAttribute("aria-label", "Símbolos del teclado táctil");
      keysContainer.innerHTML = renderRows([
        ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
        ["!", "@", "#", "$", "%", "^", "&", "*", "(", ")"],
        ["-", "_", "=", "+", "[", "]", "{", "}", "\\", "|"],
        [";", ":", "'", '"', ",", ".", "/", "?", "`", "~"],
      ]) + `
        <div class="touch-keyboard-row is-controls">
          <button type="button" class="is-layout" data-touch-keyboard-action="letters">ABC</button>
          ${keyButton(" ", "is-space")}
        </div>
      `;
      return;
    }

    const applyCase = key => state.uppercase ? key : key.toLocaleLowerCase("es");
    const fieldMode = state.target?.dataset.touchKeyboardInput || "text";
    const shortcuts = fieldMode === "email"
      ? ["@", ".", "_", "-", "+"]
      : ["@", ".", "_", "-"];

    keysContainer.className = "touch-keyboard-keys is-letters";
    keysContainer.setAttribute("aria-label", "Letras del teclado táctil");
    keysContainer.innerHTML = renderRows([
      ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
      ["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"].map(applyCase),
      ["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"].map(applyCase),
    ]) + `
      <div class="touch-keyboard-row is-short">
        <button type="button" class="is-shift ${state.uppercase ? "is-active" : ""}" data-touch-keyboard-action="shift" aria-pressed="${state.uppercase}">Mayús</button>
        ${["Z", "X", "C", "V", "B", "N", "M"].map(key => keyButton(applyCase(key))).join("")}
      </div>
      <div class="touch-keyboard-row is-controls">
        <button type="button" class="is-layout" data-touch-keyboard-action="symbols">?123</button>
        ${shortcuts.map(key => keyButton(key)).join("")}
        ${fieldMode === "password" ? keyButton(" ", "is-space") : ""}
      </div>
    `;
  }

  function setValue(nextValue) {
    if (!state.target) return;
    const maximum = state.target.maxLength > 0 ? state.target.maxLength : 255;
    state.buffer = nextValue.slice(0, maximum);
    state.target.value = state.buffer;
    state.target.dispatchEvent(new Event("input", { bubbles: true }));
    displayValue();
  }

  function openKeyboard(input) {
    if (!input || input.disabled || state.suppressOpen) return;

    state.target?.setAttribute("aria-expanded", "false");
    state.target = input;
    state.initialValue = input.value;
    state.buffer = input.value;
    state.uppercase = false;
    state.symbols = false;
    input.setAttribute("aria-expanded", "true");
    input.setAttribute("aria-controls", keyboard.id);
    title.textContent = input.dataset.touchKeyboardLabel || "Ingresar texto";
    renderKeyboard();
    displayValue();
    keyboard.hidden = false;
    keyboard.setAttribute("aria-hidden", "false");
    document.body.classList.add("has-touch-keyboard");
  }

  function closeKeyboard(commit = true) {
    const target = state.target;
    if (!target) return;

    if (!commit) setValue(state.initialValue);
    if (commit) target.dispatchEvent(new Event("change", { bubbles: true }));
    keyboard.hidden = true;
    keyboard.setAttribute("aria-hidden", "true");
    document.body.classList.remove("has-touch-keyboard");
    target.setAttribute("aria-expanded", "false");
    state.target = null;
    state.initialValue = "";
    state.buffer = "";
    state.suppressOpen = true;
    target.focus({ preventScroll: true });
    queueMicrotask(() => { state.suppressOpen = false; });
  }

  document.addEventListener("focusin", event => {
    if (event.target.matches("[data-touch-keyboard-input]")) openKeyboard(event.target);
  });

  document.addEventListener("click", event => {
    const input = event.target.closest("[data-touch-keyboard-input]");
    if (input) {
      openKeyboard(input);
      return;
    }

    const key = event.target.closest("[data-touch-keyboard-key]");
    if (key) {
      setValue(`${state.buffer}${key.dataset.touchKeyboardKey}`);
      return;
    }

    const action = event.target.closest("[data-touch-keyboard-action]");
    if (!action) return;

    switch (action.dataset.touchKeyboardAction) {
      case "clear": setValue(""); break;
      case "backspace": setValue(state.buffer.slice(0, -1)); break;
      case "shift": state.uppercase = !state.uppercase; renderKeyboard(); break;
      case "symbols": state.symbols = true; renderKeyboard(); break;
      case "letters": state.symbols = false; renderKeyboard(); break;
      case "cancel": closeKeyboard(false); break;
      case "accept": closeKeyboard(true); break;
    }
  });

  document.addEventListener("input", event => {
    if (event.target === state.target && event.isTrusted) {
      state.buffer = state.target.value;
      displayValue();
    }
  });

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && state.target) {
      event.preventDefault();
      closeKeyboard(false);
    }
  });
}
