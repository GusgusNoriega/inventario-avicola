<?php

namespace Tests\Feature;

use App\Models\Balanza;
use App\Models\JornadaOperativa;
use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class LiveChickenReceptionApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $warehouseOneId;

    private int $warehouseTwoId;

    private int $clientThreeId;

    private int $clientFourId;

    private int $externalOwnerId;

    private int $cageTypeId;

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
        $this->grantModules($this->user, ['MODULO_RECEPCION_POLLO_VIVO']);
        Sanctum::actingAs($this->user, ['api']);

        DB::table('tipos_pollo')->insert([
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
        $this->warehouseOneId = $this->createWarehouse('ALMACEN_1', 'Almacén 1');
        $this->warehouseTwoId = $this->createWarehouse('ALMACEN_2', 'Almacén 2');
        $this->clientThreeId = $this->createParty('20100000001', 'Cliente externo 1', TerceroRole::CLIENT);
        $this->clientFourId = $this->createParty('20100000002', 'Cliente externo 2', TerceroRole::CLIENT);
        $this->externalOwnerId = $this->createParty('20900000001', 'Empresa del hermano', TerceroRole::PROVIDER);
    }

    public function test_catalog_exposes_four_effective_destinations_and_external_owner_options(): void
    {
        $this->getJson('/api/v1/recepcion-pollo-vivo')
            ->assertOk()
            ->assertJsonPath('data.catalog.scale.code', Balanza::CODE_LIVE_CHICKEN_RECEPTION)
            ->assertJsonPath('data.configuration.saved', false)
            ->assertJsonPath('data.configuration.lanes.1.destination_id', $this->warehouseOneId)
            ->assertJsonPath('data.configuration.lanes.2.destination_id', $this->warehouseTwoId)
            ->assertJsonPath('data.configuration.lanes.3.destination_id', $this->clientThreeId)
            ->assertJsonPath('data.configuration.lanes.4.destination_id', $this->clientFourId)
            ->assertJsonCount(2, 'data.catalog.warehouses')
            ->assertJsonCount(2, 'data.catalog.clients')
            ->assertJsonCount(1, 'data.catalog.external_owners')
            ->assertJsonPath('data.totals.daily.weighings', 0);
    }

    public function test_own_warehouse_weighing_updates_only_own_chicken_and_java_inventory(): void
    {
        $this->saveConfiguration();

        $response = $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $this->payload())
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.records.0.owner.type', PesadaRecepcionPolloVivo::OWNER_OWN)
            ->assertJsonPath('data.records.0.destination.type', PesadaRecepcionPolloVivo::DESTINATION_WAREHOUSE)
            ->assertJsonPath('data.records.0.birds', 14)
            ->assertJsonPath('data.records.0.net_weight_kg', 86)
            ->assertJsonPath('data.totals.own.cages', 2)
            ->assertJsonPath('data.totals.own.birds', 14);

        $weighingId = (int) $response->json('weighing_id');
        $this->assertDatabaseHas('existencias_almacen', [
            'almacen_id' => $this->warehouseOneId,
            'cantidad_aves' => 14,
            'peso_neto_kg' => 86,
        ]);
        $this->assertDatabaseHas('inventarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_total' => 2,
        ]);
        $this->assertDatabaseHas('movimiento_detalles', [
            'pesada_recepcion_pollo_vivo_id' => $weighingId,
            'cantidad_aves' => 14,
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo' => 'ENTRADA_RECEPCION',
            'almacen_destino_id' => $this->warehouseOneId,
            'estado' => 'CONFIRMADO',
        ]);
        $this->assertDatabaseCount('movimientos_javas', 0);
        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_external_owner_is_recorded_without_touching_own_inventory_or_javas(): void
    {
        $this->saveConfiguration();
        $payload = $this->payload([
            'lane' => 2,
            'owner_type' => PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
            'external_owner_id' => $this->externalOwnerId,
        ]);

        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.records.0.owner.type', PesadaRecepcionPolloVivo::OWNER_EXTERNAL)
            ->assertJsonPath('data.records.0.owner.id', $this->externalOwnerId)
            ->assertJsonPath('data.records.0.owner.name', 'Empresa del hermano')
            ->assertJsonPath('data.totals.daily.birds', 14)
            ->assertJsonPath('data.totals.own.birds', 0)
            ->assertJsonPath('data.totals.external.birds', 14);

        $this->assertDatabaseCount('existencias_almacen', 0);
        $this->assertDatabaseCount('inventarios_javas', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('movimiento_detalles', 0);
        $this->assertDatabaseCount('movimientos_javas', 0);
    }

    public function test_own_direct_lane_dispatches_to_client_without_entering_warehouse(): void
    {
        $this->saveConfiguration();

        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $this->payload(['lane' => 3]))
            ->assertCreated()
            ->assertJsonPath('data.records.0.destination.type', PesadaRecepcionPolloVivo::DESTINATION_CLIENT)
            ->assertJsonPath('data.records.0.destination.id', $this->clientThreeId)
            ->assertJsonPath('data.totals.lanes.3.birds', 14);

        $this->assertDatabaseCount('existencias_almacen', 0);
        $this->assertDatabaseHas('inventarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_total' => 2,
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo' => 'DESPACHO_DIRECTO',
            'tercero_destino_id' => $this->clientThreeId,
            'estado' => 'CONFIRMADO',
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'cliente_id' => $this->clientThreeId,
            'tipo' => 'DESPACHO',
            'cantidad' => 2,
            'ticket_despacho_id' => null,
        ]);
    }

    public function test_external_direct_lane_does_not_create_a_company_dispatch(): void
    {
        $this->saveConfiguration();

        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $this->payload([
            'lane' => 4,
            'owner_type' => PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
            'external_owner_id' => $this->externalOwnerId,
        ]))->assertCreated()
            ->assertJsonPath('data.records.0.destination.id', $this->clientFourId)
            ->assertJsonPath('data.totals.external.birds', 14);

        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('movimientos_javas', 0);
        $this->assertDatabaseCount('inventarios_javas', 0);
    }

    public function test_repeating_the_same_capture_is_idempotent(): void
    {
        $this->saveConfiguration();
        $payload = $this->payload();

        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $payload)->assertCreated();
        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $payload)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('data.totals.daily.weighings', 1);

        $this->assertDatabaseCount('pesadas_recepcion_pollo_vivo', 1);
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 2]);
    }

    public function test_physical_scale_capture_keeps_its_audit_reading(): void
    {
        $this->saveConfiguration();
        $payload = $this->payload([
            'weight_source' => Balanza::CODE_LIVE_CHICKEN_RECEPTION,
            'scale_reading' => [
                'raw_frame' => 'ST,GS 100.000 kg',
                'connection_mode' => 'serial',
                'device_name' => 'Puerto COM de prueba',
                'captured_at' => now('America/Lima')->subMinute()->toIso8601String(),
            ],
        ]);

        $this->postJson('/api/v1/recepcion-pollo-vivo/pesadas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.records.0.weight_source', Balanza::CODE_LIVE_CHICKEN_RECEPTION);

        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => Balanza::CODE_LIVE_CHICKEN_RECEPTION,
        ]);
        $this->assertDatabaseHas('lecturas_balanza', [
            'peso_kg' => 100,
            'trama_cruda' => 'ST,GS 100.000 kg',
            'modo_conexion' => 'SERIAL',
            'dispositivo' => 'Puerto COM de prueba',
        ]);
    }

    public function test_voiding_own_warehouse_weighing_reverses_stock_and_java_total(): void
    {
        $this->saveConfiguration();
        $weighingId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/pesadas',
            $this->payload(),
        )->assertCreated()->json('weighing_id');

        $this->deleteJson("/api/v1/recepcion-pollo-vivo/pesadas/{$weighingId}")
            ->assertOk()
            ->assertJsonPath('data.totals.daily.weighings', 0)
            ->assertJsonPath('data.totals.own.birds', 0);

        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $weighingId,
            'estado' => PesadaRecepcionPolloVivo::STATUS_VOIDED,
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('existencias_almacen', [
            'almacen_id' => $this->warehouseOneId,
            'cantidad_aves' => 0,
            'peso_neto_kg' => 0,
        ]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 0]);
        $this->assertDatabaseHas('movimientos_inventario', ['estado' => 'ANULADO']);
    }

    public function test_direct_weighing_cannot_be_voided_after_the_client_returned_its_cages(): void
    {
        $this->saveConfiguration();
        $weighingId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/pesadas',
            $this->payload(['lane' => 3]),
        )->assertCreated()->json('weighing_id');
        $weighing = PesadaRecepcionPolloVivo::query()
            ->with('recepcion:id,jornada_id')
            ->findOrFail($weighingId);

        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $weighing->recepcion->jornada_id,
            'cliente_id' => $this->clientThreeId,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
            'fecha_movimiento' => now(),
            'observaciones' => 'Devolución posterior de prueba.',
            'created_by' => $this->user->id,
        ]);

        $this->deleteJson("/api/v1/recepcion-pollo-vivo/pesadas/{$weighingId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighing');

        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $weighingId,
            'estado' => PesadaRecepcionPolloVivo::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 2]);
        $this->assertDatabaseCount('movimientos_javas', 2);
    }

    public function test_warehouse_weighing_cannot_reduce_inventory_below_cages_assigned_to_clients(): void
    {
        $this->saveConfiguration();
        $weighingId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/pesadas',
            $this->payload(),
        )->assertCreated()->json('weighing_id');
        $weighing = PesadaRecepcionPolloVivo::query()
            ->with('recepcion:id,jornada_id')
            ->findOrFail($weighingId);

        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $weighing->recepcion->jornada_id,
            'cliente_id' => $this->clientThreeId,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
            'fecha_movimiento' => now(),
            'observaciones' => 'Asignación posterior de prueba.',
            'created_by' => $this->user->id,
        ]);

        $this->deleteJson("/api/v1/recepcion-pollo-vivo/pesadas/{$weighingId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighing');

        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $weighingId,
            'estado' => PesadaRecepcionPolloVivo::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 2]);
    }

    public function test_weighing_from_a_closed_journey_cannot_be_voided(): void
    {
        $this->saveConfiguration();
        $weighingId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/pesadas',
            $this->payload(),
        )->assertCreated()->json('weighing_id');
        $weighing = PesadaRecepcionPolloVivo::query()
            ->with('recepcion:id,jornada_id')
            ->findOrFail($weighingId);
        JornadaOperativa::query()
            ->whereKey($weighing->recepcion->jornada_id)
            ->update(['estado' => JornadaOperativa::STATUS_CLOSED]);

        $this->deleteJson("/api/v1/recepcion-pollo-vivo/pesadas/{$weighingId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighing');

        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $weighingId,
            'estado' => PesadaRecepcionPolloVivo::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('inventarios_javas', ['cantidad_total' => 2]);
    }

    public function test_configuration_rejects_destinations_from_another_company(): void
    {
        $otherUser = User::factory()->create();
        $otherClient = Tercero::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20888888888',
            'nombre_razon_social' => 'Cliente de otra empresa',
            'direccion' => 'Otra dirección',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $otherClient->roles()->create(['rol' => TerceroRole::CLIENT]);

        $this->putJson('/api/v1/recepcion-pollo-vivo/configuracion', $this->configurationPayload([
            'lane_3_client_id' => $otherClient->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('lane_3_client_id');

        $this->assertDatabaseCount('configuraciones_recepcion_pollo_vivo', 0);
    }

    public function test_weighing_from_another_company_cannot_be_voided_or_discovered(): void
    {
        $otherUser = User::factory()->create();
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'OTRA',
            'nombre' => 'Sucursal de otra empresa',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherUser->update(['sucursal_id' => $otherBranchId]);
        $this->grantModules($otherUser, ['MODULO_RECEPCION_POLLO_VIVO']);
        DB::table('almacenes')->insert([
            'sucursal_id' => $otherBranchId,
            'codigo' => 'ALMACEN_OTRO',
            'nombre' => 'Almacén de otra empresa',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($otherUser, ['api']);
        $otherWeighingId = (int) $this->postJson(
            '/api/v1/recepcion-pollo-vivo/pesadas',
            $this->payload(),
        )->assertCreated()->json('weighing_id');

        Sanctum::actingAs($this->user, ['api']);
        $this->deleteJson("/api/v1/recepcion-pollo-vivo/pesadas/{$otherWeighingId}")
            ->assertNotFound();

        $this->assertDatabaseHas('pesadas_recepcion_pollo_vivo', [
            'id' => $otherWeighingId,
            'estado' => PesadaRecepcionPolloVivo::STATUS_ACTIVE,
        ]);
    }

    private function saveConfiguration(): void
    {
        $this->putJson(
            '/api/v1/recepcion-pollo-vivo/configuracion',
            $this->configurationPayload(),
        )->assertOk()
            ->assertJsonPath('data.configuration.saved', true)
            ->assertJsonPath('data.configuration.default_external_owner_id', $this->externalOwnerId);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'lane' => 1,
            'owner_type' => PesadaRecepcionPolloVivo::OWNER_OWN,
            'external_owner_id' => null,
            'sex' => Pesada::SEX_MALE,
            'cage_type_id' => $this->cageTypeId,
            'birds_per_cage' => 7,
            'cage_count' => 2,
            'weight_source' => 'MANUAL',
            'read_weight_kg' => 100,
            'weighed_at' => now('America/Lima')->subMinute()->toIso8601String(),
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function configurationPayload(array $overrides = []): array
    {
        return [
            'default_external_owner_id' => $this->externalOwnerId,
            'lane_1_warehouse_id' => $this->warehouseOneId,
            'lane_2_warehouse_id' => $this->warehouseTwoId,
            'lane_3_client_id' => $this->clientThreeId,
            'lane_4_client_id' => $this->clientFourId,
            ...$overrides,
        ];
    }

    private function createWarehouse(string $code, string $name): int
    {
        return DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => $code,
            'nombre' => $name,
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createParty(string $document, string $name, string $role): int
    {
        $party = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Dirección de prueba',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $party->roles()->create(['rol' => $role]);

        return (int) $party->id;
    }
}
