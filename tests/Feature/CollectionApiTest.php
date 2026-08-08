<?php

namespace Tests\Feature;

use App\Models\TerceroRole;
use App\Models\User;
use App\Services\FinancialAccountBalanceService;
use App\Services\ReportDataService;
use App\Support\FinancialMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class CollectionApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->grantModules(
            $this->user,
            ['MODULO_FINANZAS'],
            'COBRANZAS_TEST',
            'Cobranzas test',
        );
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_collectors_can_be_created_and_are_listed_only_for_the_active_company(): void
    {
        $this->postJson('/api/v1/finanzas/cobradores', [
            'nombre' => 'Ana Torres',
        ])->assertCreated()
            ->assertJsonPath('data.nombre', 'Ana Torres')
            ->assertJsonPath('data.estado', 'ACTIVO');

        $activeCollectorId = (int) DB::table('cobradores')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('nombre', 'Ana Torres')
            ->value('id');
        $this->collector('Cobrador inactivo', $this->user, 'INACTIVO');

        $foreignUser = User::factory()->create();
        $this->collector('Cobrador de otra empresa', $foreignUser);

        $this->getJson('/api/v1/finanzas/cobradores')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $activeCollectorId,
                'nombre' => 'Ana Torres',
                'estado' => 'ACTIVO',
            ])
            ->assertJsonMissing(['nombre' => 'Cobrador inactivo'])
            ->assertJsonMissing(['nombre' => 'Cobrador de otra empresa']);

        $this->getJson('/api/v1/finanzas/cobradores?incluir_inactivos=1')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Cobrador inactivo'])
            ->assertJsonMissing(['nombre' => 'Cobrador de otra empresa']);
    }

    public function test_an_own_account_collection_creates_one_child_payment_per_client_and_applies_receivables_fifo(): void
    {
        $collector = $this->collector('Carlos Caja');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco principal');
        $clientOne = $this->thirdParty('CLIENTE', 'Cliente Uno', '10111111');
        $clientTwo = $this->thirdParty('CLIENTE', 'Cliente Dos', '10222222');
        $clientOneOld = $this->document('VENTA', $clientOne, '25.00', 'CXC-UNO-ANTIGUA', '2026-07-01');
        $clientOneNew = $this->document('VENTA', $clientOne, '50.00', 'CXC-UNO-NUEVA', '2026-07-10');
        $clientTwoDebt = $this->document('VENTA', $clientTwo, '80.00', 'CXC-DOS', '2026-07-02');
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            $key,
            $collector,
            $account,
            '100.00',
            [
                ['cliente_id' => $clientOne, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00'],
                ['cliente_id' => $clientTwo, 'fecha_recepcion' => '2026-07-31', 'importe' => '60.00'],
            ],
            ['referencia' => 'VOUCHER-PROPIO-001'],
        ))->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.recibido_en_caja', null)
            ->assertJsonPath('data.recepcion_caja.estado', 'NO_APLICA')
            ->assertJsonPath('data.recepcion_caja.puede_actualizar', false);

        $collection = DB::table('cobranzas')->where('idempotency_key', $key)->first();
        $this->assertNotNull($collection);
        $this->assertSame('100.00', $this->money($collection->importe_total));
        $this->assertSame($account, (int) $collection->cuenta_destino_id);
        $this->assertNull($collection->proveedor_id);
        $this->assertSame('VOUCHER-PROPIO-001', $collection->referencia);

        $details = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collection->id)
            ->orderBy('orden')
            ->get();
        $this->assertCount(2, $details);
        $this->assertSame('100.00', $this->money($details->sum('importe')));
        $this->assertTrue($details->every(fn (object $detail): bool => $detail->medio_recepcion === 'EFECTIVO'));

        $paymentIds = $details->pluck('pago_id')->map(fn ($id): int => (int) $id);
        $payments = DB::table('pagos')->whereIn('id', $paymentIds)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(2, $paymentIds->unique()->count());
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->tipo === 'COBRO_CLIENTE'));
        $this->assertTrue($payments->every(fn (object $payment): bool => (int) $payment->cuenta_destino_id === $account));
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->proveedor_id === null));
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->metodo === 'DEPOSITO'));
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->referencia === 'VOUCHER-PROPIO-001'));
        $this->assertTrue($payments->every(
            fn (object $payment): bool => (string) $payment->fecha_hora === (string) $collection->fecha_hora,
        ));
        $this->assertSame('2026-07-30', (string) $details->firstWhere('cliente_id', $clientOne)->fecha_recepcion);
        $this->assertSame('2026-07-31', (string) $details->firstWhere('cliente_id', $clientTwo)->fecha_recepcion);

        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $payments->firstWhere('cliente_id', $clientOne)->id,
            'comprobante_id' => $clientOneOld,
            'lado' => 'CXC',
            'importe_aplicado' => 25,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $payments->firstWhere('cliente_id', $clientOne)->id,
            'comprobante_id' => $clientOneNew,
            'lado' => 'CXC',
            'importe_aplicado' => 15,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $payments->firstWhere('cliente_id', $clientTwo)->id,
            'comprobante_id' => $clientTwoDebt,
            'lado' => 'CXC',
            'importe_aplicado' => 60,
        ]);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientOneOld, 'saldo_pendiente' => 0, 'estado' => 'PAGADO']);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientOneNew, 'saldo_pendiente' => 35, 'estado' => 'PARCIAL']);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientTwoDebt, 'saldo_pendiente' => 20, 'estado' => 'PARCIAL']);
        $this->assertSame('100.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);

        // There is no aggregate Pago for the voucher: only the two traceable client rows.
        $this->assertDatabaseCount('pagos', 2);

        $this->putJson("/api/v1/finanzas/cobranzas/{$collection->id}/recepcion-caja", [
            'recibido' => true,
            'estado_esperado' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('recibido');
    }

    public function test_a_provider_account_collection_creates_direct_payments_and_applies_both_portfolios_fifo(): void
    {
        $collector = $this->collector('Diana Ruta');
        $provider = $this->thirdParty('PROVEEDOR', 'Proveedor Norte', '20111111111');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'Cuenta proveedor');
        [, $unusedOwnAccount] = $this->financialAccount('PROPIA', null, 'Cuenta propia sin ingreso');
        $clientOne = $this->thirdParty('CLIENTE', 'Cliente Directo Uno', '10333333');
        $clientTwo = $this->thirdParty('CLIENTE', 'Cliente Directo Dos', '10444444');

        $clientOneOld = $this->document('VENTA', $clientOne, '30.00', 'CXC-DIRECTA-UNO-1', '2026-06-01');
        $clientOneNew = $this->document('VENTA', $clientOne, '30.00', 'CXC-DIRECTA-UNO-2', '2026-06-15');
        $clientTwoDebt = $this->document('VENTA', $clientTwo, '70.00', 'CXC-DIRECTA-DOS', '2026-06-02');
        $providerOld = $this->document('COMPRA', $provider, '50.00', 'CXP-DIRECTA-1', '2026-05-01');
        $providerNew = $this->document('COMPRA', $provider, '80.00', 'CXP-DIRECTA-2', '2026-05-20');
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            $key,
            $collector,
            $providerAccount,
            '100.00',
            [
                ['cliente_id' => $clientOne, 'fecha_recepcion' => '2026-07-29', 'importe' => '40.00'],
                ['cliente_id' => $clientTwo, 'fecha_recepcion' => '2026-07-30', 'importe' => '60.00'],
            ],
            ['referencia' => 'VOUCHER-PROV-001'],
        ))->assertCreated();

        $collection = DB::table('cobranzas')->where('idempotency_key', $key)->first();
        $this->assertNotNull($collection);
        $this->assertSame($provider, (int) $collection->proveedor_id);
        $paymentIds = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collection->id)
            ->pluck('pago_id');
        $payments = DB::table('pagos')->whereIn('id', $paymentIds)->get();

        $this->assertCount(2, $payments);
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->tipo === 'PAGO_DIRECTO'));
        $this->assertTrue($payments->every(fn (object $payment): bool => (int) $payment->proveedor_id === $provider));
        $this->assertTrue($payments->every(fn (object $payment): bool => (int) $payment->cuenta_destino_id === $providerAccount));
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->cuenta_origen_id === null));
        $this->assertTrue($payments->every(fn (object $payment): bool => $payment->referencia === 'VOUCHER-PROV-001'));

        $this->assertDatabaseHas('comprobantes', ['id' => $clientOneOld, 'saldo_pendiente' => 0]);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientOneNew, 'saldo_pendiente' => 20]);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientTwoDebt, 'saldo_pendiente' => 10]);
        $this->assertDatabaseHas('comprobantes', ['id' => $providerOld, 'saldo_pendiente' => 0]);
        $this->assertDatabaseHas('comprobantes', ['id' => $providerNew, 'saldo_pendiente' => 30]);

        $this->assertSame('100.00', $this->money(DB::table('pago_aplicaciones')
            ->whereIn('pago_id', $paymentIds)
            ->where('lado', 'CXC')
            ->sum('importe_aplicado')));
        $this->assertSame('100.00', $this->money(DB::table('pago_aplicaciones')
            ->whereIn('pago_id', $paymentIds)
            ->where('lado', 'CXP')
            ->sum('importe_aplicado')));
        $this->assertSame('0.00', app(FinancialAccountBalanceService::class)->forAccount($unusedOwnAccount)['saldo']);

        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.paid_directly_by_clients', '100.00')
            ->assertJsonCount(2, 'data.recent_direct_deposits')
            ->assertJsonFragment(['reference' => 'VOUCHER-PROV-001']);

        $statement = app(ReportDataService::class)->providerStatement(
            (int) $this->user->empresa_id,
            $provider,
            '2026-08-01',
            '2026-08-01',
        );

        $this->assertCount(1, $statement['rows']);
        $this->assertStringContainsString('VOUCHER-PROV-001', $statement['rows']->first()['detail']);
        $this->assertSame(100.0, $statement['rows']->first()['credit']);
        $this->assertSame(-100.0, $statement['rows']->first()['effect']);
        $this->assertSame(100.0, (float) $statement['credits']);
    }

    public function test_an_own_account_collection_can_keep_the_undisclosed_remainder_pending_without_affecting_customer_receivables(): void
    {
        $collector = $this->collector('Cobrador con pendiente propio');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco con pendiente propio');
        $client = $this->thirdParty('CLIENTE', 'Cliente parcialmente identificado', '10444448');
        $receivable = $this->document('VENTA', $client, '400.00', 'CXC-PENDIENTE-PROPIO', '2026-07-01');

        $response = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '500.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '200.00']],
            ['referencia' => 'VOUCHER-PENDIENTE-PROPIO'],
        ))->assertCreated()
            ->assertJsonPath('data.importe_total', '500.00')
            ->assertJsonPath('data.importe_asignado', '200.00')
            ->assertJsonPath('data.importe_pendiente', '300.00')
            ->assertJsonPath('data.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.pendiente.importe', '300.00')
            ->assertJsonPath('data.pendiente.pago.tipo', 'DEPOSITO_NO_ASIGNADO');
        $collectionId = (int) $response->json('data.id');

        $detailPaymentId = (int) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');
        $pending = DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->first();
        $this->assertNotNull($pending);
        $this->assertSame('300.00', $this->money($pending->importe));
        $this->assertNotSame($detailPaymentId, (int) $pending->pago_id);

        $this->assertDatabaseHas('pagos', [
            'id' => $detailPaymentId,
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'importe' => 200,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $pending->pago_id,
            'tipo' => 'DEPOSITO_NO_ASIGNADO',
            'cliente_id' => null,
            'proveedor_id' => null,
            'cuenta_destino_id' => $account,
            'importe' => 300,
        ]);
        $this->assertDatabaseMissing('pago_aplicaciones', ['pago_id' => $pending->pago_id]);
        $this->assertSame('200.00', $this->money(DB::table('pago_aplicaciones')
            ->where('pago_id', $detailPaymentId)
            ->where('lado', 'CXC')
            ->sum('importe_aplicado')));
        $this->assertDatabaseHas('comprobantes', [
            'id' => $receivable,
            'saldo_pendiente' => 200,
            'estado' => 'PARCIAL',
        ]);
        $this->assertSame('500.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
        $this->assertDatabaseCount('pagos', 2);

        $this->getJson("/api/v1/finanzas/cobranzas/{$collectionId}")
            ->assertOk()
            ->assertJsonPath('data.importe_asignado', '200.00')
            ->assertJsonPath('data.importe_pendiente', '300.00')
            ->assertJsonPath('data.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.pendiente.importe', '300.00')
            ->assertJsonPath('data.pendiente.pago.id', (int) $pending->pago_id)
            ->assertJsonPath('data.pendiente.pago.cliente', null);
    }

    public function test_a_provider_collection_uses_the_full_deposit_for_payables_while_only_the_breakdown_affects_customer_receivables(): void
    {
        $collector = $this->collector('Cobrador con pendiente proveedor');
        $provider = $this->thirdParty('PROVEEDOR', 'Proveedor con depósito pendiente', '20444444445');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'Cuenta proveedor con pendiente');
        $client = $this->thirdParty('CLIENTE', 'Cliente identificado parcialmente', '10444449');
        $receivable = $this->document('VENTA', $client, '400.00', 'CXC-PENDIENTE-PROVEEDOR', '2026-07-01');
        $payable = $this->document('COMPRA', $provider, '450.00', 'CXP-PENDIENTE-PROVEEDOR', '2026-06-01');

        $response = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $providerAccount,
            '500.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '200.00']],
            ['referencia' => 'VOUCHER-PENDIENTE-PROVEEDOR'],
        ))->assertCreated()
            ->assertJsonPath('data.importe_asignado', '200.00')
            ->assertJsonPath('data.importe_pendiente', '300.00')
            ->assertJsonPath('data.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.importe_aplicado_cxp', '450.00')
            ->assertJsonPath('data.saldo_favor_proveedor', '50.00')
            ->assertJsonPath('data.pendiente.pago.tipo', 'DEPOSITO_NO_ASIGNADO');
        $collectionId = (int) $response->json('data.id');

        $detailPaymentId = (int) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');
        $pendingPaymentId = (int) DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');

        $this->assertDatabaseHas('pagos', [
            'id' => $detailPaymentId,
            'tipo' => 'PAGO_DIRECTO',
            'cliente_id' => $client,
            'proveedor_id' => $provider,
            'importe' => 200,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $pendingPaymentId,
            'tipo' => 'DEPOSITO_NO_ASIGNADO',
            'cliente_id' => null,
            'proveedor_id' => $provider,
            'cuenta_destino_id' => $providerAccount,
            'importe' => 300,
        ]);
        $this->assertSame('200.00', $this->money(DB::table('pago_aplicaciones')
            ->where('pago_id', $detailPaymentId)
            ->where('lado', 'CXC')
            ->sum('importe_aplicado')));
        $this->assertSame('0.00', $this->money(DB::table('pago_aplicaciones')
            ->where('pago_id', $pendingPaymentId)
            ->where('lado', 'CXC')
            ->sum('importe_aplicado')));
        $this->assertSame('450.00', $this->money(DB::table('pago_aplicaciones')
            ->whereIn('pago_id', [$detailPaymentId, $pendingPaymentId])
            ->where('lado', 'CXP')
            ->sum('importe_aplicado')));
        $this->assertDatabaseHas('comprobantes', [
            'id' => $receivable,
            'saldo_pendiente' => 200,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $payable,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);

        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.payments', '500.00')
            ->assertJsonPath('data.applied', '450.00')
            ->assertJsonPath('data.unapplied', '50.00')
            ->assertJsonPath('data.paid_directly_by_clients', '200.00');

        $statement = app(ReportDataService::class)->providerStatement(
            (int) $this->user->empresa_id,
            $provider,
            '2026-08-01',
            '2026-08-01',
        );

        $this->assertCount(1, $statement['rows']);
        $this->assertStringContainsString(
            'VOUCHER-PENDIENTE-PROVEEDOR',
            $statement['rows']->first()['detail'],
        );
        $this->assertSame(500.0, $statement['rows']->first()['credit']);
        $this->assertSame(-500.0, $statement['rows']->first()['effect']);
        $this->assertSame(500.0, (float) $statement['credits']);
        $this->assertSame(-50.0, $statement['balance']);
    }

    public function test_one_client_can_have_multiple_collection_rows_with_separate_dates_and_sequential_fifo_applications(): void
    {
        $collector = $this->collector('Cobrador entregas separadas');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco entregas separadas');
        $client = $this->thirdParty('CLIENTE', 'Cliente con dos entregas', '10444445');
        $oldDebt = $this->document('VENTA', $client, '50.00', 'CXC-DOS-ENTREGAS-ANTIGUA', '2026-06-01');
        $newDebt = $this->document('VENTA', $client, '50.00', 'CXC-DOS-ENTREGAS-NUEVA', '2026-06-15');

        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '70.00',
            [
                ['cliente_id' => $client, 'fecha_recepcion' => '2026-07-29', 'importe' => '30.00'],
                ['cliente_id' => $client, 'fecha_recepcion' => '2026-07-31', 'importe' => '40.00'],
            ],
            ['referencia' => 'VOUCHER-DOS-ENTREGAS'],
        ))->assertCreated()->json('data.id');

        $details = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->orderBy('orden')
            ->get();
        $this->assertCount(2, $details);
        $this->assertSame($client, (int) $details[0]->cliente_id);
        $this->assertSame($client, (int) $details[1]->cliente_id);
        $this->assertSame('2026-07-29', (string) $details[0]->fecha_recepcion);
        $this->assertSame('2026-07-31', (string) $details[1]->fecha_recepcion);
        $this->assertSame('30.00', $this->money($details[0]->importe));
        $this->assertSame('40.00', $this->money($details[1]->importe));
        $this->assertNotSame((int) $details[0]->pago_id, (int) $details[1]->pago_id);

        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $details[0]->pago_id,
            'comprobante_id' => $oldDebt,
            'lado' => 'CXC',
            'importe_aplicado' => 30,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $details[1]->pago_id,
            'comprobante_id' => $oldDebt,
            'lado' => 'CXC',
            'importe_aplicado' => 20,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $details[1]->pago_id,
            'comprobante_id' => $newDebt,
            'lado' => 'CXC',
            'importe_aplicado' => 20,
        ]);
        $this->assertSame(1, DB::table('pago_aplicaciones')
            ->where('pago_id', $details[0]->pago_id)
            ->where('lado', 'CXC')
            ->count());
        $this->assertDatabaseHas('comprobantes', [
            'id' => $oldDebt,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $newDebt,
            'saldo_pendiente' => 30,
            'estado' => 'PARCIAL',
        ]);
    }

    public function test_provider_credit_from_a_collection_direct_payment_can_be_applied_to_a_later_payable(): void
    {
        $collector = $this->collector('Cobrador con saldo proveedor');
        $provider = $this->thirdParty('PROVEEDOR', 'Proveedor con saldo posterior', '20444444444');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'Cuenta con saldo posterior');
        $client = $this->thirdParty('CLIENTE', 'Cliente para saldo proveedor', '10444446');
        $this->document('VENTA', $client, '100.00', 'CXC-SALDO-PROVEEDOR', '2026-06-01');
        $initialPayable = $this->document('COMPRA', $provider, '30.00', 'CXP-SALDO-INICIAL', '2026-06-01');

        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $providerAccount,
            '100.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '100.00']],
            ['referencia' => 'VOUCHER-SALDO-PROVEEDOR'],
        ))->assertCreated()
            ->assertJsonPath('data.importe_aplicado_cxp', '30.00')
            ->assertJsonPath('data.saldo_favor_proveedor', '70.00')
            ->json('data.id');

        $paymentId = (int) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'tipo' => 'PAGO_DIRECTO',
            'proveedor_id' => $provider,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $initialPayable,
            'lado' => 'CXP',
            'importe_aplicado' => 30,
        ]);

        $laterPayable = $this->document('COMPRA', $provider, '50.00', 'CXP-CREADA-DESPUES', '2026-08-02');
        $this->postJson("/api/v1/finanzas/movimientos/{$paymentId}/aplicaciones", [
            'idempotency_key' => (string) Str::uuid(),
            'aplicaciones' => [[
                'comprobante_id' => $laterPayable,
                'importe_aplicado' => '50.00',
            ]],
            'observaciones' => 'Aplicacion posterior del saldo originado en cobranza.',
        ])->assertCreated()
            ->assertJsonPath('data.aplicacion.importe_aplicado', '80.00')
            ->assertJsonPath('data.aplicacion.importe_sin_aplicar', '20.00');

        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $laterPayable,
            'lado' => 'CXP',
            'importe_aplicado' => 50,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $laterPayable,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);
        $this->getJson("/api/v1/finanzas/cobranzas/{$collectionId}")
            ->assertOk()
            ->assertJsonPath('data.importe_aplicado_cxp', '80.00')
            ->assertJsonPath('data.saldo_favor_proveedor', '20.00');
        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.unapplied', '20.00');
    }

    public function test_collection_catalog_index_and_show_expose_the_contract_consumed_by_the_ui(): void
    {
        $collector = $this->collector('Cobrador contrato UI');
        $inactiveCollector = $this->collector('Cobrador inactivo contrato UI', $this->user, 'INACTIVO');
        [, $account] = $this->financialAccount('PROPIA', null, 'Cuenta contrato UI');
        $client = $this->thirdParty('CLIENTE', 'Cliente contrato UI', '10444447');
        $document = $this->document('VENTA', $client, '75.00', 'CXC-CONTRATO-UI', '2026-06-01');

        $this->getJson('/api/v1/finanzas/cobranzas/catalogo')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'timezone',
                    'moneda',
                    'cobradores' => [['id', 'nombre', 'estado']],
                    'clientes' => [['id', 'numero_documento', 'nombre']],
                    'cuentas_destino' => [[
                        'id',
                        'tipo',
                        'alias',
                        'banco',
                        'numero_cuenta',
                        'cci',
                        'moneda',
                        'entidad' => ['id', 'tipo', 'razon_social', 'nombre_comercial'],
                        'proveedor',
                    ]],
                ],
            ])
            ->assertJsonFragment(['id' => $collector, 'nombre' => 'Cobrador contrato UI', 'estado' => 'ACTIVO'])
            ->assertJsonFragment(['id' => $inactiveCollector, 'nombre' => 'Cobrador inactivo contrato UI', 'estado' => 'INACTIVO'])
            ->assertJsonFragment(['id' => $client, 'numero_documento' => '10444447', 'nombre' => 'Cliente contrato UI'])
            ->assertJsonFragment(['id' => $account, 'alias' => 'Cuenta contrato UI']);

        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '75.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '75.00']],
            ['referencia' => 'VOUCHER-CONTRATO-UI'],
        ))->assertCreated()->json('data.id');

        $this->getJson('/api/v1/finanzas/cobranzas?per_page=20&page=1&buscar=CONTRATO-UI')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'codigo',
                    'fecha_hora',
                    'referencia',
                    'moneda',
                    'importe_total',
                    'importe_aplicado_cxp',
                    'saldo_favor_proveedor',
                    'observaciones',
                    'estado',
                    'cobrador' => ['id', 'nombre', 'nombre_actual', 'estado'],
                    'destino' => ['id', 'alias', 'tipo', 'banco', 'numero_cuenta', 'cci', 'moneda', 'estado', 'entidad'],
                    'cuenta_destino',
                    'proveedor',
                    'detalle_count',
                    'detalles_count',
                    'creado_por' => ['id', 'nombre'],
                    'created_at',
                    'anulada_at',
                    'motivo_anulacion',
                    'anulacion',
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.id', $collectionId)
            ->assertJsonPath('data.0.referencia', 'VOUCHER-CONTRATO-UI')
            ->assertJsonPath('data.0.detalle_count', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/finanzas/cobranzas/{$collectionId}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'codigo',
                    'referencia',
                    'importe_total',
                    'cobrador' => ['id', 'nombre', 'nombre_actual', 'estado'],
                    'destino' => ['id', 'alias', 'entidad'],
                    'cuenta_destino',
                    'detalle_count',
                    'detalles_count',
                    'detalles' => [[
                        'id',
                        'fecha_recepcion',
                        'medio_recepcion',
                        'importe',
                        'cliente' => ['id', 'numero_documento', 'nombre'],
                        'movimiento_codigo',
                        'importe_aplicado_cxc',
                        'saldo_favor_cliente',
                        'pago' => ['id', 'codigo', 'tipo', 'fecha_hora', 'referencia', 'estado', 'idempotency_key', 'reversa'],
                        'aplicaciones' => ['CXC', 'CXP'],
                    ]],
                ],
            ])
            ->assertJsonPath('data.detalles.0.fecha_recepcion', '2026-07-30')
            ->assertJsonPath('data.detalles.0.medio_recepcion', 'EFECTIVO')
            ->assertJsonPath('data.detalles.0.importe_aplicado_cxc', '75.00')
            ->assertJsonPath('data.detalles.0.saldo_favor_cliente', '0.00')
            ->assertJsonPath('data.detalles.0.aplicaciones.CXC.0.comprobante_id', $document)
            ->assertJsonPath('data.detalles.0.aplicaciones.CXP', []);
    }

    public function test_collections_can_be_filtered_by_pending_or_complete_reconciliation(): void
    {
        $collector = $this->collector('Cobrador filtro conciliación');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco filtro conciliación');
        $client = $this->thirdParty('CLIENTE', 'Cliente filtro conciliación', '10444450');

        $pendingId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00']],
            ['referencia' => 'VOUCHER-FILTRO-PENDIENTE'],
        ))->assertCreated()->json('data.id');

        $completeId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '75.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-31', 'importe' => '75.00']],
            ['referencia' => 'VOUCHER-FILTRO-COMPLETA'],
        ))->assertCreated()->json('data.id');

        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=PENDIENTE&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pendingId)
            ->assertJsonPath('data.0.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.0.importe_pendiente', '60.00');

        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=COMPLETA&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $completeId)
            ->assertJsonPath('data.0.conciliacion', 'COMPLETA')
            ->assertJsonPath('data.0.importe_pendiente', '0.00')
            ->assertJsonPath('data.0.pendiente', null);

        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=DESCONOCIDA')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conciliacion');
    }

    public function test_pending_balance_can_be_assigned_partially_then_completely_and_voided_without_changing_the_deposit(): void
    {
        $collector = $this->collector('Cobrador reasignacion progresiva');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco reasignacion progresiva');
        $clientOne = $this->thirdParty('CLIENTE', 'Cliente inicial asignacion', '10444501');
        $clientTwo = $this->thirdParty('CLIENTE', 'Cliente parcial asignacion', '10444502');
        $clientThree = $this->thirdParty('CLIENTE', 'Cliente final asignacion', '10444503');
        $clientOneDebt = $this->document('VENTA', $clientOne, '200.00', 'CXC-ASIGNACION-INICIAL', '2026-06-01');
        $clientTwoDebt = $this->document('VENTA', $clientTwo, '150.00', 'CXC-ASIGNACION-PARCIAL', '2026-06-02');
        $clientThreeDebt = $this->document('VENTA', $clientThree, '150.00', 'CXC-ASIGNACION-FINAL', '2026-06-03');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '500.00',
            [['cliente_id' => $clientOne, 'fecha_recepcion' => '2026-07-29', 'importe' => '200.00']],
            ['referencia' => 'VOUCHER-ASIGNACION-PROGRESIVA'],
        ))->assertCreated()
            ->assertJsonPath('data.importe_pendiente', '300.00')
            ->json('data.id');

        $partial = $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [
                ['cliente_id' => $clientTwo, 'fecha_recepcion' => '2026-07-30', 'importe' => '100.00'],
            ]),
        )->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.importe_total', '500.00')
            ->assertJsonPath('data.importe_asignado', '300.00')
            ->assertJsonPath('data.importe_pendiente', '200.00')
            ->assertJsonPath('data.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.puede_asignar_pendiente', true)
            ->assertJsonPath('data.asignaciones_count', 1);
        $partialAssignmentId = (int) $partial->json('meta.asignacion_id');

        $this->assertDatabaseHas('cobranza_asignaciones', [
            'id' => $partialAssignmentId,
            'cobranza_id' => $collectionId,
            'importe_pendiente_antes' => 300,
            'importe_asignado' => 100,
            'importe_pendiente_despues' => 200,
            'created_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('cobranza_detalles', [
            'cobranza_id' => $collectionId,
            'asignacion_id' => $partialAssignmentId,
            'cliente_id' => $clientTwo,
            'importe' => 100,
            'orden' => 2,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $clientTwoDebt,
            'saldo_pendiente' => 50,
            'estado' => 'PARCIAL',
        ]);
        $this->assertSame('500.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);

        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=PENDIENTE&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $collectionId);
        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=COMPLETA&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $complete = $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [
                ['cliente_id' => $clientTwo, 'fecha_recepcion' => '2026-07-30', 'importe' => '50.00'],
                ['cliente_id' => $clientThree, 'fecha_recepcion' => '2026-07-31', 'importe' => '150.00'],
            ]),
        )->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.importe_total', '500.00')
            ->assertJsonPath('data.importe_asignado', '500.00')
            ->assertJsonPath('data.importe_pendiente', '0.00')
            ->assertJsonPath('data.conciliacion', 'COMPLETA')
            ->assertJsonPath('data.pendiente', null)
            ->assertJsonPath('data.puede_asignar_pendiente', false)
            ->assertJsonPath('data.asignaciones_count', 2)
            ->assertJsonCount(2, 'data.asignaciones');
        $completeAssignmentId = (int) $complete->json('meta.asignacion_id');

        $this->assertDatabaseHas('cobranza_asignaciones', [
            'id' => $completeAssignmentId,
            'cobranza_id' => $collectionId,
            'importe_pendiente_antes' => 200,
            'importe_asignado' => 200,
            'importe_pendiente_despues' => 0,
            'pago_pendiente_nuevo_id' => null,
        ]);
        $this->assertDatabaseCount('cobranza_asignaciones', 2);
        $this->assertDatabaseMissing('cobranza_pendientes', ['cobranza_id' => $collectionId]);
        $this->assertSame(4, DB::table('cobranza_detalles')->where('cobranza_id', $collectionId)->count());
        $this->assertSame('500.00', $this->money(DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->sum('importe')));
        $this->assertSame(3, DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->whereNotNull('asignacion_id')
            ->count());
        $this->assertSame('500.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
        foreach ([$clientOneDebt, $clientTwoDebt, $clientThreeDebt] as $documentId) {
            $this->assertDatabaseHas('comprobantes', [
                'id' => $documentId,
                'saldo_pendiente' => 0,
                'estado' => 'PAGADO',
            ]);
        }

        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=PENDIENTE&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=COMPLETA&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $collectionId);

        $detailPaymentIds = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->pluck('pago_id')
            ->map(fn ($id): int => (int) $id);
        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Anulacion posterior a identificar todo el voucher',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('data.puede_asignar_pendiente', false);

        $this->assertSame('0.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
        foreach ([
            $clientOneDebt => 200,
            $clientTwoDebt => 150,
            $clientThreeDebt => 150,
        ] as $documentId => $balance) {
            $this->assertDatabaseHas('comprobantes', [
                'id' => $documentId,
                'saldo_pendiente' => $balance,
                'estado' => 'PENDIENTE',
            ]);
        }
        $this->assertSame($detailPaymentIds->count(), DB::table('pagos')
            ->whereIn('id', $detailPaymentIds)
            ->where('estado', 'ANULADO')
            ->count());
        $this->assertSame($detailPaymentIds->count(), DB::table('pagos')
            ->whereIn('reversa_de_pago_id', $detailPaymentIds)
            ->count());
        $this->assertDatabaseCount('cobranza_asignaciones', 2);
    }

    public function test_reclassified_pending_movements_keep_the_collection_link_in_financial_trace(): void
    {
        $collector = $this->collector('Cobrador trazabilidad asignacion');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco trazabilidad asignacion');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente inicial trazabilidad', '10444513');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente asignado trazabilidad', '10444514');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-29', 'importe' => '10.00']],
            ['referencia' => 'VOUCHER-TRAZABILIDAD-ASIGNACION'],
        ))->assertCreated()->json('data.id');
        $firstPendingId = (int) DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');

        $firstAssignmentId = (int) $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [[
                'cliente_id' => $assignedClient,
                'fecha_recepcion' => '2026-07-30',
                'importe' => '20.00',
            ]]),
        )->assertCreated()->json('meta.asignacion_id');
        $firstAssignment = DB::table('cobranza_asignaciones')->find($firstAssignmentId);

        $secondAssignmentId = (int) $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [[
                'cliente_id' => $assignedClient,
                'fecha_recepcion' => '2026-07-31',
                'importe' => '30.00',
            ]]),
        )->assertCreated()->json('meta.asignacion_id');
        $secondAssignment = DB::table('cobranza_asignaciones')->find($secondAssignmentId);

        $linkedPaymentIds = collect([
            $firstPendingId,
            $firstAssignment->pago_reversa_id,
            $firstAssignment->pago_pendiente_nuevo_id,
            $secondAssignment->pago_reversa_id,
            $secondAssignment->pago_pendiente_nuevo_id,
        ])->map(fn ($id): int => (int) $id)->unique()->values();

        foreach ($linkedPaymentIds as $paymentId) {
            $this->getJson("/api/v1/finanzas/movimientos/{$paymentId}")
                ->assertOk()
                ->assertJsonPath('data.cobranza.id', $collectionId)
                ->assertJsonPath('data.cobranza.referencia', 'VOUCHER-TRAZABILIDAD-ASIGNACION');
        }

        $trace = collect($this->getJson('/api/v1/finanzas/trazabilidad?per_page=100')
            ->assertOk()
            ->json('data'));
        foreach ($linkedPaymentIds as $paymentId) {
            $matches = $trace->where('id', $paymentId);
            $this->assertCount(1, $matches, "El movimiento {$paymentId} debe aparecer una sola vez.");
            $this->assertSame($collectionId, data_get($matches->first(), 'cobranza.id'));
        }
    }

    public function test_pending_assignment_is_a_reclassification_even_when_the_own_account_money_was_spent(): void
    {
        $collector = $this->collector('Cobrador saldo gastado');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco saldo gastado');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente antes del gasto', '10444504');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente despues del gasto', '10444505');
        $this->document('VENTA', $initialClient, '200.00', 'CXC-ANTES-GASTO', '2026-06-01');
        $assignedDebt = $this->document('VENTA', $assignedClient, '100.00', 'CXC-DESPUES-GASTO', '2026-06-02');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '500.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '200.00']],
            ['referencia' => 'VOUCHER-SALDO-GASTADO'],
        ))->assertCreated()->json('data.id');
        $this->accountOutflow($account, '450.00', 'SALIDA-DESPUES-DEL-VOUCHER');
        $this->assertSame('50.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);

        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [
                ['cliente_id' => $assignedClient, 'fecha_recepcion' => '2026-07-31', 'importe' => '100.00'],
            ]),
        )->assertCreated()
            ->assertJsonPath('data.importe_asignado', '300.00')
            ->assertJsonPath('data.importe_pendiente', '200.00');

        $this->assertSame('50.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $assignedDebt,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);
    }

    public function test_provider_assignment_preserves_each_payable_application_and_provider_credit(): void
    {
        $collector = $this->collector('Cobrador reasignacion proveedor');
        $provider = $this->thirdParty('PROVEEDOR', 'Proveedor reasignacion', '20444444501');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'Cuenta proveedor reasignacion');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente inicial proveedor', '10444506');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente asignado proveedor', '10444507');
        $this->document('VENTA', $initialClient, '200.00', 'CXC-INICIAL-PROVEEDOR-ASIGNACION', '2026-06-01');
        $assignedDebt = $this->document('VENTA', $assignedClient, '100.00', 'CXC-NUEVA-PROVEEDOR-ASIGNACION', '2026-06-02');
        $initialPayable = $this->document('COMPRA', $provider, '450.00', 'CXP-INICIAL-PROVEEDOR-ASIGNACION', '2026-05-01');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $providerAccount,
            '500.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '200.00']],
            ['referencia' => 'VOUCHER-ASIGNACION-PROVEEDOR'],
        ))->assertCreated()
            ->assertJsonPath('data.importe_aplicado_cxp', '450.00')
            ->assertJsonPath('data.saldo_favor_proveedor', '50.00')
            ->json('data.id');
        $pendingPaymentId = (int) DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');

        $laterPayable = $this->document('COMPRA', $provider, '40.00', 'CXP-POSTERIOR-PROVEEDOR-ASIGNACION', '2026-08-02');
        $this->postJson("/api/v1/finanzas/movimientos/{$pendingPaymentId}/aplicaciones", [
            'idempotency_key' => (string) Str::uuid(),
            'aplicaciones' => [[
                'comprobante_id' => $laterPayable,
                'importe_aplicado' => '40.00',
            ]],
            'observaciones' => 'Aplicacion posterior antes de identificar al cliente.',
        ])->assertCreated()
            ->assertJsonPath('data.aplicacion.importe_aplicado', '290.00')
            ->assertJsonPath('data.aplicacion.importe_sin_aplicar', '10.00');

        $this->assertSame('450.00', $this->collectionApplicationAmount($collectionId, $initialPayable, 'CXP'));
        $this->assertSame('40.00', $this->collectionApplicationAmount($collectionId, $laterPayable, 'CXP'));

        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [
                ['cliente_id' => $assignedClient, 'fecha_recepcion' => '2026-07-31', 'importe' => '100.00'],
            ]),
        )->assertCreated()
            ->assertJsonPath('data.importe_asignado', '300.00')
            ->assertJsonPath('data.importe_pendiente', '200.00')
            ->assertJsonPath('data.importe_aplicado_cxp', '490.00')
            ->assertJsonPath('data.saldo_favor_proveedor', '10.00');

        $this->assertSame('450.00', $this->collectionApplicationAmount($collectionId, $initialPayable, 'CXP'));
        $this->assertSame('40.00', $this->collectionApplicationAmount($collectionId, $laterPayable, 'CXP'));
        $this->assertDatabaseHas('comprobantes', ['id' => $initialPayable, 'saldo_pendiente' => 0, 'estado' => 'PAGADO']);
        $this->assertDatabaseHas('comprobantes', ['id' => $laterPayable, 'saldo_pendiente' => 0, 'estado' => 'PAGADO']);
        $this->assertDatabaseHas('comprobantes', ['id' => $assignedDebt, 'saldo_pendiente' => 0, 'estado' => 'PAGADO']);
        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.payments', '500.00')
            ->assertJsonPath('data.applied', '490.00')
            ->assertJsonPath('data.unapplied', '10.00')
            ->assertJsonPath('data.paid_directly_by_clients', '300.00');
    }

    public function test_assignment_greater_than_the_pending_balance_is_rejected_atomically(): void
    {
        $collector = $this->collector('Cobrador exceso asignacion');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco exceso asignacion');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente inicial exceso', '10444508');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente exceso', '10444509');
        $this->document('VENTA', $initialClient, '100.00', 'CXC-INICIAL-EXCESO-ASIGNACION', '2026-06-01');
        $assignedDebt = $this->document('VENTA', $assignedClient, '110.00', 'CXC-EXCESO-ASIGNACION', '2026-06-02');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '200.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '100.00']],
            ['referencia' => 'VOUCHER-EXCESO-ASIGNACION'],
        ))->assertCreated()->json('data.id');

        $countsBefore = [
            'detalles' => DB::table('cobranza_detalles')->count(),
            'pagos' => DB::table('pagos')->count(),
            'aplicaciones' => DB::table('pago_aplicaciones')->count(),
            'auditorias' => DB::table('auditoria_eventos')->count(),
        ];
        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [
                ['cliente_id' => $assignedClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '60.00'],
                ['cliente_id' => $assignedClient, 'fecha_recepcion' => '2026-07-31', 'importe' => '50.00'],
            ]),
        )->assertUnprocessable();

        $this->assertDatabaseCount('cobranza_asignaciones', 0);
        $this->assertSame($countsBefore['detalles'], DB::table('cobranza_detalles')->count());
        $this->assertSame($countsBefore['pagos'], DB::table('pagos')->count());
        $this->assertSame($countsBefore['aplicaciones'], DB::table('pago_aplicaciones')->count());
        $this->assertSame($countsBefore['auditorias'], DB::table('auditoria_eventos')->count());
        $this->assertDatabaseHas('cobranza_pendientes', [
            'cobranza_id' => $collectionId,
            'importe' => 100,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $assignedDebt,
            'saldo_pendiente' => 110,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertSame('200.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
    }

    public function test_pending_assignment_is_strictly_idempotent(): void
    {
        $collector = $this->collector('Cobrador idempotencia asignacion');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco idempotencia asignacion');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente inicial idempotencia asignacion', '10444510');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente idempotencia asignacion', '10444511');
        $this->document('VENTA', $assignedClient, '100.00', 'CXC-IDEMPOTENCIA-ASIGNACION', '2026-06-01');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '10.00']],
            ['referencia' => 'VOUCHER-IDEMPOTENCIA-ASIGNACION'],
        ))->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $payload = $this->assignmentPayload($key, [
            ['cliente_id' => $assignedClient, 'fecha_recepcion' => '2026-07-31', 'importe' => '40.00'],
        ]);

        $assignmentId = (int) $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $payload,
        )->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.importe_pendiente', '50.00')
            ->json('meta.asignacion_id');
        $countsAfterFirst = [
            'detalles' => DB::table('cobranza_detalles')->count(),
            'pagos' => DB::table('pagos')->count(),
            'aplicaciones' => DB::table('pago_aplicaciones')->count(),
        ];

        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones", $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('meta.asignacion_id', $assignmentId)
            ->assertJsonPath('data.importe_pendiente', '50.00');
        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones", [
            ...$payload,
            'detalles' => [[
                'cliente_id' => $assignedClient,
                'fecha_recepcion' => '2026-07-31',
                'importe' => '50.00',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('cobranza_asignaciones', 1);
        $this->assertSame($countsAfterFirst['detalles'], DB::table('cobranza_detalles')->count());
        $this->assertSame($countsAfterFirst['pagos'], DB::table('pagos')->count());
        $this->assertSame($countsAfterFirst['aplicaciones'], DB::table('pago_aplicaciones')->count());
        $this->assertSame('100.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
    }

    public function test_only_an_active_pending_collection_in_the_same_company_can_be_assigned_with_permission(): void
    {
        $collector = $this->collector('Cobrador estados asignacion');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco estados asignacion');
        $client = $this->thirdParty('CLIENTE', 'Cliente estados asignacion', '10444512');
        $detail = [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00']];

        $voidedId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            $detail,
            ['referencia' => 'VOUCHER-ANULADO-ASIGNACION'],
        ))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finanzas/cobranzas/{$voidedId}/anular", [
            'motivo' => 'Cobranza anulada antes de asignar',
        ])->assertOk();
        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$voidedId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), $detail),
        )->assertUnprocessable();

        $completeId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '40.00',
            $detail,
            ['referencia' => 'VOUCHER-COMPLETO-ASIGNACION'],
        ))->assertCreated()->json('data.id');
        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$completeId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), $detail),
        )->assertUnprocessable();

        $activeId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            $detail,
            ['referencia' => 'VOUCHER-ACTIVO-ASIGNACION'],
        ))->assertCreated()->json('data.id');
        $payload = $this->assignmentPayload((string) Str::uuid(), $detail);

        $foreign = User::factory()->create();
        $this->grantModules($foreign, ['MODULO_FINANZAS'], 'COBRANZAS_ASIGNACION_FORANEA', 'Asignacion foranea');
        Sanctum::actingAs($foreign, ['api']);
        $this->postJson("/api/v1/finanzas/cobranzas/{$activeId}/asignaciones", $payload)
            ->assertNotFound();

        $restricted = User::factory()->create(['empresa_id' => $this->user->empresa_id]);
        $this->grantModules($restricted, ['MODULO_FINANZAS'], 'COBRANZAS_ASIGNACION_RESTRINGIDA', 'Asignacion restringida');
        $permissionPath = 'access_modules.modules.MODULO_FINANZAS.technical_permissions';
        $technicalPermissions = config($permissionPath, []);
        try {
            config()->set($permissionPath, array_values(array_diff($technicalPermissions, ['PAGOS_REGISTRAR'])));
            Sanctum::actingAs($restricted, ['api']);
            $this->postJson("/api/v1/finanzas/cobranzas/{$activeId}/asignaciones", $payload)
                ->assertForbidden();
        } finally {
            config()->set($permissionPath, $technicalPermissions);
        }

        $this->assertDatabaseCount('cobranza_asignaciones', 0);
        $this->assertDatabaseHas('cobranza_pendientes', [
            'cobranza_id' => $activeId,
            'importe' => 60,
        ]);
    }

    public function test_inactive_historical_context_disables_pending_assignment_and_post_rejects_it(): void
    {
        $collector = $this->collector('Cobrador contexto inactivo');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco contexto inactivo');
        $initialClient = $this->thirdParty('CLIENTE', 'Cliente inicial contexto inactivo', '10444515');
        $assignedClient = $this->thirdParty('CLIENTE', 'Cliente asignado contexto inactivo', '10444516');

        $collectionId = (int) $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $initialClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '20.00']],
            ['referencia' => 'VOUCHER-CONTEXTO-INACTIVO'],
        ))->assertCreated()
            ->assertJsonPath('data.puede_asignar_pendiente', true)
            ->json('data.id');

        $this->accountOutflow($account, '100.00', 'SALIDA-CONTEXTO-INACTIVO');
        $this->deleteJson("/api/v1/finanzas/cuentas/{$account}")->assertOk();

        $this->getJson("/api/v1/finanzas/cobranzas/{$collectionId}")
            ->assertOk()
            ->assertJsonPath('data.importe_pendiente', '80.00')
            ->assertJsonPath('data.conciliacion', 'PENDIENTE')
            ->assertJsonPath('data.puede_asignar_pendiente', false);
        $this->getJson('/api/v1/finanzas/cobranzas?conciliacion=PENDIENTE&per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.puede_asignar_pendiente', false);

        $this->postJson(
            "/api/v1/finanzas/cobranzas/{$collectionId}/asignaciones",
            $this->assignmentPayload((string) Str::uuid(), [[
                'cliente_id' => $assignedClient,
                'fecha_recepcion' => '2026-07-31',
                'importe' => '10.00',
            ]]),
        )->assertUnprocessable()
            ->assertJsonValidationErrors('cobranza');

        $this->assertDatabaseCount('cobranza_asignaciones', 0);
        $this->assertDatabaseHas('cobranza_pendientes', [
            'cobranza_id' => $collectionId,
            'importe' => 80,
        ]);
    }

    public function test_a_breakdown_greater_than_the_voucher_total_is_rejected_atomically(): void
    {
        $collector = $this->collector('Cobrador desglose excedido');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco desglose excedido');
        $client = $this->thirdParty('CLIENTE', 'Cliente desglose excedido', '10444451');
        $receivable = $this->document('VENTA', $client, '150.00', 'CXC-DESGLOSE-EXCEDIDO', '2026-07-01');

        $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '100.01']],
            ['referencia' => 'VOUCHER-DESGLOSE-EXCEDIDO'],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('importe_total');

        $this->assertDatabaseCount('cobranzas', 0);
        $this->assertDatabaseCount('cobranza_detalles', 0);
        $this->assertDatabaseCount('cobranza_pendientes', 0);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $receivable,
            'saldo_pendiente' => 150,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertSame('0.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
    }

    public function test_the_voucher_total_must_equal_the_detail_sum_and_failures_are_atomic(): void
    {
        $collector = $this->collector('Elena Control');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco control');
        $client = $this->thirdParty('CLIENTE', 'Cliente Valido', '10555555');
        $inactiveClient = $this->thirdParty('CLIENTE', 'Cliente Inactivo', '10666666', $this->user, 'INACTIVO');
        $document = $this->document('VENTA', $client, '80.00', 'CXC-ATOMICIDAD', '2026-07-01');

        $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [
                ['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00'],
                ['cliente_id' => $inactiveClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '59.99'],
            ],
        ))->assertUnprocessable();

        $this->assertDatabaseCount('cobranzas', 0);
        $this->assertDatabaseCount('cobranza_detalles', 0);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document,
            'saldo_pendiente' => 80,
            'estado' => 'PENDIENTE',
        ]);

        // With an exact total, failure in the second detail must also roll back the first child payment.
        $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [
                ['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00'],
                ['cliente_id' => $inactiveClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '60.00'],
            ],
        ))->assertUnprocessable();

        $this->assertDatabaseCount('cobranzas', 0);
        $this->assertDatabaseCount('cobranza_detalles', 0);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document,
            'saldo_pendiente' => 80,
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_collection_registration_is_strictly_idempotent(): void
    {
        $collector = $this->collector('Fabian Reintentos');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco idempotencia');
        $client = $this->thirdParty('CLIENTE', 'Cliente Idempotencia', '10777777');
        $document = $this->document('VENTA', $client, '50.00', 'CXC-IDEMPOTENCIA-COBRANZA', '2026-07-01');
        $key = (string) Str::uuid();
        $payload = $this->collectionPayload(
            $key,
            $collector,
            $account,
            '50.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '50.00']],
            ['referencia' => 'VOUCHER-IDEMPOTENTE'],
        );

        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->json('data.id');

        $this->postJson('/api/v1/finanzas/cobranzas', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $collectionId)
            ->assertJsonPath('meta.idempotent', true);

        $this->postJson('/api/v1/finanzas/cobranzas', [
            ...$payload,
            'referencia' => 'VOUCHER-DIFERENTE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('cobranzas', 1);
        $this->assertDatabaseCount('cobranza_detalles', 1);
        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseCount('pago_aplicaciones', 1);
        $this->assertDatabaseHas('comprobantes', ['id' => $document, 'saldo_pendiente' => 0]);
        $this->assertSame('50.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
    }

    public function test_foreign_or_inactive_collectors_accounts_and_clients_are_rejected_without_side_effects(): void
    {
        $collector = $this->collector('Gabriel Empresa');
        $inactiveCollector = $this->collector('Gabriel Inactivo', $this->user, 'INACTIVO');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco empresa');
        [, $inactiveAccount] = $this->financialAccount('PROPIA', null, 'Banco inactivo', $this->user, 'INACTIVO');
        $client = $this->thirdParty('CLIENTE', 'Cliente Empresa', '10888881');
        $inactiveClient = $this->thirdParty('CLIENTE', 'Cliente Empresa Inactivo', '10888882', $this->user, 'INACTIVO');

        $foreignUser = User::factory()->create();
        $foreignCollector = $this->collector('Cobrador Foraneo', $foreignUser);
        [, $foreignAccount] = $this->financialAccount('PROPIA', null, 'Banco foraneo', $foreignUser);
        $foreignClient = $this->thirdParty('CLIENTE', 'Cliente Foraneo', '10888883', $foreignUser);

        $cases = [
            ['cobrador_id' => $inactiveCollector],
            ['cobrador_id' => $foreignCollector],
            ['cuenta_destino_id' => $inactiveAccount],
            ['cuenta_destino_id' => $foreignAccount],
            ['detalles' => [['cliente_id' => $inactiveClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '10.00']]],
            ['detalles' => [['cliente_id' => $foreignClient, 'fecha_recepcion' => '2026-07-30', 'importe' => '10.00']]],
        ];

        foreach ($cases as $overrides) {
            $this->postJson('/api/v1/finanzas/cobranzas', [
                ...$this->collectionPayload(
                    (string) Str::uuid(),
                    $collector,
                    $account,
                    '10.00',
                    [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '10.00']],
                ),
                ...$overrides,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('cobranzas', 0);
        $this->assertDatabaseCount('cobranza_detalles', 0);
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_company_isolation_and_payment_permissions_protect_collection_mutations(): void
    {
        $collector = $this->collector('Helena Permisos');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco permisos');
        $client = $this->thirdParty('CLIENTE', 'Cliente Permisos', '10999991');
        $payload = $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '20.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '20.00']],
        );
        $restricted = User::factory()->create(['empresa_id' => $this->user->empresa_id]);
        $this->grantModules($restricted, ['MODULO_FINANZAS'], 'COBRANZAS_RESTRINGIDO', 'Cobranzas restringido');
        $permissionPath = 'access_modules.modules.MODULO_FINANZAS.technical_permissions';
        $technicalPermissions = config($permissionPath, []);

        try {
            config()->set($permissionPath, array_values(array_diff($technicalPermissions, ['PAGOS_REGISTRAR'])));
            Sanctum::actingAs($restricted, ['api']);

            $this->postJson('/api/v1/finanzas/cobradores', ['nombre' => 'No autorizado'])
                ->assertForbidden();
            $this->postJson('/api/v1/finanzas/cobranzas', $payload)
                ->assertForbidden();
        } finally {
            config()->set($permissionPath, $technicalPermissions);
        }

        Sanctum::actingAs($this->user, ['api']);
        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $payload)
            ->assertCreated()
            ->json('data.id');

        try {
            config()->set($permissionPath, array_values(array_diff($technicalPermissions, ['SALDOS_AJUSTAR'])));
            Sanctum::actingAs($restricted, ['api']);

            $this->putJson("/api/v1/finanzas/cobranzas/{$collectionId}/recepcion-caja", [
                'recibido' => true,
                'estado_esperado' => false,
            ])->assertForbidden();
        } finally {
            config()->set($permissionPath, $technicalPermissions);
        }

        try {
            config()->set($permissionPath, array_values(array_diff($technicalPermissions, ['PAGOS_ANULAR'])));
            Sanctum::actingAs($restricted, ['api']);

            $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
                'motivo' => 'Intento sin permiso de anulacion',
            ])->assertForbidden();
        } finally {
            config()->set($permissionPath, $technicalPermissions);
        }

        $foreignUser = User::factory()->create();
        $this->grantModules($foreignUser, ['MODULO_FINANZAS'], 'COBRANZAS_FORANEO', 'Cobranzas foraneo');
        Sanctum::actingAs($foreignUser, ['api']);
        $this->getJson("/api/v1/finanzas/cobranzas/{$collectionId}")->assertNotFound();
        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Intento desde otra empresa',
        ])->assertNotFound();
        $this->putJson("/api/v1/finanzas/cobranzas/{$collectionId}/recepcion-caja", [
            'recibido' => true,
            'estado_esperado' => false,
        ])->assertNotFound();

        $inactive = User::factory()->create([
            'empresa_id' => $this->user->empresa_id,
            'estado' => User::STATUS_INACTIVE,
        ]);
        $this->grantModules($inactive, ['MODULO_FINANZAS'], 'COBRANZAS_INACTIVO', 'Cobranzas inactivo');
        Sanctum::actingAs($inactive, ['api']);
        $this->getJson('/api/v1/finanzas/cobranzas')->assertForbidden();

        $this->assertDatabaseHas('cobranzas', ['id' => $collectionId, 'estado' => 'REGISTRADO']);
        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseMissing('pagos', [
            'reversa_de_pago_id' => DB::table('cobranza_detalles')
                ->where('cobranza_id', $collectionId)
                ->value('pago_id'),
        ]);
    }

    public function test_child_payments_cannot_be_edited_or_voided_outside_the_collection(): void
    {
        $collector = $this->collector('Ines Proteccion');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco protegido');
        $client = $this->thirdParty('CLIENTE', 'Cliente Protegido', '10999992');
        $document = $this->document('VENTA', $client, '30.00', 'CXC-PAGO-PROTEGIDO', '2026-07-01');
        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '30.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '30.00']],
        ))->assertCreated()->json('data.id');
        $paymentId = (int) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');

        $this->putJson("/api/v1/finanzas/movimientos/{$paymentId}", [
            'fecha_hora' => '2026-07-31 12:00:00',
            'referencia' => 'REFERENCIA ALTERADA',
            'observaciones' => 'Intento de edicion aislada.',
        ])->assertUnprocessable();

        $this->postJson("/api/v1/finanzas/movimientos/{$paymentId}/anular", [
            'motivo' => 'Intento de anulacion aislada',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('cobranzas', ['id' => $collectionId, 'estado' => 'REGISTRADO']);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'REGISTRADO',
            'referencia' => 'VOUCHER-COBRANZA-001',
        ]);
        $this->assertDatabaseMissing('pagos', ['reversa_de_pago_id' => $paymentId]);
        $this->assertDatabaseHas('comprobantes', ['id' => $document, 'saldo_pendiente' => 0]);
    }

    public function test_the_pending_payment_is_protected_and_is_reversed_with_the_complete_collection(): void
    {
        $collector = $this->collector('Cobrador pendiente protegido');
        [, $account] = $this->financialAccount('PROPIA', null, 'Banco pendiente protegido');
        $client = $this->thirdParty('CLIENTE', 'Cliente pendiente protegido', '10999995');
        $receivable = $this->document('VENTA', $client, '40.00', 'CXC-PENDIENTE-PROTEGIDO', '2026-07-01');

        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $account,
            '100.00',
            [['cliente_id' => $client, 'fecha_recepcion' => '2026-07-30', 'importe' => '40.00']],
            ['referencia' => 'VOUCHER-PENDIENTE-PROTEGIDO'],
        ))->assertCreated()->json('data.id');
        $detailPaymentId = (int) DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');
        $pendingPaymentId = (int) DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');

        $this->putJson("/api/v1/finanzas/movimientos/{$pendingPaymentId}", [
            'fecha_hora' => '2026-07-31 12:00:00',
            'referencia' => 'REFERENCIA PENDIENTE ALTERADA',
            'observaciones' => 'Intento de edición aislada del pendiente.',
        ])->assertUnprocessable();
        $this->postJson("/api/v1/finanzas/movimientos/{$pendingPaymentId}/anular", [
            'motivo' => 'Intento de anulación aislada del pendiente',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('pagos', [
            'id' => $pendingPaymentId,
            'estado' => 'REGISTRADO',
            'referencia' => 'VOUCHER-PENDIENTE-PROTEGIDO',
        ]);
        $this->assertSame('100.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);
        $this->assertDatabaseHas('comprobantes', ['id' => $receivable, 'saldo_pendiente' => 0]);

        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Voucher con excedente rechazado',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('meta.idempotent', false);

        foreach ([$detailPaymentId, $pendingPaymentId] as $paymentId) {
            $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'estado' => 'ANULADO']);
            $this->assertDatabaseHas('pagos', [
                'reversa_de_pago_id' => $paymentId,
                'estado' => 'REGISTRADO',
            ]);
        }
        $this->assertSame(2, DB::table('pagos')
            ->whereIn('reversa_de_pago_id', [$detailPaymentId, $pendingPaymentId])
            ->count());
        $this->assertDatabaseCount('pagos', 4);
        $this->assertDatabaseHas('cobranza_pendientes', [
            'cobranza_id' => $collectionId,
            'pago_id' => $pendingPaymentId,
            'importe' => 60,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $receivable,
            'saldo_pendiente' => 40,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertSame('0.00', app(FinancialAccountBalanceService::class)->forAccount($account)['saldo']);

        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Reintento de anulación con pendiente',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true);
        $this->assertDatabaseCount('pagos', 4);
    }

    public function test_voiding_a_collection_reverses_every_child_and_restores_customer_and_provider_debts_atomically(): void
    {
        $collector = $this->collector('Julia Reversa');
        $provider = $this->thirdParty('PROVEEDOR', 'Proveedor Reversa', '20999999993');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'Cuenta proveedor reversa');
        $clientOne = $this->thirdParty('CLIENTE', 'Cliente Reversa Uno', '10999993');
        $clientTwo = $this->thirdParty('CLIENTE', 'Cliente Reversa Dos', '10999994');
        $clientOneDebt = $this->document('VENTA', $clientOne, '40.00', 'CXC-REVERSA-UNO', '2026-07-01');
        $clientTwoDebt = $this->document('VENTA', $clientTwo, '60.00', 'CXC-REVERSA-DOS', '2026-07-02');
        $providerDebt = $this->document('COMPRA', $provider, '100.00', 'CXP-REVERSA-LOTE', '2026-06-01');
        $collectionId = $this->postJson('/api/v1/finanzas/cobranzas', $this->collectionPayload(
            (string) Str::uuid(),
            $collector,
            $providerAccount,
            '100.00',
            [
                ['cliente_id' => $clientOne, 'fecha_recepcion' => '2026-07-29', 'importe' => '40.00'],
                ['cliente_id' => $clientTwo, 'fecha_recepcion' => '2026-07-30', 'importe' => '60.00'],
            ],
            ['referencia' => 'VOUCHER-REVERSA-001'],
        ))->assertCreated()->json('data.id');
        $paymentIds = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->pluck('pago_id')
            ->map(fn ($id): int => (int) $id);

        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Voucher bancario rechazado',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('meta.idempotent', false);

        $this->assertDatabaseHas('cobranzas', [
            'id' => $collectionId,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
            'motivo_anulacion' => 'Voucher bancario rechazado',
        ]);
        foreach ($paymentIds as $paymentId) {
            $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'estado' => 'ANULADO']);
            $this->assertDatabaseHas('pagos', [
                'reversa_de_pago_id' => $paymentId,
                'estado' => 'REGISTRADO',
            ]);
        }
        $this->assertSame(2, DB::table('pagos')->whereIn('reversa_de_pago_id', $paymentIds)->count());
        $this->assertDatabaseCount('pagos', 4);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientOneDebt, 'saldo_pendiente' => 40, 'estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('comprobantes', ['id' => $clientTwoDebt, 'saldo_pendiente' => 60, 'estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('comprobantes', ['id' => $providerDebt, 'saldo_pendiente' => 100, 'estado' => 'PENDIENTE']);

        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.paid_directly_by_clients', '0.00')
            ->assertJsonCount(0, 'data.recent_direct_deposits');

        $this->postJson("/api/v1/finanzas/cobranzas/{$collectionId}/anular", [
            'motivo' => 'Reintento de la misma anulacion',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true);
        $this->assertDatabaseCount('pagos', 4);
        $this->assertSame(2, DB::table('pagos')->whereIn('reversa_de_pago_id', $paymentIds)->count());
    }

    /**
     * @param  list<array{cliente_id: int, fecha_recepcion: string, importe: string}>  $details
     * @return array<string, mixed>
     */
    private function assignmentPayload(string $idempotencyKey, array $details): array
    {
        return [
            'idempotency_key' => $idempotencyKey,
            'detalles' => $details,
        ];
    }

    private function accountOutflow(int $accountId, string $amount, string $reference): int
    {
        $now = now();

        return DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PAG-'.Str::upper(Str::random(12)),
            'tipo' => 'AJUSTE',
            'cuenta_origen_id' => $accountId,
            'direccion' => 'SALIDA',
            'fecha_hora' => $now,
            'metodo' => 'AJUSTE',
            'referencia' => $reference,
            'moneda' => 'PEN',
            'importe' => $amount,
            'estado' => 'REGISTRADO',
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $this->user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function collectionApplicationAmount(int $collectionId, int $documentId, string $side): string
    {
        $paymentIds = DB::table('cobranza_detalles')
            ->where('cobranza_id', $collectionId)
            ->pluck('pago_id');
        $pendingPaymentId = DB::table('cobranza_pendientes')
            ->where('cobranza_id', $collectionId)
            ->value('pago_id');
        if ($pendingPaymentId !== null) {
            $paymentIds->push($pendingPaymentId);
        }

        return $this->money(DB::table('pago_aplicaciones')
            ->whereIn('pago_id', $paymentIds->all())
            ->where('comprobante_id', $documentId)
            ->where('lado', $side)
            ->sum('importe_aplicado'));
    }

    /**
     * @param  list<array{cliente_id: int, fecha_recepcion: string, importe: string}>  $details
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function collectionPayload(
        string $idempotencyKey,
        int $collectorId,
        int $destinationAccountId,
        string $total,
        array $details,
        array $overrides = [],
    ): array {
        return [
            'idempotency_key' => $idempotencyKey,
            'cobrador_id' => $collectorId,
            'fecha_hora' => '2026-08-01 10:30:00',
            'cuenta_destino_id' => $destinationAccountId,
            'moneda' => 'PEN',
            'importe_total' => $total,
            'referencia' => 'VOUCHER-COBRANZA-001',
            'observaciones' => 'Deposito consolidado de clientes.',
            'detalles' => $details,
            ...$overrides,
        ];
    }

    private function collector(
        string $name,
        ?User $owner = null,
        string $status = 'ACTIVO',
    ): int {
        $owner ??= $this->user;

        return DB::table('cobradores')->insertGetId([
            'empresa_id' => $owner->empresa_id,
            'nombre' => $name,
            'estado' => $status,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{int, int} */
    private function financialAccount(
        string $entityType,
        ?int $provider,
        string $alias,
        ?User $owner = null,
        string $accountStatus = 'ACTIVO',
    ): array {
        $owner ??= $this->user;
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $owner->empresa_id,
            'tipo' => $entityType,
            'proveedor_id' => $provider,
            'razon_social' => $entityType === 'PROPIA' ? 'Empresa propia' : 'Empresa del proveedor',
            'estado' => 'ACTIVO',
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => 'BANCO',
            'alias' => $alias,
            'banco' => 'Banco de prueba',
            'numero_cuenta' => Str::upper(Str::random(12)),
            'moneda' => 'PEN',
            'estado' => $accountStatus,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$entityId, $accountId];
    }

    private function thirdParty(
        string $role,
        string $name,
        string $document,
        ?User $owner = null,
        string $status = 'ACTIVO',
    ): int {
        $owner ??= $this->user;
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $owner->empresa_id,
            'tipo_documento' => strlen($document) === 11 ? 'RUC' : 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'SIN DIRECCION',
            'estado' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $id,
            'rol' => $role === 'CLIENTE' ? TerceroRole::CLIENT : TerceroRole::PROVIDER,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function document(
        string $operation,
        int $thirdParty,
        string $amount,
        string $originKey,
        string $issueDate,
    ): int {
        return DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $thirdParty,
            'operacion' => $operation,
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'INTERNO',
            'codigo' => 'DOC-'.Str::upper(Str::random(10)),
            'origen_codigo' => 'TEST',
            'origen_clave' => $originKey,
            'fecha_emision' => $issueDate,
            'fecha_vencimiento' => $issueDate,
            'moneda' => 'PEN',
            'subtotal' => $amount,
            'impuesto' => '0.00',
            'total' => $amount,
            'saldo_pendiente' => $amount,
            'estado' => 'PENDIENTE',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function money(mixed $value): string
    {
        return FinancialMoney::normalize((string) $value);
    }
}
