<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InstallationAdminSeeder extends Seeder
{
    /**
     * Create the temporary administrator accounts used during installation.
     */
    public function run(): void
    {
        $password = (string) env('INSTALLATION_ADMIN_PASSWORD', '');

        if ($password === '') {
            $this->command?->warn(
                'No se crearon los administradores de instalacion: INSTALLATION_ADMIN_PASSWORD no esta configurada.'
            );

            return;
        }

        $companyId = DB::table('empresas')
            ->where('ruc', env('EMPRESA_RUC', '20000000001'))
            ->value('id');

        if (! $companyId) {
            $this->command?->error('No se encontro la empresa configurada para crear los administradores.');

            return;
        }

        $branchId = DB::table('sucursales')
            ->where('empresa_id', $companyId)
            ->where('codigo', 'PRINCIPAL')
            ->value('id');

        $administrator = Role::query()->firstOrCreate(
            ['empresa_id' => $companyId, 'codigo' => 'ADMINISTRADOR'],
            ['nombre' => 'Administrador']
        );

        // Installation administrators must also receive permissions added later.
        $administrator->permissions()->sync(Permission::query()->pluck('id'));

        $domain = strtolower(trim((string) env('INSTALLATION_ADMIN_DOMAIN', 'sistema-pollos.local')));

        foreach ([
            ['username' => 'gustavo', 'name' => 'Gustavo'],
            ['username' => 'avicola', 'name' => 'Avicola'],
            ['username' => 'norma', 'name' => 'Norma'],
        ] as $account) {
            $user = User::query()->updateOrCreate(
                [
                    'empresa_id' => $companyId,
                    'email' => "{$account['username']}@{$domain}",
                ],
                [
                    'sucursal_id' => $branchId,
                    'nombre' => $account['name'],
                    'password_hash' => Hash::make($password),
                    'estado' => User::STATUS_ACTIVE,
                ]
            );

            $user->roles()->syncWithoutDetaching([$administrator->id]);
        }
    }
}
