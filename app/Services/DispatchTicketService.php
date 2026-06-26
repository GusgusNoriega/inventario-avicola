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
            $existing = TicketDespacho::query()
                ->where('referencia_externa', $data['draft_id'])
                ->whereHas(
                    'jornada',
                    fn ($query) => $query->whereIn(
                        'sucursal_id',
                        DB::table('sucursales')
                            ->where('empresa_id', $companyId)
                            ->select('id')
                    )
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
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
            $journey = $this->openJourney($branch, $actor, $operatingDate, $companyId);
            $destination = $this->resolveDestination(
                $companyId,
                (int) $branch->id,
                $data['destination']
            );
            $types = TipoPollo::query()
                ->whereIn('codigo', $weighings->pluck('chicken_type_code')->unique())
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

            $this->assertCatalogsComplete($weighings, $types, $cageTypes);

            $ticket = TicketDespacho::query()->create([
                'jornada_id' => $journey->id,
                'codigo' => $this->nextTicketCode($journey, $operatingDate),
                'referencia_externa' => $data['draft_id'],
                'canal' => 'MAYORISTA',
                'cliente_destino_id' => $destination['client_id'],
                'almacen_destino_id' => $destination['warehouse_id'],
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
                $type = $types->get($weighing['chicken_type_code']);
                $cageType = $cageTypes->get($weighing['cage_type_code']);
                $origin = $this->resolveOrigin(
                    $companyId,
                    (int) $branch->id,
                    $operatingDate,
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

                Pesada::query()->create([
                    'ticket_id' => $ticket->id,
                    'numero' => $index + 1,
                    'tipo_pollo_id' => $type->id,
                    'tipo_java_id' => $cageType->id,
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
                    'cantidad_aves' => $birdsPerCage * $cageCount,
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

            return [
                'ticket' => $this->loadTicket($ticket),
                'already_registered' => false,
            ];
        }, 3);
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
        $journey = JornadaOperativa::query()->firstOrCreate(
            [
                'sucursal_id' => $branch->id,
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
            ],
            [
                'estado' => JornadaOperativa::STATUS_OPEN,
                'abierta_por' => $actor->id,
                'inicio_at' => $operatingDate->subDay()->setTimeFromTimeString($cutoff),
                'cierre_programado_at' => $operatingDate->setTimeFromTimeString($cutoff),
            ]
        );
        $journey = JornadaOperativa::query()->lockForUpdate()->findOrFail($journey->id);

        if ($journey->estado !== JornadaOperativa::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'weighings' => 'La jornada operativa de estas pesadas ya está cerrada.',
            ]);
        }

        return $journey;
    }

    /**
     * @param  array<string, mixed>  $destination
     * @return array{client_id: ?int, warehouse_id: ?int}
     */
    private function resolveDestination(
        int $companyId,
        int $branchId,
        array $destination
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

            return ['client_id' => $client->id, 'warehouse_id' => null];
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

        return ['client_id' => null, 'warehouse_id' => (int) $warehouseId];
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @param  Collection<string, object>  $cageTypes
     */
    private function assertCatalogsComplete(
        Collection $weighings,
        Collection $types,
        Collection $cageTypes
    ): void {
        if ($types->count() !== $weighings->pluck('chicken_type_code')->unique()->count()) {
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
        CarbonImmutable $operatingDate
    ): string {
        $prefix = 'T-'.$operatingDate->format('Ymd').'-';
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
                    ->whereIn('tipo_pollo_id', $types->pluck('id'))
                    ->whereNull('vigente_hasta')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('tipo_pollo_id');
            }
        }

        $missingTypes = $types->filter(
            fn (TipoPollo $type) => ! $specificPrices->has($type->id)
        );
        $generalPrices = $missingTypes->isEmpty()
            ? collect()
            : $this->generalPrices($companyId, $missingTypes);
        $result = [];

        foreach ($types as $type) {
            $specific = $specificPrices->get($type->id);
            $history = $specific ?: $generalPrices->get($type->id);

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
        CarbonImmutable $operatingDate,
        array $origin,
        string $field
    ): array {
        if ($origin['type'] === 'ALMACEN') {
            $warehouseId = DB::table('almacenes')
                ->where('sucursal_id', $branchId)
                ->where('estado', 'ACTIVO')
                ->where('id', $origin['warehouse_id'] ?? 0)
                ->value('id');

            if (! $warehouseId) {
                throw ValidationException::withMessages([
                    "{$field}.warehouse_id" => 'El almacén de origen no está disponible.',
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

        $programId = ProgramacionRecepcion::query()
            ->where('sucursal_id', $branchId)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->where('estado', ProgramacionRecepcion::STATUS_PUBLISHED)
            ->value('id');
        $programDetailId = null;

        if ($programId) {
            $programDetailId = ProgramacionRecepcionDetalle::query()
                ->where('programacion_id', $programId)
                ->where('proveedor_vehiculo_id', $association->id)
                ->where('estado', '!=', ProgramacionRecepcionDetalle::STATUS_CANCELLED)
                ->value('id');

            if (! $programDetailId) {
                throw ValidationException::withMessages([
                    "{$field}.provider_vehicle_id" => 'Este camión no está seleccionado para la jornada operativa.',
                ]);
            }
        }

        return [
            'provider_id' => $provider->id,
            'warehouse_id' => null,
            'vehicle_id' => $association->vehiculo_id,
            'plate' => $association->vehiculo->placa,
            'program_detail_id' => $programDetailId ? (int) $programDetailId : null,
        ];
    }

    private function loadTicket(TicketDespacho $ticket): TicketDespacho
    {
        return $ticket->load([
            'jornada',
            'clienteDestino',
            'almacenDestino',
            'pesadas',
        ]);
    }
}
