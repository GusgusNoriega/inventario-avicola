<!doctype html>
<html lang="es" class="access-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <title>Iniciar sesión | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/access-control.css') }}?v={{ filemtime(public_path('css/access-control.css')) }}">
</head>
<body
  class="access-page login-page"
  data-menu-url="{{ route('menu') }}"
  data-account-url="{{ url('/mi-cuenta') }}"
>
  <main class="login-shell">
    <section class="login-brand" aria-labelledby="loginWelcomeTitle">
      <div class="login-brand-mark" aria-hidden="true">
        <svg viewBox="0 0 64 64" focusable="false">
          <path d="M18 42c-6-7-5-18 3-24 7-6 18-5 24 2 5 6 6 15 1 22"></path>
          <path d="M23 17c-1-5 2-9 7-10 1 4 0 8-3 11"></path>
          <path d="M30 16c2-5 7-7 12-5-1 4-4 7-8 8"></path>
          <circle cx="41" cy="26" r="1.5"></circle>
          <path d="M47 29l8 3-8 4"></path>
          <path d="M15 49h35"></path>
          <path d="M23 43v10M41 43v10"></path>
        </svg>
      </div>
      <div>
        <p class="eyebrow">Sistema Pollos</p>
        <h1 id="loginWelcomeTitle">Todo tu negocio, en un solo lugar.</h1>
        <p>Ingresa para acceder de forma segura a los módulos habilitados para tu rol.</p>
      </div>
      <ul class="login-benefits" aria-label="Características del acceso">
        <li>
          <span aria-hidden="true">✓</span>
          <div><strong>Acceso por función</strong><small>Solo verás las áreas asignadas a tu trabajo.</small></div>
        </li>
        <li>
          <span aria-hidden="true">✓</span>
          <div><strong>Sesión protegida</strong><small>Tus credenciales nunca se muestran ni se comparten.</small></div>
        </li>
      </ul>
    </section>

    <section class="login-card card" aria-labelledby="loginTitle">
      <header class="login-card-head">
        <p class="eyebrow">Acceso seguro</p>
        <h2 id="loginTitle">Iniciar sesión</h2>
        <p>Usa tu correo electrónico o nombre de usuario.</p>
      </header>

      @if (session('status'))
        <div class="access-alert access-alert-success" role="status">{{ session('status') }}</div>
      @endif

      @if ($errors->any())
        <div id="loginServerMessage" class="access-alert access-alert-danger" role="alert">
          {{ $errors->first('login') ?: $errors->first('email') ?: $errors->first('password') ?: 'No fue posible iniciar sesión.' }}
        </div>
      @endif

      <form id="loginForm" class="access-form login-form" method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf
        <label class="access-field" for="loginIdentifier">
          <span>Correo o usuario</span>
          <span class="access-input-wrap">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="8" r="4"></circle>
              <path d="M4 21a8 8 0 0 1 16 0"></path>
            </svg>
            <input
              id="loginIdentifier"
              name="login"
              type="text"
              maxlength="180"
              autocomplete="username"
              autocapitalize="none"
              spellcheck="false"
              placeholder="correo@empresa.com"
              aria-describedby="loginIdentifierError"
              value="{{ old('login', old('email')) }}"
              @if ($errors->has('login') || $errors->has('email')) aria-invalid="true" @endif
              required
            >
          </span>
          <small id="loginIdentifierError" class="access-field-error" aria-live="polite">{{ $errors->first('login') ?: $errors->first('email') }}</small>
        </label>

        <label class="access-field" for="loginPassword">
          <span>Contraseña</span>
          <span class="access-input-wrap access-password-wrap">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <rect x="5" y="10" width="14" height="11" rx="2"></rect>
              <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
            </svg>
            <input
              id="loginPassword"
              name="password"
              type="password"
              maxlength="255"
              autocomplete="current-password"
              placeholder="Tu contraseña"
              aria-describedby="loginPasswordError loginCapsLock"
              @if ($errors->has('password')) aria-invalid="true" @endif
              required
            >
            <button
              id="loginPasswordToggle"
              class="access-password-toggle"
              type="button"
              aria-label="Mostrar contraseña"
              aria-pressed="false"
            >
              <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"></path>
                <circle cx="12" cy="12" r="2.5"></circle>
              </svg>
            </button>
          </span>
          <small id="loginCapsLock" class="access-field-hint" hidden>El bloqueo de mayúsculas está activado.</small>
          <small id="loginPasswordError" class="access-field-error" aria-live="polite">{{ $errors->first('password') }}</small>
        </label>

        <div id="loginMessage" class="access-alert access-alert-danger" role="alert" tabindex="-1" hidden></div>

        <button id="loginSubmit" class="btn btn-primary access-primary-action" type="submit">
          <span class="access-button-label">Ingresar al sistema</span>
          <span class="access-spinner" aria-hidden="true" hidden></span>
        </button>
      </form>

      <p class="login-help">Si no puedes ingresar, solicita a un administrador que revise tu estado o restablezca tu contraseña.</p>
      <noscript><p class="access-alert access-alert-danger">Debes habilitar JavaScript para iniciar sesión.</p></noscript>
    </section>
  </main>

  <script type="module" src="{{ asset('js/login.js') }}?v={{ filemtime(public_path('js/login.js')) }}"></script>
</body>
</html>
