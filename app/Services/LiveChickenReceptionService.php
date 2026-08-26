<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\ConfiguracionRecepcionPolloVivo;
use App\Models\InventarioJava;
use App\Models\JornadaOperativa;
use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\RecepcionPolloVivo;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveChickenReceptionService
{
    private const LANE_PROFILES = [
        1 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_OWN, 'sex' => Pesada::SEX_MALE],
        2 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_OWN, 'sex' => Pesada::SEX_FEMALE],
        3 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_EXTERNAL, 'sex' => Pesada::SEX_MALE],
        4 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_EXTERNAL, 'sex' => Pesada::SEX_FEMALE],
        5 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_OWN, 'sex' => null],
        6 => ['destination_type' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT, 'owner_type' => PesadaRecepcionPolloVivo::OWNER_OWN, 'sex' => null],
    ];

    public function __construct(private readonly ScaleReadingService $scaleReadings) {}

    /** @return array<string, mixed> */
    public function overview(int $companyId, object $branch): array
    {
        $operatingDate = $this->operatingDate($companyId, $branch->zona_horaria);
        $journey = JornadaOperativa::query()
            ->where('sucursal_id', $branch->id)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->first();
        $reception = $journey
            ? RecepcionPolloVivo::query()->where('jornada_id', $journey->id)->first()
            : null;
        $records = $reception
            ? PesadaRecepcionPolloVivo::query()
                ->where('recepcion_id', $reception->id)
                ->where('estado', PesadaRecepcionPolloVivo::STATUS_ACTIVE)
                ->with([
                    'propietarioExterno:id,nombre_razon_social',
                    'almacenDestino:id,nombre',
                    'clienteDestino:id,nombre_razon_social',
                    'tipoJava:id,codigo,nombre,peso_kg',
                ])
                ->orderByDesc('numero')
                ->get()
            : collect();
        $catalog = $this->catalog($companyId, (int) $branch->id);
        $configuration = $this->effectiveConfiguration(
            (int) $branch->id,
            $catalog['warehouses'],
            $catalog['clients'],
            $catalog['external_owners'],
        );

        return [
            'branch' => [
                'id' => (int) $branch->id,
                'name' => $branch->nombre,
                'timezone' => $branch->zona_horaria,
            ],
            'company' => [
                'id' => $companyId,
                'name' => (string) DB::table('empresas')
                    ->where('id', $companyId)
                    ->value(DB::raw('COALESCE(nombre_comercial, razon_social)')),
            ],
            'operating_date' => $operatingDate->format('Y-m-d'),
            'journey' => $journey ? [
                'id' => (int) $journey->id,
                'status' => $journey->estado,
            ] : null,
            'reception' => $reception ? [
                'id' => (int) $reception->id,
                'origin' => $reception->origen,
                'status' => $reception->estado,
            ] : null,
            'configuration' => $configuration,
            'catalog' => $catalog,
            'records' => $records->map(fn (PesadaRecepcionPolloVivo $record): array => $this->formatRecord($record))->values(),
            'totals' => $this->totals($records),
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveConfiguration(
        int $companyId,
        int $branchId,
        User $actor,
        array $data,
    ): void {
        $externalOwnerId = isset($data['default_external_owner_id'])
            ? (int) $data['default_external_owner_id']
            : null;

        if ($externalOwnerId) {
            $this->assertExternalOwner($companyId, $externalOwnerId, 'default_external_owner_id');
        }

        $this->assertWarehouse($branchId, (int) $data['lane_1_warehouse_id'], 'lane_1_warehouse_id');
        $this->assertWarehouse($branchId, (int) $data['lane_2_warehouse_id'], 'lane_2_warehouse_id');
        $this->assertWarehouse($branchId, (int) $data['lane_3_warehouse_id'], 'lane_3_warehouse_id');
        $this->assertWarehouse($branchId, (int) $data['lane_4_warehouse_id'], 'lane_4_warehouse_id');
        $this->assertExternalClient($companyId, (int) $data['lane_5_client_id'], 'lane_5_client_id');
        $this->assertExternalClient($companyId, (int) $data['lane_6_client_id'], 'lane_6_client_id');

        ConfiguracionRecepcionPolloVivo::query()->updateOrCreate(
            ['sucursal_id' => $branchId],
            [
                'propietario_externo_predeterminado_id' => $externalOwnerId,
                'almacen_columna_1_id' => (int) $data['lane_1_warehouse_id'],
                'almacen_columna_2_id' => (int) $data['lane_2_warehouse_id'],
                'almacen_columna_3_id' => (int) $data['lane_3_warehouse_id'],
                'almacen_columna_4_id' => (int) $data['lane_4_warehouse_id'],
                'cliente_columna_3_id' => (int) $data['lane_5_client_id'],
                'cliente_columna_4_id' => (int) $data['lane_6_client_id'],
                'updated_by' => $actor->id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{weighing_id: int, already_registered: bool}
     */
    public function register(
        int $companyId,
        object $branch,
        User $actor,
        array $data,
    ): array {
        return DB::transaction(function () use ($companyId, $branch, $actor, $data): array {
            $existing = PesadaRecepcionPolloVivo::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertWeighingBelongsToBranch($existing, $companyId, (int) $branch->id);

                return ['weighing_id' => (int) $existing->id, 'already_registered' => true];
            }

            $weighedAt = CarbonImmutable::parse((string) $data['weighed_at'])
                ->setTimezone($branch->zona_horaria);
            $now = CarbonImmutable::now($branch->zona_horaria);

            if ($weighedAt->greaterThan($now->addMinutes(5))) {
                throw ValidationException::withMessages([
                    'weighed_at' => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }

            $operatingDate = $this->operatingDate($companyId, $branch->zona_horaria, $weighedAt);
            $currentOperatingDate = $this->operatingDate($companyId, $branch->zona_horaria, $now);

            if (! $operatingDate->isSameDay($currentOperatingDate)) {
                throw ValidationException::withMessages([
                    'weighed_at' => 'La pesada debe pertenecer al camión y la jornada operativa actuales.',
                ]);
            }

            $journey = $this->openJourney($companyId, $branch, $actor, $operatingDate);
            $reception = RecepcionPolloVivo::query()->firstOrCreate(
                ['jornada_id' => $journey->id],
                [
                    'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
                    'estado' => RecepcionPolloVivo::STATUS_OPEN,
                    'created_by' => $actor->id,
                ],
            );

            $catalog = $this->catalog($companyId, (int) $branch->id);
            $configuration = $this->effectiveConfiguration(
                (int) $branch->id,
                $catalog['warehouses'],
                $catalog['clients'],
                $catalog['external_owners'],
            );
            $lane = (int) $data['lane'];
            $profile = $this->laneProfile($lane);
            $ownerType = $profile['owner_type'];
            $externalOwnerId = $ownerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL
                ? (int) ($configuration['default_external_owner_id'] ?? 0)
                : null;

            if ($ownerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL && ! $externalOwnerId) {
                throw ValidationException::withMessages([
                    'lane' => 'Configura la empresa externa antes de usar las columnas 3 y 4.',
                ]);
            }

            if ($externalOwnerId) {
                $this->assertExternalOwner($companyId, $externalOwnerId, 'lane');
            }

            $sex = $profile['sex'] ?: (string) ($data['sex'] ?? '');
            $destination = $this->destinationForLane(
                $companyId,
                (int) $branch->id,
                $lane,
                $configuration,
            );
            $cageType = DB::table('tipos_java')
                ->where('id', (int) $data['cage_type_id'])
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first(['id', 'peso_kg']);

            if (! $cageType) {
                throw ValidationException::withMessages([
                    'cage_type_id' => 'El tipo de java seleccionado no está disponible.',
                ]);
            }

            $liveChickenTypeId = (int) DB::table('tipos_pollo')
                ->where('codigo', TipoPollo::CHICKEN_LIVE)
                ->where('estado', TipoPollo::STATUS_ACTIVE)
                ->value('id');

            if (! $liveChickenTypeId) {
                throw ValidationException::withMessages([
                    'chicken_type' => 'El tipo Pollo vivo no está disponible en el catálogo.',
                ]);
            }

            $cageCount = (int) $data['cage_count'];
            $birdsPerCage = (int) $data['birds_per_cage'];
            $cageWeight = round((float) $cageType->peso_kg, 3);
            $grossWeight = round((float) $data['read_weight_kg'], 3);
            $tareWeight = round($cageCount * $cageWeight, 3);
            $netWeight = round($grossWeight - $tareWeight, 3);

            if ($netWeight <= 0) {
                throw ValidationException::withMessages([
                    'read_weight_kg' => 'El peso leído debe ser mayor que la tara total de las javas.',
                ]);
            }

            $scaleReading = $this->scaleReadings->record(
                (int) $branch->id,
                $actor,
                $data,
                $weighedAt,
                'weighing',
            );
            $nextNumber = (int) PesadaRecepcionPolloVivo::query()
                ->where('recepcion_id', $reception->id)
                ->lockForUpdate()
                ->max('numero') + 1;
            $weighing = PesadaRecepcionPolloVivo::query()->create([
                'recepcion_id' => $reception->id,
                'idempotency_key' => $data['idempotency_key'],
                'numero' => $nextNumber,
                'columna' => $lane,
                'propietario_tipo' => $ownerType,
                'propietario_externo_id' => $externalOwnerId,
                'destino_tipo' => $destination['type'],
                'almacen_destino_id' => $destination['warehouse_id'],
                'cliente_destino_id' => $destination['client_id'],
                'sexo' => $sex,
                'tipo_pollo_id' => $liveChickenTypeId,
                'tipo_java_id' => (int) $cageType->id,
                'lectura_balanza_id' => $scaleReading?->id,
                'origen_peso' => (string) $data['weight_source'],
                'aves_por_java' => $birdsPerCage,
                'cantidad_javas' => $cageCount,
                'cantidad_aves' => $birdsPerCage * $cageCount,
                'peso_java_kg_snapshot' => $cageWeight,
                'peso_leido_kg' => $grossWeight,
                'peso_bruto_kg' => $grossWeight,
                'tara_total_kg' => $tareWeight,
                'peso_neto_kg' => $netWeight,
                'pesada_at' => $weighedAt,
                'estado' => PesadaRecepcionPolloVivo::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]);

            if ($ownerType === PesadaRecepcionPolloVivo::OWNER_OWN) {
                $this->applyOwnInventory(
                    $companyId,
                    (int) $branch->id,
                    $journey,
                    $actor,
                    $weighing,
                );
            }

            return ['weighing_id' => (int) $weighing->id, 'already_registered' => false];
        }, 3);
    }

    public function void(
        int $companyId,
        object $branch,
        User $actor,
        int $weighingId,
    ): void {
        DB::transaction(function () use ($companyId, $branch, $actor, $weighingId): void {
            $weighing = PesadaRecepcionPolloVivo::query()
                ->whereKey($weighingId)
                ->whereHas('recepcion.jornada.sucursal', fn (Builder $query) => $query
                    ->whereKey($branch->id)
                    ->where('empresa_id', $companyId))
                ->with('recepcion:id,jornada_id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($weighing->estado === PesadaRecepcionPolloVivo::STATUS_VOIDED) {
                return;
            }

            $journey = JornadaOperativa::query()
                ->whereKey($weighing->recepcion?->jornada_id)
                ->where('sucursal_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentOperatingDate = $this->operatingDate($companyId, $branch->zona_horaria);

            if ($journey->estado !== JornadaOperativa::STATUS_OPEN
                || $journey->fecha_operativa?->toDateString() !== $currentOperatingDate->toDateString()) {
                throw ValidationException::withMessages([
                    'weighing' => 'Solo se pueden anular pesadas de la jornada actual mientras esté abierta.',
                ]);
            }

            if ($weighing->propietario_tipo === PesadaRecepcionPolloVivo::OWNER_OWN) {
                $this->reverseOwnInventory($companyId, $actor, $weighing);
            }

            $weighing->update([
                'estado' => PesadaRecepcionPolloVivo::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => now(),
                'motivo_anulacion' => 'Anulada desde la recepción diaria de pollo vivo.',
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function catalog(int $companyId, int $branchId): array
    {
        $warehouses = DB::table('almacenes')
            ->where('sucursal_id', $branchId)
            ->where('estado', 'ACTIVO')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => $row->codigo,
                'name' => $row->nombre,
            ])->values();
        $clients = DB::table('terceros')
            ->join('tercero_roles', function ($join): void {
                $join->on('tercero_roles.tercero_id', '=', 'terceros.id')
                    ->where('tercero_roles.rol', TerceroRole::CLIENT);
            })
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->where('terceros.es_cliente_interno', false)
            ->orderBy('terceros.nombre_razon_social')
            ->get(['terceros.id', 'terceros.nombre_razon_social', 'terceros.numero_documento'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->nombre_razon_social,
                'document_number' => $row->numero_documento,
            ])->values();
        $externalOwners = DB::table('terceros')
            ->join('tercero_roles', function ($join): void {
                $join->on('tercero_roles.tercero_id', '=', 'terceros.id')
                    ->where('tercero_roles.rol', TerceroRole::PROVIDER);
            })
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->orderBy('terceros.nombre_razon_social')
            ->get(['terceros.id', 'terceros.nombre_razon_social', 'terceros.numero_documento'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->nombre_razon_social,
                'document_number' => $row->numero_documento,
            ])->values();
        $cageTypes = DB::table('tipos_java')
            ->where('estado', 'ACTIVO')
            ->orderByDesc('peso_kg')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'peso_kg'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => $row->codigo,
                'name' => $row->nombre,
                'weight_kg' => (float) $row->peso_kg,
            ])->values();

        return [
            'warehouses' => $warehouses,
            'clients' => $clients,
            'external_owners' => $externalOwners,
            'cage_types' => $cageTypes,
            'scale' => [
                'code' => Balanza::CODE_LIVE_CHICKEN_RECEPTION,
                'name' => Balanza::logicalName(Balanza::CODE_LIVE_CHICKEN_RECEPTION),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function effectiveConfiguration(int $branchId, $warehouses, $clients, $externalOwners): array
    {
        $saved = ConfiguracionRecepcionPolloVivo::query()->where('sucursal_id', $branchId)->first();
        $warehouseIds = $warehouses->pluck('id');
        $clientIds = $clients->pluck('id');
        $ownerIds = $externalOwners->pluck('id');
        $warehouseOne = $warehouseIds->first();
        $warehouseTwo = $warehouseIds->get(1, $warehouseOne);
        $clientFive = $clientIds->first();
        $clientSix = $clientIds->get(1, $clientFive);
        $externalOwnerId = $saved
            && $ownerIds->contains((int) $saved->propietario_externo_predeterminado_id)
                ? (int) $saved->propietario_externo_predeterminado_id
                : null;

        return [
            'saved' => $saved !== null,
            'default_external_owner_id' => $externalOwnerId,
            'lanes' => [
                '1' => $this->configuredLane(
                    1,
                    $saved && $warehouseIds->contains((int) $saved->almacen_columna_1_id)
                        ? (int) $saved->almacen_columna_1_id
                        : $warehouseOne,
                ),
                '2' => $this->configuredLane(
                    2,
                    $saved && $warehouseIds->contains((int) $saved->almacen_columna_2_id)
                        ? (int) $saved->almacen_columna_2_id
                        : $warehouseTwo,
                ),
                '3' => $this->configuredLane(
                    3,
                    $saved && $warehouseIds->contains((int) $saved->almacen_columna_3_id)
                        ? (int) $saved->almacen_columna_3_id
                        : $warehouseOne,
                ),
                '4' => $this->configuredLane(
                    4,
                    $saved && $warehouseIds->contains((int) $saved->almacen_columna_4_id)
                        ? (int) $saved->almacen_columna_4_id
                        : $warehouseTwo,
                ),
                '5' => $this->configuredLane(
                    5,
                    $saved && $clientIds->contains((int) $saved->cliente_columna_3_id)
                        ? (int) $saved->cliente_columna_3_id
                        : $clientFive,
                ),
                '6' => $this->configuredLane(
                    6,
                    $saved && $clientIds->contains((int) $saved->cliente_columna_4_id)
                        ? (int) $saved->cliente_columna_4_id
                        : $clientSix,
                ),
            ],
        ];
    }

    /** @return array{destination_type: string, owner_type: string, sex: ?string} */
    private function laneProfile(int $lane): array
    {
        $profile = self::LANE_PROFILES[$lane] ?? null;

        if (! $profile) {
            throw ValidationException::withMessages([
                'lane' => 'Selecciona una de las seis columnas de recepción.',
            ]);
        }

        return $profile;
    }

    /** @return array{type: string, owner_type: string, sex: ?string, requires_sex: bool, destination_id: ?int} */
    private function configuredLane(int $lane, ?int $destinationId): array
    {
        $profile = $this->laneProfile($lane);

        return [
            'type' => $profile['destination_type'],
            'owner_type' => $profile['owner_type'],
            'sex' => $profile['sex'],
            'requires_sex' => $profile['sex'] === null,
            'destination_id' => $destinationId,
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function destinationForLane(int $companyId, int $branchId, int $lane, array $configuration): array
    {
        $laneConfiguration = $configuration['lanes'][(string) $lane] ?? null;
        $destinationId = (int) ($laneConfiguration['destination_id'] ?? 0);

        $destinationType = (string) ($laneConfiguration['type'] ?? '');

        if (! $destinationId) {
            throw ValidationException::withMessages([
                'lane' => $destinationType === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE
                    ? "Configura el almacén de la columna {$lane} antes de registrar."
                    : "Configura el cliente de la columna {$lane} antes de registrar.",
            ]);
        }

        if ($destinationType === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE) {
            $this->assertWarehouse($branchId, $destinationId, 'lane');

            return [
                'type' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE,
                'warehouse_id' => $destinationId,
                'client_id' => null,
            ];
        }

        if ($destinationType !== PesadaRecepcionPolloVivo::DESTINATION_CLIENT) {
            throw ValidationException::withMessages([
                'lane' => 'La columna seleccionada no tiene un destino válido.',
            ]);
        }

        $this->assertExternalClient($companyId, $destinationId, 'lane');

        return [
            'type' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT,
            'warehouse_id' => null,
            'client_id' => $destinationId,
        ];
    }

    private function applyOwnInventory(
        int $companyId,
        int $branchId,
        JornadaOperativa $journey,
        User $actor,
        PesadaRecepcionPolloVivo $weighing,
    ): void {
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($inventory) {
            $inventory->update([
                'cantidad_total' => (int) $inventory->cantidad_total + (int) $weighing->cantidad_javas,
                'updated_by' => $actor->id,
            ]);
        } else {
            InventarioJava::query()->create([
                'empresa_id' => $companyId,
                'cantidad_total' => (int) $weighing->cantidad_javas,
                'cantidad_total_bandejas' => null,
                'updated_by' => $actor->id,
            ]);
        }

        $movementId = DB::table('movimientos_inventario')->insertGetId([
            'sucursal_id' => $branchId,
            'ticket_id' => null,
            'tipo' => $weighing->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE
                ? 'ENTRADA_RECEPCION'
                : 'DESPACHO_DIRECTO',
            'almacen_origen_id' => null,
            'almacen_destino_id' => $weighing->almacen_destino_id,
            'tercero_origen_id' => null,
            'tercero_destino_id' => $weighing->cliente_destino_id,
            'estado' => 'CONFIRMADO',
            'fecha_hora' => $weighing->pesada_at,
            'confirmado_por' => $actor->id,
            'confirmado_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('movimiento_detalles')->insert([
            'movimiento_id' => $movementId,
            'pesada_id' => null,
            'pesada_recepcion_pollo_vivo_id' => $weighing->id,
            'tipo_pollo_id' => $weighing->tipo_pollo_id,
            'cantidad_aves' => $weighing->cantidad_aves,
            'peso_neto_kg' => $weighing->peso_neto_kg,
            'created_at' => now(),
        ]);

        if ($weighing->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE) {
            $this->increaseWarehouseStock($weighing);

            return;
        }

        MovimientoJava::query()->create([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'jornada_id' => $journey->id,
            'cliente_id' => $weighing->cliente_destino_id,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => $weighing->cantidad_javas,
            'cantidad_bandejas' => 0,
            'ticket_despacho_id' => null,
            'pesada_recepcion_pollo_vivo_id' => $weighing->id,
            'vehiculo_id' => null,
            'conductor_id' => null,
            'fecha_movimiento' => $weighing->pesada_at,
            'observaciones' => 'Despacho directo desde la recepción de pollo vivo.',
            'created_by' => $actor->id,
        ]);
    }

    private function increaseWarehouseStock(PesadaRecepcionPolloVivo $weighing): void
    {
        $stock = DB::table('existencias_almacen')
            ->where('almacen_id', $weighing->almacen_destino_id)
            ->where('tipo_pollo_id', $weighing->tipo_pollo_id)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            DB::table('existencias_almacen')
                ->where('almacen_id', $weighing->almacen_destino_id)
                ->where('tipo_pollo_id', $weighing->tipo_pollo_id)
                ->update([
                    'cantidad_aves' => (int) $stock->cantidad_aves + (int) $weighing->cantidad_aves,
                    'peso_neto_kg' => round((float) $stock->peso_neto_kg + (float) $weighing->peso_neto_kg, 3),
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('existencias_almacen')->insert([
            'almacen_id' => $weighing->almacen_destino_id,
            'tipo_pollo_id' => $weighing->tipo_pollo_id,
            'cantidad_aves' => $weighing->cantidad_aves,
            'peso_neto_kg' => $weighing->peso_neto_kg,
            'updated_at' => now(),
        ]);
    }

    private function reverseOwnInventory(int $companyId, User $actor, PesadaRecepcionPolloVivo $weighing): void
    {
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (! $inventory || (int) $inventory->cantidad_total < (int) $weighing->cantidad_javas) {
            throw ValidationException::withMessages([
                'weighing' => 'No se puede anular porque el total actual de javas ya no cubre esta recepción.',
            ]);
        }

        $this->assertJavaInventoryCanBeReversed($companyId, $inventory, $weighing);

        if ($weighing->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE) {
            $stock = DB::table('existencias_almacen')
                ->where('almacen_id', $weighing->almacen_destino_id)
                ->where('tipo_pollo_id', $weighing->tipo_pollo_id)
                ->lockForUpdate()
                ->first();

            if (! $stock
                || (int) $stock->cantidad_aves < (int) $weighing->cantidad_aves
                || (float) $stock->peso_neto_kg + 0.0005 < (float) $weighing->peso_neto_kg) {
                throw ValidationException::withMessages([
                    'weighing' => 'No se puede anular porque parte de este ingreso ya salió del almacén.',
                ]);
            }

            DB::table('existencias_almacen')
                ->where('almacen_id', $weighing->almacen_destino_id)
                ->where('tipo_pollo_id', $weighing->tipo_pollo_id)
                ->update([
                    'cantidad_aves' => (int) $stock->cantidad_aves - (int) $weighing->cantidad_aves,
                    'peso_neto_kg' => round((float) $stock->peso_neto_kg - (float) $weighing->peso_neto_kg, 3),
                    'updated_at' => now(),
                ]);
        }

        DB::table('movimientos_javas')
            ->where('pesada_recepcion_pollo_vivo_id', $weighing->id)
            ->delete();

        $inventory->update([
            'cantidad_total' => (int) $inventory->cantidad_total - (int) $weighing->cantidad_javas,
            'updated_by' => $actor->id,
        ]);
        $movementId = DB::table('movimiento_detalles')
            ->where('pesada_recepcion_pollo_vivo_id', $weighing->id)
            ->value('movimiento_id');

        if ($movementId) {
            DB::table('movimientos_inventario')->where('id', $movementId)->update([
                'estado' => 'ANULADO',
                'updated_at' => now(),
            ]);
        }
    }

    private function assertJavaInventoryCanBeReversed(
        int $companyId,
        InventarioJava $inventory,
        PesadaRecepcionPolloVivo $weighing,
    ): void {
        $movements = MovimientoJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->get([
                'cliente_id',
                'tipo',
                'cantidad',
                'pesada_recepcion_pollo_vivo_id',
            ]);
        $adjustments = DB::table('ajustes_saldos_javas')
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->get(['cliente_id', 'diferencia_javas']);
        $balances = [];

        foreach ($movements as $movement) {
            $clientId = (int) $movement->cliente_id;
            $balances[$clientId] = ($balances[$clientId] ?? 0) + match ($movement->tipo) {
                MovimientoJava::TYPE_DISPATCH => (int) $movement->cantidad,
                MovimientoJava::TYPE_RECEIPT => -(int) $movement->cantidad,
                default => 0,
            };
        }

        foreach ($adjustments as $adjustment) {
            $clientId = (int) $adjustment->cliente_id;
            $balances[$clientId] = ($balances[$clientId] ?? 0) + (int) $adjustment->diferencia_javas;
        }

        if ($weighing->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_CLIENT) {
            $directMovement = $movements->first(
                fn (MovimientoJava $movement): bool => (int) $movement->pesada_recepcion_pollo_vivo_id === (int) $weighing->id,
            );

            if (! $directMovement
                || (int) $directMovement->cantidad !== (int) $weighing->cantidad_javas) {
                throw ValidationException::withMessages([
                    'weighing' => 'No se puede anular porque el despacho de javas ya no coincide con esta pesada.',
                ]);
            }

            $clientId = (int) $directMovement->cliente_id;
            $balances[$clientId] = ($balances[$clientId] ?? 0) - (int) $directMovement->cantidad;

            if ($balances[$clientId] < 0) {
                throw ValidationException::withMessages([
                    'weighing' => 'No se puede anular porque el cliente ya devolvió javas asociadas a este despacho.',
                ]);
            }
        }

        $nextInventoryTotal = (int) $inventory->cantidad_total - (int) $weighing->cantidad_javas;
        $assignedToClients = (int) collect($balances)
            ->filter(fn (int $balance): bool => $balance > 0)
            ->sum();

        if ($nextInventoryTotal < $assignedToClients) {
            throw ValidationException::withMessages([
                'weighing' => "No se puede anular: quedarían {$assignedToClients} javas con clientes y solo {$nextInventoryTotal} en el inventario general.",
            ]);
        }
    }

    private function openJourney(
        int $companyId,
        object $branch,
        User $actor,
        CarbonImmutable $operatingDate,
    ): JornadaOperativa {
        $journey = JornadaOperativa::query()
            ->where('sucursal_id', $branch->id)
            ->whereDate('fecha_operativa', $operatingDate->format('Y-m-d'))
            ->lockForUpdate()
            ->first();

        if (! $journey) {
            $cutoff = $this->cutoff($companyId);
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
                'journey' => 'La jornada operativa actual ya está cerrada.',
            ]);
        }

        return $journey;
    }

    private function operatingDate(
        int $companyId,
        string $timezone,
        ?CarbonImmutable $at = null,
    ): CarbonImmutable {
        $at ??= CarbonImmutable::now($timezone);
        $at = $at->setTimezone($timezone);
        $cutoffAt = $at->startOfDay()->setTimeFromTimeString($this->cutoff($companyId));

        return $at->greaterThanOrEqualTo($cutoffAt)
            ? $at->addDay()->startOfDay()
            : $at->startOfDay();
    }

    private function cutoff(int $companyId): string
    {
        return (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
    }

    private function assertExternalOwner(int $companyId, int $ownerId, string $field): void
    {
        $valid = DB::table('terceros')
            ->join('tercero_roles', 'tercero_roles.tercero_id', '=', 'terceros.id')
            ->where('terceros.id', $ownerId)
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->where('tercero_roles.rol', TerceroRole::PROVIDER)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => 'La empresa externa debe ser un proveedor activo de tu empresa.',
            ]);
        }
    }

    private function assertWarehouse(int $branchId, int $warehouseId, string $field): void
    {
        $valid = DB::table('almacenes')
            ->where('id', $warehouseId)
            ->where('sucursal_id', $branchId)
            ->where('estado', 'ACTIVO')
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => 'El almacén seleccionado no pertenece a esta sucursal o está inactivo.',
            ]);
        }
    }

    private function assertExternalClient(int $companyId, int $clientId, string $field): void
    {
        $valid = DB::table('terceros')
            ->join('tercero_roles', 'tercero_roles.tercero_id', '=', 'terceros.id')
            ->where('terceros.id', $clientId)
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->where('terceros.es_cliente_interno', false)
            ->where('tercero_roles.rol', TerceroRole::CLIENT)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => 'El cliente de despacho debe ser un cliente externo activo de tu empresa.',
            ]);
        }
    }

    private function assertWeighingBelongsToBranch(
        PesadaRecepcionPolloVivo $weighing,
        int $companyId,
        int $branchId,
    ): void {
        $belongs = DB::table('pesadas_recepcion_pollo_vivo')
            ->join('recepciones_pollo_vivo', 'recepciones_pollo_vivo.id', '=', 'pesadas_recepcion_pollo_vivo.recepcion_id')
            ->join('jornadas_operativas', 'jornadas_operativas.id', '=', 'recepciones_pollo_vivo.jornada_id')
            ->join('sucursales', 'sucursales.id', '=', 'jornadas_operativas.sucursal_id')
            ->where('pesadas_recepcion_pollo_vivo.id', $weighing->id)
            ->where('jornadas_operativas.sucursal_id', $branchId)
            ->where('sucursales.empresa_id', $companyId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'weighing' => 'La pesada no pertenece a esta empresa y sucursal.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function formatRecord(PesadaRecepcionPolloVivo $record): array
    {
        $destination = $record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE
            ? $record->almacenDestino?->nombre
            : $record->clienteDestino?->nombre_razon_social;
        $displayLane = $this->displayLane($record);
        $displayProfile = $this->laneProfile($displayLane);
        $usesPreviousLayout = $displayLane !== (int) $record->columna
            || $displayProfile['destination_type'] !== $record->destino_tipo
            || $displayProfile['owner_type'] !== $record->propietario_tipo
            || ($displayProfile['sex'] !== null && $displayProfile['sex'] !== $record->sexo);

        return [
            'id' => (int) $record->id,
            'number' => (int) $record->numero,
            'lane' => $displayLane,
            'uses_previous_layout' => $usesPreviousLayout,
            'owner' => [
                'type' => $record->propietario_tipo,
                'id' => $record->propietario_externo_id ? (int) $record->propietario_externo_id : null,
                'name' => $record->propietario_tipo === PesadaRecepcionPolloVivo::OWNER_OWN
                    ? 'Mi empresa'
                    : ($record->propietarioExterno?->nombre_razon_social ?: 'Empresa externa'),
            ],
            'destination' => [
                'type' => $record->destino_tipo,
                'id' => $record->almacen_destino_id
                    ? (int) $record->almacen_destino_id
                    : ($record->cliente_destino_id ? (int) $record->cliente_destino_id : null),
                'name' => $destination,
            ],
            'sex' => $record->sexo,
            'cage_type' => [
                'id' => (int) $record->tipo_java_id,
                'code' => $record->tipoJava?->codigo,
                'name' => $record->tipoJava?->nombre,
                'weight_kg' => (float) $record->peso_java_kg_snapshot,
            ],
            'birds_per_cage' => (int) $record->aves_por_java,
            'cages' => (int) $record->cantidad_javas,
            'birds' => (int) $record->cantidad_aves,
            'read_weight_kg' => (float) $record->peso_leido_kg,
            'gross_weight_kg' => (float) $record->peso_bruto_kg,
            'tare_weight_kg' => (float) $record->tara_total_kg,
            'net_weight_kg' => (float) $record->peso_neto_kg,
            'weight_source' => $record->origen_peso,
            'weighed_at' => $record->pesada_at?->toISOString(),
        ];
    }

    private function displayLane(PesadaRecepcionPolloVivo $record): int
    {
        $storedLane = (int) $record->columna;

        // En la distribución anterior 3 y 4 eran despachos directos. Su tipo
        // de destino permite presentarlos en 5 y 6 sin alterar el historial.
        if ($record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_CLIENT
            && in_array($storedLane, [3, 4], true)) {
            return $storedLane + 2;
        }

        return $storedLane;
    }

    /** @return array<string, mixed> */
    private function totals($records): array
    {
        $summary = static function ($items): array {
            return [
                'weighings' => $items->count(),
                'cages' => (int) $items->sum('cantidad_javas'),
                'birds' => (int) $items->sum('cantidad_aves'),
                'gross_weight_kg' => round((float) $items->sum('peso_bruto_kg'), 3),
                'tare_weight_kg' => round((float) $items->sum('tara_total_kg'), 3),
                'net_weight_kg' => round((float) $items->sum('peso_neto_kg'), 3),
            ];
        };

        return [
            'daily' => $summary($records),
            'own' => $summary($records->where('propietario_tipo', PesadaRecepcionPolloVivo::OWNER_OWN)),
            'external' => $summary($records->where('propietario_tipo', PesadaRecepcionPolloVivo::OWNER_EXTERNAL)),
            'lanes' => collect(range(1, 6))->mapWithKeys(
                fn (int $lane): array => [
                    (string) $lane => $summary(
                        $records->filter(fn (PesadaRecepcionPolloVivo $record): bool => $this->displayLane($record) === $lane),
                    ),
                ],
            )->all(),
        ];
    }
}
