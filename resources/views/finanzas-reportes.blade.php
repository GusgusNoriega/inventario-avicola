@php
  $reportPaletteDefaults = app(\App\Services\ReportPaletteService::class)->defaults();
  $reportPaletteErrors = $errors->getBag('reportPalette');
  $reportPaletteFormColors = [];

  foreach ($reportPaletteFields as $paletteKey => $paletteField) {
      $paletteFallback = $reportPalette[$paletteKey]
          ?? $reportPaletteDefaults[$paletteKey]
          ?? '#000000';
      $paletteCandidate = old("colors.{$paletteKey}", $paletteFallback);
      $reportPaletteFormColors[$paletteKey] = is_string($paletteCandidate)
          && preg_match('/^#[0-9A-Fa-f]{6}$/', $paletteCandidate) === 1
              ? strtoupper($paletteCandidate)
              : strtoupper($paletteFallback);
  }
@endphp
<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Reportes PDF | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/reportes.css') }}?v={{ filemtime(public_path('css/reportes.css')) }}">
</head>
<body class="fin-page">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'reportes',
      'eyebrow' => 'Informacion para decidir',
      'title' => 'Reportes PDF',
      'description' => 'Selecciona un periodo y genera reportes basados en las ventas, pesadas y movimientos financieros actuales.'
    ])

    <section class="report-intro fin-card">
      <div class="report-intro-copy">
        <p class="fin-eyebrow">Reportes adaptados al sistema actual</p>
        <h2>Sin zonas ni campos heredados</h2>
        <p>Las ventas se agrupan por cliente y producto. Los estados de cuenta incluyen el saldo anterior al periodo y todos los reportes excluyen registros anulados.</p>
      </div>
      <div class="report-intro-tools">
        <div class="report-current-palette" aria-label="Paleta actual de los reportes PDF">
          <span class="report-current-palette-label">Paleta actual</span>
          <span class="report-current-palette-swatches" aria-hidden="true">
            @foreach($reportPaletteFields as $paletteKey => $paletteField)
              @php
                $currentPaletteColor = strtoupper(
                    $reportPalette[$paletteKey] ?? $reportPaletteDefaults[$paletteKey] ?? '#000000'
                );
              @endphp
              <span
                class="report-current-palette-swatch"
                style="--report-current-color: {{ $currentPaletteColor }}"
                title="{{ $paletteField['label'] }}: {{ $currentPaletteColor }}"
              ></span>
            @endforeach
          </span>
        </div>
        @if($canConfigureReportPalette)
          <button
            id="reportPaletteOpen"
            class="fin-btn fin-btn-ghost report-palette-open"
            type="button"
            aria-haspopup="dialog"
            aria-controls="reportPaletteDialog"
          >Configurar colores</button>
        @endif
        <span class="report-intro-mark" aria-hidden="true">PDF</span>
      </div>
    </section>

    @if(session('report_palette_status'))
      <div class="report-palette-status fin-card" role="status" aria-live="polite">
        <span aria-hidden="true">✓</span>
        <p>{{ session('report_palette_status') }}</p>
      </div>
    @endif

    @if($errors->any())
      <div class="report-error fin-card" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="report-grid" aria-label="Reportes disponibles">
      <article class="report-card fin-card">
        <div class="report-card-heading"><span>01</span><div><h2>Ventas por cliente</h2><p>Pesos, aves, javas o bandejas, devoluciones y total vendido, sin zonas.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'ventas-clientes') }}" target="_blank" class="report-form">
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'ventas-clientes'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>02</span><div><h2>Estado de cuenta de cliente</h2><p>Ventas, devoluciones, cobros, saldo anterior y saldo acumulado.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'estado-cliente') }}" target="_blank" class="report-form">
          <label class="fin-field report-wide"><span>Cliente</span><select name="cliente_id" required><option value="">Selecciona un cliente</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->nombre_razon_social }}</option>@endforeach</select></label>
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'estado-cliente'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>03</span><div><h2>Estado de cuenta de proveedor</h2><p>Compras, abonos, pagos, saldo anterior y deuda acumulada.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'estado-proveedor') }}" target="_blank" class="report-form">
          <label class="fin-field report-wide"><span>Proveedor</span><select name="proveedor_id" required><option value="">Selecciona un proveedor</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->nombre_razon_social }}</option>@endforeach</select></label>
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'estado-proveedor'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>04</span><div><h2>Pagos y cobros</h2><p>Listado general con filtros opcionales por cuenta, usuario, tipo y método de pago.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'pagos') }}" target="_blank" class="report-form">
          <label class="fin-field"><span>Tipo</span><select name="tipo"><option value="">Todos</option>@foreach($paymentTypes as $paymentType)<option value="{{ $paymentType }}">{{ str_replace('_', ' ', $paymentType) }}</option>@endforeach</select></label>
          <label class="fin-field"><span>Metodo</span><select name="metodo_pago_id"><option value="">Todos</option>@foreach($paymentMethods as $method)<option value="{{ $method->id }}">{{ $method->nombre }}</option>@endforeach</select></label>
          @include('reports.partials.account-field')
          @include('reports.partials.user-field', ['required' => false, 'label' => 'Usuario responsable'])
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'pagos'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>05</span><div><h2>Movimientos por responsable</h2><p>Equivalente al reporte de cobrador, usando el usuario que registro cada movimiento.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'responsable') }}" target="_blank" class="report-form">
          @include('reports.partials.user-field', ['required' => true, 'label' => 'Responsable'])
          @include('reports.partials.account-field')
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'responsable'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>06</span><div><h2>Cuentas de clientes</h2><p>Deuda anterior, deuda del día o periodo, pagos realizados y deuda actual de todos los clientes.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'deuda-clientes') }}" target="_blank" class="report-form">
          <label class="fin-field report-wide"><span>Moneda</span><select name="moneda" required>@foreach($reportCurrencies as $currency)<option value="{{ $currency }}" @selected($currency === $defaultReportCurrency)>{{ $currency === 'PEN' ? 'Soles (PEN)' : ($currency === 'USD' ? 'Dólares (USD)' : $currency) }}</option>@endforeach</select></label>
          @include('reports.partials.date-fields')
          <p class="report-form-hint report-wide">Para consultar un solo día, selecciona la misma fecha en Desde y Hasta.</p>
          @include('reports.partials.form-actions', ['reportType' => 'deuda-clientes'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>07</span><div><h2>Ruta de cobranza 2</h2><p>Hoja diaria por cliente con saldo anterior, ventas, devoluciones, cobros y saldo acumulado.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'ruta-cobranza-2') }}" target="_blank" class="report-form">
          <label class="fin-field report-wide"><span>Fecha</span><input type="date" name="fecha" value="{{ $routeCollectionDate }}" required></label>
          <p class="report-form-hint report-wide">El corte incluye la fecha elegida y el día anterior, igual que el reporte de referencia.</p>
          @include('reports.partials.form-actions', ['reportType' => 'ruta-cobranza-2', 'showImage' => false])
        </form>
      </article>
    </section>
  </main>

  @if($canConfigureReportPalette)
    <dialog
      id="reportPaletteDialog"
      class="fin-purchase-dialog report-palette-dialog"
      aria-labelledby="reportPaletteTitle"
      aria-describedby="reportPaletteDescription"
    >
      <form
        id="reportPaletteForm"
        class="fin-purchase-dialog-card report-palette-dialog-card"
        method="POST"
        action="{{ route('finanzas.reportes.palette.update') }}"
        novalidate
      >
        @csrf
        @method('PUT')

        <header class="fin-purchase-dialog-head report-palette-dialog-head">
          <div>
            <p class="fin-eyebrow">Apariencia global</p>
            <h2 id="reportPaletteTitle">Paleta de los reportes PDF</h2>
            <p id="reportPaletteDescription" class="report-palette-dialog-copy">
              Estos colores se aplican a todos los PDF de Reportes. Revisa el ejemplo antes de guardar.
            </p>
          </div>
          <button class="fin-dialog-close" type="button" data-report-palette-close aria-label="Cerrar configuración de paleta">×</button>
        </header>

        <div class="report-palette-editor">
          <section class="report-palette-controls" aria-labelledby="reportPaletteControlsTitle">
            <div class="report-palette-section-head">
              <h3 id="reportPaletteControlsTitle">Colores</h3>
              <p>Selecciona cada muestra. El código hexadecimal se actualiza automáticamente.</p>
            </div>

            <div class="report-palette-fields">
              @foreach($reportPaletteFields as $paletteKey => $paletteField)
                @php
                  $paletteInputId = 'reportPaletteColor-'.str_replace('_', '-', $paletteKey);
                  $paletteHelpId = $paletteInputId.'-help';
                  $paletteErrorId = $paletteInputId.'-error';
                  $paletteHasError = $reportPaletteErrors->has("colors.{$paletteKey}");
                @endphp
                <label class="fin-field report-palette-field{{ $paletteHasError ? ' is-invalid' : '' }}" for="{{ $paletteInputId }}">
                  <span class="report-palette-field-heading">
                    <strong>{{ $paletteField['label'] }}</strong>
                    <output data-report-palette-value="{{ $paletteKey }}" for="{{ $paletteInputId }}">{{ $reportPaletteFormColors[$paletteKey] }}</output>
                  </span>
                  <span class="report-palette-picker">
                    <input
                      id="{{ $paletteInputId }}"
                      type="color"
                      name="colors[{{ $paletteKey }}]"
                      value="{{ $reportPaletteFormColors[$paletteKey] }}"
                      data-report-palette-color="{{ $paletteKey }}"
                      aria-describedby="{{ $paletteHelpId }}{{ $paletteHasError ? ' '.$paletteErrorId : '' }}"
                      @if($paletteHasError) aria-invalid="true" @endif
                    >
                    <span class="report-palette-picker-copy" id="{{ $paletteHelpId }}">{{ $paletteField['description'] }}</span>
                  </span>
                  @error("colors.{$paletteKey}", 'reportPalette')
                    <small id="{{ $paletteErrorId }}" class="report-palette-field-error">{{ $message }}</small>
                  @enderror
                </label>
              @endforeach
            </div>
          </section>

          <section class="report-palette-preview-panel" aria-labelledby="reportPalettePreviewTitle">
            <div class="report-palette-section-head">
              <h3 id="reportPalettePreviewTitle">Vista previa del reporte</h3>
              <p>Ejemplo representativo de encabezados, resúmenes y movimientos.</p>
            </div>
            <div
              id="reportPalettePreview"
              class="report-palette-preview"
              style="
                --report-palette-page-background: {{ $reportPaletteFormColors['page_background'] }};
                --report-palette-primary: {{ $reportPaletteFormColors['primary'] }};
                --report-palette-primary-text: {{ $reportPaletteFormColors['primary_text'] }};
                --report-palette-secondary: {{ $reportPaletteFormColors['secondary'] }};
                --report-palette-secondary-text: {{ $reportPaletteFormColors['secondary_text'] }};
                --report-palette-accent: {{ $reportPaletteFormColors['accent'] }};
                --report-palette-body-text: {{ $reportPaletteFormColors['body_text'] }};
                --report-palette-muted-text: {{ $reportPaletteFormColors['muted_text'] }};
                --report-palette-border: {{ $reportPaletteFormColors['border'] }};
                --report-palette-debit: {{ $reportPaletteFormColors['debit'] }};
                --report-palette-credit: {{ $reportPaletteFormColors['credit'] }};
              "
            >
              <div class="report-palette-preview-page">
                <header class="report-palette-preview-header">
                  <span>Distribuidora · Reportes</span>
                  <h4>Estado de cuenta</h4>
                  <p>Periodo: 01/08/2026 al 31/08/2026</p>
                </header>

                <div class="report-palette-preview-summary" aria-label="Resumen de ejemplo">
                  <article><span>Saldo anterior</span><strong>S/ 1,250.00</strong></article>
                  <article><span>Cargos</span><strong class="is-debit">S/ 840.00</strong></article>
                  <article><span>Abonos</span><strong class="is-credit">S/ 520.00</strong></article>
                </div>

                <div class="report-palette-preview-table-wrap">
                  <table class="report-palette-preview-table">
                    <thead><tr><th>Fecha</th><th>Detalle</th><th class="is-number">Cargo / abono</th></tr></thead>
                    <tbody>
                      <tr><td>03/08</td><td>Venta de pollo</td><td class="is-number is-debit">S/ 420.00</td></tr>
                      <tr><td>10/08</td><td>Cobro recibido</td><td class="is-number is-credit">S/ 300.00</td></tr>
                      <tr><td>17/08</td><td>Venta de pollo</td><td class="is-number is-debit">S/ 420.00</td></tr>
                    </tbody>
                  </table>
                </div>
                <p class="report-palette-preview-note">Los datos son ilustrativos; solo se guardarán los colores.</p>
              </div>
            </div>
          </section>
        </div>

        @if($reportPaletteErrors->any())
          <p class="fin-message is-error report-palette-form-error" role="alert">
            Revisa los colores marcados antes de guardar la paleta.
          </p>
        @endif

        <footer class="fin-purchase-dialog-actions report-palette-dialog-actions">
          <button id="reportPaletteReset" class="fin-btn fin-btn-ghost report-palette-reset" type="button">Restaurar valores originales</button>
          <button class="fin-btn fin-btn-ghost" type="button" data-report-palette-close>Cancelar</button>
          <button id="reportPaletteSave" class="fin-btn fin-btn-primary" type="submit">Guardar paleta</button>
        </footer>
      </form>
    </dialog>

    <script>
      (() => {
        const dialog = document.getElementById('reportPaletteDialog');
        const openButton = document.getElementById('reportPaletteOpen');
        const form = document.getElementById('reportPaletteForm');
        const preview = document.getElementById('reportPalettePreview');
        const resetButton = document.getElementById('reportPaletteReset');
        const saveButton = document.getElementById('reportPaletteSave');
        const inputs = Array.from(document.querySelectorAll('[data-report-palette-color]'));
        const defaults = @json($reportPaletteDefaults);
        const savedColors = @json($reportPalette);
        const shouldOpen = @json($reportPaletteErrors->any());

        if (!dialog || !openButton || !form || !preview || inputs.length === 0) return;

        const normalizeHex = (value) => /^#[0-9A-F]{6}$/i.test(String(value || ''))
          ? String(value).toUpperCase()
          : '#000000';
        const cssVariable = (key) => `--report-palette-${String(key).replaceAll('_', '-')}`;

        function renderInput(input) {
          const key = input.dataset.reportPaletteColor;
          const color = normalizeHex(input.value);
          input.value = color;
          preview.style.setProperty(cssVariable(key), color);
          const output = form.querySelector(`[data-report-palette-value="${key}"]`);
          if (output) output.textContent = color;
        }

        function openDialog() {
          if (!dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
          }
          window.requestAnimationFrame(() => inputs[0]?.focus());
        }

        function restoreSavedPalette() {
          inputs.forEach((input) => {
            const saved = savedColors[input.dataset.reportPaletteColor];
            if (saved) input.value = normalizeHex(saved);
            input.removeAttribute('aria-invalid');
            input.closest('.report-palette-field')?.classList.remove('is-invalid');
            renderInput(input);
          });
          form.querySelectorAll('.report-palette-field-error, .report-palette-form-error')
            .forEach((message) => message.remove());
        }

        function closeDialog() {
          restoreSavedPalette();
          if (typeof dialog.close === 'function') dialog.close();
          else dialog.removeAttribute('open');
          window.requestAnimationFrame(() => openButton.focus());
        }

        inputs.forEach((input) => {
          renderInput(input);
          input.addEventListener('input', () => renderInput(input));
          input.addEventListener('change', () => renderInput(input));
        });

        openButton.addEventListener('click', openDialog);
        document.querySelectorAll('[data-report-palette-close]').forEach((button) => {
          button.addEventListener('click', closeDialog);
        });

        resetButton?.addEventListener('click', () => {
          inputs.forEach((input) => {
            const original = defaults[input.dataset.reportPaletteColor];
            if (original) input.value = normalizeHex(original);
            renderInput(input);
          });
          inputs[0]?.focus();
        });

        dialog.addEventListener('cancel', (event) => {
          event.preventDefault();
          closeDialog();
        });
        dialog.addEventListener('click', (event) => {
          if (event.target !== dialog) return;
          const bounds = dialog.getBoundingClientRect();
          const inside = event.clientX >= bounds.left
            && event.clientX <= bounds.right
            && event.clientY >= bounds.top
            && event.clientY <= bounds.bottom;
          if (!inside) closeDialog();
        });

        form.addEventListener('submit', () => {
          form.setAttribute('aria-busy', 'true');
          if (saveButton) saveButton.disabled = true;
        });

        if (shouldOpen) window.requestAnimationFrame(openDialog);
      })();
    </script>
  @endif
</body>
</html>
