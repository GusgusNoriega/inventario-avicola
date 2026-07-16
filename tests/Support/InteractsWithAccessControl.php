<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

trait InteractsWithAccessControl
{
    protected function grantModules(
        User $user,
        array $moduleCodes,
        string $roleCode = 'ROL_PRUEBA',
        string $roleName = 'Rol de prueba',
    ): Role {
        $role = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => $roleCode,
            'nombre' => $roleName,
        ]);

        $permissions = collect($moduleCodes)->map(
            fn (string $moduleCode): Permission => Permission::query()->firstOrCreate(
                ['codigo' => $moduleCode],
                ['descripcion' => "Acceso de prueba a {$moduleCode}"],
            ),
        );

        $role->permissions()->sync($permissions->pluck('id')->all());
        $user->roles()->attach($role);

        return $role;
    }

    protected function makeAdministrator(User $user): Role
    {
        $role = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);

        $user->roles()->attach($role);

        return $role;
    }

    protected function createUserForCompany(User $companyUser, array $attributes = []): User
    {
        return User::factory()->create([
            'empresa_id' => $companyUser->empresa_id,
            ...$attributes,
        ]);
    }
}
