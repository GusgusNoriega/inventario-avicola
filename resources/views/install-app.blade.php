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

    <section id="ticketPrinterSetup" class="pwa-printer-setup card" aria-labelledby="ticketPrinterSetupTitle">
      <header class="pwa-printer-header">
        <div>
          <p class="eyebrow">Tickets de despacho minorista</p>
          <h2 id="ticketPrinterSetupTitle">Configurar impresión</h2>
          <p>Prepara una impresora térmica para imprimir desde <a href="https://sada-csa.com/" target="_blank" rel="noopener noreferrer">https://sada-csa.com/</a>.</p>
        </div>
        <span class="pwa-printer-platform">Windows · Edge o Chrome</span>
      </header>

      <div class="pwa-printer-grid">
        <div class="pwa-printer-guide">
          <div class="pwa-printer-limit">
            <strong>La aplicación web no puede ver ni cambiar las impresoras de esta computadora.</strong>
            <p>La selección se realiza localmente en Windows. El sistema solo genera el ticket y solicita su impresión.</p>
          </div>

          <div class="pwa-printer-actions">
            <a class="pwa-printer-action is-primary" href="ms-settings:printers">
              Abrir impresoras de Windows
            </a>
            <a class="pwa-printer-action" href="{{ route('install-app.printer-installer') }}" download>
              Descargar configurador
            </a>
          </div>

          <p class="pwa-printer-action-help">
            Windows o el navegador pueden pedir confirmación antes de abrir la configuración. El configurador usa Chrome si está instalado y Edge como respaldo; crea un acceso directo para este dominio y no modifica tickets ni datos de ventas.
          </p>

          <div class="pwa-printer-command">
            <span>Después de descargar, abre PowerShell en la carpeta Descargas y ejecuta:</span>
            <code>powershell.exe -ExecutionPolicy Bypass -File .\Configurar-Impresion-Sistema-Pollos.ps1 -DirectPrint</code>
          </div>
        </div>

        <div class="pwa-printer-steps">
          <h3>Preparación en esta computadora</h3>
          <ol>
            <li>Conecta e instala la impresora térmica y configura papel de 80 mm.</li>
            <li>Abre <strong>Impresoras y escáneres</strong>, selecciona la térmica y establécela como predeterminada.</li>
            <li>Descarga el configurador y ejecútalo con PowerShell usando la opción <code>-DirectPrint</code>.</li>
            <li>Abre el acceso directo creado e inicia sesión una vez.</li>
          </ol>
        </div>
      </div>

      <aside class="pwa-printer-fallback">
        <strong>Impresión de respaldo</strong>
        <p>Si el configurador no encuentra una impresora predeterminada válida, no activa la impresión silenciosa. Los tickets seguirán abriendo la ventana normal para elegir una impresora.</p>
      </aside>
    </section>
  </main>

  <script src="{{ asset('js/install-app.js') }}?v={{ filemtime(public_path('js/install-app.js')) }}"></script>
</body>
</html>
