const menuNotice = document.getElementById("menuNotice");
const futureViewLinks = document.querySelectorAll("[data-future-view]");
const menuFullscreenButton = document.getElementById("menuFullscreenButton");
const menuFullscreenLabel = document.getElementById("menuFullscreenLabel");
const menuFullscreenStatus = document.getElementById("menuFullscreenStatus");

function getFullscreenElement() {
  return document.fullscreenElement || document.webkitFullscreenElement || null;
}

function canUseFullscreen() {
  const root = document.documentElement;
  return Boolean(root.requestFullscreen || root.webkitRequestFullscreen);
}

function setFullscreenStatus(message) {
  if (menuFullscreenStatus) {
    menuFullscreenStatus.textContent = message;
  }
}

function updateFullscreenButton() {
  if (!menuFullscreenButton) {
    return;
  }

  if (!canUseFullscreen()) {
    menuFullscreenButton.disabled = true;
    menuFullscreenButton.setAttribute("aria-label", "Pantalla completa no disponible");
    menuFullscreenButton.title = "Pantalla completa no disponible";

    if (menuFullscreenLabel) {
      menuFullscreenLabel.textContent = "No disponible";
    }

    return;
  }

  const isFullscreen = Boolean(getFullscreenElement());
  const actionLabel = isFullscreen ? "Salir de pantalla completa" : "Activar pantalla completa";

  menuFullscreenButton.classList.toggle("is-active", isFullscreen);
  menuFullscreenButton.setAttribute("aria-pressed", String(isFullscreen));
  menuFullscreenButton.setAttribute("aria-label", actionLabel);
  menuFullscreenButton.title = actionLabel;
  document.body.classList.toggle("is-menu-fullscreen", isFullscreen);

  if (menuFullscreenLabel) {
    menuFullscreenLabel.textContent = isFullscreen ? "Restaurar pantalla" : "Pantalla completa";
  }
}

async function enterFullscreen() {
  const root = document.documentElement;

  if (root.requestFullscreen) {
    await root.requestFullscreen();
    return;
  }

  if (root.webkitRequestFullscreen) {
    await root.webkitRequestFullscreen();
  }
}

async function exitFullscreen() {
  if (document.exitFullscreen) {
    await document.exitFullscreen();
    return;
  }

  if (document.webkitExitFullscreen) {
    await document.webkitExitFullscreen();
  }
}

async function toggleFullscreen() {
  if (!canUseFullscreen()) {
    setFullscreenStatus("Este navegador no permite activar la pantalla completa desde el menú.");
    return;
  }

  menuFullscreenButton.disabled = true;

  try {
    if (getFullscreenElement()) {
      await exitFullscreen();
    } else {
      await enterFullscreen();
    }
  } catch (error) {
    setFullscreenStatus("No fue posible cambiar el modo de pantalla completa.");
  } finally {
    menuFullscreenButton.disabled = false;
    updateFullscreenButton();
    menuFullscreenButton.focus({ preventScroll: true });
  }
}

if (menuFullscreenButton) {
  if (canUseFullscreen()) {
    menuFullscreenButton.addEventListener("click", toggleFullscreen);
  }

  updateFullscreenButton();
}

function handleFullscreenChange() {
  updateFullscreenButton();
  setFullscreenStatus(
    getFullscreenElement()
      ? "Pantalla completa activada. Presiona Escape para salir."
      : "Pantalla completa desactivada.",
  );
}

document.addEventListener("fullscreenchange", handleFullscreenChange);
document.addEventListener("webkitfullscreenchange", handleFullscreenChange);

function showFutureViewNotice(viewName) {
  if (!menuNotice) {
    return;
  }

  menuNotice.textContent = `La vista ${viewName} se agregará después.`;
}

futureViewLinks.forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    const viewName = link.dataset.futureView || "seleccionada";
    showFutureViewNotice(viewName);
    window.history.pushState(null, "", link.getAttribute("href"));
  });
});

window.addEventListener("popstate", () => {
  if (menuNotice) {
    menuNotice.textContent = "";
  }
});
