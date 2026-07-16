import { apiRequest, logout } from "./api-client.js";

const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

const state = {
  currentUser: null,
  users: [],
  roles: [],
  modules: [],
  branches: [],
  page: 1,
  pagination: { currentPage: 1, lastPage: 1, total: 0, from: 0, to: 0 },
  filters: { search: "", status: "", roleId: "", branchId: "" },
  confirmAction: null,
  roleCodeEdited: false,
};

const urls = {
  login: document.body.dataset.loginUrl || "/login",
  menu: document.body.dataset.menuUrl || "/",
};

const icons = {
  edit: '<path d="M4 20h4l11-11-4-4L4 16z"></path><path d="M13.5 6.5l4 4"></path>',
  key: '<path d="M14 7a5 5 0 1 1-2 4l8-8 2 2-2 2 2 2-2 2-2-2-2 2-2-2"></path>',
  sessions: '<rect x="3" y="5" width="14" height="11" rx="2"></rect><path d="M8 20h12a2 2 0 0 0 2-2V9"></path><path d="M7 9h6"></path>',
  disable: '<circle cx="12" cy="12" r="9"></circle><path d="M6 6l12 12"></path>',
  enable: '<circle cx="12" cy="12" r="9"></circle><path d="M8 12l3 3 5-6"></path>',
  trash: '<path d="M4 7h16"></path><path d="M9 7V4h6v3"></path><path d="M7 7l1 13h8l1-13"></path><path d="M10 11v5M14 11v5"></path>',
};

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function actionButton(action, id, label, iconName, danger = false) {
  const button = element("button", `access-icon-button${danger ? " is-danger" : ""}`);
  button.type = "button";
  button.dataset.action = action;
  button.dataset.id = String(id);
  button.title = label;
  button.setAttribute("aria-label", label);
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("aria-hidden", "true");
  svg.innerHTML = icons[iconName];
  button.append(svg);
  return button;
}

function initials(value) {
  const parts = String(value || "Usuario").trim().split(/\s+/).filter(Boolean);
  return parts.slice(0, 2).map(part => part[0]).join("").toUpperCase() || "US";
}

function normalizeModule(item = {}) {
  return {
    code: String(item.code ?? item.codigo ?? ""),
    name: String(item.name ?? item.nombre ?? item.description ?? item.descripcion ?? item.code ?? "Módulo"),
    description: String(item.description ?? item.descripcion ?? "Acceso completo a las vistas y operaciones del módulo."),
    path: item.path ?? item.ruta ?? null,
  };
}

function normalizeRole(item = {}) {
  const code = String(item.code ?? item.codigo ?? "");
  const moduleCodes = item.module_codes ?? item.modulos ?? item.modules?.map(module => module.code ?? module.codigo) ?? [];
  return {
    id: Number(item.id),
    code,
    name: String(item.name ?? item.nombre ?? code ?? "Rol"),
    protected: Boolean(item.protected ?? item.is_protected ?? item.protegido ?? code === "ADMINISTRADOR"),
    usersCount: Number(item.users_count ?? item.usuarios_count ?? 0),
    moduleCodes: [...new Set((moduleCodes || []).map(String))],
  };
}

function normalizeBranch(item = {}) {
  return {
    id: Number(item.id),
    code: String(item.code ?? item.codigo ?? ""),
    name: String(item.name ?? item.nombre ?? item.code ?? item.codigo ?? "Sucursal"),
  };
}

function normalizeUser(item = {}) {
  const rawRoles = item.roles ?? [];
  const roleIds = item.role_ids ?? item.roles_ids ?? rawRoles.filter(role => typeof role === "object").map(role => role.id);
  const roles = rawRoles.map(role => {
    if (typeof role === "string") {
      return state.roles.find(candidate => candidate.code === role) ?? { id: null, code: role, name: role };
    }
    return normalizeRole(role);
  });
  const branch = item.branch ?? item.sucursal ?? null;

  return {
    id: Number(item.id),
    name: String(item.name ?? item.nombre ?? "Usuario"),
    email: String(item.email ?? item.correo ?? ""),
    status: String(item.status ?? item.estado ?? "ACTIVO").toUpperCase(),
    branchId: item.branch_id ?? item.sucursal_id ?? branch?.id ?? null,
    branch: branch ? normalizeBranch(branch) : null,
    roleIds: [...new Set((roleIds || []).map(Number).filter(Number.isFinite))],
    roles,
    moduleCodes: item.module_codes ?? item.modulos ?? [],
    mustChangePassword: Boolean(item.must_change_password ?? item.debe_cambiar_password),
    lastLoginAt: item.last_login_at ?? item.ultimo_acceso_at ?? null,
  };
}

function payloadData(response) {
  return response?.data ?? response ?? {};
}

function listFrom(response, key) {
  const payload = payloadData(response);
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.[key])) return payload[key];
  if (Array.isArray(response?.[key])) return response[key];
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function responseUser(response) {
  const payload = payloadData(response);
  return payload?.user?.data ?? payload?.user ?? payload;
}

function formatDate(value) {
  if (!value) return "Nunca";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("es-CO", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

function messageFor(error, fallback = "No se pudo completar la solicitud.") {
  if (error?.status === 422) return error?.message || "Revisa los campos marcados e intenta de nuevo.";
  if (error?.status === 403) return error?.message || "No tienes permiso para realizar esta acción.";
  if (error?.status === 429) return "Se realizaron demasiadas solicitudes. Espera un momento e intenta de nuevo.";
  return error?.message || fallback;
}

function setAlert(target, text, type = "danger") {
  if (!target) return;
  target.textContent = text;
  target.className = `access-alert access-alert-${type}`;
  target.hidden = false;
}

function clearAlert(target) {
  if (!target) return;
  target.hidden = true;
  target.textContent = "";
}

function toast(text, error = false) {
  const container = $("#accessToasts");
  const notice = element("div", `access-toast${error ? " is-error" : ""}`, text);
  notice.setAttribute("role", error ? "alert" : "status");
  container.append(notice);
  window.setTimeout(() => notice.remove(), 4500);
}

function setButtonBusy(button, busy, busyLabel) {
  if (!button) return;
  const label = $(".access-button-label", button);
  const spinner = $(".access-spinner", button);
  if (!button.dataset.idleLabel && label) button.dataset.idleLabel = label.textContent;
  button.disabled = busy;
  button.setAttribute("aria-busy", String(busy));
  if (label) label.textContent = busy ? busyLabel : button.dataset.idleLabel;
  if (spinner) spinner.hidden = !busy;
}

function clearFormErrors(form) {
  $$(".access-field-error", form).forEach(node => { node.textContent = ""; });
  $$(".access-field.is-invalid", form).forEach(node => node.classList.remove("is-invalid"));
  $$("[aria-invalid='true']", form).forEach(node => node.removeAttribute("aria-invalid"));
}

function showFormErrors(form, errors = {}) {
  let firstInvalid = null;
  for (const [key, messages] of Object.entries(errors)) {
    const errorNode = $$('[data-error-for]', form).find(node => node.dataset.errorFor === key);
    const input = form.elements.namedItem(key);
    if (errorNode) errorNode.textContent = Array.isArray(messages) ? messages[0] : String(messages);
    if (input instanceof HTMLElement) {
      input.setAttribute("aria-invalid", "true");
      input.closest(".access-field")?.classList.add("is-invalid");
      firstInvalid ||= input;
    }
  }
  firstInvalid?.focus();
}

function openDialog(dialog) {
  if (!dialog) return;
  if (typeof dialog.showModal === "function") dialog.showModal();
  else dialog.setAttribute("open", "");
  document.body.classList.add("access-dialog-open");
}

function closeDialog(dialog) {
  if (!dialog) return;
  if (typeof dialog.close === "function" && dialog.open) dialog.close();
  else dialog.removeAttribute("open");
}

function refreshDialogBodyState() {
  if (!$("dialog[open]")) document.body.classList.remove("access-dialog-open");
}

function populateSelect(select, items, placeholder) {
  const selected = select.value;
  select.replaceChildren(new Option(placeholder, ""));
  for (const item of items) select.add(new Option(item.name, String(item.id)));
  select.value = selected;
}

function moduleName(code) {
  return state.modules.find(module => module.code === code)?.name ?? code.replace(/^MODULO_/, "").replaceAll("_", " ");
}

function renderSummary() {
  $("#accessRoleCount").textContent = String(state.roles.length);
  $("#accessModuleCount").textContent = String(state.modules.length);
}

function renderCurrentUser() {
  if (!state.currentUser) return;
  $("#currentUserName").textContent = state.currentUser.name;
  $("#currentUserInitials").textContent = initials(state.currentUser.name);
  const roleNames = state.currentUser.roles.map(role => (
    state.roles.find(candidate => candidate.code === role.code)?.name || role.name || role.code
  )).filter(Boolean);
  $("#currentUserRole").textContent = roleNames.join(", ") || "Sesión activa";
}

function renderRoleFiltersAndChoices() {
  populateSelect($("#userRoleFilter"), state.roles, "Todos los roles");
  populateSelect($("#userBranchFilter"), state.branches, "Todas las sucursales");
  populateSelect($("#userBranch"), state.branches, "Sin sucursal asignada");

  const container = $("#userRoleOptions");
  container.replaceChildren();
  for (const role of state.roles) {
    const label = element("label", "access-choice");
    const input = document.createElement("input");
    input.type = "checkbox";
    input.name = "role_ids";
    input.value = String(role.id);
    const box = element("span", "access-choice-box");
    box.setAttribute("aria-hidden", "true");
    const copy = element("span", "access-choice-text");
    copy.append(element("strong", "", role.name), element("small", "", `${role.moduleCodes.length} módulo${role.moduleCodes.length === 1 ? "" : "s"}`));
    label.append(input, box, copy);
    container.append(label);
  }
}

function renderModuleChoices() {
  const container = $("#roleModuleOptions");
  container.replaceChildren();
  for (const module of state.modules) {
    const label = element("label", "access-choice");
    const input = document.createElement("input");
    input.type = "checkbox";
    input.name = "module_codes";
    input.value = module.code;
    const box = element("span", "access-choice-box");
    box.setAttribute("aria-hidden", "true");
    const copy = element("span", "access-choice-text");
    copy.append(element("strong", "", module.name), element("small", "", module.description));
    label.append(input, box, copy);
    container.append(label);
  }
}

function renderRoleCards() {
  const grid = $("#rolesGrid");
  grid.replaceChildren();
  $("#rolesEmpty").hidden = state.roles.length !== 0;

  for (const role of state.roles) {
    const card = element("article", `access-role-card${role.protected ? " is-protected" : ""}`);
    const head = element("header", "access-role-head");
    const title = element("div");
    title.append(element("h3", "", role.name), element("span", "access-role-code", role.code));
    head.append(title);
    if (role.protected) head.append(element("span", "access-protected-badge", "Protegido"));

    const meta = element("div", "access-role-meta");
    const userMeta = element("span");
    const userCount = element("strong", "", String(role.usersCount));
    userMeta.append(userCount, document.createTextNode(` usuario${role.usersCount === 1 ? "" : "s"}`));
    const moduleMeta = element("span");
    const moduleCount = element("strong", "", String(role.moduleCodes.length));
    moduleMeta.append(moduleCount, document.createTextNode(` módulo${role.moduleCodes.length === 1 ? "" : "s"}`));
    meta.append(userMeta, moduleMeta);

    const modules = element("div", "access-role-modules");
    if (role.moduleCodes.length === 0) {
      modules.append(element("span", "access-role-no-modules", role.protected ? "Acceso total por ser administrador" : "Sin módulos asignados"));
    } else {
      role.moduleCodes.slice(0, 5).forEach(code => modules.append(element("span", "access-chip", moduleName(code))));
      if (role.moduleCodes.length > 5) modules.append(element("span", "access-chip access-chip-more", `+${role.moduleCodes.length - 5} más`));
    }

    const actions = element("footer", "access-role-actions");
    if (role.protected) {
      actions.append(element("span", "access-muted-value", "Este rol no puede modificarse ni eliminarse."));
    } else {
      const edit = element("button", "btn btn-ghost", "Editar");
      edit.type = "button";
      edit.dataset.action = "edit-role";
      edit.dataset.id = String(role.id);
      const remove = element("button", "btn btn-danger", "Eliminar");
      remove.type = "button";
      remove.dataset.action = "delete-role";
      remove.dataset.id = String(role.id);
      actions.append(edit, remove);
    }

    card.append(head, meta, modules, actions);
    grid.append(card);
  }
}

function chipList(roles) {
  const list = element("div", "access-chip-list");
  if (!roles.length) {
    list.append(element("span", "access-muted-value", "Sin rol"));
    return list;
  }
  roles.slice(0, 2).forEach(role => list.append(element("span", "access-chip", role.name || role.code)));
  if (roles.length > 2) list.append(element("span", "access-chip access-chip-more", `+${roles.length - 2}`));
  return list;
}

function userCell(user) {
  const wrapper = element("div", "access-user-cell");
  wrapper.append(element("span", "access-user-avatar", initials(user.name)));
  const copy = element("div");
  copy.append(element("strong", "", user.name), element("small", "", user.email));
  if (user.mustChangePassword) copy.append(element("small", "access-muted-value", "Cambio de contraseña pendiente"));
  wrapper.append(copy);
  return wrapper;
}

function renderUsers() {
  const body = $("#usersTableBody");
  body.replaceChildren();
  $("#usersEmpty").hidden = state.users.length !== 0;

  for (const user of state.users) {
    const row = document.createElement("tr");
    const identity = element("td");
    identity.dataset.label = "Usuario";
    identity.append(userCell(user));

    const branch = element("td", user.branch ? "" : "access-muted-value", user.branch?.name || "Sin asignar");
    branch.dataset.label = "Sucursal";
    const roles = element("td");
    roles.dataset.label = "Roles";
    roles.append(chipList(user.roles));
    const lastLogin = element("td", user.lastLoginAt ? "" : "access-muted-value", formatDate(user.lastLoginAt));
    lastLogin.dataset.label = "Último acceso";
    const status = element("td");
    status.dataset.label = "Estado";
    status.append(element("span", `access-status ${user.status === "ACTIVO" ? "is-active" : "is-inactive"}`, user.status === "ACTIVO" ? "Activo" : "Inactivo"));

    const actionsCell = element("td");
    actionsCell.dataset.label = "Acciones";
    const actions = element("div", "access-row-actions");
    actions.append(
      actionButton("edit-user", user.id, `Editar a ${user.name}`, "edit"),
      actionButton("reset-password", user.id, `Restablecer contraseña de ${user.name}`, "key"),
      actionButton("revoke-sessions", user.id, `Cerrar sesiones de ${user.name}`, "sessions"),
      actionButton("toggle-status", user.id, user.status === "ACTIVO" ? `Desactivar a ${user.name}` : `Activar a ${user.name}`, user.status === "ACTIVO" ? "disable" : "enable", user.status === "ACTIVO"),
    );
    actionsCell.append(actions);
    row.append(identity, branch, roles, lastLogin, status, actionsCell);
    body.append(row);
  }
}

function parsePagination(response) {
  const payload = payloadData(response);
  const meta = response?.meta ?? payload?.meta ?? payload;
  const total = Number(meta?.total ?? state.users.length);
  const currentPage = Number(meta?.current_page ?? meta?.currentPage ?? state.page ?? 1);
  const lastPage = Math.max(1, Number(meta?.last_page ?? meta?.lastPage ?? 1));
  const perPage = Number(meta?.per_page ?? meta?.perPage ?? 20);
  const from = Number(meta?.from ?? (total ? (currentPage - 1) * perPage + 1 : 0));
  const to = Number(meta?.to ?? Math.min(total, from + state.users.length - 1));
  return { total, currentPage, lastPage, from, to };
}

function renderPagination() {
  const page = state.pagination;
  $("#usersPaginationSummary").textContent = page.total
    ? `Mostrando ${page.from}–${page.to} de ${page.total} usuarios`
    : "Sin resultados";
  $("#usersPageIndicator").textContent = `Página ${page.currentPage} de ${page.lastPage}`;
  $("#usersPreviousPage").disabled = page.currentPage <= 1;
  $("#usersNextPage").disabled = page.currentPage >= page.lastPage;
  $("#accessUserCount").textContent = String(page.total);
  const activeUsers = state.users.filter(user => user.status === "ACTIVO").length;
  $("#accessActiveUserCount").textContent = page.total === state.users.length
    ? `${activeUsers} activo${activeUsers === 1 ? "" : "s"}`
    : `${activeUsers} activos en esta página`;
}

async function loadCatalog() {
  const response = await apiRequest("/admin/modules");
  const payload = payloadData(response);
  state.modules = (payload?.modules ?? response?.modules ?? (Array.isArray(payload) ? payload : [])).map(normalizeModule);
  state.branches = (payload?.branches ?? payload?.sucursales ?? response?.branches ?? []).map(normalizeBranch);
  renderModuleChoices();
  renderRoleFiltersAndChoices();
  renderRoleCards();
  renderSummary();
}

async function loadRoles() {
  const response = await apiRequest("/admin/roles?per_page=100");
  state.roles = listFrom(response, "roles").map(normalizeRole);
  renderRoleFiltersAndChoices();
  renderRoleCards();
  renderCurrentUser();
  renderSummary();
}

async function loadCurrentUser() {
  const response = await apiRequest("/auth/me");
  state.currentUser = normalizeUser(responseUser(response));
  renderCurrentUser();
}

function userQuery() {
  const params = new URLSearchParams({ page: String(state.page), per_page: "20" });
  if (state.filters.search) params.set("search", state.filters.search);
  if (state.filters.status) params.set("status", state.filters.status);
  if (state.filters.roleId) params.set("role_id", state.filters.roleId);
  if (state.filters.branchId) params.set("branch_id", state.filters.branchId);
  return params;
}

async function loadUsers() {
  const loading = $("#usersLoading");
  const empty = $("#usersEmpty");
  const listMessage = $("#userListMessage");
  loading.hidden = false;
  empty.hidden = true;
  clearAlert(listMessage);

  try {
    const response = await apiRequest(`/admin/users?${userQuery().toString()}`);
    state.users = listFrom(response, "users").map(normalizeUser);
    state.pagination = parsePagination(response);
    state.page = state.pagination.currentPage;
    renderUsers();
    renderPagination();
  } catch (error) {
    state.users = [];
    renderUsers();
    setAlert(listMessage, messageFor(error, "No fue posible cargar los usuarios."));
  } finally {
    loading.hidden = true;
  }
}

function openUserForm(user = null) {
  const form = $("#userForm");
  form.reset();
  clearFormErrors(form);
  clearAlert($("#userFormMessage"));
  const editing = Boolean(user);
  $("#userId").value = user?.id ?? "";
  $("#userName").value = user?.name ?? "";
  $("#userEmail").value = user?.email ?? "";
  $("#userBranch").value = user?.branchId ? String(user.branchId) : "";
  $("#userStatus").value = user?.status ?? "ACTIVO";
  $("#userDialogEyebrow").textContent = editing ? "Editar cuenta" : "Nueva cuenta";
  $("#userDialogTitle").textContent = editing ? `Editar a ${user.name}` : "Crear usuario";
  $("#saveUserButton .access-button-label").textContent = editing ? "Guardar cambios" : "Crear usuario";
  $("#saveUserButton").dataset.idleLabel = editing ? "Guardar cambios" : "Crear usuario";
  $("#newUserPasswordFields").hidden = editing;
  $("#userPassword").required = !editing;
  $("#userPasswordConfirmation").required = !editing;
  const selectedIds = new Set(user?.roleIds ?? user?.roles.map(role => role.id).filter(Boolean) ?? []);
  $$('input[name="role_ids"]', form).forEach(input => { input.checked = selectedIds.has(Number(input.value)); });
  openDialog($("#userDialog"));
  window.setTimeout(() => $("#userName").focus(), 0);
}

function validateUserForm(editing) {
  const form = $("#userForm");
  clearFormErrors(form);
  if (!form.reportValidity()) return false;
  let valid = true;
  const roles = $$('input[name="role_ids"]:checked', form);
  if (!roles.length) {
    $('[data-error-for="role_ids"]', form).textContent = "Selecciona al menos un rol.";
    valid = false;
  }
  if (!editing && $("#userPassword").value !== $("#userPasswordConfirmation").value) {
    $('[data-error-for="password_confirmation"]', form).textContent = "Las contraseñas no coinciden.";
    $("#userPasswordConfirmation").setAttribute("aria-invalid", "true");
    valid = false;
  }
  if (!valid) (roles.length ? $("#userPasswordConfirmation") : $('input[name="role_ids"]', form))?.focus();
  return valid;
}

async function saveUser(event) {
  event.preventDefault();
  const id = $("#userId").value;
  const editing = Boolean(id);
  if (!validateUserForm(editing)) return;
  const button = $("#saveUserButton");
  clearAlert($("#userFormMessage"));
  const payload = {
    name: $("#userName").value.trim(),
    email: $("#userEmail").value.trim(),
    branch_id: $("#userBranch").value || null,
    status: $("#userStatus").value,
    role_ids: $$('input[name="role_ids"]:checked', event.currentTarget).map(input => Number(input.value)),
  };
  if (!editing) {
    payload.password = $("#userPassword").value;
    payload.password_confirmation = $("#userPasswordConfirmation").value;
    payload.must_change_password = $("#userMustChangePassword").checked;
  }
  setButtonBusy(button, true, editing ? "Guardando…" : "Creando…");
  try {
    await apiRequest(editing ? `/admin/users/${encodeURIComponent(id)}` : "/admin/users", {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify(payload),
    });
    closeDialog($("#userDialog"));
    toast(editing ? "Los datos del usuario se actualizaron." : "El usuario se creó correctamente.");
    await Promise.all([loadUsers(), loadRoles()]);
  } catch (error) {
    showFormErrors(event.currentTarget, error?.data?.errors);
    setAlert($("#userFormMessage"), messageFor(error));
    $("#userFormMessage").focus();
  } finally {
    setButtonBusy(button, false);
  }
}

function openPasswordForm(user) {
  const form = $("#passwordForm");
  form.reset();
  clearFormErrors(form);
  clearAlert($("#passwordFormMessage"));
  $("#passwordUserId").value = String(user.id);
  $("#passwordDialogUser").textContent = `${user.name} · ${user.email}`;
  $("#resetMustChangePassword").checked = true;
  openDialog($("#passwordDialog"));
  window.setTimeout(() => $("#resetPassword").focus(), 0);
}

async function savePassword(event) {
  event.preventDefault();
  const form = event.currentTarget;
  clearFormErrors(form);
  clearAlert($("#passwordFormMessage"));
  if (!form.reportValidity()) return;
  if ($("#resetPassword").value !== $("#resetPasswordConfirmation").value) {
    $('[data-error-for="password_confirmation"]', form).textContent = "Las contraseñas no coinciden.";
    $("#resetPasswordConfirmation").focus();
    return;
  }
  const button = $("#savePasswordButton");
  setButtonBusy(button, true, "Restableciendo…");
  try {
    await apiRequest(`/admin/users/${encodeURIComponent($("#passwordUserId").value)}/reset-password`, {
      method: "POST",
      body: JSON.stringify({
        password: $("#resetPassword").value,
        password_confirmation: $("#resetPasswordConfirmation").value,
        must_change_password: $("#resetMustChangePassword").checked,
      }),
    });
    closeDialog($("#passwordDialog"));
    toast("La contraseña fue restablecida.");
    await loadUsers();
  } catch (error) {
    showFormErrors(form, error?.data?.errors);
    setAlert($("#passwordFormMessage"), messageFor(error));
    $("#passwordFormMessage").focus();
  } finally {
    setButtonBusy(button, false);
  }
}

function codeFromName(value) {
  return value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase().replace(/[^A-Z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 50);
}

function openRoleForm(role = null) {
  const form = $("#roleForm");
  form.reset();
  clearFormErrors(form);
  clearAlert($("#roleFormMessage"));
  const editing = Boolean(role);
  state.roleCodeEdited = editing;
  $("#roleId").value = role?.id ?? "";
  $("#roleName").value = role?.name ?? "";
  $("#roleCode").value = role?.code ?? "";
  $("#roleDialogEyebrow").textContent = editing ? "Editar perfil" : "Nuevo perfil";
  $("#roleDialogTitle").textContent = editing ? `Editar ${role.name}` : "Crear rol";
  $("#saveRoleButton .access-button-label").textContent = editing ? "Guardar cambios" : "Crear rol";
  $("#saveRoleButton").dataset.idleLabel = editing ? "Guardar cambios" : "Crear rol";
  const selectedCodes = new Set(role?.moduleCodes ?? []);
  $$('input[name="module_codes"]', form).forEach(input => { input.checked = selectedCodes.has(input.value); });
  openDialog($("#roleDialog"));
  window.setTimeout(() => $("#roleName").focus(), 0);
}

async function saveRole(event) {
  event.preventDefault();
  const form = event.currentTarget;
  clearFormErrors(form);
  clearAlert($("#roleFormMessage"));
  if (!form.reportValidity()) return;
  const selectedModules = $$('input[name="module_codes"]:checked', form);
  if (!selectedModules.length) {
    $('[data-error-for="module_codes"]', form).textContent = "Selecciona al menos un módulo.";
    $('input[name="module_codes"]', form)?.focus();
    return;
  }
  const id = $("#roleId").value;
  const editing = Boolean(id);
  const button = $("#saveRoleButton");
  setButtonBusy(button, true, editing ? "Guardando…" : "Creando…");
  try {
    await apiRequest(editing ? `/admin/roles/${encodeURIComponent(id)}` : "/admin/roles", {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify({
        name: $("#roleName").value.trim(),
        code: $("#roleCode").value.trim().toUpperCase(),
        module_codes: selectedModules.map(input => input.value),
      }),
    });
    closeDialog($("#roleDialog"));
    toast(editing ? "El rol se actualizó correctamente." : "El rol se creó correctamente.");
    await loadRoles();
  } catch (error) {
    showFormErrors(form, error?.data?.errors);
    setAlert($("#roleFormMessage"), messageFor(error));
    $("#roleFormMessage").focus();
  } finally {
    setButtonBusy(button, false);
  }
}

function openConfirmation({ title, message, buttonLabel = "Confirmar", action, danger = true }) {
  state.confirmAction = action;
  $("#confirmDialogTitle").textContent = title;
  $("#confirmDialogMessage").textContent = message;
  clearAlert($("#confirmDialogError"));
  const button = $("#confirmDialogButton");
  button.className = `btn ${danger ? "btn-danger" : "btn-primary"}`;
  $(".access-button-label", button).textContent = buttonLabel;
  button.dataset.idleLabel = buttonLabel;
  openDialog($("#confirmDialog"));
}

async function runConfirmation() {
  if (!state.confirmAction) return;
  const button = $("#confirmDialogButton");
  setButtonBusy(button, true, "Procesando…");
  clearAlert($("#confirmDialogError"));
  try {
    await state.confirmAction();
    state.confirmAction = null;
    closeDialog($("#confirmDialog"));
  } catch (error) {
    setAlert($("#confirmDialogError"), messageFor(error));
  } finally {
    setButtonBusy(button, false);
  }
}

function handleUserAction(button) {
  const user = state.users.find(candidate => candidate.id === Number(button.dataset.id));
  if (!user) return;
  switch (button.dataset.action) {
    case "edit-user":
      openUserForm(user);
      break;
    case "reset-password":
      openPasswordForm(user);
      break;
    case "revoke-sessions":
      openConfirmation({
        title: "Cerrar sesiones abiertas",
        message: `Se cerrarán todas las sesiones de ${user.name}. Deberá volver a ingresar con su contraseña.`,
        buttonLabel: "Cerrar sesiones",
        action: async () => {
          await apiRequest(`/admin/users/${user.id}/revoke-sessions`, { method: "POST" });
          toast("Las sesiones del usuario fueron cerradas.");
        },
      });
      break;
    case "toggle-status": {
      const activating = user.status !== "ACTIVO";
      openConfirmation({
        title: activating ? "Activar usuario" : "Desactivar usuario",
        message: activating
          ? `${user.name} recuperará el acceso según los roles asignados.`
          : `${user.name} perderá el acceso y se cerrarán sus sesiones abiertas.`,
        buttonLabel: activating ? "Activar" : "Desactivar",
        danger: !activating,
        action: async () => {
          await apiRequest(`/admin/users/${user.id}/status`, {
            method: "PATCH",
            body: JSON.stringify({ status: activating ? "ACTIVO" : "INACTIVO" }),
          });
          toast(activating ? "El usuario fue activado." : "El usuario fue desactivado.");
          await loadUsers();
        },
      });
      break;
    }
  }
}

function handleRoleAction(button) {
  const role = state.roles.find(candidate => candidate.id === Number(button.dataset.id));
  if (!role) return;
  if (button.dataset.action === "edit-role") {
    openRoleForm(role);
    return;
  }
  if (button.dataset.action === "delete-role") {
    openConfirmation({
      title: "Eliminar rol",
      message: role.usersCount
        ? `${role.name} está asignado a ${role.usersCount} usuario${role.usersCount === 1 ? "" : "s"}. Reasígnalos antes de eliminar el rol.`
        : `Se eliminará el rol ${role.name}. Esta acción no se puede deshacer.`,
      buttonLabel: "Eliminar rol",
      action: async () => {
        await apiRequest(`/admin/roles/${role.id}`, { method: "DELETE" });
        toast("El rol fue eliminado.");
        await loadRoles();
      },
    });
  }
}

function activateTab(name, focus = false) {
  const usersActive = name === "users";
  const usersTab = $("#usersTab");
  const rolesTab = $("#rolesTab");
  usersTab.classList.toggle("is-active", usersActive);
  rolesTab.classList.toggle("is-active", !usersActive);
  usersTab.setAttribute("aria-selected", String(usersActive));
  rolesTab.setAttribute("aria-selected", String(!usersActive));
  usersTab.tabIndex = usersActive ? 0 : -1;
  rolesTab.tabIndex = usersActive ? -1 : 0;
  $("#usersPanel").hidden = !usersActive;
  $("#rolesPanel").hidden = usersActive;
  if (focus) (usersActive ? usersTab : rolesTab).focus();
  const hash = usersActive ? "#usuarios" : "#roles";
  if (window.location.hash !== hash) history.replaceState(null, "", hash);
}

let searchTimer;
function applyFilters() {
  state.filters = {
    search: $("#userSearch").value.trim(),
    status: $("#userStatusFilter").value,
    roleId: $("#userRoleFilter").value,
    branchId: $("#userBranchFilter").value,
  };
  state.page = 1;
  loadUsers();
}

function bindEvents() {
  $$("[data-access-tab]").forEach(tab => tab.addEventListener("click", () => activateTab(tab.dataset.accessTab)));
  $(".access-tabs").addEventListener("keydown", event => {
    if (!["ArrowLeft", "ArrowRight"].includes(event.key)) return;
    event.preventDefault();
    activateTab($("#usersTab").getAttribute("aria-selected") === "true" ? "roles" : "users", true);
  });

  $("#createUserButton").addEventListener("click", () => openUserForm());
  $("#createRoleButton").addEventListener("click", () => openRoleForm());
  $("#userForm").addEventListener("submit", saveUser);
  $("#passwordForm").addEventListener("submit", savePassword);
  $("#roleForm").addEventListener("submit", saveRole);
  $("#confirmDialogButton").addEventListener("click", runConfirmation);

  $("#usersTableBody").addEventListener("click", event => {
    const button = event.target.closest("button[data-action]");
    if (button) handleUserAction(button);
  });
  $("#rolesGrid").addEventListener("click", event => {
    const button = event.target.closest("button[data-action]");
    if (button) handleRoleAction(button);
  });

  $("#roleName").addEventListener("input", event => {
    if (!state.roleCodeEdited) $("#roleCode").value = codeFromName(event.target.value);
  });
  $("#roleCode").addEventListener("input", event => {
    state.roleCodeEdited = true;
    const start = event.target.selectionStart;
    event.target.value = codeFromName(event.target.value);
    event.target.setSelectionRange(start, start);
  });
  $("#selectAllModules").addEventListener("click", () => $$('input[name="module_codes"]').forEach(input => { input.checked = true; }));
  $("#clearAllModules").addEventListener("click", () => $$('input[name="module_codes"]').forEach(input => { input.checked = false; }));

  $("#userSearch").addEventListener("input", () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(applyFilters, 300);
  });
  for (const id of ["#userStatusFilter", "#userRoleFilter", "#userBranchFilter"]) $(id).addEventListener("change", applyFilters);
  $("#userFilters").addEventListener("submit", event => { event.preventDefault(); applyFilters(); });
  $("#clearUserFilters").addEventListener("click", () => {
    $("#userFilters").reset();
    applyFilters();
  });
  $("#usersPreviousPage").addEventListener("click", () => { if (state.page > 1) { state.page -= 1; loadUsers(); } });
  $("#usersNextPage").addEventListener("click", () => { if (state.page < state.pagination.lastPage) { state.page += 1; loadUsers(); } });

  $$('[data-close-dialog]').forEach(button => button.addEventListener("click", () => closeDialog(document.getElementById(button.dataset.closeDialog))));
  $$("dialog.access-dialog").forEach(dialog => {
    dialog.addEventListener("close", refreshDialogBodyState);
    dialog.addEventListener("cancel", () => window.setTimeout(refreshDialogBodyState, 0));
    dialog.addEventListener("click", event => {
      if (event.target === dialog) closeDialog(dialog);
    });
  });

  $("#adminLogoutButton").addEventListener("click", async () => {
    const button = $("#adminLogoutButton");
    button.disabled = true;
    try { await logout(); } finally { window.location.assign(urls.login); }
  });

  window.addEventListener("auth:expired", () => {
    const redirect = encodeURIComponent(`${window.location.pathname}${window.location.search}${window.location.hash}`);
    window.location.assign(`${urls.login}?redirect=${redirect}`);
  }, { once: true });
}

async function initialize() {
  bindEvents();
  activateTab(window.location.hash === "#roles" ? "roles" : "users");
  clearAlert($("#roleListMessage"));
  try {
    await Promise.all([loadCurrentUser(), loadCatalog(), loadRoles()]);
    await loadUsers();
  } catch (error) {
    const target = window.location.hash === "#roles" ? $("#roleListMessage") : $("#userListMessage");
    setAlert(target, messageFor(error, "No fue posible cargar la administración de accesos."));
    $("#rolesLoading").hidden = true;
    $("#usersLoading").hidden = true;
  } finally {
    $("#rolesLoading").hidden = true;
  }
}

initialize();
