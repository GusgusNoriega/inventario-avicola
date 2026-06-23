<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JourneyPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $providerId;

    /**
     * @var array<int, int>
     */
    private array $providerVehicleIds;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-22 20:30:00');

        $this->user = User::factory()->create();
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update([
                'zona_horaria' => 'America/Bogota',
                'hora_corte_operativo' => '21:00:00',
            ]);
        $permissions = collect([
            ['DESPACHOS_VER', 'Ver despachos'],
            ['DESPACHOS_CREAR', 'Crear despachos'],
            ['PRECIOS_GESTIONAR', 'Gestionar precios'],
            ['TERCEROS_GESTIONAR', 'Gestionar terceros'],
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
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);

        Sanctum::actingAs($this->user, ['api']);

        $this->providerId = $this->postJson('/api/v1/proveedores', [
            'nombre_razon_social' => 'Proveedor con dos camiones',
            'numero_documento' => '20123456789',
            'direccion' => 'Calle 1',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 7.5,
                TipoPollo::CHICKEN_DRESSED => 8.5,
                TipoPollo::CHICKEN_PROCESSED => 9.5,
            ],
        ])->assertCreated()->json('data.id');

        $this->providerVehicleIds = [
            $this->postJson(
                "/api/v1/proveedores/{$this->providerId}/vehiculos",
                ['placa' => 'ABC-123']
            )->assertCreated()->json('data.id'),
            $this->postJson(
                "/api/v1/proveedores/{$this->providerId}/vehiculos",
                ['placa' => 'XYZ-999']
            )->assertCreated()->json('data.id'),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_catalog_repeats_provider_for_each_plate_and_uses_nine_pm_window(): void
    {
        $this->getJson('/api/v1/operacion/jornada')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.operating_date', '2026-06-22')
            ->assertJsonPath('data.starts_at', '2026-06-21T21:00:00-05:00')
            ->assertJsonPath('data.ends_at', '2026-06-22T21:00:00-05:00')
            ->assertJsonCount(2, 'data.trucks')
            ->assertJsonPath('data.trucks.0.provider_name', 'PROVEEDOR CON DOS CAMIONES')
            ->assertJsonPath('data.trucks.0.plate', 'ABC-123')
            ->assertJsonPath('data.trucks.1.provider_name', 'PROVEEDOR CON DOS CAMIONES')
            ->assertJsonPath('data.trucks.1.plate', 'XYZ-999')
            ->assertJsonPath('data.trucks.0.prices.POLLO_VIVO', 7.5);
    }

    public function test_selected_trucks_and_global_prices_are_persisted(): void
    {
        $this->putJson('/api/v1/operacion/jornada', [
            'provider_vehicle_ids' => [$this->providerVehicleIds[1]],
            'global_prices' => [
                TipoPollo::CHICKEN_LIVE => 7.75,
                TipoPollo::CHICKEN_DRESSED => 8.75,
                TipoPollo::CHICKEN_PROCESSED => 9.75,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.status', 'PUBLICADA')
            ->assertJsonPath('data.selected_count', 1)
            ->assertJsonPath('data.trucks.0.selected', false)
            ->assertJsonPath('data.trucks.1.selected', true)
            ->assertJsonPath('data.trucks.1.prices.POLLO_VIVO', 7.5)
            ->assertJsonPath('data.global_prices.POLLO_VIVO', 7.75)
            ->assertJsonPath('data.global_prices.POLLO_PELADO', 8.75)
            ->assertJsonPath('data.global_prices.POLLO_BENEFICIADO', 9.75);

        $this->assertDatabaseHas('programaciones_recepcion', [
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-06-22 00:00:00',
            'estado' => 'PUBLICADA',
        ]);
        $this->assertDatabaseHas('programacion_recepcion_detalles', [
            'proveedor_vehiculo_id' => $this->providerVehicleIds[1],
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('listas_precios', [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => null,
            'operacion' => 'VENTA',
            'codigo' => 'GENERAL-VENTA',
        ]);
        $this->assertDatabaseCount('precios_historial', 6);
    }

    public function test_after_nine_pm_the_plan_targets_the_next_operating_date(): void
    {
        Carbon::setTestNow('2026-06-22 21:05:00');

        $this->getJson('/api/v1/operacion/jornada')
            ->assertOk()
            ->assertJsonPath('data.operating_date', '2026-06-23')
            ->assertJsonPath('data.starts_at', '2026-06-22T21:00:00-05:00')
            ->assertJsonPath('data.ends_at', '2026-06-23T21:00:00-05:00');
    }
}
