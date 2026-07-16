<?php

namespace App\Http\Resources\Access;

use App\Services\AccessModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessRoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('permissions:id,codigo');

        return [
            'id' => $this->id,
            'code' => $this->codigo,
            'name' => $this->nombre,
            'protected' => $this->codigo === AccessModuleRegistry::ADMIN_ROLE_CODE,
            'users_count' => (int) ($this->users_count ?? $this->users()->count()),
            'module_codes' => app(AccessModuleRegistry::class)->moduleCodesForRole($this->resource),
        ];
    }
}
