<!doctype html>
<html lang="es" class="access-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <title>Mi cuenta | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/access-control.css') }}?v={{ filemtime(public_path('css/access-control.css')) }}">
</head>
<body
  class="access-page account-page"
  data-login-url="{{ url('/login') }}"
  data-menu-url="{{ route('menu') }}"
>
  <main class="access-shell account-shell">
    <header class="access-hero card">
      <div class="access-hero-copy">
        <p class="eyebrow">Perfil y seguridad</p>
        <h1>Mi cuenta</h1>
        <p>Actualiza tus datos personales y protege el acceso al sistema.</p>
      </div>
      <div class="access-hero-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path><path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path><path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menú</span>
        </a>
        <button id="accountLogoutButton" class="btn btn-ghost access-logout-btn" type="button">Cerrar sesión</button>
      </div>
    </header>

    <div id="accountRequiredPasswordNotice" class="access-alert access-alert-warning account-required-notice" role="alert" hidden>
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l9 17H3z"></path><path d="M12 9v5"></path><path d="M12 17h.01"></path></svg>
      <div><strong>Debes crear una contraseña personal.</strong><span>Por seguridad, cambia la contraseña temporal antes de continuar al sistema.</span></div>
    </div>

    <section class="account-summary card" aria-label="Resumen de la cuenta">
      <div id="accountAvatar" class="account-avatar" aria-hidden="true">US</div>
      <div class="account-identity"><p class="eyebrow">Sesión actual</p><h2 id="accountDisplayName">Cargando…</h2><p id="accountDisplayEmail">—</p></div>
      <dl class="account-meta">
        <div><dt>Estado</dt><dd id="accountStatus"><span class="access-status is-active">Activo</span></dd></div>
        <div><dt>Sucursal</dt><dd id="accountBranch">Sin asignar</dd></div>
        <div><dt>Roles</dt><dd id="accountRoles">—</dd></div>
      </dl>
    </section>

    <div id="accountLoadMessage" class="access-alert access-alert-danger" role="alert" hidden></div>
    <section class="account-grid">
      <article class="account-card card" aria-labelledby="profileFormTitle">
        <header class="account-card-head">
          <span class="account-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span>
          <div><p class="eyebrow">Información de perfil</p><h2 id="profileFormTitle">Datos personales</h2><p>Estos datos identifican tu cuenta dentro de la empresa.</p></div>
        </header>
        <form id="profileForm" class="access-form account-form" novalidate>
          <label class="access-field" for="accountName"><span>Nombre completo <b aria-hidden="true">*</b></span><input id="accountName" name="name" type="text" maxlength="150" autocomplete="name" required><small class="access-field-error" data-error-for="name"></small></label>
          <label class="access-field" for="accountEmail"><span>Correo electrónico <b aria-hidden="true">*</b></span><input id="accountEmail" name="email" type="email" maxlength="180" autocomplete="email" required><small class="access-field-error" data-error-for="email"></small></label>
          <label class="access-field" for="accountBranchReadOnly"><span>Sucursal</span><input id="accountBranchReadOnly" type="text" value="Sin asignar" readonly aria-describedby="accountBranchHelp"><small id="accountBranchHelp" class="access-field-hint">Un administrador puede cambiar tu sucursal.</small></label>
          <div id="profileFormMessage" class="access-alert" role="status" tabindex="-1" hidden></div>
          <footer class="account-form-actions"><button id="saveProfileButton" class="btn btn-primary" type="submit"><span class="access-button-label">Guardar datos</span><span class="access-spinner" aria-hidden="true" hidden></span></button></footer>
        </form>
      </article>

      <article class="account-card card account-security-card" aria-labelledby="passwordFormTitle">
        <header class="account-card-head">
          <span class="account-card-icon account-security-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path><circle cx="12" cy="15" r="1"></circle></svg></span>
          <div><p class="eyebrow">Seguridad</p><h2 id="passwordFormTitle">Cambiar contraseña</h2><p>Usa una contraseña que no compartas con otros servicios.</p></div>
        </header>
        <form id="accountPasswordForm" class="access-form account-form" novalidate>
          <label class="access-field" for="currentPassword"><span>Contraseña actual <b aria-hidden="true">*</b></span><span class="access-password-wrap"><input id="currentPassword" name="current_password" type="password" maxlength="255" autocomplete="current-password" required><button class="access-password-toggle" type="button" data-password-toggle="currentPassword" aria-label="Mostrar contraseña" aria-pressed="false"><svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span><small class="access-field-error" data-error-for="current_password"></small></label>
          <div class="access-form-grid">
            <label class="access-field" for="newPassword"><span>Nueva contraseña <b aria-hidden="true">*</b></span><span class="access-password-wrap"><input id="newPassword" name="password" type="password" minlength="8" maxlength="255" autocomplete="new-password" required><button class="access-password-toggle" type="button" data-password-toggle="newPassword" aria-label="Mostrar contraseña" aria-pressed="false"><svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span><small class="access-field-hint">Mínimo 8 caracteres.</small><small class="access-field-error" data-error-for="password"></small></label>
            <label class="access-field" for="newPasswordConfirmation"><span>Confirmar contraseña <b aria-hidden="true">*</b></span><span class="access-password-wrap"><input id="newPasswordConfirmation" name="password_confirmation" type="password" minlength="8" maxlength="255" autocomplete="new-password" required><button class="access-password-toggle" type="button" data-password-toggle="newPasswordConfirmation" aria-label="Mostrar contraseña" aria-pressed="false"><svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></span><small class="access-field-error" data-error-for="password_confirmation"></small></label>
          </div>
          <label class="access-switch-row" for="accountRevokeSessions"><span><strong>Cerrar las demás sesiones</strong><small>Mantendremos abierta únicamente esta sesión.</small></span><input id="accountRevokeSessions" type="checkbox" checked><i aria-hidden="true"></i></label>
          <div id="accountPasswordMessage" class="access-alert" role="status" tabindex="-1" hidden></div>
          <footer class="account-form-actions"><button id="saveAccountPasswordButton" class="btn btn-primary" type="submit"><span class="access-button-label">Cambiar contraseña</span><span class="access-spinner" aria-hidden="true" hidden></span></button></footer>
        </form>
      </article>
    </section>

    <section class="account-session-card card" aria-labelledby="sessionsTitle">
      <div><p class="eyebrow">Sesiones</p><h2 id="sessionsTitle">¿Reconoces todos tus accesos?</h2><p>Si sospechas que alguien más usa tu cuenta, cierra todas las sesiones y vuelve a ingresar.</p></div>
      <button id="logoutAllButton" class="btn btn-danger" type="button"><span class="access-button-label">Cerrar todas las sesiones</span><span class="access-spinner" aria-hidden="true" hidden></span></button>
    </section>
  </main>

  <div id="accountToasts" class="access-toasts" aria-live="polite" aria-atomic="true"></div>
  <script type="module" src="{{ asset('js/account.js') }}?v={{ filemtime(public_path('js/account.js')) }}"></script>
</body>
</html>
