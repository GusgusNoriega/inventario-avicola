<!doctype html>
<html lang="es" class="live-reception-menu-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  @include('partials.pwa')
  <title>Dispositivos de recepción | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/recepcion-pollo-vivo-menu.css') }}">
  <style>
    .sync-card { padding: 24px; color: #fbfaf0; background: linear-gradient(180deg, #141414, #090909); }
    .sync-card h2 { margin-top: 0; }
    .sync-card p, .sync-help { color: #a8bdd5; line-height: 1.5; }
    .sync-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
    .sync-form label { display: grid; align-content: start; gap: 8px; }
    .sync-form input, .sync-form select, .sync-secret { width: 100%; box-sizing: border-box; padding: 12px; color: #fff; background: #07121e; border: 1px solid #486483; border-radius: 8px; font: inherit; }
    .sync-form footer { grid-column: 1 / -1; }
    .sync-notice { border: 1px solid #4ab880; }
    .sync-error { border: 1px solid #dc7474; }
    .sync-secret { display: block; resize: vertical; font-family: monospace; margin: 14px 0; }
    .sync-table-wrap { overflow-x: auto; }
    .sync-table { width: 100%; border-collapse: collapse; text-align: left; }
    .sync-table th, .sync-table td { padding: 13px 10px; border-bottom: 1px solid #29415b; vertical-align: top; }
    .sync-table small { display: block; margin-top: 5px; color: #a8bdd5; overflow-wrap: anywhere; }
    .sync-meta { overflow-wrap: anywhere; }
    .sync-pagination { display: flex; justify-content: space-between; gap: 15px; margin-top: 20px; }
    .sync-card a:not(.btn) { color: #a9d4ff; }
    .sync-doc-actions { display: flex; flex-wrap: wrap; gap: 12px; }
    @media (max-width: 640px) { .sync-form { grid-template-columns: 1fr; } .sync-card { padding: 18px; } }
  </style>
</head>
<body class="live-reception-menu-page">
  <main class="live-reception-menu-shell">
    <header class="live-reception-menu-header card">
      <div>
        <p class="eyebrow">Recepción de pollo vivo</p>
        <h1>Dispositivos sin conexión</h1>
        <p>Genera un token para conectar cada aplicación a los datos de su sucursal cuando tenga internet.</p>
      </div>
      <a class="menu-return-btn" href="{{ route('recepcion-pollo-vivo.menu') }}">Volver a recepción</a>
    </header>

    <section class="sync-card card" aria-labelledby="syncDocsTitle">
      <h2 id="syncDocsTitle">Documentación para la aplicación</h2>
      <p>Descarga la guía de conexión y la especificación de la API para desarrollar la aplicación y preparar la sincronización.</p>
      <div class="sync-doc-actions">
        <a class="btn btn-ghost" href="{{ route('reception-sync-tokens.documentation') }}">Descargar guía</a>
        <a class="btn btn-ghost" href="{{ route('reception-sync-tokens.openapi') }}">Descargar OpenAPI</a>
      </div>
    </section>

    @if(session('status'))
      <div class="sync-card sync-notice card" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
      <div class="sync-card sync-error card" role="alert">
        <strong>No se pudo generar el token.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif

    @if($plainTextToken)
      <section class="sync-card sync-notice card" aria-labelledby="newTokenTitle">
        <h2 id="newTokenTitle">Token generado para {{ $issuedToken->device_name }}</h2>
        <p>Cópialo ahora y pégalo en tu aplicación. Se muestra una sola vez y permite descargar y subir la información de recepción de esta sucursal.</p>
        <label for="syncSecret">Token de acceso</label>
        <textarea id="syncSecret" class="sync-secret" rows="3" readonly autocomplete="off" spellcheck="false">{{ $plainTextToken }}</textarea>
        <button type="button" id="copySyncToken" class="btn btn-primary">Copiar token</button>
        <span id="copySyncTokenStatus" role="status" aria-live="polite"></span>
        <p class="sync-meta">Dispositivo: <code>{{ $issuedToken->device_id }}</code><br>Vence: {{ $issuedToken->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} ({{ config('app.timezone') }}).</p>
        <a href="{{ route('reception-sync-tokens.index') }}">Ya lo copié, ocultar token</a>
      </section>
    @endif

    <section class="sync-card card" aria-labelledby="createTokenTitle">
      <h2 id="createTokenTitle">Conectar un dispositivo</h2>
      <p>Usa un token distinto por dispositivo. Puedes revocarlo en cualquier momento; cambiar la contraseña del usuario también invalida sus tokens.</p>
      @if($branches->isEmpty())
        <p>No tienes una sucursal activa disponible. Asigna una sucursal al usuario o activa la sucursal antes de crear un token.</p>
      @else
        <form method="POST" action="{{ route('reception-sync-tokens.store') }}" class="sync-form">
          @csrf
          <label>Nombre del dispositivo
            <input name="device_name" value="{{ old('device_name') }}" maxlength="120" placeholder="Ej. Balanza recepción principal" required>
          </label>
          <label>Sucursal
            <select name="sucursal_id" required>
              @foreach($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) old('sucursal_id') === (string) $branch->id)>{{ $branch->nombre }}</option>
              @endforeach
            </select>
          </label>
          <label>Vigencia en días
            <input type="number" name="expires_in_days" min="1" max="365" value="{{ old('expires_in_days', 90) }}" required>
            <small class="sync-help">Entre 1 y 365 días. Genera un nuevo token antes de su vencimiento.</small>
          </label>
          <label>Identificador del dispositivo (opcional)
            <input name="device_id" value="{{ old('device_id') }}" maxlength="36" placeholder="UUID del dispositivo">
            <small class="sync-help">Déjalo vacío para crear uno. Para renovar el acceso conserva el identificador anterior.</small>
          </label>
          <footer><button class="btn btn-primary" type="submit">Generar token</button></footer>
        </form>
      @endif
    </section>

    <section class="sync-card card" aria-labelledby="deviceListTitle">
      <h2 id="deviceListTitle">Tokens de dispositivos</h2>
      <div class="sync-table-wrap">
        <table class="sync-table">
          <thead><tr><th>Dispositivo</th><th>Sucursal / usuario</th><th>Estado</th><th>Último acceso</th><th>Acción</th></tr></thead>
          <tbody>
            @forelse($tokens as $token)
              <tr>
                <td>{{ $token->device_name }}<small>{{ $token->device_id }}</small><small>Token: {{ $token->token_prefix }}…</small></td>
                <td>{{ $token->sucursal?->nombre }}<small>{{ $token->user?->nombre }}</small></td>
                <td>{{ $token->revoked_at ? 'Revocado' : ($token->expires_at->isPast() ? 'Vencido' : 'Vigente') }}<small>Vence {{ $token->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</small></td>
                <td>{{ $token->last_used_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Aún no sincroniza' }}</td>
                <td>
                  @if(!$token->revoked_at)
                    <form method="POST" action="{{ route('reception-sync-tokens.destroy', $token->id) }}">
                      @csrf @method('DELETE')
                      <button class="btn btn-ghost" type="submit" aria-label="Revocar token de {{ $token->device_name }}">Revocar</button>
                    </form>
                  @else — @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5">Aún no hay tokens para tus sucursales.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if($tokens->hasPages())
        <nav class="sync-pagination" aria-label="Páginas de dispositivos">
          @if($tokens->previousPageUrl())<a href="{{ $tokens->previousPageUrl() }}">Anterior</a>@endif
          <span>Página {{ $tokens->currentPage() }} de {{ $tokens->lastPage() }}</span>
          @if($tokens->nextPageUrl())<a href="{{ $tokens->nextPageUrl() }}">Siguiente</a>@endif
        </nav>
      @endif
      <p class="sync-help">Horarios en {{ config('app.timezone') }}. La sincronización requiere que el token, el usuario, la empresa, la sucursal y el módulo estén activos.</p>
    </section>
    @include('partials.system-credit')
  </main>
  @if($plainTextToken)
    <script>
      document.getElementById('copySyncToken').addEventListener('click', async () => {
        const field = document.getElementById('syncSecret');
        const status = document.getElementById('copySyncTokenStatus');
        field.focus();
        field.select();
        try {
          await navigator.clipboard.writeText(field.value);
          status.textContent = 'Token copiado.';
        } catch {
          status.textContent = 'Token seleccionado. Usa Copiar en tu dispositivo.';
        }
      });
    </script>
  @endif
</body>
</html>
