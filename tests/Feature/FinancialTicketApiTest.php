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
