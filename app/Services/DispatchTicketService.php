<?php

namespace App\Services;

use App\Models\JornadaOperativa;
use App\Models\ListaPrecio;
use App\Models\Pesada;
use App\Models\PrecioHistorial;
use App\Models\ProgramacionRecepcion;
use App\Models\ProgramacionRecepcionDetalle;
use App\Models\ProveedorVehiculo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TicketPrecio;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchTicketService
{
    public function __construct(
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations,
        private readonly ScaleReadingService $scaleReadings
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespacho, already_registered: bool}
     */
    public function register(
        int $companyId,
        object $branch,
        User $actor,
        array $data
    ): array {
        return DB::transaction(function () use ($companyId, $branch, $actor, $data): array {
            $operationType = $this->operationType($data['operation_type'] ?? null);
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

                if ($existing->canal !== TicketDespacho::CHANNEL_WHOLESALE) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a un ticket de otro canal.',
                    ]);
                }

                return [
                    'ticket' => $this->loadTicket($existing),
                    'already_registered' => true,
                ];
            }

            $weighings = collect($data['weighings']);
            $weighedAt = $this->weighedTimes($weighings, $branch->zona_horaria);
            $operatingDate = $this->resolveOperatingDate(
                $companyId,
                $weighedAt,
                $branch->zona_horaria
            );
            $program = $this->configuredProgram((int) $branch->id, $operatingDate);
            $journey = $this->openJourney($branch, $actor, $operatingDate, $companyId);
            $destination = $this->resolveDestination(
                $companyId,
                (int) $branch->id,
                $data['destination'],
                $operationType
            );
            $requiredTypeCodes = $this->requiredTypeCodes($operationType, $weighings);
            $types = TipoPollo::query()
                ->whereIn('codigo', $requiredTypeCodes)
                ->where('estado', TipoPollo::STATUS_ACTIVE)
                ->where('permite_despacho', true)
                ->get()
                ->keyBy('codigo');
            $cageTypes = DB::table('tipos_java')
                ->whereIn('codigo', $weighings->pluck('cage_type_code')->unique())
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');

            $this->assertCatalogsComplete($weighings, $requiredTypeCodes, $types, $cageTypes);

            $ticket = TicketDespacho::query()->create([
                'jornada_id' => $journey->id,
                'codigo' => $this->nextTicketCode($journey, $operatingDate, $operationType),
                'referencia_externa' => $data['draft_id'],
                'canal' => TicketDespacho::CHANNEL_WHOLESALE,
                'tipo_operacion' => $operationType,
                'cliente_destino_id' => $destination['client_id'],
                'almacen_destino_id' => $destination['warehouse_id'],
                'vehiculo_entrega_id' => $operationType === TicketDespacho::OPERATION_DISPATCH
                    && ! $destination['internal_client']
                    ? $data['delivery']['vehicle_id']
                    : null,
                'conductor_entrega_id' => $operationType === TicketDespacho::OPERATION_DISPATCH
                    && ! $destination['internal_client']
                    ? $data['delivery']['driver_id']
                    : null,
                'estado' => TicketDespacho::STATUS_CLOSED,
                'cerrado_por' => $actor->id,
                'cerrado_at' => now(),
                'created_by' => $actor->id,
            ]);
            $ticketPrices = $this->freezePrices(
                $companyId,
                $destination['client_id'],
                $types
            );

            foreach ($ticketPrices as $typeId => $price) {
                TicketPrecio::query()->create([
                    'ticket_id' => $ticket->id,
                    'tipo_pollo_id' => $typeId,
                    'precio_historial_id' => $price['history']->id,
                    'precio_kg' => $price['history']->precio_kg,
                    'origen_precio' => $price['source'],
                    'congelado_por' => $actor->id,
                ]);
            }

            foreach ($weighings->values() as $index => $weighing) {
                $type = $types->get($this->weighingTypeCode($operationType, $weighing));
                $cageType = $cageTypes->get($weighing['cage_type_code']);
                $origin = $operationType === TicketDespacho::OPERATION_RETURN
                    ? $this->emptyOrigin()
                    : $this->resolveOrigin(
                        $companyId,
                        (int) $branch->id,
                        $program,
                        $weighing['origin'],
                        "weighings.{$index}.origin"
                    );
                $cageCount = (int) $weighing['cage_count'];
                $birdsPerCage = (int) $weighing['birds_per_cage'];
                $cageWeight = round((float) $cageType->peso_kg, 3);
                $grossWeight = round((float) $weighing['gross_weight_kg'], 3);
                $readWeight = round((float) $weighing['read_weight_kg'], 3);
                $tareWeight = round($cageCount * $cageWeight, 3);
                $netWeight = round($grossWeight - $tareWeight, 3);

                if ($netWeight <= 0) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.gross_weight_kg" => 'El peso bruto debe ser mayor que la tara total de las javas.',
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
                    'condicion_pollo' => $this->weighingCondition($operationType, $weighing),
                    'sexo' => $weighing['chicken_sex'],
                    'tipo_java_id' => $cageType->id,
                    'lectura_balanza_id' => $scaleReading?->id,
                    'proveedor_origen_id' => $origin['provider_id'],
                    'almacen_origen_id' => $origin['warehouse_id'],
                    'vehiculo_id' => $origin['vehicle_id'],
                    'programacion_recepcion_detalle_id' => $origin['program_detail_id'],
                    'placa_snapshot' => $origin['plate'],
                    'origen_peso' => $weighing['weight_source'] === 'MANUAL'
                        ? 'MANUAL'
                        : 'BALANZA',
                    'aves_por_java' => $birdsPerCage,
                    'cantidad_javas' => $cageCount,
                    'cantidad_aves' => $birdsPerCage * max($cageCount, 1),
                    'peso_java_kg_snapshot' => $cageWeight,
                    'peso_leido_kg' => $readWeight,
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

    private function operationType(?string $operationType): string
    {
        return $operationType === TicketDespacho::OPERATION_RETURN
            ? TicketDespacho::OPERATION_RETURN
            : TicketDespacho::OPERATION_DISPATCH;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, string>
     */
    private function requiredTypeCodes(string $operationType, Collection $weighings): Collection
    {
        if ($operationType === TicketDespacho::OPERATION_RETURN) {
            return $weighings
                ->map(fn (array $weighing): string => $this->weighingTypeCode($operationType, $weighing))
                ->unique()
                ->values();
        }

        return $weighings->pluck('chicken_type_code')->unique()->values();
    }

    /**
     * @param  array<string, mixed>  $weighing
     */
    private function weighingTypeCode(string $operationType, array $weighing): string
    {
        if ($operationType !== TicketDespacho::OPERATION_RETURN) {
            return (string) $weighing['chicken_type_code'];
        }

        return ($weighing['chicken_condition'] ?? null) === Pesada::CHICKEN_CONDITION_DEAD
            ? TipoPollo::CHICKEN_DEAD
            : TipoPollo::CHICKEN_LIVE;
    }

    /**
     * @param  array<string, mixed>  $weighing
     */
    private function weighingCondition(string $operationType, array $weighing): string
    {
        if ($operationType !== TicketDespacho::OPERATION_RETURN) {
            return Pesada::CHICKEN_CONDITION_LIVE;
        }

        return ($weighing['chicken_condition'] ?? null) === Pesada::CHICKEN_CONDITION_DEAD
            ? Pesada::CHICKEN_CONDITION_DEAD
            : Pesada::CHICKEN_CONDITION_LIVE;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, CarbonImmutable>
     */
    private function weighedTimes(Collection $weighings, string $timezone): Collection
    {
        $now = CarbonImmutable::now($timezone);

        return $weighings->values()->map(function (array $weighing, int $index) use ($timezone, $now): CarbonImmutable {
            $weighedAt = CarbonImmutable::parse($weighing['weighed_at'])
                ->setTimezone($timezone);

            if ($weighedAt->greaterThan($now->addMinutes(5))) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weighed_at" => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }

            return $weighedAt;
        });
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $weighedAt
     */
    private function resolveOperatingDate(
        int $companyId,
        Collection $weighedAt,
        string $timezone
    ): CarbonImmutable {
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $dates = $weighedAt
            ->map(fn (CarbonImmutable $time) => $this->operatingDateFor($time, $cutoff)->format('Y-m-d'))
            ->unique()
            ->values();

        if ($dates->count() !== 1) {
            throw ValidationException::withMessages([
                'weighings' => 'Todas las pesadas del ticket deben pertenecer a la misma jornada operativa.',
            ]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $dates->first(), $timezone)
            ->startOfDay();
    }

    private function operatingDateFor(CarbonImmutable $time, string $cutoff): CarbonImmutable
    {
        $cutoffAt = $time->startOfDay()->setTimeFromTimeString($cutoff);

        return $time->greaterThanOrEqualTo($cutoffAt)
            ? $time->addDay()->startOfDay()
            : $time->startOfDay();
    }

    private function openJourney(
        object $branch,
        User $actor,
        CarbonImmutable $operatingDate,
        int $companyId
    ): JornadaOperativa {
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
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
                'weighings' => 'La jornada operativa de estas pesadas ya está cerrada.',
            ]);
        }

        return $journey;
    }

    private function configuredProgram(
        int $branchId,
        CarbonImmutable $operatingDate
    ): ProgramacionRecepcion {
        $program = ProgramacionRecepcion::query()
            ->where('sucursal_id', $branchId)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->where('estado', ProgramacionRecepcion::STATUS_PUBLISHED)
            ->lockForUpdate()
            ->first();

        if (! $program) {
            throw ValidationException::withMessages([
                'journey' => 'Configura y publica la jornada antes de agregar pesadas.',
            ]);
        }

        return $program;
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array{client_id: ?int, warehouse_id: ?int, internal_client: bool}
     */
    private function resolveDestination(
        int $companyId,
        int $branchId,
        array $destination,
        string $operationType
    ): array {
        if ($destination['type'] === 'CLIENTE') {
            $client = Tercero::query()
                ->where('empresa_id', $companyId)
                ->where('estado', Tercero::STATUS_ACTIVE)
                ->conRol(TerceroRole::CLIENT)
                ->find($destination['id']);

            if (! $client) {
                throw ValidationException::withMessages([
                    'destination.id' => 'El cliente seleccionado no está disponible.',
                ]);
            }

            return [
                'client_id' => $client->id,
                'warehouse_id' => null,
                'internal_client' => (bool) $client->es_cliente_interno,
            ];
        }

        if ($operationType === TicketDespacho::OPERATION_RETURN) {
            throw ValidationException::withMessages([
                'destination.type' => 'Las devoluciones deben registrarse contra un cliente.',
            ]);
        }

        $warehouseId = DB::table('almacenes')
            ->where('sucursal_id', $branchId)
            ->where('estado', 'ACTIVO')
            ->where('id', $destination['id'])
            ->value('id');

        if (! $warehouseId) {
            throw ValidationException::withMessages([
                'destination.id' => 'El almacén seleccionado no está disponible.',
            ]);
        }

        return [
            'client_id' => null,
            'warehouse_id' => (int) $warehouseId,
            'internal_client' => false,
        ];
    }

    /**
     * @param  Collection<int, string>  $requiredTypeCodes
     * @param  Collection<string, TipoPollo>  $types
     * @param  Collection<string, object>  $cageTypes
     */
    private function assertCatalogsComplete(
        Collection $weighings,
        Collection $requiredTypeCodes,
        Collection $types,
        Collection $cageTypes
    ): void {
        if ($types->count() !== $requiredTypeCodes->count()) {
            throw ValidationException::withMessages([
                'weighings' => 'Uno o más tipos de pollo no están disponibles para despacho.',
            ]);
        }

        if ($cageTypes->count() !== $weighings->pluck('cage_type_code')->unique()->count()) {
            throw ValidationException::withMessages([
                'weighings' => 'Uno o más tipos de java no están disponibles.',
            ]);
        }
    }

    private function nextTicketCode(
        JornadaOperativa $journey,
        CarbonImmutable $operatingDate,
        string $operationType
    ): string {
        $prefix = ($operationType === TicketDespacho::OPERATION_RETURN ? 'D-' : 'T-')
            .$operatingDate->format('Ymd').'-';
        $next = TicketDespacho::query()
            ->where('jornada_id', $journey->id)
            ->where('codigo', 'like', $prefix.'%')
            ->pluck('codigo')
            ->map(function (string $code) use ($prefix): int {
                $suffix = substr($code, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @return array<int, array{history: PrecioHistorial, source: string}>
     */
    private function freezePrices(
        int $companyId,
        ?int $clientId,
        Collection $types
    ): array {
        $sourceTypes = $this->priceSourceTypes($types);
        $specificPrices = collect();

        if ($clientId) {
            $specificListId = ListaPrecio::query()
                ->where('empresa_id', $companyId)
                ->where('tercero_id', $clientId)
                ->where('operacion', ListaPrecio::OPERATION_SALE)
                ->where('estado', ListaPrecio::STATUS_ACTIVE)
                ->value('id');

            if ($specificListId) {
                $specificPrices = PrecioHistorial::query()
                    ->where('lista_precio_id', $specificListId)
                    ->whereIn('tipo_pollo_id', $sourceTypes->pluck('id'))
                    ->whereNull('vigente_hasta')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('tipo_pollo_id');
            }
        }

        $missingTypes = $sourceTypes->filter(
            fn (TipoPollo $type) => ! $specificPrices->has($type->id)
        );
        $generalPrices = $missingTypes->isEmpty()
            ? collect()
            : $this->generalPrices($companyId, $missingTypes);
        $result = [];

        foreach ($types as $type) {
            $sourceType = $sourceTypes->get($type->priceSourceTypeId());
            $specific = $sourceType ? $specificPrices->get($sourceType->id) : null;
            $history = $sourceType ? ($specific ?: $generalPrices->get($sourceType->id)) : null;

            if (! $history) {
                throw ValidationException::withMessages([
                    'destination.id' => "La configuración interna del destino está incompleta para {$type->nombre}.",
                ]);
            }

            $result[$type->id] = [
                'history' => $history,
                'source' => $specific ? 'CLIENTE' : 'GENERAL',
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @return Collection<int, TipoPollo>
     */
    private function priceSourceTypes(Collection $types): Collection
    {
        $sourceIds = $types
            ->map(fn (TipoPollo $type): int => $type->priceSourceTypeId())
            ->unique()
            ->values();
        $sourceTypes = TipoPollo::query()
            ->whereIn('id', $sourceIds)
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');

        if ($sourceTypes->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages([
                'destination.id' => 'La configuracion interna de precios esta incompleta.',
            ]);
        }

        return $sourceTypes;
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @return Collection<int, PrecioHistorial>
     */
    private function generalPrices(
        int $companyId,
        Collection $types
    ): Collection {
        $listId = ListaPrecio::query()
            ->where('empresa_id', $companyId)
            ->whereNull('tercero_id')
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->value('id');

        if (! $listId) {
            throw ValidationException::withMessages([
                'destination.id' => 'La configuración interna del destino está incompleta.',
            ]);
        }

        return PrecioHistorial::query()
            ->where('lista_precio_id', $listId)
            ->whereIn('tipo_pollo_id', $types->pluck('id'))
            ->whereNull('vigente_hasta')
            ->lockForUpdate()
            ->get()
            ->keyBy('tipo_pollo_id');
    }

    /**
     * @param  array<string, mixed>  $origin
     * @return array{provider_id: ?int, warehouse_id: ?int, vehicle_id: ?int, plate: ?string, program_detail_id: ?int}
     */
    private function resolveOrigin(
        int $companyId,
        int $branchId,
        ProgramacionRecepcion $program,
        array $origin,
        string $field
    ): array {
        if ($origin['type'] === 'ALMACEN') {
            $warehouseId = DB::table('almacenes')
                ->join(
                    'programacion_recepcion_almacenes',
                    'programacion_recepcion_almacenes.almacen_id',
                    '=',
                    'almacenes.id'
                )
                ->where('almacenes.sucursal_id', $branchId)
                ->where('almacenes.estado', 'ACTIVO')
                ->where('almacenes.id', $origin['warehouse_id'] ?? 0)
                ->where('programacion_recepcion_almacenes.programacion_id', $program->id)
                ->value('almacenes.id');

            if (! $warehouseId) {
                throw ValidationException::withMessages([
                    "{$field}.warehouse_id" => 'Este almacén no está seleccionado como origen para la jornada operativa.',
                ]);
            }

            return [
                'provider_id' => null,
                'warehouse_id' => (int) $warehouseId,
                'vehicle_id' => null,
                'plate' => null,
                'program_detail_id' => null,
            ];
        }

        $providerId = (int) ($origin['provider_id'] ?? 0);
        $provider = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::PROVIDER)
            ->find($providerId);

        if (! $provider) {
            throw ValidationException::withMessages([
                "{$field}.provider_id" => 'El proveedor de origen no está disponible.',
            ]);
        }

        $associationQuery = ProveedorVehiculo::query()
            ->where('proveedor_id', $provider->id)
            ->vigente()
            ->whereHas('vehiculo', fn ($query) => $query
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO'))
            ->with('vehiculo');

        if ($origin['provider_vehicle_id'] ?? null) {
            $associationQuery->where('id', $origin['provider_vehicle_id']);
        } elseif ($origin['vehicle_id'] ?? null) {
            $associationQuery->where('vehiculo_id', $origin['vehicle_id']);
        } else {
            $associationQuery->whereHas(
                'vehiculo',
                fn ($query) => $query->where('placa', $origin['plate'] ?? '')
            );
        }

        $association = $associationQuery->orderByDesc('id')->first();
        $plate = (string) ($origin['plate'] ?? '');

        if (! $association || ! $association->vehiculo || $association->vehiculo->placa !== $plate) {
            throw ValidationException::withMessages([
                "{$field}.plate" => 'La placa no pertenece al proveedor seleccionado.',
            ]);
        }

        $programDetailId = ProgramacionRecepcionDetalle::query()
            ->where('programacion_id', $program->id)
            ->where('proveedor_vehiculo_id', $association->id)
            ->where('estado', '!=', ProgramacionRecepcionDetalle::STATUS_CANCELLED)
            ->value('id');

        if (! $programDetailId) {
            throw ValidationException::withMessages([
                "{$field}.provider_vehicle_id" => 'Este camión no está seleccionado para la jornada operativa.',
            ]);
        }

        return [
            'provider_id' => $provider->id,
            'warehouse_id' => null,
            'vehicle_id' => $association->vehiculo_id,
            'plate' => $association->vehiculo->placa,
            'program_detail_id' => $programDetailId ? (int) $programDetailId : null,
        ];
    }

    /**
     * @return array{provider_id: null, warehouse_id: null, vehicle_id: null, plate: null, program_detail_id: null}
     */
    private function emptyOrigin(): array
    {
        return [
            'provider_id' => null,
            'warehouse_id' => null,
            'vehicle_id' => null,
            'plate' => null,
            'program_detail_id' => null,
        ];
    }

    private function loadTicket(TicketDespacho $ticket): TicketDespacho
    {
        return $ticket->load([
            'jornada',
            'clienteDestino',
            'almacenDestino',
            'vehiculoEntrega',
            'conductorEntrega',
            'pesadas',
        ]);
    }
}
