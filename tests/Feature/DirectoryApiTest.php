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

class DirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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

        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_client_is_created_with_role_and_current_prices(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'COMERCIAL EL SOL')
            ->assertJsonPath('data.dni', '20123456789')
            ->assertJsonPath('data.pricesKg.pollo_vivo', 8.5);

        $this->assertDatabaseHas('terceros', [
            'empresa_id' => $this->user->empresa_id,
            'numero_documento' => '20123456789',
            'tipo_documento' => 'RUC',
            'estado' => 'ACTIVO',
        ]);
        $this->assertDatabaseHas('tercero_roles', ['rol' => 'CLIENTE']);
        $this->assertDatabaseHas('listas_precios', ['operacion' => 'VENTA']);
        $this->assertDatabaseCount('precios_historial', 3);
    }

    public function test_existing_third_party_can_also_become_a_provider(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload())->assertCreated();
        $this->postJson('/api/v1/proveedores', $this->payload([
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 7.8,
                TipoPollo::CHICKEN_DRESSED => 8.8,
                TipoPollo::CHICKEN_PROCESSED => 9.8,
            ],
        ]))->assertCreated();

        $this->assertDatabaseCount('terceros', 1);
        $this->assertDatabaseCount('tercero_roles', 2);
        $this->assertDatabaseCount('listas_precios', 2);
        $this->assertDatabaseCount('precios_historial', 6);
    }

    public function test_search_filters_by_name_or_document(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload())->assertCreated();
        $this->postJson('/api/v1/clientes', $this->payload([
            'nombre_razon_social' => 'Mercado Central',
            'numero_documento' => '10456789012',
        ]))->assertCreated();

        $this->getJson('/api/v1/clientes?buscar=Sol')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dni', '20123456789');

        $this->getJson('/api/v1/clientes?buscar=104567')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'MERCADO CENTRAL');
    }

    public function test_updating_a_price_creates_a_new_history_version(): void
    {
        $recordId = $this->postJson('/api/v1/clientes', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/clientes/{$recordId}", $this->payload([
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 9.25,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('data.pricesKg.pollo_vivo', 9.25);

        $this->assertDatabaseCount('precios_historial', 4);
        $this->assertSame(
            3,
            DB::table('precios_historial')->whereNull('vigente_hasta')->count()
        );
    }

    public function test_same_role_cannot_be_registered_twice_for_one_document(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload())->assertCreated();

        $this->postJson('/api/v1/clientes', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');
    }

    public function test_name_is_always_stored_in_uppercase(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload([
            'nombre_razon_social' => 'Comercial Águila del Norte',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'COMERCIAL ÁGUILA DEL NORTE');

        $this->assertDatabaseHas('terceros', [
            'nombre_razon_social' => 'COMERCIAL ÁGUILA DEL NORTE',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'nombre_razon_social' => 'Comercial El Sol',
            'numero_documento' => '20123456789',
            'direccion' => 'Av. Principal 123',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 8.5,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ], $overrides);
    }
}
