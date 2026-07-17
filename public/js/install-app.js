const installButton = document.getElementById("installPwaButton");
const installStatus = document.getElementById("installPwaStatus");
const installedPanel = document.getElementById("installedPwaPanel");
const waitingPanel = document.getElementById("waitingPwaPanel");

function isInstalled() {
  return window.matchMedia("(display-mode: standalone)").matches
    || window.matchMedia("(display-mode: fullscreen)").matches
    || window.navigator.standalone === true;
}

function setStatus(message, isError = false) {
  if (!installStatus) {
    return;
  }

  installStatus.textContent = message;
  installStatus.classList.toggle("is-error", isError);
}

function refreshInstallState() {
  const installed = isInstalled();
  const canInstall = Boolean(window.deferredPwaInstallPrompt);

  installedPanel?.toggleAttribute("hidden", !installed);
  waitingPanel?.toggleAttribute("hidden", installed || canInstall);

  if (!installButton) {
    return;
  }

  installButton.disabled = installed || !canInstall;
  installButton.textContent = installed
    ? "Aplicación instalada"
    : canInstall
      ? "Instalar ahora"
      : "Preparando instalación…";

  if (installed) {
    setStatus("Sistema Pollos ya está abierto como aplicación instalada.");
  } else if (canInstall) {
    setStatus("La instalación está lista. Presiona el botón y confirma en el navegador.");
  }
}

function showInstallError(event) {
  if (installButton) {
    installButton.disabled = true;
    installButton.textContent = "Instalación no disponible";
  }

  waitingPanel?.removeAttribute("hidden");
  setStatus(event.detail || "No fue posible preparar la instalación en este navegador.", true);
}

async function installApplication() {
  const promptEvent = window.deferredPwaInstallPrompt;
  if (!promptEvent || !installButton) {
    refreshInstallState();
    return;
  }

  installButton.disabled = true;

  try {
    await promptEvent.prompt();
    const choice = await promptEvent.userChoice;
    window.deferredPwaInstallPrompt = null;

    if (choice.outcome === "accepted") {
      setStatus("Instalación aceptada. Windows agregará Sistema Pollos a tus aplicaciones.");
      installButton.textContent = "Instalando…";
      return;
    }

    setStatus("La instalación fue cancelada. Puedes volver a intentarlo desde el menú del navegador.");
  } catch (error) {
    setStatus("No fue posible abrir la instalación. Recarga la página e inténtalo nuevamente.", true);
  }

  refreshInstallState();
}

installButton?.addEventListener("click", installApplication);
window.addEventListener("pwa-install-ready", refreshInstallState);
window.addEventListener("pwa-install-complete", refreshInstallState);
window.addEventListener("pwa-install-error", showInstallError);
window.matchMedia("(display-mode: standalone)").addEventListener?.("change", refreshInstallState);

refreshInstallState();
