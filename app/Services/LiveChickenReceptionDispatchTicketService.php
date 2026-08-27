<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\JornadaOperativa;
use App\Models\Pesada;
use App\Models\ProgramacionRecepcion;
use App\Models\RecepcionPolloVivo;
use App\Models\RecepcionPolloVivoTicket;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveChickenReceptionDispatchTicketService extends DispatchTicketService
{
    public function __construct(
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations,
        private readonly ScaleReadingService $scaleReadings,
        private readonly LiveChickenReceptionTicketInventoryService $receptionInventory,
        private readonly FinancialAuditService $audit,
    ) {
        parent::__construct($javaControl, $financialObligations, $scaleReadings);
    }

    /** @return array{ticket: TicketDespacho, link: RecepcionPolloVivoTicket} */
    public function receptionTicket(
        int $companyId,
        object $branch,
        int $ticketId,
    ): array {
        $link = $this->receptionLink($companyId, (int) $branch->id, $ticketId);

        return [
            'ticket' => $this->loadReceptionTicket($ticketId),
            'link' => $link,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespacho, link: RecepcionPolloVivoTicket}
     */
    public function updateReceptionTicket(
        int $companyId,
        object $branch,
        User $actor,
        int $ticketId,
        array $data,
        ?string $ip = null,
    ): array {
        return DB::transaction(function () use (
            $companyId,
            $branch,
            $actor,
            $ticketId,
            $data,
            $ip,
        ): array {
            $this->receptionInventory->lockCompanyScope($companyId);
            $ticket = TicketDespacho::query()
                ->whereKey($ticketId)
                ->where('modulo_origen', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION)
                ->whereHas('jornada.sucursal', fn (Builder $query) => $query
                    ->whereKey((int) $branch->id)
                    ->where('empresa_id', $companyId))
                ->lockForUpdate()
                ->firstOrFail();
            $link = $this->receptionLink(
                $companyId,
                (int) $branch->id,
                $ticketId,
                true,
            );

            if ($ticket->estado !== TicketDespacho::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'ticket' => 'Solo se puede corregir un ticket de recepción cerrado y vigente.',
                ]);
            }

            $ticket->loadMissing('jornada:id,sucursal_id,fecha_operativa,estado');
            $currentOperatingDate = $this->currentOperatingDate(
                $companyId,
                (string) $branch->zona_horaria,
            );
            if ($ticket->jornada?->estado !== JornadaOperativa::STATUS_OPEN
                || $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                    !== $currentOperatingDate->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'ticket' => 'Solo se pueden corregir tickets de la jornada operativa actual mientras esté abierta.',
                ]);
            }

            $this->assertFinancialDocumentsAreEditable((int) $ticket->id);

            if (array_key_exists('expected_revision', $data)
                && $data['expected_revision'] !== null
                && (int) $data['expected_revision'] !== (int) $link->revision) {
                abort(409, 'El ticket fue modificado por otro usuario. Vuelve a abrirlo antes de guardar.');
            }

            $records = Pesada::query()
                ->where('ticket_id', $ticket->id)
                ->where('estado', Pesada::STATUS_ACTIVE)
                ->with(['lecturaBalanza.balanza'])
                ->orderBy('numero')
                ->lockForUpdate()
                ->get();
            $requested = collect($data['weighings'])->keyBy(
                fn (array $weighing): int => (int) $weighing['id'],
            );

            if ($records->count() !== $requested->count()
                || $records->pluck('id')->diff($requested->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'weighings' => 'Debes enviar todas las pesadas activas del ticket, sin agregar ni omitir registros.',
                ]);
            }

            $cageTypes = DB::table('tipos_java')
                ->whereIn('id', $requested->pluck('cage_type_id')->unique())
                ->lockForUpdate()
                ->get(['id', 'codigo', 'peso_kg', 'estado'])
                ->keyBy('id');

            foreach ($records as $index => $record) {
                $input = $requested->get((int) $record->id);
                $cageType = $cageTypes->get((int) $input['cage_type_id']);

                $keepsCurrentCageType = (int) $record->tipo_java_id === (int) ($cageType?->id ?? 0);
                if (! $cageType || ($cageType->estado !== 'ACTIVO' && ! $keepsCurrentCageType)) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.cage_type_id" => 'El tipo de java seleccionado no está disponible.',
                    ]);
                }

                if (filled($input['expected_updated_at'] ?? null)
                    && $record->updated_at
                    && CarbonImmutable::parse((string) $input['expected_updated_at'])->getTimestamp()
                        !== $record->updated_at->getTimestamp()) {
                    abort(409, "La pesada {$record->numero} fue modificada por otro usuario.");
                }

                $weighedAt = $this->validatedWeighedAt(
                    $companyId,
                    $branch,
                    $ticket,
                    (string) $input['weighed_at'],
                    "weighings.{$index}.weighed_at",
                );
                $cages = (int) $input['cage_count'];
                $birdsPerCage = (int) $input['birds_per_cage'];
                $cageWeight = $keepsCurrentCageType
                    ? round((float) $record->peso_java_kg_snapshot, 3)
                    : round((float) $cageType->peso_kg, 3);
                $grossWeight = round((float) $input['read_weight_kg'], 3);
                $tareWeight = round($cages * $cageWeight, 3);
                $netWeight = round($grossWeight - $tareWeight, 3);

                if ($netWeight <= 0) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.read_weight_kg" => 'El peso leído debe ser mayor que la tara total de las javas.',
                    ]);
                }

                $weightChanged = abs($grossWeight - (float) $record->peso_leido_kg) > 0.0005;
                $scaleReadingId = $record->lectura_balanza_id;
                $storedWeightSource = (string) $record->origen_peso;
                if ($weightChanged) {
                    $source = $this->changedWeightSource(
                        $record,
                        $input,
                        "weighings.{$index}.weight_source",
                    );
                    $scaleReading = $source === 'MANUAL'
                        ? null
                        : $this->recordChangedScaleReading(
                            (int) $branch->id,
                            $actor,
                            $input,
                            $source,
                            $weighedAt,
                            "weighings.{$index}",
                        );
                    $scaleReadingId = $scaleReading?->id;
                    $storedWeightSource = $source === 'MANUAL' ? 'MANUAL' : 'BALANZA';
                }
                $before = $record->attributesToArray();
                $record->update([
                    'sexo' => (string) $input['sex'],
                    'tipo_java_id' => (int) $cageType->id,
                    'lectura_balanza_id' => $scaleReadingId,
                    'origen_peso' => $storedWeightSource,
                    'aves_por_java' => $birdsPerCage,
                    'cantidad_javas' => $cages,
                    'cantidad_aves' => $birdsPerCage * $cages,
                    'peso_java_kg_snapshot' => $cageWeight,
                    'peso_leido_kg' => $grossWeight,
                    'peso_bruto_kg' => $grossWeight,
                    'tara_total_kg' => $tareWeight,
                    'peso_neto_kg' => $netWeight,
                    'pesada_at' => $weighedAt,
                ]);
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'pesadas',
                    (int) $record->id,
                    'ACTUALIZAR_TICKET_RECEPCION',
                    $before,
                    [
                        ...$record->fresh()->attributesToArray(),
                        'motivo_correccion' => trim((string) $data['correction_reason']),
                    ],
                    $ip,
                );
            }

            $ticket->loadMissing('jornada:id,sucursal_id');
            $this->javaControl->syncDispatchMovement(
                $ticket,
                $companyId,
                (int) $ticket->jornada->sucursal_id,
            );
            $link = $this->receptionInventory->sync($companyId, $actor, $ticket, true) ?: $link;
            $this->financialObligations->syncTicket($companyId, $ticket, $actor);

            return [
                'ticket' => $this->loadReceptionTicket((int) $ticket->id),
                'link' => $link,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespacho, already_registered: bool, reception_lane: int, link: RecepcionPolloVivoTicket}
     */
    public function registerReception(
        int $companyId,
        object $branch,
        User $actor,
        array $data,
    ): array {
        $requestHash = $this->registrationRequestHash($data);

        return DB::transaction(function () use (
            $companyId,
            $branch,
            $actor,
            $data,
            $requestHash,
        ): array {
            $this->receptionInventory->lockCompanyScope($companyId);
            $existing = TicketDespacho::query()
                ->where('referencia_externa', (string) $data['draft_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->confirmedRegistration(
                    $companyId,
                    $branch,
                    $existing,
                    $requestHash,
                );
            }

            $this->assertActiveRegistrationCatalogs($companyId, $data);
            $parentResult = parent::register(
                $companyId,
                $branch,
                $actor,
                $this->toDispatchPayload($data),
            );
            $ticket = $parentResult['ticket'];

            // A concurrent transaction could have registered the same UUID
            // between the initial lookup and the parent service lookup.
            if ($parentResult['already_registered']) {
                return $this->confirmedRegistration(
                    $companyId,
                    $branch,
                    $ticket,
                    $requestHash,
                );
            }

            $this->assertCurrentOpenJourney($companyId, $branch, $ticket);
            $reception = RecepcionPolloVivo::query()->firstOrCreate(
                ['jornada_id' => $ticket->jornada_id],
                [
                    'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
                    'estado' => RecepcionPolloVivo::STATUS_OPEN,
                    'created_by' => $actor->id,
                ],
            );
            $link = RecepcionPolloVivoTicket::query()
                ->where('ticket_despacho_id', $ticket->id)
                ->lockForUpdate()
                ->first();

            if (! $link) {
                $link = RecepcionPolloVivoTicket::query()->create([
                    'recepcion_id' => $reception->id,
                    'ticket_despacho_id' => $ticket->id,
                    'movimiento_inventario_id' => null,
                    'columna' => (int) $data['lane'],
                    'request_hash' => $requestHash,
                    'cantidad_javas_aplicada' => 0,
                    'revision' => 0,
                    'created_by' => $actor->id,
                ]);
            }

            $link = $this->receptionInventory->sync($companyId, $actor, $ticket) ?: $link;

            return [
                ...$parentResult,
                'ticket' => $ticket->fresh([
                    'jornada',
                    'clienteDestino',
                    'almacenDestino',
                    'vehiculoEntrega',
                    'conductorEntrega',
                    'pesadas.tipoJava',
                    'pesadas.lecturaBalanza.balanza',
                ]),
                'reception_lane' => (int) $link->columna,
                'link' => $link,
            ];
        }, 3);
    }

    /**
     * @return array{ticket: TicketDespacho, already_registered: true, reception_lane: int, link: RecepcionPolloVivoTicket}
     */
    private function confirmedRegistration(
        int $companyId,
        object $branch,
        TicketDespacho $ticket,
        string $requestHash,
    ): array {
        $ticket->loadMissing('jornada');
        $belongsToCompany = DB::table('sucursales')
            ->where('id', $ticket->jornada?->sucursal_id)
            ->where('empresa_id', $companyId)
            ->exists();

        if (! $belongsToCompany) {
            throw ValidationException::withMessages([
                'draft_id' => 'Este identificador ya se encuentra registrado.',
            ]);
        }

        if ((int) $ticket->jornada?->sucursal_id !== (int) $branch->id) {
            throw ValidationException::withMessages([
                'draft_id' => 'Este identificador ya pertenece a otra sucursal.',
            ]);
        }

        if (! $this->ownsExistingTicket($ticket)) {
            throw ValidationException::withMessages([
                'draft_id' => 'Este identificador ya pertenece a un ticket de otro canal.',
            ]);
        }

        if ($ticket->estado === TicketDespacho::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'draft_id' => 'Este borrador pertenece a un ticket anulado. Crea un ticket nuevo.',
            ]);
        }

        $link = RecepcionPolloVivoTicket::query()
            ->where('ticket_despacho_id', $ticket->id)
            ->lockForUpdate()
            ->first();
        abort_unless(
            $link,
            409,
            'El ticket ya existe, pero su vínculo de recepción está incompleto. Solicita una revisión antes de reintentar.',
        );
        abort_unless(
            is_string($link->request_hash)
                && strlen($link->request_hash) === 64
                && hash_equals($link->request_hash, $requestHash),
            409,
            'Este borrador ya fue registrado con contenido diferente. Recarga la recepción antes de continuar.',
        );

        return [
            'ticket' => $this->loadReceptionTicket((int) $ticket->id),
            'already_registered' => true,
            'reception_lane' => (int) $link->columna,
            'link' => $link,
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertActiveRegistrationCatalogs(int $companyId, array $data): void
    {
        $client = DB::table('terceros as tercero')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
            ->where('tercero.id', (int) $data['dispatch_client_id'])
            ->where('tercero.empresa_id', $companyId)
            ->where('tercero.estado', Tercero::STATUS_ACTIVE)
            ->where('tercero.es_cliente_interno', false)
            ->where('rol.rol', TerceroRole::CLIENT)
            ->lockForUpdate()
            ->first(['tercero.id']);
        if (! $client) {
            throw ValidationException::withMessages([
                'dispatch_client_id' => 'El cliente seleccionado no está disponible.',
            ]);
        }

        $vehicle = DB::table('vehiculos')
            ->where('id', (int) $data['delivery_vehicle_id'])
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->lockForUpdate()
            ->first(['id']);
        if (! $vehicle) {
            throw ValidationException::withMessages([
                'delivery_vehicle_id' => 'El camión seleccionado no pertenece a la flota activa de la empresa.',
            ]);
        }

        $driver = DB::table('conductores')
            ->where('id', (int) $data['delivery_driver_id'])
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO')
            ->lockForUpdate()
            ->first(['id']);
        if (! $driver) {
            throw ValidationException::withMessages([
                'delivery_driver_id' => 'El chofer seleccionado no pertenece a la empresa o está inactivo.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function registrationRequestHash(array $data): string
    {
        $canonical = [
            'schema' => 1,
            'lane' => (int) $data['lane'],
            'dispatch_client_id' => (int) $data['dispatch_client_id'],
            'delivery_vehicle_id' => (int) $data['delivery_vehicle_id'],
            'delivery_driver_id' => (int) $data['delivery_driver_id'],
            'weighings' => collect($data['weighings'])
                ->values()
                ->map(function (array $weighing): array {
                    $scaleReading = is_array($weighing['scale_reading'] ?? null)
                        ? $weighing['scale_reading']
                        : [];

                    return [
                        'idempotency_key' => mb_strtolower((string) $weighing['idempotency_key'], 'UTF-8'),
                        'sex' => (string) $weighing['sex'],
                        'cage_type_id' => (int) $weighing['cage_type_id'],
                        'birds_per_cage' => (int) $weighing['birds_per_cage'],
                        'cage_count' => (int) $weighing['cage_count'],
                        'weight_source' => (string) $weighing['weight_source'],
                        'read_weight_kg' => number_format(
                            round((float) $weighing['read_weight_kg'], 3),
                            3,
                            '.',
                            '',
                        ),
                        'weighed_at' => $this->canonicalTimestamp((string) $weighing['weighed_at']),
                        'scale_reading' => [
                            'raw_frame' => array_key_exists('raw_frame', $scaleReading)
                                ? (string) $scaleReading['raw_frame']
                                : null,
                            'connection_mode' => filled($scaleReading['connection_mode'] ?? null)
                                ? (string) $scaleReading['connection_mode']
                                : null,
                            'device_name' => filled($scaleReading['device_name'] ?? null)
                                ? (string) $scaleReading['device_name']
                                : null,
                            'captured_at' => filled($scaleReading['captured_at'] ?? null)
                                ? $this->canonicalTimestamp((string) $scaleReading['captured_at'])
                                : null,
                        ],
                    ];
                })
                ->all(),
        ];

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalTimestamp(string $value): string
    {
        return CarbonImmutable::parse($value)
            ->setTimezone('UTC')
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    protected function sourceModule(): ?string
    {
        return TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION;
    }

    protected function ownsExistingTicket(TicketDespacho $ticket): bool
    {
        return $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE
            && $ticket->modulo_origen === TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION;
    }

    /** @param Collection<int, array<string, mixed>> $weighings */
    protected function requiresConfiguredProgram(string $operationType, Collection $weighings): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $weighing
     * @return array{provider_id: null, warehouse_id: null, vehicle_id: null, plate: null, program_detail_id: null}
     */
    protected function resolveWeighingOrigin(
        int $companyId,
        int $branchId,
        ?ProgramacionRecepcion $program,
        array $weighing,
        string $field,
    ): array {
        return $this->emptyOrigin();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toDispatchPayload(array $data): array
    {
        $weighings = collect($data['weighings']);
        $cageTypes = DB::table('tipos_java')
            ->whereIn('id', $weighings->pluck('cage_type_id')->unique())
            ->where('estado', 'ACTIVO')
            ->lockForUpdate()
            ->get(['id', 'codigo'])
            ->keyBy('id');

        $normalizedWeighings = $weighings
            ->values()
            ->map(function (array $weighing, int $index) use ($cageTypes): array {
                $cageType = $cageTypes->get((int) $weighing['cage_type_id']);

                if (! $cageType) {
                    throw ValidationException::withMessages([
                        "weighings.{$index}.cage_type_id" => 'El tipo de java seleccionado no está disponible.',
                    ]);
                }

                $readWeight = round((float) $weighing['read_weight_kg'], 3);

                return [
                    'local_id' => $index + 1,
                    'chicken_type_code' => TipoPollo::CHICKEN_LIVE,
                    'chicken_condition' => Pesada::CHICKEN_CONDITION_LIVE,
                    'chicken_sex' => (string) $weighing['sex'],
                    'cage_type_code' => (string) $cageType->codigo,
                    'origin' => null,
                    'weight_source' => (string) $weighing['weight_source'],
                    'scale_reading' => $weighing['scale_reading'] ?? null,
                    'birds_per_cage' => (int) $weighing['birds_per_cage'],
                    'cage_count' => (int) $weighing['cage_count'],
                    'read_weight_kg' => $readWeight,
                    'gross_weight_kg' => $readWeight,
                    'weighed_at' => (string) $weighing['weighed_at'],
                ];
            })
            ->all();

        return [
            'draft_id' => (string) $data['draft_id'],
            'operation_type' => TicketDespacho::OPERATION_DISPATCH,
            'destination' => [
                'type' => 'CLIENTE',
                'id' => (int) $data['dispatch_client_id'],
            ],
            'delivery' => [
                'vehicle_id' => (int) $data['delivery_vehicle_id'],
                'driver_id' => (int) $data['delivery_driver_id'],
            ],
            'weighings' => $normalizedWeighings,
        ];
    }

    private function receptionLink(
        int $companyId,
        int $branchId,
        int $ticketId,
        bool $lock = false,
    ): RecepcionPolloVivoTicket {
        return RecepcionPolloVivoTicket::query()
            ->where('ticket_despacho_id', $ticketId)
            ->whereHas('ticket', fn (Builder $query) => $query
                ->where('modulo_origen', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION))
            ->whereHas('ticket.jornada.sucursal', fn (Builder $query) => $query
                ->whereKey($branchId)
                ->where('empresa_id', $companyId))
            ->whereHas('recepcion.jornada.sucursal', fn (Builder $query) => $query
                ->whereKey($branchId)
                ->where('empresa_id', $companyId))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    private function loadReceptionTicket(int $ticketId): TicketDespacho
    {
        return TicketDespacho::query()
            ->with([
                'jornada',
                'clienteDestino',
                'almacenDestino',
                'vehiculoEntrega',
                'conductorEntrega',
                'pesadas' => fn ($query) => $query
                    ->where('estado', Pesada::STATUS_ACTIVE)
                    ->orderBy('numero'),
                'pesadas.tipoJava',
                'pesadas.lecturaBalanza.balanza',
            ])
            ->findOrFail($ticketId);
    }

    private function validatedWeighedAt(
        int $companyId,
        object $branch,
        TicketDespacho $ticket,
        string $value,
        string $field,
    ): CarbonImmutable {
        $weighedAt = CarbonImmutable::parse($value)->setTimezone($branch->zona_horaria);

        if ($weighedAt->greaterThan(CarbonImmutable::now($branch->zona_horaria)->addMinutes(5))) {
            throw ValidationException::withMessages([
                $field => 'La fecha de la pesada no puede estar en el futuro.',
            ]);
        }

        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $cutoffAt = $weighedAt->startOfDay()->setTimeFromTimeString($cutoff);
        $operatingDate = $weighedAt->greaterThanOrEqualTo($cutoffAt)
            ? $weighedAt->addDay()->startOfDay()
            : $weighedAt->startOfDay();
        $ticket->loadMissing('jornada:id,fecha_operativa,sucursal_id');

        if ($operatingDate->format('Y-m-d') !== $ticket->jornada?->fecha_operativa?->format('Y-m-d')) {
            throw ValidationException::withMessages([
                $field => 'La pesada corregida debe permanecer en la jornada operativa del ticket.',
            ]);
        }

        return $weighedAt;
    }

    private function currentOperatingDate(int $companyId, string $timezone): CarbonImmutable
    {
        $now = CarbonImmutable::now($timezone);
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $cutoffAt = $now->startOfDay()->setTimeFromTimeString($cutoff);

        return $now->greaterThanOrEqualTo($cutoffAt)
            ? $now->addDay()->startOfDay()
            : $now->startOfDay();
    }

    private function assertCurrentOpenJourney(
        int $companyId,
        object $branch,
        TicketDespacho $ticket,
    ): void {
        $ticket->loadMissing('jornada:id,sucursal_id,fecha_operativa,estado');
        $currentOperatingDate = $this->currentOperatingDate(
            $companyId,
            (string) $branch->zona_horaria,
        );

        if ((int) $ticket->jornada?->sucursal_id !== (int) $branch->id
            || $ticket->jornada?->estado !== JornadaOperativa::STATUS_OPEN
            || $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                !== $currentOperatingDate->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'ticket' => 'El borrador solo se puede registrar en la jornada operativa actual mientras esté abierta.',
            ]);
        }
    }

    private function assertFinancialDocumentsAreEditable(int $ticketId): void
    {
        $documentIds = DB::table('comprobante_tickets')
            ->where('ticket_id', $ticketId)
            ->pluck('comprobante_id')
            ->merge(
                DB::table('comprobante_pesadas as comprobante_pesada')
                    ->join('pesadas as pesada_financiera', 'pesada_financiera.id', '=', 'comprobante_pesada.pesada_id')
                    ->where('pesada_financiera.ticket_id', $ticketId)
                    ->pluck('comprobante_pesada.comprobante_id'),
            )
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($documentIds->isEmpty()) {
            return;
        }

        DB::table('comprobantes')
            ->whereIn('id', $documentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $hasAppliedMovement = DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('pago.estado', 'REGISTRADO')
            ->whereIn('aplicacion.comprobante_id', $documentIds)
            ->exists();

        abort_if(
            $hasAppliedMovement,
            409,
            'No se puede corregir el ticket porque ya tiene cobros o pagos aplicados. Anula primero los movimientos financieros relacionados.',
        );
    }

    /** @param array<string, mixed> $input */
    private function changedWeightSource(Pesada $record, array $input, string $field): string
    {
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
}
