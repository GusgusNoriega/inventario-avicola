<?php

namespace Tests\Feature;

use App\Models\AjustePesoMayoristaDos;
use App\Models\Permission;
use App\Models\Pesada;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\FinancialMovementService;
use App\Services\FinancialObligationService;
use App\Services\WholesaleTwoWeightAdjustmentService;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketWeighingManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $ticketId;

    private int $weighingId;

    private int $clientId;

    private int $liveTypeId;

    private int $dressedTypeId;

    private int $processedTypeId;

    private int $smallCageTypeId;

    private int $deliveryVehicleId;

    private int $deliveryDriverId;

    private int $alternateDeliveryVehicleId;

    private int $alternateDeliveryDriverId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-27 20:59:00', 'America/Bogota'));
        config(['directory.public_access' => false]);
        $this->user = User::factory()->create();
        $permission = Permission::query()->where('codigo', 'PESADAS_GESTIONAR')->firstOrFail();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'OPERADOR_PESADAS',
            'nombre' => 'Operador de pesadas',
        ]);
        $role->permissions()->attach($permission);
        $this->user->roles()->attach($role);

        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);

        $this->liveTypeId = $this->createChickenType(TipoPollo::CHICKEN_LIVE, 'Pollo vivo');
        $this->dressedTypeId = $this->createChickenType(TipoPollo::CHICKEN_DRESSED, 'Pollo pelado');
        $this->processedTypeId = $this->createChickenType(TipoPollo::CHICKEN_PROCESSED, 'Pollo beneficiado');
        $largeCageTypeId = $this->createCageType('JAVA_700', 'Java 7 kg', 7);
        $this->smallCageTypeId = $this->createCageType('JAVA_500', 'Java 5 kg', 5);
        $this->clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900123456',
            'nombre_razon_social' => 'Distribuidora Central',
            'direccion' => 'Calle 1',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $this->clientId,
            'rol' => 'CLIENTE',
            'created_at' => now(),
        ]);
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-06-27',
            'estado' => 'ABIERTA',
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-06-27 06:00:00',
            'cierre_programado_at' => '2026-06-27 21:00:00',
        ]);
        $this->deliveryVehicleId = $this->createDeliveryVehicle('ENT-001');
        $this->alternateDeliveryVehicleId = $this->createDeliveryVehicle('ENT-002');
        $this->deliveryDriverId = $this->createDeliveryDriver('CHOFER PRINCIPAL', '10001');
        $this->alternateDeliveryDriverId = $this->createDeliveryDriver('CHOFER ALTERNO', '10002');
        $this->ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => 'T-20260627-001',
            'canal' => 'MAYORISTA',
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $this->clientId,
            'vehiculo_entrega_id' => $this->deliveryVehicleId,
            'conductor_entrega_id' => $this->deliveryDriverId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => '2026-06-27 10:00:00',
            'created_by' => $this->user->id,
            'created_at' => '2026-06-27 09:50:00',
            'updated_at' => '2026-06-27 10:00:00',
        ]);
        $this->weighingId = DB::table('pesadas')->insertGetId([
            'ticket_id' => $this->ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $this->liveTypeId,
            'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
            'sexo' => Pesada::SEX_MALE,
            'tipo_java_id' => $largeCageTypeId,
            'origen_peso' => 'BALANZA_1',
            'aves_por_java' => 10,
            'cantidad_javas' => 2,
            'cantidad_aves' => 20,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => 40,
            'peso_bruto_kg' => 40,
            'tara_total_kg' => 14,
            'peso_neto_kg' => 26,
            'pesada_at' => '2026-06-27 09:55:00',
            'estado' => Pesada::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'created_at' => '2026-06-27 09:55:00',
            'updated_at' => '2026-06-27 09:55:00',
        ]);

        Sanctum::actingAs($this->user, ['api']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_search_finds_ticket_by_code_or_client_and_show_returns_one_ticket(): void
    {
        $this->getJson('/api/v1/operacion/gestion-pesadas?search=20260627-001')
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', false)
            ->assertJsonPath('data.access.can_void_tickets', false)
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.id', $this->ticketId)
            ->assertJsonPath('data.tickets.0.editable', true)
            ->assertJsonPath('data.tickets.0.can_void', false)
            ->assertJsonPath('data.tickets.0.weighings_count', 1);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=Distribuidora')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.destination.name', 'Distribuidora Central');

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', false)
            ->assertJsonPath('data.access.can_void_tickets', false)
            ->assertJsonPath('data.ticket.code', 'T-20260627-001')
            ->assertJsonPath('data.ticket.editable', true)
            ->assertJsonPath('data.ticket.can_void', false)
            ->assertJsonPath('data.ticket.delivery.vehicle.id', $this->deliveryVehicleId)
            ->assertJsonPath('data.ticket.delivery.vehicle.plate', 'ENT-001')
            ->assertJsonPath('data.ticket.delivery.driver.id', $this->deliveryDriverId)
            ->assertJsonPath('data.ticket.delivery.driver.name', 'CHOFER PRINCIPAL')
            ->assertJsonPath('data.catalogs.delivery_trucks.1.id', $this->alternateDeliveryVehicleId)
            ->assertJsonCount(2, 'data.catalogs.delivery_drivers')
            ->assertJsonFragment([
                'id' => $this->alternateDeliveryDriverId,
                'name' => 'CHOFER ALTERNO',
                'document' => 'CC 10002',
            ])
            ->assertJsonCount(1, 'data.ticket.weighings')
            ->assertJsonPath('data.ticket.weighings.0.id', $this->weighingId)
            ->assertJsonPath('data.ticket.weighings.0.chicken_sex', Pesada::SEX_MALE)
            ->assertJsonPath('data.ticket.source_module', null)
            ->assertJsonPath('data.ticket.weighings.0.chicken_variant_code', null)
            ->assertJsonPath('data.ticket.summary.net_weight_kg', 26);
    }

    public function test_wholesale_two_exposes_its_source_and_null_safe_processed_variant(): void
    {
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update(['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]);
        DB::table('pesadas')
            ->where('id', $this->weighingId)
            ->update([
                'tipo_pollo_id' => $this->processedTypeId,
                'sexo' => null,
                'presentacion_pollo' => null,
            ]);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=20260627-001')
            ->assertOk()
            ->assertJsonPath('data.tickets.0.source_module', TicketDespacho::SOURCE_WHOLESALE_TWO);

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.source_module', TicketDespacho::SOURCE_WHOLESALE_TWO)
            ->assertJsonPath(
                'data.ticket.weighings.0.chicken_variant_code',
                WholesaleTwoChickenVariant::PROCESSED
            )
            ->assertJsonPath('data.ticket.weighings.0.chicken_sex', null)
            ->assertJsonPath('data.ticket.weighings.0.presentation', null)
            ->assertJsonPath('data.ticket.weighings.0.adjustment', null)
            ->assertJsonCount(7, 'data.catalogs.weight_adjustments')
            ->assertJsonFragment([
                'code' => WholesaleTwoChickenVariant::PROCESSED,
                'additional_grams' => 0,
                'configurable' => false,
            ]);
    }

    public function test_administrator_can_void_a_ticket_from_weighing_management(): void
    {
        $this->postJson("/api/v1/operacion/tickets/{$this->ticketId}/anular", [
            'motivo' => 'Intento sin permisos administrativos',
        ])->assertForbidden();

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $this->ticketId,
            'estado' => TicketDespacho::STATUS_CLOSED,
        ]);

        $administrator = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $this->user->roles()->attach($administrator);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=20260627-001')
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', true)
            ->assertJsonPath('data.access.can_void_tickets', true)
            ->assertJsonPath('data.tickets.0.can_void', true);

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', true)
            ->assertJsonPath('data.ticket.can_void', true);

        $this->postJson("/api/v1/operacion/tickets/{$this->ticketId}/anular", [
            'motivo' => 'Ticket duplicado en gestión de pesadas',
        ])->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $this->ticketId,
            'estado' => TicketDespacho::STATUS_VOIDED,
            'anulado_por' => $this->user->id,
            'motivo_anulacion' => 'Ticket duplicado en gestión de pesadas',
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'estado' => Pesada::STATUS_VOIDED,
            'anulada_por' => $this->user->id,
        ]);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=20260627-001')
            ->assertOk()
            ->assertJsonCount(0, 'data.tickets');
    }

    public function test_current_journey_ticket_delivery_can_be_updated_and_is_audited(): void
    {
        $this->putJson("/api/v1/operacion/tickets/{$this->ticketId}/transporte", [
            'vehicle_id' => $this->alternateDeliveryVehicleId,
            'driver_id' => $this->alternateDeliveryDriverId,
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.delivery.vehicle.id', $this->alternateDeliveryVehicleId)
            ->assertJsonPath('data.ticket.delivery.vehicle.plate', 'ENT-002')
            ->assertJsonPath('data.ticket.delivery.driver.id', $this->alternateDeliveryDriverId)
            ->assertJsonPath('data.ticket.delivery.driver.name', 'CHOFER ALTERNO');

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $this->ticketId,
            'vehiculo_entrega_id' => $this->alternateDeliveryVehicleId,
            'conductor_entrega_id' => $this->alternateDeliveryDriverId,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $this->ticketId,
            'accion' => 'ACTUALIZAR_TRANSPORTE',
            'usuario_id' => $this->user->id,
        ]);
    }

    public function test_ticket_delivery_rejects_fleet_from_another_company(): void
    {
        $otherUser = User::factory()->create();
        $otherVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'placa' => 'OTR-001',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDriverId = DB::table('conductores')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'nombre_completo' => 'CHOFER EXTERNO',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/operacion/tickets/{$this->ticketId}/transporte", [
            'vehicle_id' => $otherVehicleId,
            'driver_id' => $otherDriverId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id', 'driver_id']);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $this->ticketId,
            'vehiculo_entrega_id' => $this->deliveryVehicleId,
            'conductor_entrega_id' => $this->deliveryDriverId,
        ]);
    }

    public function test_update_recalculates_the_weighing_and_writes_an_audit_event(): void
    {
        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $this->updatePayload()
        )
            ->assertOk()
            ->assertJsonPath('data.ticket.weighings.0.birds', 24)
            ->assertJsonPath('data.ticket.weighings.0.chicken_sex', Pesada::SEX_FEMALE)
            ->assertJsonPath('data.ticket.weighings.0.tare_weight_kg', 10)
            ->assertJsonPath('data.ticket.weighings.0.net_weight_kg', 20)
            ->assertJsonPath('data.ticket.weighings.0.weighed_at', '2026-06-27T10:30:00-05:00');

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'tipo_pollo_id' => $this->dressedTypeId,
            'tipo_java_id' => $this->smallCageTypeId,
            'sexo' => Pesada::SEX_FEMALE,
            'cantidad_aves' => 24,
            'peso_neto_kg' => 20,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'pesadas',
            'entidad_id' => (string) $this->weighingId,
            'accion' => 'ACTUALIZAR',
            'usuario_id' => $this->user->id,
        ]);
    }

    public function test_wholesale_two_update_derives_sex_and_presentation_from_its_variant(): void
    {
        $adjustment = $this->wholesaleTwoAdjustment(
            WholesaleTwoChickenVariant::FEMALE_OPEN,
            250
        );
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update(['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]);
        DB::table('pesadas')
            ->where('id', $this->weighingId)
            ->update([
                'tipo_pollo_id' => $this->dressedTypeId,
                'sexo' => Pesada::SEX_MALE,
                'presentacion_pollo' => 'CERRADO',
            ]);
        $payload = $this->updatePayload();
        unset($payload['chicken_sex'], $payload['gross_weight_kg']);
        $payload['chicken_variant_code'] = WholesaleTwoChickenVariant::FEMALE_OPEN;
        $payload['read_weight_kg'] = 30;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertOk()
            ->assertJsonPath(
                'data.ticket.weighings.0.chicken_variant_code',
                WholesaleTwoChickenVariant::FEMALE_OPEN
            )
            ->assertJsonPath('data.ticket.weighings.0.chicken_sex', Pesada::SEX_FEMALE)
            ->assertJsonPath('data.ticket.weighings.0.presentation', 'ABIERTA')
            ->assertJsonPath('data.ticket.weighings.0.read_weight_kg', 30)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.code', WholesaleTwoChickenVariant::FEMALE_OPEN)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.additional_grams', 250)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.total_grams', 6000)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.total_weight_kg', 6)
            ->assertJsonPath('data.ticket.weighings.0.gross_weight_kg', 36)
            ->assertJsonPath('data.ticket.weighings.0.net_weight_kg', 26)
            ->assertJsonPath('data.ticket.summary.read_weight_kg', 30)
            ->assertJsonPath('data.ticket.summary.adjustment_weight_kg', 6);

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'tipo_pollo_id' => $this->dressedTypeId,
            'sexo' => Pesada::SEX_FEMALE,
            'presentacion_pollo' => 'ABIERTA',
            'ajuste_peso_mayorista_2_id' => $adjustment->id,
            'ajuste_peso_mayorista_2_gramos' => 250,
            'peso_leido_kg' => 30,
            'peso_bruto_kg' => 36,
            'peso_neto_kg' => 26,
        ]);

        $audit = DB::table('auditoria_eventos')
            ->where('entidad', 'pesadas')
            ->where('entidad_id', (string) $this->weighingId)
            ->where('accion', 'ACTUALIZAR')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('CERRADO', json_decode($audit->datos_antes, true)['presentacion_pollo']);
        $this->assertSame('ABIERTA', json_decode($audit->datos_despues, true)['presentacion_pollo']);
        $this->assertSame(250, json_decode($audit->datos_despues, true)['ajuste_peso_mayorista_2_gramos']);
        $this->assertSame(30.0, (float) json_decode($audit->datos_despues, true)['peso_leido_kg']);
    }

    public function test_wholesale_two_update_preserves_the_weighing_snapshot_for_the_same_variant(): void
    {
        $adjustment = $this->wholesaleTwoAdjustment(WholesaleTwoChickenVariant::MALE, 100);
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update(['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]);
        DB::table('pesadas')
            ->where('id', $this->weighingId)
            ->update([
                'ajuste_peso_mayorista_2_id' => $adjustment->id,
                'ajuste_peso_mayorista_2_gramos' => 100,
                'peso_leido_kg' => 40,
                'peso_bruto_kg' => 42,
                'peso_neto_kg' => 28,
            ]);
        $adjustment->update(['gramos_adicionales' => 400]);

        $payload = $this->updatePayload();
        unset($payload['chicken_sex'], $payload['gross_weight_kg']);
        $payload['chicken_type_code'] = TipoPollo::CHICKEN_LIVE;
        $payload['chicken_variant_code'] = WholesaleTwoChickenVariant::MALE;
        $payload['read_weight_kg'] = 30;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.ticket.weighings.0.adjustment.code', WholesaleTwoChickenVariant::MALE)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.additional_grams', 100)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.total_grams', 2400)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.total_weight_kg', 2.4)
            ->assertJsonPath('data.ticket.weighings.0.read_weight_kg', 30)
            ->assertJsonPath('data.ticket.weighings.0.gross_weight_kg', 32.4)
            ->assertJsonPath('data.ticket.weighings.0.tare_weight_kg', 10)
            ->assertJsonPath('data.ticket.weighings.0.net_weight_kg', 22.4);

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'ajuste_peso_mayorista_2_id' => $adjustment->id,
            'ajuste_peso_mayorista_2_gramos' => 100,
            'peso_leido_kg' => 30,
            'peso_bruto_kg' => 32.4,
            'peso_neto_kg' => 22.4,
        ]);
        $this->assertDatabaseHas('ajustes_peso_mayorista_2', [
            'id' => $adjustment->id,
            'gramos_adicionales' => 400,
        ]);

        $audit = DB::table('auditoria_eventos')
            ->where('entidad', 'pesadas')
            ->where('entidad_id', (string) $this->weighingId)
            ->where('accion', 'ACTUALIZAR')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(100, json_decode($audit->datos_antes, true)['ajuste_peso_mayorista_2_gramos']);
        $this->assertSame(100, json_decode($audit->datos_despues, true)['ajuste_peso_mayorista_2_gramos']);
    }

    public function test_wholesale_two_processed_variant_clears_sex_and_presentation(): void
    {
        $adjustment = $this->wholesaleTwoAdjustment(WholesaleTwoChickenVariant::PROCESSED, 0);
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update(['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]);
        DB::table('pesadas')
            ->where('id', $this->weighingId)
            ->update([
                'tipo_pollo_id' => $this->dressedTypeId,
                'sexo' => Pesada::SEX_MALE,
                'presentacion_pollo' => 'ABIERTO',
            ]);
        $payload = $this->updatePayload();
        unset($payload['chicken_sex'], $payload['gross_weight_kg']);
        $payload['chicken_type_code'] = TipoPollo::CHICKEN_PROCESSED;
        $payload['chicken_variant_code'] = WholesaleTwoChickenVariant::PROCESSED;
        $payload['read_weight_kg'] = 30;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertOk()
            ->assertJsonPath(
                'data.ticket.weighings.0.chicken_variant_code',
                WholesaleTwoChickenVariant::PROCESSED
            )
            ->assertJsonPath('data.ticket.weighings.0.chicken_sex', null)
            ->assertJsonPath('data.ticket.weighings.0.presentation', null)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.code', WholesaleTwoChickenVariant::PROCESSED)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.additional_grams', 0)
            ->assertJsonPath('data.ticket.weighings.0.adjustment.total_weight_kg', 0)
            ->assertJsonPath('data.ticket.weighings.0.gross_weight_kg', 30)
            ->assertJsonPath('data.ticket.weighings.0.net_weight_kg', 20);

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'tipo_pollo_id' => $this->processedTypeId,
            'sexo' => null,
            'presentacion_pollo' => null,
            'ajuste_peso_mayorista_2_id' => $adjustment->id,
            'ajuste_peso_mayorista_2_gramos' => 0,
            'peso_leido_kg' => 30,
            'peso_bruto_kg' => 30,
            'peso_neto_kg' => 20,
        ]);
    }

    public function test_wholesale_two_rejects_a_variant_for_another_chicken_type(): void
    {
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update(['modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO]);
        $payload = $this->updatePayload();
        unset($payload['chicken_sex'], $payload['gross_weight_kg']);
        $payload['chicken_variant_code'] = WholesaleTwoChickenVariant::PROCESSED;
        $payload['read_weight_kg'] = 30;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chicken_variant_code');

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'tipo_pollo_id' => $this->liveTypeId,
            'sexo' => Pesada::SEX_MALE,
            'presentacion_pollo' => null,
        ]);
    }

    public function test_show_lists_only_origin_trucks_from_the_ticket_journey(): void
    {
        $first = $this->createJourneyOriginTruck(
            'Proveedor jornada uno',
            '20900000001',
            'JOR-001'
        );
        $second = $this->createJourneyOriginTruck(
            'Proveedor jornada dos',
            '20900000002',
            'JOR-002'
        );
        $outside = $this->createJourneyOriginTruck(
            'Proveedor otra jornada',
            '20900000003',
            'JOR-003',
            '2026-06-26'
        );

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonCount(2, 'data.catalogs.origin_trucks')
            ->assertJsonPath('data.catalogs.origin_trucks.0.program_detail_id', $first['program_detail_id'])
            ->assertJsonPath('data.catalogs.origin_trucks.0.provider_name', 'Proveedor jornada uno')
            ->assertJsonPath('data.catalogs.origin_trucks.0.plate', 'JOR-001')
            ->assertJsonPath('data.catalogs.origin_trucks.1.program_detail_id', $second['program_detail_id'])
            ->assertJsonMissing(['program_detail_id' => $outside['program_detail_id']]);
    }

    public function test_update_can_change_origin_to_a_truck_from_the_ticket_journey(): void
    {
        $origin = $this->createJourneyOriginTruck(
            'Proveedor nuevo origen',
            '20900000004',
            'JOR-004'
        );
        $payload = $this->updatePayload();
        $payload['origin_program_detail_id'] = $origin['program_detail_id'];

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.ticket.weighings.0.origin', 'Proveedor nuevo origen')
            ->assertJsonPath('data.ticket.weighings.0.plate', 'JOR-004')
            ->assertJsonPath(
                'data.ticket.weighings.0.origin_program_detail_id',
                $origin['program_detail_id']
            );

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'proveedor_origen_id' => $origin['provider_id'],
            'almacen_origen_id' => null,
            'vehiculo_id' => $origin['vehicle_id'],
            'programacion_recepcion_detalle_id' => $origin['program_detail_id'],
            'placa_snapshot' => 'JOR-004',
        ]);
    }

    public function test_update_rejects_origin_trucks_outside_the_ticket_journey(): void
    {
        $outside = $this->createJourneyOriginTruck(
            'Proveedor fecha diferente',
            '20900000005',
            'JOR-005',
            '2026-06-26'
        );
        $cancelled = $this->createJourneyOriginTruck(
            'Proveedor cancelado',
            '20900000006',
            'JOR-006',
            '2026-06-27',
            'CANCELADA'
        );

        foreach ([$outside, $cancelled] as $origin) {
            $payload = $this->updatePayload();
            $payload['origin_program_detail_id'] = $origin['program_detail_id'];

            $this->putJson(
                "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
                $payload
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('origin_program_detail_id');
        }

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'proveedor_origen_id' => null,
            'vehiculo_id' => null,
            'programacion_recepcion_detalle_id' => null,
            'placa_snapshot' => null,
        ]);
    }

    public function test_update_resynchronizes_the_unpaid_sale_without_creating_purchase_obligations(): void
    {
        $saleDocumentId = $this->prepareFinancialReceivable();
        $this->assertDatabaseHas('comprobantes', [
            'id' => $saleDocumentId,
            'total' => 260,
            'saldo_pendiente' => 260,
        ]);
        $this->assertDatabaseMissing('comprobantes', ['operacion' => 'COMPRA']);

        $payload = $this->updatePayload();
        $payload['chicken_type_code'] = TipoPollo::CHICKEN_LIVE;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.ticket.weighings.0.net_weight_kg', 20);

        $this->assertDatabaseHas('comprobantes', [
            'id' => $saleDocumentId,
            'total' => 200,
            'saldo_pendiente' => 200,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseMissing('comprobantes', ['operacion' => 'COMPRA']);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseCount('comprobante_pesadas', 0);
    }

    public function test_update_is_blocked_after_a_financial_movement_is_applied(): void
    {
        $saleDocumentId = $this->prepareFinancialReceivable();
        $account = $this->createOwnFinancialAccount();
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        app(FinancialMovementService::class)->register(
            (int) $this->user->empresa_id,
            $this->user,
            [
                'idempotency_key' => (string) Str::uuid(),
                'tipo' => 'COBRO_CLIENTE',
                'cliente_id' => $this->clientId,
                'cuenta_destino_id' => $account,
                'metodo_pago_id' => $method,
                'moneda' => 'PEN',
                'importe' => '20.00',
                'aplicaciones' => [[
                    'lado' => 'CXC',
                    'comprobante_id' => $saleDocumentId,
                    'importe_aplicado' => '20.00',
                ]],
            ],
        );

        $payload = $this->updatePayload();
        $payload['chicken_type_code'] = TipoPollo::CHICKEN_LIVE;

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload,
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'No se puede modificar la pesada porque el ticket ya tiene cobros o pagos aplicados. Anula primero los movimientos financieros relacionados.'
            );

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'peso_neto_kg' => 26,
            'estado' => Pesada::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $saleDocumentId,
            'total' => 260,
            'saldo_pendiente' => 240,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'pesadas',
            'entidad_id' => (string) $this->weighingId,
            'accion' => 'ACTUALIZAR',
        ]);
    }

    public function test_update_rejects_an_invalid_chicken_sex(): void
    {
        $payload = $this->updatePayload();
        $payload['chicken_sex'] = 'OTRO';

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $payload
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chicken_sex');

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'sexo' => Pesada::SEX_MALE,
        ]);
    }

    public function test_delete_annuls_the_weighing_and_removes_it_from_active_results(): void
    {
        $this->deleteJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}", [
            'reason' => 'Registro duplicado',
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.summary.weighings', 0)
            ->assertJsonCount(0, 'data.ticket.weighings');

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'estado' => Pesada::STATUS_VOIDED,
            'anulada_por' => $this->user->id,
            'motivo_anulacion' => 'Registro duplicado',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'pesadas',
            'entidad_id' => (string) $this->weighingId,
            'accion' => 'ANULAR',
        ]);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=T-20260627-001')
            ->assertOk()
            ->assertJsonCount(0, 'data.tickets');
    }

    public function test_previous_journey_is_read_only_in_show_update_and_delete(): void
    {
        DB::table('jornadas_operativas')
            ->where('id', DB::table('tickets_despacho')->where('id', $this->ticketId)->value('jornada_id'))
            ->update(['fecha_operativa' => '2026-06-26']);

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'Este ticket pertenece a una jornada anterior y solo puede consultarse en esta vista.'
            );

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $this->updatePayload()
        )
            ->assertStatus(409)
            ->assertJsonPath('message', 'Solo se pueden modificar pesadas de la jornada operativa actual.');

        $this->putJson("/api/v1/operacion/tickets/{$this->ticketId}/transporte", [
            'vehicle_id' => $this->alternateDeliveryVehicleId,
            'driver_id' => $this->alternateDeliveryDriverId,
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Solo se puede modificar el transporte de tickets de la jornada operativa actual.'
            );

        $this->deleteJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}", [
            'reason' => 'Intento sobre jornada anterior',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Solo se pueden modificar pesadas de la jornada operativa actual.');

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'estado' => Pesada::STATUS_ACTIVE,
            'peso_neto_kg' => 26,
        ]);
        $this->assertDatabaseCount('auditoria_eventos', 0);
    }

    public function test_nine_pm_starts_the_next_operating_journey(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 21:00:00', 'America/Bogota'));

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=T-20260627-001')
            ->assertOk()
            ->assertJsonPath('data.current_operating_date', '2026-06-28')
            ->assertJsonPath('data.tickets.0.editable', false);

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $this->updatePayload()
        )->assertStatus(409);
    }

    public function test_deferred_retail_delivery_can_receive_fleet_without_unlocking_weighings(): void
    {
        $trayTypeId = (int) DB::table('tipos_bandeja')->orderBy('id')->value('id');
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update([
                'canal' => TicketDespacho::CHANNEL_RETAIL,
                'vehiculo_entrega_id' => null,
                'conductor_entrega_id' => null,
                'asignacion_transporte_posterior' => true,
            ]);
        DB::table('pesadas')
            ->where('id', $this->weighingId)
            ->update([
                'tipo_java_id' => null,
                'tipo_bandeja_id' => $trayTypeId,
                'aves_por_java' => null,
                'aves_por_bandeja' => 10,
                'cantidad_javas' => null,
                'cantidad_bandejas' => 2,
                'peso_java_kg_snapshot' => null,
                'peso_bandeja_kg_snapshot' => 2.5,
                'origen_peso' => 'BALANZA_MINORISTA',
            ]);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=T-20260627-001')
            ->assertOk()
            ->assertJsonPath('data.tickets.0.editable', false)
            ->assertJsonPath('data.tickets.0.delivery_editable', true)
            ->assertJsonPath('data.tickets.0.delivery_assignment_deferred', true)
            ->assertJsonPath(
                'data.tickets.0.delivery_mode',
                TicketDespacho::DELIVERY_MODE_PENDING_ASSIGNMENT
            );

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath('data.ticket.delivery_editable', true)
            ->assertJsonPath('data.ticket.delivery_assignment_deferred', true)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'Las pesadas minoristas son de solo consulta; el camión y el chofer se gestionan por separado.'
            )
            ->assertJsonPath(
                'data.ticket.delivery.mode',
                TicketDespacho::DELIVERY_MODE_PENDING_ASSIGNMENT
            )
            ->assertJsonPath('data.ticket.delivery.vehicle', null)
            ->assertJsonPath('data.ticket.delivery.driver', null);

        $this->putJson("/api/v1/operacion/tickets/{$this->ticketId}/transporte", [
            'vehicle_id' => $this->alternateDeliveryVehicleId,
            'driver_id' => $this->alternateDeliveryDriverId,
        ])
            ->assertOk()
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath('data.ticket.delivery_editable', true)
            ->assertJsonPath(
                'data.ticket.delivery.mode',
                TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK
            )
            ->assertJsonPath('data.ticket.delivery.vehicle.id', $this->alternateDeliveryVehicleId)
            ->assertJsonPath('data.ticket.delivery.driver.id', $this->alternateDeliveryDriverId);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $this->ticketId,
            'vehiculo_entrega_id' => $this->alternateDeliveryVehicleId,
            'conductor_entrega_id' => $this->alternateDeliveryDriverId,
            'asignacion_transporte_posterior' => true,
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $this->ticketId,
            'cantidad_bandejas' => 2,
            'vehiculo_id' => $this->alternateDeliveryVehicleId,
            'conductor_id' => $this->alternateDeliveryDriverId,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $this->ticketId,
            'accion' => 'ACTUALIZAR_TRANSPORTE',
        ]);

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $this->updatePayload()
        )->assertStatus(409);
        $this->deleteJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}", [
            'reason' => 'Las pesadas minoristas deben seguir bloqueadas',
        ])->assertStatus(409);
    }

    public function test_retail_tickets_can_be_searched_viewed_and_reprinted_but_not_modified(): void
    {
        DB::table('tickets_despacho')
            ->where('id', $this->ticketId)
            ->update([
                'canal' => TicketDespacho::CHANNEL_RETAIL,
                'cliente_destino_id' => null,
                'vehiculo_entrega_id' => null,
                'conductor_entrega_id' => null,
            ]);
        $priceListId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => null,
            'codigo' => 'GENERAL-MINORISTA',
            'nombre' => 'Precios minoristas',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priceHistoryId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $this->liveTypeId,
            'precio_kg' => 9.75,
            'vigente_desde' => now()->subHour(),
            'vigente_hasta' => null,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('ticket_precios')->insert([
            'ticket_id' => $this->ticketId,
            'tipo_pollo_id' => $this->liveTypeId,
            'precio_historial_id' => $priceHistoryId,
            'precio_kg' => 9.75,
            'origen_precio' => 'MANUAL',
            'congelado_por' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=T-20260627-001')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.channel', TicketDespacho::CHANNEL_RETAIL)
            ->assertJsonPath('data.tickets.0.editable', false)
            ->assertJsonPath('data.tickets.0.customer_type', 'EXTERNO_SIN_REGISTRO')
            ->assertJsonPath('data.tickets.0.client', null)
            ->assertJsonPath('data.tickets.0.destination.type', 'VENTA_EXTERNA')
            ->assertJsonPath('data.tickets.0.destination.name', 'Venta externa (sin cliente)');

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.channel', TicketDespacho::CHANNEL_RETAIL)
            ->assertJsonPath('data.ticket.editable', false)
            ->assertJsonPath(
                'data.ticket.edit_restriction',
                'Los tickets de despacho minorista solo pueden consultarse y reimprimirse en esta vista.'
            )
            ->assertJsonPath('data.ticket.prices.POLLO_VIVO.price_kg', 9.75)
            ->assertJsonPath('data.ticket.prices.POLLO_VIVO.source', 'MANUAL')
            ->assertJsonPath('data.ticket.weighings.0.price_kg', 9.75)
            ->assertJsonPath('data.ticket.weighings.0.price_origin', 'MANUAL')
            ->assertJsonPath('data.ticket.weighings.0.amount', 253.5)
            ->assertJsonPath('data.ticket.summary.amount', 253.5);

        $this->putJson("/api/v1/operacion/tickets/{$this->ticketId}/transporte", [
            'vehicle_id' => $this->alternateDeliveryVehicleId,
            'driver_id' => $this->alternateDeliveryDriverId,
        ])->assertStatus(409);

        $this->putJson(
            "/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}",
            $this->updatePayload()
        )->assertStatus(409);

        $this->deleteJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas/{$this->weighingId}", [
            'reason' => 'No debe anularse desde gestion mayorista',
        ])->assertStatus(409);

        $this->assertDatabaseHas('pesadas', [
            'id' => $this->weighingId,
            'estado' => Pesada::STATUS_ACTIVE,
            'peso_neto_kg' => 26,
        ]);
        $this->assertDatabaseCount('auditoria_eventos', 0);
    }

    private function prepareFinancialReceivable(): int
    {
        $provider = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20999999991',
            'nombre_razon_social' => 'Proveedor financiero de prueba',
            'direccion' => 'Calle financiera 1',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $provider,
            'rol' => 'PROVEEDOR',
            'created_at' => now(),
        ]);
        DB::table('pesadas')->where('id', $this->weighingId)->update([
            'proveedor_origen_id' => $provider,
        ]);

        $saleList = $this->createFinancialPriceList($this->clientId, 'VENTA');
        $saleHistory = $this->createFinancialPriceHistory($saleList, '10.0000');
        DB::table('ticket_precios')->insert([
            'ticket_id' => $this->ticketId,
            'tipo_pollo_id' => $this->liveTypeId,
            'precio_historial_id' => $saleHistory,
            'precio_kg' => 10,
            'origen_precio' => 'CLIENTE',
            'congelado_por' => $this->user->id,
            'created_at' => now(),
        ]);

        $result = app(FinancialObligationService::class)->syncTicket(
            (int) $this->user->empresa_id,
            TicketDespacho::query()->findOrFail($this->ticketId),
            $this->user,
        );

        $this->assertSame([], $result['purchase_document_ids']);
        $this->assertSame(0, $result['pending_purchase_costs']);

        return (int) $result['sale_document_id'];
    }

    private function createFinancialPriceList(int $thirdParty, string $operation): int
    {
        return DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $thirdParty,
            'codigo' => "FIN-{$operation}-{$thirdParty}",
            'nombre' => "Lista financiera {$operation}",
            'operacion' => $operation,
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFinancialPriceHistory(int $list, string $price): int
    {
        return DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $list,
            'tipo_pollo_id' => $this->liveTypeId,
            'precio_kg' => $price,
            'vigente_desde' => now()->subDay(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Precio para regresion financiera',
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
    }

    private function createOwnFinancialAccount(): int
    {
        $entity = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Caja de prueba de pesadas',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entity,
            'tipo' => 'CAJA',
            'alias' => 'Caja gestion pesadas',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createChickenType(string $code, string $name): int
    {
        return DB::table('tipos_pollo')->insertGetId([
            'codigo' => $code,
            'nombre' => $name,
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCageType(string $code, string $name, float $weight): int
    {
        return DB::table('tipos_java')->insertGetId([
            'codigo' => $code,
            'nombre' => $name,
            'peso_kg' => $weight,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDeliveryVehicle(string $plate): int
    {
        return DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => $plate,
            'marca' => 'Hino',
            'modelo' => '300',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDeliveryDriver(string $name, string $document): int
    {
        return DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre_completo' => $name,
            'tipo_documento' => 'CC',
            'numero_documento' => $document,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{provider_id: int, vehicle_id: int, program_detail_id: int} */
    private function createJourneyOriginTruck(
        string $providerName,
        string $document,
        string $plate,
        string $operatingDate = '2026-06-27',
        string $detailStatus = 'PENDIENTE'
    ): array {
        $providerId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $providerName,
            'direccion' => 'Origen de prueba',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $providerId,
            'rol' => 'PROVEEDOR',
            'created_at' => now(),
        ]);
        $vehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => $plate,
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerVehicleId = DB::table('proveedor_vehiculos')->insertGetId([
            'proveedor_id' => $providerId,
            'vehiculo_id' => $vehicleId,
            'vigente_desde' => '2026-06-01',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $programId = DB::table('programaciones_recepcion')
            ->where('sucursal_id', $this->branchId)
            ->whereDate('fecha_operativa', $operatingDate)
            ->value('id');

        if (! $programId) {
            $programId = DB::table('programaciones_recepcion')->insertGetId([
                'sucursal_id' => $this->branchId,
                'fecha_operativa' => $operatingDate,
                'estado' => 'PUBLICADA',
                'publicada_por' => $this->user->id,
                'publicada_at' => now(),
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $order = DB::table('programacion_recepcion_detalles')
            ->where('programacion_id', $programId)
            ->count() + 1;
        $programDetailId = DB::table('programacion_recepcion_detalles')->insertGetId([
            'programacion_id' => $programId,
            'proveedor_vehiculo_id' => $providerVehicleId,
            'numero_visita' => 1,
            'orden_llegada' => $order,
            'estado' => $detailStatus,
            'estado_actualizado_por' => $this->user->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'provider_id' => $providerId,
            'vehicle_id' => $vehicleId,
            'program_detail_id' => $programDetailId,
        ];
    }

    private function wholesaleTwoAdjustment(string $variantCode, int $grams): AjustePesoMayoristaDos
    {
        app(WholesaleTwoWeightAdjustmentService::class)->ensureDefaults(
            (int) $this->user->empresa_id
        );
        $adjustment = AjustePesoMayoristaDos::query()
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', $variantCode)
            ->firstOrFail();
        $adjustment->update(['gramos_adicionales' => $grams]);

        return $adjustment->refresh();
    }

    /** @return array<string, mixed> */
    private function updatePayload(): array
    {
        return [
            'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
            'chicken_condition' => Pesada::CHICKEN_CONDITION_LIVE,
            'chicken_sex' => Pesada::SEX_FEMALE,
            'cage_type_code' => 'JAVA_500',
            'weight_source' => 'MANUAL',
            'birds_per_cage' => 12,
            'cages' => 2,
            'gross_weight_kg' => 30,
            'weighed_at' => '2026-06-27T10:30',
        ];
    }
}
