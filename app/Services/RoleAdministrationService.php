<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleAdministrationService
{
    public function __construct(
        private readonly AccessModuleRegistry $modules,
        private readonly AccessAuditService $audit,
        private readonly AccessSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?string $ip = null): Role
    {
        return DB::transaction(function () use ($actor, $data, $ip): Role {
            if ($data['code'] === AccessModuleRegistry::ADMIN_ROLE_CODE) {
                $this->reservedCode();
            }

            $moduleCodes = $this->modules->normalizeModuleCodes($data['module_codes']);
            $role = Role::query()->create([
                'empresa_id' => $actor->empresa_id,
                'codigo' => $data['code'],
                'nombre' => $data['name'],
            ]);
            $role->permissions()->sync(
                $this->modules->permissionIdsForModules($moduleCodes)
            );
            $role = $this->load($role);

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'rol',
                $role->id,
                'CREAR',
                null,
                $this->snapshot($role),
                $ip,
            );

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        User $actor,
        int $roleId,
        array $data,
        ?string $ip = null
    ): Role {
        return DB::transaction(function () use ($actor, $roleId, $data, $ip): Role {
            $role = $this->findLocked((int) $actor->empresa_id, $roleId);
            $role = $this->load($role);

            if ($role->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE) {
                throw ValidationException::withMessages([
                    'role' => 'El rol ADMINISTRADOR esta protegido y no puede modificarse.',
                ]);
            }

            if (($data['code'] ?? null) === AccessModuleRegistry::ADMIN_ROLE_CODE) {
                $this->reservedCode();
            }

            $before = $this->snapshot($role);
            $oldPermissionIds = $role->permissions->modelKeys();

            $role->forceFill(array_filter([
                'codigo' => $data['code'] ?? null,
                'nombre' => $data['name'] ?? null,
            ], fn (mixed $value): bool => $value !== null))->save();

            if (array_key_exists('module_codes', $data)) {
                $role->permissions()->sync(
                    $this->modules->permissionIdsForModules($data['module_codes'])
                );
            }

            $role = $this->load($role->fresh());
            $newPermissionIds = $role->permissions->modelKeys();

            if ($this->differentIds($oldPermissionIds, $newPermissionIds)) {
                User::query()
                    ->where('empresa_id', $actor->empresa_id)
                    ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
                    ->each(fn (User $user) => $this->sessions->revokeAll($user));
            }

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'rol',
                $role->id,
                'ACTUALIZAR',
                $before,
                $this->snapshot($role),
                $ip,
            );

            return $role;
        });
    }

    public function destroy(User $actor, int $roleId, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $roleId, $ip): void {
            $role = $this->load($this->findLocked((int) $actor->empresa_id, $roleId));

            if ($role->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE) {
                throw ValidationException::withMessages([
                    'role' => 'El rol ADMINISTRADOR esta protegido y no puede eliminarse.',
                ]);
            }

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => 'No se puede eliminar un rol que tiene usuarios asignados.',
                ]);
            }

            $before = $this->snapshot($role);
            $role->delete();

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'rol',
                $roleId,
                'ELIMINAR',
                $before,
                null,
                $ip,
            );
        });
    }

    public function findForCompany(int $companyId, int $roleId): Role
    {
        return $this->load(
            Role::query()->where('empresa_id', $companyId)->findOrFail($roleId)
        );
    }

    private function findLocked(int $companyId, int $roleId): Role
    {
        return Role::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($roleId);
    }

    private function load(Role $role): Role
    {
        return $role->load('permissions:id,codigo')->loadCount('users');
    }

    private function reservedCode(): never
    {
        throw ValidationException::withMessages([
            'code' => 'El codigo ADMINISTRADOR esta reservado para el rol protegido del sistema.',
        ]);
    }

    /**
     * @param  array<int, int|string>  $left
     * @param  array<int, int|string>  $right
     */
    private function differentIds(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left !== $right;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Role $role): array
    {
        return [
            'code' => $role->codigo,
            'name' => $role->nombre,
            'module_codes' => $this->modules->moduleCodesForRole($role),
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
        ];
    }
}
