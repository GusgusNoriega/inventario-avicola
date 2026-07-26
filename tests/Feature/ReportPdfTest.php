<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReportPdfTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->makeAdministrator($this->user);
        $this->actingAs($this->user);
    }

    public function test_reports_page_lists_current_system_reports_without_zones(): void
    {
        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('Ventas por cliente')
            ->assertSee('Estado de cuenta de cliente')
            ->assertSee('Estado de cuenta de proveedor')
            ->assertSee('Pagos y cobros')
            ->assertSee('Movimientos por responsable')
            ->assertSee('Sin zonas ni campos heredados')
            ->assertDontSee('Reporte de ventas por zonas');
    }

    public function test_sales_report_is_generated_as_an_inline_pdf(): void
    {
        $response = $this->get(route('finanzas.reportes.pdf', [
            'type' => 'ventas-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ventas-clientes-2026-07-01-2026-07-31.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_report_rejects_an_inverted_date_range(): void
    {
        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'pagos',
                'desde' => '2026-07-31',
                'hasta' => '2026-07-01',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('hasta');
    }

    public function test_report_can_be_downloaded_as_png(): void
    {
        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'ventas-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="ventas-clientes-2026-07-01-2026-07-31.png"');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function test_customer_statement_can_be_downloaded_as_png_with_the_customer_layout(): void
    {
        $client = $this->thirdParty('Cliente para imagen', TerceroRole::CLIENT);

        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'estado-cliente',
            'cliente_id' => $client->id,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="estado-cliente-2026-07-01-2026-07-31.png"');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function test_long_image_report_is_downloaded_as_numbered_png_pages_in_zip(): void
    {
        $client = $this->thirdParty('Cliente para imagenes', TerceroRole::CLIENT);
        foreach (range(1, 36) as $index) {
            Pago::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'codigo' => 'PG-IMG-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'tercero_id' => $client->id,
                'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
                'cliente_id' => $client->id,
                'direccion' => Pago::DIRECTION_INCOME,
                'fecha_hora' => "2026-07-15 10:00:{$index}",
                'metodo' => 'EFECTIVO',
                'moneda' => 'PEN',
                'importe' => '10.00',
                'estado' => Pago::STATUS_REGISTERED,
                'created_by' => $this->user->id,
            ]);
        }

        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'pagos',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="pagos-2026-07-01-2026-07-31-imagenes.zip"');
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_all_current_report_types_generate_pdf_without_transactions(): void
    {
        $client = $this->thirdParty('Cliente de prueba', TerceroRole::CLIENT);
        $provider = $this->thirdParty('Proveedor de prueba', TerceroRole::PROVIDER);
        $common = ['desde' => '2026-07-01', 'hasta' => '2026-07-31'];
        $reports = [
            'estado-cliente' => [...$common, 'cliente_id' => $client->id],
            'estado-proveedor' => [...$common, 'proveedor_id' => $provider->id],
            'pagos' => $common,
            'responsable' => [...$common, 'usuario_id' => $this->user->id],
        ];

        foreach ($reports as $type => $query) {
            $response = $this->get(route('finanzas.reportes.pdf', ['type' => $type, ...$query]));
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    public function test_customer_statement_uses_opening_balance_charges_and_collections(): void
    {
        $client = $this->thirdParty('Cliente con saldo', TerceroRole::CLIENT);
        $documentDefaults = [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'origen_codigo' => 'PRUEBA',
            'moneda' => 'PEN',
            'subtotal' => '0.00',
            'impuesto' => '0.00',
            'saldo_pendiente' => '0.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ];
        Comprobante::query()->create([...$documentDefaults, 'codigo' => 'V-ANTERIOR', 'fecha_emision' => '2026-06-30', 'total' => '100.00']);
        Comprobante::query()->create([...$documentDefaults, 'codigo' => 'V-PERIODO', 'fecha_emision' => '2026-07-10', 'total' => '1000.00']);
        Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-PRUEBA',
            'tercero_id' => $client->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $client->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-15 10:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '200.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);

        $statement = app(ReportDataService::class)->customerStatement(
            (int) $this->user->empresa_id,
            (int) $client->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame(100.0, $statement['opening']);
        $this->assertSame(1000.0, $statement['charges']);
        $this->assertSame(200.0, $statement['credits']);
        $this->assertSame(900.0, $statement['balance']);
    }

    public function test_customer_statement_abbreviates_only_known_chicken_types(): void
    {
        $client = $this->thirdParty('Cliente con productos', TerceroRole::CLIENT);
        $document = Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'V-PRODUCTOS',
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-07-10',
            'moneda' => 'PEN',
            'subtotal' => '100.00',
            'impuesto' => '0.00',
            'total' => '100.00',
            'saldo_pendiente' => '100.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
        $types = collect([
            TipoPollo::CHICKEN_LIVE => ['Pollo vivo', 'PV'],
            TipoPollo::CHICKEN_DEAD => ['Pollo muerto', 'PM'],
            TipoPollo::CHICKEN_DRESSED => ['Pollo pelado', 'PP'],
            TipoPollo::CHICKEN_PROCESSED => ['Pollo beneficiado', 'PB'],
            'POLLO_ESPECIAL' => ['Pollo especial', 'Pollo especial'],
        ])->mapWithKeys(function (array $type, string $code): array {
            $model = TipoPollo::query()->firstOrCreate([
                'codigo' => $code,
            ], [
                'nombre' => $type[0],
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
            ]);

            return [$model->id => $type];
        });

        $details = $types->map(fn (array $type, int $typeId): array => [
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => $typeId,
            'descripcion' => $type[0],
            'cantidad_aves' => 1,
            'peso_neto_kg' => '1.000',
            'precio_kg' => '10.0000',
            'subtotal' => '10.00',
            'created_at' => now(),
        ])->values()->all();
        $details[] = [
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => null,
            'descripcion' => 'Ajuste manual',
            'cantidad_aves' => null,
            'peso_neto_kg' => null,
            'precio_kg' => null,
            'subtotal' => '50.00',
            'created_at' => now(),
        ];
        DB::table('comprobante_detalles')->insert($details);

        $statement = app(ReportDataService::class)->customerStatement(
            (int) $this->user->empresa_id,
            (int) $client->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('PV, PM, PP, PB, Pollo especial, Ajuste manual', $statement['rows']->first()['detail']);
    }

    public function test_provider_statement_keeps_full_chicken_type_names(): void
    {
        $provider = $this->thirdParty('Proveedor con productos', TerceroRole::PROVIDER);
        $liveChicken = TipoPollo::query()->firstOrCreate([
            'codigo' => TipoPollo::CHICKEN_LIVE,
        ], [
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
        ]);
        $document = Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $provider->id,
            'operacion' => Comprobante::OPERATION_PURCHASE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'C-PRODUCTOS',
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-07-10',
            'moneda' => 'PEN',
            'subtotal' => '100.00',
            'impuesto' => '0.00',
            'total' => '100.00',
            'saldo_pendiente' => '100.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => $liveChicken->id,
            'descripcion' => 'Pollo vivo',
            'cantidad_aves' => 1,
            'peso_neto_kg' => '1.000',
            'precio_kg' => '100.0000',
            'subtotal' => '100.00',
            'created_at' => now(),
        ]);

        $statement = app(ReportDataService::class)->providerStatement(
            (int) $this->user->empresa_id,
            (int) $provider->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('Pollo vivo', $statement['rows']->first()['detail']);
    }

    public function test_customer_pdf_layout_shows_only_name_and_groups_movements_by_day(): void
    {
        $client = $this->thirdParty('Cliente solo nombre', TerceroRole::CLIENT);
        $rows = collect([
            [
                'date' => '2026-07-10',
                'code' => 'V-1',
                'type' => 'INTERNO',
                'detail' => 'PV',
                'weight' => 10.0,
                'price' => 7.0,
                'debit' => 70.0,
                'credit' => 0.0,
                'effect' => 70.0,
                'balance' => 170.0,
            ],
            [
                'date' => '2026-07-10',
                'code' => 'PG-1',
                'type' => 'COBRO',
                'detail' => 'EFECTIVO',
                'weight' => null,
                'price' => null,
                'debit' => 0.0,
                'credit' => 20.0,
                'effect' => -20.0,
                'balance' => 150.0,
            ],
            [
                'date' => '2026-07-11',
                'code' => 'V-2',
                'type' => 'INTERNO',
                'detail' => 'PP',
                'weight' => 5.0,
                'price' => 8.0,
                'debit' => 40.0,
                'credit' => 0.0,
                'effect' => 40.0,
                'balance' => 190.0,
            ],
        ]);

        $html = view('reports.pdf', [
            'company' => $this->user->empresa,
            'type' => 'estado-cliente',
            'title' => 'Estado de cuenta de cliente',
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'data' => [
                'counterparty' => $client,
                'opening' => 100.0,
                'rows' => $rows,
                'charges' => 110.0,
                'credits' => 20.0,
                'balance' => 190.0,
            ],
            'generatedAt' => CarbonImmutable::parse('2026-07-31 12:00:00'),
        ])->render();
        $plainText = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertIsString($plainText);
        $this->assertStringContainsString('Cliente: Cliente solo nombre', $plainText);
        $this->assertStringNotContainsString((string) $client->numero_documento, $plainText);
        $this->assertStringNotContainsString('<table class="summary">', $html);
        $this->assertStringNotContainsString('Cargos del periodo', $plainText);
        $this->assertStringContainsString('Cargo / Abono', $plainText);
        $this->assertSame(2, substr_count($plainText, 'Movimientos del'));
        $this->assertStringContainsString('class="num debit"', $html);
        $this->assertStringContainsString('class="num credit"', $html);
    }

    private function thirdParty(string $name, string $role): Tercero
    {
        $thirdParty = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => fake()->unique()->numerify('########'),
            'nombre_razon_social' => $name,
            'direccion' => 'Direccion de prueba',
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        TerceroRole::query()->create(['tercero_id' => $thirdParty->id, 'rol' => $role]);

        return $thirdParty;
    }
}
