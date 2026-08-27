<?php

namespace Tests\Feature;

use App\Models\Balanza;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class LiveChickenReceptionDispatchTicketApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    private int $cageTypeId;

    private int $vehicleId;

    private int $driverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, [
            'MODULO_RECEPCION_POLLO_VIVO',
            'MODULO_RESUMEN_JORNADA',
            'MODULO_DIRECTORIO',
            'MODULO_FINANZAS',
        ]);
        $this->makeAdministrator($this->user);
        Sanctum::actingAs($this->user, ['api']);

        $liveTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_700',
            'nombre' => 'Java 7.00 kg',
            'peso_kg' => 7,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $client = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20100000001',
            'nombre_razon_social' => 'Cliente del despacho',
            'direccion' => 'Dirección de prueba',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $client->roles()->create(['rol' => TerceroRole::CLIENT]);
        $this->clientId = (int) $client->id;
        $this->vehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'ENT-001',
            'marca' => 'Hino',
            'modelo' => '300',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->driverId = DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre_completo' => 'Chofer de reparto',
            'tipo_documento' => 'CC',
            'numero_documento' => '10990001',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priceListId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => null,
            'codigo' => 'GENERAL-VENTA',
            'nombre' => 'Lista general de venta',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('precios_historial')->insert([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $liveTypeId,
            'precio_kg' => 8.75,
            'vigente_desde' => now()->subMinute(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Precio de prueba',
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
    }

    public function test_mixed_ticket_is_registered_once_and_projected_into_own_sex_lanes(): void
    {
        $payload = $this->ticketPayload();

        $response = $this->postJson('/api/v1/recepcion-pollo-vivo/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('ticket.editable', true)
            ->assertJsonPath('ticket.edit_restriction', null);
        $ticketId = (int) ($response->json('ticket.id') ?: $response->json('data.id'));

        $this->assertGreaterThan(0, $ticketId);
        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'modulo_origen' => TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            'cliente_destino_id' => $this->clientId,
            'vehiculo_entrega_id' => $this->vehicleId,
            'conductor_entrega_id' => $this->driverId,
            'estado' => TicketDespacho::STATUS_CLOSED,
        ]);
        $this->assertDatabaseCount('pesadas', 2);
        $this->assertDatabaseCount('pesadas_recepcion_pollo_vivo', 0);
        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticketId,
            'columna' => 5,
            'cantidad_javas_aplicada' => 3,
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('inventarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_total' => 3,
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
            'cliente_id' => $this->clientId,
            'cantidad' => 3,
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'ticket_id' => $ticketId,
            'tipo' => 'DESPACHO_DIRECTO',
            'tercero_destino_id' => $this->clientId,
            'estado' => 'CONFIRMADO',
        ]);
        $this->assertDatabaseCount('movimiento_detalles', 2);
        $this->assertDatabaseCount('comprobantes', 1);

        $overview = $this->getJson('/api/v1/recepcion-pollo-vivo')
            ->assertOk()
            ->assertJsonCount(2, 'data.records')
            ->assertJsonPath('data.records.0.record_kind', 'dispatch_ticket')
            ->assertJsonPath('data.records.1.record_kind', 'dispatch_ticket')
            ->assertJsonPath('data.totals.daily.weighings', 2)
            ->assertJsonPath('data.totals.daily.cages', 3)
            ->assertJsonPath('data.totals.daily.birds', 21)
            ->assertJsonPath('data.totals.own.birds', 21)
            ->assertJsonPath('data.totals.external.birds', 0)
            ->assertJsonPath('data.totals.lanes.1.birds', 14)
            ->assertJsonPath('data.totals.lanes.2.birds', 7)
            ->assertJsonPath('data.totals.lanes.5.birds', 0);
        $this->assertEqualsCanonicalizing(
            [Pesada::SEX_MALE, Pesada::SEX_FEMALE],
            collect($overview->json('data.records'))->pluck('sex')->all(),
        );

        $this->postJson('/api/v1/recepcion-pollo-vivo/tickets', $payload)
            ->assertOk()
            ->assertJsonPath('already_registered', true);

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 2);
        $this->assertDatabaseCount('recepcion_pollo_vivo_tickets', 1);
        $this->assertDatabaseCount('movimientos_javas', 1);
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertDatabaseCount('movimiento_detalles', 2);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 3]);
    }

    public function test_overview_keeps_every_ticket_when_multiple_tickets_have_the_same_sex(): void
    {
        $firstPayload = $this->ticketPayload();
        $firstPayload['weighings'] = [$firstPayload['weighings'][0]];
        $secondPayload = $this->ticketPayload();
        $secondPayload['lane'] = 6;
        $secondPayload['weighings'] = [$secondPayload['weighings'][0]];

        $firstTicketId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $firstPayload,
        )->assertCreated()->json('ticket.id');
        $secondTicketId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $secondPayload,
        )->assertCreated()->json('ticket.id');

        $response = $this->getJson('/api/v1/recepcion-pollo-vivo')
            ->assertOk()
            ->assertJsonCount(2, 'data.records')
            ->assertJsonCount(2, 'data.dispatch_tickets');

        $this->assertEqualsCanonicalizing(
            [$firstTicketId, $secondTicketId],
            collect($response->json('data.records'))->pluck('ticket_id')->all(),
        );
        $this->assertSame(
            [Pesada::SEX_MALE],
            collect($response->json('data.records'))->pluck('sex')->unique()->values()->all(),
        );
    }

    public function test_ticket_draft_from_a_previous_operating_day_is_rejected_atomically(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'] = collect($payload['weighings'])
            ->map(fn (array $weighing): array => [
                ...$weighing,
                'weighed_at' => now('America/Lima')->subDay()->subMinute()->toIso8601String(),
            ])
            ->all();

        $this->postJson('/api/v1/recepcion-pollo-vivo/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pesadas', 0);
        $this->assertDatabaseCount('recepcion_pollo_vivo_tickets', 0);
        $this->assertDatabaseCount('movimientos_javas', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_batch_correction_is_rejected_when_the_ticket_has_an_applied_collection(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET:{$ticket['id']}")
            ->value('id');
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'COBRO-RECEPCION-001',
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $this->clientId,
            'direccion' => 'ENTRADA',
            'fecha_hora' => now(),
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '10.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => '10.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        $this->getJson("/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}")
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'El ticket tiene cobros o pagos aplicados; anula primero esos movimientos financieros para corregirlo.',
            );
        $payload = $this->ticketUpdatePayload($ticket);
        $payload['weighings'][0]['read_weight_kg'] = 110;

        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $payload,
        )->assertStatus(409)
            ->assertJsonPath(
                'message',
                'No se puede corregir el ticket porque ya tiene cobros o pagos aplicados. Anula primero los movimientos financieros relacionados.',
            );

        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticket['id'],
            'revision' => $ticket['link_revision'],
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $ticket['weighings'][0]['id'],
            'peso_leido_kg' => $ticket['weighings'][0]['read_weight_kg'],
        ]);
    }

    public function test_generic_weighing_management_cannot_mutate_or_remove_reception_ticket_rows(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        $weighingId = (int) $ticket['weighings'][0]['id'];

        $this->getJson('/api/v1/operacion/gestion-pesadas?search='.urlencode($ticket['code']))
            ->assertOk()
            ->assertJsonPath('data.tickets.0.source_module', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION)
            ->assertJsonPath('data.tickets.0.editable', false)
            ->assertJsonPath('data.tickets.0.delivery_editable', true)
            ->assertJsonPath(
                'data.tickets.0.edit_restriction',
                'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.',
            );
        $this->getJson("/api/v1/operacion/tickets/{$ticket['id']}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath('data.ticket.delivery_editable', true)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.',
            );
        $this->getJson('/api/v1/finanzas/tickets?ticket='.urlencode($ticket['code']))
            ->assertOk()
            ->assertJsonPath('data.0.source_module', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION)
            ->assertJsonPath('data.0.can_edit_weighings', false)
            ->assertJsonPath(
                'data.0.weighing_edit_restriction',
                'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.',
            );
        $this->getJson("/api/v1/finanzas/tickets/{$ticket['id']}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'Las pesadas de este ticket se editan completas desde Recepción de pollo vivo.',
            );
        $this->getJson('/api/v1/operacion/tickets-dia?date='.$ticket['operating_date'])
            ->assertOk()
            ->assertJsonPath('data.tickets.0.source_module', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION);
        $this->getJson("/api/v1/clientes/{$this->clientId}/historial")
            ->assertOk()
            ->assertJsonPath('data.tickets.0.source_module', TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION);

        $this->putJson(
            "/api/v1/operacion/tickets/{$ticket['id']}/pesadas/{$weighingId}",
            [],
        )->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Las pesadas de este ticket se corrigen completas desde Recepción de pollo vivo.',
            );
        $this->deleteJson(
            "/api/v1/operacion/tickets/{$ticket['id']}/pesadas/{$weighingId}",
            ['reason' => 'No debe permitirse'],
        )->assertStatus(409);

        $this->assertDatabaseHas('pesadas', [
            'id' => $weighingId,
            'estado' => Pesada::STATUS_ACTIVE,
            'tipo_pollo_id' => DB::table('tipos_pollo')->where('codigo', TipoPollo::CHICKEN_LIVE)->value('id'),
        ]);
    }

    public function test_registered_reception_ticket_rejects_owner_and_lane_reassignment(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        $payload = $this->ticketUpdatePayload($ticket);
        $payload['owner_type'] = PesadaRecepcionPolloVivo::OWNER_EXTERNAL;
        $payload['external_owner_id'] = $this->clientId;
        $payload['lane'] = 3;
        $payload['warehouse_id'] = 1;
        $payload['destination_id'] = $this->clientId;
        $payload['dispatch_client_id'] = $this->clientId;

        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $payload,
        )->assertUnprocessable()
            ->assertJsonValidationErrors([
                'owner_type',
                'external_owner_id',
                'lane',
                'warehouse_id',
                'destination_id',
                'dispatch_client_id',
            ]);

        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticket['id'],
            'revision' => $ticket['link_revision'],
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $ticket['weighings'][0]['id'],
            'cantidad_javas' => $ticket['weighings'][0]['cages'],
        ]);
        $this->getJson('/api/v1/recepcion-pollo-vivo')
            ->assertOk()
            ->assertJsonPath('data.records.0.owner.type', PesadaRecepcionPolloVivo::OWNER_OWN);
    }

    public function test_batch_correction_preserves_existing_scale_provenance_when_weight_is_unchanged(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][0]['weight_source'] = Balanza::CODE_LIVE_CHICKEN_RECEPTION;
        $payload['weighings'][0]['scale_reading'] = [
            'raw_frame' => 'ST,GS 100.000 kg',
            'connection_mode' => 'SERIAL',
            'device_name' => 'Balanza recepción',
            'captured_at' => now('America/Lima')->subMinute()->toIso8601String(),
        ];
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $payload,
        )->assertCreated();
        $ticket = $created->json('ticket');
        $scaleWeighingId = (int) $ticket['weighings'][0]['id'];
        $manualWeighingId = (int) $ticket['weighings'][1]['id'];
        $readingId = (int) DB::table('pesadas')
            ->where('id', $scaleWeighingId)
            ->value('lectura_balanza_id');
        $update = $this->ticketUpdatePayload($ticket);
        unset($update['weighings'][0]['weight_source']);
        $update['weighings'][0]['sex'] = Pesada::SEX_FEMALE;
        $update['weighings'][0]['birds_per_cage'] = 8;

        $preserved = $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $update,
        )->assertOk();

        $this->assertDatabaseHas('pesadas', [
            'id' => $scaleWeighingId,
            'lectura_balanza_id' => $readingId,
            'origen_peso' => 'BALANZA',
            'sexo' => Pesada::SEX_FEMALE,
            'aves_por_java' => 8,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $manualWeighingId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
        ]);
        $this->assertDatabaseCount('lecturas_balanza', 1);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $readingId,
            'trama_cruda' => 'ST,GS 100.000 kg',
        ]);

        $manualUpdate = $this->ticketUpdatePayload($preserved->json('ticket'));
        $manualUpdate['weighings'][0]['read_weight_kg'] = 105;
        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $manualUpdate,
        )->assertOk();
        $this->assertDatabaseHas('pesadas', [
            'id' => $scaleWeighingId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
        ]);
        $this->assertDatabaseCount('lecturas_balanza', 1);
    }

    public function test_batch_correction_can_keep_an_inactive_cage_type_and_its_snapshot(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        DB::table('tipos_java')->where('id', $this->cageTypeId)->update([
            'estado' => 'INACTIVO',
            'peso_kg' => 99,
        ]);

        $loaded = $this->getJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
        )->assertOk();
        $inactiveType = collect($loaded->json('data.catalog.cage_types'))
            ->firstWhere('id', $this->cageTypeId);
        $this->assertNotNull($inactiveType);
        $this->assertFalse($inactiveType['active']);
        $update = $this->ticketUpdatePayload($loaded->json('data.ticket'));
        $update['weighings'][0]['read_weight_kg'] = 110;

        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $update,
        )->assertOk();

        $this->assertDatabaseHas('pesadas', [
            'id' => $ticket['weighings'][0]['id'],
            'tipo_java_id' => $this->cageTypeId,
            'peso_java_kg_snapshot' => 7,
            'tara_total_kg' => 14,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $ticket['weighings'][1]['id'],
            'tipo_java_id' => $this->cageTypeId,
            'peso_java_kg_snapshot' => 7,
            'tara_total_kg' => 7,
            'peso_neto_kg' => 43,
        ]);
    }

    public function test_voiding_a_reception_ticket_reverses_its_reception_inventory_once(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticketId = (int) $created->json('ticket.id');

        DB::table('inventarios_javas')
            ->where('empresa_id', $this->user->empresa_id)
            ->update([
                'cantidad_total' => -4,
                'cantidad_total_bandejas' => -6,
            ]);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Ticket de recepción duplicado',
        ])->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED);

        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticketId,
            'cantidad_javas_aplicada' => 0,
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('inventarios_javas', [
            'cantidad_total' => -7,
            'cantidad_total_bandejas' => -6,
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'ticket_id' => $ticketId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseMissing('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
        ]);
        $this->getJson('/api/v1/recepcion-pollo-vivo')
            ->assertOk()
            ->assertJsonCount(0, 'data.records')
            ->assertJsonPath('data.totals.daily.cages', 0);
    }

    public function test_voiding_is_atomic_when_the_client_already_returned_reception_ticket_cages(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticketId = (int) $created->json('ticket.id');
        $journeyId = (int) DB::table('tickets_despacho')
            ->where('id', $ticketId)
            ->value('jornada_id');
        DB::table('movimientos_javas')->insert([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $journeyId,
            'cliente_id' => $this->clientId,
            'tipo' => 'RECEPCION',
            'cantidad' => 3,
            'cantidad_bandejas' => 0,
            'fecha_movimiento' => now(),
            'observaciones' => 'Devolución posterior de prueba',
            'created_by' => $this->user->id,
        ]);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Intento posterior a una devolución',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'estado' => TicketDespacho::STATUS_CLOSED,
        ]);
        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticketId,
            'cantidad_javas_aplicada' => 3,
            'revision' => 1,
        ]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 3]);
        $this->assertDatabaseCount('movimientos_javas', 2);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'estado' => Pesada::STATUS_ACTIVE,
        ]);
    }

    public function test_historical_ticket_is_visible_but_cannot_be_updated_from_reception(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        DB::table('jornadas_operativas')
            ->where('id', DB::table('tickets_despacho')->where('id', $ticket['id'])->value('jornada_id'))
            ->update(['estado' => 'CERRADA']);

        $this->getJson("/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}")
            ->assertOk()
            ->assertJsonPath('data.ticket.id', $ticket['id'])
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'La jornada operativa del ticket está cerrada; queda disponible solo para consulta.',
            );

        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $this->ticketUpdatePayload($ticket),
        )->assertUnprocessable()
            ->assertJsonValidationErrors('ticket');

        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticket['id'],
            'revision' => $ticket['link_revision'],
        ]);
    }

    public function test_ticket_read_and_update_are_scoped_to_the_authenticated_company_and_branch(): void
    {
        $created = $this->postJson(
            '/api/v1/recepcion-pollo-vivo/tickets',
            $this->ticketPayload(),
        )->assertCreated();
        $ticket = $created->json('ticket');
        $otherUser = User::factory()->create();
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'OTRA',
            'nombre' => 'Sucursal ajena',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherUser->update(['sucursal_id' => $otherBranchId]);
        $this->grantModules($otherUser, ['MODULO_RECEPCION_POLLO_VIVO']);
        Sanctum::actingAs($otherUser, ['api']);

        $this->getJson("/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}")
            ->assertNotFound();
        $this->putJson(
            "/api/v1/recepcion-pollo-vivo/tickets/{$ticket['id']}",
            $this->ticketUpdatePayload($ticket),
        )->assertNotFound();

        $this->assertDatabaseHas('recepcion_pollo_vivo_tickets', [
            'ticket_despacho_id' => $ticket['id'],
            'revision' => $ticket['link_revision'],
        ]);
    }

    /** @return array<string, mixed> */
    private function ticketPayload(): array
    {
        $weighedAt = now('America/Lima')->subMinute()->toIso8601String();

        return [
            'layout_version' => 4,
            'draft_id' => (string) Str::uuid(),
            'lane' => 5,
            'dispatch_client_id' => $this->clientId,
            'delivery_vehicle_id' => $this->vehicleId,
            'delivery_driver_id' => $this->driverId,
            'weighings' => [
                [
                    'idempotency_key' => (string) Str::uuid(),
                    'sex' => Pesada::SEX_MALE,
                    'cage_type_id' => $this->cageTypeId,
                    'birds_per_cage' => 7,
                    'cage_count' => 2,
                    'weight_source' => 'MANUAL',
                    'read_weight_kg' => 100,
                    'weighed_at' => $weighedAt,
                ],
                [
                    'idempotency_key' => (string) Str::uuid(),
                    'sex' => Pesada::SEX_FEMALE,
                    'cage_type_id' => $this->cageTypeId,
                    'birds_per_cage' => 7,
                    'cage_count' => 1,
                    'weight_source' => 'MANUAL',
                    'read_weight_kg' => 50,
                    'weighed_at' => $weighedAt,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $ticket @return array<string, mixed> */
    private function ticketUpdatePayload(array $ticket): array
    {
        return [
            'layout_version' => 4,
            'expected_revision' => $ticket['link_revision'],
            'correction_reason' => 'Corrección completa de prueba',
            'weighings' => collect($ticket['weighings'])
                ->map(fn (array $weighing): array => [
                    'id' => $weighing['id'],
                    'sex' => $weighing['sex'],
                    'cage_type_id' => $weighing['cage_type_id'],
                    'birds_per_cage' => $weighing['birds_per_cage'],
                    'cage_count' => $weighing['cage_count'],
                    'weight_source' => 'MANUAL',
                    'read_weight_kg' => $weighing['read_weight_kg'],
                    'weighed_at' => $weighing['weighed_at'],
                ])
                ->all(),
        ];
    }
}
