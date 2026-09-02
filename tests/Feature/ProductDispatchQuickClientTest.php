<?php

namespace Tests\Feature;

use App\Models\ListaPrecio;
use App\Models\PrecioHistorial;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchQuickClientTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->grantModules($this->user, ['MODULO_DESPACHO_PRODUCTOS']);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_it_creates_an_external_client_without_creating_prices(): void
    {
        $response = $this->postJson('/api/v1/despacho-productos/clientes', [
            'nombre_razon_social' => '  Comercial Águila del Norte  ',
            'numero_documento' => '20-123-456-789',
            'direccion' => '  Av. Principal 123  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Cliente externo registrado correctamente.')
            ->assertJsonPath('data.document_type', 'RUC')
            ->assertJsonPath('data.document', '20123456789')
            ->assertJsonPath('data.name', 'COMERCIAL ÁGUILA DEL NORTE')
            ->assertJsonPath('data.address', 'Av. Principal 123')
            ->assertJsonPath('data.is_external', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'document_type',
                    'document',
                    'name',
                    'address',
                    'created_at',
                    'is_external',
                ],
            ]);

        $this->assertArrayNotHasKey('pricesKg', $response->json('data'));
        $this->assertArrayNotHasKey('precios', $response->json('data'));

        $client = Tercero::query()
            ->where('empresa_id', $this->user->empresa_id)
            ->where('numero_documento', '20123456789')
            ->firstOrFail();

        $this->assertFalse((bool) $client->es_cliente_interno);
        $this->assertSame(Tercero::STATUS_ACTIVE, $client->estado);
        $this->assertDatabaseHas('tercero_roles', [
            'tercero_id' => $client->id,
            'rol' => TerceroRole::CLIENT,
        ]);
        $this->assertDatabaseCount('listas_precios', 0);
        $this->assertDatabaseCount('precios_historial', 0);
    }

    public function test_it_lists_and_searches_only_active_external_clients_from_its_company(): void
    {
        $sol = $this->createClient(
            (int) $this->user->empresa_id,
            'COMERCIAL EL SOL',
            '20111111111',
        );
        $luna = $this->createClient(
            (int) $this->user->empresa_id,
            'MERCADO LA LUNA',
            '20222222222',
        );
        $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE INTERNO',
            '20333333333',
            internal: true,
        );
        $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE INACTIVO',
            '20444444444',
            status: Tercero::STATUS_INACTIVE,
        );
        $otherCompanyUser = User::factory()->create();
        $this->createClient(
            (int) $otherCompanyUser->empresa_id,
            'CLIENTE DE OTRA EMPRESA',
            '20555555555',
        );

        $listing = $this->getJson('/api/v1/despacho-productos/clientes')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonCount(2, 'data');

        $listedIds = collect($listing->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$sol->id, $luna->id])->sort()->values()->all(), $listedIds);

        foreach ($listing->json('data') as $client) {
            $this->assertTrue($client['is_external']);
            $this->assertArrayNotHasKey('pricesKg', $client);
            $this->assertArrayNotHasKey('precios', $client);
        }

        $this->getJson('/api/v1/despacho-productos/clientes?buscar=111111')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sol->id)
            ->assertJsonPath('data.0.name', 'COMERCIAL EL SOL');

        $this->getJson('/api/v1/despacho-productos/clientes?buscar[]=sol')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buscar');
    }

    public function test_create_rejects_prices_internal_classification_and_invalid_basic_data(): void
    {
        $this->postJson('/api/v1/despacho-productos/clientes', [
            ...$this->payload(),
            'precios' => [TipoPollo::CHICKEN_LIVE => 8.50],
            'es_cliente_interno' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['precios', 'es_cliente_interno']);

        $this->postJson('/api/v1/despacho-productos/clientes', [
            'nombre_razon_social' => [],
            'numero_documento' => [],
            'direccion' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre_razon_social',
                'numero_documento',
                'direccion',
            ]);

        $this->postJson('/api/v1/despacho-productos/clientes', [
            'nombre_razon_social' => '',
            'numero_documento' => '1234567',
            'direccion' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nombre_razon_social',
                'numero_documento',
                'direccion',
            ]);

        $this->assertDatabaseCount('terceros', 0);
        $this->assertDatabaseCount('listas_precios', 0);
        $this->assertDatabaseCount('precios_historial', 0);
    }

    public function test_update_changes_only_basic_data_and_preserves_existing_price_history(): void
    {
        $client = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE CON PRECIO',
            '20123456789',
        );
        [$priceList, $price] = $this->createSalePrice($client, 8.75);
        $priceSnapshot = DB::table('precios_historial')->where('id', $price->id)->first();

        $response = $this->putJson(
            "/api/v1/despacho-productos/clientes/{$client->id}",
            [
                'nombre_razon_social' => '  Cliente actualizado  ',
                'numero_documento' => '12 345 678',
                'direccion' => '  Jr. Nuevo 456  ',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Cliente externo actualizado correctamente.')
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.document_type', 'DNI')
            ->assertJsonPath('data.document', '12345678')
            ->assertJsonPath('data.name', 'CLIENTE ACTUALIZADO')
            ->assertJsonPath('data.address', 'Jr. Nuevo 456')
            ->assertJsonPath('data.is_external', true);

        $this->assertDatabaseHas('terceros', [
            'id' => $client->id,
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'nombre_razon_social' => 'CLIENTE ACTUALIZADO',
            'direccion' => 'Jr. Nuevo 456',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('listas_precios', 1);
        $this->assertDatabaseHas('listas_precios', [
            'id' => $priceList->id,
            'tercero_id' => $client->id,
            'operacion' => ListaPrecio::OPERATION_SALE,
            'estado' => ListaPrecio::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('precios_historial', 1);
        $this->assertEquals(
            $priceSnapshot,
            DB::table('precios_historial')->where('id', $price->id)->first(),
        );
    }

    public function test_update_rejects_prices_internal_classification_and_duplicate_documents(): void
    {
        $client = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE UNO',
            '20111111111',
        );
        $duplicate = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE DOS',
            '20222222222',
        );

        $this->putJson(
            "/api/v1/despacho-productos/clientes/{$client->id}",
            [
                ...$this->payload(),
                'precios' => [TipoPollo::CHICKEN_LIVE => 9.25],
                'es_cliente_interno' => true,
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['precios', 'es_cliente_interno']);

        $this->putJson(
            "/api/v1/despacho-productos/clientes/{$client->id}",
            $this->payload([
                'numero_documento' => $duplicate->numero_documento,
            ]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');

        $this->postJson(
            '/api/v1/despacho-productos/clientes',
            $this->payload(['numero_documento' => $client->numero_documento]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');

        $this->assertDatabaseHas('terceros', [
            'id' => $client->id,
            'nombre_razon_social' => 'CLIENTE UNO',
            'numero_documento' => '20111111111',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('listas_precios', 0);
        $this->assertDatabaseCount('precios_historial', 0);
    }

    public function test_update_and_delete_cannot_target_internal_or_other_company_clients(): void
    {
        $internal = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE INTERNO',
            '20111111111',
            internal: true,
        );
        $otherCompanyUser = User::factory()->create();
        $foreign = $this->createClient(
            (int) $otherCompanyUser->empresa_id,
            'CLIENTE AJENO',
            '20222222222',
        );

        foreach ([$internal, $foreign] as $protectedClient) {
            $this->putJson(
                "/api/v1/despacho-productos/clientes/{$protectedClient->id}",
                $this->payload(),
            )->assertNotFound();

            $this->deleteJson(
                "/api/v1/despacho-productos/clientes/{$protectedClient->id}",
            )->assertNotFound();
        }

        $this->assertDatabaseHas('terceros', [
            'id' => $internal->id,
            'nombre_razon_social' => 'CLIENTE INTERNO',
            'es_cliente_interno' => true,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('terceros', [
            'id' => $foreign->id,
            'empresa_id' => $otherCompanyUser->empresa_id,
            'nombre_razon_social' => 'CLIENTE AJENO',
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
    }

    public function test_delete_needs_no_reason_and_soft_deactivates_a_client(): void
    {
        $client = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE PARA ELIMINAR',
            '20123456789',
        );
        [$priceList, $price] = $this->createSalePrice($client, 8.75);

        $this->deleteJson("/api/v1/despacho-productos/clientes/{$client->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Cliente externo eliminado correctamente.');

        $this->assertDatabaseHas('terceros', [
            'id' => $client->id,
            'estado' => Tercero::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('tercero_roles', [
            'tercero_id' => $client->id,
            'rol' => TerceroRole::CLIENT,
        ]);
        $this->assertDatabaseHas('listas_precios', [
            'id' => $priceList->id,
            'estado' => ListaPrecio::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('precios_historial', [
            'id' => $price->id,
            'precio_kg' => 8.75,
            'vigente_hasta' => null,
        ]);

        $this->getJson('/api/v1/despacho-productos/clientes')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_delete_removes_only_the_client_role_from_a_dual_role_third_party(): void
    {
        $client = $this->createClient(
            (int) $this->user->empresa_id,
            'CLIENTE Y PROVEEDOR',
            '20123456789',
        );
        $client->roles()->create(['rol' => TerceroRole::PROVIDER]);
        [$saleList] = $this->createSalePrice($client, 8.75);
        $purchaseList = ListaPrecio::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'codigo' => "PROVEEDOR-{$client->id}-COMPRA",
            'nombre' => 'Compra - Cliente y proveedor',
            'operacion' => ListaPrecio::OPERATION_PURCHASE,
            'estado' => ListaPrecio::STATUS_ACTIVE,
            'created_by' => $this->user->id,
        ]);

        $this->deleteJson("/api/v1/despacho-productos/clientes/{$client->id}")
            ->assertOk();

        $this->assertDatabaseHas('terceros', [
            'id' => $client->id,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('tercero_roles', [
            'tercero_id' => $client->id,
            'rol' => TerceroRole::CLIENT,
        ]);
        $this->assertDatabaseHas('tercero_roles', [
            'tercero_id' => $client->id,
            'rol' => TerceroRole::PROVIDER,
        ]);
        $this->assertDatabaseHas('listas_precios', [
            'id' => $saleList->id,
            'estado' => ListaPrecio::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('listas_precios', [
            'id' => $purchaseList->id,
            'estado' => ListaPrecio::STATUS_ACTIVE,
        ]);
    }

    public function test_quick_registration_is_visible_in_the_dispatch_catalog_but_does_not_grant_directory_access(): void
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $branchId]);

        $clientId = (int) $this->postJson(
            '/api/v1/despacho-productos/clientes',
            $this->payload(),
        )->assertCreated()->json('data.id');

        $catalog = $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk();

        $this->assertTrue(
            collect($catalog->json('data.clients'))->contains(
                fn (array $client): bool => $client['id'] === $clientId
                    && $client['document'] === '20123456789'
                    && $client['name'] === 'COMERCIAL EL SOL',
            ),
        );

        $this->postJson('/api/v1/clientes', $this->payload())
            ->assertForbidden();
    }

    private function createClient(
        int $companyId,
        string $name,
        string $document,
        bool $internal = false,
        string $status = Tercero::STATUS_ACTIVE,
    ): Tercero {
        $client = Tercero::query()->create([
            'empresa_id' => $companyId,
            'tipo_documento' => strlen($document) === 11 ? 'RUC' : 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Prueba 123',
            'es_cliente_interno' => $internal,
            'estado' => $status,
        ]);
        $client->roles()->create(['rol' => TerceroRole::CLIENT]);

        return $client;
    }

    /** @return array{0: ListaPrecio, 1: PrecioHistorial} */
    private function createSalePrice(Tercero $client, float $amount): array
    {
        $type = TipoPollo::query()->firstOrCreate(
            ['codigo' => TipoPollo::CHICKEN_LIVE],
            [
                'nombre' => 'Pollo vivo',
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
            ],
        );
        $list = ListaPrecio::query()->create([
            'empresa_id' => $client->empresa_id,
            'tercero_id' => $client->id,
            'codigo' => "CLIENTE-{$client->id}-VENTA",
            'nombre' => "Venta - {$client->nombre_razon_social}",
            'operacion' => ListaPrecio::OPERATION_SALE,
            'estado' => ListaPrecio::STATUS_ACTIVE,
            'created_by' => $this->user->id,
        ]);
        $price = PrecioHistorial::query()->create([
            'lista_precio_id' => $list->id,
            'tipo_pollo_id' => $type->id,
            'precio_kg' => $amount,
            'vigente_desde' => now()->subDay()->startOfSecond(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Precio anterior al alta rápida',
            'registrado_por' => $this->user->id,
        ]);

        return [$list, $price];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'nombre_razon_social' => 'Comercial El Sol',
            'numero_documento' => '20123456789',
            'direccion' => 'Av. Principal 123',
        ], $overrides);
    }
}
