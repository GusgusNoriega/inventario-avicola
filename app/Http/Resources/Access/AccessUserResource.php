<?php

namespace App\Http\Resources\Access;

use App\Models\Role;
use App\Services\AccessModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class AccessUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roles.permissions:id,codigo');
        $branch = $this->sucursal_id
            ? DB::table('sucursales')
                ->select(['id', 'codigo', 'nombre', 'estado'])
                ->where('empresa_id', $this->empresa_id)
                ->where('id', $this->sucursal_id)
                ->first()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'email' => $this->email,
            'status' => $this->estado,
            'branch_id' => $this->sucursal_id,
            'branch' => $branch ? [
                'id' => (int) $branch->id,
                'code' => $branch->codigo,
                'name' => $branch->nombre,
                'status' => $branch->estado,
            ] : null,
            'role_ids' => $this->roles->modelKeys(),
            'roles' => $this->roles->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'code' => $role->codigo,
                'name' => $role->nombre,
                'protected' => $role->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE,
            ])->values()->all(),
            'module_codes' => app(AccessModuleRegistry::class)
                ->moduleCodesForRoles($this->roles),
            'must_change_password' => (bool) $this->debe_cambiar_password,
            'last_login_at' => $this->ultimo_acceso_at?->toISOString(),
        ];
    }
}
