<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.pwa')
  <title>Acceso denegado | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <style>
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #06090f; color: #f4f8ff; font-family: Arial, sans-serif; }
    .access-denied { width: min(520px, calc(100% - 32px)); padding: 32px; border: 1px solid #34435a; border-top: 5px solid #ff5b5b; border-radius: 14px; background: #101722; box-shadow: 0 20px 50px rgba(0,0,0,.4); }
    .access-denied p { color: #afbdd0; line-height: 1.6; }
    .access-denied a { display: inline-flex; margin-top: 12px; padding: 12px 18px; border-radius: 9px; background: #2bc7ff; color: #00121a; font-weight: 800; text-decoration: none; }
  </style>
</head>
<body>
  <main class="access-denied">
    <p>Acceso protegido</p>
    <h1>No tienes acceso a esta vista</h1>
    <p>{{ $message ?? 'Tu rol no tiene habilitado este módulo.' }}</p>
    <a href="{{ route('menu') }}">Volver al menú principal</a>
  </main>
</body>
</html>
