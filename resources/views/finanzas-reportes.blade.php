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
      <div>
        <p class="fin-eyebrow">Reportes adaptados al sistema actual</p>
        <h2>Sin zonas ni campos heredados</h2>
        <p>Las ventas se agrupan por cliente y producto. Los estados de cuenta incluyen el saldo anterior al periodo y todos los reportes excluyen registros anulados.</p>
      </div>
      <span class="report-intro-mark" aria-hidden="true">PDF</span>
    </section>

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
        <div class="report-card-heading"><span>04</span><div><h2>Pagos y cobros</h2><p>Listado general con filtros opcionales por tipo y metodo de pago.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'pagos') }}" target="_blank" class="report-form">
          <label class="fin-field"><span>Tipo</span><select name="tipo"><option value="">Todos</option>@foreach($paymentTypes as $paymentType)<option value="{{ $paymentType }}">{{ str_replace('_', ' ', $paymentType) }}</option>@endforeach</select></label>
          <label class="fin-field"><span>Metodo</span><select name="metodo_pago_id"><option value="">Todos</option>@foreach($paymentMethods as $method)<option value="{{ $method->id }}">{{ $method->nombre }}</option>@endforeach</select></label>
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'pagos'])
        </form>
      </article>

      <article class="report-card fin-card">
        <div class="report-card-heading"><span>05</span><div><h2>Movimientos por responsable</h2><p>Equivalente al reporte de cobrador, usando el usuario que registro cada movimiento.</p></div></div>
        <form method="GET" action="{{ route('finanzas.reportes.pdf', 'responsable') }}" target="_blank" class="report-form">
          <label class="fin-field report-wide"><span>Responsable</span><select name="usuario_id" required><option value="">Selecciona un usuario</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->nombre }}</option>@endforeach</select></label>
          @include('reports.partials.date-fields')
          @include('reports.partials.form-actions', ['reportType' => 'responsable'])
        </form>
      </article>
    </section>
  </main>
</body>
</html>
