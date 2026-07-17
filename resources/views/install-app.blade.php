<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Instalar aplicación | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
</head>
<body class="pwa-install-page">
  <main class="pwa-install-shell">
    <header class="pwa-install-header">
      <a class="menu-return-btn" href="{{ route('menu') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 6h7v7H4z"></path>
          <path d="M13 6h7v7h-7z"></path>
          <path d="M4 15h7v3H4z"></path>
          <path d="M13 15h7v3h-7z"></path>
        </svg>
        <span>Menú</span>
      </a>
      <div>
        <p class="eyebrow">Sistema Pollos</p>
        <h1>Instalar aplicación</h1>
        <p>Agrega el sistema a Windows para abrirlo como una aplicación independiente.</p>
      </div>
    </header>

    <section class="pwa-install-grid">
      <article class="pwa-install-card card" aria-labelledby="installPwaTitle">
        <img src="{{ asset('icons/icon-192.png') }}" width="112" height="112" alt="Icono de Sistema Pollos">
        <div>
          <p class="eyebrow">Instalación directa</p>
          <h2 id="installPwaTitle">Sistema Pollos para Windows</h2>
          <p>El botón abrirá la confirmación oficial de Edge o Chrome. No necesitas descargar ni ejecutar archivos adicionales.</p>
        </div>

        <button id="installPwaButton" class="pwa-install-button" type="button" disabled>
          Preparando instalación…
        </button>
        <p id="installPwaStatus" class="pwa-install-status" role="status" aria-live="polite"></p>

        <div id="installedPwaPanel" class="pwa-install-notice is-success" hidden>
          Esta aplicación ya está instalada. Puedes abrirla desde el menú Inicio o desde su icono en el escritorio.
        </div>

        <div id="waitingPwaPanel" class="pwa-install-notice" hidden>
          Si el botón no se habilita, abre esta página en Microsoft Edge o Google Chrome, recarga una vez y verifica que el sitio use HTTPS. También puedes elegir <strong>Instalar Sistema Pollos</strong> desde el menú del navegador.
        </div>
      </article>

      <aside class="pwa-install-info card">
        <h2>Servidor de la aplicación</h2>
        <a href="https://sada-csa.com/" target="_blank" rel="noopener noreferrer">https://sada-csa.com/</a>
        <ol>
          <li>Abre esta vista en Edge o Chrome.</li>
          <li>Presiona <strong>Instalar ahora</strong>.</li>
          <li>Confirma la instalación solicitada por el navegador.</li>
          <li>Abre Sistema Pollos desde Windows.</li>
        </ol>
        <p>Para una estación fija que nunca muestre la interfaz del navegador, continúa usando el acceso directo en modo kiosco.</p>
      </aside>
    </section>
  </main>

  <script src="{{ asset('js/install-app.js') }}?v={{ filemtime(public_path('js/install-app.js')) }}"></script>
</body>
</html>
