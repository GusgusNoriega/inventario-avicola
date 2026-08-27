<?php

namespace Tests\Feature;

use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\RecepcionPolloVivo;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class LiveChickenReceptionHistoryApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/recepcion-pollo-vivo/historial';

    private User $user;

    private int $branchId;

    private int $otherBranchId;

    private int $foreignJourneyId;

    private int $moduleOnlyJourneyId;

    private int $latestJourneyId;

    private int $historicalJourneyId;

    private int $historicalReceptionId;

    private int $chickenTypeId;

    private int $cageTypeId;

    private int $warehouseId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->branchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'PRINCIPAL',
            'Sucursal principal',
        );
        $this->otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'SECUNDARIA',
            'Sucursal secundaria',
        );
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, ['MODULO_RECEPCION_POLLO_VIVO']);
        Sanctum::actingAs($this->user, ['api']);

        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_HISTORIAL',
            'nombre' => 'Java histórica',
            'peso_kg' => 7,
            'estado' => 'INACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => 'HISTORICO',
            'nombre' => 'Almacén histórico',
            'permite_stock_negativo' => false,
            'estado' => 'INACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20999999991',
            'nombre_razon_social' => 'Cliente histórico',
            'direccion' => 'Dirección histórica',
            'estado' => 'INACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->historicalJourneyId = $this->createJourney(
            $this->branchId,
            '2026-08-26',
            'CERRADA',
            (int) $this->user->id,
        );
        $this->latestJourneyId = $this->createJourney(
            $this->branchId,
            '2026-08-27',
            'ABIERTA',
            (int) $this->user->id,
        );
        $this->historicalReceptionId = $this->createReception(
            $this->historicalJourneyId,
            (int) $this->user->id,
        );
        $latestReception = $this->createReception(
            $this->latestJourneyId,
            (int) $this->user->id,
        );
        $this->createNativeWeighing(
            $latestReception,
            PesadaRecepcionPolloVivo::STATUS_ACTIVE,
            1,
            1,
            7,
            50,
            '2026-08-26 22:00:00',
        );

        $this->seedHistoricalRows();
        $this->seedRowsThatMustStayOutsideTheHistory();
    }

    public function test_history_defaults_to_the_most_recent_scoped_journey(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.selected_journey.id', $this->latestJourneyId)
            ->assertJsonPath('data.selected_journey.operating_date', '2026-08-27')
            ->assertJsonPath('data.applied_filters.status', 'TODAS')
            ->assertJsonPath('data.applied_filters.source', 'TODAS')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.records.0.source', 'RECEPCION')
            ->assertJsonPath('data.records.0.destination.name', 'Almacén histórico')
            ->assertJsonPath('data.records.0.cage_type.name', 'Java histórica');

        $catalogJourneyIds = collect($this->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.catalog.journeys'))
            ->pluck('id')
            ->filter()
            ->values();
        $this->assertEqualsCanonicalizing(
            [$this->latestJourneyId, $this->historicalJourneyId],
            $catalogJourneyIds->all(),
        );
    }

    public function test_history_unifies_only_physical_rows_owned_by_the_module_and_paginates_them(): void
    {
        $query = http_build_query([
            'journey_id' => $this->historicalJourneyId,
            'status' => 'TODAS',
            'source' => 'TODAS',
            'per_page' => 2,
            'page' => 1,
        ]);
        $firstPage = $this->getJson(self::ENDPOINT.'?'.$query)
            ->assertOk()
            ->assertJsonPath('data.selected_journey.id', $this->historicalJourneyId)
            ->assertJsonPath('data.selected_journey.operating_date', '2026-08-26')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 6)
            ->assertJsonPath('data.pagination.from', 1)
            ->assertJsonPath('data.pagination.to', 2)
            ->assertJsonPath('data.summary.active.weighings', 3)
            ->assertJsonPath('data.summary.active.cages', 6)
            ->assertJsonPath('data.summary.active.birds', 42)
            ->assertJsonPath('data.summary.active.gross_weight_kg', 310)
            ->assertJsonPath('data.summary.active.tare_weight_kg', 42)
            ->assertJsonPath('data.summary.active.net_weight_kg', 268)
            ->assertJsonPath('data.summary.voided.weighings', 3)
            ->assertJsonPath('data.summary.voided.cages', 7)
            ->assertJsonPath('data.summary.voided.birds', 49)
            ->assertJsonPath('data.summary.voided.net_weight_kg', 311)
            ->assertJsonPath('data.summary.total.weighings', 6)
            ->assertJsonPath('data.summary.total.cages', 13)
            ->assertJsonPath('data.summary.total.birds', 91)
            ->assertJsonCount(2, 'data.records');

        $records = collect($firstPage->json('data.records'));
        foreach ([2, 3] as $page) {
            $records = $records->concat($this->getJson(
                self::ENDPOINT.'?'.http_build_query([
                    'journey_id' => $this->historicalJourneyId,
                    'status' => 'TODAS',
                    'source' => 'TODAS',
                    'per_page' => 2,
                    'page' => $page,
                ]),
            )->assertOk()->json('data.records'));
        }

        $this->assertCount(6, $records);
        $this->assertCount(6, $records->pluck('row_key')->unique());
        $this->assertSame(2, $records->where('source', 'RECEPCION')->count());
        $this->assertSame(4, $records->where('source', 'TICKET')->count());
        $this->assertSame(3, $records->where('status', 'ACTIVA')->count());
        $this->assertSame(3, $records->where('status', 'ANULADA')->count());
        $this->assertTrue($records->every(
            fn (array $record): bool => (int) $record['journey']['id'] === $this->historicalJourneyId,
        ));
        $this->assertTrue($records->where('source', 'TICKET')->every(
            fn (array $record): bool => $record['record_kind'] === 'DISPATCH_TICKET_WEIGHING'
                && in_array($record['lane'], [5, 6], true)
                && filled($record['ticket']['code'] ?? null),
        ));
        $this->assertTrue($records->where('source', 'RECEPCION')->every(
            fn (array $record): bool => $record['record_kind'] === 'RECEPTION_WEIGHING'
                && $record['ticket'] === null,
        ));
        $legacyDirect = $records->first(
            fn (array $record): bool => $record['source'] === 'RECEPCION'
                && $record['source_lane'] === 3,
        );
        $this->assertNotNull($legacyDirect);
        $this->assertSame(5, $legacyDirect['lane']);
        $this->assertTrue($legacyDirect['uses_previous_layout']);
    }

    public function test_table_filters_do_not_change_journey_totals_and_foreign_journeys_are_hidden(): void
    {
        $filtered = $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'journey_id' => $this->historicalJourneyId,
            'status' => 'activa',
            'source' => 'ticket',
        ]))
            ->assertOk()
            ->assertJsonPath('data.applied_filters.status', 'ACTIVA')
            ->assertJsonPath('data.applied_filters.source', 'TICKET')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.summary.active.weighings', 3)
            ->assertJsonPath('data.summary.voided.weighings', 3);

        $this->assertTrue(collect($filtered->json('data.records'))->every(
            fn (array $record): bool => $record['status'] === 'ACTIVA'
                && $record['source'] === 'TICKET',
        ));

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'journey_id' => $this->historicalJourneyId,
            'status' => 'ANULADA',
            'source' => 'RECEPCION',
        ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.records.0.status', 'ANULADA')
            ->assertJsonPath('data.records.0.source', 'RECEPCION')
            ->assertJsonPath('data.summary.active.weighings', 3)
            ->assertJsonPath('data.summary.voided.weighings', 3);

        $this->getJson(self::ENDPOINT.'?journey_id='.$this->foreignJourneyId)
            ->assertNotFound();
        $this->getJson(self::ENDPOINT.'?journey_id='.$this->moduleOnlyJourneyId)
            ->assertNotFound();
        $this->getJson(self::ENDPOINT.'?status=desconocida')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->getJson(self::ENDPOINT.'?source=otro_modulo')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');
    }

    public function test_history_keeps_the_reception_module_access_boundary(): void
    {
        $unauthorized = $this->createUserForCompany($this->user, [
            'sucursal_id' => $this->branchId,
        ]);
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    private function seedHistoricalRows(): void
    {
        $this->createNativeWeighing(
            $this->historicalReceptionId,
            PesadaRecepcionPolloVivo::STATUS_ACTIVE,
            1,
            2,
            14,
            100,
            '2026-08-25 22:10:00',
        );
        $legacyDirectId = $this->createNativeWeighing(
            $this->historicalReceptionId,
            PesadaRecepcionPolloVivo::STATUS_VOIDED,
            2,
            1,
            7,
            50,
            '2026-08-25 22:20:00',
        );
        DB::table('pesadas_recepcion_pollo_vivo')
            ->where('id', $legacyDirectId)
            ->update([
                'columna' => 3,
                'destino_tipo' => PesadaRecepcionPolloVivo::DESTINATION_CLIENT,
                'almacen_destino_id' => null,
                'cliente_destino_id' => $this->clientId,
            ]);

        $activeTicket = $this->createTicket(
            $this->historicalJourneyId,
            'RCP-HIST-001',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->linkTicket($this->historicalReceptionId, $activeTicket, 5);
        $this->createTicketWeighing($activeTicket, Pesada::STATUS_ACTIVE, 1, 3, 21, 150, '2026-08-25 22:30:00');
        $this->createTicketWeighing($activeTicket, Pesada::STATUS_ACTIVE, 2, 1, 7, 60, '2026-08-25 22:40:00');
        $this->createTicketWeighing($activeTicket, Pesada::STATUS_VOIDED, 3, 2, 14, 110, '2026-08-25 22:50:00');

        $voidedTicket = $this->createTicket(
            $this->historicalJourneyId,
            'RCP-HIST-002',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_VOIDED,
        );
        $this->linkTicket($this->historicalReceptionId, $voidedTicket, 6);
        $this->createTicketWeighing($voidedTicket, Pesada::STATUS_ACTIVE, 1, 4, 28, 200, '2026-08-25 23:00:00');
    }

    private function seedRowsThatMustStayOutsideTheHistory(): void
    {
        $this->moduleOnlyJourneyId = $this->createJourney(
            $this->branchId,
            '2026-08-25',
            'CERRADA',
            (int) $this->user->id,
        );
        $moduleOnlyTicket = $this->createTicket(
            $this->moduleOnlyJourneyId,
            'JORNADA-OTRO-MODULO',
            'MODULO_DESPACHO_MAYORISTA_2',
            TicketDespacho::STATUS_CLOSED,
        );
        $this->createTicketWeighing($moduleOnlyTicket, Pesada::STATUS_ACTIVE, 1, 9, 63, 400, '2026-08-24 23:00:00');

        $ordinaryTicket = $this->createTicket(
            $this->historicalJourneyId,
            'OTRO-MODULO',
            'MODULO_DESPACHO_MAYORISTA_2',
            TicketDespacho::STATUS_CLOSED,
        );
        $this->createTicketWeighing($ordinaryTicket, Pesada::STATUS_ACTIVE, 1, 9, 63, 400, '2026-08-25 23:10:00');

        $liveTicketWithoutLink = $this->createTicket(
            $this->historicalJourneyId,
            'SIN-VINCULO',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->createTicketWeighing($liveTicketWithoutLink, Pesada::STATUS_ACTIVE, 1, 9, 63, 400, '2026-08-25 23:20:00');

        $linkedOtherModule = $this->createTicket(
            $this->historicalJourneyId,
            'VINCULO-OTRO',
            'MODULO_DESPACHO_MAYORISTA_2',
            TicketDespacho::STATUS_CLOSED,
        );
        $this->linkTicket($this->historicalReceptionId, $linkedOtherModule, 5);
        $this->createTicketWeighing($linkedOtherModule, Pesada::STATUS_ACTIVE, 1, 9, 63, 400, '2026-08-25 23:30:00');

        $mismatchedJourneyTicket = $this->createTicket(
            $this->latestJourneyId,
            'JORNADA-DIFERENTE',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->linkTicket($this->historicalReceptionId, $mismatchedJourneyTicket, 5);
        $this->createTicketWeighing($mismatchedJourneyTicket, Pesada::STATUS_ACTIVE, 1, 9, 63, 400, '2026-08-25 23:40:00');

        $otherBranchJourney = $this->createJourney(
            $this->otherBranchId,
            '2026-08-26',
            'CERRADA',
            (int) $this->user->id,
        );
        $otherBranchReception = $this->createReception($otherBranchJourney, (int) $this->user->id);
        $this->createNativeWeighing(
            $otherBranchReception,
            PesadaRecepcionPolloVivo::STATUS_ACTIVE,
            1,
            8,
            56,
            350,
            '2026-08-25 23:50:00',
            null,
        );

        $foreignUser = User::factory()->create();
        $foreignBranch = $this->createBranch(
            (int) $foreignUser->empresa_id,
            'AJENA',
            'Sucursal ajena',
        );
        $foreignUser->update(['sucursal_id' => $foreignBranch]);
        $this->foreignJourneyId = $this->createJourney(
            $foreignBranch,
            '2026-08-26',
            'CERRADA',
            (int) $foreignUser->id,
        );
        $foreignReception = $this->createReception(
            $this->foreignJourneyId,
            (int) $foreignUser->id,
        );
        $this->createNativeWeighing(
            $foreignReception,
            PesadaRecepcionPolloVivo::STATUS_ACTIVE,
            1,
            8,
            56,
            350,
            '2026-08-25 23:55:00',
            null,
            (int) $foreignUser->id,
        );
    }

    private function createBranch(int $companyId, string $code, string $name): int
    {
        return DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJourney(int $branchId, string $date, string $status, int $actorId): int
    {
        return DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => $date,
            'estado' => $status,
            'abierta_por' => $actorId,
            'inicio_at' => "{$date} 00:00:00",
            'cierre_programado_at' => "{$date} 21:00:00",
            'cerrada_por' => $status === 'CERRADA' ? $actorId : null,
            'cerrada_at' => $status === 'CERRADA' ? "{$date} 21:00:00" : null,
        ]);
    }

    private function createReception(int $journeyId, int $actorId): int
    {
        return DB::table('recepciones_pollo_vivo')->insertGetId([
            'jornada_id' => $journeyId,
            'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
            'estado' => RecepcionPolloVivo::STATUS_OPEN,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNativeWeighing(
        int $receptionId,
        string $status,
        int $number,
        int $cages,
        int $birds,
        float $grossWeight,
        string $weighedAt,
        ?int $warehouseId = null,
        ?int $actorId = null,
    ): int {
        $tare = $cages * 7;

        return DB::table('pesadas_recepcion_pollo_vivo')->insertGetId([
            'recepcion_id' => $receptionId,
            'idempotency_key' => (string) Str::uuid(),
            'numero' => $number,
            'columna' => $number % 2 === 0 ? 2 : 1,
            'propietario_tipo' => PesadaRecepcionPolloVivo::OWNER_OWN,
            'propietario_externo_id' => null,
            'destino_tipo' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE,
            'almacen_destino_id' => $warehouseId ?? $this->warehouseId,
            'cliente_destino_id' => null,
            'sexo' => $number % 2 === 0 ? Pesada::SEX_FEMALE : Pesada::SEX_MALE,
            'tipo_pollo_id' => $this->chickenTypeId,
            'tipo_java_id' => $this->cageTypeId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => 7,
            'cantidad_javas' => $cages,
            'cantidad_aves' => $birds,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => $grossWeight,
            'peso_bruto_kg' => $grossWeight,
            'tara_total_kg' => $tare,
            'peso_neto_kg' => $grossWeight - $tare,
            'pesada_at' => $weighedAt,
            'estado' => $status,
            'anulada_por' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED
                ? ($actorId ?? $this->user->id)
                : null,
            'anulada_at' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED ? now() : null,
            'motivo_anulacion' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED
                ? 'Anulada para la prueba histórica.'
                : null,
            'created_by' => $actorId ?? $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTicket(int $journeyId, string $code, string $source, string $status): int
    {
        return DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'modulo_origen' => $source,
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $this->clientId,
            'estado' => $status,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => now(),
            'anulado_por' => $status === TicketDespacho::STATUS_VOIDED ? $this->user->id : null,
            'anulado_at' => $status === TicketDespacho::STATUS_VOIDED ? now() : null,
            'motivo_anulacion' => $status === TicketDespacho::STATUS_VOIDED
                ? 'Ticket anulado para la prueba histórica.'
                : null,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkTicket(int $receptionId, int $ticketId, int $lane): void
    {
        DB::table('recepcion_pollo_vivo_tickets')->insert([
            'recepcion_id' => $receptionId,
            'ticket_despacho_id' => $ticketId,
            'movimiento_inventario_id' => null,
            'columna' => $lane,
            'request_hash' => hash('sha256', "history-{$ticketId}"),
            'cantidad_javas_aplicada' => 0,
            'revision' => 0,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTicketWeighing(
        int $ticketId,
        string $status,
        int $number,
        int $cages,
        int $birds,
        float $grossWeight,
        string $weighedAt,
    ): int {
        $tare = $cages * 7;

        return DB::table('pesadas')->insertGetId([
            'ticket_id' => $ticketId,
            'numero' => $number,
            'tipo_pollo_id' => $this->chickenTypeId,
            'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
            'sexo' => $number % 2 === 0 ? Pesada::SEX_FEMALE : Pesada::SEX_MALE,
            'tipo_java_id' => $this->cageTypeId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => 7,
            'cantidad_javas' => $cages,
            'cantidad_aves' => $birds,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => $grossWeight,
            'peso_bruto_kg' => $grossWeight,
            'tara_total_kg' => $tare,
            'peso_neto_kg' => $grossWeight - $tare,
            'pesada_at' => $weighedAt,
            'estado' => $status,
            'anulada_por' => $status === Pesada::STATUS_VOIDED ? $this->user->id : null,
            'anulada_at' => $status === Pesada::STATUS_VOIDED ? now() : null,
            'motivo_anulacion' => $status === Pesada::STATUS_VOIDED
                ? 'Pesada anulada para la prueba histórica.'
                : null,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
