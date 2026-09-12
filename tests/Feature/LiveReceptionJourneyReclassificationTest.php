<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LiveReceptionJourneyReclassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveReceptionJourneyReclassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $branchId;

    private int $chickenTypeId;

    private int $cageTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->actor->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
        ]);
        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'POLLO_VIVO', 'nombre' => 'Pollo vivo',
            'permite_despacho' => true, 'estado' => 'ACTIVO',
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_7', 'nombre' => 'Java 7 kg', 'peso_kg' => 7, 'estado' => 'ACTIVO',
        ]);
    }

    public function test_reception_history_moves_together_without_reapplying_stock_or_voided_records(): void
    {
        $this->freezeTime();
        $destinationJourneyId = $this->journey('2026-09-12');
        $sourceJourneyId = $this->journey('2026-09-13');
        $destinationReceptionId = $this->reception($destinationJourneyId);
        $sourceReceptionId = $this->reception($sourceJourneyId);
        $stationaryId = $this->weighing($destinationReceptionId, 1, '2026-09-12 20:00:00');
        $movedId = $this->weighing($sourceReceptionId, 1, '2026-09-12 21:15:00');
        $voidedId = $this->weighing($sourceReceptionId, 2, '2026-09-12 21:40:00', true);
        $beforeWeighing = DB::table('pesadas_recepcion_pollo_vivo')->where('id', $movedId)->first();
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->actor->empresa_id, 'tipo_documento' => 'RUC',
            'numero_documento' => '20100000001', 'nombre_razon_social' => 'Cliente',
            'direccion' => 'Dirección del cliente', 'estado' => 'ACTIVO',
        ]);
        $movementId = DB::table('movimientos_javas')->insertGetId([
            'empresa_id' => $this->actor->empresa_id, 'sucursal_id' => $this->branchId,
            'jornada_id' => $sourceJourneyId, 'cliente_id' => $clientId, 'tipo' => 'DESPACHO',
            'cantidad' => 4, 'cantidad_bandejas' => 0, 'pesada_recepcion_pollo_vivo_id' => $movedId,
            'fecha_movimiento' => '2026-09-12 21:15:00', 'created_by' => $this->actor->id,
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $destinationJourneyId, 'codigo' => 'T-20260913-001',
            'estado' => 'ANULADO', 'modulo_origen' => 'RECEPCION_POLLO_VIVO',
            'created_by' => $this->actor->id,
        ]);
        $linkId = DB::table('recepcion_pollo_vivo_tickets')->insertGetId([
            'recepcion_id' => $sourceReceptionId, 'ticket_despacho_id' => $ticketId,
            'columna' => 5, 'request_hash' => str_repeat('a', 64),
            'revision' => 5, 'cantidad_javas_aplicada' => 0, 'created_by' => $this->actor->id,
        ]);
        DB::table('inventarios_javas')->insert([
            'empresa_id' => $this->actor->empresa_id, 'cantidad_total' => 100,
            'updated_by' => $this->actor->id,
        ]);
        $version = now()->startOfSecond();
        foreach ([
            'pesadas_recepcion_pollo_vivo' => $movedId,
            'recepcion_pollo_vivo_tickets' => $linkId,
            'movimientos_javas' => $movementId,
        ] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['updated_at' => $version]);
        }

        $result = $this->reclassify('22:00:00', [$ticketId => $destinationJourneyId]);

        $this->assertSame([
            'reception_tickets' => 1, 'reception_weighings' => 2,
            'reception_numbers' => 1, 'reception_java_movements' => 1,
        ], $result);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $stationaryId, 'recepcion_id' => $destinationReceptionId, 'numero' => 1,
        ]);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $movedId, 'recepcion_id' => $destinationReceptionId, 'numero' => 3,
            'idempotency_key' => $beforeWeighing->idempotency_key,
            'pesada_at' => $beforeWeighing->pesada_at, 'peso_neto_kg' => $beforeWeighing->peso_neto_kg,
        ]);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $voidedId, 'recepcion_id' => $destinationReceptionId, 'numero' => 2,
            'estado' => 'ANULADA', 'anulada_at' => '2026-09-12 22:10:00', 'motivo_anulacion' => 'Error de captura',
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'id' => $movementId, 'jornada_id' => $destinationJourneyId, 'cantidad' => 4,
            'fecha_movimiento' => '2026-09-12 21:15:00',
        ]);
        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'id' => $linkId, 'recepcion_id' => $destinationReceptionId,
            'revision' => 6, 'cantidad_javas_aplicada' => 0, 'request_hash' => str_repeat('a', 64),
        ]);
        $this->assertDatabaseHas('recepciones_pollo_vivo', ['id' => $sourceReceptionId]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 100]);
        // Stale edit tokens must be invalidated even when the change happens in
        // the same second as the preceding edit.
        foreach ([
            'pesadas_recepcion_pollo_vivo' => $movedId,
            'recepcion_pollo_vivo_tickets' => $linkId,
            'movimientos_javas' => $movementId,
        ] as $table => $id) {
            $this->assertDatabaseHas($table, [
                'id' => $id, 'updated_at' => $version->copy()->addSecond()->format('Y-m-d H:i:s'),
            ]);
        }
        $this->assertDatabaseCount('auditoria_eventos', 4);
        $this->assertSame(0, array_sum($this->reclassify('22:00:00', [$ticketId => $destinationJourneyId])));
        $this->assertDatabaseCount('auditoria_eventos', 4);
    }

    public function test_exchanging_receptions_preserves_available_numbers_without_unique_collisions(): void
    {
        $firstJourneyId = $this->journey('2026-09-12');
        $secondJourneyId = $this->journey('2026-09-13');
        $firstReceptionId = $this->reception($firstJourneyId);
        $secondReceptionId = $this->reception($secondJourneyId);
        $firstRecordId = $this->weighing($firstReceptionId, 1, '2026-09-12 21:30:00');
        $secondRecordId = $this->weighing($secondReceptionId, 1, '2026-09-12 20:30:00');

        $result = $this->reclassify('21:00:00');

        $this->assertSame(2, $result['reception_weighings']);
        $this->assertSame(0, $result['reception_numbers']);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $firstRecordId, 'recepcion_id' => $secondReceptionId, 'numero' => 1,
        ]);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $secondRecordId, 'recepcion_id' => $firstReceptionId, 'numero' => 1,
        ]);
    }

    public function test_creates_missing_reception_and_leaves_other_companies_untouched(): void
    {
        $sourceJourneyId = $this->journey('2026-09-13');
        $sourceReceptionId = $this->reception($sourceJourneyId);
        $recordId = $this->weighing($sourceReceptionId, 4, '2026-09-12 21:30:00');
        $otherUser = User::factory()->create();
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $otherUser->empresa_id, 'codigo' => 'OTRA', 'nombre' => 'Otra sucursal',
            'zona_horaria' => 'America/Lima', 'estado' => 'ACTIVO',
        ]);
        $otherJourneyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $otherBranchId, 'fecha_operativa' => '2026-09-13', 'estado' => 'CERRADA',
            'abierta_por' => $otherUser->id, 'inicio_at' => '2026-09-12 21:00:00',
            'cierre_programado_at' => '2026-09-13 21:00:00',
        ]);
        $otherReceptionId = $this->reception($otherJourneyId);
        $otherRecordId = $this->weighing($otherReceptionId, 4, '2026-09-12 21:30:00');

        $result = $this->reclassify('22:00:00');

        $destinationJourneyId = DB::table('jornadas_operativas')->where('sucursal_id', $this->branchId)
            ->where('fecha_operativa', '2026-09-12')->value('id');
        $destinationReceptionId = DB::table('recepciones_pollo_vivo')->where('jornada_id', $destinationJourneyId)->value('id');
        $this->assertSame(1, $result['reception_weighings']);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $recordId, 'recepcion_id' => $destinationReceptionId, 'numero' => 4,
        ]);
        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $otherRecordId, 'recepcion_id' => $otherReceptionId, 'numero' => 4,
        ]);
        $this->assertDatabaseHas('recepciones_pollo_vivo', ['id' => $sourceReceptionId]);
    }

    private function reclassify(string $cutoff, array $ticketJourneys = []): array
    {
        return app(LiveReceptionJourneyReclassificationService::class)->reclassify(
            (int) $this->actor->empresa_id, $cutoff,
            fn (int $branchId, string $date, int $actorId): int => $this->journey($date),
            $ticketJourneys,
        );
    }

    private function journey(string $date): int
    {
        return DB::table('jornadas_operativas')->where('sucursal_id', $this->branchId)
            ->where('fecha_operativa', $date)->value('id')
            ?? DB::table('jornadas_operativas')->insertGetId([
                'sucursal_id' => $this->branchId, 'fecha_operativa' => $date, 'estado' => 'ABIERTA',
                'abierta_por' => $this->actor->id, 'inicio_at' => $date.' 00:00:00',
                'cierre_programado_at' => $date.' 21:00:00',
            ]);
    }

    private function reception(int $journeyId): int
    {
        return DB::table('recepciones_pollo_vivo')->insertGetId([
            'jornada_id' => $journeyId, 'origen' => 'Camión del día',
            'estado' => 'ABIERTA', 'created_by' => $this->actor->id,
        ]);
    }

    private function weighing(int $receptionId, int $number, string $time, bool $voided = false): int
    {
        return DB::table('pesadas_recepcion_pollo_vivo')->insertGetId([
            'recepcion_id' => $receptionId, 'idempotency_key' => (string) Str::uuid(),
            'numero' => $number, 'columna' => 1, 'propietario_tipo' => 'PROPIA',
            'destino_tipo' => 'ALMACEN', 'sexo' => 'MACHO', 'tipo_pollo_id' => $this->chickenTypeId,
            'tipo_java_id' => $this->cageTypeId, 'origen_peso' => 'MANUAL', 'aves_por_java' => 5,
            'cantidad_javas' => 1, 'cantidad_aves' => 5, 'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => 20, 'peso_bruto_kg' => 20, 'tara_total_kg' => 7, 'peso_neto_kg' => 13,
            'pesada_at' => $time, 'estado' => $voided ? 'ANULADA' : 'ACTIVA',
            'anulada_por' => $voided ? $this->actor->id : null,
            'anulada_at' => $voided ? '2026-09-12 22:10:00' : null,
            'motivo_anulacion' => $voided ? 'Error de captura' : null,
            'created_by' => $this->actor->id, 'created_at' => $time, 'updated_at' => $time,
        ]);
    }
}
