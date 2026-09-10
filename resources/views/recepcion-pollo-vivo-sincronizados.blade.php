<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registros sincronizados | Recepción de pollo vivo</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <style>
    html, body { height: auto; min-height: 100%; overflow: auto; }
    body{background:#f3f6fa;color:#152c46}.sync-shell{max-width:1440px;margin:auto;padding:24px}.sync-header,.sync-filters,.sync-summary,.sync-list{padding:24px;margin-bottom:20px;border-radius:18px;background:#fff;border:1px solid #dfe7ef}.sync-header{display:flex;align-items:center;justify-content:space-between;gap:20px}.sync-header h1{margin:6px 0}.sync-header p,.sync-note{color:#596b7e;line-height:1.5}.sync-actions{display:flex;gap:12px;flex-wrap:wrap}.sync-filters{display:flex;gap:16px;align-items:end;flex-wrap:wrap}.sync-filters label{display:grid;gap:6px;font-weight:600}.sync-filters input,.sync-filters select{padding:11px;border:1px solid #cbd7e4;border-radius:8px;background:white;color:#152c46;font:inherit}.sync-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}.sync-summary dt{color:#596b7e}.sync-summary dd{font-size:26px;font-weight:700;margin:6px 0}.sync-record{border-top:1px solid #dfe7ef;padding:18px 0}.sync-record:first-of-type{border-top:0}.sync-record summary{cursor:pointer;display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.sync-record summary strong{display:block;margin-bottom:4px}.sync-record small{color:#596b7e}.sync-badge{display:inline-block;padding:5px 10px;border-radius:20px;background:#e5f5ed;color:#1e6746;font-size:13px}.sync-badge.is-voided{background:#fbe9e9;color:#972f2f}.sync-details{padding-top:20px}.sync-details dl{display:flex;flex-wrap:wrap;gap:16px 32px}.sync-details dt{font-size:13px;color:#596b7e}.sync-details dd{margin:4px 0;font-weight:600;overflow-wrap:anywhere}.sync-table-wrap{overflow-x:auto}.sync-table{border-collapse:collapse;width:100%;font-size:14px}.sync-table th,.sync-table td{text-align:left;padding:12px 8px;border-bottom:1px solid #e5ebf2;white-space:nowrap}.sync-table th{background:#f5f8fb}.sync-empty{padding:32px;text-align:center;color:#596b7e}.sync-pagination{display:flex;justify-content:space-between;gap:16px;align-items:center}.sync-errors{color:#972f2f;background:#fff0f0;padding:18px;border-radius:10px;margin-bottom:20px}.sync-notes{white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:700px){.sync-shell{padding:12px}.sync-header{display:block}.sync-summary{grid-template-columns:repeat(2,1fr)}.sync-header,.sync-filters,.sync-summary,.sync-list{padding:18px}.sync-actions{margin-top:14px}.sync-filters label{width:100%}}@media print{body{background:white}.sync-shell{padding:0}.sync-filters,.sync-actions,.sync-pagination,.system-credit{display:none!important}.sync-header,.sync-summary,.sync-list{border:0;padding:8px}.sync-record{break-inside:avoid}.sync-record summary{list-style:none}.sync-details{display:block}.sync-note{font-size:12px}}
  </style>
</head>
<body>
  <main class="sync-shell">
    <header class="sync-header">
      <div>
        <p class="eyebrow">Recepción de pollo vivo · {{ $branch->nombre }}</p>
        <h1>Registros sincronizados</h1>
        <p>Recepciones y tickets enviados desde las aplicaciones conectadas a esta sucursal.</p>
      </div>
      <div class="sync-actions">
        <button type="button" class="btn btn-primary" onclick="document.querySelectorAll('details.sync-record').forEach(item => item.open = true); window.print()">Imprimir esta página</button>
        <a class="btn btn-ghost" href="{{ route('recepcion-pollo-vivo.menu') }}">Opciones de recepción</a>
      </div>
    </header>

    @if($errors->any())
      <div class="sync-errors" role="alert"><strong>Revisa los filtros.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="get" class="sync-filters">
      <label>Desde<input type="date" name="date_from" value="{{ $filters['date_from'] }}" required></label>
      <label>Hasta<input type="date" name="date_to" value="{{ $filters['date_to'] }}" required></label>
      <label>Tipo<select name="kind"><option value="">Recepciones y tickets</option><option value="reception" @selected(($filters['kind'] ?? '') === 'reception')>Recepciones</option><option value="ticket" @selected(($filters['kind'] ?? '') === 'ticket')>Tickets</option></select></label>
      <label>Estado<select name="status"><option value="">Activos y anulados</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activos</option><option value="voided" @selected(($filters['status'] ?? '') === 'voided')>Anulados</option></select></label>
      <button class="btn btn-primary" type="submit">Consultar</button>
    </form>

    <dl class="sync-summary" aria-label="Totales del filtro seleccionado">
      <div><dt>Registros activos</dt><dd>{{ number_format($summary['active_records'], 0, ',', '.') }}</dd><small>{{ $summary['voided_records'] }} anulados</small></div>
      <div><dt>Pollos</dt><dd>{{ number_format($summary['birds'], 0, ',', '.') }}</dd></div>
      <div><dt>Javas registradas</dt><dd>{{ number_format($summary['cages'], 0, ',', '.') }}</dd></div>
      <div><dt>Peso neto</dt><dd>{{ number_format($summary['net_weight_kg'], 3, ',', '.') }} kg</dd></div>
    </dl>

    <section class="sync-list" aria-labelledby="syncRecordTitle">
      <h2 id="syncRecordTitle">Del {{ $filters['date_from'] }} al {{ $filters['date_to'] }}</h2>
      <p class="sync-note">Los totales incluyen todos los registros activos que coinciden con los filtros. La impresión contiene únicamente los registros de esta página.</p>
      @forelse($records as $record)
        @php($payload = $record->payload)
        <details class="sync-record">
          <summary>
            <div><strong>{{ $record->kind === 'ticket' ? 'Ticket' : 'Recepción' }} {{ $payload['local_number'] ?? substr($record->uuid, 0, 8) }}</strong><small>{{ $record->operating_date }} · {{ $payload['destination']['name'] ?? ('Destino #'.($payload['destination_id'] ?? '')) }} · Carril {{ $payload['lane'] ?? '—' }}</small></div>
            <div><strong>{{ number_format($payload['totals']['net_weight_kg'] ?? 0, 3, ',', '.') }} kg · {{ number_format($payload['totals']['birds'] ?? 0, 0, ',', '.') }} pollos</strong><span class="sync-badge {{ $record->status === 'voided' ? 'is-voided' : '' }}">{{ $record->status === 'voided' ? 'Anulado' : 'Activo' }}</span> <small>Revisión {{ $record->revision }}</small></div>
          </summary>
          <div class="sync-details">
            <dl>
              <div><dt>Identificador</dt><dd>{{ $record->uuid }}</dd></div>
              <div><dt>Dispositivo</dt><dd>#{{ $record->device_id }}</dd></div>
              <div><dt>Origen</dt><dd>{{ $payload['origin'] ?? '—' }}</dd></div>
              <div><dt>Propietario</dt><dd>{{ $payload['owner']['name'] ?? (($payload['external_owner_id'] ?? null) ? '#'.$payload['external_owner_id'] : 'Mi empresa') }}</dd></div>
              <div><dt>Última sincronización ({{ config('app.timezone') }})</dt><dd>{{ $record->updated_at }}</dd></div>
            </dl>
            @if($record->status === 'voided' && !empty($payload['void_reason']))<p class="sync-notes"><strong>Motivo de anulación:</strong> {{ $payload['void_reason'] }}</p>@endif
            @if(!empty($payload['delivery']))<p>Entrega: {{ $payload['delivery']['vehicle']['plate'] ?? '—' }} · {{ $payload['delivery']['driver']['name'] ?? '—' }}</p>@endif
            @if(!empty($payload['notes']))<p class="sync-notes">{{ $payload['notes'] }}</p>@endif
            <div class="sync-table-wrap"><table class="sync-table">
              <thead><tr><th>Fecha de pesada</th><th>Sexo</th><th>Javas</th><th>Pollos / java</th><th>Peso leído</th><th>Tara / java</th><th>Fuente</th><th>Estado</th></tr></thead>
              <tbody>@foreach($payload['weighings'] ?? [] as $weighing)<tr>
                <td>{{ $weighing['weighed_at'] ?? '—' }}</td><td>{{ $weighing['sex'] ?? '—' }}</td><td>{{ $weighing['cage_count'] ?? 0 }}</td><td>{{ $weighing['birds_per_cage'] ?? 0 }}</td><td>{{ number_format($weighing['read_weight_kg'] ?? 0, 3, ',', '.') }} kg</td><td>{{ number_format($weighing['cage_weight_kg'] ?? 0, 3, ',', '.') }} kg</td><td>{{ $weighing['weight_source'] ?? '—' }}</td><td>{{ ($weighing['status'] ?? 'active') === 'voided' ? 'Anulada' : 'Activa' }}@if(!empty($weighing['void_reason'])) · {{ $weighing['void_reason'] }}@endif</td>
              </tr>@endforeach</tbody>
            </table></div>
          </div>
        </details>
      @empty
        <p class="sync-empty">No hay registros sincronizados para los filtros seleccionados.</p>
      @endforelse
    </section>

    <nav class="sync-pagination" aria-label="Paginación">
      <div>@if($records->previousPageUrl())<a class="btn btn-ghost" href="{{ $records->previousPageUrl() }}">Anterior</a>@endif</div>
      <span>Página {{ $records->currentPage() }} de {{ $records->lastPage() }} · {{ $records->total() }} registros</span>
      <div>@if($records->nextPageUrl())<a class="btn btn-ghost" href="{{ $records->nextPageUrl() }}">Siguiente</a>@endif</div>
    </nav>
    @include('partials.system-credit')
  </main>
</body>
</html>
