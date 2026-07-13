<header class="fin-header fin-card">
  <div class="fin-header-copy">
    <p class="fin-eyebrow">{{ $eyebrow ?? 'Control financiero' }}</p>
    <h1>{{ $title ?? 'Finanzas' }}</h1>
    <p>{{ $description ?? 'Controla el dinero recibido, los pagos y su recorrido hasta cada proveedor.' }}</p>
  </div>

  <div class="fin-header-actions">
    <button id="financeAccessButton" class="fin-session-status" type="button" aria-haspopup="dialog" aria-controls="financeAuthDialog">
      <span class="fin-session-dot" aria-hidden="true"></span>
      <span id="financeAccessLabel">Comprobando acceso</span>
    </button>
    <a class="menu-return-btn" href="{{ route('menu') }}" aria-label="Volver al menú principal">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M4 6h7v7H4z"></path>
        <path d="M13 6h7v7h-7z"></path>
        <path d="M4 15h7v3H4z"></path>
        <path d="M13 15h7v3h-7z"></path>
      </svg>
      <span>Menú</span>
    </a>
  </div>
</header>

<nav class="fin-nav fin-card" aria-label="Módulos financieros">
  <a class="fin-nav-link {{ ($active ?? '') === 'dashboard' ? 'is-active' : '' }}" href="{{ route('finanzas') }}" @if (($active ?? '') === 'dashboard') aria-current="page" @endif>
    <span aria-hidden="true">01</span>
    <strong>Saldos y trazabilidad</strong>
  </a>
  <a class="fin-nav-link {{ ($active ?? '') === 'entidades' ? 'is-active' : '' }}" href="{{ route('finanzas.entidades') }}" @if (($active ?? '') === 'entidades') aria-current="page" @endif>
    <span aria-hidden="true">02</span>
    <strong>Empresas y cuentas</strong>
  </a>
  <a class="fin-nav-link {{ ($active ?? '') === 'movimiento' ? 'is-active' : '' }}" href="{{ route('finanzas.movimientos.nuevo') }}" @if (($active ?? '') === 'movimiento') aria-current="page" @endif>
    <span aria-hidden="true">03</span>
    <strong>Registrar cobro o pago</strong>
  </a>
</nav>

<dialog id="financeAuthDialog" class="fin-auth-dialog" aria-labelledby="financeAuthTitle">
  <form id="financeAuthForm" class="fin-auth-card" method="dialog" novalidate>
    <div class="fin-auth-mark" aria-hidden="true">S/</div>
    <p class="fin-eyebrow">Área protegida</p>
    <h2 id="financeAuthTitle">Ingresa para continuar</h2>
    <p class="fin-auth-copy">Los saldos y movimientos financieros solo están disponibles para usuarios autorizados.</p>

    <label class="fin-field">
      <span>Correo electrónico</span>
      <input id="financeAuthEmail" type="email" autocomplete="username" required placeholder="usuario@empresa.com">
    </label>
    <label class="fin-field">
      <span>Contraseña</span>
      <input id="financeAuthPassword" type="password" autocomplete="current-password" required placeholder="Tu contraseña">
    </label>

    <p id="financeAuthMessage" class="fin-message" role="status" aria-live="polite"></p>
    <div class="fin-auth-actions">
      <button id="financeAuthSubmit" class="fin-btn fin-btn-primary" type="submit">Ingresar y reintentar</button>
      <a class="fin-btn fin-btn-ghost" href="{{ route('menu') }}">Volver al menú</a>
    </div>
  </form>
</dialog>
