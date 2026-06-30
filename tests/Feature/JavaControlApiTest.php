<?php

namespace Tests\Feature;

use App\Models\Conductor;
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
            ->assertJsonPath('data.clients.0.id', $this->client->id)
            ->assertJsonPath('data.clients.0.balance', 10)
            ->assertJsonPath('data.clients_pagination.per_page', 12)
            ->assertJsonPath('data.clients_pagination.total', 1)
            ->assertJsonPath('data.trucks.0.id', $this->truck->id)
            ->assertJsonPath('data.drivers.0.id', $this->driver->id);

        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'quantity' => 4,
            'observations' => 'Recibidas completas',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', MovimientoJava::TYPE_RECEIPT)
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.truck.plate', 'JAV-001');

        $this->assertDatabaseHas('movimientos_javas', [
            'cliente_id' => $this->client->id,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 4,
            'vehiculo_id' => $this->truck->id,
            'conductor_id' => $this->driver->id,
        ]);
        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.total_pending', 6)
            ->assertJsonPath('data.clients.0.balance', 6);
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

    public function test_receipt_timestamp_is_assigned_by_the_server_and_manual_value_is_ignored(): void
    {
        $this->travelTo(now()->setTime(14, 35, 20));

        $this->postJson('/api/v1/control-javas/recepciones', [
            'client_id' => $this->client->id,
            'vehicle_id' => $this->truck->id,
            'driver_id' => $this->driver->id,
            'quantity' => 2,
            'received_at' => '2000-01-01T00:00:00-05:00',
        ])->assertCreated();

        $movement = MovimientoJava::query()
            ->where('tipo', MovimientoJava::TYPE_RECEIPT)
            ->sole();

        $this->assertTrue($movement->fecha_movimiento->equalTo(now()));
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
}
