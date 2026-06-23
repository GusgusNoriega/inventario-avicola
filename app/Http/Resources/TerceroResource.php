<?php

namespace App\Http\Resources;

use App\Models\ListaPrecio;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TerceroResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->getAttribute('_directory_role') ?: TerceroRole::CLIENT;
        $operation = $role === TerceroRole::PROVIDER
            ? ListaPrecio::OPERATION_PURCHASE
            : ListaPrecio::OPERATION_SALE;
        $priceList = $this->listasPrecios->firstWhere('operacion', $operation);
        $prices = $priceList?->preciosVigentes
            ->mapWithKeys(fn ($price) => [$price->tipoPollo->codigo => (float) $price->precio_kg])
            ?? collect();

        $data = [
            'id' => $this->id,
            'type' => $role === TerceroRole::PROVIDER ? 'proveedores' : 'clientes',
            'name' => $this->nombre_razon_social,
            'nombre' => $this->nombre_razon_social,
            'tipo_documento' => $this->tipo_documento,
            'dni' => $this->numero_documento,
            'numero_documento' => $this->numero_documento,
            'direccion' => $this->direccion,
            'roles' => $this->roles->pluck('rol')->values(),
            'pricesKg' => [
                'pollo_vivo' => $prices->get(TipoPollo::CHICKEN_LIVE),
                'pollo_pelado' => $prices->get(TipoPollo::CHICKEN_DRESSED),
                'pollo_beneficiado' => $prices->get(TipoPollo::CHICKEN_PROCESSED),
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];

        if ($role === TerceroRole::PROVIDER) {
            $data['vehicles'] = $this->relationLoaded('vehiculosProveedor')
                ? $this->vehiculosProveedor
                    ->map(fn ($association) => [
                        'id' => $association->id,
                        'vehicle_id' => $association->vehiculo_id,
                        'plate' => $association->vehiculo?->placa,
                        'alias' => $association->alias,
                        'valid_from' => $association->vigente_desde?->format('Y-m-d'),
                        'status' => $association->estado,
                    ])
                    ->filter(fn (array $vehicle) => filled($vehicle['plate']))
                    ->values()
                : [];
        }

        return $data;
    }
}
