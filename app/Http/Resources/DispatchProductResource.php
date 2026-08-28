<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DispatchProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->nombre,
            'description' => $this->descripcion,
            'price_mode' => $this->modo_precio,
            'price' => $this->precio_venta,
            'waste_grams_per_unit' => $this->merma_gramos_unidad,
            'image_url' => $this->imagen_path
                ? "/api/v1/productos-despacho/{$this->id}/imagen?v=".$this->updated_at?->timestamp
                : null,
            'status' => $this->estado,
            'variations' => DispatchProductVariationResource::collection(
                $this->whenLoaded('variaciones'),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
