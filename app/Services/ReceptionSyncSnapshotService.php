<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\Empresa;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\RecepcionPolloVivoTicket;
use App\Models\ReceptionSyncToken;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReceptionSyncSnapshotService
{
    public const PAGE_SIZE = 200;

    public const TTL_HOURS = 24;

    public const MAX_ACTIVE_SNAPSHOTS = 3;

    public function __construct(
        private readonly LiveChickenReceptionService $reception,
        private readonly TicketTitleService $ticketTitles,
        private readonly TicketMessageService $ticketMessages,
        private readonly ReceptionSyncPayloadService $payloads,
    ) {}

    /** Materialize the complete module dataset once so subsequent pages never drift. */
    public function create(ReceptionSyncToken $token, object $branch): array
    {
        abort_unless(
            (int) $token->empresa_id === (int) $branch->empresa_id
                && (int) $token->sucursal_id === (int) $branch->id,
            403,
        );

        return retry(3, function () use ($token, $branch): array {
            // Set this before EACH attempt; a rollback consumes a transaction-only setting.
            // Do not change SESSION isolation, which could leak into subsequent requests.
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                abort_if(DB::transactionLevel() !== 0, 409, 'La descarga debe iniciarse fuera de otra transacción.');
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return DB::transaction(function () use ($token, $branch): array {
                // Share the push mutex. Locking empresa first can deadlock against a push's
                // branch lock when their foreign-key checks acquire the inverse parent locks.
                DB::table('sucursales')->where('id', $token->sucursal_id)
                    ->where('empresa_id', $token->empresa_id)->lockForUpdate()->firstOrFail();
                $now = CarbonImmutable::now()->startOfSecond();
                $scope = DB::table('reception_sync_snapshots')
                    ->where('company_id', $token->empresa_id)
                    ->where('branch_id', $token->sucursal_id)
                    ->where('device_id', $token->device_id);
                (clone $scope)->where('expires_at', '<=', $now)->delete();
                $activeCount = (clone $scope)->where('expires_at', '>', $now)->lockForUpdate()->get(['id'])->count();
                abort_if(
                    $activeCount >= self::MAX_ACTIVE_SNAPSHOTS,
                    429,
                    'Este dispositivo ya tiene tres descargas vigentes. Reanuda una descarga o espera su vencimiento.',
                );

                $snapshot = (object) [
                    'id' => (string) Str::uuid(),
                    'company_id' => (int) $token->empresa_id,
                    'branch_id' => (int) $token->sucursal_id,
                    'device_id' => (string) $token->device_id,
                    'schema_version' => 1,
                    'total_items' => 0,
                    'created_at' => $now,
                    'expires_at' => $now->addHours(self::TTL_HOURS),
                ];
                DB::table('reception_sync_snapshots')->insert((array) $snapshot);
                $batch = [];
                $sequence = 0;

                foreach ($this->items($token, $branch) as $item) {
                    $batch[] = [
                        'snapshot_id' => $snapshot->id,
                        'sequence' => ++$sequence,
                        'entity' => $item['entity'],
                        'entity_key' => $item['key'],
                        'data' => json_encode($item['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ];
                    if (count($batch) >= self::PAGE_SIZE) {
                        DB::table('reception_sync_snapshot_items')->insert($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    DB::table('reception_sync_snapshot_items')->insert($batch);
                }
                $snapshot->total_items = $sequence;
                DB::table('reception_sync_snapshots')->where('id', $snapshot->id)->update(['total_items' => $sequence]);

                return [...$this->metadata($snapshot), 'page_size' => self::PAGE_SIZE, 'after' => 0];
            });
        }, 0, fn (Throwable $exception): bool => DB::transactionLevel() === 0
            && app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception));
    }

    /** Recover a download identifier when the response to its creation was lost. */
    public function listActive(ReceptionSyncToken $token): array
    {
        return DB::table('reception_sync_snapshots')
            ->where('company_id', $token->empresa_id)
            ->where('branch_id', $token->sucursal_id)
            ->where('device_id', $token->device_id)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $snapshot): array => [...$this->metadata($snapshot), 'page_size' => self::PAGE_SIZE, 'after' => 0])
            ->all();
    }

    public function page(ReceptionSyncToken $token, string $snapshotId, int $after = 0, int $limit = self::PAGE_SIZE): array
    {
        abort_if($after < 0 || $limit < 1 || $limit > self::PAGE_SIZE, 422, 'La página admite entre 1 y 200 elementos y un cursor no negativo.');

        return DB::transaction(function () use ($token, $snapshotId, $after, $limit): array {
            $snapshot = DB::table('reception_sync_snapshots')
                ->where('id', $snapshotId)
                ->where('company_id', $token->empresa_id)
                ->where('branch_id', $token->sucursal_id)
                ->where('device_id', $token->device_id)
                ->sharedLock()
                ->first();
            abort_unless($snapshot, 404, 'Descarga no encontrada.');
            abort_if(CarbonImmutable::parse($snapshot->expires_at)->isPast(), 410, 'Esta descarga venció. Inicia una nueva descarga completa.');
            abort_if($after > (int) $snapshot->total_items, 422, 'El cursor no pertenece a esta descarga.');

            $items = DB::table('reception_sync_snapshot_items')
                ->where('snapshot_id', $snapshot->id)
                ->where('sequence', '>', $after)
                ->orderBy('sequence')
                ->limit($limit)
                ->get()
                ->map(fn (object $item): array => [
                    'sequence' => (int) $item->sequence,
                    'entity' => (string) $item->entity,
                    'key' => (string) $item->entity_key,
                    'data' => json_decode($item->data, true, 512, JSON_THROW_ON_ERROR),
                ]);
            $lastSequence = $items->isEmpty() ? $after : (int) $items->last()['sequence'];
            $hasMore = $lastSequence < (int) $snapshot->total_items;

            return [
                'snapshot' => $this->metadata($snapshot),
                'items' => $items->all(),
                'next_after' => $hasMore ? $lastSequence : null,
                'has_more' => $hasMore,
            ];
        });
    }

    /** Release a completed download without preventing retries before explicit release. */
    public function release(ReceptionSyncToken $token, string $snapshotId): void
    {
        DB::transaction(function () use ($token, $snapshotId): void {
            $snapshot = DB::table('reception_sync_snapshots')
                ->where('id', $snapshotId)
                ->where('company_id', $token->empresa_id)
                ->where('branch_id', $token->sucursal_id)
                ->where('device_id', $token->device_id)
                ->lockForUpdate()
                ->first();
            abort_unless($snapshot, 404, 'Descarga no encontrada.');
            DB::table('reception_sync_snapshots')->where('id', $snapshot->id)->delete();
        });
    }

    /** Expired headers cascade to their materialized items. */
    public function pruneExpired(): int
    {
        return DB::table('reception_sync_snapshots')->where('expires_at', '<=', now())->delete();
    }

    private function metadata(object $snapshot): array
    {
        return [
            'id' => (string) $snapshot->id,
            'schema_version' => (int) $snapshot->schema_version,
            'mode' => 'full',
            'created_at' => $this->iso($snapshot->created_at),
            'expires_at' => $this->iso($snapshot->expires_at),
            'total_items' => (int) $snapshot->total_items,
        ];
    }

    private function items(ReceptionSyncToken $token, object $branch): Generator
    {
        $companyId = (int) $token->empresa_id;
        $branchId = (int) $token->sucursal_id;
        $company = Empresa::query()->findOrFail($companyId);
        // Read the branch inside the same database snapshot as every other item.
        $branch = DB::table('sucursales')->where('id', $branchId)->where('empresa_id', $companyId)->firstOrFail();
        yield $this->item('company', $companyId, [
            'id' => $companyId,
            'name' => $company->nombre_comercial ?: $company->razon_social,
            'legal_name' => $company->razon_social,
            'tax_id' => $company->ruc,
            'country_code' => $company->pais_codigo,
            'currency' => $company->moneda,
            'timezone' => $company->zona_horaria,
            'operating_cutoff' => $company->hora_corte_operativo ?: '21:00:00',
            'ticket_title' => $this->ticketTitles->normalize($company->titulo_ticket),
            'ticket_message' => $this->ticketMessages->normalize($company->mensaje_ticket),
        ]);
        yield $this->item('branch', $branchId, [
            'id' => $branchId,
            'company_id' => $companyId,
            'code' => $branch->codigo,
            'name' => $branch->nombre,
            'address' => $branch->direccion,
            'timezone' => $branch->zona_horaria,
        ]);
        yield $this->item('configuration', $branchId, [
            ...$this->reception->configurationForSync($companyId, $branchId),
            'layout_version' => 4,
            'precision_decimals' => 3,
            'inventory_tracking' => false,
            'tray_tracking' => false,
        ]);

        foreach (['client' => TerceroRole::CLIENT, 'external_owner' => TerceroRole::PROVIDER] as $entity => $role) {
            $counterparties = DB::table('terceros')->where('empresa_id', $companyId)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('tercero_roles')
                    ->whereColumn('tercero_roles.tercero_id', 'terceros.id')->where('rol', $role))
                ->select(['id', 'nombre_razon_social', 'tipo_documento', 'numero_documento', 'direccion', 'telefono', 'email', 'es_cliente_interno', 'estado', 'updated_at'])
                ->lazyById(self::PAGE_SIZE);
            foreach ($counterparties as $row) {
                yield $this->item($entity, $row->id, [
                    'id' => (int) $row->id,
                    'name' => $row->nombre_razon_social,
                    'document_type' => $row->tipo_documento,
                    'document_number' => $row->numero_documento,
                    'address' => $row->direccion,
                    'phone' => $row->telefono,
                    'email' => $row->email,
                    'is_internal_client' => (bool) $row->es_cliente_interno,
                    'role' => $role,
                    ...$this->catalogState($row),
                ]);
            }
        }

        foreach (DB::table('almacenes')->where('sucursal_id', $branchId)->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('warehouse', $row->id, [
                'id' => (int) $row->id, 'code' => $row->codigo, 'name' => $row->nombre, ...$this->catalogState($row),
            ]);
        }
        foreach (DB::table('tipos_java')->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('cage_type', $row->id, [
                'id' => (int) $row->id, 'code' => $row->codigo, 'name' => $row->nombre,
                'weight_kg' => number_format((float) $row->peso_kg, 3, '.', ''), ...$this->catalogState($row),
            ]);
        }
        foreach (DB::table('vehiculos')->where('empresa_id', $companyId)->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('vehicle', $row->id, [
                'id' => (int) $row->id, 'plate' => $row->placa, 'brand' => $row->marca,
                'model' => $row->modelo, 'color' => $row->color, 'description' => $row->descripcion,
                ...$this->catalogState($row),
            ]);
        }
        foreach (DB::table('conductores')->where('empresa_id', $companyId)->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('driver', $row->id, [
                'id' => (int) $row->id, 'name' => $row->nombre_completo,
                'document_type' => $row->tipo_documento, 'document_number' => $row->numero_documento,
                'phone' => $row->telefono, ...$this->catalogState($row),
            ]);
        }
        foreach (DB::table('tipos_pollo')->where('codigo', TipoPollo::CHICKEN_LIVE)->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('chicken_type', $row->id, [
                'id' => (int) $row->id, 'code' => $row->codigo, 'name' => $row->nombre, ...$this->catalogState($row),
            ]);
        }
        yield $this->item('scale', Balanza::CODE_LIVE_CHICKEN_RECEPTION, [
            'code' => Balanza::CODE_LIVE_CHICKEN_RECEPTION,
            'name' => Balanza::logicalName(Balanza::CODE_LIVE_CHICKEN_RECEPTION),
            'weight_sources' => ['MANUAL', 'BALANZA'],
            'connection_modes' => ['SERIAL', 'BLE', 'BLUETOOTH'],
        ]);

        $journeys = DB::table('jornadas_operativas as journey')
            ->join('recepciones_pollo_vivo as reception', 'reception.jornada_id', '=', 'journey.id')
            ->where('journey.sucursal_id', $branchId)
            ->select(['journey.*', 'reception.id as reception_id', 'reception.estado as reception_status', 'reception.origen as reception_origin'])
            ->lazyById(self::PAGE_SIZE, 'journey.id', 'id');
        foreach ($journeys as $journey) {
            yield $this->item('journey', 'web:'.$journey->id, [
                'id' => (int) $journey->id, 'source' => 'web', 'editable' => false,
                'operating_date' => $journey->fecha_operativa, 'status' => $journey->estado,
                'starts_at' => $this->iso($journey->inicio_at), 'ends_at' => $this->iso($journey->cierre_programado_at),
                'closed_at' => $this->iso($journey->cerrada_at),
                'reception' => ['id' => (int) $journey->reception_id, 'status' => $journey->reception_status, 'origin' => $journey->reception_origin],
            ]);
        }

        yield from $this->webReceptionItems($companyId, $branchId);
        yield from $this->webTicketItems($companyId, $branchId);

        foreach (DB::table('reception_sync_records')->where('company_id', $companyId)->where('branch_id', $branchId)->lazyById(self::PAGE_SIZE) as $row) {
            yield $this->item('record', 'offline:'.$row->uuid, [
                'id' => (int) $row->id, 'uuid' => $row->uuid, 'source' => 'offline',
                'editable' => $row->status === 'active',
                'device_id' => $row->device_id, 'kind' => $row->kind, 'operating_date' => $row->operating_date,
                'status' => $row->status, 'revision' => (int) $row->revision,
                'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
                'created_at' => $this->iso($row->created_at), 'updated_at' => $this->iso($row->updated_at),
            ]);
        }
    }

    private function webReceptionItems(int $companyId, int $branchId): Generator
    {
        $records = PesadaRecepcionPolloVivo::query()
            ->whereHas('recepcion.jornada.sucursal', fn (Builder $query) => $query->whereKey($branchId)->where('empresa_id', $companyId))
            ->with(['recepcion.jornada', 'propietarioExterno:id,nombre_razon_social', 'almacenDestino:id,nombre', 'clienteDestino:id,nombre_razon_social', 'tipoJava', 'lecturaBalanza.balanza'])
            ->lazyById(self::PAGE_SIZE);
        foreach ($records as $record) {
            $isWarehouse = $record->destino_tipo === PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE;
            $line = $this->weighing($record);
            $destinationId = $isWarehouse ? $record->almacen_destino_id : $record->cliente_destino_id;
            $destinationName = $isWarehouse ? $record->almacenDestino?->nombre : $record->clienteDestino?->nombre_razon_social;
            $ownerName = $record->propietario_tipo === PesadaRecepcionPolloVivo::OWNER_OWN ? 'Mi empresa' : $record->propietarioExterno?->nombre_razon_social;
            yield $this->item('record', 'web:reception:'.$record->id, [
                'id' => (int) $record->id, 'uuid' => $record->idempotency_key,
                'source' => 'web', 'editable' => false, 'kind' => 'reception',
                'operating_date' => $record->recepcion->jornada->fecha_operativa->format('Y-m-d'),
                'status' => $record->estado === PesadaRecepcionPolloVivo::STATUS_VOIDED ? 'voided' : 'active',
                'revision' => 0,
                'payload' => [
                    'operating_date' => $record->recepcion->jornada->fecha_operativa->format('Y-m-d'),
                    'origin' => $record->recepcion->origen,
                    'local_number' => (string) $record->numero,
                    'lane' => (int) $record->columna,
                    'source_lane' => (int) $record->columna,
                    'legacy_layout' => ! $isWarehouse,
                    'journey_id' => (int) $record->recepcion->jornada_id,
                    'reception_id' => (int) $record->recepcion_id,
                    'owner_type' => $record->propietario_tipo,
                    'external_owner_id' => $record->propietario_externo_id,
                    'warehouse_id' => $record->almacen_destino_id,
                    'dispatch_client_id' => $record->cliente_destino_id,
                    'owner' => ['type' => $record->propietario_tipo, 'id' => $record->propietario_externo_id, 'name' => $ownerName],
                    'destination_id' => $destinationId,
                    'destination' => ['id' => $destinationId, 'type' => $record->destino_tipo, 'name' => $destinationName],
                    'weighings' => [$line],
                    'totals' => $this->payloads->totals($line['status'] === 'active' ? [$line] : []),
                ],
                'created_at' => $record->created_at?->toISOString(), 'updated_at' => $record->updated_at?->toISOString(),
            ]);
        }
    }

    private function webTicketItems(int $companyId, int $branchId): Generator
    {
        $links = RecepcionPolloVivoTicket::query()
            ->whereHas('recepcion.jornada.sucursal', fn (Builder $query) => $query->whereKey($branchId)->where('empresa_id', $companyId))
            ->whereHas('ticket', fn (Builder $query) => $query->where('modulo_origen', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION))
            ->whereHas('ticket.jornada.sucursal', fn (Builder $query) => $query->whereKey($branchId)->where('empresa_id', $companyId))
            ->with(['recepcion', 'ticket.jornada', 'ticket.clienteDestino', 'ticket.almacenDestino', 'ticket.vehiculoEntrega', 'ticket.conductorEntrega', 'ticket.pesadas.tipoJava', 'ticket.pesadas.lecturaBalanza.balanza'])
            ->lazyById(self::PAGE_SIZE);
        foreach ($links as $link) {
            $ticket = $link->ticket;
            // A corrupted link must never import a ticket into the wrong reception day.
            if ((int) $ticket->jornada_id !== (int) $link->recepcion->jornada_id) {
                continue;
            }
            $voided = $ticket->estado === TicketDespacho::STATUS_VOIDED;
            $lines = $ticket->pesadas->sortBy('numero')->map(fn (Pesada $record): array => $this->weighing($record, $voided))->values()->all();
            $destinationId = $ticket->cliente_destino_id ?: $ticket->almacen_destino_id;
            yield $this->item('record', 'web:ticket:'.$ticket->id, [
                'id' => (int) $ticket->id, 'uuid' => $ticket->referencia_externa,
                'source' => 'web', 'editable' => false, 'kind' => 'ticket',
                'operating_date' => $ticket->jornada->fecha_operativa->format('Y-m-d'),
                'status' => $voided ? 'voided' : 'active', 'revision' => (int) $link->revision,
                'payload' => [
                    'operating_date' => $ticket->jornada->fecha_operativa->format('Y-m-d'),
                    'origin' => $link->recepcion->origen,
                    'local_number' => $ticket->codigo,
                    'notes' => $ticket->observaciones,
                    'code' => $ticket->codigo, 'lane' => (int) $link->columna,
                    'journey_id' => (int) $ticket->jornada_id, 'reception_id' => (int) $link->recepcion_id,
                    'dispatch_client_id' => $ticket->cliente_destino_id,
                    'client_name' => $ticket->clienteDestino?->nombre_razon_social,
                    'client_document_number' => $ticket->clienteDestino?->numero_documento,
                    'is_internal_client' => (bool) $ticket->clienteDestino?->es_cliente_interno,
                    'warehouse_id' => $ticket->almacen_destino_id,
                    'warehouse_name' => $ticket->almacenDestino?->nombre,
                    'delivery_vehicle_id' => $ticket->vehiculo_entrega_id,
                    'delivery_plate' => $ticket->vehiculoEntrega?->placa,
                    'delivery_driver_id' => $ticket->conductor_entrega_id,
                    'delivery_driver_name' => $ticket->conductorEntrega?->nombre_completo,
                    'destination_id' => $destinationId,
                    'destination' => ['id' => $destinationId, 'type' => $ticket->cliente_destino_id ? 'CLIENTE' : 'ALMACEN', 'name' => $ticket->clienteDestino?->nombre_razon_social ?: $ticket->almacenDestino?->nombre],
                    'external_owner_id' => null,
                    'owner' => ['type' => 'PROPIA', 'id' => null, 'name' => 'Mi empresa'],
                    'delivery' => $ticket->vehiculoEntrega || $ticket->conductorEntrega ? [
                        'vehicle' => $ticket->vehiculoEntrega ? ['id' => (int) $ticket->vehiculoEntrega->id, 'plate' => $ticket->vehiculoEntrega->placa] : null,
                        'driver' => $ticket->conductorEntrega ? ['id' => (int) $ticket->conductorEntrega->id, 'name' => $ticket->conductorEntrega->nombre_completo] : null,
                    ] : null,
                    'ticket_status' => $ticket->estado,
                    'registered_at' => $ticket->cerrado_at?->toISOString(),
                    'voided_at' => $ticket->anulado_at?->toISOString(), 'void_reason' => $ticket->motivo_anulacion,
                    'weighings' => $lines,
                    'totals' => $this->payloads->totals(array_values(array_filter($lines, fn (array $line): bool => $line['status'] === 'active'))),
                ],
                'created_at' => $ticket->created_at?->toISOString(), 'updated_at' => $ticket->updated_at?->toISOString(),
            ]);
        }
    }

    private function weighing(Pesada|PesadaRecepcionPolloVivo $record, bool $ticketVoided = false): array
    {
        $reading = $record->lecturaBalanza;

        return [
            'id' => (int) $record->id,
            'key' => ($record instanceof Pesada ? 'web:ticket-weighing:' : 'web:reception-weighing:').$record->id,
            'uuid' => $record instanceof PesadaRecepcionPolloVivo ? $record->idempotency_key : null,
            'number' => (int) $record->numero,
            'status' => $ticketVoided || $record->estado === Pesada::STATUS_VOIDED ? 'voided' : 'active',
            'weighing_status' => $record->estado,
            'sex' => $record->sexo,
            'chicken_type_id' => (int) $record->tipo_pollo_id,
            'cage_type_id' => (int) $record->tipo_java_id,
            'cage_type_code' => $record->tipoJava?->codigo,
            'cage_type_name' => $record->tipoJava?->nombre,
            'cage_weight_kg' => (string) $record->peso_java_kg_snapshot,
            'birds_per_cage' => (int) $record->aves_por_java,
            'cage_count' => (int) $record->cantidad_javas,
            'bird_count' => (int) $record->cantidad_aves,
            'birds' => (int) $record->cantidad_aves,
            'read_weight_kg' => (string) $record->peso_leido_kg,
            'gross_weight_kg' => (string) $record->peso_bruto_kg,
            'tare_weight_kg' => (string) $record->tara_total_kg,
            'net_weight_kg' => (string) $record->peso_neto_kg,
            'weight_source' => $record->origen_peso === 'MANUAL' ? 'MANUAL' : 'BALANZA',
            'scale_code' => $record->origen_peso === 'MANUAL' ? null : ($reading?->balanza?->codigo ?: $record->origen_peso),
            'scale_reading' => $reading ? [
                'raw_frame' => $reading->trama_cruda,
                'connection_mode' => $reading->modo_conexion,
                'device_name' => $reading->dispositivo,
                'captured_at' => $reading->capturada_at?->toISOString(),
            ] : null,
            'weighed_at' => $record->pesada_at?->toISOString(),
            'voided_at' => $record->anulada_at?->toISOString(),
            'void_reason' => $record->motivo_anulacion,
            'created_by' => (int) $record->created_by,
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function catalogState(object $row): array
    {
        return [
            'status' => $row->estado,
            'active' => $row->estado === 'ACTIVO',
            'updated_at' => $this->iso($row->updated_at ?? null),
        ];
    }

    private function item(string $entity, string|int $key, array $data): array
    {
        return ['entity' => $entity, 'key' => (string) $key, 'data' => $data];
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toISOString();
    }
}
