<?php

namespace Tests\Feature;

use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\TerceroDirectoryService;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class WholesaleTwoDispatchTicketApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    /** @var array<string, int> */
    private array $typeIds;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('directory.public_access', false);
        $this->user = User::factory()->create();
        $this->grantModules(
            $this->user,
            ['MODULO_DESPACHO_MAYORISTA_2', 'MODULO_DESPACHO_MAYORISTA'],
            'OPERADOR_MAYORISTA_DOBLE',
            'Operador mayorista doble',
        );
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

        collect([
            TipoPollo::CHICKEN_LIVE => 'Pollo vivo',
            TipoPollo::CHICKEN_DRESSED => 'Pollo pelado',
            TipoPollo::CHICKEN_PROCESSED => 'Pollo beneficiado',
            TipoPollo::CHICKEN_DEAD => 'Pollo muerto',
            TipoPollo::HEN_RED => 'Gallina roja',
            TipoPollo::HEN_DOUBLE => 'Gallina doble',
            TipoPollo::OTHER => 'Otros',
        ])->each(function (string $name, string $code): void {
            DB::table('tipos_pollo')->updateOrInsert([
                'codigo' => $code,
            ], [
                'nombre' => $name,
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->typeIds[$code] = (int) DB::table('tipos_pollo')
                ->where('codigo', $code)
                ->value('id');
        });
        DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::CHICKEN_DEAD)
            ->update(['precio_fuente_tipo_pollo_id' => $this->typeIds[TipoPollo::CHICKEN_LIVE]]);
        DB::table('tipos_java')->insert([
            [
                'codigo' => 'JAVA_700',
                'nombre' => 'Java 7.00 kg',
                'peso_kg' => 7,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'JAVA_690',
                'nombre' => 'Java 6.90 kg',
                'peso_kg' => 6.9,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->clientId = $this->createParty(
            TerceroRole::CLIENT,
            'Cliente interno',
            '20111111111',
            true,
        );
        $this->createClientPrices();

        Sanctum::actingAs($this->user, ['api']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_all_wholesale_two_variants_are_stored_without_an_origin_or_published_program(): void
    {
        $variants = [
            [TipoPollo::CHICKEN_LIVE, WholesaleTwoChickenVariant::MALE, Pesada::SEX_MALE, null],
            [TipoPollo::CHICKEN_LIVE, WholesaleTwoChickenVariant::FEMALE, Pesada::SEX_FEMALE, null],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::MALE_OPEN, Pesada::SEX_MALE, 'ABIERTO'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::MALE_CLOSED, Pesada::SEX_MALE, 'CERRADO'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::FEMALE_OPEN, Pesada::SEX_FEMALE, 'ABIERTA'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::FEMALE_CLOSED, Pesada::SEX_FEMALE, 'CERRADA'],
            [TipoPollo::CHICKEN_PROCESSED, WholesaleTwoChickenVariant::PROCESSED, null, null],
        ];
        $payload = $this->ticketPayload();
        $payload['weighings'] = collect($variants)
            ->map(fn (array $variant, int $index): array => $this->weighing(
                $index + 1,
                $variant[0],
                $variant[1],
            ))
            ->all();

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.ticket_title', 'DISTRIBUIDORA DIEGO ALBERTO')
            ->assertJsonPath('data.weighing_count', 7);

        $ticketId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO,
        ]);
        $this->assertDatabaseCount('programaciones_recepcion', 0);
        $this->assertDatabaseCount('ticket_precios', 3);
        $this->assertDatabaseHas('comprobantes', [
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'total' => 410,
        ]);

        foreach ($variants as $index => [$typeCode, $variantCode, $sex, $presentation]) {
            $this->assertDatabaseHas('pesadas', [
                'ticket_id' => $ticketId,
                'numero' => $index + 1,
                'tipo_pollo_id' => $this->typeIds[$typeCode],
                'sexo' => $sex,
                'presentacion_pollo' => $presentation,
                'proveedor_origen_id' => null,
                'almacen_origen_id' => null,
                'vehiculo_id' => null,
                'programacion_recepcion_detalle_id' => null,
            ]);
            $response
                ->assertJsonPath("data.weighings.{$index}.chicken_type_code", $typeCode)
                ->assertJsonPath("data.weighings.{$index}.chicken_variant_code", $variantCode)
                ->assertJsonPath("data.weighings.{$index}.chicken_sex", $sex)
                ->assertJsonPath("data.weighings.{$index}.chicken_presentation", $presentation);
        }
    }

    public function test_gallinas_and_others_store_independent_adjustments_and_manual_ticket_prices(): void
    {
        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')->assertOk();
        DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::HEN_RED)
            ->update(['gramos_adicionales' => 100]);
        DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::HEN_DOUBLE)
            ->update(['gramos_adicionales' => 200]);
        DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::OTHER)
            ->update(['gramos_adicionales' => 900]);

        $payload = $this->ticketPayload();
        $red = $this->weighing(1, TipoPollo::HEN_RED, WholesaleTwoChickenVariant::HEN_RED);
        $red['birds_per_cage'] = 10;
        $red['read_weight_kg'] = 20;
        $double = $this->weighing(2, TipoPollo::HEN_DOUBLE, WholesaleTwoChickenVariant::HEN_DOUBLE);
        $double['birds_per_cage'] = 5;
        $double['read_weight_kg'] = 30;
        $other = $this->weighing(3, TipoPollo::OTHER, WholesaleTwoChickenVariant::OTHER);
        $other['read_weight_kg'] = 7.5;
        $payload['weighings'] = [$red, $double, $other];
        $payload['manual_prices'] = [
            TipoPollo::HEN_RED => 8.5,
            TipoPollo::HEN_DOUBLE => 9.25,
            TipoPollo::OTHER => 4,
        ];

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.prices.GALLINA_ROJA.price_kg', 8.5)
            ->assertJsonPath('data.prices.GALLINA_ROJA.source', 'MANUAL')
            ->assertJsonPath('data.prices.GALLINA_ROJA.history_id', null)
            ->assertJsonPath('data.prices.GALLINA_DOBLE.price_kg', 9.25)
            ->assertJsonPath('data.prices.GALLINA_DOBLE.source', 'MANUAL')
            ->assertJsonPath('data.prices.OTROS.price_kg', 4)
            ->assertJsonPath('data.prices.OTROS.source', 'MANUAL')
            ->assertJsonPath('data.totals.net_weight_kg', 59.5)
            ->assertJsonPath('data.totals.amount', 495.25)
            ->assertJsonPath('data.weighings.0.chicken_variant_code', WholesaleTwoChickenVariant::HEN_RED)
            ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 100)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 21)
            ->assertJsonPath('data.weighings.0.price_kg', 8.5)
            ->assertJsonPath('data.weighings.0.price_origin', 'MANUAL')
            ->assertJsonPath('data.weighings.0.price_history_id', null)
            ->assertJsonPath('data.weighings.0.amount', 178.5)
            ->assertJsonPath('data.weighings.1.chicken_variant_code', WholesaleTwoChickenVariant::HEN_DOUBLE)
            ->assertJsonPath('data.weighings.1.adjustment.additional_grams', 200)
            ->assertJsonPath('data.weighings.1.net_weight_kg', 31)
            ->assertJsonPath('data.weighings.1.amount', 286.75)
            ->assertJsonPath('data.weighings.2.chicken_variant_code', WholesaleTwoChickenVariant::OTHER)
            ->assertJsonPath('data.weighings.2.adjustment.code', WholesaleTwoChickenVariant::OTHER)
            ->assertJsonPath('data.weighings.2.adjustment.additional_grams', 0)
            ->assertJsonPath('data.weighings.2.adjustment.configurable', false)
            ->assertJsonPath('data.weighings.2.gross_weight_kg', 7.5)
            ->assertJsonPath('data.weighings.2.price_kg', 4)
            ->assertJsonPath('data.weighings.2.amount', 30);

        $ticketId = (int) $response->json('data.id');
        foreach ($payload['manual_prices'] as $code => $price) {
            $this->assertDatabaseHas('ticket_precios', [
                'ticket_id' => $ticketId,
                'tipo_pollo_id' => $this->typeIds[$code],
                'precio_historial_id' => null,
                'precio_kg' => $price,
                'origen_precio' => 'MANUAL',
            ]);
        }
        $this->assertDatabaseCount('ticket_precios', 3);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_RED],
            'ajuste_peso_mayorista_2_gramos' => 100,
            'peso_bruto_kg' => 21,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_DOUBLE],
            'ajuste_peso_mayorista_2_gramos' => 200,
            'peso_bruto_kg' => 31,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $this->typeIds[TipoPollo::OTHER],
            'ajuste_peso_mayorista_2_gramos' => 0,
            'peso_bruto_kg' => 7.5,
        ]);
        $this->assertDatabaseHas('ajustes_peso_mayorista_2', [
            'empresa_id' => $this->user->empresa_id,
            'codigo' => WholesaleTwoChickenVariant::OTHER,
            'gramos_adicionales' => 0,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$ticketId}",
            'total' => 495.25,
        ]);
    }

    public function test_special_manual_prices_must_match_exactly_the_products_in_the_ticket(): void
    {
        $missing = $this->ticketPayload();
        $missing['weighings'] = [
            $this->weighing(1, TipoPollo::HEN_RED, WholesaleTwoChickenVariant::HEN_RED),
        ];
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manual_prices');

        $partial = $this->ticketPayload();
        $partial['weighings'] = [
            $this->weighing(1, TipoPollo::HEN_RED, WholesaleTwoChickenVariant::HEN_RED),
            $this->weighing(2, TipoPollo::HEN_DOUBLE, WholesaleTwoChickenVariant::HEN_DOUBLE),
        ];
        $partial['manual_prices'] = [TipoPollo::HEN_RED => 8.5];
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $partial)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'manual_prices',
                'manual_prices.'.TipoPollo::HEN_DOUBLE,
            ]);

        $extra = $this->ticketPayload();
        $extra['manual_prices'] = [TipoPollo::OTHER => 4];
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $extra)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manual_prices');

        $precision = $this->ticketPayload();
        $precision['weighings'] = [
            $this->weighing(1, TipoPollo::OTHER, WholesaleTwoChickenVariant::OTHER),
        ];
        $precision['manual_prices'] = [TipoPollo::OTHER => 4.001];
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $precision)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manual_prices.'.TipoPollo::OTHER);

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('ticket_precios', 0);
    }

    public function test_standard_and_special_prices_are_frozen_together_without_a_special_client_rate(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][] = $this->weighing(
            2,
            TipoPollo::HEN_RED,
            WholesaleTwoChickenVariant::HEN_RED,
        );
        $payload['manual_prices'] = [TipoPollo::HEN_RED => 8.5];

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.prices.POLLO_VIVO.price_kg', 5)
            ->assertJsonPath('data.prices.POLLO_VIVO.source', 'CLIENTE')
            ->assertJsonPath('data.prices.GALLINA_ROJA.price_kg', 8.5)
            ->assertJsonPath('data.prices.GALLINA_ROJA.source', 'MANUAL')
            ->assertJsonPath('data.prices.GALLINA_ROJA.history_id', null)
            ->assertJsonPath('data.totals.amount', 135);

        $this->assertDatabaseCount('ticket_precios', 2);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $response->json('data.id'),
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_RED],
            'precio_historial_id' => null,
            'precio_kg' => 8.5,
            'origen_precio' => 'MANUAL',
        ]);
    }

    public function test_client_hen_price_is_the_fallback_but_a_manual_ticket_price_has_priority(): void
    {
        $listId = (int) DB::table('listas_precios')
            ->where('tercero_id', $this->clientId)
            ->where('operacion', 'VENTA')
            ->value('id');
        $clientPriceHistoryId = (int) DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_RED],
            'precio_kg' => 7.75,
            'vigente_desde' => now()->subMinute(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Tarifa de gallina para el cliente',
            'reemplaza_precio_id' => null,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);

        $clientFallback = $this->ticketPayload();
        $clientFallback['weighings'] = [
            $this->weighing(1, TipoPollo::HEN_RED, WholesaleTwoChickenVariant::HEN_RED),
        ];
        $fallbackResponse = $this->postJson(
            '/api/v1/despacho-mayorista-2/tickets',
            $clientFallback,
        )->assertCreated()
            ->assertJsonPath('data.prices.GALLINA_ROJA.price_kg', 7.75)
            ->assertJsonPath('data.prices.GALLINA_ROJA.source', 'CLIENTE')
            ->assertJsonPath('data.prices.GALLINA_ROJA.history_id', $clientPriceHistoryId)
            ->assertJsonPath('data.weighings.0.amount', 77.5);

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $fallbackResponse->json('data.id'),
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_RED],
            'precio_historial_id' => $clientPriceHistoryId,
            'precio_kg' => 7.75,
            'origen_precio' => 'CLIENTE',
        ]);

        $manualOverride = $this->ticketPayload();
        $manualOverride['weighings'] = [
            $this->weighing(1, TipoPollo::HEN_RED, WholesaleTwoChickenVariant::HEN_RED),
        ];
        $manualOverride['manual_prices'] = [TipoPollo::HEN_RED => 9.1];
        $manualResponse = $this->postJson(
            '/api/v1/despacho-mayorista-2/tickets',
            $manualOverride,
        )->assertCreated()
            ->assertJsonPath('data.prices.GALLINA_ROJA.price_kg', 9.1)
            ->assertJsonPath('data.prices.GALLINA_ROJA.source', 'MANUAL')
            ->assertJsonPath('data.prices.GALLINA_ROJA.history_id', null)
            ->assertJsonPath('data.weighings.0.amount', 91);

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $manualResponse->json('data.id'),
            'tipo_pollo_id' => $this->typeIds[TipoPollo::HEN_RED],
            'precio_historial_id' => null,
            'precio_kg' => 9.1,
            'origen_precio' => 'MANUAL',
        ]);

        app(TerceroDirectoryService::class)->update(
            Tercero::query()->findOrFail($this->clientId),
            (int) $this->user->id,
            TerceroRole::CLIENT,
            [
                'nombre_razon_social' => 'Cliente interno',
                'numero_documento' => '20111111111',
                'direccion' => 'Av. Principal 123',
                'precios' => [TipoPollo::HEN_RED => 8.25],
            ],
        );
        $updatedHistoryId = (int) DB::table('precios_historial')
            ->where('lista_precio_id', $listId)
            ->where('tipo_pollo_id', $this->typeIds[TipoPollo::HEN_RED])
            ->whereNull('vigente_hasta')
            ->value('id');

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $fallbackResponse->json('data.id'),
            'precio_historial_id' => $updatedHistoryId,
            'precio_kg' => 8.25,
            'origen_precio' => 'CLIENTE',
        ]);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $manualResponse->json('data.id'),
            'precio_historial_id' => null,
            'precio_kg' => 9.1,
            'origen_precio' => 'MANUAL',
        ]);
    }

    public function test_catalog_returns_java_680_in_descending_weight_order(): void
    {
        $response = $this->getJson('/api/v1/despacho-mayorista-2/catalogo')
            ->assertOk()
            ->assertJsonCount(3, 'data.cage_types')
            ->assertJsonPath('data.cage_types.0.code', 'JAVA_700')
            ->assertJsonPath('data.cage_types.0.weight_kg', 7)
            ->assertJsonPath('data.cage_types.1.code', 'JAVA_690')
            ->assertJsonPath('data.cage_types.1.weight_kg', 6.9)
            ->assertJsonPath('data.cage_types.2.code', 'JAVA_680')
            ->assertJsonPath('data.cage_types.2.name', 'Java 6.80 kg')
            ->assertJsonPath('data.cage_types.2.weight_kg', 6.8);

        $this->assertEmpty(array_diff(
            TipoPollo::wholesaleTwoManualPriceCodes(),
            collect($response->json('data.chicken_types'))->pluck('code')->all(),
        ));
    }

    public function test_java_680_weight_is_frozen_and_used_for_wholesale_two_tare_and_net_weight(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][0] = [
            ...$payload['weighings'][0],
            'cage_type_code' => 'JAVA_680',
            'birds_per_cage' => 5,
            'cage_count' => 2,
            'read_weight_kg' => 30,
            'gross_weight_kg' => 999,
        ];

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighing_count', 1)
            ->assertJsonPath('data.totals.read_weight_kg', 30)
            ->assertJsonPath('data.totals.adjustment_weight_kg', 0)
            ->assertJsonPath('data.totals.gross_weight_kg', 30)
            ->assertJsonPath('data.totals.tare_weight_kg', 13.6)
            ->assertJsonPath('data.totals.net_weight_kg', 16.4)
            ->assertJsonPath('data.weighings.0.cage_type_code', 'JAVA_680')
            ->assertJsonPath('data.weighings.0.cage_type', 'Java 6.80 kg')
            ->assertJsonPath('data.weighings.0.cage_weight_kg', 6.8)
            ->assertJsonPath('data.weighings.0.tare_weight_kg', 13.6)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 16.4);

        $java680Id = DB::table('tipos_java')
            ->where('codigo', 'JAVA_680')
            ->value('id');

        $this->assertNotNull($java680Id);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $response->json('data.id'),
            'tipo_java_id' => $java680Id,
            'cantidad_javas' => 2,
            'peso_java_kg_snapshot' => 6.8,
            'peso_leido_kg' => 30,
            'peso_bruto_kg' => 30,
            'tara_total_kg' => 13.6,
            'peso_neto_kg' => 16.4,
        ]);
    }

    public function test_wholesale_two_rejects_missing_or_incompatible_variants_and_spoofed_sex(): void
    {
        $cases = [
            [TipoPollo::CHICKEN_LIVE, null, null, 'weighings.0.chicken_variant_code'],
            [TipoPollo::CHICKEN_LIVE, WholesaleTwoChickenVariant::MALE_OPEN, null, 'weighings.0.chicken_variant_code'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::MALE, null, 'weighings.0.chicken_variant_code'],
            [TipoPollo::CHICKEN_PROCESSED, WholesaleTwoChickenVariant::MALE_CLOSED, null, 'weighings.0.chicken_variant_code'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::PROCESSED, null, 'weighings.0.chicken_variant_code'],
            [TipoPollo::CHICKEN_PROCESSED, WholesaleTwoChickenVariant::PROCESSED, Pesada::SEX_MALE, 'weighings.0.chicken_sex'],
            [TipoPollo::CHICKEN_DRESSED, WholesaleTwoChickenVariant::FEMALE_OPEN, Pesada::SEX_MALE, 'weighings.0.chicken_sex'],
        ];

        foreach ($cases as [$typeCode, $variantCode, $sex, $errorField]) {
            $payload = $this->ticketPayload();
            $payload['weighings'] = [$this->weighing(1, $typeCode, $variantCode, null, $sex)];

            $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_original_wholesale_still_requires_an_origin_while_wholesale_two_does_not(): void
    {
        $wholesaleTwoPayload = $this->ticketPayload();

        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $wholesaleTwoPayload)
            ->assertCreated();

        $wholesaleOnePayload = $this->originalWholesalePayload($this->ticketPayload());
        $this->postJson('/api/v1/operacion/tickets', $wholesaleOnePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.origin.type');

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseHas('tickets_despacho', [
            'referencia_externa' => $wholesaleTwoPayload['draft_id'],
            'modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO,
        ]);
    }

    public function test_partial_origin_is_rejected_instead_of_being_silently_ignored(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][0]['origin'] = ['provider_id' => 999];

        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.origin.type');

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pesadas', 0);
    }

    public function test_selected_provider_keeps_journey_vehicle_and_plate_validation(): void
    {
        $origin = $this->createProviderOrigin();
        $payload = $this->ticketPayload(origin: $origin['payload']);

        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('journey');
        $this->assertDatabaseCount('tickets_despacho', 0);

        $programDetailId = $this->configureJourney($origin['provider_vehicle_id']);
        $payload['draft_id'] = (string) Str::uuid();
        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated();
        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'proveedor_origen_id' => $origin['provider_id'],
            'vehiculo_id' => $origin['vehicle_id'],
            'programacion_recepcion_detalle_id' => $programDetailId,
            'placa_snapshot' => 'ORI-002',
        ]);

        $payload['draft_id'] = (string) Str::uuid();
        $payload['weighings'][0]['origin']['plate'] = 'NO-EXISTE';
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.origin.plate');
        $this->assertDatabaseCount('tickets_despacho', 1);
    }

    public function test_idempotency_is_owned_by_each_wholesale_module(): void
    {
        $origin = $this->createProviderOrigin();
        $this->configureJourney($origin['provider_vehicle_id']);

        $wholesaleTwoPayload = $this->ticketPayload(origin: $origin['payload']);
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $wholesaleTwoPayload)
            ->assertCreated();
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $wholesaleTwoPayload)
            ->assertOk()
            ->assertJsonPath('already_registered', true);
        $this->postJson(
            '/api/v1/operacion/tickets',
            $this->originalWholesalePayload($wholesaleTwoPayload),
        )->assertUnprocessable()->assertJsonValidationErrors('draft_id');

        $wholesaleOnePayload = $this->originalWholesalePayload(
            $this->ticketPayload(origin: $origin['payload'])
        );
        $this->postJson('/api/v1/operacion/tickets', $wholesaleOnePayload)
            ->assertCreated();
        $wholesaleTwoCollision = $this->ticketPayload(
            draftId: $wholesaleOnePayload['draft_id'],
            origin: $origin['payload'],
        );
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', $wholesaleTwoCollision)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_id');

        $this->assertDatabaseCount('tickets_despacho', 2);
        $this->assertDatabaseHas('tickets_despacho', [
            'referencia_externa' => $wholesaleOnePayload['draft_id'],
            'modulo_origen' => null,
        ]);
        $this->assertDatabaseHas('tickets_despacho', [
            'referencia_externa' => $wholesaleTwoPayload['draft_id'],
            'modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO,
        ]);
    }

    public function test_wholesale_two_return_keeps_existing_return_semantics_without_an_origin(): void
    {
        $payload = $this->ticketPayload();
        $payload['operation_type'] = TicketDespacho::OPERATION_RETURN;
        $payload['weighings'][0]['chicken_condition'] = Pesada::CHICKEN_CONDITION_DEAD;
        $payload['weighings'][0]['chicken_sex'] = Pesada::SEX_FEMALE;
        unset($payload['weighings'][0]['chicken_variant_code']);
        unset($payload['weighings'][0]['gross_weight_kg']);

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.weighings.0.chicken_condition', Pesada::CHICKEN_CONDITION_DEAD)
            ->assertJsonPath('data.weighings.0.chicken_sex', Pesada::SEX_FEMALE)
            ->assertJsonPath('data.weighings.0.adjustment', null)
            ->assertJsonPath('data.weighings.0.gross_weight_kg', 10);

        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $response->json('data.id'),
            'tipo_pollo_id' => $this->typeIds[TipoPollo::CHICKEN_DEAD],
            'sexo' => Pesada::SEX_FEMALE,
            'presentacion_pollo' => null,
            'proveedor_origen_id' => null,
            'ajuste_peso_mayorista_2_id' => null,
            'ajuste_peso_mayorista_2_gramos' => 0,
            'peso_leido_kg' => 10,
            'peso_bruto_kg' => 10,
        ]);
        $this->assertDatabaseCount('programaciones_recepcion', 0);
    }

    public function test_dispatch_without_provider_creates_receivable_and_java_movement_and_voids_cleanly(): void
    {
        $payload = $this->ticketPayload();
        $payload['weighings'][0] = [
            ...$payload['weighings'][0],
            'birds_per_cage' => 10,
            'cage_count' => 2,
            'read_weight_kg' => 30,
            'gross_weight_kg' => 30,
        ];

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated();
        $ticketId = (int) $response->json('data.id');
        $documentId = (int) DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET:{$ticketId}")
            ->value('id');

        $this->assertGreaterThan(0, $documentId);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'origen_clave' => "VENTA:TICKET:{$ticketId}",
            'total' => 80,
            'saldo_pendiente' => 80,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('comprobante_tickets', [
            'comprobante_id' => $documentId,
            'ticket_id' => $ticketId,
            'importe_aplicado' => 80,
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'cliente_id' => $this->clientId,
            'tipo' => 'DESPACHO',
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'proveedor_origen_id' => null,
            'cantidad_javas' => 2,
            'cantidad_aves' => 20,
            'peso_neto_kg' => 16,
        ]);
        $this->assertDatabaseMissing('comprobantes', ['operacion' => 'COMPRA']);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseCount('compras', 0);
        $this->assertDatabaseCount('compra_detalles', 0);

        $administrator = $this->createUserForCompany($this->user, [
            'sucursal_id' => $this->branchId,
        ]);
        $this->makeAdministrator($administrator);
        Sanctum::actingAs($administrator, ['api']);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Corrección de prueba M2 sin proveedor',
        ])->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED)
            ->assertJsonPath('meta.idempotent', false);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'estado' => TicketDespacho::STATUS_VOIDED,
            'modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'estado' => Pesada::STATUS_VOIDED,
        ]);
        $this->assertDatabaseMissing('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'ANULADO',
            'anulada_por' => $administrator->id,
        ]);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseCount('compras', 0);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Reintento idempotente',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true);
    }

    public function test_wholesale_two_calculates_adjustment_from_read_weight_and_ignores_submitted_gross_weight(): void
    {
        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')
            ->assertOk();
        $adjustmentId = (int) DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::MALE)
            ->value('id');
        DB::table('ajustes_peso_mayorista_2')
            ->where('id', $adjustmentId)
            ->update(['gramos_adicionales' => 150]);

        $payload = $this->ticketPayload();
        $payload['weighings'][0] = [
            ...$payload['weighings'][0],
            'birds_per_cage' => 10,
            'cage_count' => 2,
            'read_weight_kg' => 30,
            'gross_weight_kg' => 999,
        ];

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.totals.read_weight_kg', 30)
            ->assertJsonPath('data.totals.adjustment_weight_kg', 3)
            ->assertJsonPath('data.totals.gross_weight_kg', 33)
            ->assertJsonPath('data.totals.tare_weight_kg', 14)
            ->assertJsonPath('data.totals.net_weight_kg', 19)
            ->assertJsonPath('data.weighings.0.adjustment.code', WholesaleTwoChickenVariant::MALE)
            ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 150)
            ->assertJsonPath('data.weighings.0.adjustment.total_grams', 3000)
            ->assertJsonPath('data.weighings.0.adjustment.total_weight_kg', 3)
            ->assertJsonPath('data.weighings.0.read_weight_kg', 30)
            ->assertJsonPath('data.weighings.0.gross_weight_kg', 33)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 19);
        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'ajuste_peso_minorista_id' => null,
            'ajuste_peso_mayorista_2_id' => $adjustmentId,
            'ajuste_peso_gramos' => null,
            'ajuste_peso_mayorista_2_gramos' => 150,
            'cantidad_aves' => 20,
            'peso_leido_kg' => 30,
            'peso_bruto_kg' => 33,
            'tara_total_kg' => 14,
            'peso_neto_kg' => 19,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$ticketId}",
            'total' => 95,
            'saldo_pendiente' => 95,
        ]);

        DB::table('ajustes_peso_mayorista_2')
            ->where('id', $adjustmentId)
            ->update(['gramos_adicionales' => 300]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'ajuste_peso_mayorista_2_gramos' => 150,
            'peso_neto_kg' => 19,
        ]);
    }

    public function test_processed_chicken_forces_zero_adjustment_and_accepts_missing_gross_weight(): void
    {
        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')
            ->assertOk();
        DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::PROCESSED)
            ->update(['gramos_adicionales' => 900]);
        $payload = $this->ticketPayload();
        $payload['weighings'] = [$this->weighing(
            1,
            TipoPollo::CHICKEN_PROCESSED,
            WholesaleTwoChickenVariant::PROCESSED,
        )];
        $payload['weighings'][0]['birds_per_cage'] = 5;
        unset($payload['weighings'][0]['gross_weight_kg']);

        $response = $this->postJson('/api/v1/despacho-mayorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.adjustment.code', WholesaleTwoChickenVariant::PROCESSED)
            ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 0)
            ->assertJsonPath('data.weighings.0.adjustment.total_weight_kg', 0)
            ->assertJsonPath('data.weighings.0.adjustment.configurable', false)
            ->assertJsonPath('data.weighings.0.read_weight_kg', 10)
            ->assertJsonPath('data.weighings.0.gross_weight_kg', 10)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 10);

        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $response->json('data.id'),
            'ajuste_peso_mayorista_2_gramos' => 0,
            'cantidad_aves' => 5,
            'peso_leido_kg' => 10,
            'peso_bruto_kg' => 10,
            'peso_neto_kg' => 10,
        ]);
        $this->assertDatabaseHas('ajustes_peso_mayorista_2', [
            'empresa_id' => $this->user->empresa_id,
            'codigo' => WholesaleTwoChickenVariant::PROCESSED,
            'gramos_adicionales' => 0,
        ]);
    }

    public function test_original_wholesale_keeps_submitted_gross_weight_and_has_no_wholesale_two_adjustment(): void
    {
        $origin = $this->createProviderOrigin();
        $this->configureJourney($origin['provider_vehicle_id']);
        $payload = $this->originalWholesalePayload(
            $this->ticketPayload(origin: $origin['payload'])
        );
        $payload['weighings'][0]['read_weight_kg'] = 10;
        $payload['weighings'][0]['gross_weight_kg'] = 12;

        $response = $this->postJson('/api/v1/operacion/tickets', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $response->json('data.id'),
            'peso_leido_kg' => 10,
            'peso_bruto_kg' => 12,
            'peso_neto_kg' => 12,
            'ajuste_peso_mayorista_2_id' => null,
            'ajuste_peso_mayorista_2_gramos' => null,
        ]);
        $this->assertDatabaseCount('ajustes_peso_mayorista_2', 0);
    }

    /** @return array<string, mixed> */
    private function ticketPayload(
        ?string $draftId = null,
        ?array $origin = null,
    ): array {
        return [
            'draft_id' => $draftId ?? (string) Str::uuid(),
            'operation_type' => TicketDespacho::OPERATION_DISPATCH,
            'destination' => [
                'type' => 'CLIENTE',
                'id' => $this->clientId,
            ],
            'weighings' => [
                $this->weighing(
                    1,
                    TipoPollo::CHICKEN_LIVE,
                    WholesaleTwoChickenVariant::MALE,
                    $origin,
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function weighing(
        int $localId,
        string $typeCode,
        ?string $variantCode,
        ?array $origin = null,
        ?string $sex = null,
    ): array {
        $weighing = [
            'local_id' => $localId,
            'chicken_type_code' => $typeCode,
            'chicken_variant_code' => $variantCode,
            'chicken_sex' => $sex,
            'cage_type_code' => 'JAVA_700',
            'weight_source' => 'MANUAL',
            'birds_per_cage' => 1,
            'cage_count' => 0,
            'read_weight_kg' => 10,
            'gross_weight_kg' => 10,
            'weighed_at' => now('America/Lima')->subMinute()->toIso8601String(),
        ];

        if ($origin !== null) {
            $weighing['origin'] = $origin;
        }
        if ($variantCode === null) {
            unset($weighing['chicken_variant_code']);
        }
        if ($sex === null) {
            $weighing['chicken_sex'] = null;
        }

        return $weighing;
    }

    /** @param array<string, mixed> $payload */
    private function originalWholesalePayload(array $payload): array
    {
        $payload['weighings'] = collect($payload['weighings'])
            ->map(function (array $weighing): array {
                unset($weighing['chicken_variant_code']);
                $weighing['chicken_sex'] = Pesada::SEX_MALE;

                return $weighing;
            })
            ->all();

        return $payload;
    }

    private function createClientPrices(): void
    {
        $listId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'CLIENTE-INTERNO',
            'nombre' => 'Lista cliente interno',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prices = [
            TipoPollo::CHICKEN_LIVE => 5,
            TipoPollo::CHICKEN_DRESSED => 6,
            TipoPollo::CHICKEN_PROCESSED => 7,
        ];

        foreach ($prices as $code => $price) {
            DB::table('precios_historial')->insert([
                'lista_precio_id' => $listId,
                'tipo_pollo_id' => $this->typeIds[$code],
                'precio_kg' => $price,
                'vigente_desde' => now()->subMinute(),
                'vigente_hasta' => null,
                'motivo_cambio' => 'Prueba Mayorista 2',
                'reemplaza_precio_id' => null,
                'registrado_por' => $this->user->id,
                'created_at' => now(),
            ]);
        }
    }

    private function createParty(
        string $role,
        string $name,
        string $document,
        bool $internalClient = false,
    ): int {
        $partyId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Principal 123',
            'es_cliente_interno' => $internalClient,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $partyId,
            'rol' => $role,
            'created_at' => now(),
        ]);

        return $partyId;
    }

    /** @return array{provider_id: int, vehicle_id: int, provider_vehicle_id: int, payload: array<string, mixed>} */
    private function createProviderOrigin(): array
    {
        $providerId = $this->createParty(
            TerceroRole::PROVIDER,
            'Proveedor de origen',
            '20222222222',
        );
        $vehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'ORI-002',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerVehicleId = DB::table('proveedor_vehiculos')->insertGetId([
            'proveedor_id' => $providerId,
            'vehiculo_id' => $vehicleId,
            'vigente_desde' => now()->subDay()->toDateString(),
            'vigente_hasta' => null,
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'provider_id' => $providerId,
            'vehicle_id' => $vehicleId,
            'provider_vehicle_id' => $providerVehicleId,
            'payload' => [
                'type' => 'PROVEEDOR',
                'provider_id' => $providerId,
                'provider_vehicle_id' => $providerVehicleId,
                'vehicle_id' => $vehicleId,
                'plate' => 'ORI-002',
            ],
        ];
    }

    private function configureJourney(int $providerVehicleId): int
    {
        $localNow = now('America/Lima');
        $operatingDate = $localNow->format('H:i:s') >= '21:00:00'
            ? $localNow->copy()->addDay()->format('Y-m-d')
            : $localNow->format('Y-m-d');
        $programId = DB::table('programaciones_recepcion')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => $operatingDate,
            'estado' => 'PUBLICADA',
            'publicada_por' => $this->user->id,
            'publicada_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('programacion_recepcion_detalles')->insertGetId([
            'programacion_id' => $programId,
            'proveedor_vehiculo_id' => $providerVehicleId,
            'numero_visita' => 1,
            'orden_llegada' => 1,
            'estado' => 'PENDIENTE',
            'estado_actualizado_por' => $this->user->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
