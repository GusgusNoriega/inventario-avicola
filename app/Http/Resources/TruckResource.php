<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TruckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->asignacionProveedorActiva?->proveedor;

        return [
            'id' => $this->id,
            'placa' => $this->placa,
            'marca' => $this->marca,
            'modelo' => $this->modelo,
            'color' => $this->color,
            'descripcion' => $this->descripcion,
            'is_own' => true,
            'assigned_provider' => $provider
                ? [
                    'id' => $provider->id,
                    'name' => $provider->nombre_razon_social,
                    'document' => $provider->numero_documento,
                ]
                : null,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
