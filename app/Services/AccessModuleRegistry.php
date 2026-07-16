<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccessModuleRegistry
{
    public const ADMIN_ROLE_CODE = 'ADMINISTRADOR';

    public const MANAGEMENT_MODULE_CODE = 'MODULO_USUARIOS_ROLES';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        return config('access_modules.modules', []);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->modules());
    }

    /**
     * @return list<array{code: string, name: string, description: string, path: string}>
     */
    public function catalogue(): array
    {
        return collect($this->modules())
            ->map(fn (array $module, string $code): array => [
                'code' => $code,
                'name' => (string) ($module['name'] ?? $code),
                'description' => (string) ($module['description'] ?? ''),
                'path' => (string) ($module['path'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $moduleCodes
     * @return list<string>
     */
    public function normalizeModuleCodes(array $moduleCodes): array
    {
        $normalized = collect($moduleCodes)
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->filter()
            ->unique()
            ->values();
        $invalid = $normalized->diff($this->codes())->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'module_codes' => 'Uno o mas modulos no existen: '.$invalid->implode(', ').'.',
            ]);
        }

        return $normalized->all();
    }

    /**
     * Return the permission markers persisted for the selected modules.
     * Technical permissions are derived at authorization time from the bundles.
     *
     * @param  array<int, mixed>  $moduleCodes
     * @return list<string>
     */
    public function permissionCodesForModules(array $moduleCodes): array
    {
        return $this->normalizeModuleCodes($moduleCodes);
    }

    /**
     * @param  array<int, mixed>  $moduleCodes
     * @return list<int>
     */
    public function permissionIdsForModules(array $moduleCodes): array
    {
        return collect($this->permissionCodesForModules($moduleCodes))
            ->map(function (string $code): int {
                $permission = Permission::query()->firstOrCreate(
                    ['codigo' => $code],
                    ['descripcion' => $this->permissionDescription($code, $this->modules())]
                );

                return (int) $permission->id;
            })
            ->all();
    }

    /**
     * Translate old role assignments when module markers have not been added yet.
     * Once at least one marker exists, markers are authoritative.
     *
     * @param  iterable<int, string>  $permissionCodes
     * @return list<string>
     */
    public function moduleCodesForPermissions(iterable $permissionCodes): array
    {
        $permissions = collect($permissionCodes)
            ->map(fn (mixed $code): string => (string) $code)
            ->unique()
            ->values();
        $explicit = $permissions->intersect($this->codes());

        if ($explicit->isNotEmpty()) {
            return collect($this->codes())->filter(
                fn (string $code): bool => $explicit->containsStrict($code)
            )->values()->all();
        }

        return collect($this->modules())
            ->filter(function (array $module) use ($permissions): bool {
                return $permissions->intersect($module['legacy_permissions'] ?? [])->isNotEmpty();
            })
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function moduleCodesForRole(Role $role): array
    {
        if ($role->codigo === self::ADMIN_ROLE_CODE) {
            return $this->codes();
        }

        $role->loadMissing('permissions:id,codigo');

        return $this->moduleCodesForPermissions($role->permissions->pluck('codigo'));
    }

    /**
     * @param  iterable<int, Role>  $roles
     * @return list<string>
     */
    public function moduleCodesForRoles(iterable $roles): array
    {
        $roles = $roles instanceof Collection ? $roles : collect($roles);

        if ($roles->contains(fn (Role $role): bool => $role->codigo === self::ADMIN_ROLE_CODE)) {
            return $this->codes();
        }

        $selected = $roles
            ->flatMap(fn (Role $role): array => $this->moduleCodesForRole($role))
            ->unique();

        return collect($this->codes())
            ->filter(fn (string $code): bool => $selected->containsStrict($code))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    private function permissionDescription(string $code, array $modules): string
    {
        if (isset($modules[$code])) {
            return (string) ($modules[$code]['name'] ?? $code);
        }

        return str($code)->replace('_', ' ')->lower()->ucfirst()->toString();
    }
}
