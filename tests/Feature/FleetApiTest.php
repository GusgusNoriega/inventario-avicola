<?php

namespace Tests\Feature;

use App\Models\Conductor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permission = Permission::query()->create([
            'codigo' => 'TERCEROS_GESTIONAR',
            'descripcion' => 'Gestionar terceros',
        ]);
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $role->permissions()->attach($permission);
        $this->user->roles()->attach($role);

        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_truck_crud_is_complete_and_scoped_to_company_fleet(): void
    {
        Vehiculo::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'EXT-999',
            'es_propio' => false,
            'estado' => Vehiculo::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/v1/camiones', [
            'placa' => ' abc 123 ',
            'marca' => 'Hino',
            'modelo' => '500',
            'color' => 'Blanco',
            'descripcion' => 'Camion principal',
        ])
            ->assertCreated()
            ->assertJsonPath('data.placa', 'ABC123')
            ->assertJsonPath('data.marca', 'Hino');

        $truckId = $response->json('data.id');

        $this->getJson('/api/v1/camiones?buscar=ABC')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $truckId);

        $this->getJson("/api/v1/camiones/{$truckId}")
            ->assertOk()
            ->assertJsonPath('data.placa', 'ABC123');

        $this->patchJson("/api/v1/camiones/{$truckId}", [
            'color' => 'Azul',
            'descripcion' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.color', 'Azul')
            ->assertJsonPath('data.descripcion', null);

        $this->deleteJson("/api/v1/camiones/{$truckId}")
            ->assertOk();

        $this->assertDatabaseHas('vehiculos', [
            'id' => $truckId,
            'estado' => Vehiculo::STATUS_INACTIVE,
        ]);
        $this->getJson("/api/v1/camiones/{$truckId}")->assertNotFound();

        $this->postJson('/api/v1/camiones', ['placa' => 'ABC123'])
            ->assertCreated()
            ->assertJsonPath('data.id', $truckId);
    }

    public function test_truck_plate_must_be_unique_within_company(): void
    {
        $this->postJson('/api/v1/camiones', ['placa' => 'AAA-111'])
            ->assertCreated();

        $this->postJson('/api/v1/camiones', ['placa' => 'AAA-111'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('placa');
    }

    public function test_driver_can_be_created_without_identity_document_and_updated_later(): void
    {
        $response = $this->postJson('/api/v1/choferes', [
            'nombre_completo' => 'Juan Perez',
            'telefono' => '3001234567',
        ])
            ->assertCreated()
            ->assertJsonPath('data.nombre_completo', 'JUAN PEREZ')
            ->assertJsonPath('data.tipo_documento', null)
            ->assertJsonPath('data.numero_documento', null);

        $driverId = $response->json('data.id');

        $this->patchJson("/api/v1/choferes/{$driverId}", [
            'tipo_documento' => 'CC',
            'numero_documento' => '10203040',
        ])
            ->assertOk()
            ->assertJsonPath('data.tipo_documento', 'CC')
            ->assertJsonPath('data.numero_documento', '10203040');

        $this->getJson('/api/v1/choferes?buscar=10203040')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/choferes/{$driverId}")
            ->assertOk();

        $this->assertDatabaseHas('conductores', [
            'id' => $driverId,
            'estado' => Conductor::STATUS_INACTIVE,
        ]);
        $this->getJson("/api/v1/choferes/{$driverId}")->assertNotFound();

        $this->postJson('/api/v1/choferes', [
            'nombre_completo' => 'Juan Perez actualizado',
            'tipo_documento' => 'CC',
            'numero_documento' => '10203040',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $driverId)
            ->assertJsonPath('data.nombre_completo', 'JUAN PEREZ ACTUALIZADO');
    }

    public function test_driver_document_fields_are_optional_as_a_pair_and_unique(): void
    {
        $this->postJson('/api/v1/choferes', [
            'nombre_completo' => 'Documento incompleto',
            'tipo_documento' => 'CC',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');

        $this->postJson('/api/v1/choferes', [
            'nombre_completo' => 'Primer chofer',
            'tipo_documento' => 'CC',
            'numero_documento' => '12345678',
        ])->assertCreated();

        $this->postJson('/api/v1/choferes', [
            'nombre_completo' => 'Segundo chofer',
            'tipo_documento' => 'CC',
            'numero_documento' => '12345678',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');
    }

    public function test_company_cannot_access_another_company_fleet(): void
    {
        $otherUser = User::factory()->create();
        $otherTruck = Vehiculo::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'placa' => 'OTR-001',
            'es_propio' => true,
            'estado' => Vehiculo::STATUS_ACTIVE,
        ]);
        $otherDriver = Conductor::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'nombre_completo' => 'OTRO CHOFER',
            'estado' => Conductor::STATUS_ACTIVE,
        ]);

        $this->getJson("/api/v1/camiones/{$otherTruck->id}")->assertNotFound();
        $this->getJson("/api/v1/choferes/{$otherDriver->id}")->assertNotFound();
    }
}
