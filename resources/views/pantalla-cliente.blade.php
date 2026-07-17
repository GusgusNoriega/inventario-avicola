<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Peso actual | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/pantalla-cliente.css') }}?v={{ filemtime(public_path('css/pantalla-cliente.css')) }}">
</head>
<body class="customer-display-page">
  <main class="customer-display-shell" aria-live="polite">
    <header class="customer-display-header">
      <div>
        <p class="customer-display-eyebrow">Pesada actual</p>
        <h1 id="customerDisplayName">Sin cliente asignado</h1>
      </div>
      <div class="customer-display-actions">
        <span id="customerDisplayStatus" class="customer-display-status is-waiting">Esperando despacho</span>
        <button id="customerDisplayFullscreen" type="button">Pantalla completa</button>
      </div>
    </header>

    <section class="customer-display-weight" aria-label="Peso actual">
      <span>Peso actual</span>
      <div><strong id="customerDisplayWeight">0.00</strong><small>kg</small></div>
    </section>

    <section class="customer-display-counts" aria-label="Cantidades de la pesada">
      <article>
        <span>Javas</span>
        <strong id="customerDisplayCages">0</strong>
      </article>
      <article>
        <span>Pollos</span>
        <strong id="customerDisplayBirds">0</strong>
      </article>
    </section>
  </main>

  <script type="module" src="{{ asset('js/pantalla-cliente.js') }}?v={{ filemtime(public_path('js/pantalla-cliente.js')) }}"></script>
</body>
</html>
