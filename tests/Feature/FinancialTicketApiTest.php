<?php

namespace Tests\Feature;

use App\Models\ListaPrecio;
use App\Models\MovimientoJava;
use App\Models\Pesada;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class FinancialTicketApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $journeyId;

    private int $sourceClientId;

    private int $replacementClientId;

    private int $firstChickenTypeId;

    private int $secondChickenTypeId;

    private int $javaTypeId;

    /** @var array<int, int> */
    private array $priceHistoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->grantModules(
            $this->user,
            ['MODULO_FINANZAS'],
            'FINANCIAL_TICKETS_TEST',
            'Consulta financiera de tickets',
        );
        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'FIN-TICKETS',
            'nombre' => 'Sucursal tickets financieros',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-07-20',
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-07-20 00:00:00',
            'cierre_programado_at' => '2026-07-20 23:59:59',
            'cerrada_por' => $this->user->id,
            'cerrada_at' => '2026-07-20 23:59:59',
        ]);
        $this->sourceClientId = $this->createClient(
            'Cliente origen tickets',
            '20123456789',
        );
        $this->replacementClientId = $this->createClient(
            'Cliente reemplazo tickets',
            '20987654321',
        );
        $this->firstChickenTypeId = $this->createChickenType(
            'FIN_TIPO_A',
            'Tipo financiero A',
        );
        $this->secondChickenTypeId = $this->createChickenType(
            'FIN_TIPO_B',
            'Tipo financiero B',
        );
        $this->javaTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_FIN_TEST',
            'nombre' => 'Java para tickets financieros',
            'peso_kg' => '1.000',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createSalePriceHistories();

        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_it_requires_a_real_filter_and_accepts_ticket_client_or_complete_datetime_range(): void
    {
        $endpoint = '/api/v1/finanzas/tickets';

        $this->getJson($endpoint)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filtros']);

        $this->getJson($endpoint.'?ticket=%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filtros']);

        $this->getJson($endpoint.'?desde=2026-07-20T10%3A30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hasta']);

        $this->createTicket(
            'FILTRO-001',
            CarbonImmutable::parse('2026-07-20 10:30:45'),
            $this->sourceClientId,
        );
        $this->createTicket(
            'OTRO-001',
            CarbonImmutable::parse('2026-07-20 11:40:00'),
            $this->replacementClientId,
        );

        $this->getJson($endpoint.'?'.http_build_query([
            'ticket' => 'FILTRO-001',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'FILTRO-001');

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente' => '2012345',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.client.id', $this->sourceClientId);

        $this->getJson($endpoint.'?'.http_build_query([
            'desde' => '2026-07-20T10:30',
            'hasta' => '2026-07-20T10:30',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'FILTRO-001');
    }

    public function test_selected_client_filter_uses_the_exact_client_id(): void
    {
        $sameNameClientId = $this->createClient(
            'Cliente origen tickets',
            '20111111111',
        );
        $this->createTicket(
            'CLIENTE-ID-ORIGEN',
            CarbonImmutable::parse('2026-07-20 10:40:00'),
            $this->sourceClientId,
        );
        $this->createTicket(
            'CLIENTE-ID-HOMONIMO',
            CarbonImmutable::parse('2026-07-20 10:41:00'),
            $sameNameClientId,
        );
        $endpoint = '/api/v1/finanzas/tickets';

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente' => 'Cliente origen tickets',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente_id' => $this->sourceClientId,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'CLIENTE-ID-ORIGEN')
            ->assertJsonPath('data.0.client.id', $this->sourceClientId);

        $foreignUser = User::factory()->create();
        $foreignClientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $foreignUser->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20888888888',
            'nombre_razon_social' => 'Cliente de otra empresa',
            'direccion' => 'Dirección externa',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $foreignClientId,
            'rol' => TerceroRole::CLIENT,
            'created_at' => now(),
        ]);

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente_id' => $foreignClientId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente_id']);
    }

    public function test_client_lookup_searches_name_and_document_including_inactive_clients_and_escapes_like_wildcards(): void
    {
        $inactiveClientId = $this->createClient(
            'Cliente Historico Especial',
            '20555000123',
        );
        DB::table('terceros')
            ->where('id', $inactiveClientId)
            ->update(['estado' => 'INACTIVO']);
        $literalWildcardClientId = $this->createClient(
            'Cliente 100% Literal',
            '20666000123',
        );
        $this->createClient(
            'Cliente 100X Literal',
            '20777000123',
        );
        $endpoint = '/api/v1/finanzas/tickets/clientes';

        $this->getJson($endpoint.'?'.http_build_query([
            'buscar' => '  Historico  ',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveClientId)
            ->assertJsonPath('data.0.nombre', 'Cliente Historico Especial')
            ->assertJsonPath('data.0.numero_documento', '20555000123')
            ->assertJsonPath('data.0.estado', 'INACTIVO');

        $this->getJson($endpoint.'?'.http_build_query([
            'buscar' => '5000123',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveClientId);

        $this->getJson($endpoint.'?'.http_build_query([
            'buscar' => '100%',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $literalWildcardClientId);

        $this->getJson($endpoint.'?'.http_build_query([
            'buscar' => str_repeat('A', 121),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['buscar']);
    }

    public function test_client_lookup_is_limited_to_eight_company_customers_and_orders_active_clients_first(): void
    {
        $expectedIds = [];

        foreach (range(2, 8) as $sequence) {
            $expectedIds[] = $this->createClient(
                'Buscador '.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                '210000000'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            );
        }

        $firstInactiveId = $this->createClient(
            'Buscador 01',
            '21100000001',
        );
        DB::table('terceros')->where('id', $firstInactiveId)->update([
            'estado' => 'INACTIVO',
        ]);
        foreach ([9, 10] as $sequence) {
            $inactiveId = $this->createClient(
                'Buscador '.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                '211000000'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            );
            DB::table('terceros')->where('id', $inactiveId)->update([
                'estado' => 'INACTIVO',
            ]);
        }

        $foreignUser = User::factory()->create();
        $foreignClientId = $this->createThirdParty(
            (int) $foreignUser->empresa_id,
            'Buscador 00 Externo',
            '21200000000',
            'ACTIVO',
            TerceroRole::CLIENT,
        );
        $providerId = $this->createThirdParty(
            (int) $this->user->empresa_id,
            'Buscador 00 Proveedor',
            '21300000000',
            'ACTIVO',
            TerceroRole::PROVIDER,
        );

        $response = $this->getJson(
            '/api/v1/finanzas/tickets/clientes?'.http_build_query([
                'buscar' => 'Buscador',
            ]),
        )
            ->assertOk()
            ->assertJsonCount(8, 'data');
        $clients = collect($response->json('data'));

        $this->assertSame(
            [
                'Buscador 02',
                'Buscador 03',
                'Buscador 04',
                'Buscador 05',
                'Buscador 06',
                'Buscador 07',
                'Buscador 08',
                'Buscador 01',
            ],
            $clients->pluck('nombre')->all(),
        );
        $this->assertSame(
            [
                'ACTIVO',
                'ACTIVO',
                'ACTIVO',
                'ACTIVO',
                'ACTIVO',
                'ACTIVO',
                'ACTIVO',
                'INACTIVO',
            ],
            $clients->pluck('estado')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [...$expectedIds, $firstInactiveId],
            $clients->pluck('id')->all(),
        );
        $this->assertNotContains($foreignClientId, $clients->pluck('id')->all());
        $this->assertNotContains($providerId, $clients->pluck('id')->all());
    }

    public function test_exact_client_filter_accepts_an_inactive_historical_customer_and_rejects_ambiguous_or_non_customer_values(): void
    {
        $this->createTicket(
            'CLIENTE-INACTIVO-001',
            CarbonImmutable::parse('2026-07-20 10:42:00'),
            $this->sourceClientId,
        );
        DB::table('terceros')
            ->where('id', $this->sourceClientId)
            ->update(['estado' => 'INACTIVO']);
        $providerId = $this->createThirdParty(
            (int) $this->user->empresa_id,
            'Proveedor sin rol cliente',
            '21400000000',
            'ACTIVO',
            TerceroRole::PROVIDER,
        );
        $endpoint = '/api/v1/finanzas/tickets';

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente_id' => $this->sourceClientId,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'CLIENTE-INACTIVO-001')
            ->assertJsonPath('data.0.client.id', $this->sourceClientId);

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente_id' => $providerId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente_id']);

        $this->getJson($endpoint.'?'.http_build_query([
            'cliente' => 'Cliente origen',
            'cliente_id' => $this->sourceClientId,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente', 'cliente_id']);
    }

    public function test_bulk_adjustment_respects_the_selected_client_id(): void
    {
        $sameNameClientId = $this->createClient(
            'Cliente origen tickets',
            '20222222222',
        );
        $selectedTicket = $this->createTicket(
            'CLIENTE-MASIVO-SELECCIONADO',
            CarbonImmutable::parse('2026-07-20 10:45:00'),
            $this->sourceClientId,
        );
        $otherTicket = $this->createTicket(
            'CLIENTE-MASIVO-HOMONIMO',
            CarbonImmutable::parse('2026-07-20 10:46:00'),
            $sameNameClientId,
        );
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'cliente_id' => $this->sourceClientId,
            'idempotency_key' => $idempotencyKey,
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '1.00',
        ];

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', $payload)
            ->assertOk()
            ->assertJsonPath('data.matched_tickets', 1)
            ->assertJsonPath('data.updated_tickets', 1);

        $this->assertDecimalValue(
            'ticket_precios',
            $selectedTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '9.5000',
            4,
        );
        $this->assertDecimalValue(
            'ticket_precios',
            $otherTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            ...$payload,
            'cliente_id' => $sameNameClientId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_it_uses_a_fixed_page_size_of_thirty_tickets(): void
    {
        $registeredAt = CarbonImmutable::parse('2026-07-20 09:00:00');

        foreach (range(1, 35) as $sequence) {
            $this->createTicket(
                'PAG-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                $registeredAt->addSeconds($sequence),
                $this->sourceClientId,
            );
        }

        $firstPage = $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
            'ticket' => 'PAG-',
            'page' => 1,
        ]))
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('meta.total', 35)
            ->assertJsonPath('data.0.code', 'PAG-035');

        $this->assertSame('PAG-006', $firstPage->json('data.29.code'));

        $secondPage = $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
            'ticket' => 'PAG-',
            'page' => 2,
        ]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('meta.total', 35)
            ->assertJsonPath('data.0.code', 'PAG-005');

        $this->assertSame('PAG-001', $secondPage->json('data.4.code'));
    }

    public function test_it_lists_the_assigned_prices_and_calculates_the_amount_across_multiple_types(): void
    {
        $this->createTicket(
            'MONTO-001',
            CarbonImmutable::parse('2026-07-20 12:15:30'),
            $this->sourceClientId,
            $this->twoTypeValues(),
        );

        $response = $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
            'ticket' => 'MONTO-001',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'MONTO-001')
            ->assertJsonPath('data.0.client.name', 'Cliente origen tickets')
            ->assertJsonPath('data.0.client.document_number', '20123456789')
            ->assertJsonPath('data.0.currency', 'PEN')
            ->assertJsonPath('data.0.amount', '145.00')
            ->assertJsonCount(2, 'data.0.prices')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'code',
                    'client' => ['id', 'name', 'document_number'],
                    'registered_at',
                    'currency',
                    'amount',
                    'prices' => [[
                        'id',
                        'chicken_type' => ['id', 'code', 'name'],
                        'price_kg',
                        'weight_kg',
                        'subtotal',
                    ]],
                ]],
            ]);

        $prices = collect($response->json('data.0.prices'))
            ->keyBy(fn (array $price): int => (int) $price['chicken_type']['id']);

        $this->assertSame('8.5000', $prices->get($this->firstChickenTypeId)['price_kg']);
        $this->assertSame('10.000', $prices->get($this->firstChickenTypeId)['weight_kg']);
        $this->assertSame('85.00', $prices->get($this->firstChickenTypeId)['subtotal']);
        $this->assertSame('12.0000', $prices->get($this->secondChickenTypeId)['price_kg']);
        $this->assertSame('5.000', $prices->get($this->secondChickenTypeId)['weight_kg']);
        $this->assertSame('60.00', $prices->get($this->secondChickenTypeId)['subtotal']);
    }

    public function test_it_edits_an_individual_price_and_changes_the_client_without_losing_the_ticket_values(): void
    {
        $ticket = $this->createTicket(
            'EDITAR-001',
            CarbonImmutable::parse('2026-07-20 14:10:00'),
            $this->sourceClientId,
            $this->twoTypeValues(),
        );

        $this->putJson("/api/v1/finanzas/tickets/{$ticket['id']}/precios", [
            'precios' => [[
                'id' => $ticket['price_ids'][$this->firstChickenTypeId],
                'precio_kg' => '9.1000',
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.code', 'EDITAR-001')
            ->assertJsonPath('data.amount', '151.00');

        $this->assertDecimalValue(
            'ticket_precios',
            $ticket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '9.1000',
            4,
        );
        $this->assertDatabaseHas('ticket_precios', [
            'id' => $ticket['price_ids'][$this->firstChickenTypeId],
            'origen_precio' => 'MANUAL',
            'congelado_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'ticket_precios',
            'entidad_id' => (string) $ticket['price_ids'][$this->firstChickenTypeId],
            'accion' => 'EDITAR_PRECIO',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->sourceClientId,
            'origen_clave' => "VENTA:TICKET:{$ticket['id']}",
            'total' => 151,
        ]);

        $this->createJavaMovement($ticket['id'], $this->sourceClientId);

        $response = $this->putJson(
            "/api/v1/finanzas/tickets/{$ticket['id']}/cliente",
            ['cliente_id' => $this->replacementClientId],
        )
            ->assertOk()
            ->assertJsonPath('data.client.id', $this->replacementClientId)
            ->assertJsonPath('data.client.name', 'Cliente reemplazo tickets')
            ->assertJsonPath('data.amount', '151.00');

        $prices = collect($response->json('data.prices'))
            ->keyBy(fn (array $price): int => (int) $price['chicken_type']['id']);

        $this->assertSame('9.1000', $prices->get($this->firstChickenTypeId)['price_kg']);
        $this->assertSame('12.0000', $prices->get($this->secondChickenTypeId)['price_kg']);
        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticket['id'],
            'cliente_destino_id' => $this->replacementClientId,
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticket['id'],
            'cliente_id' => $this->replacementClientId,
            'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->replacementClientId,
            'origen_clave' => "VENTA:TICKET:{$ticket['id']}",
            'contraparte_nombre_snapshot' => 'Cliente reemplazo tickets',
            'total' => 151,
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CAMBIAR_CLIENTE',
        ]);
        $this->assertSame(
            2,
            DB::table('ticket_precios')
                ->where('ticket_id', $ticket['id'])
                ->where('origen_precio', 'MANUAL')
                ->count(),
        );
    }

    public function test_bulk_adjustment_increases_and_decreases_only_the_selected_type_for_all_filtered_pages(): void
    {
        $registeredAt = CarbonImmutable::parse('2026-07-20 16:00:00');
        $matchingTicketIds = collect();

        foreach (range(1, 35) as $sequence) {
            $ticket = $this->createTicket(
                'MASIVO-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                $registeredAt->addSeconds($sequence),
                $this->sourceClientId,
                $this->twoTypeValues(),
            );
            $matchingTicketIds->push($ticket['id']);
        }

        $excludedTicket = $this->createTicket(
            'EXCLUIDO-001',
            $registeredAt->addMinute(),
            $this->sourceClientId,
            $this->twoTypeValues(),
        );
        $payload = [
            'ticket' => 'MASIVO-',
            'page' => 2,
            'tipo_pollo_id' => $this->firstChickenTypeId,
        ];

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            ...$payload,
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'AUMENTAR',
            'monto' => '0.10',
        ])
            ->assertOk()
            ->assertJsonPath('data.matched_tickets', 35)
            ->assertJsonPath('data.updated_tickets', 35)
            ->assertJsonPath('data.updated_prices', 35)
            ->assertJsonPath('data.tickets_without_type', 0);

        $this->assertPricesForTickets(
            $matchingTicketIds,
            $this->firstChickenTypeId,
            '8.6000',
        );
        $this->assertPricesForTickets(
            $matchingTicketIds,
            $this->secondChickenTypeId,
            '12.0000',
        );

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            ...$payload,
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'DISMINUIR',
            'monto' => '0.60',
        ])
            ->assertOk()
            ->assertJsonPath('data.matched_tickets', 35)
            ->assertJsonPath('data.updated_tickets', 35)
            ->assertJsonPath('data.updated_prices', 35)
            ->assertJsonPath('data.tickets_without_type', 0);

        $this->assertPricesForTickets(
            $matchingTicketIds,
            $this->firstChickenTypeId,
            '8.0000',
        );
        $this->assertPricesForTickets(
            $matchingTicketIds,
            $this->secondChickenTypeId,
            '12.0000',
        );
        $this->assertDecimalValue(
            'ticket_precios',
            $excludedTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );
        $this->assertSame(
            70,
            DB::table('auditoria_eventos')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('entidad', 'ticket_precios')
                ->where('accion', 'AJUSTE_MASIVO')
                ->count(),
        );

        $documents = DB::table('comprobantes')
            ->whereIn(
                'origen_clave',
                $matchingTicketIds->map(
                    fn (int $ticketId): string => "VENTA:TICKET:{$ticketId}",
                ),
            )
            ->get(['total']);

        $this->assertCount(35, $documents);
        $this->assertTrue(
            $documents->every(
                fn (object $document): bool => bccomp((string) $document->total, '140.00', 2) === 0,
            ),
        );
        $this->assertDatabaseMissing('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$excludedTicket['id']}",
        ]);
    }

    public function test_bulk_decrease_is_atomic_when_one_filtered_price_would_become_zero_or_negative(): void
    {
        $firstTicket = $this->createTicket(
            'ATOMICO-001',
            CarbonImmutable::parse('2026-07-20 17:00:00'),
            $this->sourceClientId,
            [
                $this->firstChickenTypeId => [
                    'price' => '8.5000',
                    'weight' => '10.000',
                    'cages' => 1,
                ],
            ],
        );
        $secondTicket = $this->createTicket(
            'ATOMICO-002',
            CarbonImmutable::parse('2026-07-20 17:01:00'),
            $this->sourceClientId,
            [
                $this->firstChickenTypeId => [
                    'price' => '0.5000',
                    'weight' => '10.000',
                    'cages' => 1,
                ],
            ],
        );

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            'ticket' => 'ATOMICO-',
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'DISMINUIR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '1.0000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto']);

        $this->assertDecimalValue(
            'ticket_precios',
            $firstTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );
        $this->assertDecimalValue(
            'ticket_precios',
            $secondTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '0.5000',
            4,
        );
        $this->assertSame(
            0,
            DB::table('auditoria_eventos')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('entidad', 'ticket_precios')
                ->where('accion', 'AJUSTE_MASIVO')
                ->count(),
        );
        $this->assertDatabaseMissing('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$firstTicket['id']}",
        ]);
        $this->assertDatabaseMissing('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$secondTicket['id']}",
        ]);
    }

    public function test_wildcards_cannot_bypass_the_required_filter_and_are_literal_when_combined_with_text(): void
    {
        $endpoint = '/api/v1/finanzas/tickets';

        $this->getJson($endpoint.'?'.http_build_query(['ticket' => '%']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket']);
        $this->getJson($endpoint.'?'.http_build_query(['cliente' => '_%_']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente']);

        $this->createTicket(
            'LITERAL%001',
            CarbonImmutable::parse('2026-07-20 18:00:00'),
            $this->sourceClientId,
        );
        $this->createTicket(
            'LITERALX001',
            CarbonImmutable::parse('2026-07-20 18:01:00'),
            $this->sourceClientId,
        );

        $this->getJson($endpoint.'?'.http_build_query(['ticket' => 'LITERAL%']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'LITERAL%001');
    }

    public function test_datetime_filters_and_output_use_the_company_timezone(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['zona_horaria' => 'America/Los_Angeles']);
        $this->createTicket(
            'ZONA-001',
            CarbonImmutable::parse('2026-07-20 12:30:45', 'America/Lima'),
            $this->sourceClientId,
        );
        $endpoint = '/api/v1/finanzas/tickets';

        $this->getJson($endpoint.'?'.http_build_query([
            'desde' => '2026-07-20T10:30',
            'hasta' => '2026-07-20T10:30',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.timezone', 'America/Los_Angeles')
            ->assertJsonPath('data.0.code', 'ZONA-001')
            ->assertJsonPath('data.0.registered_at', '2026-07-20T10:30:45-07:00');

        $this->getJson($endpoint.'?'.http_build_query([
            'desde' => '2026-07-20T12:30',
            'hasta' => '2026-07-20T12:30',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_bulk_adjustment_is_durably_idempotent_and_rejects_reusing_the_key_with_other_values(): void
    {
        $ticket = $this->createTicket(
            'IDEMPOTENTE-001',
            CarbonImmutable::parse('2026-07-20 18:30:00'),
            $this->sourceClientId,
        );
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'ticket' => 'IDEMPOTENTE-',
            'idempotency_key' => $idempotencyKey,
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '0.10',
        ];

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', $payload)
            ->assertOk()
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.updated_prices', 1);
        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            ...$payload,
            'page' => 99,
            'monto' => '0.1000',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Este ajuste masivo ya había sido procesado.')
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.updated_prices', 1);

        $this->assertDecimalValue(
            'ticket_precios',
            $ticket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.6000',
            4,
        );
        $this->assertSame(
            1,
            DB::table('auditoria_eventos')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('entidad', 'ticket_precios')
                ->where('accion', 'AJUSTE_MASIVO')
                ->count(),
        );
        $this->assertDatabaseCount('ticket_precio_ajuste_operaciones', 1);
        $this->assertNotNull(
            DB::table('ticket_precio_ajuste_operaciones')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('idempotency_key', $idempotencyKey)
                ->value('resultado'),
        );

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            ...$payload,
            'monto' => '0.20',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
        $this->assertDecimalValue(
            'ticket_precios',
            $ticket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.6000',
            4,
        );
    }

    public function test_bulk_increase_is_atomic_when_a_result_exceeds_the_decimal_column_maximum(): void
    {
        $ticket = $this->createTicket(
            'MAXIMO-001',
            CarbonImmutable::parse('2026-07-20 19:00:00'),
            $this->sourceClientId,
            [
                $this->firstChickenTypeId => [
                    'price' => '99999999.9999',
                    'weight' => '1.000',
                    'cages' => 1,
                ],
            ],
        );
        $idempotencyKey = (string) Str::uuid();

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            'ticket' => 'MAXIMO-',
            'idempotency_key' => $idempotencyKey,
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '0.0001',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monto']);

        $this->assertDecimalValue(
            'ticket_precios',
            $ticket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '99999999.9999',
            4,
        );
        $this->assertDatabaseMissing('ticket_precio_ajuste_operaciones', [
            'empresa_id' => $this->user->empresa_id,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'ticket_precios',
            'accion' => 'AJUSTE_MASIVO',
        ]);
        $this->assertDatabaseMissing('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$ticket['id']}",
        ]);
    }

    public function test_client_change_is_rejected_when_the_locked_ticket_document_has_a_registered_payment(): void
    {
        $ticket = $this->createTicket(
            'COBRADO-001',
            CarbonImmutable::parse('2026-07-20 19:30:00'),
            $this->sourceClientId,
        );
        $this->putJson("/api/v1/finanzas/tickets/{$ticket['id']}/precios", [
            'precios' => [[
                'id' => $ticket['price_ids'][$this->firstChickenTypeId],
                'precio_kg' => '8.5000',
            ]],
        ])->assertOk();
        $document = DB::table('comprobantes')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('origen_clave', "VENTA:TICKET:{$ticket['id']}")
            ->firstOrFail();
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->sourceClientId,
            'codigo' => 'COBRO-TICKET-001',
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $this->sourceClientId,
            'direccion' => 'ENTRADA',
            'fecha_hora' => now(),
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '10.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $document->id,
            'lado' => 'CXC',
            'importe_aplicado' => '10.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('comprobantes')->where('id', $document->id)->update([
            'saldo_pendiente' => '75.00',
            'estado' => 'PARCIAL',
        ]);

        $this->putJson(
            "/api/v1/finanzas/tickets/{$ticket['id']}/cliente",
            ['cliente_id' => $this->replacementClientId],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente_id']);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticket['id'],
            'cliente_destino_id' => $this->sourceClientId,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document->id,
            'tercero_id' => $this->sourceClientId,
            'contraparte_nombre_snapshot' => 'Cliente origen tickets',
        ]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'CAMBIAR_CLIENTE',
        ]);
    }

    public function test_an_administrator_can_list_void_and_restore_a_ticket_from_finance(): void
    {
        $ticket = $this->createTicket(
            'CICLO-FIN-001',
            CarbonImmutable::parse('2026-07-20 20:00:00'),
            $this->sourceClientId,
        );
        $priceId = $ticket['price_ids'][$this->firstChickenTypeId];

        $this->putJson("/api/v1/finanzas/tickets/{$ticket['id']}/precios", [
            'precios' => [[
                'id' => $priceId,
                'precio_kg' => '8.5000',
            ]],
        ])->assertOk();
        $this->createJavaMovement($ticket['id'], $this->sourceClientId);
        $document = DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET:{$ticket['id']}")
            ->firstOrFail();
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->sourceClientId,
            'codigo' => 'COBRO-CICLO-FIN-001',
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $this->sourceClientId,
            'proveedor_id' => null,
            'cuenta_origen_id' => null,
            'cuenta_destino_id' => null,
            'metodo_pago_id' => null,
            'direccion' => 'INGRESO',
            'fecha_hora' => '2026-07-20 20:01:00',
            'metodo' => 'EFECTIVO',
            'referencia' => null,
            'moneda' => 'PEN',
            'importe' => '85.00',
            'estado' => 'REGISTRADO',
            'idempotency_key' => (string) Str::uuid(),
            'reversa_de_pago_id' => null,
            'observaciones' => 'Cobro que debe permanecer reversado',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $document->id,
            'lado' => 'CXC',
            'importe_aplicado' => '85.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('comprobantes')->where('id', $document->id)->update([
            'saldo_pendiente' => '0.00',
            'estado' => 'PAGADO',
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
            'motivo' => 'Corrección solicitada desde Finanzas',
        ])->assertForbidden();

        $this->makeAdministrator($this->user);

        $voidResponse = $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
            'motivo' => 'Corrección solicitada desde Finanzas',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED)
            ->assertJsonPath('data.void_reason', 'Corrección solicitada desde Finanzas');
        $reversePaymentId = (int) $voidResponse->json('data.reversed_payment_ids.0');

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticket['id'],
            'estado' => TicketDespacho::STATUS_VOIDED,
            'anulado_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticket['id'],
            'estado' => Pesada::STATUS_VOIDED,
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseMissing('movimientos_javas', [
            'ticket_despacho_id' => $ticket['id'],
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$ticket['id']}",
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $reversePaymentId,
            'reversa_de_pago_id' => $paymentId,
            'estado' => 'REGISTRADO',
        ]);

        $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
            'ticket' => 'CICLO-FIN-001',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
            'estado' => 'ANULADOS',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'CICLO-FIN-001')
            ->assertJsonPath('data.0.amount', '85.00')
            ->assertJsonPath('data.0.status', TicketDespacho::STATUS_VOIDED)
            ->assertJsonPath('data.0.void_reason', 'Corrección solicitada desde Finanzas')
            ->assertJsonPath('data.0.voided_by.id', $this->user->id)
            ->assertJsonPath('data.0.can_edit_prices', false)
            ->assertJsonPath('data.0.can_change_client', false)
            ->assertJsonPath('data.0.can_void', false)
            ->assertJsonPath('data.0.can_restore', true);

        $restore = $this->postJson(
            "/api/v1/finanzas/tickets/{$ticket['id']}/restablecer",
        )
            ->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_CLOSED)
            ->assertJsonCount(1, 'data.restored_weighing_ids');

        $restoredWeighingId = (int) $restore->json('data.restored_weighing_ids.0');
        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticket['id'],
            'estado' => TicketDespacho::STATUS_CLOSED,
            'anulado_por' => null,
            'anulado_at' => null,
            'motivo_anulacion' => null,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $restoredWeighingId,
            'estado' => Pesada::STATUS_ACTIVE,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticket['id'],
            'cliente_id' => $this->sourceClientId,
            'cantidad' => 1,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'origen_clave' => "VENTA:TICKET:{$ticket['id']}",
            'estado' => 'PENDIENTE',
            'total' => 85,
            'saldo_pendiente' => 85,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $reversePaymentId,
            'reversa_de_pago_id' => $paymentId,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertSame(
            2,
            DB::table('pagos')
                ->where('empresa_id', $this->user->empresa_id)
                ->count(),
        );
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $ticket['id'],
            'accion' => 'RESTABLECER',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'pesadas',
            'entidad_id' => (string) $restoredWeighingId,
            'accion' => 'RESTABLECER_POR_TICKET',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'movimientos_javas',
            'accion' => 'RECREAR_POR_TICKET_RESTABLECIDO',
        ]);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/restablecer")
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonCount(0, 'data.restored_weighing_ids');
        $this->assertSame(
            1,
            DB::table('auditoria_eventos')
                ->where('entidad', 'tickets_despacho')
                ->where('entidad_id', (string) $ticket['id'])
                ->where('accion', 'RESTABLECER')
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('auditoria_eventos')
                ->where('entidad', 'movimientos_javas')
                ->where('accion', 'RECREAR_POR_TICKET_RESTABLECIDO')
                ->count(),
        );
    }

    public function test_void_and_restore_reconcile_java_returns_that_were_registered_before_cancellation(): void
    {
        $ticket = $this->createTicket(
            'CICLO-JAVAS-DEVUELTAS',
            CarbonImmutable::parse('2026-07-20 20:05:00'),
            $this->sourceClientId,
            [
                $this->firstChickenTypeId => [
                    'price' => '8.5000',
                    'weight' => '30.000',
                    'cages' => 3,
                ],
            ],
        );
        $this->createJavaMovement($ticket['id'], $this->sourceClientId);
        $javaMovementId = (int) DB::table('movimientos_javas')
            ->where('ticket_despacho_id', $ticket['id'])
            ->value('id');
        DB::table('movimientos_javas')->insert([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $this->journeyId,
            'cliente_id' => $this->sourceClientId,
            'tipo' => MovimientoJava::TYPE_RECEIPT,
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
            'ticket_despacho_id' => null,
            'vehiculo_id' => null,
            'conductor_id' => null,
            'fecha_movimiento' => '2026-07-20 20:06:00',
            'observaciones' => 'Devolucion previa a la anulacion',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeAdministrator($this->user);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
            'motivo' => 'Ticket emitido por error',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED);

        $this->assertDatabaseHas('movimientos_javas', [
            'id' => $javaMovementId,
            'ticket_despacho_id' => $ticket['id'],
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
        ]);
        $this->assertSame(0, $this->javaBalanceForClient($this->sourceClientId));
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'movimientos_javas',
            'accion' => 'NEUTRALIZAR_POR_TICKET_ANULADO',
        ]);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/restablecer")
            ->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_CLOSED);

        $this->assertDatabaseHas('movimientos_javas', [
            'id' => $javaMovementId,
            'ticket_despacho_id' => $ticket['id'],
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => 3,
            'cantidad_bandejas' => 0,
            'observaciones' => null,
        ]);
        $this->assertSame(
            1,
            DB::table('movimientos_javas')
                ->where('ticket_despacho_id', $ticket['id'])
                ->count(),
        );
        $this->assertSame(1, $this->javaBalanceForClient($this->sourceClientId));
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'movimientos_javas',
            'accion' => 'RESTABLECER_POR_TICKET',
        ]);
        $restoreAudit = DB::table('auditoria_eventos')
            ->where('entidad', 'movimientos_javas')
            ->where('accion', 'RESTABLECER_POR_TICKET')
            ->firstOrFail();
        $this->assertSame(2, (int) json_decode($restoreAudit->datos_antes, true)['cantidad']);
        $this->assertSame(3, (int) json_decode($restoreAudit->datos_despues, true)['cantidad']);
    }

    public function test_void_and_restore_preserve_a_corrected_java_balance(): void
    {
        $ticket = $this->createTicket(
            'CICLO-JAVAS-AJUSTADAS',
            CarbonImmutable::parse('2026-07-20 20:07:00'),
            $this->sourceClientId,
            [
                $this->firstChickenTypeId => [
                    'price' => '8.5000',
                    'weight' => '30.000',
                    'cages' => 3,
                ],
            ],
        );
        $this->createJavaMovement($ticket['id'], $this->sourceClientId);
        DB::table('ajustes_saldos_javas')->insert([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $this->journeyId,
            'cliente_id' => $this->sourceClientId,
            'saldo_anterior_javas' => 3,
            'saldo_nuevo_javas' => 1,
            'diferencia_javas' => -2,
            'saldo_anterior_bandejas' => 0,
            'saldo_nuevo_bandejas' => 0,
            'diferencia_bandejas' => 0,
            'motivo' => 'Corrección previa al ciclo del ticket.',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adjustedBalance = fn (): int => $this->javaBalanceForClient($this->sourceClientId)
            + (int) DB::table('ajustes_saldos_javas')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('cliente_id', $this->sourceClientId)
                ->sum('diferencia_javas');
        $this->makeAdministrator($this->user);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
            'motivo' => 'Prueba de saldo corregido',
        ])->assertOk();
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticket['id'],
            'cantidad' => 2,
        ]);
        $this->assertSame(0, $adjustedBalance());

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/restablecer")
            ->assertOk();
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticket['id'],
            'cantidad' => 3,
        ]);
        $this->assertSame(1, $adjustedBalance());
    }

    public function test_restore_keeps_a_weighing_that_was_voided_individually_before_the_ticket(): void
    {
        $ticket = $this->createTicket(
            'CICLO-PESADAS-001',
            CarbonImmutable::parse('2026-07-20 20:10:00'),
            $this->sourceClientId,
            $this->twoTypeValues(),
        );
        $this->makeAdministrator($this->user);
        $reason = 'Mismo motivo y segundo para probar la auditoría';
        $generatedReason = "Ticket CICLO-PESADAS-001 anulado: {$reason}";
        $records = DB::table('pesadas')
            ->where('ticket_id', $ticket['id'])
            ->orderBy('id')
            ->get();
        $manualRecord = $records->first();
        $ticketRecord = $records->last();

        $this->travelTo(CarbonImmutable::parse('2026-07-20 20:15:00'));
        try {
            $manualBefore = (array) $manualRecord;
            $manualAfter = [
                ...$manualBefore,
                'estado' => Pesada::STATUS_VOIDED,
                'anulada_por' => $this->user->id,
                'anulada_at' => now()->format('Y-m-d H:i:s'),
                'motivo_anulacion' => $generatedReason,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ];
            DB::table('pesadas')->where('id', $manualRecord->id)->update([
                'estado' => $manualAfter['estado'],
                'anulada_por' => $manualAfter['anulada_por'],
                'anulada_at' => $manualAfter['anulada_at'],
                'motivo_anulacion' => $manualAfter['motivo_anulacion'],
                'updated_at' => $manualAfter['updated_at'],
            ]);
            DB::table('auditoria_eventos')->insert([
                'empresa_id' => $this->user->empresa_id,
                'usuario_id' => $this->user->id,
                'entidad' => 'pesadas',
                'entidad_id' => (string) $manualRecord->id,
                'accion' => 'ANULAR',
                'datos_antes' => json_encode($manualBefore, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($manualAfter, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
                'motivo' => $reason,
            ])->assertOk();

            $this->getJson('/api/v1/finanzas/tickets?'.http_build_query([
                'ticket' => 'CICLO-PESADAS-001',
                'estado' => 'ANULADOS',
            ]))
                ->assertOk()
                ->assertJsonPath('data.0.amount', '60.00');

            $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/restablecer")
                ->assertOk()
                ->assertJsonPath('data.restored_weighing_ids.0', $ticketRecord->id)
                ->assertJsonCount(1, 'data.restored_weighing_ids');
        } finally {
            $this->travelBack();
        }

        $this->assertDatabaseHas('pesadas', [
            'id' => $manualRecord->id,
            'estado' => Pesada::STATUS_VOIDED,
            'motivo_anulacion' => $generatedReason,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'id' => $ticketRecord->id,
            'estado' => Pesada::STATUS_ACTIVE,
            'motivo_anulacion' => null,
        ]);
        $this->assertSame(
            0,
            DB::table('auditoria_eventos')
                ->where('entidad', 'pesadas')
                ->where('entidad_id', (string) $manualRecord->id)
                ->where('accion', 'RESTABLECER_POR_TICKET')
                ->count(),
        );
    }

    public function test_bulk_price_adjustment_never_changes_voided_tickets(): void
    {
        $activeTicket = $this->createTicket(
            'ESTADO-MASIVO-ACTIVO',
            CarbonImmutable::parse('2026-07-20 20:20:00'),
            $this->sourceClientId,
        );
        $voidedTicket = $this->createTicket(
            'ESTADO-MASIVO-ANULADO',
            CarbonImmutable::parse('2026-07-20 20:21:00'),
            $this->sourceClientId,
        );
        $this->makeAdministrator($this->user);

        $this->postJson("/api/v1/finanzas/tickets/{$voidedTicket['id']}/anular", [
            'motivo' => 'Excluir del ajuste masivo',
        ])->assertOk();

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            'ticket' => 'ESTADO-MASIVO-',
            'estado' => 'TODOS',
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '1.0000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);
        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            'estado' => 'ANULADOS',
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '1.0000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['estado']);

        $this->assertDecimalValue(
            'ticket_precios',
            $activeTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );
        $this->assertDecimalValue(
            'ticket_precios',
            $voidedTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );

        $this->postJson('/api/v1/finanzas/tickets/ajustar-precios', [
            'ticket' => 'ESTADO-MASIVO-',
            'estado' => 'VIGENTES',
            'idempotency_key' => (string) Str::uuid(),
            'operacion' => 'AUMENTAR',
            'tipo_pollo_id' => $this->firstChickenTypeId,
            'monto' => '1.0000',
        ])
            ->assertOk()
            ->assertJsonPath('data.matched_tickets', 1)
            ->assertJsonPath('data.updated_tickets', 1);

        $this->assertDecimalValue(
            'ticket_precios',
            $activeTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '9.5000',
            4,
        );
        $this->assertDecimalValue(
            'ticket_precios',
            $voidedTicket['price_ids'][$this->firstChickenTypeId],
            'precio_kg',
            '8.5000',
            4,
        );
    }

    public function test_ticket_lifecycle_endpoints_are_isolated_by_company(): void
    {
        $ticket = $this->createTicket(
            'CICLO-EMPRESA-001',
            CarbonImmutable::parse('2026-07-20 20:30:00'),
            $this->sourceClientId,
        );
        $foreignAdministrator = User::factory()->create();
        $this->makeAdministrator($foreignAdministrator);
        Sanctum::actingAs($foreignAdministrator, ['api']);

        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/anular", [
            'motivo' => 'Intento desde otra empresa',
        ])->assertNotFound();
        $this->postJson("/api/v1/finanzas/tickets/{$ticket['id']}/restablecer")
            ->assertNotFound();

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticket['id'],
            'estado' => TicketDespacho::STATUS_CLOSED,
            'anulado_por' => null,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticket['id'],
            'estado' => Pesada::STATUS_ACTIVE,
        ]);
    }

    private function createClient(string $name, string $document): int
    {
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Pruebas financieras 123',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $clientId,
            'rol' => TerceroRole::CLIENT,
            'created_at' => now(),
        ]);

        return $clientId;
    }

    private function createThirdParty(
        int $companyId,
        string $name,
        string $document,
        string $status,
        string $role,
    ): int {
        $thirdPartyId = DB::table('terceros')->insertGetId([
            'empresa_id' => $companyId,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Pruebas financieras 456',
            'estado' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $thirdPartyId,
            'rol' => $role,
            'created_at' => now(),
        ]);

        return $thirdPartyId;
    }

    private function createChickenType(string $code, string $name): int
    {
        return DB::table('tipos_pollo')->insertGetId([
            'codigo' => $code,
            'nombre' => $name,
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSalePriceHistories(): void
    {
        $listId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->sourceClientId,
            'codigo' => 'VENTA-FIN-TICKETS',
            'nombre' => 'Lista para tickets financieros',
            'operacion' => ListaPrecio::OPERATION_SALE,
            'estado' => ListaPrecio::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            $this->firstChickenTypeId => '8.5000',
            $this->secondChickenTypeId => '12.0000',
        ] as $chickenTypeId => $price) {
            $this->priceHistoryIds[$chickenTypeId] = DB::table('precios_historial')
                ->insertGetId([
                    'lista_precio_id' => $listId,
                    'tipo_pollo_id' => $chickenTypeId,
                    'precio_kg' => $price,
                    'vigente_desde' => '2026-07-19 00:00:00',
                    'vigente_hasta' => null,
                    'motivo_cambio' => 'Precio de prueba financiera',
                    'registrado_por' => $this->user->id,
                    'created_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<int, array{price: string, weight: string, cages: int}>|null  $typeValues
     * @return array{id: int, price_ids: array<int, int>}
     */
    private function createTicket(
        string $code,
        CarbonImmutable $registeredAt,
        int $clientId,
        ?array $typeValues = null,
    ): array {
        $typeValues ??= [
            $this->firstChickenTypeId => [
                'price' => '8.5000',
                'weight' => '10.000',
                'cages' => 1,
            ],
        ];
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $this->journeyId,
            'codigo' => $code,
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $clientId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => $registeredAt,
            'created_by' => $this->user->id,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ]);
        $priceIds = [];
        $recordNumber = 0;

        foreach ($typeValues as $chickenTypeId => $values) {
            $recordNumber++;
            $priceIds[$chickenTypeId] = DB::table('ticket_precios')->insertGetId([
                'ticket_id' => $ticketId,
                'tipo_pollo_id' => $chickenTypeId,
                'precio_historial_id' => $this->priceHistoryIds[$chickenTypeId],
                'precio_kg' => $values['price'],
                'origen_precio' => 'CLIENTE',
                'congelado_por' => $this->user->id,
                'created_at' => $registeredAt,
            ]);
            DB::table('pesadas')->insert([
                'ticket_id' => $ticketId,
                'numero' => $recordNumber,
                'tipo_pollo_id' => $chickenTypeId,
                'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
                'sexo' => Pesada::SEX_MALE,
                'tipo_java_id' => $this->javaTypeId,
                'origen_peso' => 'MANUAL',
                'aves_por_java' => 10,
                'cantidad_javas' => $values['cages'],
                'cantidad_bandejas' => 0,
                'cantidad_aves' => 10,
                'peso_java_kg_snapshot' => '1.000',
                'peso_leido_kg' => $values['weight'],
                'peso_bruto_kg' => $values['weight'],
                'tara_total_kg' => '0.000',
                'peso_neto_kg' => $values['weight'],
                'pesada_at' => $registeredAt,
                'estado' => Pesada::STATUS_ACTIVE,
                'created_by' => $this->user->id,
                'created_at' => $registeredAt,
                'updated_at' => $registeredAt,
            ]);
        }

        return [
            'id' => $ticketId,
            'price_ids' => $priceIds,
        ];
    }

    /** @return array<int, array{price: string, weight: string, cages: int}> */
    private function twoTypeValues(): array
    {
        return [
            $this->firstChickenTypeId => [
                'price' => '8.5000',
                'weight' => '10.000',
                'cages' => 1,
            ],
            $this->secondChickenTypeId => [
                'price' => '12.0000',
                'weight' => '5.000',
                'cages' => 1,
            ],
        ];
    }

    private function createJavaMovement(int $ticketId, int $clientId): void
    {
        $quantities = DB::table('pesadas')
            ->where('ticket_id', $ticketId)
            ->selectRaw('COALESCE(SUM(cantidad_javas), 0) as cages')
            ->selectRaw('COALESCE(SUM(cantidad_bandejas), 0) as trays')
            ->first();

        DB::table('movimientos_javas')->insert([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $this->journeyId,
            'cliente_id' => $clientId,
            'tipo' => MovimientoJava::TYPE_DISPATCH,
            'cantidad' => (int) $quantities->cages,
            'cantidad_bandejas' => (int) $quantities->trays,
            'ticket_despacho_id' => $ticketId,
            'fecha_movimiento' => '2026-07-20 14:10:00',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function javaBalanceForClient(int $clientId): int
    {
        return (int) DB::table('movimientos_javas')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('cliente_id', $clientId)
            ->selectRaw(
                "SUM(CASE WHEN tipo = 'DESPACHO' THEN cantidad ELSE -cantidad END) AS saldo"
            )
            ->value('saldo');
    }

    /** @param  Collection<int, int>  $ticketIds */
    private function assertPricesForTickets(
        Collection $ticketIds,
        int $chickenTypeId,
        string $expected,
    ): void {
        $prices = DB::table('ticket_precios')
            ->whereIn('ticket_id', $ticketIds)
            ->where('tipo_pollo_id', $chickenTypeId)
            ->pluck('precio_kg');

        $this->assertCount($ticketIds->count(), $prices);
        $this->assertTrue(
            $prices->every(
                fn (mixed $price): bool => bccomp((string) $price, $expected, 4) === 0,
            ),
        );
    }

    private function assertDecimalValue(
        string $table,
        int $id,
        string $column,
        string $expected,
        int $scale,
    ): void {
        $actual = DB::table($table)->where('id', $id)->value($column);

        $this->assertNotNull($actual);
        $this->assertSame(0, bccomp((string) $actual, $expected, $scale));
    }
}
