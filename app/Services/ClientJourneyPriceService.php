<?php

namespace App\Services;

use App\Models\JornadaOperativa;
use App\Models\ListaPrecio;
use App\Models\PrecioHistorial;
use App\Models\Tercero;
use App\Models\TicketPrecio;
use App\Models\TipoPollo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientJourneyPriceService
{
    /**
     * Revaloriza los tickets del cliente que pertenecen a la jornada vigente.
     *
     * @param  array<int, string>  $chickenTypeCodes
     */
    public function refresh(
        Tercero $client,
        int $actorId,
        array $chickenTypeCodes
    ): int {
        if ($chickenTypeCodes === []) {
            return 0;
        }

        $journeyIds = $this->currentJourneyIds($client->empresa_id);

        if ($journeyIds->isEmpty()) {
            return 0;
        }

        $typeCodes = $this->expandTypeCodes($chickenTypeCodes);
        $types = TipoPollo::query()
            ->whereIn('codigo', $typeCodes)
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');
        $applicablePrices = $this->applicablePrices($client, $types);

        if ($applicablePrices->isEmpty()) {
            return 0;
        }

        $ticketPrices = TicketPrecio::query()
            ->whereIn('tipo_pollo_id', $applicablePrices->keys())
            ->whereHas('ticket', fn ($query) => $query
                ->where('cliente_destino_id', $client->id)
                ->whereIn('jornada_id', $journeyIds))
            ->lockForUpdate()
            ->get();
        $updated = 0;

        foreach ($ticketPrices as $ticketPrice) {
            $applicable = $applicablePrices->get($ticketPrice->tipo_pollo_id);

            if (! $applicable) {
                continue;
            }

            $before = $this->auditValues($ticketPrice);
            $after = [
                'precio_historial_id' => $applicable['history']->id,
                'precio_kg' => round((float) $applicable['history']->precio_kg, 4),
                'origen_precio' => $applicable['source'],
                'congelado_por' => $actorId,
            ];

            if ($before['precio_historial_id'] === $after['precio_historial_id']
                && $before['precio_kg'] === $after['precio_kg']
                && $before['origen_precio'] === $after['origen_precio']) {
                continue;
            }

            $ticketPrice->update($after);
            DB::table('auditoria_eventos')->insert([
                'empresa_id' => $client->empresa_id,
                'usuario_id' => $actorId,
                'entidad' => 'ticket_precios',
                'entidad_id' => (string) $ticketPrice->id,
                'accion' => 'REVALORIZAR_JORNADA',
                'datos_antes' => json_encode($before, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($after, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return Collection<int, int>
     */
    private function currentJourneyIds(int $companyId): Collection
    {
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';

        return DB::table('jornadas_operativas as jornadas')
            ->join('sucursales', 'sucursales.id', '=', 'jornadas.sucursal_id')
            ->where('sucursales.empresa_id', $companyId)
            ->where('jornadas.estado', JornadaOperativa::STATUS_OPEN)
            ->get([
                'jornadas.id',
                'jornadas.fecha_operativa',
                'sucursales.zona_horaria',
            ])
            ->filter(function (object $journey) use ($cutoff): bool {
                $now = CarbonImmutable::now($journey->zona_horaria);
                $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);
                $operatingDate = $now->greaterThanOrEqualTo($cutoffAt)
                    ? $now->addDay()
                    : $now;

                return substr((string) $journey->fecha_operativa, 0, 10)
                    === $operatingDate->format('Y-m-d');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * @param  Collection<int, TipoPollo>  $types
     * @return Collection<int, array{history: PrecioHistorial, source: string}>
     */
    private function applicablePrices(Tercero $client, Collection $types): Collection
    {
        $sourceTypes = $this->priceSourceTypes($types);
        $specificListId = ListaPrecio::query()
            ->where('empresa_id', $client->empresa_id)
            ->where('tercero_id', $client->id)
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->value('id');
        $specificPrices = $specificListId
            ? PrecioHistorial::query()
                ->where('lista_precio_id', $specificListId)
                ->whereIn('tipo_pollo_id', $sourceTypes->pluck('id'))
                ->whereNull('vigente_hasta')
                ->lockForUpdate()
                ->get()
                ->keyBy('tipo_pollo_id')
            : collect();
        $missingTypeIds = $sourceTypes->pluck('id')->diff($specificPrices->keys());
        $generalListId = $missingTypeIds->isEmpty()
            ? null
            : ListaPrecio::query()
                ->where('empresa_id', $client->empresa_id)
                ->whereNull('tercero_id')
                ->where('operacion', ListaPrecio::OPERATION_SALE)
                ->where('estado', ListaPrecio::STATUS_ACTIVE)
                ->value('id');
        $generalPrices = $generalListId
            ? PrecioHistorial::query()
                ->where('lista_precio_id', $generalListId)
                ->whereIn('tipo_pollo_id', $missingTypeIds)
                ->whereNull('vigente_hasta')
                ->lockForUpdate()
                ->get()
                ->keyBy('tipo_pollo_id')
            : collect();

        return $types->mapWithKeys(function (TipoPollo $type) use (
            $sourceTypes,
            $specificPrices,
            $generalPrices
        ): array {
            $sourceType = $sourceTypes->get($type->priceSourceTypeId());
            $specific = $sourceType ? $specificPrices->get($sourceType->id) : null;
            $history = $sourceType ? ($specific ?: $generalPrices->get($sourceType->id)) : null;

            return $history
                ? [$type->id => [
                    'history' => $history,
                    'source' => $specific ? 'CLIENTE' : 'GENERAL',
                ]]
                : [];
        });
    }

    /**
     * @param  array<int, string>  $chickenTypeCodes
     * @return array<int, string>
     */
    private function expandTypeCodes(array $chickenTypeCodes): array
    {
        return collect($chickenTypeCodes)
            ->map(fn (string $code): string => $code)
            ->flatMap(fn (string $code): array => $code === TipoPollo::CHICKEN_LIVE
                ? [TipoPollo::CHICKEN_LIVE, TipoPollo::CHICKEN_DEAD]
                : [$code])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TipoPollo>  $types
     * @return Collection<int, TipoPollo>
     */
    private function priceSourceTypes(Collection $types): Collection
    {
        $sourceIds = $types
            ->map(fn (TipoPollo $type): int => $type->priceSourceTypeId())
            ->unique()
            ->values();

        return TipoPollo::query()
            ->whereIn('id', $sourceIds)
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array{precio_historial_id: int, precio_kg: float, origen_precio: string, congelado_por: int}
     */
    private function auditValues(TicketPrecio $ticketPrice): array
    {
        return [
            'precio_historial_id' => (int) $ticketPrice->precio_historial_id,
            'precio_kg' => round((float) $ticketPrice->precio_kg, 4),
            'origen_precio' => (string) $ticketPrice->origen_precio,
            'congelado_por' => (int) $ticketPrice->congelado_por,
        ];
    }
}
