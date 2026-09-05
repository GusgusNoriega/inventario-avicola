<?php

namespace Tests\Feature;

use App\Models\Balanza;
use App\Models\PesadaDespachoProducto;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchOperationApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    private ProductoDespacho $turkey;

    private VariacionProductoDespacho $largeTurkey;

    private ProductoDespacho $eggs;

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

        $this->grantModules($this->user, ['MODULO_DESPACHO_PRODUCTOS']);
        Sanctum::actingAs($this->user, ['api']);

        $this->clientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente avícola',
            '20123456789',
        );
        $this->turkey = $this->createProduct(
            'Pavo',
            ProductoDespacho::PRICE_MODE_KG,
            '18.0000',
            25,
        );
        $this->largeTurkey = $this->createVariation(
            $this->turkey,
            'Pavo grande',
            ProductoDespacho::PRICE_MODE_KG,
            '20.0000',
            30,
        );
        $this->eggs = $this->createProduct(
            'Huevo',
            ProductoDespacho::PRICE_MODE_UNIT,
            '0.7500',
            2,
        );
    }

    public function test_catalog_returns_only_active_company_products_variations_clients_and_the_own_scale(): void
    {
        $inactiveProduct = $this->createProduct(
            'Gallina inactiva',
            ProductoDespacho::PRICE_MODE_KG,
            '12.0000',
            10,
            ProductoDespacho::STATUS_INACTIVE,
        );
        $this->createVariation(
            $inactiveProduct,
            'Gallina roja',
            ProductoDespacho::PRICE_MODE_KG,
            '13.0000',
            12,
        );
        $this->createVariation(
            $this->turkey,
            'Pavo oculto',
            ProductoDespacho::PRICE_MODE_KG,
            '22.0000',
            35,
            VariacionProductoDespacho::STATUS_INACTIVE,
        );
        $inactiveClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente inactivo',
            '20999999991',
            Tercero::STATUS_INACTIVE,
        );

        $response = $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [0, 50, 100, 150])
            ->assertJsonPath('data.quick_product_ids', [$this->eggs->id, $this->turkey->id])
            ->assertJsonPath('data.quick_products_configured', false)
            ->assertJsonPath('data.scale.code', Balanza::CODE_PRODUCT_DISPATCH)
            ->assertJsonPath('data.scale.configuration.baudRate', 9600);

        $this->assertSame(
            [$this->eggs->id, $this->turkey->id],
            collect($response->json('data.quick_products'))->pluck('id')->all(),
        );

        $this->assertSame(
            collect([$this->eggs->id, $this->turkey->id])->sort()->values()->all(),
            collect($response->json('data.products'))->pluck('id')->sort()->values()->all(),
        );
        $turkeyCatalog = collect($response->json('data.products'))
            ->firstWhere('id', $this->turkey->id);
        $this->assertSame(
            [$this->largeTurkey->id],
            collect($turkeyCatalog['variations'])->pluck('id')->all(),
        );
        $this->assertContains($this->clientId, collect($response->json('data.clients'))->pluck('id')->all());
        $this->assertNotContains($inactiveClientId, collect($response->json('data.clients'))->pluck('id')->all());
    }

    public function test_waste_presets_are_configurable_and_scoped_by_company_and_branch(): void
    {
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [15, 40, 90, 125],
        ])
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [15, 40, 90, 125]);

        $this->assertDatabaseHas('configuraciones_despacho_productos', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'merma_preset_1_gramos_unidad' => 15,
            'merma_preset_2_gramos_unidad' => 40,
            'merma_preset_3_gramos_unidad' => 90,
            'merma_preset_4_gramos_unidad' => 125,
        ]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [15, 40, 90, 125]);

        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'SECUNDARIA',
            'nombre' => 'Sucursal secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $otherBranchId]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [0, 50, 100, 150]);

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [1, 2, 3],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('waste_presets');
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [0, -1, 1000001, 1.5],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'waste_presets.1',
                'waste_presets.2',
                'waste_presets.3',
            ]);
    }

    public function test_customer_display_title_uses_company_fallback_and_is_configurable_per_branch(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update([
                'nombre_comercial' => '  Avícola Central  ',
                'razon_social' => 'Avícola Central S.A.C.',
            ]);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'Avícola Central');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'customer_display_title' => '  La Central de los Pollos  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'La Central de los Pollos')
            ->assertJsonPath('data.waste_presets', [0, 50, 100, 150]);

        $this->assertDatabaseHas('configuraciones_despacho_productos', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'titulo_pantalla_cliente' => 'La Central de los Pollos',
        ]);

        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PANTALLA-SECUNDARIA',
            'nombre' => 'Sucursal pantalla secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $otherBranchId]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'Avícola Central');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'customer_display_title' => 'Sucursal Dos',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'Sucursal Dos');

        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'La Central de los Pollos');
    }

    public function test_customer_display_title_fallbacks_and_validation_are_enforced(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update([
                'nombre_comercial' => '   ',
                'razon_social' => '  Razón Avícola Legal  ',
            ]);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'Razón Avícola Legal');

        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['razon_social' => '   ']);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.customer_display_title', 'Despacho de productos');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'customer_display_title' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_display_title');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'customer_display_title' => str_repeat('T', 121),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_display_title');
    }

    public function test_product_ticket_title_is_configurable_per_branch_without_changing_other_ticket_titles(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update([
                'titulo_ticket' => 'TITULO GENERAL DE LA EMPRESA',
                'nombre_comercial' => 'Avicola Central',
                'razon_social' => 'Avicola Central S.A.C.',
            ]);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'TITULO GENERAL DE LA EMPRESA')
            ->assertJsonPath('data.ticket_title', 'TITULO GENERAL DE LA EMPRESA');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => '  PALACIO DE LOS POLLOS  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'PALACIO DE LOS POLLOS');

        $this->assertDatabaseHas('configuraciones_despacho_productos', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'titulo_ticket_despacho' => 'PALACIO DE LOS POLLOS',
        ]);
        $this->assertSame(
            'TITULO GENERAL DE LA EMPRESA',
            DB::table('empresas')
                ->where('id', $this->user->empresa_id)
                ->value('titulo_ticket'),
        );

        $this->grantModules(
            $this->user,
            ['MODULO_DESPACHO_MAYORISTA'],
            'ROL_MAYORISTA_AISLAMIENTO_TICKET',
        );
        $this->getJson('/api/v1/operacion/catalogo')
            ->assertOk()
            ->assertJsonPath('data.ticket_title', 'TITULO GENERAL DE LA EMPRESA');

        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'TICKET-SECUNDARIA',
            'nombre' => 'Sucursal ticket secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $otherBranchId]);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'TITULO GENERAL DE LA EMPRESA');
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => 'TITULO SUCURSAL DOS',
        ])
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'TITULO SUCURSAL DOS');

        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'PALACIO DE LOS POLLOS');
    }

    public function test_product_ticket_title_fallbacks_and_validation_are_enforced(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update([
                'titulo_ticket' => '   ',
                'nombre_comercial' => '  Avicola Comercial  ',
                'razon_social' => 'Avicola Legal S.A.C.',
            ]);

        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'Avicola Comercial');

        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['nombre_comercial' => '   ']);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'Avicola Legal S.A.C.');

        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['razon_social' => '   ']);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.product_ticket_title', 'DESPACHO DE PRODUCTOS');

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_ticket_title');
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => ['no', 'es', 'texto'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_ticket_title');
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => str_repeat('T', 181),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_ticket_title');
    }

    public function test_quick_products_are_ordered_configurable_and_scoped_by_company_and_branch(): void
    {
        $hen = $this->createProduct(
            'Gallina',
            ProductoDespacho::PRICE_MODE_KG,
            '12.0000',
            20,
        );
        $duck = $this->createProduct(
            'Pato',
            ProductoDespacho::PRICE_MODE_KG,
            '16.0000',
            15,
        );
        $chicken = $this->createProduct(
            'Pollo',
            ProductoDespacho::PRICE_MODE_KG,
            '11.0000',
            10,
        );
        $selection = [
            $this->turkey->id,
            $hen->id,
            $this->eggs->id,
            $duck->id,
        ];

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'quick_product_ids' => $selection,
        ])
            ->assertOk()
            ->assertJsonPath('data.quick_product_ids', $selection)
            ->assertJsonPath('data.quick_products_configured', true)
            ->assertJsonPath('data.waste_presets', [0, 50, 100, 150]);

        $this->assertDatabaseHas('configuraciones_despacho_productos', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'productos_rapidos_configurados' => true,
            'producto_rapido_1_id' => $selection[0],
            'producto_rapido_2_id' => $selection[1],
            'producto_rapido_3_id' => $selection[2],
            'producto_rapido_4_id' => $selection[3],
        ]);

        $catalog = $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.quick_product_ids', $selection)
            ->assertJsonPath('data.quick_products_configured', true);
        $this->assertSame(
            $selection,
            collect($catalog->json('data.quick_products'))->pluck('id')->all(),
        );

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [5, 10, 15, 20],
        ])
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [5, 10, 15, 20])
            ->assertJsonPath('data.quick_product_ids', $selection);

        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'RAPIDOS-SECUNDARIA',
            'nombre' => 'Sucursal rápida secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $otherBranchId]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.quick_product_ids', [
                $hen->id,
                $this->eggs->id,
                $duck->id,
                $this->turkey->id,
            ])
            ->assertJsonPath('data.quick_products_configured', false);

        $this->user->update(['sucursal_id' => $this->branchId]);
        $hen->update(['estado' => ProductoDespacho::STATUS_INACTIVE]);
        $effectiveSelection = [
            $this->turkey->id,
            $this->eggs->id,
            $duck->id,
            $chicken->id,
        ];
        $catalogAfterDeactivation = $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.quick_product_ids', $effectiveSelection);
        $this->assertSame(
            $effectiveSelection,
            collect($catalogAfterDeactivation->json('data.quick_products'))->pluck('id')->all(),
        );
    }

    public function test_quick_product_configuration_rejects_invalid_duplicate_inactive_and_foreign_products(): void
    {
        $hen = $this->createProduct(
            'Gallina rápida',
            ProductoDespacho::PRICE_MODE_KG,
            '12.0000',
            20,
        );
        $duck = $this->createProduct(
            'Pato rápido',
            ProductoDespacho::PRICE_MODE_KG,
            '16.0000',
            15,
        );
        $inactive = $this->createProduct(
            'Inactivo rápido',
            ProductoDespacho::PRICE_MODE_KG,
            '10.0000',
            10,
            ProductoDespacho::STATUS_INACTIVE,
        );

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'quick_product_ids' => [$this->eggs->id, $this->turkey->id, $hen->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quick_product_ids');

        $duplicateResponse = $this->putJson('/api/v1/despacho-productos/configuracion', [
            'quick_product_ids' => [$this->eggs->id, $this->turkey->id, $hen->id, $hen->id],
        ])->assertUnprocessable();
        $this->assertTrue(
            collect(array_keys($duplicateResponse->json('errors')))
                ->contains(fn (string $key): bool => str_starts_with($key, 'quick_product_ids.')),
        );

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'quick_product_ids' => [$this->eggs->id, $this->turkey->id, $hen->id, $inactive->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quick_product_ids.3');

        $foreignUser = User::factory()->create();
        $foreignProduct = ProductoDespacho::query()->create([
            'empresa_id' => $foreignUser->empresa_id,
            'nombre' => 'Producto ajeno',
            'nombre_normalizado' => 'producto ajeno',
            'descripcion' => null,
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_venta' => '10.0000',
            'merma_gramos_unidad' => 0,
            'imagen_path' => null,
            'estado' => ProductoDespacho::STATUS_ACTIVE,
            'created_by' => $foreignUser->id,
            'updated_by' => $foreignUser->id,
        ]);
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'quick_product_ids' => [$this->eggs->id, $this->turkey->id, $duck->id, $foreignProduct->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quick_product_ids.3');

        $this->putJson('/api/v1/despacho-productos/configuracion', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration');
    }

    public function test_registered_client_ticket_uses_snapshots_and_authoritative_kg_and_unit_calculations(): void
    {
        $payload = $this->payload($this->clientId, [
            $this->weighing(
                product: $this->turkey,
                variation: $this->largeTurkey,
                quantity: 2,
                price: '21.00',
                readWeight: '10.000',
                waste: 100,
            ),
            $this->weighing(
                product: $this->eggs,
                quantity: 12,
                price: '0.75',
                readWeight: '2.000',
                waste: 24,
            ),
        ]);

        $response = $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.customer_type', TicketDespachoProducto::CUSTOMER_REGISTERED)
            ->assertJsonPath('data.client.id', $this->clientId)
            ->assertJsonPath('data.totals.quantity', 14)
            ->assertJsonPath('data.totals.waste_grams', 124)
            ->assertJsonPath('data.totals.tare_grams', 0)
            ->assertJsonPath('data.weighings.0.price_mode', ProductoDespacho::PRICE_MODE_KG)
            ->assertJsonPath('data.weighings.0.price_origin', PesadaDespachoProducto::PRICE_MANUAL)
            ->assertJsonPath('data.weighings.0.waste_grams_per_unit', 50)
            ->assertJsonPath('data.weighings.0.tare_grams', 0)
            ->assertJsonPath('data.weighings.1.price_mode', ProductoDespacho::PRICE_MODE_UNIT)
            ->assertJsonPath('data.weighings.1.price_origin', PesadaDespachoProducto::PRICE_CATALOG);

        $this->assertEqualsWithDelta(12, $response->json('data.totals.read_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(12.124, $response->json('data.totals.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(221.1, $response->json('data.totals.amount'), 0.001);
        $this->assertEqualsWithDelta(20, $response->json('data.weighings.0.catalog_price'), 0.0001);
        $this->assertEqualsWithDelta(21, $response->json('data.weighings.0.unit_price'), 0.0001);
        $this->assertEqualsWithDelta(10.1, $response->json('data.weighings.0.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(212.1, $response->json('data.weighings.0.amount'), 0.001);
        $this->assertEqualsWithDelta(2.024, $response->json('data.weighings.1.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(9, $response->json('data.weighings.1.amount'), 0.001);

        $ticketId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'referencia_externa' => $payload['draft_id'],
            'cliente_id' => $this->clientId,
            'tipo_cliente' => TicketDespachoProducto::CUSTOMER_REGISTERED,
            'cliente_nombre_snapshot' => 'Cliente avícola',
            'cantidad_total' => 14,
            'peso_leido_total_kg' => 12,
            'merma_total_gramos' => 124,
            'tara_total_gramos' => 0,
            'peso_neto_total_kg' => 12.124,
            'total' => 221.10,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'numero' => 1,
            'producto_despacho_id' => $this->turkey->id,
            'variacion_producto_despacho_id' => $this->largeTurkey->id,
            'producto_nombre_snapshot' => 'Pavo',
            'variacion_nombre_snapshot' => 'Pavo grande',
            'precio_catalogo_snapshot' => 20,
            'precio_venta_snapshot' => 21,
            'origen_precio' => PesadaDespachoProducto::PRICE_MANUAL,
            'merma_catalogo_gramos_unidad' => 30,
            'merma_aplicada_gramos_unidad' => 50,
            'merma_total_gramos' => 100,
            'tara_gramos' => 0,
            'peso_neto_kg' => 10.1,
            'importe' => 212.1,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'numero' => 2,
            'producto_despacho_id' => $this->eggs->id,
            'variacion_producto_despacho_id' => null,
            'modo_precio_snapshot' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_venta_snapshot' => 0.75,
            'importe' => 9,
        ]);

        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticketId}")
            ->value('id');
        $this->assertGreaterThan(0, $documentId);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'tercero_id' => $this->clientId,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'total' => 221.10,
            'saldo_pendiente' => 221.10,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->turkey->id,
            'variacion_producto_despacho_id' => $this->largeTurkey->id,
            'cantidad_unidades' => 2,
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_kg' => 21,
            'subtotal' => 212.1,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->eggs->id,
            'cantidad_unidades' => 12,
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_unitario' => 0.75,
            'subtotal' => 9,
        ]);
        $this->assertDatabaseHas('comprobante_tickets_despacho_productos', [
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticketId,
            'importe_aplicado' => 221.1,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'usuario_id' => $this->user->id,
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticketId,
            'accion' => 'REGISTRAR',
        ]);
        $this->assertDatabaseCount('lecturas_balanza', 0);
    }

    public function test_kg_weighing_accepts_zero_quantity_and_rejects_negative_quantity(): void
    {
        $created = $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [
            $this->weighing(
                product: $this->turkey,
                variation: $this->largeTurkey,
                quantity: 0,
                price: '20.00',
                readWeight: '5.250',
                waste: 0,
            ),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.totals.quantity', 0)
            ->assertJsonPath('data.weighings.0.quantity', 0)
            ->assertJsonPath('data.weighings.0.waste_total_grams', 0)
            ->assertJsonPath('data.weighings.0.tare_grams', 0);

        $this->assertEqualsWithDelta(5.25, $created->json('data.weighings.0.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(105, $created->json('data.weighings.0.amount'), 0.001);
        $ticket = $created->json('data');
        $ticketId = (int) $ticket['id'];

        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'cantidad' => 0,
            'peso_leido_kg' => 5.25,
            'peso_neto_kg' => 5.25,
            'merma_total_gramos' => 0,
            'tara_gramos' => 0,
            'importe' => 105,
        ]);

        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticketId}",
            $this->updatePayload($ticket),
        )
            ->assertOk()
            ->assertJsonPath('data.totals.quantity', 0)
            ->assertJsonPath('data.weighings.0.quantity', 0);

        $negative = $this->weighing(
            product: $this->turkey,
            variation: $this->largeTurkey,
            quantity: -1,
            price: '20.00',
            readWeight: '5.250',
            waste: 0,
        );
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$negative]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.quantity');
    }

    public function test_ticket_keeps_its_list_number_and_own_title_snapshot_when_configuration_changes(): void
    {
        DB::table('empresas')->where('id', $this->user->empresa_id)->update([
            'mensaje_ticket' => 'Mensaje original del despacho',
        ]);
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => 'CONTROL DE DESPACHO ORIGINAL',
        ])->assertOk();

        $payload = $this->payload(null, [
            $this->weighing(product: $this->eggs, quantity: 2),
        ], 4);
        $created = $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.list_number', 4)
            ->assertJsonPath('data.product_ticket_title', 'CONTROL DE DESPACHO ORIGINAL')
            ->assertJsonPath('data.ticket_title', 'CONTROL DE DESPACHO ORIGINAL')
            ->assertJsonPath('data.ticket_message', 'Mensaje original del despacho');
        $ticketId = (int) $created->json('data.id');

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'numero_lista' => 4,
            'titulo_ticket_snapshot' => 'CONTROL DE DESPACHO ORIGINAL',
            'mensaje_ticket_snapshot' => 'Mensaje original del despacho',
        ]);

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'product_ticket_title' => 'CONTROL DE DESPACHO NUEVO',
        ])->assertOk();
        DB::table('empresas')->where('id', $this->user->empresa_id)->update([
            'mensaje_ticket' => 'Mensaje nuevo de la empresa',
        ]);

        $this->getJson("/api/v1/despacho-productos/tickets/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('data.list_number', 4)
            ->assertJsonPath('data.product_ticket_title', 'CONTROL DE DESPACHO ORIGINAL')
            ->assertJsonPath('data.ticket_title', 'CONTROL DE DESPACHO ORIGINAL')
            ->assertJsonPath('data.ticket_message', 'Mensaje original del despacho');

        $retryPayload = $payload;
        $retryPayload['list_number'] = 7;
        $this->postJson('/api/v1/despacho-productos/tickets', $retryPayload)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('data.id', $ticketId)
            ->assertJsonPath('data.list_number', 4)
            ->assertJsonPath('data.product_ticket_title', 'CONTROL DE DESPACHO ORIGINAL')
            ->assertJsonPath('data.ticket_message', 'Mensaje original del despacho');

        $newTicket = $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [
            $this->weighing(product: $this->eggs),
        ], 8))
            ->assertCreated()
            ->assertJsonPath('data.list_number', 8)
            ->assertJsonPath('data.product_ticket_title', 'CONTROL DE DESPACHO NUEVO')
            ->assertJsonPath('data.ticket_message', 'Mensaje nuevo de la empresa');
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => (int) $newTicket->json('data.id'),
            'numero_lista' => 8,
            'titulo_ticket_snapshot' => 'CONTROL DE DESPACHO NUEVO',
            'mensaje_ticket_snapshot' => 'Mensaje nuevo de la empresa',
        ]);
    }

    public function test_list_number_defaults_to_one_and_rejects_values_outside_the_visible_lists(): void
    {
        $withoutList = $this->payload(null, [
            $this->weighing(product: $this->eggs),
        ]);
        unset($withoutList['list_number']);

        $created = $this->postJson('/api/v1/despacho-productos/tickets', $withoutList)
            ->assertCreated()
            ->assertJsonPath('data.list_number', 1);
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => (int) $created->json('data.id'),
            'numero_lista' => 1,
        ]);

        foreach ([0, 9] as $invalidListNumber) {
            $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [
                $this->weighing(product: $this->eggs),
            ], $invalidListNumber))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('list_number');
        }

        $this->assertDatabaseCount('tickets_despacho_productos', 1);
    }

    public function test_same_product_weighings_keep_independent_prices_and_sum_their_amounts(): void
    {
        $payload = $this->payload($this->clientId, [
            $this->weighing(
                product: $this->eggs,
                quantity: 2,
                price: '0.75',
                readWeight: '1.000',
            ),
            $this->weighing(
                product: $this->eggs,
                quantity: 3,
                price: '0.90',
                readWeight: '1.000',
            ),
        ]);

        $response = $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.price_origin', PesadaDespachoProducto::PRICE_CATALOG)
            ->assertJsonPath('data.weighings.1.price_origin', PesadaDespachoProducto::PRICE_MANUAL);

        $this->assertEqualsWithDelta(0.75, $response->json('data.weighings.0.unit_price'), 0.0001);
        $this->assertEqualsWithDelta(1.50, $response->json('data.weighings.0.amount'), 0.001);
        $this->assertEqualsWithDelta(0.90, $response->json('data.weighings.1.unit_price'), 0.0001);
        $this->assertEqualsWithDelta(2.70, $response->json('data.weighings.1.amount'), 0.001);
        $this->assertEqualsWithDelta(4.20, $response->json('data.totals.amount'), 0.001);

        $ticketId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'subtotal' => 4.20,
            'total' => 4.20,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'numero' => 1,
            'producto_despacho_id' => $this->eggs->id,
            'precio_catalogo_snapshot' => 0.75,
            'precio_venta_snapshot' => 0.75,
            'origen_precio' => PesadaDespachoProducto::PRICE_CATALOG,
            'importe' => 1.50,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'numero' => 2,
            'producto_despacho_id' => $this->eggs->id,
            'precio_catalogo_snapshot' => 0.75,
            'precio_venta_snapshot' => 0.90,
            'origen_precio' => PesadaDespachoProducto::PRICE_MANUAL,
            'importe' => 2.70,
        ]);

        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticketId}")
            ->value('id');
        $this->assertGreaterThan(0, $documentId);
        $this->assertDatabaseCount('comprobante_detalles', 2);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->eggs->id,
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_unitario' => 0.75,
            'subtotal' => 1.50,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->eggs->id,
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_unitario' => 0.90,
            'subtotal' => 2.70,
        ]);
    }

    public function test_ticket_without_a_client_is_an_explicit_public_sale_and_keeps_a_financial_document(): void
    {
        $payload = $this->payload(null, [
            $this->weighing(
                product: $this->eggs,
                quantity: 6,
                price: '0.80',
                readWeight: '1.000',
                waste: 12,
            ),
        ]);

        $response = $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.customer_type', TicketDespachoProducto::CUSTOMER_PUBLIC)
            ->assertJsonPath('data.customer_label', TicketDespachoProducto::PUBLIC_SALE_LABEL);
        $this->assertEqualsWithDelta(4.8, $response->json('data.totals.amount'), 0.001);
        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'cliente_id' => null,
            'tipo_cliente' => TicketDespachoProducto::CUSTOMER_PUBLIC,
            'cliente_nombre_snapshot' => TicketDespachoProducto::PUBLIC_SALE_LABEL,
            'total' => 4.8,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'tercero_id' => null,
            'contraparte_nombre_snapshot' => TicketDespachoProducto::PUBLIC_SALE_LABEL,
            'total' => 4.8,
            'saldo_pendiente' => 4.8,
        ]);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('comprobante_detalles', 1);
    }

    public function test_unit_waste_is_added_before_tare_is_subtracted_from_net_weight(): void
    {
        $weighing = $this->weighing(
            product: $this->turkey,
            quantity: 2,
            price: '20.00',
            readWeight: '10.000',
            waste: 100,
        );
        $weighing['waste_grams_per_unit'] = 50;
        $weighing['tare_grams'] = 250;

        $response = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload(null, [$weighing]),
        )
            ->assertCreated()
            ->assertJsonPath('data.totals.waste_grams', 100)
            ->assertJsonPath('data.totals.tare_grams', 250)
            ->assertJsonPath('data.weighings.0.waste_grams_per_unit', 50)
            ->assertJsonPath('data.weighings.0.waste_total_grams', 100)
            ->assertJsonPath('data.weighings.0.tare_grams', 250);

        $this->assertEqualsWithDelta(9.85, $response->json('data.weighings.0.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(197, $response->json('data.weighings.0.amount'), 0.001);
        $this->assertEqualsWithDelta(9.85, $response->json('data.totals.net_weight_kg'), 0.0001);

        $ticketId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'merma_total_gramos' => 100,
            'tara_total_gramos' => 250,
            'peso_neto_total_kg' => 9.85,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'merma_catalogo_gramos_unidad' => 25,
            'merma_aplicada_gramos_unidad' => 50,
            'merma_total_gramos' => 100,
            'tara_gramos' => 250,
            'peso_neto_kg' => 9.85,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'producto_despacho_id' => $this->turkey->id,
            'peso_neto_kg' => 9.85,
            'subtotal' => 197,
        ]);
    }

    public function test_physical_weight_keeps_the_raw_scale_evidence(): void
    {
        $capturedAt = now('America/Lima')->subSeconds(30)->toIso8601String();
        $payload = $this->payload($this->clientId, [[
            ...$this->weighing(
                product: $this->turkey,
                quantity: 1,
                price: '18.00',
                readWeight: '4.500',
                waste: 25,
            ),
            'weight_source' => Balanza::CODE_PRODUCT_DISPATCH,
            'scale_reading' => [
                'raw_frame' => 'ST,GS,+004.500kg',
                'connection_mode' => 'serial',
                'device_name' => 'COM7',
                'captured_at' => $capturedAt,
            ],
        ]]);

        $response = $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.weight_source', Balanza::CODE_PRODUCT_DISPATCH);
        $this->assertEqualsWithDelta(4.5, $response->json('data.weighings.0.read_weight_kg'), 0.0001);
        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => Balanza::CODE_PRODUCT_DISPATCH,
        ]);
        $this->assertDatabaseHas('lecturas_balanza', [
            'peso_kg' => 4.5,
            'trama_cruda' => 'ST,GS,+004.500kg',
            'modo_conexion' => 'serial',
            'dispositivo' => 'COM7',
            'capturada_por' => $this->user->id,
        ]);
        $this->assertNotNull(DB::table('pesadas_despacho_productos')
            ->where('ticket_despacho_producto_id', $ticketId)
            ->value('lectura_balanza_id'));
    }

    public function test_repeating_the_same_draft_is_idempotent_across_ticket_weight_and_finance(): void
    {
        $payload = $this->payload($this->clientId, [
            $this->weighing(
                product: $this->turkey,
                variation: $this->largeTurkey,
                quantity: 1,
                price: '20.00',
                readWeight: '5.000',
                waste: 30,
            ),
        ]);

        $ticketId = (int) $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->json('data.id');

        $changedRetry = $payload;
        $changedRetry['weighings'][0]['read_weight_kg'] = '99.000';
        $retry = $this->postJson('/api/v1/despacho-productos/tickets', $changedRetry)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('data.id', $ticketId);
        $this->assertEqualsWithDelta(5, $retry->json('data.weighings.0.read_weight_kg'), 0.0001);

        $this->assertDatabaseCount('tickets_despacho_productos', 1);
        $this->assertDatabaseCount('pesadas_despacho_productos', 1);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('comprobante_detalles', 1);
        $this->assertDatabaseCount('comprobante_tickets_despacho_productos', 1);
    }

    public function test_saved_ticket_keeps_sale_snapshots_and_cannot_be_read_from_another_company(): void
    {
        $payload = $this->payload($this->clientId, [
            $this->weighing(
                product: $this->turkey,
                variation: $this->largeTurkey,
                quantity: 1,
                price: '20.00',
                readWeight: '5.000',
                waste: 30,
            ),
        ]);
        $ticketId = (int) $this->postJson('/api/v1/despacho-productos/tickets', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->turkey->update(['nombre' => 'Pavo renombrado', 'precio_venta' => 99]);
        $this->largeTurkey->update(['nombre' => 'Variación renombrada', 'precio_venta' => 88]);
        DB::table('terceros')->where('id', $this->clientId)->update([
            'nombre_razon_social' => 'Cliente renombrado',
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/despacho-productos/tickets/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('data.client.name', 'Cliente avícola')
            ->assertJsonPath('data.weighings.0.product.name', 'Pavo')
            ->assertJsonPath('data.weighings.0.variation.name', 'Pavo grande');
        $this->assertEqualsWithDelta(20, $response->json('data.weighings.0.catalog_price'), 0.0001);

        $otherUser = User::factory()->create();
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'OTRA-EMPRESA',
            'nombre' => 'Sucursal otra empresa',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherUser->update(['sucursal_id' => $otherBranchId]);
        $this->grantModules($otherUser, ['MODULO_DESPACHO_PRODUCTOS'], 'OTRA_EMPRESA_PRODUCTOS');
        Sanctum::actingAs($otherUser, ['api']);

        $this->getJson("/api/v1/despacho-productos/tickets/{$ticketId}")->assertNotFound();
    }

    public function test_foreign_client_wrong_variation_and_inactive_product_are_rejected_without_partial_writes(): void
    {
        $otherCompanyUser = User::factory()->create();
        $foreignClientId = $this->createClient(
            $otherCompanyUser->empresa_id,
            'Cliente de otra empresa',
            '20888888881',
        );
        $otherProduct = $this->createProduct(
            'Codorniz',
            ProductoDespacho::PRICE_MODE_UNIT,
            '7.0000',
            0,
        );
        $otherVariation = $this->createVariation(
            $otherProduct,
            'Codorniz grande',
            ProductoDespacho::PRICE_MODE_UNIT,
            '8.0000',
            0,
        );
        $inactive = $this->createProduct(
            'Gallina retirada',
            ProductoDespacho::PRICE_MODE_KG,
            '11.0000',
            10,
            ProductoDespacho::STATUS_INACTIVE,
        );

        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload($foreignClientId, [
            $this->weighing(product: $this->eggs),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload($this->clientId, [
            $this->weighing(product: $this->turkey, variation: $otherVariation),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.variation_id');

        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload($this->clientId, [
            $this->weighing(product: $inactive, price: '11.00'),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.product_id');

        $this->assertDatabaseCount('tickets_despacho_productos', 0);
        $this->assertDatabaseCount('pesadas_despacho_productos', 0);
        $this->assertDatabaseCount('lecturas_balanza', 0);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_inconsistent_or_overflowing_waste_impossible_tare_future_capture_and_invalid_source_are_rejected(): void
    {
        $inconsistentWaste = $this->weighing(
            product: $this->eggs,
            quantity: 2,
            readWeight: '1.000',
            waste: 90,
        );
        $inconsistentWaste['waste_grams_per_unit'] = 50;
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$inconsistentWaste]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.waste_total_grams');

        $impossibleTare = $this->weighing(
            product: $this->eggs,
            readWeight: '1.000',
            waste: 200,
        );
        $impossibleTare['waste_grams_per_unit'] = 200;
        $impossibleTare['tare_grams'] = 1200;
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$impossibleTare]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.tare_grams');

        $overflowingWaste = $this->weighing(
            product: $this->eggs,
            quantity: 100000,
            readWeight: '999999999.999',
            waste: 1000000000,
        );
        $overflowingWaste['waste_grams_per_unit'] = 1000000;
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$overflowingWaste]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.waste_grams_per_unit');

        $future = $this->weighing(product: $this->eggs);
        $future['weighed_at'] = now('America/Lima')->addMinutes(10)->toIso8601String();
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$future]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.weighed_at');

        $invalidSource = $this->weighing(product: $this->eggs);
        $invalidSource['weight_source'] = 'BALANZA_MINORISTA';
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$invalidSource]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.weight_source');

        $missingEvidence = $this->weighing(product: $this->eggs);
        $missingEvidence['weight_source'] = Balanza::CODE_PRODUCT_DISPATCH;
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$missingEvidence]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.scale_reading');

        $manualWithEvidence = $this->weighing(product: $this->eggs);
        $manualWithEvidence['scale_reading'] = [
            'captured_at' => $manualWithEvidence['weighed_at'],
        ];
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$manualWithEvidence]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.scale_reading');

        $zeroAmount = $this->weighing(
            product: $this->eggs,
            price: '0.0001',
            readWeight: '1.000',
            waste: 0,
        );
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$zeroAmount]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.unit_price');

        $stalePriceMode = $this->weighing(product: $this->eggs);
        $stalePriceMode['price_mode'] = ProductoDespacho::PRICE_MODE_KG;
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$stalePriceMode]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.price_mode');

        $this->assertDatabaseCount('tickets_despacho_productos', 0);
        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_weighing_price_accepts_two_decimals_and_rejects_three(): void
    {
        $accepted = $this->weighing(
            product: $this->eggs,
            price: '9999999999.99',
        );

        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$accepted]))
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.unit_price', 9999999999.99);

        $threeDecimals = $this->weighing(
            product: $this->eggs,
            price: '0.751',
        );

        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$threeDecimals]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.unit_price');

        $this->assertDatabaseCount('tickets_despacho_productos', 1);
    }

    public function test_ticket_management_list_returns_full_details_filters_pagination_and_summary(): void
    {
        $tickets = collect();

        foreach (range(0, 10) as $index) {
            $weighings = $index === 0
                ? [$this->weighing(
                    product: $this->turkey,
                    variation: $this->largeTurkey,
                    quantity: 2,
                )]
                : [$this->weighing(product: $this->eggs)];
            $clientId = $index === 0 ? $this->clientId : null;
            $created = $this->postJson(
                '/api/v1/despacho-productos/tickets',
                $this->payload($clientId, $weighings, ($index % 8) + 1),
            )->assertCreated();
            $ticket = $created->json('data');
            $date = now('America/Lima')->subDays(20 - $index)->format('Y-m-d');
            $registeredAt = "{$date} 10:00:00";

            DB::table('tickets_despacho_productos')
                ->where('id', $ticket['id'])
                ->update([
                    'fecha_operativa' => $date,
                    'registrado_at' => $registeredAt,
                ]);
            DB::table('pesadas_despacho_productos')
                ->where('ticket_despacho_producto_id', $ticket['id'])
                ->update(['pesada_at' => $registeredAt]);

            $tickets->push([
                ...$ticket,
                'operating_date' => $date,
            ]);
        }

        $oldest = $tickets->first();
        $page = $this->getJson('/api/v1/despacho-productos/tickets?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.id', $oldest['id'])
            ->assertJsonPath('data.tickets.0.code', $oldest['code'])
            ->assertJsonPath('data.tickets.0.client.id', $this->clientId)
            ->assertJsonPath('data.tickets.0.weighings.0.product.id', $this->turkey->id)
            ->assertJsonPath('data.tickets.0.weighings.0.variation.id', $this->largeTurkey->id)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 11)
            ->assertJsonPath('data.pagination.from', 11)
            ->assertJsonPath('data.pagination.to', 11)
            ->assertJsonPath('data.summary.tickets', 11)
            ->assertJsonPath('data.summary.quantity', 12)
            ->assertJsonPath('data.applied_filters.search', null)
            ->assertJsonPath('data.applied_filters.date_from', null)
            ->assertJsonPath('data.applied_filters.date_to', null);

        $this->assertEqualsWithDelta(11.080, $page->json('data.summary.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(28.70, $page->json('data.summary.amount'), 0.001);
        $page->assertJsonPath('data.summary.currency', 'PEN')
            ->assertJsonCount(1, 'data.summary.amounts')
            ->assertJsonPath('data.summary.amounts.0.currency', 'PEN');

        $this->getJson('/api/v1/despacho-productos/tickets?per_page=10&page=999')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.from', 11)
            ->assertJsonPath('data.pagination.to', 11);

        DB::table('tickets_despacho_productos')
            ->where('id', $oldest['id'])
            ->update(['moneda' => 'USD']);
        $this->getJson('/api/v1/despacho-productos/tickets')
            ->assertOk()
            ->assertJsonPath('data.summary.currency', null)
            ->assertJsonPath('data.summary.amount', null)
            ->assertJsonCount(2, 'data.summary.amounts')
            ->assertJsonPath('data.summary.amounts.0.currency', 'PEN')
            ->assertJsonPath('data.summary.amounts.1.currency', 'USD');

        foreach ([
            $oldest['code'],
            'Cliente avícola',
            '20123456789',
            'Pavo grande',
        ] as $search) {
            $this->getJson('/api/v1/despacho-productos/tickets?search='.urlencode($search).'&per_page=20')
                ->assertOk()
                ->assertJsonCount(1, 'data.tickets')
                ->assertJsonPath('data.tickets.0.id', $oldest['id'])
                ->assertJsonPath('data.summary.tickets', 1)
                ->assertJsonPath('data.applied_filters.search', $search);
        }

        $rangeStart = $tickets->get(2)['operating_date'];
        $rangeEnd = $tickets->get(3)['operating_date'];
        $this->getJson(
            "/api/v1/despacho-productos/tickets?date_from={$rangeStart}&date_to={$rangeEnd}&per_page=20",
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.tickets')
            ->assertJsonPath('data.summary.tickets', 2)
            ->assertJsonPath('data.applied_filters.date_from', $rangeStart)
            ->assertJsonPath('data.applied_filters.date_to', $rangeEnd);

        $this->getJson('/api/v1/despacho-productos/tickets?date_from=2026-08-20&date_to=2026-08-19')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
        $this->getJson('/api/v1/despacho-productos/tickets?per_page=30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_ticket_management_is_scoped_to_company_and_branch_for_list_detail_and_update(): void
    {
        $ownTicket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $update = $this->updatePayload($ownTicket);
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'OTRA-TICKETS',
            'nombre' => 'Otra sucursal de tickets',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user->update(['sucursal_id' => $otherBranchId]);
        $otherBranchTicket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload(null, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $this->getJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}")
            ->assertNotFound();
        $this->putJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}", $update)
            ->assertNotFound();
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}", [
            'version' => $ownTicket['version'],
        ])->assertNotFound();

        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->getJson('/api/v1/despacho-productos/tickets?per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets')
            ->assertJsonPath('data.tickets.0.id', $ownTicket['id'])
            ->assertJsonPath('data.summary.tickets', 1);
        $this->getJson("/api/v1/despacho-productos/tickets/{$otherBranchTicket['id']}")
            ->assertNotFound();

        $foreignUser = User::factory()->create();
        $foreignBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $foreignUser->empresa_id,
            'codigo' => 'AJENA-TICKETS',
            'nombre' => 'Sucursal ajena de tickets',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignUser->update(['sucursal_id' => $foreignBranchId]);
        $this->grantModules(
            $foreignUser,
            ['MODULO_DESPACHO_PRODUCTOS'],
            'PRODUCTOS_TICKETS_AJENOS',
        );
        Sanctum::actingAs($foreignUser, ['api']);

        $this->getJson('/api/v1/despacho-productos/tickets?per_page=20')
            ->assertOk()
            ->assertJsonCount(0, 'data.tickets')
            ->assertJsonPath('data.summary.tickets', 0);
        $this->getJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}")
            ->assertNotFound();
        $this->putJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}", $update)
            ->assertNotFound();
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ownTicket['id']}", [
            'version' => $ownTicket['version'],
        ])->assertNotFound();

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ownTicket['id'],
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'total' => $ownTicket['totals']['amount'],
        ]);
    }

    public function test_ticket_correction_recalculates_every_projection_and_can_add_and_remove_lines(): void
    {
        $created = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [
                $this->weighing(
                    product: $this->turkey,
                    variation: $this->largeTurkey,
                    quantity: 2,
                    price: '21.00',
                    readWeight: '10.000',
                    waste: 100,
                ),
                $this->weighing(product: $this->eggs, quantity: 12),
            ], 2),
        )->assertCreated();
        $ticket = $created->json('data');
        $keptWeighingId = (int) $ticket['weighings'][0]['id'];
        $removedWeighingId = (int) $ticket['weighings'][1]['id'];
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        $newClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente corregido',
            '20987654321',
        );
        $registeredAt = now('America/Lima')->subDay()->setTime(10, 45);
        $payload = [
            'version' => $ticket['version'],
            'correction_reason' => 'Corrección integral solicitada por caja',
            'ticket_title' => 'TICKET CORREGIDO DE PRODUCTOS',
            'list_number' => 7,
            'client_id' => $newClientId,
            'registered_at' => $registeredAt->format('Y-m-d\TH:i'),
            'weighings' => [
                [
                    'id' => $keptWeighingId,
                    'product_id' => $this->eggs->id,
                    'variation_id' => null,
                    'quantity' => 4,
                    'price_mode' => ProductoDespacho::PRICE_MODE_UNIT,
                    'unit_price' => '0.80',
                    'waste_grams_per_unit' => 3,
                    'waste_total_grams' => 12,
                    'tare_grams' => 12,
                    'read_weight_kg' => '2.000',
                ],
                [
                    'id' => null,
                    'product_id' => $this->turkey->id,
                    'variation_id' => $this->largeTurkey->id,
                    'quantity' => 2,
                    'price_mode' => ProductoDespacho::PRICE_MODE_KG,
                    'unit_price' => '21.00',
                    'waste_grams_per_unit' => 50,
                    'waste_total_grams' => 100,
                    'tare_grams' => 100,
                    'read_weight_kg' => '10.000',
                ],
            ],
        ];

        $this->travel(1)->seconds();
        $response = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.id', $ticket['id'])
            ->assertJsonPath('data.code', $ticket['code'])
            ->assertJsonPath('data.ticket_title', 'TICKET CORREGIDO DE PRODUCTOS')
            ->assertJsonPath('data.list_number', 7)
            ->assertJsonPath('data.client.id', $newClientId)
            ->assertJsonPath('data.customer_type', TicketDespachoProducto::CUSTOMER_REGISTERED)
            ->assertJsonPath('data.operating_date', $registeredAt->format('Y-m-d'))
            ->assertJsonPath('data.totals.weighings', 2)
            ->assertJsonPath('data.totals.quantity', 6)
            ->assertJsonPath('data.totals.waste_grams', 112)
            ->assertJsonPath('data.totals.tare_grams', 112)
            ->assertJsonPath('data.weighings.0.id', $keptWeighingId)
            ->assertJsonPath('data.weighings.0.product.id', $this->eggs->id)
            ->assertJsonPath('data.weighings.1.product.id', $this->turkey->id)
            ->assertJsonPath('data.weighings.1.variation.id', $this->largeTurkey->id);

        $this->assertNotSame($ticket['version'], $response->json('data.version'));
        $this->assertEqualsWithDelta(12, $response->json('data.totals.read_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(12, $response->json('data.totals.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(213.20, $response->json('data.totals.amount'), 0.001);
        $newWeighingId = (int) $response->json('data.weighings.1.id');
        $this->assertNotContains($newWeighingId, [$keptWeighingId, $removedWeighingId]);

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'numero_lista' => 7,
            'titulo_ticket_snapshot' => 'TICKET CORREGIDO DE PRODUCTOS',
            'fecha_operativa' => $registeredAt->format('Y-m-d'),
            'cliente_id' => $newClientId,
            'cliente_numero_documento_snapshot' => '20987654321',
            'cliente_nombre_snapshot' => 'Cliente corregido',
            'cantidad_total' => 6,
            'peso_leido_total_kg' => 12,
            'merma_total_gramos' => 112,
            'tara_total_gramos' => 112,
            'peso_neto_total_kg' => 12,
            'subtotal' => 213.20,
            'total' => 213.20,
        ]);
        $this->assertDatabaseMissing('pesadas_despacho_productos', [
            'id' => $removedWeighingId,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $keptWeighingId,
            'ticket_despacho_producto_id' => $ticket['id'],
            'numero' => 1,
            'producto_despacho_id' => $this->eggs->id,
            'variacion_producto_despacho_id' => null,
            'modo_precio_snapshot' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_venta_snapshot' => 0.80,
            'cantidad' => 4,
            'merma_aplicada_gramos_unidad' => 3,
            'merma_total_gramos' => 12,
            'tara_gramos' => 12,
            'peso_neto_kg' => 2,
            'importe' => 3.20,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $newWeighingId,
            'ticket_despacho_producto_id' => $ticket['id'],
            'numero' => 2,
            'producto_despacho_id' => $this->turkey->id,
            'variacion_producto_despacho_id' => $this->largeTurkey->id,
            'modo_precio_snapshot' => ProductoDespacho::PRICE_MODE_KG,
            'precio_venta_snapshot' => 21,
            'cantidad' => 2,
            'merma_aplicada_gramos_unidad' => 50,
            'merma_total_gramos' => 100,
            'tara_gramos' => 100,
            'peso_neto_kg' => 10,
            'importe' => 210,
        ]);

        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'tercero_id' => $newClientId,
            'fecha_emision' => $registeredAt->format('Y-m-d'),
            'fecha_vencimiento' => $registeredAt->format('Y-m-d'),
            'subtotal' => 213.20,
            'total' => 213.20,
            'saldo_pendiente' => 213.20,
            'estado' => 'PENDIENTE',
            'contraparte_numero_documento_snapshot' => '20987654321',
            'contraparte_nombre_snapshot' => 'Cliente corregido',
        ]);
        $this->assertSame(
            2,
            DB::table('comprobante_detalles')->where('comprobante_id', $documentId)->count(),
        );
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->eggs->id,
            'variacion_producto_despacho_id' => null,
            'cantidad_unidades' => 4,
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_unitario' => 0.80,
            'subtotal' => 3.20,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->turkey->id,
            'variacion_producto_despacho_id' => $this->largeTurkey->id,
            'cantidad_unidades' => 2,
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_kg' => 21,
            'subtotal' => 210,
        ]);
        $this->assertDatabaseHas('comprobante_tickets_despacho_productos', [
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticket['id'],
            'importe_aplicado' => 213.20,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'usuario_id' => $this->user->id,
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CORREGIR',
        ]);
        $audit = DB::table('auditoria_eventos')
            ->where('entidad', 'tickets_despacho_productos')
            ->where('entidad_id', (string) $ticket['id'])
            ->where('accion', 'CORREGIR')
            ->first();
        $this->assertNotNull($audit?->datos_antes);
        $this->assertStringContainsString(
            'Corrección integral solicitada por caja',
            (string) $audit?->datos_despues,
        );
    }

    public function test_ticket_correction_rejects_a_stale_version_without_overwriting_the_first_change(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload(null, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'PRIMERA CORRECCIÓN VÁLIDA',
            'correction_reason' => 'Primera corrección válida',
        ]);

        $this->travel(1)->seconds();
        $updated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.ticket_title', 'PRIMERA CORRECCIÓN VÁLIDA');
        $this->assertNotSame($payload['version'], $updated->json('data.version'));

        $stalePayload = $payload;
        $stalePayload['ticket_title'] = 'CAMBIO QUE NO DEBE GUARDARSE';
        $stalePayload['correction_reason'] = 'Intento con una versión anterior';
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $stalePayload,
        )->assertConflict();

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'titulo_ticket_snapshot' => 'PRIMERA CORRECCIÓN VÁLIDA',
        ]);
        $this->assertDatabaseMissing('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'titulo_ticket_snapshot' => 'CAMBIO QUE NO DEBE GUARDARSE',
        ]);
        $this->assertSame(
            1,
            DB::table('auditoria_eventos')
                ->where('entidad', 'tickets_despacho_productos')
                ->where('entidad_id', (string) $ticket['id'])
                ->where('accion', 'CORREGIR')
                ->count(),
        );
    }

    public function test_invalid_ticket_correction_rolls_back_ticket_lines_document_and_audit(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $weighingId = (int) $ticket['weighings'][0]['id'];
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'ESTE TÍTULO NO DEBE GUARDARSE',
            'correction_reason' => 'Prueba de reversión total',
        ]);
        $payload['weighings'][0]['tare_grams'] = 2000;

        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'titulo_ticket_snapshot' => $ticket['ticket_title'],
            'cantidad_total' => 1,
            'total' => 0.75,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $weighingId,
            'tara_gramos' => 0,
            'peso_neto_kg' => 1.002,
            'importe' => 0.75,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'total' => 0.75,
            'saldo_pendiente' => 0.75,
        ]);
        $this->assertSame(
            1,
            DB::table('comprobante_detalles')->where('comprobante_id', $documentId)->count(),
        );
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CORREGIR',
        ]);
    }

    public function test_ticket_correction_cannot_reactivate_a_voided_financial_document(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        DB::table('comprobantes')->where('id', $documentId)->update([
            'estado' => 'ANULADO',
            'saldo_pendiente' => 0,
            'anulada_por' => $this->user->id,
            'anulada_at' => now(),
            'motivo_anulacion' => 'Anulación previa de prueba',
        ]);

        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'CAMBIO QUE NO DEBE REACTIVAR',
            'correction_reason' => 'Intentar corregir un comprobante anulado',
        ]);
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings');

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'titulo_ticket_snapshot' => $ticket['ticket_title'],
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'ANULADO',
            'saldo_pendiente' => 0,
            'anulada_por' => $this->user->id,
            'motivo_anulacion' => 'Anulación previa de prueba',
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CORREGIR',
        ]);
    }

    public function test_ticket_total_cannot_be_reduced_below_registered_payments_and_rolls_back_atomically(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [
                $this->weighing(product: $this->eggs, quantity: 100),
            ]),
        )->assertCreated()->json('data');
        $weighingId = (int) $ticket['weighings'][0]['id'];
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        $paymentId = (int) DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'COBRO-PRODUCTOS-001',
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $this->clientId,
            'direccion' => 'ENTRADA',
            'fecha_hora' => now(),
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '50.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => '50.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('comprobantes')->where('id', $documentId)->update([
            'saldo_pendiente' => '25.00',
            'estado' => 'PARCIAL',
        ]);
        $otherClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente que no debe recibir la deuda cobrada',
            '20444444444',
        );
        $clientChangePayload = $this->updatePayload($ticket, [
            'client_id' => $otherClientId,
            'correction_reason' => 'Intento de trasladar una venta con cobros',
        ]);

        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $clientChangePayload,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'cliente_id' => $this->clientId,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'tercero_id' => $this->clientId,
            'total' => 75,
            'saldo_pendiente' => 25,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'cliente_id' => $this->clientId,
            'estado' => 'REGISTRADO',
        ]);

        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'TOTAL INFERIOR NO VÁLIDO',
            'correction_reason' => 'Intento de reducir una venta ya cobrada',
        ]);
        $payload['weighings'][0] = [
            ...$payload['weighings'][0],
            'quantity' => 40,
            'waste_grams_per_unit' => 2,
            'waste_total_grams' => 80,
        ];

        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )->assertUnprocessable();

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'titulo_ticket_snapshot' => $ticket['ticket_title'],
            'cantidad_total' => 100,
            'total' => 75,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $weighingId,
            'cantidad' => 100,
            'merma_total_gramos' => 200,
            'importe' => 75,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'total' => 75,
            'saldo_pendiente' => 25,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'importe_aplicado' => 50,
        ]);
        $this->assertDatabaseHas('comprobante_tickets_despacho_productos', [
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticket['id'],
            'importe_aplicado' => 75,
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CORREGIR',
        ]);
    }

    public function test_ticket_correction_preserves_historical_snapshots_but_rejects_new_inactive_selections(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [
                $this->weighing(
                    product: $this->turkey,
                    variation: $this->largeTurkey,
                    quantity: 2,
                    price: '21.00',
                    readWeight: '10.000',
                    waste: 60,
                ),
            ]),
        )->assertCreated()->json('data');
        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'CORRECCIÓN CON CATÁLOGO HISTÓRICO',
            'correction_reason' => 'Conservar selección histórica inactiva',
        ]);

        $this->turkey->update([
            'nombre' => 'Pavo renombrado',
            'nombre_normalizado' => 'pavo renombrado',
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_venta' => '99.0000',
            'merma_gramos_unidad' => 999,
            'estado' => ProductoDespacho::STATUS_INACTIVE,
        ]);
        $this->largeTurkey->update([
            'nombre' => 'Pavo grande renombrado',
            'nombre_normalizado' => 'pavo grande renombrado',
            'modo_precio' => ProductoDespacho::PRICE_MODE_UNIT,
            'precio_venta' => '88.0000',
            'merma_gramos_unidad' => 888,
            'estado' => VariacionProductoDespacho::STATUS_INACTIVE,
        ]);
        DB::table('terceros')->where('id', $this->clientId)->update([
            'numero_documento' => '20000000000',
            'nombre_razon_social' => 'Cliente renombrado',
            'estado' => Tercero::STATUS_INACTIVE,
            'updated_at' => now(),
        ]);

        $updated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.client.document', '20123456789')
            ->assertJsonPath('data.client.name', 'Cliente avícola')
            ->assertJsonPath('data.weighings.0.product.name', 'Pavo')
            ->assertJsonPath('data.weighings.0.variation.name', 'Pavo grande')
            ->assertJsonPath('data.weighings.0.price_mode', ProductoDespacho::PRICE_MODE_KG)
            ->assertJsonPath('data.weighings.0.catalog_waste_grams_per_unit', 30);
        $this->assertEqualsWithDelta(20, $updated->json('data.weighings.0.catalog_price'), 0.0001);
        $this->assertEqualsWithDelta(21, $updated->json('data.weighings.0.unit_price'), 0.0001);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $ticket['weighings'][0]['id'],
            'producto_nombre_snapshot' => 'Pavo',
            'variacion_nombre_snapshot' => 'Pavo grande',
            'modo_precio_snapshot' => ProductoDespacho::PRICE_MODE_KG,
            'precio_catalogo_snapshot' => 20,
            'merma_catalogo_gramos_unidad' => 30,
        ]);
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'cliente_id' => $this->clientId,
            'cliente_numero_documento_snapshot' => '20123456789',
            'cliente_nombre_snapshot' => 'Cliente avícola',
        ]);

        $changedInactiveSelection = $this->updatePayload($updated->json('data'), [
            'correction_reason' => 'Intento de cambiar la selección de un producto inactivo',
        ]);
        $changedInactiveSelection['weighings'][0] = [
            ...$changedInactiveSelection['weighings'][0],
            'variation_id' => null,
            'price_mode' => ProductoDespacho::PRICE_MODE_UNIT,
            'unit_price' => '99.00',
            'waste_grams_per_unit' => 999,
            'waste_total_grams' => 1998,
        ];
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $changedInactiveSelection,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.product_id');

        $inactiveProduct = $this->createProduct(
            'Producto inactivo nuevo',
            ProductoDespacho::PRICE_MODE_KG,
            '7.0000',
            10,
            ProductoDespacho::STATUS_INACTIVE,
        );
        $invalidProduct = $this->updatePayload($updated->json('data'), [
            'correction_reason' => 'Intento de seleccionar producto inactivo',
        ]);
        $invalidProduct['weighings'][0] = [
            ...$invalidProduct['weighings'][0],
            'product_id' => $inactiveProduct->id,
            'variation_id' => null,
            'price_mode' => ProductoDespacho::PRICE_MODE_KG,
            'unit_price' => '7.00',
            'waste_grams_per_unit' => 10,
            'waste_total_grams' => 20,
        ];
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $invalidProduct,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.product_id');

        $inactiveVariation = $this->createVariation(
            $this->eggs,
            'Variación inactiva nueva',
            ProductoDespacho::PRICE_MODE_UNIT,
            '0.90',
            3,
            VariacionProductoDespacho::STATUS_INACTIVE,
        );
        $invalidVariation = $this->updatePayload($updated->json('data'), [
            'correction_reason' => 'Intento de seleccionar variación inactiva',
        ]);
        $invalidVariation['weighings'][0] = [
            ...$invalidVariation['weighings'][0],
            'product_id' => $this->eggs->id,
            'variation_id' => $inactiveVariation->id,
            'price_mode' => ProductoDespacho::PRICE_MODE_UNIT,
            'unit_price' => '0.90',
            'waste_grams_per_unit' => 3,
            'waste_total_grams' => 6,
        ];
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $invalidVariation,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.variation_id');

        $inactiveClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente inactivo nuevo',
            '20777777777',
            Tercero::STATUS_INACTIVE,
        );
        $invalidClient = $this->updatePayload($updated->json('data'), [
            'client_id' => $inactiveClientId,
            'correction_reason' => 'Intento de seleccionar cliente inactivo',
        ]);
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $invalidClient,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->assertSame(
            1,
            DB::table('auditoria_eventos')
                ->where('entidad', 'tickets_despacho_productos')
                ->where('entidad_id', (string) $ticket['id'])
                ->where('accion', 'CORREGIR')
                ->count(),
        );
    }

    public function test_ticket_correction_unlinks_scale_evidence_when_time_or_weight_changes(): void
    {
        $capturedAt = now('America/Lima')->subSeconds(30)->toIso8601String();
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [[
                ...$this->weighing(
                    product: $this->turkey,
                    quantity: 1,
                    price: '18.00',
                    readWeight: '4.500',
                    waste: 25,
                ),
                'weight_source' => Balanza::CODE_PRODUCT_DISPATCH,
                'scale_reading' => [
                    'raw_frame' => 'ST,GS,+004.500kg',
                    'connection_mode' => 'serial',
                    'device_name' => 'COM7',
                    'captured_at' => $capturedAt,
                ],
            ]]),
        )->assertCreated()->json('data');
        $weighingId = (int) $ticket['weighings'][0]['id'];
        $readingId = (int) DB::table('pesadas_despacho_productos')
            ->where('id', $weighingId)
            ->value('lectura_balanza_id');
        $registeredBefore = (string) DB::table('tickets_despacho_productos')
            ->where('id', $ticket['id'])
            ->value('registrado_at');
        $weighedBefore = (string) DB::table('pesadas_despacho_productos')
            ->where('id', $weighingId)
            ->value('pesada_at');
        $newRegisteredAt = now('America/Lima')->subHours(2)->startOfMinute();
        $preservePayload = $this->updatePayload($ticket, [
            'registered_at' => $newRegisteredAt->format('Y-m-d\TH:i'),
            'correction_reason' => 'Ajustar hora sin cambiar el peso capturado',
        ]);

        $corrected = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $preservePayload,
        )
            ->assertOk()
            ->assertJsonPath('data.weighings.0.id', $weighingId)
            ->assertJsonPath('data.weighings.0.weight_source', 'MANUAL');
        $registeredAfter = (string) DB::table('tickets_despacho_productos')
            ->where('id', $ticket['id'])
            ->value('registrado_at');
        $weighedAfter = (string) DB::table('pesadas_despacho_productos')
            ->where('id', $weighingId)
            ->value('pesada_at');

        $this->assertGreaterThan(0, $readingId);
        $this->assertSame(
            strtotime($registeredAfter) - strtotime($registeredBefore),
            strtotime($weighedAfter) - strtotime($weighedBefore),
        );
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $weighingId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
            'peso_leido_kg' => 4.5,
        ]);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $readingId,
            'trama_cruda' => 'ST,GS,+004.500kg',
        ]);

        $changedPayload = $this->updatePayload($corrected->json('data'), [
            'registered_at' => $corrected->json('data.registered_at_local'),
            'correction_reason' => 'Corregir un peso que ya no conserva la lectura',
        ]);
        $changedPayload['weighings'][0]['read_weight_kg'] = '4.750';
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $changedPayload,
        )
            ->assertOk()
            ->assertJsonPath('data.weighings.0.weight_source', 'MANUAL');

        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $weighingId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
            'peso_leido_kg' => 4.75,
        ]);
        $this->assertDatabaseCount('lecturas_balanza', 1);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $readingId,
            'trama_cruda' => 'ST,GS,+004.500kg',
        ]);
    }

    public function test_ticket_correction_rejects_invalid_ambiguous_and_out_of_range_local_times(): void
    {
        DB::table('sucursales')->where('id', $this->branchId)->update([
            'zona_horaria' => 'America/Los_Angeles',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'America/Los_Angeles'));
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $storedRegisteredAt = (string) DB::table('tickets_despacho_productos')
            ->where('id', $ticket['id'])
            ->value('registrado_at');

        foreach ([
            '2026-03-08T02:30:00',
            '2026-11-01T01:30:00',
            '1969-12-31T23:59:59',
        ] as $invalidLocalTime) {
            $payload = $this->updatePayload($ticket, [
                'registered_at' => $invalidLocalTime,
                'correction_reason' => 'Intentar una fecha local inválida',
            ]);

            $this->putJson(
                "/api/v1/despacho-productos/tickets/{$ticket['id']}",
                $payload,
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('registered_at');
        }

        $this->assertSame(
            $storedRegisteredAt,
            (string) DB::table('tickets_despacho_productos')
                ->where('id', $ticket['id'])
                ->value('registrado_at'),
        );
    }

    public function test_new_line_uses_the_historical_operating_clock_when_ticket_time_is_unchanged(): void
    {
        $historicalTime = now('America/Lima')->subDays(2)->setTime(10, 15, 27);
        $originalWeighing = $this->weighing(product: $this->eggs);
        $originalWeighing['weighed_at'] = $historicalTime->toIso8601String();
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$originalWeighing]),
        )->assertCreated()->json('data');
        $originalWeighedAt = (string) DB::table('pesadas_despacho_productos')
            ->where('id', $ticket['weighings'][0]['id'])
            ->value('pesada_at');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        $payload = $this->updatePayload($ticket, [
            'registered_at' => $ticket['registered_at_local'],
            'correction_reason' => 'Agregar una pesada a un despacho histórico',
        ]);
        $payload['weighings'][] = [
            'id' => null,
            'product_id' => $this->eggs->id,
            'variation_id' => null,
            'quantity' => 2,
            'price_mode' => ProductoDespacho::PRICE_MODE_UNIT,
            'unit_price' => '0.75',
            'waste_grams_per_unit' => 2,
            'waste_total_grams' => 4,
            'tare_grams' => 0,
            'read_weight_kg' => '1.000',
        ];

        $updated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.weighings')
            ->assertJsonPath('data.operating_date', $ticket['operating_date']);
        $newWeighingId = (int) $updated->json('data.weighings.1.id');

        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $newWeighingId,
            'pesada_at' => $originalWeighedAt,
            'origen_peso' => 'MANUAL',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'fecha_emision' => $ticket['operating_date'],
            'fecha_vencimiento' => $ticket['operating_date'],
        ]);
    }

    public function test_title_only_correction_preserves_ticket_and_weighing_seconds(): void
    {
        DB::table('empresas')->where('id', $this->user->empresa_id)->update([
            'hora_corte_operativo' => '21:00:00',
        ]);
        $this->travelTo(now('America/Lima')->startOfDay()->setTime(20, 30, 37));
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $ticketBefore = (string) DB::table('tickets_despacho_productos')
            ->where('id', $ticket['id'])
            ->value('registrado_at');
        $weighingBefore = (string) DB::table('pesadas_despacho_productos')
            ->where('id', $ticket['weighings'][0]['id'])
            ->value('pesada_at');
        $this->assertStringEndsWith(':37', $ticketBefore);
        $this->assertStringEndsWith(':37', $weighingBefore);
        $this->assertStringEndsWith(':37', $ticket['registered_at_local']);
        $operatingDateBefore = now('America/Lima')->format('Y-m-d');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        $this->assertSame($operatingDateBefore, $ticket['operating_date']);

        DB::table('empresas')->where('id', $this->user->empresa_id)->update([
            'hora_corte_operativo' => '20:00:00',
        ]);

        $payload = $this->updatePayload($ticket, [
            'registered_at' => $ticket['registered_at_local'],
            'ticket_title' => 'CORRECCIÓN SIN CAMBIAR LA HORA',
            'correction_reason' => 'Corregir únicamente el título impreso',
        ]);
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.ticket_title', 'CORRECCIÓN SIN CAMBIAR LA HORA')
            ->assertJsonPath('data.registered_at_local', $ticket['registered_at_local']);

        $this->assertSame(
            $ticketBefore,
            (string) DB::table('tickets_despacho_productos')
                ->where('id', $ticket['id'])
                ->value('registrado_at'),
        );
        $this->assertSame(
            $weighingBefore,
            (string) DB::table('pesadas_despacho_productos')
                ->where('id', $ticket['weighings'][0]['id'])
                ->value('pesada_at'),
        );
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'fecha_operativa' => $operatingDateBefore,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'fecha_emision' => $operatingDateBefore,
            'fecha_vencimiento' => $operatingDateBefore,
        ]);
    }

    public function test_scale_and_weighing_keep_the_branch_clock_outside_the_app_timezone(): void
    {
        DB::table('sucursales')->where('id', $this->branchId)->update([
            'zona_horaria' => 'America/Los_Angeles',
        ]);
        $this->travelTo(now('America/Los_Angeles')->startOfDay()->setTime(10, 30, 37));
        $weighedAt = now('America/Los_Angeles')->subMinute();
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [[
                ...$this->weighing(product: $this->eggs),
                'weight_source' => Balanza::CODE_PRODUCT_DISPATCH,
                'weighed_at' => $weighedAt->toIso8601String(),
                'scale_reading' => [
                    'raw_frame' => 'ST,GS,+001.000kg',
                    'captured_at' => $weighedAt->toIso8601String(),
                ],
            ]]),
        )->assertCreated()->json('data');
        $expectedBranchClock = $weighedAt->format('Y-m-d H:i:s');
        $weighing = DB::table('pesadas_despacho_productos')
            ->where('id', $ticket['weighings'][0]['id'])
            ->first();
        $readingClock = (string) DB::table('lecturas_balanza')
            ->where('id', $weighing->lectura_balanza_id)
            ->value('capturada_at');

        $this->assertSame($expectedBranchClock, (string) $weighing->pesada_at);
        $this->assertSame($expectedBranchClock, $readingClock);
        $this->assertSame(
            $weighedAt->getTimestamp(),
            strtotime((string) $ticket['weighings'][0]['weighed_at']),
        );

        $payload = $this->updatePayload($ticket, [
            'registered_at' => $ticket['registered_at_local'],
            'ticket_title' => 'CORRECCIÓN EN OTRA ZONA HORARIA',
            'correction_reason' => 'Validar que se conserve el reloj de la sucursal',
        ]);
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )->assertOk();

        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $ticket['weighings'][0]['id'],
            'pesada_at' => $expectedBranchClock,
            'lectura_balanza_id' => $weighing->lectura_balanza_id,
        ]);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $weighing->lectura_balanza_id,
            'capturada_at' => $expectedBranchClock,
        ]);
    }

    public function test_ticket_correction_accepts_an_omitted_or_blank_reason_and_rejects_malformed_values(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $payload = $this->updatePayload($ticket, [
            'ticket_title' => 'EDICIÓN SIN MOTIVO',
        ]);
        unset($payload['correction_reason']);

        $this->travel(1)->seconds();
        $updated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $payload,
        )
            ->assertOk()
            ->assertJsonPath('data.ticket_title', 'EDICIÓN SIN MOTIVO')
            ->json('data');

        $ticketAudit = DB::table('auditoria_eventos')
            ->where('entidad', 'tickets_despacho_productos')
            ->where('entidad_id', (string) $ticket['id'])
            ->where('accion', 'CORREGIR')
            ->latest('id')
            ->firstOrFail();
        $ticketAfter = json_decode((string) $ticketAudit->datos_despues, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('correction_reason', $ticketAfter);
        $this->assertArrayNotHasKey('motivo_correccion', $ticketAfter);

        $blankPayload = $this->updatePayload($updated, [
            'correction_reason' => '   ',
            'ticket_title' => 'EDICIÓN CON MOTIVO VACÍO',
        ]);
        $this->travel(1)->seconds();
        $blankUpdated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $blankPayload,
        )
            ->assertOk()
            ->assertJsonPath('data.ticket_title', 'EDICIÓN CON MOTIVO VACÍO')
            ->json('data');

        foreach (['x', ['motivo inválido']] as $invalidReason) {
            $invalid = $this->updatePayload($blankUpdated, [
                'correction_reason' => $invalidReason,
            ]);
            $this->putJson(
                "/api/v1/despacho-productos/tickets/{$ticket['id']}",
                $invalid,
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('correction_reason');
        }

        $malformed = $this->updatePayload($blankUpdated, [
            'ticket_title' => ['título inválido'],
            'registered_at' => ['fecha inválida'],
        ]);
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $malformed,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket_title', 'registered_at']);
    }

    public function test_ticket_can_be_logically_deleted_without_a_reason_and_preserves_traceability(): void
    {
        $capturedAt = now('America/Lima')->subSeconds(30)->toIso8601String();
        $originalPayload = $this->payload($this->clientId, [[
            ...$this->weighing(
                product: $this->turkey,
                readWeight: '4.500',
                waste: 25,
            ),
            'weight_source' => Balanza::CODE_PRODUCT_DISPATCH,
            'scale_reading' => [
                'raw_frame' => 'ST,GS,+004.500kg',
                'connection_mode' => 'serial',
                'device_name' => 'COM7',
                'captured_at' => $capturedAt,
            ],
        ]]);
        $ticket = $this->postJson('/api/v1/despacho-productos/tickets', $originalPayload)
            ->assertCreated()
            ->json('data');
        $weighingId = (int) $ticket['weighings'][0]['id'];
        $readingId = (int) DB::table('pesadas_despacho_productos')
            ->where('id', $weighingId)
            ->value('lectura_balanza_id');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');

        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $this->travel(1)->seconds();
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $ticket['version'],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Ticket de despacho eliminado correctamente.');

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'codigo' => $ticket['code'],
            'referencia_externa' => $originalPayload['draft_id'],
            'estado' => TicketDespachoProducto::STATUS_DELETED,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'id' => $weighingId,
            'ticket_despacho_producto_id' => $ticket['id'],
            'lectura_balanza_id' => $readingId,
        ]);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $readingId,
            'trama_cruda' => 'ST,GS,+004.500kg',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'saldo_pendiente' => 0,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
            'motivo_anulacion' => null,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->turkey->id,
        ]);
        $this->assertDatabaseHas('comprobante_tickets_despacho_productos', [
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticket['id'],
        ]);

        $this->getJson('/api/v1/despacho-productos/tickets')
            ->assertOk()
            ->assertJsonCount(0, 'data.tickets')
            ->assertJsonPath('data.summary.tickets', 0);
        $this->getJson("/api/v1/despacho-productos/tickets/{$ticket['id']}")
            ->assertNotFound();
        $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $this->updatePayload($ticket),
        )->assertNotFound();
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $ticket['version'],
        ])->assertNotFound();

        $this->postJson('/api/v1/despacho-productos/tickets', $originalPayload)
            ->assertConflict();
        $newTicket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload(null, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $this->assertNotSame($ticket['code'], $newTicket['code']);
        $this->assertStringEndsWith('-002', $newTicket['code']);
        $this->assertDatabaseCount('tickets_despacho_productos', 2);
        $this->assertDatabaseCount('comprobantes', 2);

        $ticketAudit = DB::table('auditoria_eventos')
            ->where('entidad', 'tickets_despacho_productos')
            ->where('entidad_id', (string) $ticket['id'])
            ->where('accion', 'ELIMINAR')
            ->firstOrFail();
        $ticketBefore = json_decode((string) $ticketAudit->datos_antes, true, 512, JSON_THROW_ON_ERROR);
        $ticketAfter = json_decode((string) $ticketAudit->datos_despues, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(TicketDespachoProducto::STATUS_REGISTERED, $ticketBefore['estado']);
        $this->assertSame(TicketDespachoProducto::STATUS_DELETED, $ticketAfter['estado']);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'usuario_id' => $this->user->id,
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $documentId,
            'accion' => 'ANULAR',
        ]);
    }

    public function test_ticket_deletion_rejects_stale_versions_and_active_payment_applications(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs, quantity: 10)]),
        )->assertCreated()->json('data');
        $originalVersion = $ticket['version'];
        $update = $this->updatePayload($ticket, [
            'ticket_title' => 'VERSIÓN MÁS RECIENTE',
        ]);
        unset($update['correction_reason']);
        $this->travel(1)->seconds();
        $updated = $this->putJson(
            "/api/v1/despacho-productos/tickets/{$ticket['id']}",
            $update,
        )->assertOk()->json('data');

        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $originalVersion,
        ])->assertConflict();
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'estado' => TicketDespachoProducto::STATUS_REGISTERED,
            'titulo_ticket_snapshot' => 'VERSIÓN MÁS RECIENTE',
        ]);

        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")
            ->value('id');
        DB::table('comprobantes')->where('id', $documentId)->update([
            'saldo_pendiente' => '6.50',
            'estado' => 'PARCIAL',
        ]);
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $updated['version'],
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'El comprobante asociado tiene un saldo financiero inconsistente y no puede eliminarse.',
            );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'saldo_pendiente' => '7.50',
            'estado' => 'PENDIENTE',
        ]);
        $paymentId = (int) DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'COBRO-ACTIVO-PRODUCTOS',
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $this->clientId,
            'direccion' => 'ENTRADA',
            'fecha_hora' => now(),
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '1.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => '1.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $updated['version'],
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'No se puede eliminar el ticket porque ya tiene cobros o pagos aplicados.',
            );

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'],
            'estado' => TicketDespachoProducto::STATUS_REGISTERED,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 7.50,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'ELIMINAR',
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $documentId,
            'accion' => 'ANULAR',
        ]);
    }

    public function test_ticket_can_be_deleted_after_reversing_its_customer_credit_and_preserves_payment_history(): void
    {
        $ticket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($this->clientId, [$this->weighing(product: $this->eggs, quantity: 10)]),
        )->assertCreated()->json('data');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET_PRODUCTOS:{$ticket['id']}")->value('id');
        $credit = $this->postJson('/api/v1/despacho-productos/pagos/ajustes', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'CREDIT', 'cliente_id' => $this->clientId,
            'importe' => '3.00', 'moneda' => 'PEN',
            'fecha_hora' => now('America/Lima')->format('Y-m-d\TH:i'),
            'observaciones' => 'Saldo a favor para corregir',
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId, 'estado' => 'PARCIAL', 'saldo_pendiente' => 4.50,
        ]);
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $ticket['version'],
        ])->assertConflict();

        $this->deleteJson('/api/v1/despacho-productos/pagos/ajustes/'.$credit['id'])->assertOk();
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId, 'estado' => 'PENDIENTE', 'saldo_pendiente' => 7.50,
        ]);
        $this->deleteJson("/api/v1/despacho-productos/tickets/{$ticket['id']}", [
            'version' => $ticket['version'],
        ])->assertOk();

        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticket['id'], 'estado' => TicketDespachoProducto::STATUS_DELETED,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId, 'estado' => 'ANULADO', 'saldo_pendiente' => 0,
        ]);
        $this->assertDatabaseHas('pagos', ['id' => $credit['payment_id'], 'estado' => 'ANULADO']);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $credit['payment_id'], 'comprobante_id' => $documentId, 'importe_aplicado' => 3.00,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'tickets_despacho_productos', 'entidad_id' => (string) $ticket['id'], 'accion' => 'ELIMINAR',
        ]);
    }

    public function test_ticket_search_treats_sql_wildcards_and_escape_character_as_literals(): void
    {
        $literalClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente 100% _ ! literal',
            '20666666666',
        );
        $literalTicket = $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($literalClientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated()->json('data');
        $plainClientId = $this->createClient(
            $this->user->empresa_id,
            'Cliente sin caracteres especiales',
            '20555555555',
        );
        $this->postJson(
            '/api/v1/despacho-productos/tickets',
            $this->payload($plainClientId, [$this->weighing(product: $this->eggs)]),
        )->assertCreated();

        foreach (['%', '_', '!'] as $literal) {
            $this->getJson('/api/v1/despacho-productos/tickets?search='.urlencode($literal))
                ->assertOk()
                ->assertJsonCount(1, 'data.tickets')
                ->assertJsonPath('data.tickets.0.id', $literalTicket['id'])
                ->assertJsonPath('data.summary.tickets', 1)
                ->assertJsonPath('data.applied_filters.search', $literal);
        }
    }

    public function test_operation_endpoints_require_the_product_dispatch_module(): void
    {
        $unauthorized = User::factory()->create();
        $unauthorizedBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $unauthorized->empresa_id,
            'codigo' => 'SIN-PRODUCTOS',
            'nombre' => 'Sucursal sin productos',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unauthorized->update(['sucursal_id' => $unauthorizedBranchId]);
        $this->grantModules($unauthorized, ['MODULO_DIRECTORIO'], 'SIN_DESPACHO_PRODUCTOS');
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson('/api/v1/despacho-productos/catalogo')->assertForbidden();
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [0, 50, 100, 150],
        ])->assertForbidden();
        $this->postJson('/api/v1/despacho-productos/tickets', [])->assertForbidden();
        $this->getJson('/api/v1/despacho-productos/tickets')->assertForbidden();
        $this->getJson('/api/v1/despacho-productos/tickets/1')->assertForbidden();
        $this->putJson('/api/v1/despacho-productos/tickets/1', [])->assertForbidden();
        $this->deleteJson('/api/v1/despacho-productos/tickets/1', [])->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $weighings
     * @return array<string, mixed>
     */
    private function payload(?int $clientId, array $weighings, int $listNumber = 1): array
    {
        return [
            'draft_id' => (string) Str::uuid(),
            'list_number' => $listNumber,
            'client_id' => $clientId,
            'weighings' => $weighings,
        ];
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $ticket, array $overrides = []): array
    {
        $payload = [
            'version' => $ticket['version'],
            'correction_reason' => 'Corrección de prueba con trazabilidad',
            'ticket_title' => $ticket['ticket_title'],
            'list_number' => $ticket['list_number'],
            'client_id' => $ticket['client']['id'] ?? null,
            'registered_at' => now('America/Lima')->subMinute()->format('Y-m-d\TH:i'),
            'weighings' => collect($ticket['weighings'])
                ->map(fn (array $weighing): array => [
                    'id' => $weighing['id'],
                    'product_id' => $weighing['product']['id'],
                    'variation_id' => $weighing['variation']['id'] ?? null,
                    'quantity' => $weighing['quantity'],
                    'price_mode' => $weighing['price_mode'],
                    'unit_price' => number_format((float) $weighing['unit_price'], 2, '.', ''),
                    'waste_grams_per_unit' => $weighing['waste_grams_per_unit'],
                    'waste_total_grams' => $weighing['waste_total_grams'],
                    'tare_grams' => $weighing['tare_grams'],
                    'read_weight_kg' => number_format((float) $weighing['read_weight_kg'], 3, '.', ''),
                ])
                ->values()
                ->all(),
        ];

        return [...$payload, ...$overrides];
    }

    /** @return array<string, mixed> */
    private function weighing(
        ProductoDespacho $product,
        ?VariacionProductoDespacho $variation = null,
        int $quantity = 1,
        ?string $price = null,
        string $readWeight = '1.000',
        ?int $waste = null,
    ): array {
        $effective = $variation ?? $product;
        $unitPrice = $price ?? number_format((float) $effective->precio_venta, 2, '.', '');

        return [
            'product_id' => $product->id,
            'variation_id' => $variation?->id,
            'quantity' => $quantity,
            'price_mode' => $effective->modo_precio,
            'unit_price' => $unitPrice,
            'waste_total_grams' => $waste ?? ($effective->merma_gramos_unidad * $quantity),
            'weight_source' => 'MANUAL',
            'read_weight_kg' => $readWeight,
            'weighed_at' => now('America/Lima')->subMinute()->toIso8601String(),
        ];
    }

    private function createProduct(
        string $name,
        string $priceMode,
        string $price,
        int $waste,
        string $status = ProductoDespacho::STATUS_ACTIVE,
    ): ProductoDespacho {
        return ProductoDespacho::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'descripcion' => null,
            'modo_precio' => $priceMode,
            'precio_venta' => $price,
            'merma_gramos_unidad' => $waste,
            'imagen_path' => null,
            'estado' => $status,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function createVariation(
        ProductoDespacho $product,
        string $name,
        string $priceMode,
        string $price,
        int $waste,
        string $status = VariacionProductoDespacho::STATUS_ACTIVE,
    ): VariacionProductoDespacho {
        return VariacionProductoDespacho::query()->create([
            'producto_despacho_id' => $product->id,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'modo_precio' => $priceMode,
            'precio_venta' => $price,
            'merma_gramos_unidad' => $waste,
            'imagen_path' => null,
            'orden' => 1,
            'estado' => $status,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function createClient(
        int $companyId,
        string $name,
        string $document,
        string $status = Tercero::STATUS_ACTIVE,
    ): int {
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $companyId,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Mercado avícola',
            'estado' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $clientId,
            'rol' => 'CLIENTE',
            'created_at' => now(),
        ]);

        return $clientId;
    }
}
