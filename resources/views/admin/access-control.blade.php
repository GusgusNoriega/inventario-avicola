<!doctype html>
<html lang="es" class="access-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <title>Usuarios y roles | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/access-control.css') }}?v={{ filemtime(public_path('css/access-control.css')) }}">
</head>
<body
  class="access-page access-admin-page"
  data-login-url="{{ url('/login') }}"
  data-menu-url="{{ route('menu') }}"
  data-account-url="{{ url('/mi-cuenta') }}"
>
  <main class="access-shell">
    <header class="access-hero card">
      <div class="access-hero-copy">
        <p class="eyebrow">Administración de acceso</p>
        <h1>Usuarios y roles</h1>
        <p>Crea cuentas, asigna roles y decide qué módulos puede administrar cada equipo.</p>
      </div>
      <div class="access-hero-user">
        <div class="access-current-user" aria-live="polite">
          <span id="currentUserInitials" aria-hidden="true">US</span>
          <div><strong id="currentUserName">Usuario</strong><small id="currentUserRole">Sesión activa</small></div>
        </div>
        <div class="access-hero-actions">
          <a class="menu-return-btn" href="{{ url('/mi-cuenta') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
            </svg>
            <span>Mi cuenta</span>
          </a>
          <a class="menu-return-btn" href="{{ route('menu') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M4 6h7v7H4z"></path><path d="M13 6h7v7h-7z"></path>
              <path d="M4 15h7v3H4z"></path><path d="M13 15h7v3h-7z"></path>
            </svg>
            <span>Menú</span>
          </a>
          <button id="adminLogoutButton" class="btn btn-ghost access-logout-btn" type="button">Cerrar sesión</button>
        </div>
      </div>
    </header>

    <section class="access-summary" aria-label="Resumen de accesos">
      <article class="access-summary-card card">
        <span class="access-summary-icon access-summary-icon-users" aria-hidden="true">
          <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"></circle><path d="M2 21a7 7 0 0 1 14 0"></path><path d="M16 5a4 4 0 0 1 0 7"></path><path d="M17 15a6 6 0 0 1 5 6"></path></svg>
        </span>
        <div><small>Usuarios</small><strong id="accessUserCount">—</strong><span id="accessActiveUserCount">Cargando…</span></div>
      </article>
      <article class="access-summary-card card">
        <span class="access-summary-icon access-summary-icon-roles" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 3l8 4v5c0 5-3 8-8 10-5-2-8-5-8-10V7z"></path><path d="M9 12l2 2 4-5"></path></svg>
        </span>
        <div><small>Roles configurados</small><strong id="accessRoleCount">—</strong><span>Permisos agrupados por módulo</span></div>
      </article>
      <article class="access-summary-card card">
        <span class="access-summary-icon access-summary-icon-modules" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
        </span>
        <div><small>Módulos disponibles</small><strong id="accessModuleCount">—</strong><span>Áreas principales del sistema</span></div>
      </article>
    </section>

    <section class="access-workspace card" aria-labelledby="accessWorkspaceTitle">
      <h2 id="accessWorkspaceTitle" class="sr-only">Administración de usuarios y roles</h2>
      <nav class="access-tabs" role="tablist" aria-label="Tipo de administración">
        <button id="usersTab" class="access-tab is-active" type="button" role="tab" aria-selected="true" aria-controls="usersPanel" data-access-tab="users">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="4"></circle><path d="M2 21a7 7 0 0 1 14 0"></path><path d="M16 5a4 4 0 0 1 0 7"></path><path d="M17 15a6 6 0 0 1 5 6"></path></svg>
          Usuarios
        </button>
        <button id="rolesTab" class="access-tab" type="button" role="tab" aria-selected="false" aria-controls="rolesPanel" data-access-tab="roles" tabindex="-1">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l8 4v5c0 5-3 8-8 10-5-2-8-5-8-10V7z"></path><path d="M9 12l2 2 4-5"></path></svg>
          Roles y módulos
        </button>
      </nav>

      <section id="usersPanel" class="access-panel" role="tabpanel" aria-labelledby="usersTab">
        <header class="access-panel-head">
          <div>
            <p class="eyebrow">Cuentas de la empresa</p>
            <h2>Usuarios</h2>
            <p>Administra los datos, estado, roles y sesiones de cada persona.</p>
          </div>
          <button id="createUserButton" class="btn btn-primary access-create-btn" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="4"></circle><path d="M2 21a7 7 0 0 1 14 0"></path><path d="M19 8v8M15 12h8"></path></svg>
            Nuevo usuario
          </button>
        </header>

        <form id="userFilters" class="access-filters" role="search">
          <label class="access-search" for="userSearch">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="7.5"></circle><path d="M16 16l5 5"></path></svg>
            <span class="sr-only">Buscar usuarios</span>
            <input id="userSearch" type="search" maxlength="180" autocomplete="off" placeholder="Buscar por nombre o correo">
          </label>
          <label class="access-filter-field" for="userStatusFilter">
            <span class="sr-only">Filtrar por estado</span>
            <select id="userStatusFilter">
              <option value="">Todos los estados</option>
              <option value="ACTIVO">Activos</option>
              <option value="INACTIVO">Inactivos</option>
            </select>
          </label>
          <label class="access-filter-field" for="userRoleFilter">
            <span class="sr-only">Filtrar por rol</span>
            <select id="userRoleFilter"><option value="">Todos los roles</option></select>
          </label>
          <label class="access-filter-field" for="userBranchFilter">
            <span class="sr-only">Filtrar por sucursal</span>
            <select id="userBranchFilter"><option value="">Todas las sucursales</option></select>
          </label>
          <button id="clearUserFilters" class="btn btn-ghost" type="button">Limpiar</button>
        </form>

        <div id="userListMessage" class="access-alert access-alert-danger" role="alert" hidden></div>
        <div class="access-table-wrap">
          <table class="access-table">
            <caption class="sr-only">Listado de usuarios de la empresa</caption>
            <thead>
              <tr><th scope="col">Usuario</th><th scope="col">Sucursal</th><th scope="col">Roles</th><th scope="col">Último acceso</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr>
            </thead>
            <tbody id="usersTableBody"></tbody>
          </table>
          <div id="usersLoading" class="access-loading" role="status"><span class="access-spinner" aria-hidden="true"></span>Cargando usuarios…</div>
          <div id="usersEmpty" class="access-empty" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="4"></circle><path d="M2 21a7 7 0 0 1 14 0"></path><path d="M17 10h5"></path></svg>
            <strong>No encontramos usuarios</strong><span>Prueba con otros filtros o crea una cuenta nueva.</span>
          </div>
        </div>
        <footer class="access-pagination">
          <span id="usersPaginationSummary">Sin resultados</span>
          <div>
            <button id="usersPreviousPage" class="btn btn-ghost" type="button" disabled>Anterior</button>
            <span id="usersPageIndicator" aria-live="polite">Página 1 de 1</span>
            <button id="usersNextPage" class="btn btn-ghost" type="button" disabled>Siguiente</button>
          </div>
        </footer>
      </section>

      <section id="rolesPanel" class="access-panel" role="tabpanel" aria-labelledby="rolesTab" hidden>
        <header class="access-panel-head">
          <div>
            <p class="eyebrow">Permisos sencillos</p>
            <h2>Roles y módulos</h2>
            <p>Al habilitar un módulo, el rol obtiene acceso a todas sus vistas y operaciones internas.</p>
          </div>
          <button id="createRoleButton" class="btn btn-primary access-create-btn" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 3l7 3v5c0 4-2 7-7 9-4-2-7-5-7-9V6z"></path><path d="M20 9v8M16 13h8"></path></svg>
            Nuevo rol
          </button>
        </header>
        <div id="roleListMessage" class="access-alert access-alert-danger" role="alert" hidden></div>
        <div id="rolesLoading" class="access-loading" role="status"><span class="access-spinner" aria-hidden="true"></span>Cargando roles…</div>
        <div id="rolesGrid" class="access-role-grid" aria-live="polite"></div>
        <div id="rolesEmpty" class="access-empty" hidden>
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l8 4v5c0 5-3 8-8 10-5-2-8-5-8-10V7z"></path></svg>
          <strong>No hay roles configurados</strong><span>Crea el primer rol y elige sus módulos.</span>
        </div>
      </section>
    </section>
  </main>

  <dialog id="userDialog" class="access-dialog" aria-labelledby="userDialogTitle">
    <form id="userForm" class="access-dialog-card" novalidate>
      <header class="access-dialog-head">
        <div><p id="userDialogEyebrow" class="eyebrow">Nueva cuenta</p><h2 id="userDialogTitle">Crear usuario</h2></div>
        <button class="access-dialog-close" type="button" data-close-dialog="userDialog" aria-label="Cerrar">×</button>
      </header>
      <div class="access-dialog-body">
        <input id="userId" type="hidden">
        <div class="access-form-grid">
          <label class="access-field access-field-wide" for="userName"><span>Nombre completo <b aria-hidden="true">*</b></span><input id="userName" name="name" type="text" maxlength="150" autocomplete="name" required><small class="access-field-error" data-error-for="name"></small></label>
          <label class="access-field access-field-wide" for="userEmail"><span>Correo electrónico <b aria-hidden="true">*</b></span><input id="userEmail" name="email" type="email" maxlength="180" autocomplete="email" required><small class="access-field-error" data-error-for="email"></small></label>
          <label class="access-field" for="userBranch"><span>Sucursal</span><select id="userBranch" name="branch_id"><option value="">Sin sucursal asignada</option></select><small class="access-field-error" data-error-for="branch_id"></small></label>
          <label class="access-field" for="userStatus"><span>Estado <b aria-hidden="true">*</b></span><select id="userStatus" name="status" required><option value="ACTIVO">Activo</option><option value="INACTIVO">Inactivo</option></select><small class="access-field-error" data-error-for="status"></small></label>
        </div>

        <fieldset class="access-check-section">
          <legend>Roles asignados <b aria-hidden="true">*</b></legend>
          <p>Los módulos disponibles serán la suma de todos los roles elegidos.</p>
          <div id="userRoleOptions" class="access-choice-grid"></div>
          <small class="access-field-error" data-error-for="role_ids"></small>
        </fieldset>

        <section id="newUserPasswordFields" class="access-password-section" aria-labelledby="newUserPasswordTitle">
          <div><h3 id="newUserPasswordTitle">Contraseña inicial</h3><p>El usuario deberá reemplazarla cuando ingrese por primera vez.</p></div>
          <div class="access-form-grid">
            <label class="access-field" for="userPassword"><span>Contraseña <b aria-hidden="true">*</b></span><input id="userPassword" name="password" type="password" minlength="8" maxlength="255" autocomplete="new-password"><small class="access-field-hint">Mínimo 8 caracteres.</small><small class="access-field-error" data-error-for="password"></small></label>
            <label class="access-field" for="userPasswordConfirmation"><span>Confirmar contraseña <b aria-hidden="true">*</b></span><input id="userPasswordConfirmation" name="password_confirmation" type="password" minlength="8" maxlength="255" autocomplete="new-password"><small class="access-field-error" data-error-for="password_confirmation"></small></label>
          </div>
          <label class="access-switch-row" for="userMustChangePassword"><span><strong>Exigir cambio al iniciar sesión</strong><small>Protege la cuenta aunque la contraseña inicial haya sido compartida.</small></span><input id="userMustChangePassword" type="checkbox" checked><i aria-hidden="true"></i></label>
        </section>

        <div id="userFormMessage" class="access-alert access-alert-danger" role="alert" tabindex="-1" hidden></div>
      </div>
      <footer class="access-dialog-actions">
        <button class="btn btn-ghost" type="button" data-close-dialog="userDialog">Cancelar</button>
        <button id="saveUserButton" class="btn btn-primary" type="submit"><span class="access-button-label">Crear usuario</span><span class="access-spinner" aria-hidden="true" hidden></span></button>
      </footer>
    </form>
  </dialog>

  <dialog id="roleDialog" class="access-dialog access-dialog-wide" aria-labelledby="roleDialogTitle">
    <form id="roleForm" class="access-dialog-card" novalidate>
      <header class="access-dialog-head">
        <div><p id="roleDialogEyebrow" class="eyebrow">Nuevo perfil</p><h2 id="roleDialogTitle">Crear rol</h2></div>
        <button class="access-dialog-close" type="button" data-close-dialog="roleDialog" aria-label="Cerrar">×</button>
      </header>
      <div class="access-dialog-body">
        <input id="roleId" type="hidden">
        <div class="access-form-grid">
          <label class="access-field" for="roleName"><span>Nombre del rol <b aria-hidden="true">*</b></span><input id="roleName" name="name" type="text" maxlength="100" autocomplete="off" placeholder="Ej: Encargado de finanzas" required><small class="access-field-error" data-error-for="name"></small></label>
          <label class="access-field" for="roleCode"><span>Código <b aria-hidden="true">*</b></span><input id="roleCode" name="code" type="text" maxlength="50" autocomplete="off" placeholder="ENCARGADO_FINANZAS" pattern="[A-Z][A-Z0-9_]*" required><small class="access-field-hint">Empieza con una letra; usa mayúsculas, números y guion bajo.</small><small class="access-field-error" data-error-for="code"></small></label>
        </div>
        <fieldset class="access-check-section access-module-section">
          <legend>Módulos permitidos</legend>
          <div class="access-module-toolbar"><p>El rol tendrá control completo de cada módulo seleccionado.</p><div><button id="selectAllModules" class="access-text-button" type="button">Seleccionar todos</button><span aria-hidden="true">·</span><button id="clearAllModules" class="access-text-button" type="button">Quitar todos</button></div></div>
          <div id="roleModuleOptions" class="access-module-grid"></div>
          <small class="access-field-error" data-error-for="module_codes"></small>
        </fieldset>
        <div id="roleFormMessage" class="access-alert access-alert-danger" role="alert" tabindex="-1" hidden></div>
      </div>
      <footer class="access-dialog-actions">
        <button class="btn btn-ghost" type="button" data-close-dialog="roleDialog">Cancelar</button>
        <button id="saveRoleButton" class="btn btn-primary" type="submit"><span class="access-button-label">Crear rol</span><span class="access-spinner" aria-hidden="true" hidden></span></button>
      </footer>
    </form>
  </dialog>

  <dialog id="passwordDialog" class="access-dialog access-dialog-compact" aria-labelledby="passwordDialogTitle">
    <form id="passwordForm" class="access-dialog-card" novalidate>
      <header class="access-dialog-head">
        <div><p class="eyebrow">Acceso temporal</p><h2 id="passwordDialogTitle">Restablecer contraseña</h2><p id="passwordDialogUser"></p></div>
        <button class="access-dialog-close" type="button" data-close-dialog="passwordDialog" aria-label="Cerrar">×</button>
      </header>
      <div class="access-dialog-body">
        <input id="passwordUserId" type="hidden">
        <label class="access-field" for="resetPassword"><span>Nueva contraseña <b aria-hidden="true">*</b></span><input id="resetPassword" name="password" type="password" minlength="8" maxlength="255" autocomplete="new-password" required><small class="access-field-hint">Mínimo 8 caracteres.</small><small class="access-field-error" data-error-for="password"></small></label>
        <label class="access-field" for="resetPasswordConfirmation"><span>Confirmar contraseña <b aria-hidden="true">*</b></span><input id="resetPasswordConfirmation" name="password_confirmation" type="password" minlength="8" maxlength="255" autocomplete="new-password" required><small class="access-field-error" data-error-for="password_confirmation"></small></label>
        <label class="access-switch-row" for="resetMustChangePassword"><span><strong>Exigir cambio al iniciar sesión</strong><small>Recomendado para una contraseña entregada por un administrador.</small></span><input id="resetMustChangePassword" type="checkbox" checked><i aria-hidden="true"></i></label>
        <div class="access-alert access-alert-info">Las sesiones abiertas se cerrarán automáticamente para proteger la cuenta.</div>
        <div id="passwordFormMessage" class="access-alert access-alert-danger" role="alert" tabindex="-1" hidden></div>
      </div>
      <footer class="access-dialog-actions">
        <button class="btn btn-ghost" type="button" data-close-dialog="passwordDialog">Cancelar</button>
        <button id="savePasswordButton" class="btn btn-primary" type="submit"><span class="access-button-label">Restablecer contraseña</span><span class="access-spinner" aria-hidden="true" hidden></span></button>
      </footer>
    </form>
  </dialog>

  <dialog id="confirmDialog" class="access-dialog access-dialog-compact" aria-labelledby="confirmDialogTitle">
    <section class="access-dialog-card">
      <header class="access-dialog-head"><div><p class="eyebrow">Confirmación</p><h2 id="confirmDialogTitle">Confirmar acción</h2></div><button class="access-dialog-close" type="button" data-close-dialog="confirmDialog" aria-label="Cerrar">×</button></header>
      <div class="access-dialog-body"><p id="confirmDialogMessage" class="access-confirm-copy"></p><div id="confirmDialogError" class="access-alert access-alert-danger" role="alert" hidden></div></div>
      <footer class="access-dialog-actions"><button class="btn btn-ghost" type="button" data-close-dialog="confirmDialog">Cancelar</button><button id="confirmDialogButton" class="btn btn-danger" type="button"><span class="access-button-label">Confirmar</span><span class="access-spinner" aria-hidden="true" hidden></span></button></footer>
    </section>
  </dialog>

  <div id="accessToasts" class="access-toasts" aria-live="polite" aria-atomic="true"></div>
  <script type="module" src="{{ asset('js/access-control.js') }}?v={{ filemtime(public_path('js/access-control.js')) }}"></script>
</body>
</html>
