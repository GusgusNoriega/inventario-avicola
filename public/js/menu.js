const menuNotice = document.getElementById("menuNotice");
const futureViewLinks = document.querySelectorAll("[data-future-view]");

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
