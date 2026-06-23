<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchTicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $warehouseId;

    private int $clientId;

    private int $providerId;

    private int $providerVehicleId;

    private int $vehicleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permissions = collect([
            ['DESPACHOS_VER', 'Ver despachos'],
            ['DESPACHOS_CREAR', 'Crear despachos'],
            ['TERCEROS_GESTIONAR', 'Gestionar terceros'],
            ['PRECIOS_GESTIONAR', 'Gestionar precios'],
        ])->map(fn (array $permission) => Permission::query()->create([
            'codigo' => $permission[0],
            'descripcion' => $permission[1],
        ]));
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
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

        collect([
            ['JAVA_700', 'Java 7.00 kg', 7],
            ['JAVA_690', 'Java 6.90 kg', 6.9],
        ])->each(fn (array $type) => DB::table('tipos_java')->insert([
            'codigo' => $type[0],
            'nombre' => $type[1],
            'peso_kg' => $type[2],
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

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
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => 'ALMACEN_1',
            'nombre' => 'Almacén principal',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['api']);

        $this->clientId = $this->postJson('/api/v1/clientes', $this->partyPayload(
            'Cliente destino',
            '20111111111',
            [8.5, 9.5, 10.5]
        ))->assertCreated()->json('data.id');
        $this->providerId = $this->postJson('/api/v1/proveedores', $this->partyPayload(
            'Proveedor origen',
            '20222222222',
            [7.5, 8.5, 9.5]
        ))->assertCreated()->json('data.id');
        $vehicle = $this->postJson(
            "/api/v1/proveedores/{$this->providerId}/vehiculos",
            ['placa' => 'ABC-123']
        )->assertCreated()->json('data');
        $this->providerVehicleId = $vehicle['id'];
        $this->vehicleId = $vehicle['vehicle_id'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ticket_and_all_weighings_are_registered_in_one_transaction(): void
    {
        $payload = $this->ticketPayload();
        $localNow = now('America/Bogota');
        $operatingDate = $localNow->format('H:i:s') >= '21:00:00'
            ? $localNow->copy()->addDay()
            : $localNow;

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pesadas', 0);

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.code', 'T-'.$operatingDate->format('Ymd').'-001')
            ->assertJsonPath('data.status', 'CERRADO')
            ->assertJsonPath('data.destination.id', $this->clientId)
            ->assertJsonPath('data.weighing_count', 2)
            ->assertJsonMissingPath('data.prices');

        $this->assertDatabaseCount('jornadas_operativas', 1);
        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('ticket_precios', 2);
        $this->assertDatabaseHas('ticket_precios', [
            'precio_kg' => 8.5,
            'origen_precio' => 'CLIENTE',
        ]);
        $this->assertDatabaseCount('pesadas', 2);
        $this->assertDatabaseHas('pesadas', [
            'numero' => 1,
            'proveedor_origen_id' => $this->providerId,
            'vehiculo_id' => $this->vehicleId,
            'placa_snapshot' => 'ABC-123',
            'cantidad_aves' => 20,
            'tara_total_kg' => 14,
            'peso_neto_kg' => 16,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'numero' => 2,
            'almacen_origen_id' => $this->warehouseId,
            'cantidad_aves' => 12,
            'tara_total_kg' => 6.9,
            'peso_neto_kg' => 13.1,
        ]);
    }

    public function test_invalid_weighing_rolls_back_ticket_and_every_weighing(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][1]['origin'] = [
            'type' => 'PROVEEDOR',
            'provider_id' => $this->providerId,
            'provider_vehicle_id' => $this->providerVehicleId,
            'vehicle_id' => $this->vehicleId,
            'plate' => 'ZZZ-999',
        ];

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.1.origin.plate');

        $this->assertDatabaseCount('jornadas_operativas', 0);
        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('ticket_precios', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_published_journey_rejects_a_truck_that_was_not_selected(): void
    {
        $localNow = now('America/Bogota');
        $operatingDate = $localNow->format('H:i:s') >= '21:00:00'
            ? $localNow->copy()->addDay()->format('Y-m-d')
            : $localNow->format('Y-m-d');

        DB::table('programaciones_recepcion')->insert([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => $operatingDate,
            'estado' => 'PUBLICADA',
            'publicada_por' => $this->user->id,
            'publicada_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/operacion/tickets', $this->ticketPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.origin.provider_vehicle_id');

        $this->assertDatabaseCount('jornadas_operativas', 0);
        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_same_draft_id_is_idempotent(): void
    {
        $payload = $this->ticketPayload();

        $firstId = $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertCreated()
            ->json('data.id');
        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('data.id', $firstId);

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 2);
    }

    public function test_warehouse_destination_freezes_general_prices(): void
    {
        $this->createGeneralPrices();
        $payload = $this->ticketPayload();
        $payload['destination'] = [
            'type' => 'ALMACEN',
            'id' => $this->warehouseId,
        ];

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.destination.type', 'ALMACEN')
            ->assertJsonMissingPath('data.prices');

        $this->assertDatabaseCount('listas_precios', 3);
        $this->assertDatabaseCount('precios_historial', 9);
        $this->assertDatabaseHas('ticket_precios', [
            'precio_kg' => 8.75,
            'origen_precio' => 'GENERAL',
        ]);
    }

    public function test_client_without_specific_prices_uses_global_prices(): void
    {
        $this->createGeneralPrices();
        $clientWithoutPrices = $this->postJson('/api/v1/clientes', [
            'nombre_razon_social' => 'Cliente sin precio',
            'numero_documento' => '20333333333',
            'direccion' => 'Av. Sin precio 123',
        ])->assertCreated()->json('data.id');
        $payload = $this->ticketPayload();
        $payload['destination']['id'] = $clientWithoutPrices;

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.destination.id', $clientWithoutPrices);

        $this->assertDatabaseHas('ticket_precios', [
            'precio_kg' => 8.75,
            'origen_precio' => 'GENERAL',
        ]);
        $this->assertDatabaseHas('ticket_precios', [
            'precio_kg' => 9.75,
            'origen_precio' => 'GENERAL',
        ]);
    }

    public function test_client_price_update_revalues_only_tickets_from_the_current_journey(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 20:00:00', 'America/Bogota'));
        $previousTicketId = $this->postJson(
            '/api/v1/operacion/tickets',
            $this->ticketPayload()
        )->assertCreated()->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-06-22 22:00:00', 'America/Bogota'));
        $currentTicketId = $this->postJson(
            '/api/v1/operacion/tickets',
            $this->ticketPayload()
        )->assertCreated()->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-06-23 10:00:00', 'America/Bogota'));
        $this->putJson("/api/v1/clientes/{$this->clientId}", $this->partyPayload(
            'Cliente destino',
            '20111111111',
            [9.25, 9.5, 10.5]
        ))->assertOk();

        $liveTypeId = DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::CHICKEN_LIVE)
            ->value('id');
        $latestHistoryId = DB::table('precios_historial')
            ->join('listas_precios', 'listas_precios.id', '=', 'precios_historial.lista_precio_id')
            ->where('listas_precios.tercero_id', $this->clientId)
            ->where('precios_historial.tipo_pollo_id', $liveTypeId)
            ->whereNull('precios_historial.vigente_hasta')
            ->value('precios_historial.id');

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $previousTicketId,
            'tipo_pollo_id' => $liveTypeId,
            'precio_kg' => 8.5,
        ]);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $currentTicketId,
            'tipo_pollo_id' => $liveTypeId,
            'precio_historial_id' => $latestHistoryId,
            'precio_kg' => 9.25,
            'origen_precio' => 'CLIENTE',
            'congelado_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'usuario_id' => $this->user->id,
            'entidad' => 'ticket_precios',
            'accion' => 'REVALORIZAR_JORNADA',
        ]);
    }

    public function test_missing_internal_general_prices_rolls_back_warehouse_ticket(): void
    {
        $payload = $this->ticketPayload();
        $payload['destination'] = [
            'type' => 'ALMACEN',
            'id' => $this->warehouseId,
        ];

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination.id');

        $this->assertDatabaseCount('jornadas_operativas', 0);
        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('ticket_precios', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_dispatch_ticket_payload_cannot_submit_prices(): void
    {
        $payload = $this->ticketPayload();
        $payload['general_prices'] = [
            TipoPollo::CHICKEN_LIVE => 1,
            TipoPollo::CHICKEN_DRESSED => 1,
        ];

        $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('general_prices');

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('ticket_precios', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_operation_catalog_returns_database_warehouses_and_cage_types(): void
    {
        $this->getJson('/api/v1/operacion/catalogo')
            ->assertOk()
            ->assertJsonPath('data.branch.id', $this->branchId)
            ->assertJsonPath('data.warehouses.0.id', $this->warehouseId)
            ->assertJsonPath('data.warehouses.0.code', 'ALMACEN_1')
            ->assertJsonPath('data.cage_types.0.code', 'JAVA_700')
            ->assertJsonPath('data.cage_types.0.weight_kg', 7)
            ->assertJsonMissingPath('data.general_prices');
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(): array
    {
        $weighedAt = now('America/Bogota')->subMinute()->toISOString();

        return [
            'draft_id' => (string) Str::uuid(),
            'destination' => [
                'type' => 'CLIENTE',
                'id' => $this->clientId,
            ],
            'weighings' => [
                [
                    'local_id' => 1,
                    'chicken_type_code' => TipoPollo::CHICKEN_LIVE,
                    'cage_type_code' => 'JAVA_700',
                    'origin' => [
                        'type' => 'PROVEEDOR',
                        'provider_id' => $this->providerId,
                        'provider_vehicle_id' => $this->providerVehicleId,
                        'vehicle_id' => $this->vehicleId,
                        'plate' => 'ABC-123',
                    ],
                    'weight_source' => 'BALANZA_1',
                    'birds_per_cage' => 10,
                    'cage_count' => 2,
                    'read_weight_kg' => 30,
                    'gross_weight_kg' => 30,
                    'weighed_at' => $weighedAt,
                ],
                [
                    'local_id' => 2,
                    'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
                    'cage_type_code' => 'JAVA_690',
                    'origin' => [
                        'type' => 'ALMACEN',
                        'warehouse_id' => $this->warehouseId,
                    ],
                    'weight_source' => 'MANUAL',
                    'birds_per_cage' => 12,
                    'cage_count' => 1,
                    'read_weight_kg' => 20,
                    'gross_weight_kg' => 20,
                    'weighed_at' => $weighedAt,
                ],
            ],
        ];
    }

    private function createGeneralPrices(): void
    {
        $listId = DB::table('listas_precios')->insertGetId([
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
        $prices = [
            TipoPollo::CHICKEN_LIVE => 8.75,
            TipoPollo::CHICKEN_DRESSED => 9.75,
            TipoPollo::CHICKEN_PROCESSED => 10.75,
        ];
        $typeIds = DB::table('tipos_pollo')
            ->whereIn('codigo', array_keys($prices))
            ->pluck('id', 'codigo');

        foreach ($prices as $code => $price) {
            DB::table('precios_historial')->insert([
                'lista_precio_id' => $listId,
                'tipo_pollo_id' => $typeIds[$code],
                'precio_kg' => $price,
                'vigente_desde' => now()->subMinute(),
                'vigente_hasta' => null,
                'motivo_cambio' => 'Configuración administrativa',
                'reemplaza_precio_id' => null,
                'registrado_por' => $this->user->id,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $prices
     * @return array<string, mixed>
     */
    private function partyPayload(string $name, string $document, array $prices): array
    {
        return [
            'nombre_razon_social' => $name,
            'numero_documento' => $document,
            'direccion' => 'Av. Principal 123',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => $prices[0],
                TipoPollo::CHICKEN_DRESSED => $prices[1],
                TipoPollo::CHICKEN_PROCESSED => $prices[2],
            ],
        ];
    }
}
