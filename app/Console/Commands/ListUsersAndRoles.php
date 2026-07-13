<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class ListUsersAndRoles extends Command
{
    protected $signature = 'usuarios:listar
        {--mostrar-claves : Muestra la clave configurada para las cuentas de instalacion}';

    protected $description = 'Lista los usuarios, sus roles y las claves de instalacion disponibles';

    public function handle(): int
    {
        $users = User::query()
            ->with([
                'roles:id,codigo,nombre',
            ])
            ->orderBy('nombre')
            ->orderBy('email')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('No hay usuarios registrados.');
        } else {
            $showPasswords = (bool) $this->option('mostrar-claves');

            $this->newLine();
            $this->components->info('Usuarios registrados');
            $this->table(
                ['ID', 'Nombre', 'Correo', 'Estado', 'Roles', 'Clave'],
                $users->map(fn (User $user): array => [
                    $user->id,
                    $user->nombre,
                    $user->email,
                    $user->estado,
                    $user->roles->pluck('codigo')->join(', ') ?: 'SIN ROL',
                    $this->passwordFor($user, $showPasswords),
                ])->all()
            );
        }

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('codigo')
            ->get();

        $this->newLine();
        $this->components->info('Roles disponibles');
        $this->table(
            ['ID', 'Codigo', 'Nombre', 'Usuarios', 'Permisos'],
            $roles->map(fn (Role $role): array => [
                $role->id,
                $role->codigo,
                $role->nombre,
                $role->users_count,
                $role->permissions_count,
            ])->all()
        );

        if (! $this->option('mostrar-claves')) {
            $this->newLine();
            $this->comment('Agrega --mostrar-claves para ver la clave de las cuentas de instalacion.');
        }

        return self::SUCCESS;
    }

    private function passwordFor(User $user, bool $showPasswords): string
    {
        if (! $this->isInstallationAccount($user)) {
            return 'NO RECUPERABLE (hash)';
        }

        if (! $showPasswords) {
            return 'OCULTA';
        }

        return (string) env('INSTALLATION_ADMIN_PASSWORD', '') ?: 'NO CONFIGURADA';
    }

    private function isInstallationAccount(User $user): bool
    {
        $domain = strtolower(trim((string) env('INSTALLATION_ADMIN_DOMAIN', 'sistema-pollos.local')));
        $emails = collect(['gustavo', 'avicola', 'norma'])
            ->map(fn (string $username): string => "{$username}@{$domain}");

        return $emails->contains(strtolower($user->email));
    }
}
