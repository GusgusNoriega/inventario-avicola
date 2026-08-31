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
use App\Models\RecepcionPolloVivoTicket;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    public function __construct(
        private readonly ScaleReadingService $scaleReadings,
        private readonly FinancialAuditService $audit,
        private readonly LiveChickenReceptionTicketInventoryService $receptionTicketInventory,
    ) {}

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
        $formattedReceptionRecords = $records
            ->map(fn (PesadaRecepcionPolloVivo $record): array => $this->formatRecord($record));
        $dispatchTicketRecords = $this->dispatchTicketRecords($reception);
        $displayRecords = $formattedReceptionRecords
            ->concat($dispatchTicketRecords)
            ->sortByDesc('weighed_at')
            ->values();
        $usedCageTypeIds = $records
            ->pluck('tipo_java_id')
            ->merge($dispatchTicketRecords->flatMap(
                fn (array $record): Collection => collect($record['weighings'] ?? [])
                    ->pluck('cage_type.id'),
            ))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $catalog = $this->catalog($companyId, (int) $branch->id, $usedCageTypeIds);
        $configuration = $this->effectiveConfiguration(
            (int) $branch->id,
            $catalog['warehouses'],
            $catalog['clients'],
            $catalog['external_owners'],
            $catalog['cage_types'],
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
            'records' => $displayRecords,
            'dispatch_tickets' => $dispatchTicketRecords->values(),
            'totals' => $this->totals($displayRecords),
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
        $this->assertClient($companyId, (int) $data['lane_5_client_id'], 'lane_5_client_id');
        $this->assertClient($companyId, (int) $data['lane_6_client_id'], 'lane_6_client_id');

        $captureDefaults = [];
        foreach ([
            'default_male_birds_per_cage' => 'aves_por_java_macho',
            'default_female_birds_per_cage' => 'aves_por_java_hembra',
            'default_cage_count' => 'cantidad_javas_predeterminada',
            'default_cage_type_id' => 'tipo_java_predeterminado_id',
        ] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $captureDefaults[$column] = $data[$field] === null ? null : (int) $data[$field];
            }
        }
        if (isset($data['default_cage_type_id']) && ! DB::table('tipos_java')
            ->where('id', (int) $data['default_cage_type_id'])
            ->where('estado', 'ACTIVO')
            ->exists()) {
            throw ValidationException::withMessages([
                'default_cage_type_id' => 'Selecciona un tipo de java activo como predeterminado.',
            ]);
        }

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
                ...$captureDefaults,
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
            $this->receptionTicketInventory->lockCompanyScope($companyId);
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
                $catalog['cage_types'],
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
                isset($data['dispatch_client_id']) ? (int) $data['dispatch_client_id'] : null,
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
        ?string $expectedUpdatedAt = null,
    ): void {
        DB::transaction(function () use ($companyId, $branch, $actor, $weighingId, $expectedUpdatedAt): void {
            $this->receptionTicketInventory->lockCompanyScope($companyId);
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

            if (filled($expectedUpdatedAt)
                && $weighing->updated_at
                && CarbonImmutable::parse($expectedUpdatedAt)->getTimestamp()
                    !== $weighing->updated_at->getTimestamp()) {
                abort(409, 'La pesada fue modificada por otro usuario. Vuelve a abrirla antes de eliminarla.');
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

    /** @param array<string, mixed> $data */
    public function update(
        int $companyId,
        object $branch,
        User $actor,
        int $weighingId,
        array $data,
        ?string $ip = null,
    ): void {
        DB::transaction(function () use (
            $companyId,
            $branch,
            $actor,
            $weighingId,
            $data,
            $ip,
        ): void {
            $this->receptionTicketInventory->lockCompanyScope($companyId);
            $record = PesadaRecepcionPolloVivo::query()
                ->whereKey($weighingId)
                ->whereHas('recepcion.jornada.sucursal', fn (Builder $query) => $query
                    ->whereKey($branch->id)
                    ->where('empresa_id', $companyId))
                ->with(['recepcion.jornada', 'tipoJava', 'lecturaBalanza.balanza'])
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($record->estado === PesadaRecepcionPolloVivo::STATUS_ACTIVE, 409, 'La pesada ya fue anulada.');

            if ($record->destino_tipo !== PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE) {
                throw ValidationException::withMessages([
                    'weighing' => 'Esta es una pesada histórica de despacho directo. Corrígela mediante un ticket registrado.',
                ]);
            }

            $journey = $record->recepcion?->jornada;
            $currentOperatingDate = $this->operatingDate($companyId, $branch->zona_horaria);
            if (! $journey
                || $journey->estado !== JornadaOperativa::STATUS_OPEN
                || $journey->fecha_operativa?->toDateString() !== $currentOperatingDate->toDateString()) {
                throw ValidationException::withMessages([
                    'weighing' => 'Solo se pueden corregir pesadas de la jornada actual mientras esté abierta.',
                ]);
            }

            if (filled($data['expected_updated_at'] ?? null)
                && $record->updated_at
                && CarbonImmutable::parse((string) $data['expected_updated_at'])->getTimestamp()
                    !== $record->updated_at->getTimestamp()) {
                abort(409, 'La pesada fue modificada por otro usuario. Vuelve a abrirla antes de guardar.');
            }

            $weighedAt = CarbonImmutable::parse((string) $data['weighed_at'])
                ->setTimezone($branch->zona_horaria);
            if ($weighedAt->greaterThan(CarbonImmutable::now($branch->zona_horaria)->addMinutes(5))) {
                throw ValidationException::withMessages([
                    'weighed_at' => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }
            if (! $this->operatingDate($companyId, $branch->zona_horaria, $weighedAt)
                ->isSameDay($currentOperatingDate)) {
                throw ValidationException::withMessages([
                    'weighed_at' => 'La pesada corregida debe permanecer en la jornada operativa actual.',
                ]);
            }

            $cageType = DB::table('tipos_java')
                ->where('id', (int) $data['cage_type_id'])
                ->lockForUpdate()
                ->first(['id', 'peso_kg', 'estado']);
            $keepsCurrentCageType = (int) $record->tipo_java_id === (int) ($cageType?->id ?? 0);
            if (! $cageType || ($cageType->estado !== 'ACTIVO' && ! $keepsCurrentCageType)) {
                throw ValidationException::withMessages([
                    'cage_type_id' => 'El tipo de java seleccionado no está disponible.',
                ]);
            }

            $catalog = $this->catalog($companyId, (int) $branch->id);
            $configuration = $this->effectiveConfiguration(
                (int) $branch->id,
                $catalog['warehouses'],
                $catalog['clients'],
                $catalog['external_owners'],
                $catalog['cage_types'],
            );
            $sex = (string) $data['sex'];
            $previousOwnerType = (string) $record->propietario_tipo;
            $newOwnerType = (string) ($data['owner_type'] ?? $previousOwnerType);
            $ownerTypeChanged = $newOwnerType !== $previousOwnerType;
            $newExternalOwnerId = null;
            if ($newOwnerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL) {
                $newExternalOwnerId = ! $ownerTypeChanged
                    ? (int) $record->propietario_externo_id
                    : (int) ($configuration['default_external_owner_id'] ?? 0);
                if (! $newExternalOwnerId) {
                    throw ValidationException::withMessages([
                        'owner_type' => 'Configura la empresa externa predeterminada antes de asignarle esta pesada.',
                    ]);
                }
                if ($ownerTypeChanged) {
                    $this->assertExternalOwner($companyId, $newExternalOwnerId, 'owner_type');
                }
            }

            $newLane = $newOwnerType === PesadaRecepcionPolloVivo::OWNER_OWN
                ? ($sex === Pesada::SEX_MALE ? 1 : 2)
                : ($sex === Pesada::SEX_MALE ? 3 : 4);
            $laneChanged = $newLane !== (int) $record->columna;
            $newWarehouseId = $laneChanged
                ? (int) ($configuration['lanes'][(string) $newLane]['destination_id'] ?? 0)
                : (int) $record->almacen_destino_id;
            if (! $newWarehouseId) {
                throw ValidationException::withMessages([
                    'sex' => 'Configura el almacén de la columna correspondiente antes de cambiar el sexo.',
                ]);
            }

            $cages = (int) $data['cage_count'];
            $birdsPerCage = (int) $data['birds_per_cage'];
            $birds = $cages * $birdsPerCage;
            $cageWeight = $keepsCurrentCageType
                ? round((float) $record->peso_java_kg_snapshot, 3)
                : round((float) $cageType->peso_kg, 3);
            $grossWeight = round((float) $data['read_weight_kg'], 3);
            $tareWeight = round($cages * $cageWeight, 3);
            $netWeight = round($grossWeight - $tareWeight, 3);
            if ($netWeight <= 0) {
                throw ValidationException::withMessages([
                    'read_weight_kg' => 'El peso leído debe ser mayor que la tara total de las javas.',
                ]);
            }

            $weightChanged = abs($grossWeight - (float) $record->peso_leido_kg) > 0.0005;
            $scaleReadingId = $record->lectura_balanza_id;
            $storedWeightSource = (string) $record->origen_peso;
            if ($weightChanged) {
                $source = $this->changedWeightSource($record, $data, 'weight_source');
                $scaleReading = $source === 'MANUAL'
                    ? null
                    : $this->recordChangedScaleReading(
                        (int) $branch->id,
                        $actor,
                        $data,
                        $source,
                        $weighedAt,
                        'weighing',
                    );
                $scaleReadingId = $scaleReading?->id;
                $storedWeightSource = $source === 'MANUAL' ? 'MANUAL' : 'BALANZA';
            }
            $before = $record->attributesToArray();
            $nextUpdatedAt = now();
            if ($record->updated_at
                && $nextUpdatedAt->getTimestamp() <= $record->updated_at->getTimestamp()) {
                $nextUpdatedAt = $record->updated_at->copy()->addSecond();
            }

            if ($previousOwnerType === PesadaRecepcionPolloVivo::OWNER_OWN) {
                if ($newOwnerType === PesadaRecepcionPolloVivo::OWNER_OWN) {
                    $this->applyReceptionWeighingCorrection(
                        $companyId,
                        $actor,
                        $record,
                        $newWarehouseId,
                        $cages,
                        $birds,
                        $netWeight,
                        $weighedAt,
                    );
                } else {
                    $this->reverseOwnInventory($companyId, $actor, $record);
                }
            }

            $record->fill([
                'columna' => $newLane,
                'propietario_tipo' => $newOwnerType,
                'propietario_externo_id' => $newExternalOwnerId,
                'almacen_destino_id' => $newWarehouseId,
                'cliente_destino_id' => null,
                'sexo' => $sex,
                'tipo_java_id' => (int) $cageType->id,
                'lectura_balanza_id' => $scaleReadingId,
                'origen_peso' => $storedWeightSource,
                'aves_por_java' => $birdsPerCage,
                'cantidad_javas' => $cages,
                'cantidad_aves' => $birds,
                'peso_java_kg_snapshot' => $cageWeight,
                'peso_leido_kg' => $grossWeight,
                'peso_bruto_kg' => $grossWeight,
                'tara_total_kg' => $tareWeight,
                'peso_neto_kg' => $netWeight,
                'pesada_at' => $weighedAt,
            ]);
            // `updated_at` no forma parte de los campos rellenables. Asignarlo
            // explícitamente garantiza que cada corrección genere un token de
            // concurrencia distinto, incluso dentro del mismo segundo.
            $record->setUpdatedAt($nextUpdatedAt);
            $record->save();
            if ($previousOwnerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL
                && $newOwnerType === PesadaRecepcionPolloVivo::OWNER_OWN) {
                $this->applyOwnInventory(
                    $companyId,
                    (int) $branch->id,
                    $journey,
                    $actor,
                    $record,
                );
            }
            $this->audit->record(
                $companyId,
                (int) $actor->id,
                'pesadas_recepcion_pollo_vivo',
                (int) $record->id,
                'ACTUALIZAR',
                $before,
                [
                    ...$record->fresh()->attributesToArray(),
                    'motivo_correccion' => trim((string) $data['correction_reason']),
                ],
                $ip,
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    /** @param array<int, int> $usedCageTypeIds */
    private function catalog(int $companyId, int $branchId, array $usedCageTypeIds = []): array
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
            ->orderBy('terceros.nombre_razon_social')
            ->get(['terceros.id', 'terceros.nombre_razon_social', 'terceros.numero_documento', 'terceros.es_cliente_interno'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->nombre_razon_social,
                'document_number' => $row->numero_documento,
                'is_internal_client' => (bool) $row->es_cliente_interno,
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
            ->where(function ($query) use ($usedCageTypeIds): void {
                $query->where('estado', 'ACTIVO');
                if ($usedCageTypeIds !== []) {
                    $query->orWhereIn('id', $usedCageTypeIds);
                }
            })
            ->orderByDesc('peso_kg')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'peso_kg', 'estado'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => $row->codigo,
                'name' => $row->nombre,
                'weight_kg' => (float) $row->peso_kg,
                'active' => $row->estado === 'ACTIVO',
            ])->values();
        $deliveryTrucks = DB::table('vehiculos')
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->orderBy('placa')
            ->get(['id', 'placa', 'marca', 'modelo', 'color', 'descripcion'])
            ->map(fn (object $truck): array => [
                'id' => (int) $truck->id,
                'plate' => $truck->placa,
                'brand' => $truck->marca,
                'model' => $truck->modelo,
                'color' => $truck->color,
                'description' => $truck->descripcion,
            ])->values();
        $deliveryDrivers = DB::table('conductores')
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre_completo')
            ->get(['id', 'nombre_completo', 'tipo_documento', 'numero_documento', 'telefono'])
            ->map(fn (object $driver): array => [
                'id' => (int) $driver->id,
                'name' => $driver->nombre_completo,
                'document_type' => $driver->tipo_documento,
                'document_number' => $driver->numero_documento,
                'phone' => $driver->telefono,
            ])->values();

        return [
            'warehouses' => $warehouses,
            'clients' => $clients,
            'external_owners' => $externalOwners,
            'cage_types' => $cageTypes,
            'delivery_trucks' => $deliveryTrucks,
            'delivery_drivers' => $deliveryDrivers,
            'scale' => [
                'code' => Balanza::CODE_LIVE_CHICKEN_RECEPTION,
                'name' => Balanza::logicalName(Balanza::CODE_LIVE_CHICKEN_RECEPTION),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function effectiveConfiguration(int $branchId, $warehouses, $clients, $externalOwners, Collection $cageTypes): array
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
        $activeCageTypes = $cageTypes->filter(fn (array $type): bool => $type['active']);
        $defaultCageType = $activeCageTypes->firstWhere('id', (int) ($saved?->tipo_java_predeterminado_id ?? 0))
            ?? $activeCageTypes->first(fn (array $type): bool => abs($type['weight_kg'] - 6.8) < 0.0005);

        return [
            'saved' => $saved !== null,
            'default_external_owner_id' => $externalOwnerId,
            'default_male_birds_per_cage' => (int) ($saved?->aves_por_java_macho ?? 7),
            'default_female_birds_per_cage' => (int) ($saved?->aves_por_java_hembra ?? 9),
            'default_cage_count' => (int) ($saved?->cantidad_javas_predeterminada ?? 5),
            'default_cage_type_id' => $defaultCageType['id'] ?? null,
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
    private function destinationForLane(
        int $companyId,
        int $branchId,
        int $lane,
        array $configuration,
        ?int $dispatchClientId,
    ): array {
        $laneConfiguration = $configuration['lanes'][(string) $lane] ?? null;
        $destinationId = (int) ($laneConfiguration['destination_id'] ?? 0);

        $destinationType = (string) ($laneConfiguration['type'] ?? '');

        if ($destinationType === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE) {
            if (! $destinationId) {
                throw ValidationException::withMessages([
                    'lane' => "Configura el almacén de la columna {$lane} antes de registrar.",
                ]);
            }

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

        if (! $dispatchClientId) {
            throw ValidationException::withMessages([
                'dispatch_client_id' => 'Selecciona el cliente que recibirá este despacho directo.',
            ]);
        }

        $this->assertClient($companyId, $dispatchClientId, 'dispatch_client_id');

        return [
            'type' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT,
            'warehouse_id' => null,
            'client_id' => $dispatchClientId,
        ];
    }

    private function applyOwnInventory(
        int $companyId,
        int $branchId,
        JornadaOperativa $journey,
        User $actor,
        PesadaRecepcionPolloVivo $weighing,
    ): void {
        $existingDetail = DB::table('movimiento_detalles')
            ->where('pesada_recepcion_pollo_vivo_id', $weighing->id)
            ->lockForUpdate()
            ->first();
        $existingMovement = $existingDetail
            ? DB::table('movimientos_inventario')
                ->where('id', $existingDetail->movimiento_id)
                ->lockForUpdate()
                ->first()
            : null;
        $otherMovementDetail = $existingDetail
            ? DB::table('movimiento_detalles')
                ->where('movimiento_id', $existingDetail->movimiento_id)
                ->where('id', '<>', $existingDetail->id)
                ->lockForUpdate()
                ->first()
            : null;
        if ($existingDetail
            && (! $existingMovement
                || (string) $existingMovement->estado !== 'ANULADO'
                || $existingMovement->ticket_id !== null
                || (string) $existingMovement->tipo !== 'ENTRADA_RECEPCION'
                || $otherMovementDetail)) {
            throw ValidationException::withMessages([
                'weighing' => 'La pesada tiene un movimiento de inventario activo o incompleto y no puede reasignarse.',
            ]);
        }

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

        $movementValues = [
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
            'updated_at' => now(),
        ];
        $detailValues = [
            'pesada_id' => null,
            'pesada_recepcion_pollo_vivo_id' => $weighing->id,
            'tipo_pollo_id' => $weighing->tipo_pollo_id,
            'cantidad_aves' => $weighing->cantidad_aves,
            'peso_neto_kg' => $weighing->peso_neto_kg,
        ];

        if ($existingDetail) {
            DB::table('movimientos_inventario')
                ->where('id', $existingDetail->movimiento_id)
                ->update($movementValues);
            DB::table('movimiento_detalles')
                ->where('id', $existingDetail->id)
                ->update($detailValues);
        } else {
            $movementId = DB::table('movimientos_inventario')->insertGetId([
                ...$movementValues,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
            DB::table('movimiento_detalles')->insert([
                ...$detailValues,
                'movimiento_id' => $movementId,
                'created_at' => now(),
            ]);
        }

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

    private function applyReceptionWeighingCorrection(
        int $companyId,
        User $actor,
        PesadaRecepcionPolloVivo $record,
        int $newWarehouseId,
        int $newCages,
        int $newBirds,
        float $newNetWeight,
        CarbonImmutable $weighedAt,
    ): void {
        $inventoryTrace = $this->lockConfirmedOwnInventoryMovement($record);
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first();
        if (! $inventory) {
            throw ValidationException::withMessages([
                'weighing' => 'No existe el inventario general que respalda esta pesada.',
            ]);
        }

        $nextCageTotal = (int) $inventory->cantidad_total
            - (int) $record->cantidad_javas
            + $newCages;

        $oldWarehouseId = (int) $record->almacen_destino_id;
        if ($oldWarehouseId === $newWarehouseId) {
            $this->adjustWarehouseStock(
                $oldWarehouseId,
                (int) $record->tipo_pollo_id,
                $newBirds - (int) $record->cantidad_aves,
                $newNetWeight - (float) $record->peso_neto_kg,
            );
        } else {
            $this->adjustWarehouseStock(
                $oldWarehouseId,
                (int) $record->tipo_pollo_id,
                -(int) $record->cantidad_aves,
                -(float) $record->peso_neto_kg,
            );
            $this->adjustWarehouseStock(
                $newWarehouseId,
                (int) $record->tipo_pollo_id,
                $newBirds,
                $newNetWeight,
            );
        }

        DB::table('movimiento_detalles')
            ->where('id', $inventoryTrace['detail']->id)
            ->update([
                'cantidad_aves' => $newBirds,
                'peso_neto_kg' => round($newNetWeight, 3),
            ]);
        DB::table('movimientos_inventario')
            ->where('id', $inventoryTrace['movement']->id)
            ->update([
                'almacen_destino_id' => $newWarehouseId,
                'fecha_hora' => $weighedAt,
                'updated_at' => now(),
            ]);
        $inventory->update([
            'cantidad_total' => $nextCageTotal,
            'updated_by' => $actor->id,
        ]);
    }

    private function adjustWarehouseStock(
        int $warehouseId,
        int $chickenTypeId,
        int $birdDelta,
        float $weightDelta,
    ): void {
        $stock = DB::table('existencias_almacen')
            ->where('almacen_id', $warehouseId)
            ->where('tipo_pollo_id', $chickenTypeId)
            ->lockForUpdate()
            ->first();
        $nextBirds = (int) ($stock?->cantidad_aves ?? 0) + $birdDelta;
        $nextWeight = round((float) ($stock?->peso_neto_kg ?? 0) + $weightDelta, 3);

        if ($nextBirds < 0 || $nextWeight < -0.0005) {
            throw ValidationException::withMessages([
                'weighing' => 'No se puede corregir porque parte de este ingreso ya salió del almacén.',
            ]);
        }

        if ($stock) {
            DB::table('existencias_almacen')
                ->where('almacen_id', $warehouseId)
                ->where('tipo_pollo_id', $chickenTypeId)
                ->update([
                    'cantidad_aves' => $nextBirds,
                    'peso_neto_kg' => max(0, $nextWeight),
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('existencias_almacen')->insert([
            'almacen_id' => $warehouseId,
            'tipo_pollo_id' => $chickenTypeId,
            'cantidad_aves' => $nextBirds,
            'peso_neto_kg' => max(0, $nextWeight),
            'updated_at' => now(),
        ]);
    }

    private function reverseOwnInventory(int $companyId, User $actor, PesadaRecepcionPolloVivo $weighing): void
    {
        $inventoryTrace = $this->lockConfirmedOwnInventoryMovement($weighing);
        $inventory = InventarioJava::query()
            ->where('empresa_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (! $inventory) {
            throw ValidationException::withMessages([
                'weighing' => 'No existe el inventario general que respalda esta pesada.',
            ]);
        }

        $this->assertJavaInventoryCanBeReversed($companyId, $weighing);

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
        DB::table('movimientos_inventario')
            ->where('id', $inventoryTrace['movement']->id)
            ->update([
                'estado' => 'ANULADO',
                'updated_at' => now(),
            ]);
    }

    /** @return array{detail: object, movement: object} */
    private function lockConfirmedOwnInventoryMovement(PesadaRecepcionPolloVivo $weighing): array
    {
        $expectedMovementType = $weighing->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE
            ? 'ENTRADA_RECEPCION'
            : 'DESPACHO_DIRECTO';
        $detail = DB::table('movimiento_detalles')
            ->where('pesada_recepcion_pollo_vivo_id', $weighing->id)
            ->lockForUpdate()
            ->first();
        $movement = $detail
            ? DB::table('movimientos_inventario')
                ->where('id', $detail->movimiento_id)
                ->lockForUpdate()
                ->first()
            : null;
        $otherDetail = $detail
            ? DB::table('movimiento_detalles')
                ->where('movimiento_id', $detail->movimiento_id)
                ->where('id', '<>', $detail->id)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $detail
            || ! $movement
            || (string) $movement->estado !== 'CONFIRMADO'
            || $movement->ticket_id !== null
            || (string) $movement->tipo !== $expectedMovementType
            || $otherDetail) {
            throw ValidationException::withMessages([
                'weighing' => 'La pesada no tiene un movimiento de inventario confirmado y exclusivo para corregirla.',
            ]);
        }

        return ['detail' => $detail, 'movement' => $movement];
    }

    private function assertJavaInventoryCanBeReversed(
        int $companyId,
        PesadaRecepcionPolloVivo $weighing,
    ): void {
        if ($weighing->destino_tipo !== PesadaRecepcionPolloVivo::DESTINATION_CLIENT) {
            return;
        }

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

    private function assertClient(int $companyId, int $clientId, string $field): void
    {
        $valid = DB::table('terceros')
            ->join('tercero_roles', 'tercero_roles.tercero_id', '=', 'terceros.id')
            ->where('terceros.id', $clientId)
            ->where('terceros.empresa_id', $companyId)
            ->where('terceros.estado', 'ACTIVO')
            ->where('tercero_roles.rol', TerceroRole::CLIENT)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                $field => 'El cliente de despacho debe estar activo y habilitado en tu empresa.',
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

    /** @return Collection<int, array<string, mixed>> */
    private function dispatchTicketRecords(?RecepcionPolloVivo $reception): Collection
    {
        if (! $reception) {
            return collect();
        }

        return RecepcionPolloVivoTicket::query()
            ->where('recepcion_id', $reception->id)
            ->whereHas('ticket', fn (Builder $query) => $query
                ->where('modulo_origen', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION)
                ->where('estado', '!=', TicketDespacho::STATUS_VOIDED))
            ->with([
                'ticket.clienteDestino:id,nombre_razon_social,es_cliente_interno',
                'ticket.vehiculoEntrega:id,placa',
                'ticket.conductorEntrega:id,nombre_completo',
                'ticket.pesadas' => fn ($query) => $query
                    ->where('estado', Pesada::STATUS_ACTIVE)
                    ->orderBy('numero'),
                'ticket.pesadas.tipoJava:id,codigo,nombre,peso_kg',
                'ticket.pesadas.lecturaBalanza.balanza:id,codigo,nombre',
            ])
            ->orderByDesc('id')
            ->get()
            ->flatMap(function (RecepcionPolloVivoTicket $link): Collection {
                $ticket = $link->ticket;

                if (! $ticket) {
                    return collect();
                }

                return $ticket->pesadas
                    ->groupBy('sexo')
                    ->filter(fn (Collection $weighings, mixed $sex): bool => $weighings->isNotEmpty()
                        && in_array($sex, [Pesada::SEX_MALE, Pesada::SEX_FEMALE], true))
                    ->map(fn (Collection $weighings, string $sex): array => $this->formatDispatchTicketRecord(
                        $link,
                        $ticket,
                        $sex,
                        $weighings,
                    ))
                    ->values();
            })
            ->values();
    }

    /**
     * @param  Collection<int, Pesada>  $weighings
     * @return array<string, mixed>
     */
    private function formatDispatchTicketRecord(
        RecepcionPolloVivoTicket $link,
        TicketDespacho $ticket,
        string $sex,
        Collection $weighings,
    ): array {
        /** @var Pesada $first */
        $first = $weighings->first();
        $last = $weighings
            ->sortByDesc(fn (Pesada $weighing): int => $weighing->pesada_at?->getTimestamp() ?? 0)
            ->first();
        $cageTypeIds = $weighings->pluck('tipo_java_id')->filter()->unique()->values();
        $birdsPerCage = $weighings->pluck('aves_por_java')->unique()->values();
        $weightSources = $weighings
            ->map(fn (Pesada $weighing): string => $this->dispatchWeightSource($weighing))
            ->unique()
            ->values();
        $singleCageType = $cageTypeIds->count() === 1;

        return [
            'record_kind' => 'dispatch_ticket',
            'row_key' => "ticket:{$ticket->id}:{$sex}",
            'id' => (int) $ticket->id,
            'number' => $ticket->codigo,
            'lane' => $sex === Pesada::SEX_MALE ? 1 : 2,
            'source_lane' => (int) $link->columna,
            'uses_previous_layout' => false,
            'editable_mode' => 'ticket',
            'dispatched' => true,
            'ticket_id' => (int) $ticket->id,
            'ticket_code' => $ticket->codigo,
            'ticket_status' => $ticket->estado,
            'ticket_source_module' => $ticket->modulo_origen,
            'ticket_revision' => (int) $link->revision,
            'ticket_registered_at' => $ticket->cerrado_at?->toISOString(),
            'owner' => [
                'type' => PesadaRecepcionPolloVivo::OWNER_OWN,
                'id' => null,
                'name' => 'Mi empresa',
            ],
            'destination' => [
                'type' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT,
                'id' => $ticket->cliente_destino_id ? (int) $ticket->cliente_destino_id : null,
                'name' => $ticket->clienteDestino?->nombre_razon_social,
            ],
            'delivery' => $ticket->vehiculoEntrega && $ticket->conductorEntrega
                ? [
                    'vehicle' => [
                        'id' => (int) $ticket->vehiculoEntrega->id,
                        'plate' => $ticket->vehiculoEntrega->placa,
                    ],
                    'driver' => [
                        'id' => (int) $ticket->conductorEntrega->id,
                        'name' => $ticket->conductorEntrega->nombre_completo,
                    ],
                ]
                : null,
            'sex' => $sex,
            'cage_type' => $singleCageType
                ? [
                    'id' => (int) $first->tipo_java_id,
                    'code' => $first->tipoJava?->codigo,
                    'name' => $first->tipoJava?->nombre,
                    'weight_kg' => (float) $first->peso_java_kg_snapshot,
                ]
                : [
                    'id' => null,
                    'code' => 'MIXTO',
                    'name' => 'Tipos de java mixtos',
                    'weight_kg' => null,
                ],
            'birds_per_cage' => $birdsPerCage->count() === 1
                ? (int) $birdsPerCage->first()
                : null,
            'cages' => (int) $weighings->sum('cantidad_javas'),
            'birds' => (int) $weighings->sum('cantidad_aves'),
            'read_weight_kg' => round((float) $weighings->sum('peso_leido_kg'), 3),
            'gross_weight_kg' => round((float) $weighings->sum('peso_bruto_kg'), 3),
            'tare_weight_kg' => round((float) $weighings->sum('tara_total_kg'), 3),
            'net_weight_kg' => round((float) $weighings->sum('peso_neto_kg'), 3),
            'weight_source' => $weightSources->count() === 1
                ? $weightSources->first()
                : 'MIXTO',
            'weighed_at' => $last?->pesada_at?->toISOString(),
            'weighing_count' => $weighings->count(),
            'weighing_ids' => $weighings->pluck('id')->map(fn (mixed $id): int => (int) $id)->values(),
            'weighings' => $weighings
                ->map(fn (Pesada $weighing): array => $this->formatDispatchWeighing($weighing))
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDispatchWeighing(Pesada $weighing): array
    {
        return [
            'id' => (int) $weighing->id,
            'number' => (int) $weighing->numero,
            'sex' => $weighing->sexo,
            'cage_type' => [
                'id' => (int) $weighing->tipo_java_id,
                'code' => $weighing->tipoJava?->codigo,
                'name' => $weighing->tipoJava?->nombre,
                'weight_kg' => (float) $weighing->peso_java_kg_snapshot,
            ],
            'birds_per_cage' => (int) $weighing->aves_por_java,
            'cages' => (int) $weighing->cantidad_javas,
            'birds' => (int) $weighing->cantidad_aves,
            'read_weight_kg' => (float) $weighing->peso_leido_kg,
            'gross_weight_kg' => (float) $weighing->peso_bruto_kg,
            'tare_weight_kg' => (float) $weighing->tara_total_kg,
            'net_weight_kg' => (float) $weighing->peso_neto_kg,
            'weight_source' => $this->dispatchWeightSource($weighing),
            'weighed_at' => $weighing->pesada_at?->toISOString(),
            'updated_at' => $weighing->updated_at?->toISOString(),
        ];
    }

    private function dispatchWeightSource(Pesada $weighing): string
    {
        if ($weighing->origen_peso === 'MANUAL') {
            return 'MANUAL';
        }

        return (string) ($weighing->lecturaBalanza?->balanza?->codigo ?: $weighing->origen_peso);
    }

    /** @param array<string, mixed> $input */
    private function changedWeightSource(
        PesadaRecepcionPolloVivo $record,
        array $input,
        string $field,
    ): string {
        $source = (string) ($input['weight_source'] ?? '');

        if ($source === '') {
            throw ValidationException::withMessages([
                $field => 'Indica MANUAL o adjunta una nueva lectura de balanza al cambiar el peso.',
            ]);
        }

        if ($source === 'BALANZA') {
            return (string) ($record->lecturaBalanza?->balanza?->codigo
                ?: Balanza::CODE_LIVE_CHICKEN_RECEPTION);
        }

        return $source;
    }

    /** @param array<string, mixed> $input */
    private function recordChangedScaleReading(
        int $branchId,
        User $actor,
        array $input,
        string $source,
        CarbonImmutable $weighedAt,
        string $field,
    ): object {
        $metadata = $input['scale_reading'] ?? null;
        if (! is_array($metadata) || collect($metadata)->filter(fn (mixed $value): bool => filled($value))->isEmpty()) {
            throw ValidationException::withMessages([
                "{$field}.scale_reading" => 'Adjunta la nueva lectura de balanza o registra la corrección como MANUAL.',
            ]);
        }

        return $this->scaleReadings->record(
            $branchId,
            $actor,
            [...$input, 'weight_source' => $source],
            $weighedAt,
            $field,
        );
    }

    /** @return array<string, mixed> */
    private function formatRecord(PesadaRecepcionPolloVivo $record): array
    {
        $destination = $record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE
            ? $record->almacenDestino?->nombre
            : $record->clienteDestino?->nombre_razon_social;
        $displayLane = $this->displayLane($record);
        $displayProfile = $this->laneProfile($displayLane);
        $isLegacyDirect = $record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_CLIENT;
        $usesPreviousLayout = $isLegacyDirect
            || $displayLane !== (int) $record->columna
            || $displayProfile['destination_type'] !== $record->destino_tipo
            || $displayProfile['owner_type'] !== $record->propietario_tipo
            || ($displayProfile['sex'] !== null && $displayProfile['sex'] !== $record->sexo);

        return [
            'record_kind' => $isLegacyDirect
                ? 'legacy_direct_weighing'
                : 'reception_weighing',
            'row_key' => "reception:{$record->id}",
            'id' => (int) $record->id,
            'number' => (int) $record->numero,
            'lane' => $displayLane,
            'source_lane' => (int) $record->columna,
            'uses_previous_layout' => $usesPreviousLayout,
            'editable_mode' => $isLegacyDirect ? 'readonly' : 'weighing',
            'dispatched' => $record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_CLIENT,
            'ticket_id' => null,
            'ticket_code' => null,
            'ticket_status' => null,
            'ticket_source_module' => null,
            'ticket_revision' => null,
            'ticket_registered_at' => null,
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
            'updated_at' => $record->updated_at?->toISOString(),
            'weighing_count' => 1,
            'weighing_ids' => [(int) $record->id],
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
    private function totals(Collection $records): array
    {
        $summary = static function ($items): array {
            return [
                'weighings' => (int) $items->sum(fn (array $item): int => (int) ($item['weighing_count'] ?? 1)),
                'cages' => (int) $items->sum('cages'),
                'birds' => (int) $items->sum('birds'),
                'male_birds' => (int) $items->where('sex', Pesada::SEX_MALE)->sum('birds'),
                'female_birds' => (int) $items->where('sex', Pesada::SEX_FEMALE)->sum('birds'),
                'gross_weight_kg' => round((float) $items->sum('gross_weight_kg'), 3),
                'tare_weight_kg' => round((float) $items->sum('tare_weight_kg'), 3),
                'net_weight_kg' => round((float) $items->sum('net_weight_kg'), 3),
            ];
        };

        return [
            'daily' => $summary($records),
            'own' => $summary($records->filter(
                fn (array $record): bool => data_get($record, 'owner.type') === PesadaRecepcionPolloVivo::OWNER_OWN,
            )),
            'external' => $summary($records->filter(
                fn (array $record): bool => data_get($record, 'owner.type') === PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
            )),
            'lanes' => collect(range(1, 6))->mapWithKeys(
                fn (int $lane): array => [
                    (string) $lane => $summary(
                        $records->filter(fn (array $record): bool => (int) $record['lane'] === $lane),
                    ),
                ],
            )->all(),
        ];
    }
}
