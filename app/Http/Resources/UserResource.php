<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'email' => $this->email,
            'status' => $this->estado,
            'roles' => $this->roleCodes(),
            'permissions' => $this->permissionCodes(),
            'last_login_at' => $this->ultimo_acceso_at?->toISOString(),
        ];
    }
}
