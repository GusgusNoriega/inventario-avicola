<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\TerceroDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function test_client_hen_prices_are_optional_and_exposed_by_the_directory_api(): void
    {
        $payload = $this->payload();
        $payload['precios'][TipoPollo::HEN_RED] = 11.25;
        $payload['precios'][TipoPollo::HEN_DOUBLE] = 12.5;

        $this->postJson('/api/v1/clientes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.pricesKg.gallina_roja', 11.25)
            ->assertJsonPath('data.pricesKg.gallina_doble', 12.5);

        $this->assertDatabaseCount('precios_historial', 5);
        $this->assertDatabaseHas('precios_historial', [
            'tipo_pollo_id' => DB::table('tipos_pollo')
                ->where('codigo', TipoPollo::HEN_RED)
                ->value('id'),
            'precio_kg' => 11.25,
            'vigente_hasta' => null,
        ]);
    }

    public function test_directory_service_rejects_special_prices_when_form_requests_are_bypassed(): void
    {
        $service = app(TerceroDirectoryService::class);

        foreach (TipoPollo::wholesaleTwoManualPriceCodes() as $index => $code) {
            $submittedCode = $index === 0 ? mb_strtolower($code, 'UTF-8') : $code;
            $document = '201234568'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $directoryRole = $index === 1 ? TerceroRole::PROVIDER : TerceroRole::CLIENT;
            $payload = $this->payload([
                'numero_documento' => $document,
                'precios' => [$submittedCode => 12.5],
            ]);

            $this->assertDirectoryValidationError(
                fn () => $service->create(
                    (int) $this->user->empresa_id,
                    (int) $this->user->id,
                    $directoryRole,
                    $payload,
                ),
                'precios',
            );

            $this->assertDatabaseMissing('terceros', ['numero_documento' => $document]);
        }

        $this->assertDatabaseCount('listas_precios', 0);
        $this->assertDatabaseCount('precios_historial', 0);
    }

    public function test_directory_service_allows_syncing_an_optional_client_hen_price(): void
    {
        $recordId = (int) $this->postJson('/api/v1/clientes', $this->payload())
            ->assertCreated()
            ->json('data.id');
        $service = app(TerceroDirectoryService::class);
        $record = Tercero::query()->findOrFail($recordId);
        $payload = $this->payload([
            'nombre_razon_social' => 'Cliente con gallina',
        ]);
        $payload['precios'][TipoPollo::HEN_DOUBLE] = 12.5;
        $updated = $service->update(
            $record,
            (int) $this->user->id,
            TerceroRole::CLIENT,
            $payload,
        );

        $this->assertSame('CLIENTE CON GALLINA', $updated->nombre_razon_social);
        $this->assertDatabaseCount('precios_historial', 4);
        $this->assertDatabaseHas('precios_historial', [
            'tipo_pollo_id' => DB::table('tipos_pollo')
                ->where('codigo', TipoPollo::HEN_DOUBLE)
                ->value('id'),
            'precio_kg' => 12.5,
            'vigente_hasta' => null,
        ]);
    }

    public function test_global_hen_adjustments_change_only_clients_with_that_optional_price(): void
    {
        $pricedPayload = $this->payload();
        $pricedPayload['precios'][TipoPollo::HEN_RED] = 10;
        $pricedClientId = (int) $this->postJson('/api/v1/clientes', $pricedPayload)
            ->assertCreated()
            ->json('data.id');
        $this->postJson('/api/v1/clientes', $this->payload([
            'nombre_razon_social' => 'Cliente sin precio de gallina',
            'numero_documento' => '20456789012',
        ]))->assertCreated();

        $this->patchJson('/api/v1/clientes/precios/ajuste-global', [
            'tipo_pollo' => TipoPollo::HEN_RED,
            'monto' => 1.5,
            'direccion' => 'AUMENTAR',
        ])->assertOk()->assertJsonPath('affected', 1);
        $this->patchJson('/api/v1/clientes/precios/ajuste-global', [
            'tipo_pollo' => TipoPollo::HEN_RED,
            'monto' => 0.25,
            'direccion' => 'DISMINUIR',
        ])->assertOk()->assertJsonPath('affected', 1);

        $listId = DB::table('listas_precios')
            ->where('tercero_id', $pricedClientId)
            ->where('operacion', 'VENTA')
            ->value('id');
        $henTypeId = DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::HEN_RED)
            ->value('id');
        $this->assertDatabaseHas('precios_historial', [
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $henTypeId,
            'precio_kg' => 11.25,
            'vigente_hasta' => null,
        ]);
        $this->assertSame(1, DB::table('precios_historial')
            ->where('tipo_pollo_id', $henTypeId)
            ->whereNull('vigente_hasta')
            ->count());
    }

    public function test_directory_price_adjustment_rejects_special_products_without_deleting_history(): void
    {
        $recordId = (int) $this->postJson('/api/v1/clientes', $this->payload())
            ->assertCreated()
            ->json('data.id');
        $listId = (int) DB::table('listas_precios')
            ->where('tercero_id', $recordId)
            ->where('operacion', 'VENTA')
            ->value('id');
        $specialTypeId = (int) DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::OTHER)
            ->value('id');
        $historicalPriceId = (int) DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $specialTypeId,
            'precio_kg' => 4,
            'vigente_desde' => now()->subDay(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Registro histórico anterior a la defensa de dominio',
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
        $service = app(TerceroDirectoryService::class);

        $this->assertDirectoryValidationError(
            fn () => $service->adjustPrices(
                (int) $this->user->empresa_id,
                (int) $this->user->id,
                TerceroRole::CLIENT,
                TipoPollo::OTHER,
                1,
                'AUMENTAR',
            ),
            'tipo_pollo',
        );

        $this->assertDatabaseCount('precios_historial', 4);
        $this->assertDatabaseHas('precios_historial', [
            'id' => $historicalPriceId,
            'tipo_pollo_id' => $specialTypeId,
            'precio_kg' => 4,
            'vigente_hasta' => null,
        ]);
        $loaded = $service->loadForDirectory(
            Tercero::query()->findOrFail($recordId),
            TerceroRole::CLIENT,
        );
        $this->assertTrue(
            $loaded->listasPrecios
                ->flatMap->preciosVigentes
                ->contains('tipo_pollo_id', $specialTypeId)
        );
    }

    public function test_client_can_be_created_without_specific_prices(): void
    {
        $payload = $this->payload();
        unset($payload['precios']);

        $this->postJson('/api/v1/clientes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.pricesKg.pollo_vivo', null)
            ->assertJsonPath('data.pricesKg.pollo_pelado', null)
            ->assertJsonPath('data.pricesKg.pollo_beneficiado', null)
            ->assertJsonPath('data.pricesKg.gallina_roja', null)
            ->assertJsonPath('data.pricesKg.gallina_doble', null);

        $this->assertDatabaseCount('listas_precios', 0);
        $this->assertDatabaseCount('precios_historial', 0);
    }

    public function test_client_can_be_marked_as_internal(): void
    {
        $this->postJson('/api/v1/clientes', $this->payload([
            'es_cliente_interno' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.es_cliente_interno', true);

        $this->assertDatabaseHas('terceros', [
            'numero_documento' => '20123456789',
            'es_cliente_interno' => true,
        ]);
    }

    public function test_provider_cannot_be_marked_as_internal_client(): void
    {
        $this->postJson('/api/v1/proveedores', $this->payload([
            'es_cliente_interno' => true,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('es_cliente_interno');
    }

    public function test_provider_still_requires_all_specific_prices(): void
    {
        $payload = $this->payload();
        unset($payload['precios']);

        $this->postJson('/api/v1/proveedores', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('precios');
    }

    public function test_provider_cannot_receive_client_only_hen_prices(): void
    {
        $payload = $this->payload();
        $payload['precios'][TipoPollo::HEN_RED] = 11;

        $this->postJson('/api/v1/proveedores', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'precios',
                'precios.'.TipoPollo::HEN_RED,
            ]);
    }

    public function test_client_specific_price_can_be_cleared_to_use_the_global_price(): void
    {
        $recordId = $this->postJson('/api/v1/clientes', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/clientes/{$recordId}", $this->payload([
            'precios' => [
                TipoPollo::CHICKEN_LIVE => null,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('data.pricesKg.pollo_vivo', null)
            ->assertJsonPath('data.pricesKg.pollo_pelado', 9.5);

        $liveTypeId = DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::CHICKEN_LIVE)
            ->value('id');
        $this->assertDatabaseMissing('precios_historial', [
            'tipo_pollo_id' => $liveTypeId,
            'vigente_hasta' => null,
        ]);
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

    private function assertDirectoryValidationError(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Se esperaba un error de validación en {$field}.");
    }
}
