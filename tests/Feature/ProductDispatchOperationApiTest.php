<?php

namespace Tests\Feature;

use App\Models\Balanza;
use App\Models\PesadaDespachoProducto;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
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
            ->assertJsonPath('data.waste_presets', [0, 50, 100])
            ->assertJsonPath('data.scale.code', Balanza::CODE_PRODUCT_DISPATCH)
            ->assertJsonPath('data.scale.configuration.baudRate', 9600);

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
            'waste_presets' => [15, 40, 90],
        ])
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [15, 40, 90]);

        $this->assertDatabaseHas('configuraciones_despacho_productos', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'merma_preset_1_gramos_unidad' => 15,
            'merma_preset_2_gramos_unidad' => 40,
            'merma_preset_3_gramos_unidad' => 90,
        ]);
        $this->getJson('/api/v1/despacho-productos/catalogo')
            ->assertOk()
            ->assertJsonPath('data.waste_presets', [15, 40, 90]);

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
            ->assertJsonPath('data.waste_presets', [0, 50, 100]);

        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [1, 2],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('waste_presets');
        $this->putJson('/api/v1/despacho-productos/configuracion', [
            'waste_presets' => [0, -1, 1000001],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'waste_presets.1',
                'waste_presets.2',
            ]);
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
        $this->assertEqualsWithDelta(11.876, $response->json('data.totals.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(216.9, $response->json('data.totals.amount'), 0.001);
        $this->assertEqualsWithDelta(20, $response->json('data.weighings.0.catalog_price'), 0.0001);
        $this->assertEqualsWithDelta(21, $response->json('data.weighings.0.unit_price'), 0.0001);
        $this->assertEqualsWithDelta(9.9, $response->json('data.weighings.0.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(207.9, $response->json('data.weighings.0.amount'), 0.001);
        $this->assertEqualsWithDelta(1.976, $response->json('data.weighings.1.net_weight_kg'), 0.0001);
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
            'peso_neto_total_kg' => 11.876,
            'total' => 216.90,
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
            'peso_neto_kg' => 9.9,
            'importe' => 207.9,
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
            'total' => 216.90,
            'saldo_pendiente' => 216.90,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'producto_despacho_id' => $this->turkey->id,
            'variacion_producto_despacho_id' => $this->largeTurkey->id,
            'cantidad_unidades' => 2,
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_kg' => 21,
            'subtotal' => 207.9,
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
            'importe_aplicado' => 216.9,
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

    public function test_unit_waste_and_tare_are_persisted_and_authoritatively_reduce_net_weight(): void
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

        $this->assertEqualsWithDelta(9.65, $response->json('data.weighings.0.net_weight_kg'), 0.0001);
        $this->assertEqualsWithDelta(193, $response->json('data.weighings.0.amount'), 0.001);
        $this->assertEqualsWithDelta(9.65, $response->json('data.totals.net_weight_kg'), 0.0001);

        $ticketId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tickets_despacho_productos', [
            'id' => $ticketId,
            'merma_total_gramos' => 100,
            'tara_total_gramos' => 250,
            'peso_neto_total_kg' => 9.65,
        ]);
        $this->assertDatabaseHas('pesadas_despacho_productos', [
            'ticket_despacho_producto_id' => $ticketId,
            'merma_catalogo_gramos_unidad' => 25,
            'merma_aplicada_gramos_unidad' => 50,
            'merma_total_gramos' => 100,
            'tara_gramos' => 250,
            'peso_neto_kg' => 9.65,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'producto_despacho_id' => $this->turkey->id,
            'peso_neto_kg' => 9.65,
            'subtotal' => 193,
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

    public function test_impossible_waste_future_capture_and_invalid_source_are_rejected(): void
    {
        $impossibleWaste = $this->weighing(
            product: $this->eggs,
            readWeight: '1.000',
            waste: 1000,
        );
        $this->postJson('/api/v1/despacho-productos/tickets', $this->payload(null, [$impossibleWaste]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.waste_total_grams');

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
        $impossibleTare['tare_grams'] = 800;
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
            'waste_presets' => [0, 50, 100],
        ])->assertForbidden();
        $this->postJson('/api/v1/despacho-productos/tickets', [])->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $weighings
     * @return array<string, mixed>
     */
    private function payload(?int $clientId, array $weighings): array
    {
        return [
            'draft_id' => (string) Str::uuid(),
            'client_id' => $clientId,
            'weighings' => $weighings,
        ];
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
