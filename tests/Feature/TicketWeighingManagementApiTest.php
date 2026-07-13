<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Pesada;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketWeighingManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $ticketId;

    private int $weighingId;

    private int $liveTypeId;

    private int $dressedTypeId;

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
        $largeCageTypeId = $this->createCageType('JAVA_700', 'Java 7 kg', 7);
        $this->smallCageTypeId = $this->createCageType('JAVA_500', 'Java 5 kg', 5);
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900123456',
            'nombre_razon_social' => 'Distribuidora Central',
            'direccion' => 'Calle 1',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
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
            'cliente_destino_id' => $clientId,
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
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.id', $this->ticketId)
            ->assertJsonPath('data.tickets.0.editable', true)
            ->assertJsonPath('data.tickets.0.weighings_count', 1);

        $this->getJson('/api/v1/operacion/gestion-pesadas?search=Distribuidora')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.destination.name', 'Distribuidora Central');

        $this->getJson("/api/v1/operacion/tickets/{$this->ticketId}/pesadas")
            ->assertOk()
            ->assertJsonPath('data.ticket.code', 'T-20260627-001')
            ->assertJsonPath('data.ticket.editable', true)
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
            ->assertJsonPath('data.ticket.summary.net_weight_kg', 26);
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
