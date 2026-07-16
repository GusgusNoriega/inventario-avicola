<?php

namespace Tests\Feature;

use App\Models\Conductor;
use App\Models\JornadaOperativa;
use App\Models\MovimientoJava;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JavaControlApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private Tercero $client;

    private Vehiculo $truck;

    private Conductor $driver;

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

        $permissions = collect(['DESPACHOS_VER', 'DESPACHOS_CREAR'])
            ->map(fn (string $code) => Permission::query()->create([
                'codigo' => $code,
                'descripcion' => $code,
            ]));
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        $role->permissions()->attach($permissions->pluck('id'));
        $this->user->roles()->attach($role);

        $this->client = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900123456',
            'nombre_razon_social' => 'CLIENTE CONTROL JAVAS',
            'direccion' => 'Dirección del cliente',
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        TerceroRole::query()->create([
            'tercero_id' => $this->client->id,
            'rol' => TerceroRole::CLIENT,
        ]);
        $this->truck = Vehiculo::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'JAV-001',
            'es_propio' => true,
            'estado' => Vehiculo::STATUS_ACTIVE,
        ]);
        $this->driver = Conductor::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'nombre_completo' => 'CHOFER DE JAVAS',
            'estado' => Conductor::STATUS_ACTIVE,
        ]);
        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 10,
            'cantidad_bandejas' => 8,
            'vehiculo_id' => $this->truck->id,
            'conductor_id' => $this->driver->id,
            'fecha_movimiento' => now(),
            'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_control_lists_balances_and_registers_a_receipt(): void
    {
        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.total_pending', 10)
            ->assertJsonPath('data.summary.clients_with_balance', 1)
            ->assertJsonPath('data.summary.java_total_pending', 10)
            ->assertJsonPath('data.summary.java_clients_with_balance', 1)
            ->assertJsonPath('data.summary.tray_total_pending', 8)
            ->assertJsonPath('data.summary.tray_clients_with_balance', 1)
            ->assertJsonPath('data.inventory.configured', false)
            ->assertJsonPath('data.inventory.outside', 10)
            ->assertJsonPath('data.inventory.javas.configured', false)
            ->assertJsonPath('data.inventory.javas.outside', 10)
            ->assertJsonPath('data.inventory.trays.configured', false)
            ->assertJsonPath('data.inventory.trays.outside', 8)
            ->assertJsonPath('data.clients.0.id', $this->client->id)
            ->assertJsonPath('data.clients.0.balance', 10)
            ->assertJsonPath('data.clients.0.java_balance', 10)
            ->assertJsonPath('data.clients.0.tray_balance', 8)
            ->assertJsonPath('data.client_options.0.id', $this->client->id)
            ->assertJsonPath('data.client_options.0.balance', 10)
            ->assertJsonPath('data.client_options.0.java_balance', 10)
            ->assertJsonPath('data.client_options.0.tray_balance', 8)
            ->assertJsonPath('data.clients_pagination.per_page', 12)
            ->assertJsonPath('data.clients_pagination.total', 1)
            ->assertJsonPath('data.trucks.0.id', $this->truck->id)
            ->assertJsonPath('data.drivers.0.id', $this->driver->id);

        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 50,
            'tray_quantity' => 30,
        ])
            ->assertCreated()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.inside', 40)
            ->assertJsonPath('data.outside', 10)
            ->assertJsonPath('data.javas.configured', true)
            ->assertJsonPath('data.javas.total', 50)
            ->assertJsonPath('data.javas.inside', 40)
            ->assertJsonPath('data.javas.outside', 10)
            ->assertJsonPath('data.trays.configured', true)
            ->assertJsonPath('data.trays.total', 30)
            ->assertJsonPath('data.trays.inside', 22)
            ->assertJsonPath('data.trays.outside', 8);
        $this->assertDatabaseHas('inventarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_total' => 50,
            'cantidad_total_bandejas' => 30,
            'updated_by' => $this->user->id,
        ]);

        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'java_quantity' => 4,
            'tray_quantity' => 3,
            'observations' => 'Recibidas completas',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', MovimientoJava::TYPE_RECEIPT)
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.java_quantity', 4)
            ->assertJsonPath('data.tray_quantity', 3)
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.truck.plate', 'JAV-001')
            ->assertJsonPath('data.journey.status', JornadaOperativa::STATUS_OPEN);

        $this->assertDatabaseHas('movimientos_javas', [
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 4,
            'cantidad_bandejas' => 3,
            'vehiculo_id' => $this->truck->id,
            'conductor_id' => $this->driver->id,
        ]);
        $this->assertNotNull(
            MovimientoJava::query()
                ->where('tipo', MovimientoJava::TYPE_RECEIPT)
                ->value('jornada_id')
        );
        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.total_pending', 6)
            ->assertJsonPath('data.summary.java_total_pending', 6)
            ->assertJsonPath('data.summary.tray_total_pending', 5)
            ->assertJsonPath('data.clients.0.balance', 6)
            ->assertJsonPath('data.clients.0.java_balance', 6)
            ->assertJsonPath('data.clients.0.tray_balance', 5)
            ->assertJsonPath('data.inventory.total', 50)
            ->assertJsonPath('data.inventory.inside', 44)
            ->assertJsonPath('data.inventory.outside', 6)
            ->assertJsonPath('data.inventory.javas.total', 50)
            ->assertJsonPath('data.inventory.javas.inside', 44)
            ->assertJsonPath('data.inventory.javas.outside', 6)
            ->assertJsonPath('data.inventory.trays.total', 30)
            ->assertJsonPath('data.inventory.trays.inside', 25)
            ->assertJsonPath('data.inventory.trays.outside', 5);
    }

    public function test_receipt_cannot_exceed_the_client_balance(): void
    {
        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'quantity' => 11,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('movimientos_javas', 1);
    }

    public function test_tray_receipt_cannot_exceed_the_client_balance(): void
    {
        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'java_quantity' => 0,
            'tray_quantity' => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tray_quantity');

        $this->assertDatabaseCount('movimientos_javas', 1);
    }

    public function test_legacy_java_quantity_aliases_remain_compatible(): void
    {
        $this->postJson('/api/v1/control-javas/inventario', [
            'total_quantity' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.javas.total', 50);

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'quantity' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.daily_count.quantity', 40)
            ->assertJsonPath('data.javas.daily_count.quantity', 40)
            ->assertJsonPath('data.count_breakdown.legacy', true)
            ->assertJsonPath('data.count_breakdown.detailed', false)
            ->assertJsonPath('data.count_breakdown.local.javas', null);

        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.java_quantity', 2)
            ->assertJsonPath('data.tray_quantity', 0);

        $this->assertDatabaseHas('movimientos_javas', [
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
        ]);
        $this->assertDatabaseCount('conteos_diarios_javas_camiones', 0);
    }

    public function test_company_total_cannot_be_less_than_javas_outside(): void
    {
        $this->postJson('/api/v1/control-javas/inventario', [
            'total_quantity' => 9,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_quantity');

        $this->assertDatabaseCount('inventarios_javas', 0);
    }

    public function test_company_total_cannot_be_less_than_trays_outside(): void
    {
        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 10,
            'tray_quantity' => 7,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tray_quantity');

        $this->assertDatabaseCount('inventarios_javas', 0);
    }

    public function test_daily_count_detects_missing_javas_without_changing_company_total(): void
    {
        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 50,
            'tray_quantity' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'java_quantity' => 38,
            'tray_quantity' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', 50)
            ->assertJsonPath('data.inside', 40)
            ->assertJsonPath('data.outside', 10)
            ->assertJsonPath('data.daily_count.configured', true)
            ->assertJsonPath('data.daily_count.quantity', 38)
            ->assertJsonPath('data.daily_count.expected', 40)
            ->assertJsonPath('data.daily_count.difference', -2)
            ->assertJsonPath('data.daily_count.missing', 2)
            ->assertJsonPath('data.javas.total', 50)
            ->assertJsonPath('data.javas.inside', 40)
            ->assertJsonPath('data.javas.outside', 10)
            ->assertJsonPath('data.javas.daily_count.quantity', 38)
            ->assertJsonPath('data.javas.daily_count.expected', 40)
            ->assertJsonPath('data.javas.daily_count.difference', -2)
            ->assertJsonPath('data.javas.daily_count.missing', 2)
            ->assertJsonPath('data.trays.total', 30)
            ->assertJsonPath('data.trays.inside', 22)
            ->assertJsonPath('data.trays.outside', 8)
            ->assertJsonPath('data.trays.daily_count.quantity', 20)
            ->assertJsonPath('data.trays.daily_count.expected', 22)
            ->assertJsonPath('data.trays.daily_count.difference', -2)
            ->assertJsonPath('data.trays.daily_count.missing', 2);

        $this->assertDatabaseHas('conteos_diarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_en_empresa' => 38,
            'cantidad_esperada' => 40,
            'diferencia' => -2,
            'cantidad_en_empresa_bandejas' => 20,
            'cantidad_esperada_bandejas' => 22,
            'diferencia_bandejas' => -2,
            'contado_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('inventarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_total' => 50,
            'cantidad_total_bandejas' => 30,
        ]);
        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.inventory.daily_count.quantity', 38)
            ->assertJsonPath('data.inventory.daily_count.missing', 2)
            ->assertJsonPath('data.inventory.javas.daily_count.quantity', 38)
            ->assertJsonPath('data.inventory.javas.daily_count.missing', 2)
            ->assertJsonPath('data.inventory.trays.daily_count.quantity', 20)
            ->assertJsonPath('data.inventory.trays.daily_count.missing', 2);

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'java_quantity' => 41,
            'tray_quantity' => 23,
        ])
            ->assertCreated()
            ->assertJsonPath('data.daily_count.difference', 1)
            ->assertJsonPath('data.daily_count.missing', 0)
            ->assertJsonPath('data.javas.daily_count.difference', 1)
            ->assertJsonPath('data.javas.daily_count.missing', 0)
            ->assertJsonPath('data.trays.daily_count.difference', 1)
            ->assertJsonPath('data.trays.daily_count.missing', 0);

        $this->assertDatabaseCount('conteos_diarios_javas', 1);
    }

    public function test_daily_count_separates_clients_local_and_every_active_truck(): void
    {
        $internalClient = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900123457',
            'nombre_razon_social' => 'CLIENTE INTERNO DE LA AVICOLA',
            'direccion' => 'Dentro de la avicola',
            'es_cliente_interno' => true,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        TerceroRole::query()->create([
            'tercero_id' => $internalClient->id,
            'rol' => TerceroRole::CLIENT,
        ]);
        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'cliente_id' => $internalClient->id,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 5,
            'cantidad_bandejas' => 4,
            'fecha_movimiento' => now(),
            'created_by' => $this->user->id,
        ]);
        $emptyTruck = Vehiculo::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'JAV-002',
            'estado' => Vehiculo::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.external_clients_count', 1)
            ->assertJsonPath('data.summary.external_java_pending', 10)
            ->assertJsonPath('data.summary.external_tray_pending', 8)
            ->assertJsonPath('data.summary.internal_clients_count', 1)
            ->assertJsonPath('data.summary.internal_java_pending', 5)
            ->assertJsonPath('data.summary.internal_tray_pending', 4)
            ->assertJsonPath('data.client_holders.external.clients.0.id', $this->client->id)
            ->assertJsonPath('data.client_holders.internal.clients.0.id', $internalClient->id)
            ->assertJsonPath('data.client_holders.internal.clients.0.is_internal_client', true)
            ->assertJsonPath('data.inventory.javas.outside', 15)
            ->assertJsonPath('data.inventory.javas.assigned_external', 10)
            ->assertJsonPath('data.inventory.javas.assigned_internal', 5)
            ->assertJsonPath('data.inventory.count_breakdown.configured', false)
            ->assertJsonCount(2, 'data.inventory.count_breakdown.trucks')
            ->assertJsonPath('data.inventory.count_breakdown.trucks.1.plate', 'JAV-002')
            ->assertJsonPath('data.inventory.count_breakdown.trucks.1.java_quantity', 0);

        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 60,
            'tray_quantity' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.javas.inside', 45)
            ->assertJsonPath('data.trays.inside', 28);

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'local_java_quantity' => 35,
            'local_tray_quantity' => 20,
            'truck_counts' => [
                [
                    'vehicle_id' => $this->truck->id,
                    'java_quantity' => 10,
                    'tray_quantity' => 8,
                ],
                [
                    'vehicle_id' => $emptyTruck->id,
                    'java_quantity' => 0,
                    'tray_quantity' => 0,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.javas.daily_count.quantity', 45)
            ->assertJsonPath('data.javas.daily_count.expected', 45)
            ->assertJsonPath('data.javas.daily_count.difference', 0)
            ->assertJsonPath('data.trays.daily_count.quantity', 28)
            ->assertJsonPath('data.trays.daily_count.expected', 28)
            ->assertJsonPath('data.trays.daily_count.difference', 0)
            ->assertJsonPath('data.count_breakdown.configured', true)
            ->assertJsonPath('data.count_breakdown.detailed', true)
            ->assertJsonPath('data.count_breakdown.legacy', false)
            ->assertJsonPath('data.count_breakdown.local.javas', 35)
            ->assertJsonPath('data.count_breakdown.local.trays', 20)
            ->assertJsonPath('data.count_breakdown.trucks_total.javas', 10)
            ->assertJsonPath('data.count_breakdown.trucks_total.trays', 8)
            ->assertJsonPath('data.count_breakdown.direct_total.javas', 45)
            ->assertJsonPath('data.count_breakdown.internal_clients.javas', 5)
            ->assertJsonPath('data.count_breakdown.inside_avicola.javas', 50)
            ->assertJsonPath('data.count_breakdown.external_clients.javas', 10)
            ->assertJsonPath('data.count_breakdown.accounted_total.javas', 60)
            ->assertJsonPath('data.count_breakdown.property_total.javas', 60)
            ->assertJsonPath('data.count_breakdown.difference.javas', 0)
            ->assertJsonPath('data.count_breakdown.trucks.1.recorded', true)
            ->assertJsonPath('data.count_breakdown.trucks.1.java_quantity', 0);

        $this->assertDatabaseHas('conteos_diarios_javas', [
            'empresa_id' => $this->user->empresa_id,
            'cantidad_en_local' => 35,
            'cantidad_en_local_bandejas' => 20,
            'cantidad_en_empresa' => 45,
            'cantidad_en_empresa_bandejas' => 28,
            'cantidad_clientes_externos' => 10,
            'cantidad_clientes_externos_bandejas' => 8,
            'cantidad_clientes_internos' => 5,
            'cantidad_clientes_internos_bandejas' => 4,
            'cantidad_total_inventario' => 60,
            'cantidad_total_inventario_bandejas' => 40,
            'diferencia' => 0,
            'diferencia_bandejas' => 0,
        ]);
        $this->assertDatabaseHas('conteos_diarios_javas_camiones', [
            'vehiculo_id' => $this->truck->id,
            'placa_snapshot' => 'JAV-001',
            'cantidad_javas' => 10,
            'cantidad_bandejas' => 8,
        ]);
        $this->assertDatabaseHas('conteos_diarios_javas_camiones', [
            'vehiculo_id' => $emptyTruck->id,
            'placa_snapshot' => 'JAV-002',
            'cantidad_javas' => 0,
            'cantidad_bandejas' => 0,
        ]);
        $this->assertDatabaseCount('conteos_diarios_javas_camiones', 2);
    }

    public function test_detailed_count_requires_the_complete_active_company_fleet(): void
    {
        Vehiculo::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'JAV-002',
            'estado' => Vehiculo::STATUS_ACTIVE,
        ]);
        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 50,
            'tray_quantity' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'local_java_quantity' => 30,
            'local_tray_quantity' => 20,
            'truck_counts' => [[
                'vehicle_id' => $this->truck->id,
                'java_quantity' => 10,
                'tray_quantity' => 2,
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('truck_counts');

        $this->assertDatabaseCount('conteos_diarios_javas', 0);
        $this->assertDatabaseCount('conteos_diarios_javas_camiones', 0);
    }

    public function test_detailed_count_works_with_local_only_when_there_are_no_active_trucks(): void
    {
        $this->truck->update(['estado' => Vehiculo::STATUS_INACTIVE]);
        $this->postJson('/api/v1/control-javas/inventario', [
            'java_quantity' => 50,
            'tray_quantity' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/control-javas/conteo-diario', [
            'local_java_quantity' => 40,
            'local_tray_quantity' => 22,
            'truck_counts' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.count_breakdown.detailed', true)
            ->assertJsonPath('data.count_breakdown.local.javas', 40)
            ->assertJsonPath('data.count_breakdown.trucks_total.javas', 0)
            ->assertJsonPath('data.count_breakdown.direct_total.javas', 40)
            ->assertJsonPath('data.count_breakdown.difference.javas', 0)
            ->assertJsonCount(0, 'data.count_breakdown.trucks');

        $this->assertDatabaseCount('conteos_diarios_javas', 1);
        $this->assertDatabaseCount('conteos_diarios_javas_camiones', 0);
    }

    public function test_inactive_clients_with_assets_remain_visible_in_the_daily_report(): void
    {
        $this->client->update(['estado' => Tercero::STATUS_INACTIVE]);

        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.total_pending', 10)
            ->assertJsonPath('data.summary.clients_with_balance', 1)
            ->assertJsonPath('data.client_holders.external.clients.0.id', $this->client->id)
            ->assertJsonPath('data.client_holders.external.clients.0.status', Tercero::STATUS_INACTIVE)
            ->assertJsonPath('data.inventory.javas.outside', 10)
            ->assertJsonCount(0, 'data.client_options');
    }

    public function test_receipt_timestamp_is_assigned_by_the_server_and_manual_value_is_ignored(): void
    {
        $this->travelTo(now()->setTime(14, 35, 20));

        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'quantity' => 2,
            'received_at' => '2000-01-01T00:00:00-05:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.java_quantity', 2)
            ->assertJsonPath('data.tray_quantity', 0);

        $movement = MovimientoJava::query()
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sole();

        $this->assertTrue($movement->fecha_movimiento->equalTo(now()));
        $this->assertSame(2, $movement->cantidad);
        $this->assertSame(0, $movement->cantidad_bandejas);
    }

    public function test_clients_are_paginated_by_twelve_and_searched_on_the_server(): void
    {
        foreach (range(1, 13) as $index) {
            $client = Tercero::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'tipo_documento' => 'NIT',
                'numero_documento' => '800000'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'nombre_razon_social' => $index === 13
                    ? 'CLIENTE PAGINADO ESPECIAL'
                    : 'CLIENTE PAGINADO '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'direccion' => 'Dirección cliente paginado',
                'estado' => Tercero::STATUS_ACTIVE,
            ]);
            TerceroRole::query()->create([
                'tercero_id' => $client->id,
                'rol' => TerceroRole::CLIENT,
            ]);
        }

        $this->getJson('/api/v1/control-javas?page=1')
            ->assertOk()
            ->assertJsonCount(12, 'data.clients')
            ->assertJsonPath('data.clients_pagination.current_page', 1)
            ->assertJsonPath('data.clients_pagination.last_page', 2)
            ->assertJsonPath('data.clients_pagination.total', 14);

        $this->getJson('/api/v1/control-javas?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.clients')
            ->assertJsonPath('data.clients_pagination.current_page', 2);

        $this->getJson('/api/v1/control-javas?search=ESPECIAL')
            ->assertOk()
            ->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.clients.0.name', 'CLIENTE PAGINADO ESPECIAL')
            ->assertJsonPath('data.clients_pagination.total', 1)
            ->assertJsonPath('data.summary.total_pending', 10);

        $this->getJson('/api/v1/control-javas?search=800000013')
            ->assertOk()
            ->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.clients.0.name', 'CLIENTE PAGINADO ESPECIAL');
    }

    public function test_traceability_is_filtered_and_summarized_by_journey(): void
    {
        $firstJourney = JornadaOperativa::query()->create([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-06-29',
            'estado' => JornadaOperativa::STATUS_CLOSED,
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-06-28 21:00:00',
            'cierre_programado_at' => '2026-06-29 21:00:00',
            'cerrada_por' => $this->user->id,
            'cerrada_at' => '2026-06-29 21:00:00',
        ]);
        $secondJourney = JornadaOperativa::query()->create([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-06-30',
            'estado' => JornadaOperativa::STATUS_CLOSED,
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-06-29 21:00:00',
            'cierre_programado_at' => '2026-06-30 21:00:00',
            'cerrada_por' => $this->user->id,
            'cerrada_at' => '2026-06-30 21:00:00',
        ]);
        MovimientoJava::query()->firstOrFail()->update([
            'jornada_id' => $firstJourney->id,
            'cantidad_bandejas' => 6,
            'fecha_movimiento' => '2026-06-29 10:00:00',
        ]);
        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $firstJourney->id,
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 3,
            'cantidad_bandejas' => 2,
            'vehiculo_id' => $this->truck->id,
            'conductor_id' => $this->driver->id,
            'fecha_movimiento' => '2026-06-29 12:00:00',
            'created_by' => $this->user->id,
        ]);
        MovimientoJava::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $secondJourney->id,
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 7,
            'cantidad_bandejas' => 4,
            'vehiculo_id' => $this->truck->id,
            'conductor_id' => $this->driver->id,
            'fecha_movimiento' => '2026-06-30 10:00:00',
            'created_by' => $this->user->id,
        ]);

        $this->getJson("/api/v1/control-javas?journey_id={$firstJourney->id}")
            ->assertOk()
            ->assertJsonPath('data.selected_journey_id', $firstJourney->id)
            ->assertJsonPath('data.summary.dispatched', 10)
            ->assertJsonPath('data.summary.received', 3)
            ->assertJsonPath('data.summary.net', 7)
            ->assertJsonPath('data.summary.java_dispatched', 10)
            ->assertJsonPath('data.summary.java_received', 3)
            ->assertJsonPath('data.summary.java_net', 7)
            ->assertJsonPath('data.summary.tray_dispatched', 6)
            ->assertJsonPath('data.summary.tray_received', 2)
            ->assertJsonPath('data.summary.tray_net', 4)
            ->assertJsonPath('data.summary.trucks_count', 1)
            ->assertJsonPath('data.current_summary.journey_id', $secondJourney->id)
            ->assertJsonPath('data.current_summary.dispatched', 7)
            ->assertJsonPath('data.current_summary.received', 0)
            ->assertJsonPath('data.current_summary.java_dispatched', 7)
            ->assertJsonPath('data.current_summary.java_received', 0)
            ->assertJsonPath('data.current_summary.java_net', 7)
            ->assertJsonPath('data.current_summary.tray_dispatched', 4)
            ->assertJsonPath('data.current_summary.tray_received', 0)
            ->assertJsonPath('data.current_summary.tray_net', 4)
            ->assertJsonCount(2, 'data.movements')
            ->assertJsonPath('data.movements.0.quantity', 3)
            ->assertJsonPath('data.movements.0.java_quantity', 3)
            ->assertJsonPath('data.movements.0.tray_quantity', 2)
            ->assertJsonPath('data.truck_activity.0.truck.plate', 'JAV-001')
            ->assertJsonPath('data.truck_activity.0.driver.name', 'CHOFER DE JAVAS')
            ->assertJsonPath('data.truck_activity.0.dispatched', 10)
            ->assertJsonPath('data.truck_activity.0.received', 3)
            ->assertJsonPath('data.truck_activity.0.java_dispatched', 10)
            ->assertJsonPath('data.truck_activity.0.java_received', 3)
            ->assertJsonPath('data.truck_activity.0.java_net', 7)
            ->assertJsonPath('data.truck_activity.0.tray_dispatched', 6)
            ->assertJsonPath('data.truck_activity.0.tray_received', 2)
            ->assertJsonPath('data.truck_activity.0.tray_net', 4);

        $this->getJson("/api/v1/control-javas?journey_id={$secondJourney->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.dispatched', 7)
            ->assertJsonPath('data.summary.received', 0)
            ->assertJsonPath('data.summary.java_net', 7)
            ->assertJsonPath('data.summary.tray_dispatched', 4)
            ->assertJsonPath('data.summary.tray_net', 4)
            ->assertJsonCount(1, 'data.movements');
    }
}
