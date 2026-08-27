<?php

namespace Tests\Feature;

use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\RecepcionPolloVivo;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\LiveChickenReceptionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class LiveChickenReceptionHistoryReportDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private object $branch;

    private int $journeyId;

    private int $receptionId;

    private int $otherBranchJourneyId;

    private int $journeyWithoutReceptionId;

    private int $chickenTypeId;

    private int $cageTypeId;

    private int $warehouseId;

    private int $clientId;

    private int $externalOwnerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $branchId = $this->createBranch((int) $this->user->empresa_id, 'REPORTE', 'Sucursal reporte');
        $this->user->update(['sucursal_id' => $branchId]);
        $this->branch = DB::table('sucursales')->find($branchId);
        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_REPORTE',
            'nombre' => 'Java reporte',
            'peso_kg' => 7,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $branchId,
            'codigo' => 'ALM-REPORTE',
            'nombre' => 'Almacén reporte',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clientId = $this->createThirdParty('20999999001', 'Cliente reporte');
        $this->externalOwnerId = $this->createThirdParty('20999999002', 'Avícola externa');
        $this->journeyId = $this->createJourney($branchId, '2026-08-26');
        $this->receptionId = $this->createReception($this->journeyId);
        $this->journeyWithoutReceptionId = $this->createJourney($branchId, '2026-08-24');

        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'OTRA',
            'Otra sucursal',
        );
        $this->otherBranchJourneyId = $this->createJourney($otherBranchId, '2026-08-26');
        $otherReceptionId = $this->createReception($this->otherBranchJourneyId);
        $this->createNativeWeighing($otherReceptionId, 1, 8, 56, 350, '2026-08-25 21:45:00');
    }

    public function test_report_returns_every_active_module_weighing_and_owner_totals_without_pagination(): void
    {
        for ($number = 1; $number <= 35; $number++) {
            $this->createNativeWeighing(
                $this->receptionId,
                $number,
                1,
                7,
                50,
                sprintf('2026-08-25 22:%02d:00', $number - 1),
            );
        }
        $externalId = $this->createNativeWeighing(
            $this->receptionId,
            36,
            2,
            14,
            100,
            '2026-08-25 23:00:00',
            PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
            PesadaRecepcionPolloVivo::STATUS_ACTIVE,
        );
        $this->createNativeWeighing(
            $this->receptionId,
            37,
            5,
            35,
            250,
            '2026-08-25 23:05:00',
            PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
            PesadaRecepcionPolloVivo::STATUS_VOIDED,
        );

        $ticketId = $this->createTicket(
            $this->journeyId,
            'RCP-REPORT-001',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->linkTicket($this->receptionId, $ticketId);
        $ticketWeighingId = $this->createTicketWeighing($ticketId, 1, 3, 21, 150, '2026-08-25 23:10:00');

        $voidedTicketId = $this->createTicket(
            $this->journeyId,
            'RCP-REPORT-VOID',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_VOIDED,
        );
        $this->linkTicket($this->receptionId, $voidedTicketId);
        $this->createTicketWeighing($voidedTicketId, 1, 4, 28, 200, '2026-08-25 23:15:00');

        $otherModuleTicketId = $this->createTicket(
            $this->journeyId,
            'OTHER-MODULE',
            TicketDespacho::SOURCE_WHOLESALE_TWO,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->linkTicket($this->receptionId, $otherModuleTicketId);
        $this->createTicketWeighing($otherModuleTicketId, 1, 9, 63, 400, '2026-08-25 23:20:00');

        $unlinkedTicketId = $this->createTicket(
            $this->journeyId,
            'WITHOUT-LINK',
            TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            TicketDespacho::STATUS_CLOSED,
        );
        $this->createTicketWeighing($unlinkedTicketId, 1, 9, 63, 400, '2026-08-25 23:25:00');

        $report = app(LiveChickenReceptionHistoryService::class)->report(
            (int) $this->user->empresa_id,
            $this->branch,
            $this->journeyId,
        );

        $this->assertSame($this->journeyId, $report['journey']['id']);
        $this->assertSame('2026-08-26', $report['journey']['operating_date']);
        $this->assertSame((int) $this->branch->id, $report['branch']['id']);
        $this->assertArrayNotHasKey('pagination', $report);
        $this->assertCount(37, $report['records']);
        $this->assertTrue(collect($report['records'])->every(
            fn (array $record): bool => $record['status'] === PesadaRecepcionPolloVivo::STATUS_ACTIVE
                && $record['journey']['id'] === $this->journeyId,
        ));
        $this->assertSame(
            ["reception-weighing:{$externalId}", "ticket-weighing:{$ticketWeighingId}"],
            collect($report['records'])
                ->whereIn('row_key', ["reception-weighing:{$externalId}", "ticket-weighing:{$ticketWeighingId}"])
                ->pluck('row_key')
                ->values()
                ->all(),
        );

        $this->assertSame([
            'weighings' => 36,
            'cages' => 38,
            'birds' => 266,
            'read_weight_kg' => 1900.0,
            'gross_weight_kg' => 1900.0,
            'tare_weight_kg' => 266.0,
            'net_weight_kg' => 1634.0,
            'average_weight_per_bird_kg' => 6.143,
        ], $report['summary']['own']);
        $this->assertSame([
            'weighings' => 1,
            'cages' => 2,
            'birds' => 14,
            'read_weight_kg' => 100.0,
            'gross_weight_kg' => 100.0,
            'tare_weight_kg' => 14.0,
            'net_weight_kg' => 86.0,
            'average_weight_per_bird_kg' => 6.143,
        ], $report['summary']['external']);
        $this->assertSame([
            'weighings' => 37,
            'cages' => 40,
            'birds' => 280,
            'read_weight_kg' => 2000.0,
            'gross_weight_kg' => 2000.0,
            'tare_weight_kg' => 280.0,
            'net_weight_kg' => 1720.0,
            'average_weight_per_bird_kg' => 6.143,
        ], $report['summary']['total']);
    }

    public function test_report_rejects_journeys_outside_the_branch_or_reception_module_catalog(): void
    {
        $this->assertJourneyNotFound($this->otherBranchJourneyId);
        $this->assertJourneyNotFound($this->journeyWithoutReceptionId);

        $foreignUser = User::factory()->create();
        $foreignBranchId = $this->createBranch((int) $foreignUser->empresa_id, 'AJENA', 'Sucursal ajena');
        $foreignJourneyId = $this->createJourney($foreignBranchId, '2026-08-26', (int) $foreignUser->id);
        $this->createReception($foreignJourneyId, (int) $foreignUser->id);
        $this->assertJourneyNotFound($foreignJourneyId);
    }

    private function assertJourneyNotFound(int $journeyId): void
    {
        try {
            app(LiveChickenReceptionHistoryService::class)->report(
                (int) $this->user->empresa_id,
                $this->branch,
                $journeyId,
            );
            $this->fail('La jornada fuera del catálogo del módulo debió rechazarse.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame('Jornada operativa no encontrada.', $exception->getMessage());
        }
    }

    private function createBranch(int $companyId, string $code, string $name): int
    {
        return DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createThirdParty(string $document, string $name): int
    {
        return DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Dirección reporte',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJourney(int $branchId, string $date, ?int $actorId = null): int
    {
        return DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => $date,
            'estado' => 'CERRADA',
            'abierta_por' => $actorId ?? $this->user->id,
            'inicio_at' => "{$date} 00:00:00",
            'cierre_programado_at' => "{$date} 21:00:00",
            'cerrada_por' => $actorId ?? $this->user->id,
            'cerrada_at' => "{$date} 21:00:00",
        ]);
    }

    private function createReception(int $journeyId, ?int $actorId = null): int
    {
        return DB::table('recepciones_pollo_vivo')->insertGetId([
            'jornada_id' => $journeyId,
            'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
            'estado' => RecepcionPolloVivo::STATUS_OPEN,
            'created_by' => $actorId ?? $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNativeWeighing(
        int $receptionId,
        int $number,
        int $cages,
        int $birds,
        float $grossWeight,
        string $weighedAt,
        string $ownerType = PesadaRecepcionPolloVivo::OWNER_OWN,
        string $status = PesadaRecepcionPolloVivo::STATUS_ACTIVE,
    ): int {
        $tare = $cages * 7;

        return DB::table('pesadas_recepcion_pollo_vivo')->insertGetId([
            'recepcion_id' => $receptionId,
            'idempotency_key' => (string) Str::uuid(),
            'numero' => $number,
            'columna' => $ownerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL ? 3 : 1,
            'propietario_tipo' => $ownerType,
            'propietario_externo_id' => $ownerType === PesadaRecepcionPolloVivo::OWNER_EXTERNAL
                ? $this->externalOwnerId
                : null,
            'destino_tipo' => PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE,
            'almacen_destino_id' => $this->warehouseId,
            'cliente_destino_id' => null,
            'sexo' => Pesada::SEX_MALE,
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
            'anulada_por' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED ? $this->user->id : null,
            'anulada_at' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED ? now() : null,
            'motivo_anulacion' => $status === PesadaRecepcionPolloVivo::STATUS_VOIDED
                ? 'Anulada para probar el reporte.'
                : null,
            'created_by' => $this->user->id,
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
                ? 'Ticket anulado para probar el reporte.'
                : null,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function linkTicket(int $receptionId, int $ticketId): void
    {
        DB::table('recepcion_pollo_vivo_tickets')->insert([
            'recepcion_id' => $receptionId,
            'ticket_despacho_id' => $ticketId,
            'movimiento_inventario_id' => null,
            'columna' => 5,
            'request_hash' => hash('sha256', "report-{$ticketId}"),
            'cantidad_javas_aplicada' => 0,
            'revision' => 0,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTicketWeighing(
        int $ticketId,
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
            'sexo' => Pesada::SEX_MALE,
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
            'estado' => Pesada::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
