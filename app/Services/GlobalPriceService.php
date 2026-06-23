<?php

namespace App\Services;

use App\Models\ListaPrecio;
use App\Models\PrecioHistorial;
use App\Models\TipoPollo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GlobalPriceService
{
    /**
     * @return array<string, ?float>
     */
    public function current(int $companyId): array
    {
        $types = $this->types();
        $listId = ListaPrecio::query()
            ->where('empresa_id', $companyId)
            ->whereNull('tercero_id')
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->value('id');
        $prices = $listId
            ? PrecioHistorial::query()
                ->where('lista_precio_id', $listId)
                ->whereIn('tipo_pollo_id', $types->pluck('id'))
                ->whereNull('vigente_hasta')
                ->get()
                ->keyBy('tipo_pollo_id')
            : collect();

        return $types->mapWithKeys(fn (TipoPollo $type) => [
            $type->codigo => $prices->has($type->id)
                ? (float) $prices->get($type->id)->precio_kg
                : null,
        ])->all();
    }

    /**
     * @param  array<string, float|int|string>  $prices
     */
    public function save(int $companyId, int $actorId, array $prices): void
    {
        $types = $this->types()->keyBy('codigo');

        if ($types->count() !== count($prices)) {
            throw ValidationException::withMessages([
                'global_prices' => 'Debes configurar los tres precios globales.',
            ]);
        }

        $list = ListaPrecio::query()->updateOrCreate(
            [
                'empresa_id' => $companyId,
                'tercero_id' => null,
                'operacion' => ListaPrecio::OPERATION_SALE,
            ],
            [
                'codigo' => 'GENERAL-VENTA',
                'nombre' => 'Lista general de venta',
                'estado' => ListaPrecio::STATUS_ACTIVE,
                'created_by' => $actorId,
            ]
        );

        foreach ($prices as $code => $value) {
            $type = $types->get($code);
            $newPrice = round((float) $value, 4);
            $current = PrecioHistorial::query()
                ->where('lista_precio_id', $list->id)
                ->where('tipo_pollo_id', $type->id)
                ->whereNull('vigente_hasta')
                ->lockForUpdate()
                ->first();

            if ($current && (float) $current->precio_kg === $newPrice) {
                continue;
            }

            $effectiveAt = $this->nextEffectiveAt($current?->vigente_desde);

            if ($current) {
                $current->update(['vigente_hasta' => $effectiveAt]);
            }

            PrecioHistorial::query()->create([
                'lista_precio_id' => $list->id,
                'tipo_pollo_id' => $type->id,
                'precio_kg' => $newPrice,
                'vigente_desde' => $effectiveAt,
                'motivo_cambio' => 'Actualización de precio global',
                'reemplaza_precio_id' => $current?->id,
                'registrado_por' => $actorId,
            ]);
        }
    }

    /**
     * @return Collection<int, TipoPollo>
     */
    private function types(): Collection
    {
        return TipoPollo::query()
            ->whereIn('codigo', [
                TipoPollo::CHICKEN_LIVE,
                TipoPollo::CHICKEN_DRESSED,
                TipoPollo::CHICKEN_PROCESSED,
            ])
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();
    }

    private function nextEffectiveAt(?CarbonInterface $currentStart): CarbonInterface
    {
        $effectiveAt = now();

        if ($currentStart && $currentStart->gte($effectiveAt->copy()->startOfSecond())) {
            return $currentStart->copy()->addSecond();
        }

        return $effectiveAt;
    }
}
