<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$companyId = DB::table('empresas')->value('id');
if (! $companyId) {
    throw new RuntimeException('No company available');
}
$roleId = DB::table('roles')
    ->where('empresa_id', $companyId)
    ->where('codigo', 'ADMINISTRADOR')
    ->value('id');
if (! $roleId) {
    throw new RuntimeException('No admin role available');
}

$user = User::query()->updateOrCreate(
    ['email' => 'codex.visual@local.test'],
    [
        'empresa_id' => $companyId,
        'nombre' => 'Codex Visual QA',
        'password_hash' => Hash::make('CodexVisual-2026!'),
        'debe_cambiar_password' => false,
        'estado' => 'ACTIVO',
    ],
);
$user->roles()->syncWithoutDetaching([$roleId]);

echo "ready\n";
