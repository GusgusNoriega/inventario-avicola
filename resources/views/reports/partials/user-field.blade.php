@php
  $userFieldRequired = $required ?? false;
  $userFieldLabel = $label ?? 'Usuario';
@endphp
<label class="fin-field report-wide">
  <span>{{ $userFieldLabel }}</span>
  <select name="usuario_id" @required($userFieldRequired)>
    <option value="">{{ $userFieldRequired ? 'Selecciona un usuario' : 'Todos los usuarios' }}</option>
    @foreach($users as $user)
      <option value="{{ $user->id }}" @selected((string) old('usuario_id') === (string) $user->id)>
        {{ $user->nombre }}@if($user->estado !== 'ACTIVO') · Inactivo @endif
      </option>
    @endforeach
  </select>
  <small>Incluye usuarios inactivos para consultar movimientos históricos.</small>
</label>
