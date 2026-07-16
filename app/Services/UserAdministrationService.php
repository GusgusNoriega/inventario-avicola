<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserAdministrationService
{
    public function __construct(
        private readonly AccessModuleRegistry $modules,
        private readonly AccessAuditService $audit,
        private readonly AccessSessionService $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?string $ip = null): User
    {
        return DB::transaction(function () use ($actor, $data, $ip): User {
            $roles = $this->rolesForCompany((int) $actor->empresa_id, $data['role_ids']);
            $this->ensureBranchBelongsToCompany(
                (int) $actor->empresa_id,
                $data['branch_id'] ?? null
            );

            $user = User::query()->create([
                'empresa_id' => $actor->empresa_id,
                'sucursal_id' => $data['branch_id'] ?? null,
                'nombre' => $data['name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'estado' => $data['status'] ?? User::STATUS_ACTIVE,
            ]);
            $user->forceFill([
                'debe_cambiar_password' => (bool) ($data['must_change_password'] ?? true),
            ])->save();
            $user->roles()->sync($roles->modelKeys());
            $user = $this->load($user);

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'usuario',
                $user->id,
                'CREAR',
                null,
                $this->snapshot($user),
                $ip,
            );

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        User $actor,
        int $userId,
        array $data,
        ?string $ip = null
    ): User {
        return DB::transaction(function () use ($actor, $userId, $data, $ip): User {
            DB::table('empresas')
                ->where('id', $actor->empresa_id)
                ->lockForUpdate()
                ->first();
            $user = $this->findLocked((int) $actor->empresa_id, $userId);
            $user->load('roles.permissions');
            $before = $this->snapshot($user);
            $oldRoleIds = $user->roles->modelKeys();
            $roles = array_key_exists('role_ids', $data)
                ? $this->rolesForCompany((int) $actor->empresa_id, $data['role_ids'])
                : $user->roles;
            $newRoleIds = $roles->modelKeys();
            $rolesChanged = $this->differentIds($oldRoleIds, $newRoleIds);
            $newStatus = $data['status'] ?? $user->estado;
            $willRemainActiveAdmin = $newStatus === User::STATUS_ACTIVE
                && $roles->contains(fn (Role $role): bool => $role->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE);

            if ($newStatus === User::STATUS_INACTIVE && (int) $user->id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'status' => 'No puedes desactivar tu propio usuario.',
                ]);
            }

            if ($user->isActive() && $this->isAdministrator($user) && ! $willRemainActiveAdmin) {
                $this->ensureAnotherActiveAdministrator((int) $actor->empresa_id, (int) $user->id);
            }

            if (array_key_exists('branch_id', $data)) {
                $this->ensureBranchBelongsToCompany(
                    (int) $actor->empresa_id,
                    $data['branch_id']
                );
            }

            $attributes = [];

            foreach ([
                'name' => 'nombre',
                'email' => 'email',
                'branch_id' => 'sucursal_id',
                'status' => 'estado',
            ] as $input => $attribute) {
                if (array_key_exists($input, $data)) {
                    $attributes[$attribute] = $data[$input];
                }
            }

            $passwordChanged = array_key_exists('password', $data);

            if ($passwordChanged) {
                $attributes['password_hash'] = Hash::make($data['password']);
                $attributes['debe_cambiar_password'] = (bool) ($data['must_change_password'] ?? true);
            } elseif (array_key_exists('must_change_password', $data)) {
                $attributes['debe_cambiar_password'] = (bool) $data['must_change_password'];
            }

            if ($attributes !== []) {
                $user->forceFill($attributes)->save();
            }

            if ($rolesChanged) {
                $user->roles()->sync($newRoleIds);
            }

            if ($rolesChanged || $passwordChanged || $newStatus === User::STATUS_INACTIVE) {
                $this->sessions->revokeAll($user);
            }

            $user = $this->load($user->fresh());
            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'usuario',
                $user->id,
                'ACTUALIZAR',
                $before,
                $this->snapshot($user),
                $ip,
            );

            return $user;
        });
    }

    public function changeStatus(
        User $actor,
        int $userId,
        string $status,
        ?string $ip = null
    ): User {
        return $this->update($actor, $userId, ['status' => $status], $ip);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resetPassword(
        User $actor,
        int $userId,
        array $data,
        ?string $ip = null
    ): User {
        return DB::transaction(function () use ($actor, $userId, $data, $ip): User {
            $user = $this->findLocked((int) $actor->empresa_id, $userId);
            $before = [
                'must_change_password' => (bool) $user->debe_cambiar_password,
            ];

            $user->forceFill([
                'password_hash' => Hash::make($data['password']),
                'debe_cambiar_password' => (bool) ($data['must_change_password'] ?? true),
            ])->save();
            $this->sessions->revokeAll($user);

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'usuario',
                $user->id,
                'RESTABLECER_PASSWORD',
                $before,
                ['must_change_password' => (bool) $user->debe_cambiar_password],
                $ip,
            );

            return $this->load($user->fresh());
        });
    }

    public function revokeSessions(
        User $actor,
        int $userId,
        ?string $ip = null
    ): User {
        return DB::transaction(function () use ($actor, $userId, $ip): User {
            $user = $this->findLocked((int) $actor->empresa_id, $userId);
            $this->sessions->revokeAll($user);

            $this->audit->record(
                (int) $actor->empresa_id,
                (int) $actor->id,
                'usuario',
                $user->id,
                'REVOCAR_SESIONES',
                null,
                null,
                $ip,
            );

            return $this->load($user);
        });
    }

    public function findForCompany(int $companyId, int $userId): User
    {
        return $this->load(
            User::query()->where('empresa_id', $companyId)->findOrFail($userId)
        );
    }

    private function findLocked(int $companyId, int $userId): User
    {
        return User::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($userId);
    }

    /**
     * @param  array<int, mixed>  $roleIds
     * @return Collection<int, Role>
     */
    private function rolesForCompany(int $companyId, array $roleIds): Collection
    {
        $ids = collect($roleIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $roles = Role::query()
            ->where('empresa_id', $companyId)
            ->whereKey($ids)
            ->lockForUpdate()
            ->get();

        if ($roles->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'role_ids' => 'Uno o mas roles no pertenecen a tu empresa.',
            ]);
        }

        return $roles;
    }

    private function ensureBranchBelongsToCompany(int $companyId, mixed $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $exists = DB::table('sucursales')
            ->where('empresa_id', $companyId)
            ->where('id', $branchId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sucursal no pertenece a tu empresa.',
            ]);
        }
    }

    private function ensureAnotherActiveAdministrator(int $companyId, int $exceptUserId): void
    {
        $exists = User::query()
            ->where('empresa_id', $companyId)
            ->where('estado', User::STATUS_ACTIVE)
            ->whereKeyNot($exceptUserId)
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.empresa_id', $companyId)
                ->where('roles.codigo', AccessModuleRegistry::ADMIN_ROLE_CODE))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'role_ids' => 'La empresa debe conservar al menos un administrador activo.',
                'status' => 'La empresa debe conservar al menos un administrador activo.',
            ]);
        }
    }

    private function isAdministrator(User $user): bool
    {
        return $user->roles->contains(
            fn (Role $role): bool => $role->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE
        );
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

    private function load(User $user): User
    {
        return $user->load('roles.permissions:id,codigo');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $user->loadMissing('roles.permissions:id,codigo');

        return [
            'name' => $user->nombre,
            'email' => $user->email,
            'branch_id' => $user->sucursal_id,
            'status' => $user->estado,
            'role_ids' => $user->roles->modelKeys(),
            'role_codes' => $user->roles->pluck('codigo')->values()->all(),
            'module_codes' => $this->modules->moduleCodesForRoles($user->roles),
            'must_change_password' => (bool) $user->debe_cambiar_password,
        ];
    }
}
