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

        return [
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
                'pollo_vivo' => $prices->get(TipoPollo::CHICKEN_LIVE, 0),
                'pollo_pelado' => $prices->get(TipoPollo::CHICKEN_DRESSED, 0),
                'pollo_beneficiado' => $prices->get(TipoPollo::CHICKEN_PROCESSED, 0),
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
