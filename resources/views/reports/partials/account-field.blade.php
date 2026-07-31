<label class="fin-field report-wide">
  <span>Cuenta de la empresa</span>
  <select name="cuenta_id">
    <option value="">Todas las cuentas</option>
    @foreach($accounts->groupBy('entidad_financiera_id') as $entityAccounts)
      @php($entity = $entityAccounts->first())
      <optgroup label="{{ $entity->entidad_nombre_comercial ?: $entity->entidad_razon_social }}">
        @foreach($entityAccounts as $account)
          <option value="{{ $account->id }}" @selected((string) old('cuenta_id') === (string) $account->id)>
            {{ $account->alias }} · {{ $account->tipo }} · {{ $account->moneda }}@if($account->cuenta_estado !== 'ACTIVO' || $account->entidad_estado !== 'ACTIVO') · Inactiva @endif
          </option>
        @endforeach
      </optgroup>
    @endforeach
  </select>
  <small>Incluye cuentas inactivas para consultar movimientos históricos.</small>
</label>
