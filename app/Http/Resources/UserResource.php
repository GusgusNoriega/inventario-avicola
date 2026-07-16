<?php

namespace App\Http\Resources;

use App\Services\AccessModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roles.permissions:id,codigo');
        $registry = app(AccessModuleRegistry::class);
        $moduleCodes = $registry->moduleCodesForRoles($this->roles);

        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'email' => $this->email,
            'status' => $this->estado,
            'roles' => $this->roleCodes(),
            'permissions' => $this->permissionCodes(),
            'module_codes' => $moduleCodes,
            'modules' => collect($registry->catalogue())
                ->whereIn('code', $moduleCodes)
                ->values()
                ->all(),
            'must_change_password' => (bool) $this->debe_cambiar_password,
            'last_login_at' => $this->ultimo_acceso_at?->toISOString(),
        ];
    }
}
