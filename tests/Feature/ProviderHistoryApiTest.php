<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $providerId;

    private int $customerId;

    private int $branchId;

    private int $warehouseId;

    private int $chickenTypeId;

    private int $cageTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permissions = collect([
            ['TERCEROS_GESTIONAR', 'Gestionar terceros'],
            ['PRECIOS_GESTIONAR', 'Gestionar precios'],
        ])->map(fn (array $permission) => Permission::query()->create([
            'codigo' => $permission[0],
            'descripcion' => $permission[1],
        ]));
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $role->permissions()->attach($permissions);
        $this->user->roles()->attach($role);

        collect([
            [TipoPollo::CHICKEN_LIVE, 'Pollo vivo'],
            [TipoPollo::CHICKEN_DRESSED, 'Pollo pelado'],
            [TipoPollo::CHICKEN_PROCESSED, 'Pollo beneficiado'],
        ])->each(fn (array $type) => DB::table('tipos_pollo')->insert([
            'codigo' => $type[0],
            'nombre' => $type[1],
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => 'ALMACEN_1',
            'nombre' => 'Almacén principal',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
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
        $this->chickenTypeId = (int) DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::CHICKEN_LIVE)
            ->value('id');

        Sanctum::actingAs($this->user, ['api']);

        $this->providerId = $this->postJson('/api/v1/proveedores', $this->partyPayload(
            'Proveedor Norte',
            '20111111111'
        ))->assertCreated()->json('data.id');
        $this->customerId = $this->postJson('/api/v1/clientes', $this->partyPayload(
            'Cliente Sur',
            '20222222222'
        ))->assertCreated()->json('data.id');
    }

    public function test_provider_history_returns_weighings_with_client_and_warehouse_destinations(): void
    {
        $this->createWeighing(
            'T-20260620-001',
            '2026-06-20',
            'ABC-123',
            customerId: $this->customerId,
            netWeight: 10
        );
        $this->createWeighing(
            'T-20260621-002',
            '2026-06-21',
            'XYZ-999',
            warehouseId: $this->warehouseId,
            netWeight: 20
        );

        $this->getJson("/api/v1/proveedores/{$this->providerId}/historial")
            ->assertOk()
            ->assertJsonPath('data.provider.name', 'PROVEEDOR NORTE')
            ->assertJsonPath('data.summary.records', 2)
            ->assertJsonPath('data.summary.tickets', 2)
            ->assertJsonPath('data.summary.net_weight_kg', 30)
            ->assertJsonPath('data.records.0.destination.type', 'ALMACEN')
            ->assertJsonPath('data.records.0.destination.name', 'Almacén principal')
            ->assertJsonPath('data.records.1.destination.type', 'CLIENTE')
            ->assertJsonPath('data.records.1.destination.name', 'CLIENTE SUR');
    }

    public function test_provider_history_filters_by_ticket_plate_and_date(): void
    {
        $this->createWeighing(
            'T-20260620-001',
            '2026-06-20',
            'ABC-123',
            customerId: $this->customerId,
            netWeight: 10
        );
        $this->createWeighing(
            'T-20260621-002',
            '2026-06-21',
            'XYZ-999',
            warehouseId: $this->warehouseId,
            netWeight: 20
        );

        $this->getJson("/api/v1/proveedores/{$this->providerId}/historial?placa=abc")
            ->assertOk()
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.records.0.plate', 'ABC-123');

        $this->getJson(
            "/api/v1/proveedores/{$this->providerId}/historial?ticket=002&fecha_desde=2026-06-21&fecha_hasta=2026-06-21"
        )
            ->assertOk()
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.records.0.ticket.code', 'T-20260621-002');
    }

    public function test_provider_can_have_multiple_assigned_plates(): void
    {
        $first = $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'abc-123']
        )
            ->assertCreated()
            ->assertJsonPath('data.plate', 'ABC-123')
            ->json('data.id');
        $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'xyz-999']
        )->assertCreated();

        $this->assertDatabaseCount('vehiculos', 2);
        $this->assertDatabaseCount('proveedor_vehiculos', 2);
        $this->assertDatabaseMissing('vehiculos', ['es_propio' => false]);
        $this->assertDatabaseMissing('vehiculos', ['tercero_propietario_id' => $this->providerId]);

        $this->getJson("/api/v1/proveedores/{$this->providerId}/historial")
            ->assertOk()
            ->assertJsonCount(2, 'data.vehicles');
        $this->getJson('/api/v1/operacion/proveedores?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->providerId)
            ->assertJsonCount(2, 'data.0.vehicles')
            ->assertJsonPath('data.0.vehicles.0.plate', 'ABC-123')
            ->assertJsonPath('data.0.vehicles.1.plate', 'XYZ-999');

        $this->deleteJson("/api/v1/proveedores/{$this->providerId}/vehiculos/{$first}")
            ->assertOk();
        $this->assertDatabaseHas('proveedor_vehiculos', [
            'id' => $first,
            'estado' => 'INACTIVO',
        ]);
        $this->assertDatabaseCount('vehiculos', 2);
        $this->assertDatabaseHas('vehiculos', [
            'placa' => 'ABC-123',
            'es_propio' => true,
            'tercero_propietario_id' => null,
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_existing_company_truck_can_be_assigned_without_duplication(): void
    {
        $truckId = $this->postJson('/api/v1/camiones', ['placa' => 'EMP-001'])
            ->assertCreated()
            ->assertJsonPath('data.is_own', true)
            ->json('data.id');

        $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'EMP-001']
        )
            ->assertCreated()
            ->assertJsonPath('data.vehicle_id', $truckId);

        $this->assertDatabaseCount('vehiculos', 1);
        $this->assertDatabaseHas('vehiculos', [
            'id' => $truckId,
            'es_propio' => true,
            'tercero_propietario_id' => null,
        ]);
        $this->getJson('/api/v1/camiones?buscar=PROVEEDOR NORTE')
            ->assertOk()
            ->assertJsonPath('data.0.id', $truckId)
            ->assertJsonPath('data.0.assigned_provider.id', $this->providerId);
    }

    public function test_plate_cannot_be_assigned_twice_or_to_two_active_providers(): void
    {
        $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'ABC-123']
        )->assertCreated();
        $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'ABC-123']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('placa');

        $otherProviderId = $this->postJson('/api/v1/proveedores', $this->partyPayload(
            'Proveedor Centro',
            '20333333333'
        ))->assertCreated()->json('data.id');

        $this->postJson(
            "/api/v1/proveedores/{$otherProviderId}/vehiculos",
            ['placa' => 'ABC-123']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('placa');
    }

    /**
     * @return array<string, mixed>
     */
    private function partyPayload(string $name, string $document): array
    {
        return [
            'nombre_razon_social' => $name,
            'numero_documento' => $document,
            'direccion' => 'Av. Principal 123',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 8.5,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ];
    }

    private function createWeighing(
        string $ticketCode,
        string $operatingDate,
        string $plate,
        ?int $customerId = null,
        ?int $warehouseId = null,
        float $netWeight = 10
    ): void {
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => $operatingDate,
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => "{$operatingDate} 06:00:00",
            'cierre_programado_at' => "{$operatingDate} 21:00:00",
            'cerrada_por' => $this->user->id,
            'cerrada_at' => "{$operatingDate} 20:00:00",
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $ticketCode,
            'canal' => 'MAYORISTA',
            'cliente_destino_id' => $customerId,
            'almacen_destino_id' => $warehouseId,
            'estado' => 'CERRADO',
            'cerrado_por' => $this->user->id,
            'cerrado_at' => "{$operatingDate} 10:30:00",
            'created_by' => $this->user->id,
            'created_at' => "{$operatingDate} 10:00:00",
            'updated_at' => "{$operatingDate} 10:30:00",
        ]);

        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $this->chickenTypeId,
            'tipo_java_id' => $this->cageTypeId,
            'proveedor_origen_id' => $this->providerId,
            'placa_snapshot' => $plate,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => 10,
            'cantidad_javas' => 2,
            'cantidad_aves' => 20,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => $netWeight + 14,
            'peso_bruto_kg' => $netWeight + 14,
            'tara_total_kg' => 14,
            'peso_neto_kg' => $netWeight,
            'pesada_at' => "{$operatingDate} 10:15:00",
            'estado' => 'ACTIVA',
            'created_by' => $this->user->id,
            'created_at' => "{$operatingDate} 10:15:00",
            'updated_at' => "{$operatingDate} 10:15:00",
        ]);
    }
}
