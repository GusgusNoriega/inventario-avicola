<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\CuentaFinanciera;
use App\Models\EntidadFinanciera;
use App\Models\Pago;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\CashRegisterMovementService;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            ->assertSee('Cuentas de clientes')
            ->assertSee('Deuda anterior, deuda del día o periodo, pagos realizados y deuda actual')
            ->assertSee('Sin zonas ni campos heredados')
            ->assertDontSee('Reporte de ventas por zonas');
    }

    public function test_collection_route_two_card_uses_one_required_date_selector(): void
    {
        $response = $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('Ruta de cobranza 2')
            ->assertSee('Hoja diaria por cliente con saldo anterior, ventas, devoluciones, cobros y saldo acumulado.');
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        $this->assertTrue($loaded);
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@action="'.route('finanzas.reportes.pdf', 'ruta-cobranza-2').'"]');
        $this->assertNotFalse($forms);
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);

        $dateFields = $xpath->query('.//input[@type="date" and @name="fecha"]', $form);
        $this->assertNotFalse($dateFields);
        $this->assertSame(1, $dateFields->length);
        $dateField = $dateFields->item(0);
        $this->assertInstanceOf(\DOMElement::class, $dateField);
        $this->assertTrue($dateField->hasAttribute('required'));
        $this->assertSame(0, $xpath->query('.//input[@name="desde" or @name="hasta"]', $form)?->length);
    }

    public function test_collection_route_two_requires_the_selected_date(): void
    {
        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', ['type' => 'ruta-cobranza-2']))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('fecha');
    }

    public function test_collection_route_two_service_includes_every_customer_and_rebuilds_the_two_day_ledger(): void
    {
        $alpha = $this->thirdParty('Alfa Ruta Dos', TerceroRole::CLIENT);
        $beta = $this->thirdParty('Beta Ruta Dos Sin Movimientos', TerceroRole::CLIENT);
        $this->thirdParty('Proveedor fuera de la ruta', TerceroRole::PROVIDER);
        $document = function (
            string $code,
            string $date,
            string $amount,
            string $nature = Comprobante::NATURE_CHARGE,
            ?string $description = null,
            ?string $weight = null,
            ?string $price = null,
        ) use ($alpha): Comprobante {
            $record = Comprobante::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'tercero_id' => $alpha->id,
                'operacion' => Comprobante::OPERATION_SALE,
                'naturaleza' => $nature,
                'tipo_documento' => 'INTERNO',
                'codigo' => $code,
                'origen_codigo' => 'PRUEBA_RUTA_COBRANZA_2',
                'fecha_emision' => $date,
                'moneda' => 'PEN',
                'subtotal' => $amount,
                'impuesto' => '0.00',
                'total' => $amount,
                'saldo_pendiente' => $nature === Comprobante::NATURE_CREDIT ? '0.00' : $amount,
                'estado' => Comprobante::STATUS_PENDING,
                'created_by' => $this->user->id,
            ]);

            if ($description !== null) {
                DB::table('comprobante_detalles')->insert([
                    'comprobante_id' => $record->id,
                    'tipo_pollo_id' => null,
                    'descripcion' => $description,
                    'cantidad_aves' => null,
                    'peso_neto_kg' => $weight,
                    'precio_kg' => $price,
                    'subtotal' => $amount,
                    'created_at' => now(),
                ]);
            }

            return $record;
        };

        $document('V-RUTA-ANTERIOR', '2026-08-18', '100.00');
        $document('V-RUTA-DIA-ANTERIOR', '2026-08-19', '50.00', description: 'Pollo vivo', weight: '10.000', price: '5.0000');
        $document('NC-RUTA-DIA-ANTERIOR', '2026-08-19', '10.00', Comprobante::NATURE_CREDIT, 'Pollo devuelto', '2.000', '5.0000');
        $document('V-RUTA-FUTURA', '2026-08-21', '999.00', description: 'Venta futura');

        $payment = Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-RUTA-CODIGO-TERCIARIO',
            'tercero_id' => $alpha->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $alpha->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-08-21 09:30:00',
            'metodo' => 'EFECTIVO',
            'referencia' => 'PAGO-REFERENCIA-TERCIARIA',
            'moneda' => 'PEN',
            'importe' => '30.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);
        $collectorId = DB::table('cobradores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre' => 'Cobrador Ruta Dos',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountId = $this->financialAccount(EntidadFinanciera::TYPE_OWN, 'Cuenta Ruta Dos');
        $methodId = (int) DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $collectionId = DB::table('cobranzas')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'cobrador_id' => $collectorId,
            'cobrador_nombre_snapshot' => 'Cobrador Ruta Dos',
            'codigo' => 'COB-RUTA-CODIGO-SECUNDARIO',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'reporte-ruta-cobranza-dos'),
            'cuenta_destino_id' => $accountId,
            'metodo_pago_id' => $methodId,
            'fecha_hora' => '2026-08-21 09:30:00',
            'referencia' => 'RUTA-REFERENCIA-PRIORITARIA',
            'moneda' => 'PEN',
            'importe_total' => '30.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cobranza_detalles')->insert([
            'cobranza_id' => $collectionId,
            'pago_id' => $payment->id,
            'cliente_id' => $alpha->id,
            'fecha_recepcion' => '2026-08-20',
            'medio_recepcion' => 'EFECTIVO',
            'importe' => '30.00',
            'orden' => 1,
            'created_at' => now(),
        ]);

        $report = app(ReportDataService::class)->collectionRouteTwo(
            (int) $this->user->empresa_id,
            '2026-08-20',
            'PEN',
        );

        $this->assertSame('2026-08-19', $report['period_from']);
        $this->assertSame('2026-08-20', $report['period_to']);
        $this->assertSame(
            ['Alfa Ruta Dos', 'Beta Ruta Dos Sin Movimientos'],
            $report['customers']->pluck('name')->all(),
        );

        $alphaLedger = $report['customers']->firstWhere('id', $alpha->id);
        $this->assertIsArray($alphaLedger);
        $this->assertSame('100.00', $alphaLedger['opening']);
        $this->assertSame('110.00', $alphaLedger['balance']);
        $this->assertSame(['2026-08-19', '2026-08-20'], $alphaLedger['rows']->pluck('date')->unique()->values()->all());
        $this->assertCount(3, $alphaLedger['rows']);

        $sale = $alphaLedger['rows']->firstWhere('kind', 'sale');
        $this->assertIsArray($sale);
        $this->assertSame('POLLO VIVO', $sale['detail']);
        $this->assertSame(10.0, (float) $sale['weight']);
        $this->assertSame(5.0, (float) $sale['price']);
        $this->assertSame('50.00', $sale['outflow']);
        $this->assertNull($sale['inflow']);
        $this->assertSame('150.00', $sale['balance']);

        $return = $alphaLedger['rows']->firstWhere('kind', 'return');
        $this->assertIsArray($return);
        $this->assertSame('DEVOLUCION', $return['detail']);
        $this->assertSame('-10.00', $return['outflow']);
        $this->assertNull($return['inflow']);
        $this->assertSame('140.00', $return['balance']);

        $collection = $alphaLedger['rows']->firstWhere('kind', 'payment');
        $this->assertIsArray($collection);
        $this->assertSame('2026-08-20', $collection['date']);
        $this->assertSame('RUTA-REFERENCIA-PRIORITARIA', $collection['detail']);
        $this->assertSame('-', $collection['marker']);
        $this->assertNull($collection['outflow']);
        $this->assertSame('30.00', $collection['inflow']);
        $this->assertSame('110.00', $collection['balance']);

        $betaLedger = $report['customers']->firstWhere('id', $beta->id);
        $this->assertIsArray($betaLedger);
        $this->assertSame('0.00', $betaLedger['opening']);
        $this->assertTrue($betaLedger['rows']->isEmpty());
        $this->assertSame('0.00', $betaLedger['balance']);
    }

    public function test_collection_route_two_ignores_a_collection_link_from_another_company(): void
    {
        $customer = $this->thirdParty('Cliente con enlace ajeno', TerceroRole::CLIENT);
        $payment = Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-RUTA-LOCAL',
            'tercero_id' => $customer->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $customer->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-08-20 09:30:00',
            'metodo' => 'EFECTIVO',
            'referencia' => 'REFERENCIA-PAGO-LOCAL',
            'moneda' => 'PEN',
            'importe' => '40.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);
        $otherTenantUser = User::factory()->create();
        $otherTenantCollectorId = DB::table('cobradores')->insertGetId([
            'empresa_id' => $otherTenantUser->empresa_id,
            'nombre' => 'Cobrador de otra empresa',
            'estado' => 'ACTIVO',
            'created_by' => $otherTenantUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherTenantAccountId = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta de otra empresa para ruta',
            owner: $otherTenantUser,
        );
        $methodId = (int) DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $otherTenantCollectionId = DB::table('cobranzas')->insertGetId([
            'empresa_id' => $otherTenantUser->empresa_id,
            'cobrador_id' => $otherTenantCollectorId,
            'cobrador_nombre_snapshot' => 'Cobrador de otra empresa',
            'codigo' => 'COB-RUTA-OTRA-EMPRESA',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'enlace-inconsistente-otra-empresa'),
            'cuenta_destino_id' => $otherTenantAccountId,
            'metodo_pago_id' => $methodId,
            'fecha_hora' => '2026-08-20 10:00:00',
            'referencia' => 'REFERENCIA-SECRETA-OTRA-EMPRESA',
            'moneda' => 'PEN',
            'importe_total' => '40.00',
            'estado' => 'REGISTRADO',
            'created_by' => $otherTenantUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cobranza_detalles')->insert([
            'cobranza_id' => $otherTenantCollectionId,
            'pago_id' => $payment->id,
            'cliente_id' => $customer->id,
            'fecha_recepcion' => '2026-08-19',
            'medio_recepcion' => 'EFECTIVO',
            'importe' => '40.00',
            'orden' => 1,
            'created_at' => now(),
        ]);

        $report = app(ReportDataService::class)->collectionRouteTwo(
            (int) $this->user->empresa_id,
            '2026-08-20',
            'PEN',
        );
        $ledger = $report['customers']->firstWhere('id', $customer->id);

        $this->assertIsArray($ledger);
        $this->assertSame('0.00', $ledger['opening']);
        $this->assertSame('-40.00', $ledger['balance']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame('2026-08-20', $ledger['rows']->first()['date']);
        $this->assertSame('REFERENCIA-PAGO-LOCAL', $ledger['rows']->first()['detail']);
        $this->assertFalse($ledger['rows']->pluck('detail')->contains('REFERENCIA-SECRETA-OTRA-EMPRESA'));
    }

    public function test_collection_route_two_uses_company_timezone_for_a_direct_payment_near_midnight(): void
    {
        $companyTimezone = 'Asia/Tokyo';
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['zona_horaria' => $companyTimezone]);
        $customer = $this->thirdParty('Cliente con pago cerca de medianoche', TerceroRole::CLIENT);
        $connection = DB::connection()->getName();
        $databaseTimezone = (string) (
            config("database.connections.{$connection}.timezone")
            ?: config('app.timezone', 'UTC')
        );
        $storedAt = CarbonImmutable::parse('2026-08-19 00:30:00', $companyTimezone)
            ->setTimezone($databaseTimezone)
            ->format('Y-m-d H:i:s');
        Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-RUTA-MEDIANOCHE',
            'tercero_id' => $customer->id,
            'tipo' => Pago::TYPE_DIRECT_PAYMENT,
            'cliente_id' => $customer->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => $storedAt,
            'metodo' => 'EFECTIVO',
            'referencia' => 'PAGO-DIRECTO-MEDIANOCHE',
            'moneda' => 'PEN',
            'importe' => '20.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);

        $report = app(ReportDataService::class)->collectionRouteTwo(
            (int) $this->user->empresa_id,
            '2026-08-20',
            'PEN',
        );
        $ledger = $report['customers']->firstWhere('id', $customer->id);

        $this->assertIsArray($ledger);
        $this->assertSame('0.00', $ledger['opening']);
        $this->assertSame('-20.00', $ledger['balance']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame('2026-08-19', $ledger['rows']->first()['date']);
        $this->assertSame('PAGO-DIRECTO-MEDIANOCHE', $ledger['rows']->first()['detail']);
    }

    public function test_collection_route_two_is_generated_inline_on_letter_paper(): void
    {
        $response = $this->get(route('finanzas.reportes.pdf', [
            'type' => 'ruta-cobranza-2',
            'fecha' => '2026-08-20',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ruta-cobranza-2-2026-08-20.pdf"');
        $contents = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $contents);
        $matched = preg_match(
            '/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/',
            $contents,
            $mediaBox,
        );
        $this->assertSame(1, $matched, 'El PDF debe declarar un MediaBox verificable.');
        $this->assertEqualsWithDelta(612.0, (float) $mediaBox[3] - (float) $mediaBox[1], 0.1);
        $this->assertEqualsWithDelta(792.0, (float) $mediaBox[4] - (float) $mediaBox[2], 0.1);
    }

    public function test_customer_debt_summary_rebuilds_balances_and_totals_for_the_selected_days(): void
    {
        $alpha = $this->thirdParty('Alfa Cliente', TerceroRole::CLIENT);
        $beta = $this->thirdParty('Beta Cliente', TerceroRole::CLIENT);
        $this->thirdParty('Cliente sin movimientos', TerceroRole::CLIENT);
        $document = function (
            Tercero $customer,
            string $code,
            string $date,
            string $amount,
            string $nature = Comprobante::NATURE_CHARGE,
            string $currency = 'PEN',
            string $status = Comprobante::STATUS_PENDING,
        ): Comprobante {
            return Comprobante::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'tercero_id' => $customer->id,
                'operacion' => Comprobante::OPERATION_SALE,
                'naturaleza' => $nature,
                'tipo_documento' => 'INTERNO',
                'codigo' => $code,
                'origen_codigo' => 'PRUEBA_REPORTE_DEUDA',
                'fecha_emision' => $date,
                'moneda' => $currency,
                'subtotal' => $amount,
                'impuesto' => '0.00',
                'total' => $amount,
                'saldo_pendiente' => $amount,
                'estado' => $status,
                'created_by' => $this->user->id,
            ]);
        };
        $payment = function (
            Tercero $customer,
            string $code,
            string $dateTime,
            string $amount,
            string $type = Pago::TYPE_CUSTOMER_COLLECTION,
            string $currency = 'PEN',
            string $status = Pago::STATUS_REGISTERED,
        ): Pago {
            return Pago::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'codigo' => $code,
                'tercero_id' => $customer->id,
                'tipo' => $type,
                'cliente_id' => $customer->id,
                'direccion' => $type === Pago::TYPE_CUSTOMER_REFUND
                    ? Pago::DIRECTION_EXPENSE
                    : Pago::DIRECTION_INCOME,
                'fecha_hora' => $dateTime,
                'metodo' => 'EFECTIVO',
                'moneda' => $currency,
                'importe' => $amount,
                'estado' => $status,
                'created_by' => $this->user->id,
            ]);
        };

        $document($alpha, 'V-ALFA-ANT', '2026-06-30', '100.00');
        $document($alpha, 'NC-ALFA-ANT', '2026-06-30', '10.00', Comprobante::NATURE_CREDIT);
        $payment($alpha, 'PG-ALFA-ANT', '2026-06-30 16:00:00', '20.00');
        $document($alpha, 'V-ALFA-DIA', '2026-07-10', '50.00');
        $document($alpha, 'NC-ALFA-DIA', '2026-07-11', '5.00', Comprobante::NATURE_CREDIT);
        $payment($alpha, 'PG-ALFA-DIA', '2026-07-12 09:00:00', '30.00');
        $payment($alpha, 'DS-ALFA-DIA', '2026-07-12 10:00:00', '5.00', Pago::TYPE_CUSTOMER_DISCOUNT);
        $payment($alpha, 'RE-ALFA-DIA', '2026-07-12 11:00:00', '2.00', Pago::TYPE_CUSTOMER_REFUND);
        $document($alpha, 'V-ALFA-USD', '2026-07-10', '999.00', currency: 'USD');
        $document($alpha, 'V-ALFA-ANULADA', '2026-07-10', '500.00', status: Comprobante::STATUS_VOIDED);
        $document($alpha, 'V-ALFA-BORRADOR', '2026-07-10', '450.00', status: Comprobante::STATUS_DRAFT);
        $payment($alpha, 'PG-ALFA-ANULADO', '2026-07-12 12:00:00', '300.00', status: Pago::STATUS_VOIDED);
        $document($alpha, 'V-ALFA-FUTURA', '2026-08-01', '700.00');

        $document($beta, 'V-BETA-DIA', '2026-07-05', '40.00');
        $payment($beta, 'PG-BETA-DIA', '2026-07-05 14:00:00', '40.00');

        TerceroRole::query()
            ->where('tercero_id', $alpha->id)
            ->where('rol', TerceroRole::CLIENT)
            ->delete();

        $report = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
            'PEN',
        );

        $this->assertSame(['Alfa Cliente', 'Beta Cliente'], $report['rows']->pluck('customer')->all());
        $alphaRow = $report['rows']->firstWhere('customer_id', $alpha->id);
        $this->assertIsArray($alphaRow);
        $this->assertSame('70.00', $alphaRow['opening']);
        $this->assertSame('45.00', $alphaRow['period_debt']);
        $this->assertSame('115.00', $alphaRow['debt_to_date']);
        $this->assertSame('33.00', $alphaRow['payments']);
        $this->assertSame('82.00', $alphaRow['balance']);
        $betaRow = $report['rows']->firstWhere('customer_id', $beta->id);
        $this->assertIsArray($betaRow);
        $this->assertSame('0.00', $betaRow['balance']);
        $this->assertSame([
            'opening' => '70.00',
            'period_debt' => '85.00',
            'debt_to_date' => '155.00',
            'payments' => '73.00',
            'balance' => '82.00',
        ], $report['totals']);
        $this->assertSame('PEN', $report['currency']);
    }

    public function test_customer_debt_report_accepts_the_company_iso_currency(): void
    {
        DB::table('empresas')
            ->where('id', $this->user->empresa_id)
            ->update(['moneda' => 'COP']);

        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertViewHas('defaultReportCurrency', 'COP')
            ->assertSee('<option value="COP" selected>COP</option>', false);

        $this->get(route('finanzas.reportes.pdf', [
            'type' => 'deuda-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
            'moneda' => 'COP',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_customer_debt_summary_keeps_voids_on_the_day_they_happened(): void
    {
        $client = $this->thirdParty('Cliente con anulaciones historicas', TerceroRole::CLIENT);
        $documentDefaults = [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'origen_codigo' => 'PRUEBA_CORTE_HISTORICO',
            'moneda' => 'PEN',
            'impuesto' => '0.00',
            'saldo_pendiente' => '0.00',
            'estado' => Comprobante::STATUS_VOIDED,
            'created_by' => $this->user->id,
        ];
        foreach ([
            ['codigo' => 'V-ANULADA-EN-PERIODO', 'fecha_emision' => '2026-06-20', 'total' => '100.00', 'anulada_at' => '2026-07-15 10:00:00'],
            ['codigo' => 'V-ANULADA-DESPUES', 'fecha_emision' => '2026-07-20', 'total' => '40.00', 'anulada_at' => '2026-08-05 10:00:00'],
            ['codigo' => 'V-ANULADA-ANTES', 'fecha_emision' => '2026-06-01', 'total' => '80.00', 'anulada_at' => '2026-06-20 10:00:00'],
        ] as $attributes) {
            Comprobante::query()->create([
                ...$documentDefaults,
                ...$attributes,
                'subtotal' => $attributes['total'],
            ]);
        }

        $paymentDefaults = [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $client->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'estado' => Pago::STATUS_VOIDED,
            'created_by' => $this->user->id,
        ];
        $openingPayment = Pago::query()->create([
            ...$paymentDefaults,
            'codigo' => 'PG-ANULADO-EN-PERIODO',
            'fecha_hora' => '2026-06-25 10:00:00',
            'importe' => '30.00',
            'anulada_at' => '2026-07-10 10:00:00',
        ]);
        Pago::query()->create([
            ...$paymentDefaults,
            'codigo' => 'PG-ANULADO-DESPUES',
            'fecha_hora' => '2026-07-25 10:00:00',
            'importe' => '10.00',
            'anulada_at' => '2026-08-05 10:00:00',
        ]);
        Pago::query()->create([
            ...$paymentDefaults,
            'codigo' => 'PG-ANULADO-ANTES',
            'fecha_hora' => '2026-06-10 10:00:00',
            'importe' => '15.00',
            'anulada_at' => '2026-06-20 10:00:00',
        ]);
        Pago::query()->create([
            ...$paymentDefaults,
            'codigo' => 'PG-REVERSA-EN-PERIODO',
            'direccion' => Pago::DIRECTION_EXPENSE,
            'fecha_hora' => '2026-07-10 10:00:00',
            'importe' => '30.00',
            'estado' => Pago::STATUS_REGISTERED,
            'reversa_de_pago_id' => $openingPayment->id,
            'anulada_at' => null,
        ]);

        $row = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
            'PEN',
        )['rows']->sole();

        $this->assertSame('70.00', $row['opening']);
        $this->assertSame('-60.00', $row['period_debt']);
        $this->assertSame('10.00', $row['debt_to_date']);
        $this->assertSame('-20.00', $row['payments']);
        $this->assertSame('30.00', $row['balance']);
    }

    public function test_customer_debt_summary_replays_a_ticket_void_and_restore_from_the_audit_log(): void
    {
        $client = $this->thirdParty('Cliente con ticket restablecido', TerceroRole::CLIENT);
        $correctedClient = $this->thirdParty('Cliente corregido del ticket', TerceroRole::CLIENT);
        $document = Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'V-TICKET-RESTABLECIDO',
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => 'VENTA:TICKET:999999',
            'fecha_emision' => '2026-06-20',
            'moneda' => 'PEN',
            'subtotal' => '100.00',
            'impuesto' => '0.00',
            'total' => '100.00',
            'saldo_pendiente' => '100.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
        $active = $document->getAttributes();
        $voided = [
            ...$active,
            'estado' => Comprobante::STATUS_VOIDED,
            'anulada_at' => '2026-07-11T01:00:00.000000Z',
        ];
        $restored = [
            ...$active,
            'estado' => Comprobante::STATUS_PENDING,
            'anulada_at' => null,
        ];
        $revalued = [
            ...$active,
            'tercero_id' => $correctedClient->id,
            'subtotal' => '120.00',
            'total' => '120.00',
            'saldo_pendiente' => '120.00',
            'estado' => Comprobante::STATUS_PENDING,
            'anulada_at' => null,
        ];
        $voidedAgain = [
            ...$revalued,
            'estado' => Comprobante::STATUS_VOIDED,
            'saldo_pendiente' => '0.00',
            'anulada_at' => '2026-07-21T01:00:00.000000Z',
        ];
        $document->update([
            'tercero_id' => $correctedClient->id,
            'subtotal' => '120.00',
            'total' => '120.00',
            'saldo_pendiente' => '0.00',
            'estado' => Comprobante::STATUS_VOIDED,
            'anulada_at' => '2026-07-20 20:00:00',
        ]);
        DB::table('auditoria_eventos')->insert([
            [
                'empresa_id' => $this->user->empresa_id,
                'usuario_id' => $this->user->id,
                'entidad' => 'comprobantes',
                'entidad_id' => (string) $document->id,
                'accion' => 'ANULAR_AUTOMATICO',
                'datos_antes' => json_encode($active, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($voided, JSON_THROW_ON_ERROR),
                'created_at' => '2026-07-10 20:00:00',
            ],
            [
                'empresa_id' => $this->user->empresa_id,
                'usuario_id' => $this->user->id,
                'entidad' => 'comprobantes',
                'entidad_id' => (string) $document->id,
                'accion' => 'REVALORIZAR',
                'datos_antes' => json_encode($voided, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($restored, JSON_THROW_ON_ERROR),
                'created_at' => '2026-07-15 10:00:00',
            ],
            [
                'empresa_id' => $this->user->empresa_id,
                'usuario_id' => $this->user->id,
                'entidad' => 'comprobantes',
                'entidad_id' => (string) $document->id,
                'accion' => 'REVALORIZAR',
                'datos_antes' => json_encode($restored, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($revalued, JSON_THROW_ON_ERROR),
                'created_at' => '2026-07-18 10:00:00',
            ],
            [
                'empresa_id' => $this->user->empresa_id,
                'usuario_id' => $this->user->id,
                'entidad' => 'comprobantes',
                'entidad_id' => (string) $document->id,
                'accion' => 'ANULAR_AUTOMATICO',
                'datos_antes' => json_encode($revalued, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($voidedAgain, JSON_THROW_ON_ERROR),
                'created_at' => '2026-07-20 20:00:00',
            ],
        ]);

        $voidedCut = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-10',
            '2026-07-10',
            'PEN',
        )['rows']->sole();
        $restoredCut = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-15',
            '2026-07-15',
            'PEN',
        )['rows']->sole();
        $currentVoidCut = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-20',
            '2026-07-20',
            'PEN',
        )['rows']->sole();
        $afterCurrentVoidCut = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-21',
            '2026-07-21',
            'PEN',
        );

        $this->assertSame('Cliente corregido del ticket', $voidedCut['customer']);
        $this->assertSame('120.00', $voidedCut['opening']);
        $this->assertSame('-120.00', $voidedCut['period_debt']);
        $this->assertSame('0.00', $voidedCut['balance']);
        $this->assertSame('0.00', $restoredCut['opening']);
        $this->assertSame('120.00', $restoredCut['period_debt']);
        $this->assertSame('120.00', $restoredCut['balance']);
        $this->assertSame('120.00', $currentVoidCut['opening']);
        $this->assertSame('-120.00', $currentVoidCut['period_debt']);
        $this->assertSame('0.00', $currentVoidCut['balance']);
        $this->assertTrue($afterCurrentVoidCut['rows']->isEmpty());
        $this->assertSame('0.00', $afterCurrentVoidCut['totals']['balance']);
    }

    public function test_customer_debt_summary_uses_the_day_the_collection_was_received(): void
    {
        $client = $this->thirdParty('Cliente cobrado antes del deposito', TerceroRole::CLIENT);
        $payment = Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-RECEPCION-ANTERIOR',
            'tercero_id' => $client->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $client->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-15 10:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '25.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);
        $collectorId = DB::table('cobradores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre' => 'Cobrador fecha efectiva',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountId = $this->financialAccount(EntidadFinanciera::TYPE_OWN, 'Cuenta fecha efectiva');
        $methodId = (int) DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $collectionId = DB::table('cobranzas')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'cobrador_id' => $collectorId,
            'cobrador_nombre_snapshot' => 'Cobrador fecha efectiva',
            'codigo' => 'COB-FECHA-EFECTIVA',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'cobranza-fecha-efectiva'),
            'cuenta_destino_id' => $accountId,
            'metodo_pago_id' => $methodId,
            'fecha_hora' => '2026-07-15 10:00:00',
            'referencia' => 'REF-FECHA-EFECTIVA',
            'moneda' => 'PEN',
            'importe_total' => '25.00',
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cobranza_detalles')->insert([
            'cobranza_id' => $collectionId,
            'pago_id' => $payment->id,
            'cliente_id' => $client->id,
            'fecha_recepcion' => '2026-06-30',
            'medio_recepcion' => 'EFECTIVO',
            'importe' => '25.00',
            'orden' => 1,
            'created_at' => now(),
        ]);

        $row = app(ReportDataService::class)->customerDebtSummary(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
            'PEN',
        )['rows']->sole();

        $this->assertSame('-25.00', $row['opening']);
        $this->assertSame('0.00', $row['period_debt']);
        $this->assertSame('0.00', $row['payments']);
        $this->assertSame('-25.00', $row['balance']);
    }

    public function test_reports_page_shows_the_csv_download_and_optional_user_filter_for_payments(): void
    {
        $inactiveUser = $this->createUserForCompany($this->user, [
            'nombre' => 'Usuario inactivo reporte',
            'estado' => User::STATUS_INACTIVE,
        ]);
        $otherTenantUser = User::factory()->create([
            'nombre' => 'Usuario otro tenant reporte',
        ]);
        $response = $this->get(route('finanzas.reportes'))->assertOk();
        $response->assertViewHas('users', function ($users) use ($inactiveUser, $otherTenantUser): bool {
            $userIds = $users->pluck('id')->map(fn (mixed $id): int => (int) $id);

            return $userIds->contains($inactiveUser->id)
                && ! $userIds->contains($otherTenantUser->id);
        });
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        $this->assertTrue($loaded);
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//button[normalize-space(.) = "Descargar CSV"]');

        $this->assertNotFalse($buttons);
        $this->assertSame(1, $buttons->length);
        $button = $buttons->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);
        $this->assertSame(route('finanzas.reportes.pagos.csv'), $button->getAttribute('formaction'));
        $this->assertSame('_self', $button->getAttribute('formtarget'));

        $forms = $xpath->query('ancestor::form', $button);
        $this->assertNotFalse($forms);
        $form = $forms->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame(route('finanzas.reportes.pdf', 'pagos'), $form->getAttribute('action'));

        $userFields = $xpath->query('.//select[@name="usuario_id"]', $form);
        $this->assertNotFalse($userFields);
        $this->assertSame(1, $userFields->length);
        $userField = $userFields->item(0);
        $this->assertInstanceOf(\DOMElement::class, $userField);
        $this->assertFalse($userField->hasAttribute('required'));
        $this->assertSame(
            'Todos los usuarios',
            trim((string) $xpath->evaluate('string(option[1])', $userField)),
        );
        $inactiveOption = $xpath->query('.//option[@value="'.$inactiveUser->id.'"]', $userField);
        $this->assertNotFalse($inactiveOption);
        $this->assertSame(1, $inactiveOption->length);
        $this->assertStringContainsString('Inactivo', trim($inactiveOption->item(0)?->textContent ?? ''));
        $otherTenantOption = $xpath->query('.//option[@value="'.$otherTenantUser->id.'"]', $userField);
        $this->assertNotFalse($otherTenantOption);
        $this->assertSame(0, $otherTenantOption->length);
    }

    public function test_reports_page_lists_only_own_tenant_accounts_and_includes_inactive_accounts(): void
    {
        $activeOwnAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta propia activa reporte',
        );
        $inactiveOwnAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta propia inactiva reporte',
            CuentaFinanciera::STATUS_INACTIVE,
        );
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa excluida reporte',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta de otro tenant excluida reporte',
            owner: $otherTenantUser,
        );

        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('name="cuenta_id"', false)
            ->assertSee('Cuenta propia activa reporte')
            ->assertSee('Cuenta propia inactiva reporte')
            ->assertSee('Inactiva')
            ->assertDontSee('Cuenta externa excluida reporte')
            ->assertDontSee('Cuenta de otro tenant excluida reporte')
            ->assertViewHas('accounts', function ($accounts) use (
                $activeOwnAccount,
                $inactiveOwnAccount,
                $externalAccount,
                $otherTenantAccount,
            ): bool {
                $accountIds = $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id);

                return $accountIds->contains($activeOwnAccount)
                    && $accountIds->contains($inactiveOwnAccount)
                    && ! $accountIds->contains($externalAccount)
                    && ! $accountIds->contains($otherTenantAccount);
            });
    }

    public function test_report_http_validation_rejects_external_and_other_tenant_accounts(): void
    {
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa invalida reporte',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta ajena invalida reporte',
            owner: $otherTenantUser,
        );

        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'pagos',
                'cuenta_id' => $externalAccount,
                'desde' => '2026-07-01',
                'hasta' => '2026-07-31',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('cuenta_id');

        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'responsable',
                'usuario_id' => $this->user->id,
                'cuenta_id' => $otherTenantAccount,
                'desde' => '2026-07-01',
                'hasta' => '2026-07-31',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('cuenta_id');
    }

    public function test_payments_report_combines_account_and_user_filters_and_recalculates_totals(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta seleccionada pagos reporte',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta no seleccionada pagos reporte',
        );
        $otherUser = $this->createUserForCompany($this->user, ['nombre' => 'Otro usuario pagos reporte']);
        $this->reportPayment('PG-CUENTA-DESTINO', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '120.00',
        ]);
        $this->reportPayment('PG-CUENTA-ORIGEN', [
            'cuenta_origen_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_EXPENSE,
            'importe' => '45.00',
        ]);
        $this->reportPayment('PG-CUENTA-TRANSFERENCIA', [
            'tipo' => Pago::TYPE_INTERNAL_TRANSFER,
            'cuenta_origen_id' => $selectedAccount,
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_NO_FLOW,
            'importe' => '20.00',
        ]);
        $this->reportPayment('PG-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '900.00',
        ]);
        $this->reportPayment('PG-OTRO-USUARIO', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '800.00',
            'created_by' => $otherUser->id,
        ]);

        $report = app(ReportDataService::class)->payments(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
            [
                'cuenta_id' => $selectedAccount,
                'usuario_id' => $this->user->id,
            ],
        );

        $this->assertSame(
            ['PG-CUENTA-DESTINO', 'PG-CUENTA-ORIGEN', 'PG-CUENTA-TRANSFERENCIA'],
            $report['rows']->pluck('code')->all(),
        );
        $this->assertSame(1, $report['rows']->where('code', 'PG-CUENTA-TRANSFERENCIA')->count());
        $this->assertFalse($report['rows']->pluck('code')->contains('PG-OTRA-CUENTA'));
        $this->assertFalse($report['rows']->pluck('code')->contains('PG-OTRO-USUARIO'));
        $this->assertSame([$this->user->nombre], $report['rows']->pluck('user')->unique()->values()->all());
        $this->assertSame(120.0, (float) $report['income']);
        $this->assertSame(45.0, (float) $report['expense']);
        $this->assertSame(185.0, (float) $report['total']);
    }

    public function test_payments_csv_is_excel_compatible_and_respects_account_user_and_date_filters(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Caja Ñandú; "Principal"',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Caja no seleccionada CSV',
        );
        $otherUser = $this->createUserForCompany($this->user, ['nombre' => 'Otro usuario CSV']);
        $this->reportPayment('PG-CSV-INICIO', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-07-01 00:00:00',
            'referencia' => 'Referencia; "especial"',
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '120.50',
        ]);
        $this->reportPayment('=PG-CSV-TRANSFERENCIA', [
            'tipo' => Pago::TYPE_INTERNAL_TRANSFER,
            'cuenta_origen_id' => $selectedAccount,
            'cuenta_destino_id' => $otherAccount,
            'fecha_hora' => '2026-07-15 12:30:45',
            'direccion' => Pago::DIRECTION_NO_FLOW,
            'importe' => '20.00',
        ]);
        $this->reportPayment('PG-CSV-FIN', [
            'cuenta_origen_id' => $selectedAccount,
            'fecha_hora' => '2026-07-31 23:59:59',
            'direccion' => Pago::DIRECTION_EXPENSE,
            'importe' => '45.00',
        ]);
        $this->reportPayment('PG-CSV-ANTES', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-06-30 23:59:59',
        ]);
        $this->reportPayment('PG-CSV-DESPUES', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-08-01 00:00:00',
        ]);
        $this->reportPayment('PG-CSV-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'fecha_hora' => '2026-07-15 10:00:00',
            'importe' => '900.00',
        ]);
        $this->reportPayment('PG-CSV-OTRO-USUARIO', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-07-15 11:00:00',
            'importe' => '800.00',
            'created_by' => $otherUser->id,
        ]);

        $response = $this->get(route('finanzas.reportes.pagos.csv', [
            'cuenta_id' => $selectedAccount,
            'usuario_id' => $this->user->id,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('pagos-2026-07-01-2026-07-31.csv');
        $contents = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFsep=;\r\n", $contents);
        $this->assertStringEndsWith("\r\n", $contents);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $contents));

        $rows = $this->parseExcelCsv($contents);
        $this->assertSame([
            'Fecha y hora',
            'Código',
            'Contraparte',
            'Tipo',
            'Método',
            'Detalle',
            'Responsable',
            'Flujo',
            'Monto',
        ], array_shift($rows));
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['PG-CSV-INICIO', "'=PG-CSV-TRANSFERENCIA", 'PG-CSV-FIN'],
            array_column($rows, 1),
        );
        $this->assertSame(1, count(array_filter(
            $rows,
            fn (array $row): bool => $row[1] === "'=PG-CSV-TRANSFERENCIA",
        )));
        $this->assertSame(0, preg_match('/^\s*[=+\-@]/u', $rows[1][1]));
        $this->assertSame(
            ['01/07/2026 00:00:00', '15/07/2026 12:30:45', '31/07/2026 23:59:59'],
            array_column($rows, 0),
        );
        $this->assertSame(['INGRESO', 'SIN_FLUJO', 'EGRESO'], array_column($rows, 7));
        $this->assertSame(['120,50', '20,00', '45,00'], array_column($rows, 8));
        $this->assertSame([$this->user->nombre], array_values(array_unique(array_column($rows, 6))));
        $this->assertSame(
            'Entidad Caja Ñandú; "Principal" - Caja Ñandú; "Principal" - Referencia; "especial"',
            $rows[0][5],
        );
    }

    public function test_payments_csv_rejects_external_and_other_tenant_accounts(): void
    {
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa invalida CSV',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta ajena invalida CSV',
            owner: $otherTenantUser,
        );

        foreach ([$externalAccount, $otherTenantAccount] as $accountId) {
            $this->from(route('finanzas.reportes'))
                ->get(route('finanzas.reportes.pagos.csv', [
                    'cuenta_id' => $accountId,
                    'desde' => '2026-07-01',
                    'hasta' => '2026-07-31',
                ]))
                ->assertRedirect(route('finanzas.reportes'))
                ->assertSessionHasErrors('cuenta_id');
        }
    }

    public function test_payments_pdf_image_and_csv_reject_a_user_from_another_tenant(): void
    {
        $otherTenantUser = User::factory()->create();
        $query = [
            'usuario_id' => $otherTenantUser->id,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ];
        $urls = [
            route('finanzas.reportes.pdf', ['type' => 'pagos', ...$query]),
            route('finanzas.reportes.imagen', ['type' => 'pagos', ...$query]),
            route('finanzas.reportes.pagos.csv', $query),
        ];

        foreach ($urls as $url) {
            $this->from(route('finanzas.reportes'))
                ->get($url)
                ->assertRedirect(route('finanzas.reportes'))
                ->assertSessionHasErrors('usuario_id');
        }
    }

    public function test_responsible_report_respects_the_account_filter(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta seleccionada responsable reporte',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta no seleccionada responsable reporte',
        );
        $otherUser = $this->createUserForCompany($this->user, ['nombre' => 'Otro responsable de cuenta']);
        $this->reportPayment('PG-RESP-CUENTA', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '70.00',
        ]);
        $this->reportPayment('PG-RESP-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '90.00',
        ]);
        $this->reportPayment('PG-RESP-OTRO-USUARIO', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '110.00',
            'created_by' => $otherUser->id,
        ]);

        $report = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
            $selectedAccount,
        );

        $this->assertSame(['PG-RESP-CUENTA'], $report['rows']->pluck('code')->all());
        $this->assertCount(1, $report['collections']);
        $this->assertCount(0, $report['expenses']);
        $this->assertCount(0, $report['other']);
        $this->assertSame(70.0, (float) $report['income']);
        $this->assertSame(0.0, (float) $report['expense']);
        $this->assertSame(70.0, (float) $report['total']);
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

    public function test_sales_report_stacks_customer_rows_and_only_subtotals_repeated_customers(): void
    {
        $alpha = $this->thirdParty('CLIENTE ALFA', TerceroRole::CLIENT);
        $beta = $this->thirdParty('CLIENTE BETA', TerceroRole::CLIENT);
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'SUC-REPORTE-VENTAS',
            'nombre' => 'Sucursal para reporte de ventas',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => '2026-07-15',
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-07-15 06:00:00',
            'cierre_programado_at' => '2026-07-15 21:00:00',
            'cerrada_por' => $this->user->id,
            'cerrada_at' => '2026-07-15 21:00:00',
        ]);
        $liveChicken = TipoPollo::query()->create([
            'codigo' => 'REPORTE_VENTAS_VIVO',
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
        ]);
        $dressedChicken = TipoPollo::query()->create([
            'codigo' => 'REPORTE_VENTAS_PELADO',
            'nombre' => 'Pollo pelado',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
        ]);

        $this->salesReportTicket(
            $journeyId,
            (int) $alpha->id,
            (int) $liveChicken->id,
            'T-REPORTE-ALFA-VENTA',
            '2026-07-15 08:00:00',
            TicketDespacho::OPERATION_DISPATCH,
            5,
            2,
            20,
            100,
            10,
            90,
        );
        $this->salesReportTicket(
            $journeyId,
            (int) $beta->id,
            (int) $liveChicken->id,
            'T-REPORTE-BETA-VENTA',
            '2026-07-15 09:00:00',
            TicketDespacho::OPERATION_DISPATCH,
            7,
            1,
            10,
            50,
            5,
            45,
        );
        $this->salesReportTicket(
            $journeyId,
            (int) $alpha->id,
            (int) $dressedChicken->id,
            'T-REPORTE-ALFA-DEVOLUCION',
            '2026-07-15 10:00:00',
            TicketDespacho::OPERATION_RETURN,
            6,
            1,
            2,
            10,
            0,
            10,
        );

        $report = app(ReportDataService::class)->salesByCustomer(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame(
            ['CLIENTE ALFA', 'CLIENTE ALFA', 'CLIENTE BETA'],
            $report['rows']->pluck('customer')->all(),
        );
        $this->assertSame(['Pollo vivo', 'Pollo pelado'], $report['customer_groups'][0]['rows']->pluck('product')->all());
        $this->assertCount(2, $report['customer_groups']);
        $this->assertCount(2, $report['customer_groups'][0]['rows']);
        $this->assertCount(1, $report['customer_groups'][1]['rows']);
        $this->assertSame(2, (int) $report['customer_groups'][0]['subtotal']['containers']);
        $this->assertSame(20, (int) $report['customer_groups'][0]['subtotal']['birds']);
        $this->assertSame(10.0, (float) $report['customer_groups'][0]['subtotal']['returns']);
        $this->assertSame(80.0, (float) $report['customer_groups'][0]['subtotal']['net_weight']);
        $this->assertSame(390.0, (float) $report['customer_groups'][0]['subtotal']['amount']);
        $this->assertEqualsWithDelta(4.875, (float) $report['customer_groups'][0]['subtotal']['weighted_price'], 0.0001);
        $this->assertSame(3, (int) $report['totals']['containers']);
        $this->assertSame(30, (int) $report['totals']['birds']);
        $this->assertSame(10.0, (float) $report['totals']['returns']);
        $this->assertSame(125.0, (float) $report['totals']['net_weight']);
        $this->assertSame(705.0, (float) $report['totals']['amount']);

        $html = view('reports.pdf', [
            'company' => $this->user->empresa,
            'type' => 'ventas-clientes',
            'title' => 'Reporte de ventas por cliente',
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'data' => $report,
            'selectedAccount' => null,
            'selectedUser' => null,
            'generatedAt' => CarbonImmutable::parse('2026-07-31 12:00:00'),
        ])->render();
        $document = new \DOMDocument('1.0', 'UTF-8');
        $this->assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">'.$html));
        $xpath = new \DOMXPath($document);
        $details = $xpath->query('//tr[contains(concat(" ", normalize-space(@class), " "), " sales-detail ")]');
        $groupStarts = $xpath->query('//tr[contains(concat(" ", normalize-space(@class), " "), " customer-group-start ")]');
        $subtotals = $xpath->query('//tr[contains(concat(" ", normalize-space(@class), " "), " customer-subtotal ")]');
        $this->assertNotFalse($details);
        $this->assertNotFalse($groupStarts);
        $this->assertNotFalse($subtotals);
        $this->assertSame(3, $details->length);
        $this->assertSame(2, $groupStarts->length);
        $this->assertSame(1, $subtotals->length);
        $subtotalText = preg_replace('/\s+/', ' ', $subtotals->item(0)?->textContent ?? '');
        $this->assertIsString($subtotalText);
        $this->assertStringContainsString('Total CLIENTE ALFA', $subtotalText);
        $this->assertStringContainsString('4.88', $subtotalText);
        $this->assertStringContainsString('390.00', $subtotalText);
        $this->assertStringNotContainsString('Total CLIENTE BETA', preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        $query = [
            'type' => 'ventas-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ];
        $pdf = $this->get(route('finanzas.reportes.pdf', $query));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $image = $this->get(route('finanzas.reportes.imagen', $query));
        $image->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image->getContent());
    }

    public function test_customer_debt_report_can_be_generated_as_pdf_and_image(): void
    {
        $query = [
            'type' => 'deuda-clientes',
            'desde' => '2026-07-15',
            'hasta' => '2026-07-15',
            'moneda' => 'PEN',
        ];
        $pdf = $this->get(route('finanzas.reportes.pdf', $query));

        $pdf->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="deuda-clientes-2026-07-15-2026-07-15.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $image = $this->get(route('finanzas.reportes.imagen', $query));
        $image->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="deuda-clientes-2026-07-15-2026-07-15.png"');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image->getContent());
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

    public function test_responsible_report_includes_each_active_cash_movement_once_in_its_real_section(): void
    {
        [$cashRegisterId, $otherCashRegisterId] = $this->cashRegisters();
        $client = $this->thirdParty('Cliente caja reporte', TerceroRole::CLIENT);
        $otherUser = $this->userForCompany('Responsable ajeno');

        $income = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'CLIENTE',
            'cliente_id' => $client->id,
            'fecha_hora' => '2026-07-10 09:00:00',
            'importe' => '100.00',
            'detalle' => 'Cobro en efectivo del pedido 100',
        ]));
        $expense = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'ADMINISTRATIVO',
            'fecha_hora' => '2026-07-10 10:00:00',
            'importe' => '30.00',
            'detalle' => 'Compra administrativa de utiles',
        ]));
        $transfer = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'OTRA_CAJA',
            'otra_caja_id' => $otherCashRegisterId,
            'fecha_hora' => '2026-07-10 11:00:00',
            'importe' => '20.00',
            'detalle' => 'Fondo enviado a caja secundaria',
        ]));
        $foreignMovement = $this->cashMovement($this->registerCashMovement($otherUser, $cashRegisterId, [
            'fecha_hora' => '2026-07-10 12:00:00',
            'importe' => '999.00',
            'detalle' => 'Movimiento de otro responsable',
        ]));

        $report = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $rows = $report['rows'];
        $rowsByCode = $rows->keyBy('code');

        $this->assertCount(3, $rows);
        $this->assertCount(3, $rows->pluck('code')->unique());
        $this->assertSame($this->user->nombre, $report['user_name']);
        $this->assertSame([$this->user->nombre], $rows->pluck('user')->unique()->values()->all());
        $this->assertSame(100.0, (float) $report['income']);
        $this->assertSame(30.0, (float) $report['expense']);
        $this->assertSame(150.0, (float) $report['total']);
        $this->assertCount(1, $report['collections']);
        $this->assertCount(1, $report['expenses']);
        $this->assertCount(1, $report['other']);
        $this->assertFalse($rowsByCode->has($foreignMovement->codigo));

        foreach ([$income, $expense, $transfer] as $cashMovement) {
            $this->assertStringStartsWith('CAJ-', $cashMovement->codigo);
            $this->assertSame(1, $rows->where('code', $cashMovement->codigo)->count());
        }

        $incomeRow = $rowsByCode->get($income->codigo);
        $this->assertSame('INGRESO DE CAJA', $incomeRow['type']);
        $this->assertSame('INGRESO', $incomeRow['flow']);
        $this->assertSame('Cliente caja reporte', $incomeRow['counterparty']);
        $this->assertSame('Cobro en efectivo del pedido 100', $incomeRow['detail']);
        $this->assertSame($income->codigo, $report['collections']->sole()['code']);

        $expenseRow = $rowsByCode->get($expense->codigo);
        $this->assertSame('GASTO DE CAJA', $expenseRow['type']);
        $this->assertSame('EGRESO', $expenseRow['flow']);
        $this->assertSame('Administrativo', $expenseRow['counterparty']);
        $this->assertSame('Compra administrativa de utiles', $expenseRow['detail']);
        $this->assertSame($expense->codigo, $report['expenses']->sole()['code']);

        $transferRow = $rowsByCode->get($transfer->codigo);
        $this->assertSame('TRANSFERENCIA ENTRE CAJAS', $transferRow['type']);
        $this->assertSame('SIN_FLUJO', $transferRow['flow']);
        $this->assertStringContainsString('Caja secundaria reporte', $transferRow['counterparty']);
        $this->assertSame('Fondo enviado a caja secundaria', $transferRow['detail']);
        $this->assertSame($transfer->codigo, $report['other']->sole()['code']);
    }

    public function test_financial_cash_edit_keeps_the_logical_creator_and_lists_only_the_active_replacement(): void
    {
        [$cashRegisterId] = $this->cashRegisters();
        $editor = $this->userForCompany('Editor de caja');
        $movementId = $this->registerCashMovement($this->user, $cashRegisterId, [
            'fecha_hora' => '2026-07-15 09:00:00',
            'importe' => '80.00',
            'detalle' => 'Ingreso antes de corregir',
        ]);
        $before = $this->cashMovement($movementId);

        app(CashRegisterMovementService::class)->update(
            (int) $this->user->empresa_id,
            $editor,
            $movementId,
            $this->cashMovementPayload(null, $cashRegisterId, [
                'fecha_hora' => '2026-07-15 09:00:00',
                'importe' => '65.00',
                'detalle' => 'Ingreso corregido por el editor',
            ]),
        );
        $after = $this->cashMovement($movementId);

        $this->assertNotSame((int) $before->pago_id, (int) $after->pago_id);
        $this->assertDatabaseHas('pagos', [
            'id' => $before->pago_id,
            'estado' => Pago::STATUS_VOIDED,
            'importe' => 80,
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $before->pago_id,
            'importe' => 80,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $after->pago_id,
            'created_by' => $editor->id,
            'estado' => Pago::STATUS_REGISTERED,
            'importe' => 65,
        ]);
        $this->assertDatabaseCount('pagos', 3);

        $creatorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $editorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $editor->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertCount(1, $creatorReport['rows']);
        $this->assertCount(1, $creatorReport['collections']);
        $this->assertCount(0, $creatorReport['expenses']);
        $this->assertCount(0, $creatorReport['other']);
        $this->assertSame(65.0, (float) $creatorReport['income']);
        $this->assertSame(0.0, (float) $creatorReport['expense']);
        $this->assertSame(65.0, (float) $creatorReport['total']);
        $this->assertSame($before->codigo, $creatorReport['rows']->sole()['code']);
        $this->assertSame($this->user->nombre, $creatorReport['rows']->sole()['user']);
        $this->assertSame(65.0, (float) $creatorReport['rows']->sole()['amount']);
        $this->assertSame('Ingreso corregido por el editor', $creatorReport['rows']->sole()['detail']);
        $this->assertFalse($creatorReport['rows']->contains(
            fn (array $row): bool => (float) $row['amount'] === 80.0,
        ));

        $this->assertEmptyResponsibleReport($editorReport);
    }

    public function test_voided_cash_movement_is_excluded_from_creator_and_voider_reports_and_totals(): void
    {
        [$cashRegisterId] = $this->cashRegisters();
        $voider = $this->userForCompany('Anulador de caja');
        $movementId = $this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'ADMINISTRATIVO',
            'fecha_hora' => '2026-07-20 14:00:00',
            'importe' => '45.00',
            'detalle' => 'Gasto administrativo que sera eliminado',
        ]);
        $cashMovement = $this->cashMovement($movementId);

        app(CashRegisterMovementService::class)->void(
            (int) $this->user->empresa_id,
            $voider,
            $movementId,
        );

        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $movementId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $cashMovement->pago_id,
            'estado' => Pago::STATUS_VOIDED,
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $cashMovement->pago_id,
            'created_by' => $voider->id,
        ]);

        $creatorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $voiderReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $voider->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertEmptyResponsibleReport($creatorReport);
        $this->assertEmptyResponsibleReport($voiderReport);
        $this->assertFalse($creatorReport['rows']->pluck('code')->contains($cashMovement->codigo));
        $this->assertFalse($voiderReport['rows']->pluck('code')->contains($cashMovement->codigo));
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

    public function test_customer_statement_compacts_credit_notes_and_collection_destinations(): void
    {
        $client = $this->thirdParty('Cliente con detalle compacto', TerceroRole::CLIENT);
        $accountId = $this->financialAccount(EntidadFinanciera::TYPE_OWN, 'DARWIND');
        $account = CuentaFinanciera::query()->with('entidadFinanciera')->findOrFail($accountId);
        $account->entidadFinanciera()->update([
            'razon_social' => 'SADACSA',
            'nombre_comercial' => 'SADACSA',
        ]);
        Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CREDIT,
            'tipo_documento' => 'NOTA DE CREDITO',
            'codigo' => 'NCV-COMPACTA',
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-07-10',
            'moneda' => 'PEN',
            'subtotal' => '50.00',
            'impuesto' => '0.00',
            'total' => '50.00',
            'saldo_pendiente' => '0.00',
            'estado' => Comprobante::STATUS_PAID,
            'created_by' => $this->user->id,
        ]);
        Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-COMPACTO',
            'tercero_id' => $client->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $client->id,
            'cuenta_destino_id' => $accountId,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-11 10:00:00',
            'metodo' => 'EFECTIVO',
            'referencia' => 'NORMA',
            'moneda' => 'PEN',
            'importe' => '20.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);

        $rows = app(ReportDataService::class)->customerStatement(
            (int) $this->user->empresa_id,
            (int) $client->id,
            '2026-07-01',
            '2026-07-31',
        )['rows']->keyBy('code');

        $this->assertSame('DEV', $rows->get('NCV-COMPACTA')['type']);
        $this->assertSame('EFECTIVO - Caja: DARWIND', $rows->get('PG-COMPACTO')['detail']);
        $this->assertStringNotContainsString('SADACSA', $rows->get('PG-COMPACTO')['detail']);
        $this->assertStringNotContainsString('NORMA', $rows->get('PG-COMPACTO')['detail']);
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
        foreach (['Fec.', 'Cód.', 'Tipo', 'Det.', 'Kg', 'P/Kg', 'C/A', 'Saldo'] as $heading) {
            $this->assertStringContainsString($heading, $plainText);
        }
        $this->assertStringContainsString('Saldo anterior', $plainText);
        $this->assertStringNotContainsString('Saldo anterior al', $plainText);
        $this->assertSame(2, substr_count($plainText, 'Movimientos del'));
        $this->assertStringContainsString('class="num debit"', $html);
        $this->assertStringContainsString('class="num credit"', $html);
    }

    public function test_customer_debt_pdf_layout_matches_the_daily_reference_columns(): void
    {
        $html = view('reports.pdf', [
            'company' => $this->user->empresa,
            'type' => 'deuda-clientes',
            'title' => 'Reporte de cuentas de clientes',
            'from' => '2026-07-15',
            'to' => '2026-07-15',
            'data' => [
                'currency' => 'PEN',
                'rows' => collect([[
                    'customer_id' => 1,
                    'customer' => 'CLIENTE DE REFERENCIA',
                    'opening' => '100.00',
                    'period_debt' => '50.00',
                    'debt_to_date' => '150.00',
                    'payments' => '40.00',
                    'balance' => '110.00',
                ]]),
                'totals' => [
                    'opening' => '100.00',
                    'period_debt' => '50.00',
                    'debt_to_date' => '150.00',
                    'payments' => '40.00',
                    'balance' => '110.00',
                ],
            ],
            'generatedAt' => CarbonImmutable::parse('2026-07-16 12:30:00'),
        ])->render();
        $plainText = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertIsString($plainText);
        foreach ([
            'Reporte de cuentas de clientes',
            'Actualizado hasta el 16/07/2026 12:30 - Moneda: PEN',
            'TOTALES',
            'Clientes',
            'Deuda hasta ayer',
            '14/07/2026',
            'Deuda',
            '15/07/2026',
            'Total deuda hasta',
            'Pagos realizados',
            'Total deuda',
            'CLIENTE DE REFERENCIA',
        ] as $text) {
            $this->assertStringContainsString($text, $plainText);
        }
        $this->assertStringNotContainsString('S/ ', $plainText);
    }

    /** @return array{int, int} */
    private function cashRegisters(): array
    {
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Entidad de cajas para reporte',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = function (string $alias) use ($entityId): int {
            return DB::table('cuentas_financieras')->insertGetId([
                'entidad_financiera_id' => $entityId,
                'tipo' => 'CAJA',
                'alias' => $alias,
                'moneda' => 'PEN',
                'estado' => 'ACTIVO',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        return [
            $account('Caja principal reporte'),
            $account('Caja secundaria reporte'),
        ];
    }

    private function financialAccount(
        string $entityType,
        string $alias,
        string $accountStatus = CuentaFinanciera::STATUS_ACTIVE,
        ?User $owner = null,
        string $entityStatus = EntidadFinanciera::STATUS_ACTIVE,
    ): int {
        $owner ??= $this->user;
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $owner->empresa_id,
            'tipo' => $entityType,
            'razon_social' => 'Entidad '.$alias,
            'estado' => $entityStatus,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => CuentaFinanciera::TYPE_CASH,
            'alias' => $alias,
            'moneda' => 'PEN',
            'estado' => $accountStatus,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<list<string>> */
    private function parseExcelCsv(string $contents): array
    {
        $prefix = "\xEF\xBB\xBFsep=;\r\n";
        $this->assertStringStartsWith($prefix, $contents);
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, substr($contents, strlen($prefix)));
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /** @param array<string, mixed> $overrides */
    private function reportPayment(string $code, array $overrides = []): Pago
    {
        return Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => $code,
            'tipo' => Pago::TYPE_ADJUSTMENT,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-15 10:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '10.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function registerCashMovement(User $actor, int $cashRegisterId, array $overrides = []): int
    {
        $result = app(CashRegisterMovementService::class)->register(
            (int) $this->user->empresa_id,
            $actor,
            $this->cashMovementPayload((string) Str::uuid(), $cashRegisterId, $overrides),
        );

        return (int) $result['movimiento_caja_id'];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function cashMovementPayload(
        ?string $idempotencyKey,
        int $cashRegisterId,
        array $overrides = [],
    ): array {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'caja_id' => $cashRegisterId,
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'OTRO',
            'fecha_hora' => '2026-07-10 08:00:00',
            'importe' => '10.00',
            'detalle' => 'Movimiento de caja para reporte',
            ...$overrides,
        ];

        if ($idempotencyKey === null) {
            unset($payload['idempotency_key']);
        }

        return $payload;
    }

    private function cashMovement(int $movementId): object
    {
        $movement = DB::table('movimientos_caja_efectivo')->where('id', $movementId)->first();
        $this->assertNotNull($movement);

        return $movement;
    }

    private function userForCompany(string $name): User
    {
        $user = $this->createUserForCompany($this->user, ['nombre' => $name]);
        $administratorRole = $this->user->roles()
            ->where('roles.codigo', 'ADMINISTRADOR')
            ->firstOrFail();
        $user->roles()->attach($administratorRole);

        return $user;
    }

    /** @param array<string, mixed> $report */
    private function assertEmptyResponsibleReport(array $report): void
    {
        $this->assertCount(0, $report['rows']);
        $this->assertCount(0, $report['collections']);
        $this->assertCount(0, $report['expenses']);
        $this->assertCount(0, $report['other']);
        $this->assertSame(0.0, (float) $report['income']);
        $this->assertSame(0.0, (float) $report['expense']);
        $this->assertSame(0.0, (float) $report['total']);
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

    private function salesReportTicket(
        int $journeyId,
        int $customerId,
        int $chickenTypeId,
        string $code,
        string $closedAt,
        string $operation,
        float $price,
        int $containers,
        int $birds,
        float $grossWeight,
        float $tare,
        float $netWeight,
    ): void {
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'tipo_operacion' => $operation,
            'cliente_destino_id' => $customerId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => $closedAt,
            'created_by' => $this->user->id,
            'created_at' => $closedAt,
            'updated_at' => $closedAt,
        ]);
        DB::table('ticket_precios')->insert([
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $chickenTypeId,
            'precio_historial_id' => null,
            'precio_kg' => $price,
            'origen_precio' => 'MANUAL',
            'congelado_por' => $this->user->id,
            'created_at' => $closedAt,
        ]);
        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $chickenTypeId,
            'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
            'sexo' => Pesada::SEX_MALE,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => intdiv($birds, max(1, $containers)),
            'cantidad_javas' => $containers,
            'cantidad_aves' => $birds,
            'peso_java_kg_snapshot' => $containers > 0 ? $tare / $containers : 0,
            'peso_leido_kg' => $grossWeight,
            'peso_bruto_kg' => $grossWeight,
            'tara_total_kg' => $tare,
            'peso_neto_kg' => $netWeight,
            'pesada_at' => $closedAt,
            'estado' => Pesada::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'created_at' => $closedAt,
            'updated_at' => $closedAt,
        ]);
    }
}
