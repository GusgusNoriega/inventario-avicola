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
                ? round((float) $prices->get($type->id)->precio_kg, 2, PHP_ROUND_HALF_UP)
                : null,
        ])->all();
    }

    /**
     * @param  array<string, float|int|string>  $prices
     * @param  array<string, float|int|string|null>  $expectedPrices
     */
    public function save(
        int $companyId,
        int $actorId,
        array $prices,
        array $expectedPrices
    ): void {
        $types = $this->types()->keyBy('codigo');
        $priceCodes = collect(array_keys($prices));

        if ($priceCodes->isEmpty() || $priceCodes->diff($types->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'global_prices' => 'Debes enviar entre uno y tres precios globales activos.',
            ]);
        }

        $expectedCodes = collect(array_keys($expectedPrices));
        if ($expectedCodes->diff($priceCodes)->isNotEmpty() || $priceCodes->diff($expectedCodes)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'expected_prices' => 'Los precios esperados deben corresponder a los precios que deseas actualizar.',
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
            $newPrice = round((float) $value, 2, PHP_ROUND_HALF_UP);
            $current = PrecioHistorial::query()
                ->where('lista_precio_id', $list->id)
                ->where('tipo_pollo_id', $type->id)
                ->whereNull('vigente_hasta')
                ->lockForUpdate()
                ->first();

            $expectedPrice = $expectedPrices[$code];
            $currentPrice = $current
                ? round((float) $current->precio_kg, 2, PHP_ROUND_HALF_UP)
                : null;
            $normalizedExpectedPrice = $expectedPrice === null
                ? null
                : round((float) $expectedPrice, 2, PHP_ROUND_HALF_UP);

            if ($currentPrice !== $normalizedExpectedPrice) {
                throw ValidationException::withMessages([
                    "expected_prices.{$code}" => 'El precio de la jornada cambió en otra estación. Revisa el valor vigente e inténtalo nuevamente.',
                ]);
            }

            if ($currentPrice === $newPrice) {
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
