<table class="report">
  <thead><tr>
    <th style="width: 9%">Fecha</th><th style="width: 13%">Codigo</th><th style="width: 19%">Cliente / proveedor</th>
    <th style="width: 14%">Tipo</th><th style="width: 10%">Metodo</th><th>Detalle</th>
    @if($showUser)<th style="width: 11%">Responsable</th>@endif
    <th style="width: 10%">Monto</th>
  </tr></thead>
  <tbody>
    @forelse($rows as $row)
      <tr>
        <td>{{ $row['date']->format('d/m/Y') }}</td><td>{{ $row['code'] }}</td><td>{{ $row['counterparty'] }}</td>
        <td>{{ $row['type'] }}</td><td>{{ $row['method'] }}</td><td>{{ $row['detail'] ?: '-' }}</td>
        @if($showUser)<td>{{ $row['user'] }}</td>@endif
        <td class="num {{ $row['flow'] === 'EGRESO' ? 'credit' : ($row['flow'] === 'INGRESO' ? 'debit' : '') }}">{{ number_format($row['amount'], 2) }}</td>
      </tr>
    @empty
      <tr><td colspan="{{ $showUser ? 8 : 7 }}" class="empty">No hay movimientos para mostrar.</td></tr>
    @endforelse
  </tbody>
</table>
