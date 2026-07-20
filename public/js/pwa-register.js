(function registerSistemaPollosServiceWorker() {
  window.deferredPwaInstallPrompt = null;

  window.addEventListener("beforeinstallprompt", function (event) {
    event.preventDefault();
    window.deferredPwaInstallPrompt = event;
    window.dispatchEvent(new CustomEvent("pwa-install-ready"));
  });

  window.addEventListener("appinstalled", function () {
    window.deferredPwaInstallPrompt = null;
    window.dispatchEvent(new CustomEvent("pwa-install-complete"));
  });

  if (!("serviceWorker" in navigator)) {
    window.dispatchEvent(new CustomEvent("pwa-install-error", {
      detail: "Este navegador no admite la instalación de aplicaciones web."
    }));
    return;
  }

  var refreshingForUpdate = false;
  var hadServiceWorkerController = Boolean(navigator.serviceWorker.controller);

  navigator.serviceWorker.addEventListener("controllerchange", function () {
    if (!hadServiceWorkerController || refreshingForUpdate) {
      return;
    }

    refreshingForUpdate = true;
    window.location.reload();
  });

  window.addEventListener("load", function () {
    navigator.serviceWorker.register("/service-worker.js", {
      scope: "/",
      updateViaCache: "none"
    }).then(function (registration) {
      return registration.update();
    }).catch(function (error) {
      console.warn("No fue posible registrar la aplicación instalable.", error);
      window.dispatchEvent(new CustomEvent("pwa-install-error", {
        detail: "No fue posible preparar la instalación. Verifica los archivos PWA del servidor."
      }));
    });
  });
})();
