<?php

namespace App\Services;

use App\Models\AjustePesoMinorista;
use App\Models\JornadaOperativa;
use App\Models\ListaPrecio;
use App\Models\Pesada;
use App\Models\PrecioHistorial;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TicketPrecio;
use App\Models\TipoBandeja;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailDispatchService
{
    public function __construct(
        private readonly RetailConfigurationService $configuration,
        private readonly ScaleReadingService $scaleReadings,
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespacho, already_registered: bool}
     */
    public function register(
        int $companyId,
        object $branch,
        User $actor,
        array $data,
        int $station = 1
    ): array {
        $station = $station === 2 ? 2 : 1;
        $expectedScaleCode = $this->configuration->scaleCode($station);

        foreach ($data['weighings'] as $index => $weighing) {
            if ($weighing['weight_source'] !== 'MANUAL' && $weighing['weight_source'] !== $expectedScaleCode) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weight_source" => 'La balanza no corresponde a esta estacion minorista.',
                ]);
            }
        }

        return DB::transaction(function () use ($companyId, $branch, $actor, $data, $station): array {
            $existing = TicketDespacho::query()
                ->where('referencia_externa', $data['draft_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->loadMissing('jornada');
                $belongsToCompany = DB::table('sucursales')
                    ->where('id', $existing->jornada?->sucursal_id)
                    ->where('empresa_id', $companyId)
                    ->exists();

                if (! $belongsToCompany) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya se encuentra registrado.',
                    ]);
                }

                if ((int) $existing->jornada?->sucursal_id !== (int) $branch->id) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a otra sucursal.',
                    ]);
                }

                if ($existing->canal !== TicketDespacho::CHANNEL_RETAIL) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a un ticket de otro canal.',
                    ]);
                }

                if ($existing->estado === TicketDespacho::STATUS_VOIDED) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este borrador pertenece a un ticket anulado. Crea un ticket nuevo.',
                    ]);
                }

                if ($this->existingRetailStation($existing) !== $station) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a otra estacion minorista.',
                    ]);
                }

                return [
                    'ticket' => $this->loadTicket($existing),
                    'already_registered' => true,
                ];
            }

            $this->configuration->ensureDefaults($companyId, (int) $branch->id, $station);

            $clientId = filled($data['client_id'] ?? null)
                ? (int) $data['client_id']
                : null;
            $client = $clientId
                ? Tercero::query()
                    ->where('empresa_id', $companyId)
                    ->where('estado', Tercero::STATUS_ACTIVE)
                    ->conRol(TerceroRole::CLIENT)
                    ->find($clientId)
                : null;
            $publicSale = $station === 2
                && $data['operation_type'] === TicketDespacho::OPERATION_DISPATCH
                && $clientId === null;

            if (! $client && ! $publicSale) {
                throw ValidationException::withMessages([
                    'client_id' => 'El cliente seleccionado no esta disponible.',
                ]);
            }

            $weighings = collect($data['weighings'])->values();
            $weighedAt = $this->weighedTimes($weighings, $branch->zona_horaria);
            $operatingDate = $this->resolveOperatingDate($companyId, $weighedAt, $branch->zona_horaria);
            $journey = $this->openJourney($companyId, $branch, $actor, $operatingDate);
            $typeCodes = $weighings->pluck('chicken_type_code')->unique()->values();
            $types = TipoPollo::query()
                ->whereIn('codigo', $typeCodes)
                ->where('estado', TipoPollo::STATUS_ACTIVE)
                ->where('permite_despacho', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');
            $trayCodes = $weighings->pluck('tray_type_code')->unique()->values();
            $trays = TipoBandeja::query()
                ->whereIn('codigo', $trayCodes)
                ->where('estado', TipoBandeja::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');
            $defaultAdjustment = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
                ->where('predeterminado', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $adjustmentCodes = $weighings
                ->pluck('adjustment_code')
                ->filter()
                ->when($defaultAdjustment, fn (Collection $codes) => $codes->push($defaultAdjustment->codigo))
                ->unique()
                ->values();
            $adjustments = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
                ->whereIn('codigo', $adjustmentCodes)
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');

            if ($types->count() !== $typeCodes->count()) {
                throw ValidationException::withMessages([
                    'weighings' => 'Uno o mas tipos de pollo no estan disponibles para despacho.',
                ]);
            }
            if ($trays->count() !== $trayCodes->count()) {
                throw ValidationException::withMessages([
                    'weighings' => 'Uno o mas tipos de bandeja no estan disponibles.',
                ]);
            }
            if (! $defaultAdjustment) {
                throw ValidationException::withMessages([
                    'weighings' => 'No existe un ajuste de peso minorista predeterminado.',
                ]);
            }

            foreach ($weighings as $index => $weighing) {
                $code = ($weighing['adjustment_code'] ?? null) ?: $defaultAdjustment->codigo;

                if (! $adjustments->has($code)) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.adjustment_code" => 'El ajuste de peso seleccionado no esta disponible.',
                    ]);
                }
            }

            $deliveryData = $data['delivery'] ?? [];
            $delivery = $data['operation_type'] === TicketDespacho::OPERATION_DISPATCH
                && $weighings->contains(fn (array $weighing): bool => (int) $weighing['tray_count'] > 0)
                && ($deliveryData['mode'] ?? null) === TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK
                    ? $deliveryData
                    : [];
            $deferredDeliveryAssignment = in_array($station, [1, 2], true)
                && $data['operation_type'] === TicketDespacho::OPERATION_DISPATCH
                && $client !== null
                && ! $client->es_cliente_interno
                && $weighings->contains(fn (array $weighing): bool => (int) $weighing['tray_count'] > 0)
                && ($deliveryData['mode'] ?? null) === TicketDespacho::DELIVERY_MODE_PENDING_ASSIGNMENT;

            $ticket = TicketDespacho::query()->create([
                'jornada_id' => $journey->id,
                'codigo' => $this->nextTicketCode($journey, $operatingDate),
                'referencia_externa' => $data['draft_id'],
                'canal' => TicketDespacho::CHANNEL_RETAIL,
                'tipo_operacion' => $data['operation_type'],
                'cliente_destino_id' => $client?->id,
                'almacen_destino_id' => null,
                'vehiculo_entrega_id' => $delivery['vehicle_id'] ?? null,
                'conductor_entrega_id' => $delivery['driver_id'] ?? null,
                'asignacion_transporte_posterior' => $deferredDeliveryAssignment,
                'estado' => TicketDespacho::STATUS_CLOSED,
                'observaciones' => $publicSale ? TicketDespacho::PUBLIC_SALE_LABEL : null,
                'cerrado_por' => $actor->id,
                'cerrado_at' => now(),
                'created_by' => $actor->id,
            ]);

            $prices = $this->freezePrices(
                $companyId,
                $client?->id,
                $types,
                collect($data['price_overrides'] ?? [])
            );

            foreach ($prices as $typeId => $price) {
                TicketPrecio::query()->create([
                    'ticket_id' => $ticket->id,
                    'tipo_pollo_id' => $typeId,
                    'precio_historial_id' => $price['history']->id,
                    'precio_kg' => $price['price_kg'],
                    'origen_precio' => $price['source'],
                    'congelado_por' => $actor->id,
                ]);
            }

            foreach ($weighings as $index => $weighing) {
                $type = $types->get($weighing['chicken_type_code']);
                $tray = $trays->get($weighing['tray_type_code']);
                $adjustmentCode = ($weighing['adjustment_code'] ?? null) ?: $defaultAdjustment->codigo;
                $adjustment = $adjustments->get($adjustmentCode);
                $trayCount = (int) $weighing['tray_count'];
                $birdsPerTray = (int) $weighing['birds_per_tray'];
                $birdCount = $birdsPerTray * max($trayCount, 1);
                $trayWeight = round((float) $tray->peso_kg, 3);
                $readWeight = round((float) $weighing['read_weight_kg'], 3);
                $additionalGramsPerBird = $type->codigo === TipoPollo::CHICKEN_PROCESSED
                    ? 0
                    : (int) $adjustment->gramos_adicionales;
                $totalAdditionalGrams = $additionalGramsPerBird * $birdCount;
                $grossWeight = round($readWeight + ($totalAdditionalGrams / 1000), 3);
                $tareWeight = round($trayCount * $trayWeight, 3);
                $netWeight = round($grossWeight - $tareWeight, 3);

                if ($netWeight <= 0) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.read_weight_kg" => 'El peso ajustado debe ser mayor que la tara total de las bandejas.',
                    ]);
                }

                $scaleReading = $this->scaleReadings->record(
                    (int) $branch->id,
                    $actor,
                    $weighing,
                    $weighedAt->get($index),
                    "weighings.{$index}"
                );

                Pesada::query()->create([
                    'ticket_id' => $ticket->id,
                    'numero' => $index + 1,
                    'tipo_pollo_id' => $type->id,
                    'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
                    'sexo' => $adjustment->sexo,
                    'presentacion_pollo' => $adjustment->presentacion,
                    'tipo_java_id' => null,
                    'tipo_bandeja_id' => $tray->id,
                    'ajuste_peso_minorista_id' => $adjustment->id,
                    'lectura_balanza_id' => $scaleReading?->id,
                    'proveedor_origen_id' => null,
                    'almacen_origen_id' => null,
                    'vehiculo_id' => null,
                    'programacion_recepcion_detalle_id' => null,
                    'placa_snapshot' => null,
                    'origen_peso' => $weighing['weight_source'],
                    'aves_por_java' => null,
                    'aves_por_bandeja' => $birdsPerTray,
                    'cantidad_javas' => null,
                    'cantidad_bandejas' => $trayCount,
                    'cantidad_aves' => $birdCount,
                    'peso_java_kg_snapshot' => null,
                    'peso_bandeja_kg_snapshot' => $trayWeight,
                    'peso_leido_kg' => $readWeight,
                    'ajuste_peso_gramos' => $additionalGramsPerBird,
                    'peso_bruto_kg' => $grossWeight,
                    'tara_total_kg' => $tareWeight,
                    'peso_neto_kg' => $netWeight,
                    'pesada_at' => $weighedAt->get($index),
                    'estado' => Pesada::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                ]);
            }

            $this->javaControl->syncDispatchMovement(
                $ticket,
                $companyId,
                (int) $branch->id
            );
            $this->financialObligations->syncTicket($companyId, $ticket, $actor);

            return [
                'ticket' => $this->loadTicket($ticket),
                'already_registered' => false,
            ];
        }, 3);
    }

    /** @param Collection<int, array<string, mixed>> $weighings */
    private function weighedTimes(Collection $weighings, string $timezone): Collection
    {
        $now = CarbonImmutable::now($timezone);

        return $weighings->map(function (array $weighing, int $index) use ($timezone, $now): CarbonImmutable {
            $time = CarbonImmutable::parse($weighing['weighed_at'])->setTimezone($timezone);

            if ($time->greaterThan($now->addMinutes(5))) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weighed_at" => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }

            return $time;
        });
    }

    /** @param Collection<int, CarbonImmutable> $weighedAt */
    private function resolveOperatingDate(int $companyId, Collection $weighedAt, string $timezone): CarbonImmutable
    {
        $cutoff = (string) DB::table('empresas')->where('id', $companyId)->value('hora_corte_operativo') ?: '21:00:00';
        $dates = $weighedAt->map(function (CarbonImmutable $time) use ($cutoff): string {
            $cutoffAt = $time->startOfDay()->setTimeFromTimeString($cutoff);

            return ($time->greaterThanOrEqualTo($cutoffAt) ? $time->addDay() : $time)->format('Y-m-d');
        })->unique()->values();

        if ($dates->count() !== 1) {
            throw ValidationException::withMessages([
                'weighings' => 'Todas las pesadas deben pertenecer a la misma jornada operativa.',
            ]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $dates->first(), $timezone)->startOfDay();
    }

    private function openJourney(int $companyId, object $branch, User $actor, CarbonImmutable $operatingDate): JornadaOperativa
    {
        $cutoff = (string) DB::table('empresas')->where('id', $companyId)->value('hora_corte_operativo') ?: '21:00:00';
        $journey = JornadaOperativa::query()
            ->where('sucursal_id', $branch->id)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->lockForUpdate()
            ->first();

        if (! $journey) {
            $journey = JornadaOperativa::query()->create([
                'sucursal_id' => $branch->id,
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
                'estado' => JornadaOperativa::STATUS_OPEN,
                'abierta_por' => $actor->id,
                'inicio_at' => $operatingDate->subDay()->setTimeFromTimeString($cutoff),
                'cierre_programado_at' => $operatingDate->setTimeFromTimeString($cutoff),
            ]);
        }

        if ($journey->estado !== JornadaOperativa::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'weighings' => 'La jornada operativa de estas pesadas ya esta cerrada.',
            ]);
        }

        return $journey;
    }

    private function nextTicketCode(JornadaOperativa $journey, CarbonImmutable $operatingDate): string
    {
        $prefix = 'M-'.$operatingDate->format('Ymd').'-';
        $next = TicketDespacho::query()
            ->where('jornada_id', $journey->id)
            ->where('codigo', 'like', $prefix.'%')
            ->pluck('codigo')
            ->map(fn (string $code): int => ctype_digit(substr($code, strlen($prefix)))
                ? (int) substr($code, strlen($prefix))
                : 0)
            ->max() + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @param  Collection<string, mixed>  $overrides
     * @return array<int, array{history: PrecioHistorial, source: string, price_kg: float}>
     */
    private function freezePrices(
        int $companyId,
        ?int $clientId,
        Collection $types,
        Collection $overrides
    ): array {
        $sourceIds = $types->map(fn (TipoPollo $type): int => $type->priceSourceTypeId())->unique()->values();
        $specificListId = $clientId
            ? ListaPrecio::query()
                ->where('empresa_id', $companyId)
                ->where('tercero_id', $clientId)
                ->where('operacion', ListaPrecio::OPERATION_SALE)
                ->where('estado', ListaPrecio::STATUS_ACTIVE)
                ->value('id')
            : null;
        $specific = $specificListId
            ? PrecioHistorial::query()->where('lista_precio_id', $specificListId)
                ->whereIn('tipo_pollo_id', $sourceIds)->whereNull('vigente_hasta')
                ->lockForUpdate()->get()->keyBy('tipo_pollo_id')
            : collect();
        $missingIds = $sourceIds->diff($specific->keys());
        $generalListId = $missingIds->isEmpty() ? null : ListaPrecio::query()
            ->where('empresa_id', $companyId)
            ->whereNull('tercero_id')
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->value('id');
        $general = $generalListId
            ? PrecioHistorial::query()->where('lista_precio_id', $generalListId)
                ->whereIn('tipo_pollo_id', $missingIds)->whereNull('vigente_hasta')
                ->lockForUpdate()->get()->keyBy('tipo_pollo_id')
            : collect();
        $result = [];

        foreach ($types as $type) {
            $sourceId = $type->priceSourceTypeId();
            $specificPrice = $specific->get($sourceId);
            $history = $specificPrice ?: $general->get($sourceId);

            if (! $history) {
                throw ValidationException::withMessages([
                    ($clientId ? 'client_id' : 'price_overrides') => $clientId
                        ? "Falta configurar el precio de {$type->nombre} para este cliente."
                        : "Falta configurar el precio general de {$type->nombre}.",
                ]);
            }

            // Un precio puntual pertenece exclusivamente a este ticket y prevalece
            // sobre la tarifa del cliente o la tarifa general usada como referencia.
            $isManual = $overrides->has($type->codigo);
            $result[$type->id] = [
                'history' => $history,
                'source' => $isManual ? 'MANUAL' : ($specificPrice ? 'CLIENTE' : 'GENERAL'),
                'price_kg' => $isManual
                    ? round((float) $overrides->get($type->codigo), 2, PHP_ROUND_HALF_UP)
                    : round((float) $history->precio_kg, 2, PHP_ROUND_HALF_UP),
            ];
        }

        return $result;
    }

    private function existingRetailStation(TicketDespacho $ticket): ?int
    {
        $scaleCodes = [
            1 => $this->configuration->scaleCode(1),
            2 => $this->configuration->scaleCode(2),
        ];
        $sourceStations = DB::table('pesadas')
            ->where('ticket_id', $ticket->id)
            ->whereIn('origen_peso', array_values($scaleCodes))
            ->pluck('origen_peso')
            ->map(fn (string $source): int => $source === $scaleCodes[2] ? 2 : 1)
            ->unique()
            ->values();

        if ($sourceStations->count() === 1) {
            return (int) $sourceStations->first();
        }

        if ($sourceStations->count() > 1) {
            return null;
        }

        $adjustmentStations = DB::table('pesadas')
            ->join(
                'ajustes_peso_minorista',
                'ajustes_peso_minorista.id',
                '=',
                'pesadas.ajuste_peso_minorista_id'
            )
            ->where('pesadas.ticket_id', $ticket->id)
            ->whereIn('ajustes_peso_minorista.estacion', [1, 2])
            ->distinct()
            ->pluck('ajustes_peso_minorista.estacion');

        if ($adjustmentStations->count() === 1) {
            return (int) $adjustmentStations->first();
        }

        if ($adjustmentStations->count() > 1) {
            return null;
        }

        // Los tickets manuales anteriores al aislamiento no tienen una marca
        // de puesto inequívoca; conservar el puesto 1 mantiene su idempotencia.
        return 1;
    }

    private function loadTicket(TicketDespacho $ticket): TicketDespacho
    {
        return $ticket->load([
            'jornada',
            'clienteDestino',
            'vehiculoEntrega',
            'conductorEntrega',
            'precios.tipoPollo',
            'pesadas.tipoPollo',
            'pesadas.tipoBandeja',
            'pesadas.ajustePesoMinorista',
        ]);
    }
}
