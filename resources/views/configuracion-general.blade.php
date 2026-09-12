<!doctype html>
<html lang="es" class="journey-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Configuración general | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/configuracion-general.css') }}">
</head>
<body class="journey-page general-settings-page">
  <main class="journey-shell general-settings-shell">
    <header class="journey-hero card">
      <div>
        <p class="eyebrow">Administración</p>
        <h1>Configuración general</h1>
        <p>Define el horario de la jornada para todas las sucursales de tu empresa.</p>
      </div>
      <div class="journey-header-actions">
        <a class="menu-return-btn" href="{{ route('menu') }}">Menú</a>
      </div>
    </header>

    <section class="journey-summary general-settings-summary" aria-label="Configuración vigente">
      <article class="journey-summary-card card">
        <span>Empresa</span>
        <strong id="settingsCompany">Cargando...</strong>
      </article>
      <article class="journey-summary-card card">
        <span>Hora vigente</span>
        <strong id="settingsCurrentCutoff">Cargando...</strong>
      </article>
      <article class="journey-summary-card card">
        <span>Zona horaria de la empresa</span>
        <strong id="settingsTimezone">Cargando...</strong>
      </article>
    </section>

    <section class="journey-global-prices card" aria-labelledby="settingsScheduleTitle">
      <div class="journey-global-prices-head">
        <div>
          <p class="eyebrow">Jornada operativa</p>
          <h2 id="settingsScheduleTitle">Horario de cierre y apertura</h2>
        </div>
      </div>
      <p id="settingsScheduleHelp" class="general-settings-copy">A esta hora se cierra una jornada y se abre la siguiente. Solo necesitas indicar una hora. El horario predeterminado es 9:00 p. m. (21:00).</p>
      <p class="general-settings-copy">Al guardar se recalculan todas las jornadas, incluidos los tickets anteriores de todos los módulos, la recepción de pollo vivo y los movimientos y conteos de javas. Se utiliza la hora local de cada sucursal.</p>
      <p class="general-settings-copy">Cada ticket se conserva completo en la jornada de su primera pesada. Si no tiene pesadas, se usa su fecha y hora de cierre o registro, según el tipo de ticket. Los importes, pagos y fechas reales de las operaciones se conservan.</p>
      <p class="general-settings-copy">También puedes guardar el mismo horario para actualizar el historial.</p>

      <form id="generalSettingsForm" class="general-settings-form" aria-busy="true">
        <div class="general-settings-editor">
          <label class="field" for="settingsCutoff">
            Hora de cierre y apertura
            <input id="settingsCutoff" name="cutoff" type="time" step="60" required disabled aria-describedby="settingsScheduleHelp settingsWindowExample">
          </label>
          <div class="general-settings-example">
            <span>Así quedará cada jornada</span>
            <p id="settingsWindowExample" aria-live="polite">Cargando el horario vigente...</p>
          </div>
        </div>
        <div class="journey-price-actions general-settings-actions">
          <p id="settingsStatus" class="journey-message" role="status" aria-live="polite">Cargando configuración...</p>
          <button id="settingsReload" class="btn btn-secondary" type="button" hidden>Volver a cargar</button>
          <button id="settingsSave" class="btn btn-success" type="submit" disabled>Guardar y recalcular jornadas</button>
        </div>
      </form>
    </section>
  </main>

  <script type="module" src="{{ asset('js/configuracion-general.js') }}"></script>
</body>
</html>
