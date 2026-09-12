<?php

namespace Tests\Feature;

use App\Models\TicketDespacho;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class JourneyReclassificationTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $chickenTypeId;

    private int $cageTypeId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'America/Lima']);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'America/Lima'));
        $this->user = User::factory()->create();
        $this->makeAdministrator($this->user);
        $this->branchId = $this->branch($this->user);
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'VIVO', 'nombre' => 'Pollo vivo', 'permite_despacho' => true, 'estado' => 'ACTIVO',
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'RECLASS_JAVA', 'nombre' => 'Java de prueba', 'peso_kg' => 7, 'estado' => 'ACTIVO',
        ]);
        $this->clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'nombre_razon_social' => 'Cliente histórico',
            'tipo_documento' => 'RUC', 'numero_documento' => '20123456789', 'direccion' => 'Dirección de prueba', 'estado' => 'ACTIVO',
        ]);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_changing_cutoff_reclassifies_wholesale_retail_and_voided_history_without_altering_ticket_facts(): void
    {
        $ids = [
            $this->ticket('MAY-1', '2026-09-11', ['2026-09-10 21:30:00']),
            $this->ticket('MAY2-1', '2026-09-11', ['2026-09-10 21:45:00'], ['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]),
            $this->ticket('MIN-1', '2026-09-11', ['2026-09-10 21:59:59'], ['canal' => TicketDespacho::CHANNEL_RETAIL]),
            $this->ticket('ANULADO-1', '2026-09-11', ['2026-09-10 21:15:00'], ['estado' => TicketDespacho::STATUS_VOIDED, 'anulado_por' => $this->user->id, 'anulado_at' => '2026-09-11 15:00:00', 'motivo_anulacion' => 'Corrección histórica']),
        ];
        DB::table('pesadas')->where('ticket_id', $ids[3])->update(['estado' => 'ANULADA', 'motivo_anulacion' => 'Corrección histórica']);
        $beforeTickets = DB::table('tickets_despacho')->orderBy('id')->get();
        $beforeWeighings = DB::table('pesadas')->orderBy('id')->get();

        $this->save('22:00')->assertOk()->assertJsonPath('data.reclassified.tickets', 4);

        foreach ($beforeTickets as $before) {
            $this->assertTicketDate((int) $before->id, '2026-09-10');
            $after = (array) DB::table('tickets_despacho')->where('id', $before->id)->first();
            $this->assertSame(Arr::except((array) $before, ['jornada_id', 'updated_at']), Arr::except($after, ['jornada_id', 'updated_at']));
        }
        $this->assertEquals($beforeWeighings, DB::table('pesadas')->orderBy('id')->get());
        $this->assertDatabaseHas('jornadas_operativas', [
            'sucursal_id' => $this->branchId, 'fecha_operativa' => '2026-09-10',
            'inicio_at' => '2026-09-09 22:00:00', 'cierre_programado_at' => '2026-09-10 22:00:00',
        ]);
        $this->assertDatabaseHas('jornadas_operativas', [
            'sucursal_id' => $this->branchId, 'fecha_operativa' => '2026-09-11',
            'inicio_at' => '2026-09-10 22:00:00', 'cierre_programado_at' => '2026-09-11 22:00:00',
        ]);
    }

    public function test_first_chronological_weighing_anchors_the_complete_ticket_even_when_voided(): void
    {
        $ticket = $this->ticket('CRUZA', '2026-09-11', ['2026-09-10 22:15:00', '2026-09-10 21:30:00']);
        DB::table('pesadas')->where('ticket_id', $ticket)->where('numero', 2)->update(['estado' => 'ANULADA']);

        $this->save('22:00')->assertOk();

        $this->assertTicketDate($ticket, '2026-09-10');
        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertSame(2, DB::table('pesadas')->where('ticket_id', $ticket)->count());
    }

    #[DataProvider('boundaries')]
    public function test_historical_tickets_follow_the_new_boundary(string $cutoff, string $time, string $expectedDate): void
    {
        $ticket = $this->ticket('LIMITE', '2026-09-11', [$time]);

        $this->save($cutoff)->assertOk();

        $this->assertTicketDate($ticket, $expectedDate);
    }

    public static function boundaries(): array
    {
        return [
            'second before' => ['22:00', '2026-09-10 21:59:59', '2026-09-10'],
            'exact cutoff' => ['22:00', '2026-09-10 22:00:00', '2026-09-11'],
            'second after' => ['22:00', '2026-09-10 22:00:01', '2026-09-11'],
            'midnight' => ['00:00', '2026-09-10 00:00:00', '2026-09-11'],
        ];
    }

    public function test_fallback_uses_closure_then_creation_time_and_respects_each_branch_timezone(): void
    {
        $secondBranch = $this->branch($this->user, 'America/Los_Angeles');
        $closed = $this->ticket('SIN-PESADAS-CERRADO', '2026-09-11', [], [
            'cerrado_at' => '2026-09-10 23:30:00',
        ], $secondBranch);
        $open = $this->ticket('SIN-PESADAS-ABIERTO', '2026-09-11', [], [
            'cerrado_at' => null, 'estado' => 'ABIERTO', 'created_at' => '2026-09-10 23:30:00',
        ], $secondBranch);
        $localWeighing = $this->ticket('PESADA-LOCAL', '2026-09-11', ['2026-09-10 21:30:00'], [], $secondBranch);
        DB::table('sucursales')->where('id', $secondBranch)->update(['estado' => 'INACTIVO']);
        $otherUser = User::factory()->create();
        $otherBranch = $this->branch($otherUser);
        $otherTicket = $this->ticket('OTRA-EMPRESA', '2026-09-11', ['2026-09-10 21:30:00'], [], $otherBranch);
        $otherJourney = DB::table('jornadas_operativas')->where('sucursal_id', $otherBranch)->first();

        $this->save('22:00')->assertOk()->assertJsonPath('data.reclassified.tickets', 3);

        foreach ([$closed, $open, $localWeighing] as $ticket) {
            $this->assertTicketDate($ticket, '2026-09-10');
        }
        $this->assertTicketDate($otherTicket, '2026-09-11');
        $this->assertEquals($otherJourney, DB::table('jornadas_operativas')->where('id', $otherJourney->id)->first());
        $this->assertDatabaseHas('empresas', ['id' => $otherUser->empresa_id, 'hora_corte_operativo' => '21:00:00']);
    }

    public function test_related_java_movements_and_internal_document_dates_move_without_changing_payments_or_values(): void
    {
        $ticket = $this->ticket('COBRADO', '2026-09-11', ['2026-09-10 21:30:00']);
        $oldJourney = (int) DB::table('tickets_despacho')->where('id', $ticket)->value('jornada_id');
        $movement = DB::table('movimientos_javas')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'sucursal_id' => $this->branchId,
            'jornada_id' => $oldJourney, 'cliente_id' => $this->clientId, 'tipo' => 'SALIDA', 'cantidad' => 2,
            'ticket_despacho_id' => $ticket, 'fecha_movimiento' => '2026-09-10 22:30:00', 'created_by' => $this->user->id,
        ]);
        $document = $this->document($ticket);
        $manualTicket = $this->ticket('DOC-MANUAL', '2026-09-11', ['2026-09-10 21:30:00']);
        $manual = $this->document($manualTicket, ['origen_codigo' => 'MANUAL']);
        $invoiceTicket = $this->ticket('DOC-FACTURA', '2026-09-11', ['2026-09-10 21:30:00']);
        $invoice = $this->document($invoiceTicket, ['tipo_documento' => 'FACTURA']);
        $payment = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tercero_id' => $this->clientId,
            'direccion' => 'ENTRADA', 'fecha_hora' => '2026-09-11 11:00:00', 'metodo' => 'EFECTIVO',
            'moneda' => 'PEN', 'importe' => 40, 'created_by' => $this->user->id,
        ]);
        DB::table('pago_aplicaciones')->insert(['pago_id' => $payment, 'comprobante_id' => $document, 'importe_aplicado' => 40]);
        $beforeDocument = (array) DB::table('comprobantes')->where('id', $document)->first();
        $beforeMovement = (array) DB::table('movimientos_javas')->where('id', $movement)->first();
        $beforePayments = DB::table('pagos')->get();
        $beforeApplications = DB::table('pago_aplicaciones')->get();

        $this->save('22:00')->assertOk();

        $target = DB::table('tickets_despacho')->where('id', $ticket)->value('jornada_id');
        $this->assertDatabaseHas('movimientos_javas', ['id' => $movement, 'jornada_id' => $target]);
        $afterMovement = (array) DB::table('movimientos_javas')->where('id', $movement)->first();
        $this->assertSame(Arr::except($beforeMovement, ['jornada_id', 'updated_at']), Arr::except($afterMovement, ['jornada_id', 'updated_at']));
        $this->assertDatabaseHas('comprobantes', ['id' => $document, 'fecha_emision' => '2026-09-10', 'fecha_vencimiento' => '2026-09-10']);
        $afterDocument = (array) DB::table('comprobantes')->where('id', $document)->first();
        $dates = ['fecha_emision', 'fecha_vencimiento', 'updated_at'];
        $this->assertSame(Arr::except($beforeDocument, $dates), Arr::except($afterDocument, $dates));
        foreach ([$manual, $invoice] as $id) {
            $this->assertDatabaseHas('comprobantes', ['id' => $id, 'fecha_emision' => '2026-09-11', 'fecha_vencimiento' => '2026-09-11']);
        }
        $this->assertEquals($beforePayments, DB::table('pagos')->get());
        $this->assertEquals($beforeApplications, DB::table('pago_aplicaciones')->get());
    }

    public function test_daily_summary_keeps_a_reclassified_ticket_whole_while_explicit_time_filters_select_weighings(): void
    {
        $ticket = $this->ticket('RESUMEN-ENTERO', '2026-09-11', ['2026-09-10 21:30:00', '2026-09-10 22:15:00']);
        $this->save('22:00')->assertOk();

        $this->getJson('/api/v1/operacion/tickets-dia?date=2026-09-10')
            ->assertOk()->assertJsonPath('data.summary.tickets', 1)->assertJsonPath('data.summary.records', 2)
            ->assertJsonPath('data.summary.net_weight_kg', 200)->assertJsonPath('data.tickets.0.id', $ticket);
        $this->getJson('/api/v1/operacion/tickets-dia?date=2026-09-11')
            ->assertOk()->assertJsonPath('data.summary.tickets', 0);
        $this->getJson('/api/v1/operacion/tickets-dia?from_date=2026-09-10&from_time=22:00&to_date=2026-09-10&to_time=23:00')
            ->assertOk()->assertJsonPath('data.summary.tickets', 1)->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.net_weight_kg', 100);
        $this->getJson('/api/v1/operacion/tickets-dia/impresion?date=2026-09-10')
            ->assertOk()->assertJsonPath('data.summary.records', 2);
    }

    public function test_reapplying_the_same_cutoff_is_idempotent_and_reverting_it_restores_the_original_journey(): void
    {
        $ticket = $this->ticket('REPETIR', '2026-09-11', ['2026-09-10 21:30:00']);
        $originalJourney = DB::table('tickets_despacho')->where('id', $ticket)->value('jornada_id');
        $this->save('22:00')->assertOk();
        $afterFirst = DB::table('tickets_despacho')->where('id', $ticket)->first();
        $journeys = DB::table('jornadas_operativas')->orderBy('id')->get();

        $this->save('22:00', '22:00')->assertOk()->assertJsonPath('data.reclassified.tickets', 0);

        $this->assertEquals($afterFirst, DB::table('tickets_despacho')->where('id', $ticket)->first());
        $this->assertEquals($journeys, DB::table('jornadas_operativas')->orderBy('id')->get());
        $this->save('21:00', '22:00')->assertOk();
        $this->assertDatabaseHas('tickets_despacho', ['id' => $ticket, 'jornada_id' => $originalJourney, 'codigo' => 'REPETIR']);
    }

    public function test_duplicate_codes_in_the_target_journey_roll_back_the_cutoff_and_every_historical_change(): void
    {
        $this->ticket('DUPLICADO', '2026-09-10', ['2026-09-10 20:30:00']);
        $this->ticket('DUPLICADO', '2026-09-11', ['2026-09-10 21:30:00']);
        $before = $this->databaseState();

        $this->save('22:00')->assertUnprocessable()->assertJsonValidationErrors('cutoff');

        $this->assertEquals($before, $this->databaseState());
    }

    public function test_conflicting_daily_counts_roll_back_ticket_movement_documents_and_configuration(): void
    {
        $ticket = $this->ticket('ANTES-DEL-CONFLICTO', '2026-09-11', ['2026-09-10 21:30:00']);
        $this->document($ticket);
        $this->dailyCount('2026-09-10', '2026-09-10 20:30:00', 90);
        $this->dailyCount('2026-09-11', '2026-09-10 21:30:00', 95);
        $before = $this->databaseState();

        $this->save('22:00')->assertUnprocessable()->assertJsonValidationErrors('cutoff');

        $this->assertEquals($before, $this->databaseState());
    }

    public function test_daily_counts_move_to_consecutive_journeys_without_overwriting_any_snapshot(): void
    {
        $first = $this->dailyCount('2026-09-10', '2026-09-10 20:30:00', 90);
        $second = $this->dailyCount('2026-09-11', '2026-09-11 20:30:00', 95);
        $before = DB::table('conteos_diarios_javas')->orderBy('id')->get();

        $this->save('20:00')->assertOk()->assertJsonPath('data.reclassified.daily_counts', 2);

        foreach ([$first => '2026-09-11', $second => '2026-09-12'] as $id => $date) {
            $count = DB::table('conteos_diarios_javas')->where('id', $id)->first();
            $this->assertDatabaseHas('jornadas_operativas', ['id' => $count->jornada_id, 'fecha_operativa' => $date]);
            $this->assertSame(Arr::except((array) $before->firstWhere('id', $id), ['jornada_id', 'updated_at']), Arr::except((array) $count, ['jornada_id', 'updated_at']));
        }
        $this->assertDatabaseCount('conteos_diarios_javas', 2);
    }

    #[DataProvider('existingDestinationPrograms')]
    public function test_origin_plan_and_selected_warehouses_follow_the_ticket_without_overwriting_or_duplicating_the_source(bool $hasDestination): void
    {
        $ticket = $this->ticket('CON-ORIGEN', '2026-09-11', ['2026-09-10 21:30:00', '2026-09-10 21:45:00']);
        $vehicle = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'placa' => 'ABC-123', 'estado' => 'ACTIVO',
        ]);
        $providerVehicle = DB::table('proveedor_vehiculos')->insertGetId([
            'proveedor_id' => $this->clientId, 'vehiculo_id' => $vehicle, 'vigente_desde' => '2026-01-01',
            'created_by' => $this->user->id,
        ]);
        $programValues = [
            'sucursal_id' => $this->branchId, 'estado' => 'PUBLICADA',
            'observaciones' => 'Plan original', 'publicada_por' => $this->user->id,
            'publicada_at' => '2026-09-10 18:00:00', 'created_by' => $this->user->id,
        ];
        $sourceProgram = DB::table('programaciones_recepcion')->insertGetId([...$programValues, 'fecha_operativa' => '2026-09-11']);
        $detailValues = [
            'proveedor_vehiculo_id' => $providerVehicle, 'numero_visita' => 2, 'orden_llegada' => 3,
            'estado' => 'COMPLETADA', 'conductor_nombre_snapshot' => 'Conductor histórico',
            'conductor_dni_snapshot' => '12345678', 'observaciones' => 'Carga original',
            'created_by' => $this->user->id,
        ];
        $sourceDetail = DB::table('programacion_recepcion_detalles')->insertGetId([...$detailValues, 'programacion_id' => $sourceProgram]);
        DB::table('pesadas')->where('ticket_id', $ticket)->update([
            'programacion_recepcion_detalle_id' => $sourceDetail, 'proveedor_origen_id' => $this->clientId,
            'vehiculo_id' => $vehicle, 'placa_snapshot' => 'ABC-123',
        ]);
        $warehouses = [];
        foreach (range(1, 3) as $number) {
            $warehouses[] = DB::table('almacenes')->insertGetId([
                'sucursal_id' => $this->branchId, 'codigo' => 'ALMACEN-'.$number,
                'nombre' => 'Almacén '.$number, 'permite_stock_negativo' => false, 'estado' => 'ACTIVO',
            ]);
        }
        foreach ([$warehouses[0], $warehouses[1]] as $warehouse) {
            DB::table('programacion_recepcion_almacenes')->insert(['programacion_id' => $sourceProgram, 'almacen_id' => $warehouse]);
        }
        $existingTargetDetail = null;
        if ($hasDestination) {
            $targetProgram = DB::table('programaciones_recepcion')->insertGetId([
                ...$programValues, 'fecha_operativa' => '2026-09-10', 'observaciones' => 'Plan del destino',
            ]);
            $existingTargetDetail = DB::table('programacion_recepcion_detalles')->insertGetId([
                ...$detailValues, 'programacion_id' => $targetProgram,
            ]);
            foreach ([$warehouses[0], $warehouses[2]] as $warehouse) {
                DB::table('programacion_recepcion_almacenes')->insert(['programacion_id' => $targetProgram, 'almacen_id' => $warehouse]);
            }
        }
        $beforeSource = DB::table('programaciones_recepcion')->where('id', $sourceProgram)->first();
        $beforeDetail = DB::table('programacion_recepcion_detalles')->where('id', $sourceDetail)->first();
        $beforeSelections = DB::table('programacion_recepcion_almacenes')->where('programacion_id', $sourceProgram)->orderBy('almacen_id')->get();
        $beforeWeighings = DB::table('pesadas')->where('ticket_id', $ticket)->orderBy('id')->get();

        $this->save('22:00')->assertOk()->assertJsonPath('data.reclassified.origin_links', 2);

        $destination = DB::table('programaciones_recepcion')->where('sucursal_id', $this->branchId)->where('fecha_operativa', '2026-09-10')->first();
        $destinationDetail = DB::table('programacion_recepcion_detalles')->where('programacion_id', $destination->id)->sole();
        $this->assertSame($hasDestination ? 'Plan del destino' : 'Plan original', $destination->observaciones);
        $this->assertSame('Carga original', $destinationDetail->observaciones);
        if ($hasDestination) {
            $this->assertSame($existingTargetDetail, $destinationDetail->id);
        }
        $this->assertSame($providerVehicle, $destinationDetail->proveedor_vehiculo_id);
        $this->assertSame(2, $destinationDetail->numero_visita);
        foreach ($beforeWeighings as $weighing) {
            $after = DB::table('pesadas')->where('id', $weighing->id)->first();
            $this->assertSame($destinationDetail->id, $after->programacion_recepcion_detalle_id);
            $this->assertSame(Arr::except((array) $weighing, ['programacion_recepcion_detalle_id', 'updated_at']), Arr::except((array) $after, ['programacion_recepcion_detalle_id', 'updated_at']));
        }
        $expectedWarehouses = $hasDestination ? $warehouses : array_slice($warehouses, 0, 2);
        $this->assertSame($expectedWarehouses, DB::table('programacion_recepcion_almacenes')->where('programacion_id', $destination->id)->orderBy('almacen_id')->pluck('almacen_id')->all());
        $this->assertEquals($beforeSource, DB::table('programaciones_recepcion')->where('id', $sourceProgram)->first());
        $this->assertEquals($beforeDetail, DB::table('programacion_recepcion_detalles')->where('id', $sourceDetail)->first());
        $this->assertEquals($beforeSelections, DB::table('programacion_recepcion_almacenes')->where('programacion_id', $sourceProgram)->orderBy('almacen_id')->get());

        $this->save('22:00', '22:00')->assertOk()->assertJsonPath('data.reclassified.origin_links', 0);
        $this->assertDatabaseCount('programaciones_recepcion', 2);
        $this->assertDatabaseCount('programacion_recepcion_detalles', 2);
        $this->assertSame(count($expectedWarehouses), DB::table('programacion_recepcion_almacenes')->where('programacion_id', $destination->id)->count());
    }

    public static function existingDestinationPrograms(): array
    {
        return ['create destination plan' => [false], 'merge into destination plan' => [true]];
    }

    public function test_consecutive_program_moves_only_copy_each_original_warehouse_selection(): void
    {
        $this->ticket('CADENA-DIA-10', '2026-09-10', ['2026-09-10 20:30:00']);
        $this->ticket('CADENA-DIA-11', '2026-09-11', ['2026-09-11 20:30:00']);
        $programs = [];
        $warehouses = [];
        foreach ([10, 11, 12] as $day) {
            $programs[$day] = DB::table('programaciones_recepcion')->insertGetId([
                'sucursal_id' => $this->branchId, 'fecha_operativa' => '2026-09-'.$day,
                'estado' => 'PUBLICADA', 'created_by' => $this->user->id,
            ]);
            $warehouses[$day] = DB::table('almacenes')->insertGetId([
                'sucursal_id' => $this->branchId, 'codigo' => 'ALMACEN-'.$day,
                'nombre' => 'Almacén '.$day, 'permite_stock_negativo' => false, 'estado' => 'ACTIVO',
            ]);
            DB::table('programacion_recepcion_almacenes')->insert([
                'programacion_id' => $programs[$day], 'almacen_id' => $warehouses[$day],
            ]);
        }

        $this->save('20:00')->assertOk()->assertJsonPath('data.reclassified.tickets', 2);

        foreach ([10 => [10], 11 => [10, 11], 12 => [11, 12]] as $day => $warehouseDays) {
            $expected = array_map(fn (int $warehouseDay): int => $warehouses[$warehouseDay], $warehouseDays);
            $this->assertSame($expected, DB::table('programacion_recepcion_almacenes')
                ->where('programacion_id', $programs[$day])->orderBy('almacen_id')->pluck('almacen_id')->all());
        }
        $this->assertDatabaseMissing('programacion_recepcion_almacenes', [
            'programacion_id' => $programs[12], 'almacen_id' => $warehouses[10],
        ]);
        $after = DB::table('programacion_recepcion_almacenes')->orderBy('programacion_id')->orderBy('almacen_id')->get();

        $this->save('20:00', '20:00')->assertOk();

        $this->assertEquals($after, DB::table('programacion_recepcion_almacenes')->orderBy('programacion_id')->orderBy('almacen_id')->get());
    }

    public function test_origin_detail_collision_preserves_driver_times_notes_and_the_shared_link_for_all_affected_tickets(): void
    {
        $ticketIds = [
            $this->ticket('ORIGEN-COMP-1', '2026-09-11', ['2026-09-10 21:20:00', '2026-09-10 21:30:00']),
            $this->ticket('ORIGEN-COMP-2', '2026-09-11', ['2026-09-10 21:40:00']),
        ];
        $vehicle = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'placa' => 'DRV-456', 'estado' => 'ACTIVO',
        ]);
        $providerVehicle = DB::table('proveedor_vehiculos')->insertGetId([
            'proveedor_id' => $this->clientId, 'vehiculo_id' => $vehicle, 'vigente_desde' => '2026-01-01',
            'created_by' => $this->user->id,
        ]);
        $sourceDriver = DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'nombre_completo' => 'Conductor original', 'numero_documento' => '12345678',
        ]);
        $targetDriver = DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'nombre_completo' => 'Otro conductor', 'numero_documento' => '87654321',
        ]);
        $programs = [];
        foreach ([10, 11] as $day) {
            $programs[$day] = DB::table('programaciones_recepcion')->insertGetId([
                'sucursal_id' => $this->branchId, 'fecha_operativa' => '2026-09-'.$day,
                'estado' => 'PUBLICADA', 'created_by' => $this->user->id,
            ]);
        }
        $detailValues = [
            'proveedor_vehiculo_id' => $providerVehicle, 'numero_visita' => 1, 'orden_llegada' => 3,
            'estado' => 'COMPLETADA', 'conductor_id' => $sourceDriver,
            'conductor_nombre_snapshot' => 'Conductor original', 'conductor_dni_snapshot' => '12345678',
            'hora_estimada' => '20:45:00', 'llegada_at' => '2026-09-10 20:50:00',
            'recepcion_iniciada_at' => '2026-09-10 21:00:00', 'completada_at' => '2026-09-10 21:45:00',
            'observaciones' => 'Carga que corresponde a las tres pesadas', 'created_by' => $this->user->id,
        ];
        $sourceDetail = DB::table('programacion_recepcion_detalles')->insertGetId([
            ...$detailValues, 'programacion_id' => $programs[11],
        ]);
        $existingDetail = DB::table('programacion_recepcion_detalles')->insertGetId([
            ...$detailValues, 'programacion_id' => $programs[10], 'conductor_id' => $targetDriver,
            'conductor_nombre_snapshot' => 'Otro conductor', 'conductor_dni_snapshot' => '87654321',
            'hora_estimada' => '08:00:00', 'llegada_at' => '2026-09-10 08:05:00',
            'recepcion_iniciada_at' => '2026-09-10 08:10:00', 'completada_at' => '2026-09-10 09:00:00',
            'observaciones' => 'Otra carga del mismo camión',
        ]);
        DB::table('pesadas')->whereIn('ticket_id', $ticketIds)->update([
            'programacion_recepcion_detalle_id' => $sourceDetail, 'proveedor_origen_id' => $this->clientId,
            'vehiculo_id' => $vehicle, 'placa_snapshot' => 'DRV-456',
        ]);
        $beforeSource = DB::table('programacion_recepcion_detalles')->where('id', $sourceDetail)->first();
        $beforeExisting = DB::table('programacion_recepcion_detalles')->where('id', $existingDetail)->first();
        $beforeWeighings = DB::table('pesadas')->whereIn('ticket_id', $ticketIds)->orderBy('id')->get();

        $this->save('22:00')->assertOk()->assertJsonPath('data.reclassified.origin_links', 3);

        $mappedIds = DB::table('pesadas')->whereIn('ticket_id', $ticketIds)->distinct()->pluck('programacion_recepcion_detalle_id')->all();
        $this->assertCount(1, $mappedIds);
        $this->assertNotContains($mappedIds[0], [$sourceDetail, $existingDetail]);
        $movedDetail = DB::table('programacion_recepcion_detalles')->where('id', $mappedIds[0])->first();
        $this->assertSame($programs[10], $movedDetail->programacion_id);
        $this->assertSame(2, $movedDetail->numero_visita);
        $identity = ['id', 'programacion_id', 'numero_visita', 'created_at', 'updated_at'];
        $this->assertSame(Arr::except((array) $beforeSource, $identity), Arr::except((array) $movedDetail, $identity));
        $this->assertEquals($beforeSource, DB::table('programacion_recepcion_detalles')->where('id', $sourceDetail)->first());
        $this->assertEquals($beforeExisting, DB::table('programacion_recepcion_detalles')->where('id', $existingDetail)->first());
        foreach ($beforeWeighings as $before) {
            $after = DB::table('pesadas')->where('id', $before->id)->first();
            $this->assertSame(Arr::except((array) $before, ['programacion_recepcion_detalle_id', 'updated_at']), Arr::except((array) $after, ['programacion_recepcion_detalle_id', 'updated_at']));
        }
        $afterDetails = DB::table('programacion_recepcion_detalles')->orderBy('id')->get();
        $afterWeighings = DB::table('pesadas')->orderBy('id')->get();

        $this->save('22:00', '22:00')->assertOk()->assertJsonPath('data.reclassified.origin_links', 0);

        $this->assertDatabaseCount('programacion_recepcion_detalles', 3);
        $this->assertEquals($afterDetails, DB::table('programacion_recepcion_detalles')->orderBy('id')->get());
        $this->assertEquals($afterWeighings, DB::table('pesadas')->orderBy('id')->get());
    }

    public function test_response_counts_journeys_created_while_reclassifying_reception_weighings(): void
    {
        $sourceJourney = $this->journey('2026-09-11');
        $reception = DB::table('recepciones_pollo_vivo')->insertGetId([
            'jornada_id' => $sourceJourney, 'estado' => 'ABIERTA', 'created_by' => $this->user->id,
        ]);
        $weighing = DB::table('pesadas_recepcion_pollo_vivo')->insertGetId([
            'recepcion_id' => $reception, 'idempotency_key' => (string) Str::uuid(), 'numero' => 1,
            'columna' => 1, 'propietario_tipo' => 'EMPRESA', 'destino_tipo' => 'CLIENTE', 'cliente_destino_id' => $this->clientId,
            'sexo' => 'MACHO', 'tipo_pollo_id' => $this->chickenTypeId, 'tipo_java_id' => $this->cageTypeId,
            'origen_peso' => 'MANUAL', 'aves_por_java' => 25, 'cantidad_javas' => 2, 'cantidad_aves' => 50,
            'peso_java_kg_snapshot' => 7, 'peso_leido_kg' => 114, 'peso_bruto_kg' => 114,
            'tara_total_kg' => 14, 'peso_neto_kg' => 100, 'pesada_at' => '2026-09-10 21:30:00',
            'estado' => 'ACTIVA', 'created_by' => $this->user->id,
        ]);

        $this->save('22:00')->assertOk()->assertJsonPath('data.reclassified.journeys', 2)
            ->assertJsonPath('data.reclassified.reception_weighings', 1);

        $destinationDate = DB::table('pesadas_recepcion_pollo_vivo as weighing')
            ->join('recepciones_pollo_vivo as reception', 'reception.id', '=', 'weighing.recepcion_id')
            ->join('jornadas_operativas as journey', 'journey.id', '=', 'reception.jornada_id')
            ->where('weighing.id', $weighing)->value('journey.fecha_operativa');
        $this->assertSame('2026-09-10', $destinationDate);
        $this->assertDatabaseCount('jornadas_operativas', 2);
    }

    private function save(string $cutoff, string $expected = '21:00'): TestResponse
    {
        return $this->putJson('/api/v1/configuracion-general', ['cutoff' => $cutoff, 'expected_cutoff' => $expected]);
    }

    private function assertTicketDate(int $ticket, string $date): void
    {
        $actual = DB::table('tickets_despacho as ticket')->join('jornadas_operativas as journey', 'journey.id', '=', 'ticket.jornada_id')
            ->where('ticket.id', $ticket)->value('journey.fecha_operativa');
        $this->assertSame($date, $actual);
    }

    private function branch(User $user, string $timezone = 'America/Lima'): int
    {
        return DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id, 'codigo' => substr((string) Str::uuid(), 0, 16),
            'nombre' => 'Sucursal de prueba', 'zona_horaria' => $timezone, 'estado' => 'ACTIVO',
        ]);
    }

    private function journey(string $date, ?int $branchId = null): int
    {
        $branchId ??= $this->branchId;
        $existing = DB::table('jornadas_operativas')->where('sucursal_id', $branchId)->where('fecha_operativa', $date)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $end = CarbonImmutable::parse($date, 'America/Lima')->setTime(21, 0);

        return DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId, 'fecha_operativa' => $date, 'estado' => 'CERRADA',
            'abierta_por' => $this->user->id, 'inicio_at' => $end->subDay()->format('Y-m-d H:i:s'),
            'cierre_programado_at' => $end->format('Y-m-d H:i:s'),
        ]);
    }

    private function ticket(string $code, string $date, array $times, array $overrides = [], ?int $branchId = null): int
    {
        $id = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $this->journey($date, $branchId), 'codigo' => $code,
            'referencia_externa' => (string) Str::uuid(), 'canal' => 'MAYORISTA', 'tipo_operacion' => 'DESPACHO',
            'cliente_destino_id' => $this->clientId, 'estado' => 'CERRADO',
            'cerrado_at' => '2026-09-10 23:00:00', 'cerrado_por' => $this->user->id,
            'created_by' => $this->user->id, 'created_at' => '2026-09-10 23:00:00', 'updated_at' => now(),
            ...$overrides,
        ]);
        foreach ($times as $index => $time) {
            DB::table('pesadas')->insert([
                'ticket_id' => $id, 'numero' => $index + 1, 'tipo_pollo_id' => $this->chickenTypeId,
                'tipo_java_id' => $this->cageTypeId, 'origen_peso' => 'MANUAL', 'aves_por_java' => 25,
                'cantidad_javas' => 2, 'cantidad_aves' => 50, 'peso_java_kg_snapshot' => 7,
                'peso_leido_kg' => 114, 'peso_bruto_kg' => 114, 'tara_total_kg' => 14, 'peso_neto_kg' => 100,
                'pesada_at' => $time, 'estado' => 'ACTIVA', 'created_by' => $this->user->id,
            ]);
        }

        return $id;
    }

    private function document(int $ticket, array $overrides = []): int
    {
        return DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tercero_id' => $this->clientId,
            'operacion' => 'VENTA', 'naturaleza' => 'CARGO', 'tipo_documento' => 'INTERNO',
            'codigo' => 'V-'.$ticket, 'origen_codigo' => 'AUTOMATICO', 'origen_clave' => 'VENTA:TICKET:'.$ticket,
            'fecha_emision' => '2026-09-11', 'fecha_vencimiento' => '2026-09-11', 'moneda' => 'PEN',
            'subtotal' => 100, 'impuesto' => 0, 'total' => 100, 'saldo_pendiente' => 60,
            'estado' => 'PARCIAL', 'created_by' => $this->user->id,
            ...$overrides,
        ]);
    }

    private function dailyCount(string $date, string $time, int $amount): int
    {
        return DB::table('conteos_diarios_javas')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'jornada_id' => $this->journey($date),
            'cantidad_en_empresa' => $amount, 'cantidad_esperada' => 100, 'diferencia' => $amount - 100,
            'contado_at' => $time, 'contado_por' => $this->user->id,
        ]);
    }

    private function databaseState(): array
    {
        return collect(['empresas', 'jornadas_operativas', 'tickets_despacho', 'pesadas', 'comprobantes', 'conteos_diarios_javas', 'auditoria_eventos'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all();
    }
}
