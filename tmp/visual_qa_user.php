<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$companyId = Illuminate\Support\Facades\DB::table('empresas')->value('id');
if (! $companyId) {
    throw new RuntimeException('No company available');
}
$roleId = Illuminate\Support\Facades\DB::table('roles')
    ->where('empresa_id', $companyId)
    ->where('codigo', 'ADMINISTRADOR')
    ->value('id');
if (! $roleId) {
    throw new RuntimeException('No admin role available');
}

$user = App\Models\User::query()->updateOrCreate(
    ['email' => 'codex.visual@local.test'],
    [
        'empresa_id' => $companyId,
        'nombre' => 'Codex Visual QA',
        'password_hash' => Illuminate\Support\Facades\Hash::make('CodexVisual-2026!'),
        'debe_cambiar_password' => false,
        'estado' => 'ACTIVO',
    ],
);
$user->roles()->syncWithoutDetaching([$roleId]);

echo "ready\n";
