<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchAccountStatementTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    private int $kgProductId;

    private int $kgVariationId;

    private int $unitProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->empresa()->update([
            'moneda' => 'PEN',
            'zona_horaria' => 'America/Lima',
        ]);
        $this->branchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'ESTADO-CUENTA',
            'Sucursal estado de cuenta',
            'America/Lima',
        );
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, ['MODULO_DESPACHO_PRODUCTOS']);
        Sanctum::actingAs($this->user, ['api']);

        $this->clientId = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Estado Ágil',
            '20123456789',
        );
        $this->kgProductId = $this->createProduct(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            'Pavo',
            ProductoDespacho::PRICE_MODE_KG,
            '12.0000',
        );
        $this->kgVariationId = $this->createVariation(
            $this->kgProductId,
            (int) $this->user->id,
            'Pavo grande',
            ProductoDespacho::PRICE_MODE_KG,
            '12.0000',
        );
        $this->unitProductId = $this->createProduct(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            'Huevo',
            ProductoDespacho::PRICE_MODE_UNIT,
            '1.6100',
        );
    }

    public function test_statement_rebuilds_opening_sales_allocated_payments_and_detailed_balances(): void
    {
        $opening = $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-20260625-001',
            '2026-06-25',
            'PEN',
            [[
                'product_id' => $this->kgProductId,
                'product' => 'Pavo',
                'variation_id' => null,
                'variation' => null,
                'quantity' => 1,
                'net_weight_kg' => '10.000',
                'price_mode' => ProductoDespacho::PRICE_MODE_KG,
                'price' => '10.0000',
                'subtotal' => '100.00',
            ]],
        );
        $openingPayment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-ANTERIOR',
            '2026-06-28 11:00:00',
            '20.00',
        );
        $this->applyPayment($openingPayment, $opening['document_id'], '20.00');

        $period = $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-20260707-002',
            '2026-07-07',
            'PEN',
            [
                [
                    'product_id' => $this->kgProductId,
                    'product' => 'Pavo',
                    'variation_id' => $this->kgVariationId,
                    'variation' => 'Pavo grande',
                    'quantity' => 2,
                    'net_weight_kg' => '6.500',
                    'price_mode' => ProductoDespacho::PRICE_MODE_KG,
                    'price' => '12.0000',
                    'subtotal' => '78.00',
                ],
                [
                    'product_id' => $this->unitProductId,
                    'product' => 'Huevo',
                    'variation_id' => null,
                    'variation' => null,
                    'quantity' => 10,
                    'net_weight_kg' => '1.150',
                    'price_mode' => ProductoDespacho::PRICE_MODE_UNIT,
                    'price' => '1.6100',
                    'subtotal' => '16.10',
                ],
            ],
        );
        $periodPayment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-PERIODO',
            '2026-07-10 13:30:00',
            '30.00',
        );
        $this->applyPayment($periodPayment, $period['document_id'], '30.00');

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.client.id', $this->clientId)
            ->assertJsonPath('data.client.name', 'Cliente Estado Ágil')
            ->assertJsonPath('data.period.from', '2026-07-01')
            ->assertJsonPath('data.period.to', '2026-07-31')
            ->assertJsonPath('data.branch.id', $this->branchId)
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.opening_balance', '80.00')
            ->assertJsonPath('data.sales_total', '94.10')
            ->assertJsonPath('data.payments_total', '30.00')
            ->assertJsonPath('data.ending_balance', '144.10')
            ->assertJsonPath('data.ticket_count', 1)
            ->assertJsonPath('data.payment_count', 1)
            ->assertJsonCount(3, 'data.rows');

        $rows = collect($response->json('data.rows'));
        $sales = $rows->where('kind', 'SALE')->values();
        $payment = $rows->firstWhere('kind', 'PAYMENT');

        $this->assertCount(2, $sales);
        $this->assertSame('PD-20260707-002', $sales[0]['document']);
        $this->assertSame('Pavo', $sales[0]['product']);
        $this->assertSame('Pavo grande', $sales[0]['variation']);
        $this->assertSame(2, $sales[0]['quantity']);
        $this->assertSame('6.500', $sales[0]['net_weight_kg']);
        $this->assertSame('12.0000', $sales[0]['price']);
        $this->assertSame(ProductoDespacho::PRICE_MODE_KG, $sales[0]['price_mode']);
        $this->assertSame('78.00', $sales[0]['sale']);
        $this->assertSame('0.00', $sales[0]['payment']);
        $this->assertFalse($sales[0]['show_balance']);

        $this->assertSame('Huevo', $sales[1]['product']);
        $this->assertNull($sales[1]['variation']);
        $this->assertSame(10, $sales[1]['quantity']);
        $this->assertSame('1.150', $sales[1]['net_weight_kg']);
        $this->assertSame('1.6100', $sales[1]['price']);
        $this->assertSame(ProductoDespacho::PRICE_MODE_UNIT, $sales[1]['price_mode']);
        $this->assertSame('16.10', $sales[1]['sale']);
        $this->assertSame('174.10', $sales[1]['balance']);
        $this->assertTrue($sales[1]['show_balance']);

        $this->assertIsArray($payment);
        $this->assertSame('2026-07-10', $payment['date']);
        $this->assertSame('PG-PERIODO', $payment['document']);
        $this->assertSame('0.00', $payment['sale']);
        $this->assertSame('30.00', $payment['payment']);
        $this->assertSame('144.10', $payment['balance']);
        $this->assertTrue($payment['show_balance']);
    }

    public function test_statement_prorates_shared_payments_and_excludes_unrelated_or_voided_activity(): void
    {
        $own = $this->simpleProductDocument('PD-PROPIO', '2026-07-05', '100.00');
        $voidedPaymentDocument = $this->simpleProductDocument('PD-PAGO-ANULADO', '2026-07-06', '40.00');

        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'OTRA-ESTADO',
            'Otra sucursal estado de cuenta',
            'America/Lima',
        );
        $otherBranch = $this->createProductDocument(
            (int) $this->user->empresa_id,
            $otherBranchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-OTRA-SUCURSAL',
            '2026-07-05',
            'PEN',
            [$this->simpleLine('20.00')],
        );
        $classicDocumentId = $this->createStandaloneDocument(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'VENTA-OTRO-MODULO',
            '2026-07-05',
            'PEN',
            '50.00',
        );

        $shared = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-COMPARTIDO',
            '2026-07-10 10:00:00',
            '100.00',
        );
        $this->applyPayment($shared, $own['document_id'], '30.00');
        $this->applyPayment($shared, $otherBranch['document_id'], '20.00');
        $this->applyPayment($shared, $classicDocumentId, '50.00');

        $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-SIN-APLICAR',
            '2026-07-11 10:00:00',
            '75.00',
        );
        $voidedPayment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-ANULADO',
            '2026-07-12 10:00:00',
            '40.00',
            Pago::STATUS_VOIDED,
        );
        $this->applyPayment($voidedPayment, $voidedPaymentDocument['document_id'], '40.00', false);
        $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-REVERSA',
            '2026-07-13 10:00:00',
            '40.00',
            Pago::STATUS_REGISTERED,
            $voidedPayment,
        );

        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-ELIMINADO',
            '2026-07-07',
            'PEN',
            [$this->simpleLine('60.00')],
            TicketDespachoProducto::STATUS_DELETED,
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-COMPROBANTE-ANULADO',
            '2026-07-08',
            'PEN',
            [$this->simpleLine('70.00')],
            TicketDespachoProducto::STATUS_REGISTERED,
            Comprobante::STATUS_VOIDED,
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-DOLARES',
            '2026-07-09',
            'USD',
            [$this->simpleLine('80.00')],
        );
        $otherClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente diferente',
            '20999999998',
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $otherClient,
            'PD-OTRO-CLIENTE',
            '2026-07-10',
            'PEN',
            [$this->simpleLine('90.00')],
        );

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.sales_total', '140.00')
            ->assertJsonPath('data.payments_total', '30.00')
            ->assertJsonPath('data.ending_balance', '110.00')
            ->assertJsonPath('data.ticket_count', 2)
            ->assertJsonPath('data.payment_count', 1);

        $rows = collect($response->json('data.rows'));
        $this->assertSame(
            ['PD-PROPIO', 'PD-PAGO-ANULADO'],
            $rows->where('kind', 'SALE')->pluck('document')->all(),
        );
        $this->assertSame(['PG-COMPARTIDO'], $rows->where('kind', 'PAYMENT')->pluck('document')->all());
        $this->assertSame('30.00', $rows->firstWhere('kind', 'PAYMENT')['payment']);
        foreach ([
            'PG-SIN-APLICAR',
            'PG-ANULADO',
            'PG-REVERSA',
            'PD-ELIMINADO',
            'PD-COMPROBANTE-ANULADO',
            'PD-DOLARES',
            'PD-OTRA-SUCURSAL',
            'VENTA-OTRO-MODULO',
            'PD-OTRO-CLIENTE',
        ] as $excluded) {
            $this->assertNotContains($excluded, $rows->pluck('document'));
        }
    }

    public function test_statement_rejects_ambiguous_financial_links_even_when_the_extra_ticket_is_in_another_branch(): void
    {
        $current = $this->simpleProductDocument('PD-VINCULO-LOCAL', '2026-07-05', '100.00');
        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'VINCULO-AJENO',
            'Sucursal del vínculo adicional',
            'America/Lima',
        );
        $other = $this->createProductDocument(
            (int) $this->user->empresa_id,
            $otherBranchId,
            (int) $this->user->id,
            $this->clientId,
            'PD-VINCULO-OTRA-SUCURSAL',
            '2026-07-06',
            'PEN',
            [$this->simpleLine('100.00')],
        );

        DB::table('comprobante_tickets_despacho_productos')->insert([
            'comprobante_id' => $current['document_id'],
            'ticket_despacho_producto_id' => $other['ticket_id'],
            'importe_aplicado' => '100.00',
        ]);

        $response = $this->getJson($this->statementUrl())
            ->assertConflict();

        $this->assertStringContainsString(
            'vínculos financieros inconsistentes',
            (string) $response->json('message'),
        );
    }

    public function test_statement_rejects_a_financial_link_amount_that_differs_from_the_document_total(): void
    {
        $document = $this->simpleProductDocument('PD-IMPORTE-INCONSISTENTE', '2026-07-05', '100.00');
        DB::table('comprobante_tickets_despacho_productos')
            ->where('comprobante_id', $document['document_id'])
            ->where('ticket_despacho_producto_id', $document['ticket_id'])
            ->update(['importe_aplicado' => '99.99']);

        $response = $this->getJson($this->statementUrl())
            ->assertConflict();

        $this->assertStringContainsString(
            'vínculos financieros inconsistentes',
            (string) $response->json('message'),
        );
    }

    public function test_statement_rejects_a_product_document_also_linked_to_another_dispatch_module(): void
    {
        $document = $this->simpleProductDocument('PD-VINCULO-OTRO-MODULO', '2026-07-05', '100.00');
        $journeyId = (int) DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-07-05',
            'estado' => 'ABIERTA',
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-07-05 08:00:00',
            'cierre_programado_at' => '2026-07-05 21:00:00',
        ]);
        $classicTicketId = (int) DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => 'T-VINCULO-OTRO-MODULO',
            'canal' => 'MAYORISTA',
            'tipo_operacion' => 'DESPACHO',
            'estado' => 'ABIERTO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('comprobante_tickets')->insert([
            'comprobante_id' => $document['document_id'],
            'ticket_id' => $classicTicketId,
            'importe_aplicado' => '100.00',
        ]);

        $response = $this->getJson($this->statementUrl())
            ->assertConflict();

        $this->assertStringContainsString(
            'vínculos financieros inconsistentes',
            (string) $response->json('message'),
        );
    }

    public function test_statement_rejects_a_product_document_with_an_unrelated_origin_key(): void
    {
        $document = $this->simpleProductDocument('PD-ORIGEN-INCONSISTENTE', '2026-07-05', '100.00');
        DB::table('comprobantes')
            ->where('id', $document['document_id'])
            ->update(['origen_clave' => 'VENTA:TICKET_PRODUCTOS:999999999']);

        $response = $this->getJson($this->statementUrl())
            ->assertConflict();

        $this->assertStringContainsString(
            'vínculos financieros inconsistentes',
            (string) $response->json('message'),
        );
    }

    public function test_customer_discount_reduces_the_balance_and_is_identified_in_the_ledger(): void
    {
        $document = $this->simpleProductDocument('PD-DESCUENTO', '2026-07-05', '100.00');
        $discount = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'DS-DESCUENTO',
            '2026-07-10 11:30:00',
            '15.00',
            type: Pago::TYPE_CUSTOMER_DISCOUNT,
            observations: 'Descuento comercial autorizado por diferencia de peso',
            reference: 'AUT-15',
        );
        $this->applyPayment($discount, $document['document_id'], '15.00');

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.sales_total', '100.00')
            ->assertJsonPath('data.payments_total', '15.00')
            ->assertJsonPath('data.ending_balance', '85.00')
            ->assertJsonPath('data.payment_count', 1);

        $discountRow = collect($response->json('data.rows'))->firstWhere('document', 'DS-DESCUENTO');

        $this->assertIsArray($discountRow);
        $this->assertSame('PAYMENT', $discountRow['kind']);
        $this->assertSame(Pago::TYPE_CUSTOMER_DISCOUNT, $discountRow['payment_type']);
        $this->assertSame('Descuento', $discountRow['movement_label']);
        $this->assertSame(
            'Descuento comercial autorizado por diferencia de peso · AUT-15',
            $discountRow['detail'],
        );
        $this->assertSame('15.00', $discountRow['payment']);
        $this->assertSame('85.00', $discountRow['balance']);
        $this->assertTrue($discountRow['show_balance']);
    }

    public function test_manual_prior_debt_and_its_payments_rebuild_the_opening_balance(): void
    {
        $debtId = $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'DA-APERTURA',
            '2026-06-20',
            'PEN',
            '150.00',
            'Deuda anterior al inicio del sistema',
        );
        $openingPayment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-DA-ANTES',
            '2026-06-28 09:00:00',
            '40.00',
        );
        $this->applyPayment($openingPayment, $debtId, '40.00');
        $periodPayment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-DA-PERIODO',
            '2026-07-10 11:30:00',
            '30.00',
        );
        $this->applyPayment($periodPayment, $debtId, '30.00');

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '110.00')
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.prior_debt_total', '0.00')
            ->assertJsonPath('data.charges_total', '0.00')
            ->assertJsonPath('data.payments_total', '30.00')
            ->assertJsonPath('data.ending_balance', '80.00')
            ->assertJsonPath('data.ticket_count', 0)
            ->assertJsonPath('data.prior_debt_count', 0)
            ->assertJsonPath('data.payment_count', 1)
            ->assertJsonCount(1, 'data.rows');

        $payment = $response->json('data.rows.0');
        $this->assertSame('PAYMENT', $payment['kind']);
        $this->assertSame('PG-DA-PERIODO', $payment['document']);
        $this->assertSame('30.00', $payment['payment']);
        $this->assertSame('80.00', $payment['balance']);
        $this->assertTrue($payment['show_balance']);
    }

    public function test_manual_prior_debt_inside_the_period_is_a_separate_charge_and_excludes_invalid_debts(): void
    {
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'DA-PERIODO',
            '2026-07-05',
            'PEN',
            '200.00',
            'Saldo heredado del sistema anterior',
        );
        $this->simpleProductDocument('PD-CON-DEUDA', '2026-07-06', '50.00');
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'DA-ANULADA',
            '2026-07-07',
            'PEN',
            '70.00',
            'No debe aparecer porque fue anulada',
            Comprobante::STATUS_VOIDED,
        );
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'DA-ORIGEN-FALSO',
            '2026-07-08',
            'PEN',
            '80.00',
            'No debe aparecer porque no fue registrada como deuda anterior',
            originKey: 'PRUEBA:DA-ORIGEN-FALSO',
        );

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.sales_total', '50.00')
            ->assertJsonPath('data.prior_debt_total', '200.00')
            ->assertJsonPath('data.charges_total', '250.00')
            ->assertJsonPath('data.payments_total', '0.00')
            ->assertJsonPath('data.ending_balance', '250.00')
            ->assertJsonPath('data.ticket_count', 1)
            ->assertJsonPath('data.prior_debt_count', 1)
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonCount(2, 'data.rows');

        $rows = collect($response->json('data.rows'));
        $debt = $rows->firstWhere('kind', 'PRIOR_DEBT');

        $this->assertIsArray($debt);
        $this->assertSame('2026-07-05', $debt['date']);
        $this->assertSame('DA-PERIODO', $debt['document']);
        $this->assertSame('Deuda anterior', $debt['movement_label']);
        $this->assertSame('Deuda anterior', $debt['product']);
        $this->assertSame('Saldo heredado del sistema anterior', $debt['detail']);
        $this->assertNull($debt['quantity']);
        $this->assertNull($debt['net_weight_kg']);
        $this->assertNull($debt['price']);
        $this->assertSame('200.00', $debt['sale']);
        $this->assertSame('0.00', $debt['payment']);
        $this->assertSame('200.00', $debt['balance']);
        $this->assertTrue($debt['show_balance']);
        $this->assertSame(['DA-PERIODO', 'PD-CON-DEUDA'], $rows->pluck('document')->all());
        $this->assertNotContains('DA-ANULADA', $rows->pluck('document'));
        $this->assertNotContains('DA-ORIGEN-FALSO', $rows->pluck('document'));
    }

    public function test_catalog_includes_manual_debt_currency_and_inactive_historical_client_only_for_valid_company_debt(): void
    {
        $historicalClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Histórico Con Deuda',
            '20666666661',
        );
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $historicalClient,
            'DA-HISTORICA-USD',
            '2026-06-15',
            'USD',
            '75.00',
            'Saldo histórico en dólares',
        );
        DB::table('terceros')->where('id', $historicalClient)->update([
            'estado' => Tercero::STATUS_INACTIVE,
        ]);

        $voidedClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Solo Deuda Anulada',
            '20666666662',
            Tercero::STATUS_INACTIVE,
        );
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $voidedClient,
            'DA-CATALOGO-ANULADA',
            '2026-06-16',
            'EUR',
            '80.00',
            'Deuda anulada fuera del catálogo',
            Comprobante::STATUS_VOIDED,
        );

        $fakeClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Solo Origen Falso',
            '20666666663',
            Tercero::STATUS_INACTIVE,
        );
        $this->createManualCustomerDebt(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $fakeClient,
            'DA-CATALOGO-FALSA',
            '2026-06-17',
            'GBP',
            '90.00',
            'Documento que imita una deuda anterior',
            originKey: 'PRUEBA:DA-CATALOGO-FALSA',
        );

        $foreignUser = User::factory()->create();
        $foreignClient = $this->createClient(
            (int) $foreignUser->empresa_id,
            'Cliente Con Deuda De Otra Empresa',
            '20666666664',
            Tercero::STATUS_INACTIVE,
        );
        $this->createManualCustomerDebt(
            (int) $foreignUser->empresa_id,
            (int) $foreignUser->id,
            $foreignClient,
            'DA-EMPRESA-AJENA',
            '2026-06-18',
            'CAD',
            '100.00',
            'Deuda de otra empresa',
        );

        $response = $this->getJson('/api/v1/despacho-productos/estado-cuenta/catalogo')
            ->assertOk();
        $clientIds = collect($response->json('data.clients'))->pluck('id');

        $this->assertContains($historicalClient, $clientIds);
        $this->assertNotContains($voidedClient, $clientIds);
        $this->assertNotContains($fakeClient, $clientIds);
        $this->assertNotContains($foreignClient, $clientIds);
        $this->assertSame(['PEN', 'USD'], $response->json('data.currencies'));

        $this->getJson($this->statementUrl(clientId: $historicalClient, currency: 'USD'))
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '75.00')
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.prior_debt_total', '0.00')
            ->assertJsonPath('data.charges_total', '0.00')
            ->assertJsonPath('data.ending_balance', '75.00')
            ->assertJsonPath('data.prior_debt_count', 0)
            ->assertJsonCount(0, 'data.rows');
    }

    public function test_catalog_lists_active_external_company_clients_and_historical_branch_clients(): void
    {
        $this->simpleProductDocument('PD-CATALOGO-PEN', '2026-07-05', '10.00');
        $activeWithoutHistory = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Nuevo Sin Despachos',
            '20888888880',
        );
        $inactiveClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Histórico Inactivo',
            '20888888881',
            Tercero::STATUS_INACTIVE,
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $inactiveClient,
            'PD-CATALOGO-USD',
            '2026-07-06',
            'USD',
            [$this->simpleLine('15.00')],
        );
        $deletedOnlyClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Solo Eliminado',
            '20888888882',
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $deletedOnlyClient,
            'PD-CATALOGO-ELIMINADO',
            '2026-07-06',
            'PEN',
            [$this->simpleLine('15.00')],
            TicketDespachoProducto::STATUS_DELETED,
        );
        $inactiveWithoutHistory = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Inactivo Sin Historial',
            '20888888885',
            Tercero::STATUS_INACTIVE,
        );
        $internalClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Interno Activo',
            '20888888886',
        );
        DB::table('terceros')
            ->where('id', $internalClient)
            ->update(['es_cliente_interno' => true]);
        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'CATALOGO-AJENO',
            'Sucursal ajena al catálogo',
            'America/Lima',
        );
        $otherBranchClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente Otra Sucursal',
            '20888888883',
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $otherBranchId,
            (int) $this->user->id,
            $otherBranchClient,
            'PD-CATALOGO-OTRA-SUCURSAL',
            '2026-07-06',
            'EUR',
            [$this->simpleLine('15.00')],
        );

        $foreignUser = User::factory()->create();
        $foreignBranch = $this->createBranch(
            (int) $foreignUser->empresa_id,
            'CATALOGO-EMPRESA-AJENA',
            'Sucursal empresa ajena',
            'America/Lima',
        );
        $foreignClient = $this->createClient(
            (int) $foreignUser->empresa_id,
            'Cliente Empresa Ajena',
            '20888888884',
        );
        $foreignProduct = $this->createProduct(
            (int) $foreignUser->empresa_id,
            (int) $foreignUser->id,
            'Producto empresa ajena',
            ProductoDespacho::PRICE_MODE_KG,
            '10.0000',
        );
        $this->createProductDocument(
            (int) $foreignUser->empresa_id,
            $foreignBranch,
            (int) $foreignUser->id,
            $foreignClient,
            'PD-CATALOGO-EMPRESA-AJENA',
            '2026-07-06',
            'GBP',
            [[
                ...$this->simpleLine('15.00'),
                'product_id' => $foreignProduct,
                'product' => 'Producto empresa ajena',
            ]],
        );

        $response = $this->getJson('/api/v1/despacho-productos/estado-cuenta/catalogo')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'PEN')
            ->assertJsonPath('data.branch.id', $this->branchId)
            ->assertJsonPath('data.branch.code', 'ESTADO-CUENTA')
            ->assertJsonPath('data.branch.name', 'Sucursal estado de cuenta')
            ->assertJsonPath('data.branch.timezone', 'America/Lima');

        $clientIds = collect($response->json('data.clients'))->pluck('id');
        $this->assertEqualsCanonicalizing([
            $this->clientId,
            $activeWithoutHistory,
            $inactiveClient,
            $deletedOnlyClient,
            $otherBranchClient,
        ], $clientIds->all());
        $this->assertNotContains($inactiveWithoutHistory, $clientIds);
        $this->assertNotContains($internalClient, $clientIds);
        $this->assertNotContains($foreignClient, $clientIds);
        $this->assertSame(['PEN', 'USD'], $response->json('data.currencies'));

        $historical = collect($response->json('data.clients'))->firstWhere('id', $inactiveClient);
        $this->assertSame('Cliente Histórico Inactivo', $historical['name']);
        $this->assertSame('DNI', $historical['document_type']);
        $this->assertSame('20888888881', $historical['document']);

        $this->getJson($this->statementUrl(clientId: $inactiveClient, currency: 'USD'))
            ->assertOk()
            ->assertJsonPath('data.client.id', $inactiveClient)
            ->assertJsonPath('data.sales_total', '15.00')
            ->assertJsonPath('data.ending_balance', '15.00')
            ->assertJsonPath('data.ticket_count', 1);
    }

    public function test_active_external_client_without_tickets_can_generate_an_empty_statement(): void
    {
        $created = $this->postJson('/api/v1/despacho-productos/clientes', [
            'nombre_razon_social' => 'Cliente Recién Registrado',
            'numero_documento' => '20888888887',
            'direccion' => 'Av. Cliente Nuevo 123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'CLIENTE RECIÉN REGISTRADO');
        $newClient = (int) $created->json('data.id');

        $catalog = $this->getJson('/api/v1/despacho-productos/estado-cuenta/catalogo')
            ->assertOk();
        $this->assertContains(
            $newClient,
            collect($catalog->json('data.clients'))->pluck('id')->all(),
        );

        $this->getJson($this->statementUrl(clientId: $newClient))
            ->assertOk()
            ->assertJsonPath('data.client.id', $newClient)
            ->assertJsonPath('data.client.name', 'CLIENTE RECIÉN REGISTRADO')
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.payments_total', '0.00')
            ->assertJsonPath('data.ending_balance', '0.00')
            ->assertJsonPath('data.ticket_count', 0)
            ->assertJsonPath('data.payment_count', 0)
            ->assertJsonCount(0, 'data.rows');
    }

    public function test_statement_uses_branch_timezone_for_inclusive_payment_date_boundaries(): void
    {
        DB::table('sucursales')->where('id', $this->branchId)->update([
            'zona_horaria' => 'America/Los_Angeles',
        ]);
        $document = $this->simpleProductDocument('PD-ZONA-HORARIA', '2026-07-15', '100.00');

        // The database clock is America/Lima. These values are respectively
        // 01/07 00:00, 31/07 23:59:59 and 01/08 00:00 in Los Angeles.
        foreach ([
            ['PG-LIMITE-INICIAL', '2026-07-01 02:00:00', '10.00'],
            ['PG-LIMITE-FINAL', '2026-08-01 01:59:59', '15.00'],
            ['PG-FUERA-DEL-RANGO', '2026-08-01 02:00:00', '20.00'],
        ] as [$code, $storedAt, $amount]) {
            $payment = $this->createPayment(
                (int) $this->user->empresa_id,
                (int) $this->user->id,
                $this->clientId,
                $code,
                $storedAt,
                $amount,
            );
            $this->applyPayment($payment, $document['document_id'], $amount);
        }

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.branch.timezone', 'America/Los_Angeles')
            ->assertJsonPath('data.payments_total', '25.00')
            ->assertJsonPath('data.ending_balance', '75.00')
            ->assertJsonPath('data.payment_count', 2);

        $payments = collect($response->json('data.rows'))->where('kind', 'PAYMENT')->values();
        $this->assertSame(['PG-LIMITE-INICIAL', 'PG-LIMITE-FINAL'], $payments->pluck('document')->all());
        $this->assertSame(['2026-07-01', '2026-07-31'], $payments->pluck('date')->all());
    }

    public function test_collection_receipt_date_takes_precedence_over_the_later_deposit_timestamp(): void
    {
        $document = $this->simpleProductDocument('PD-FECHA-RECEPCION', '2026-07-15', '100.00');
        $payment = $this->createPayment(
            (int) $this->user->empresa_id,
            (int) $this->user->id,
            $this->clientId,
            'PG-DEPOSITO-AGOSTO',
            '2026-08-01 09:00:00',
            '25.00',
        );
        $this->applyPayment($payment, $document['document_id'], '25.00');

        $entityId = (int) DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Entidad de cobranza con fecha efectiva',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountId = (int) DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => 'BANCO',
            'alias' => 'Cuenta fecha efectiva',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectorId = (int) DB::table('cobradores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre' => 'Cobrador fecha efectiva',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $methodId = (int) DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $collectionId = (int) DB::table('cobranzas')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'cobrador_id' => $collectorId,
            'cobrador_nombre_snapshot' => 'Cobrador fecha efectiva',
            'codigo' => 'COB-FECHA-EFECTIVA-PRODUCTOS',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'cobranza-productos-fecha-efectiva'),
            'cuenta_destino_id' => $accountId,
            'proveedor_id' => null,
            'metodo_pago_id' => $methodId,
            'fecha_hora' => '2026-08-01 09:00:00',
            'referencia' => 'DEP-01-08',
            'moneda' => 'PEN',
            'importe_total' => '25.00',
            'observaciones' => null,
            'estado' => 'REGISTRADO',
            'created_by' => $this->user->id,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cobranza_detalles')->insert([
            'cobranza_id' => $collectionId,
            'asignacion_id' => null,
            'pago_id' => $payment,
            'cliente_id' => $this->clientId,
            'fecha_recepcion' => '2026-07-31',
            'medio_recepcion' => 'EFECTIVO',
            'importe' => '25.00',
            'orden' => 1,
            'created_at' => now(),
        ]);

        $response = $this->getJson($this->statementUrl())
            ->assertOk()
            ->assertJsonPath('data.sales_total', '100.00')
            ->assertJsonPath('data.payments_total', '25.00')
            ->assertJsonPath('data.ending_balance', '75.00')
            ->assertJsonPath('data.payment_count', 1);

        $paymentRow = collect($response->json('data.rows'))->firstWhere('kind', 'PAYMENT');
        $this->assertIsArray($paymentRow);
        $this->assertSame('2026-07-31', $paymentRow['date']);
        $this->assertSame('25.00', $paymentRow['payment']);
    }

    public function test_statement_validation_rejects_malformed_ranges_arrays_and_ineligible_clients(): void
    {
        $this->simpleProductDocument('PD-VALIDACION', '2026-07-15', '10.00');

        $this->getJson('/api/v1/despacho-productos/estado-cuenta')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'date_from', 'date_to', 'currency']);
        $this->getJson('/api/v1/despacho-productos/estado-cuenta?'.http_build_query([
            'client_id' => [$this->clientId],
            'date_from' => ['2026-07-01'],
            'date_to' => ['2026-07-31'],
            'currency' => ['PEN'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'date_from', 'date_to', 'currency']);
        $this->getJson('/api/v1/despacho-productos/estado-cuenta?'.http_build_query([
            'client_id' => $this->clientId,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-01',
            'currency' => 'PEN',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'VALIDACION-AJENA',
            'Sucursal validación ajena',
            'America/Lima',
        );
        $otherBranchClient = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente sin historial propio',
            '20777777771',
        );
        $this->createProductDocument(
            (int) $this->user->empresa_id,
            $otherBranchId,
            (int) $this->user->id,
            $otherBranchClient,
            'PD-VALIDACION-AJENA',
            '2026-07-15',
            'PEN',
            [$this->simpleLine('10.00')],
        );
        $this->getJson($this->statementUrl(clientId: $otherBranchClient))
            ->assertOk()
            ->assertJsonPath('data.client.id', $otherBranchClient)
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.payments_total', '0.00')
            ->assertJsonPath('data.ending_balance', '0.00')
            ->assertJsonCount(0, 'data.rows');

        $inactiveWithoutHistory = $this->createClient(
            (int) $this->user->empresa_id,
            'Cliente inactivo sin historial',
            '20777777773',
            Tercero::STATUS_INACTIVE,
        );
        $this->getJson($this->statementUrl(clientId: $inactiveWithoutHistory))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $foreignUser = User::factory()->create();
        $foreignClient = $this->createClient(
            (int) $foreignUser->empresa_id,
            'Cliente de otra empresa',
            '20777777772',
        );
        $this->getJson($this->statementUrl(clientId: $foreignClient))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');

        $this->getJson($this->statementUrl(currency: 'pen'))
            ->assertOk()
            ->assertJsonPath('data.currency', 'PEN');
    }

    public function test_pdf_defaults_to_download_and_supports_private_inline_preview(): void
    {
        $this->simpleProductDocument('PD-PDF', '2026-07-15', '25.00');
        $this->actingAs($this->user);
        $query = [
            'client_id' => $this->clientId,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'currency' => 'PEN',
        ];

        $download = $this->get(route('despacho-productos.estado-cuenta.pdf', $query))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment; filename="', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf"', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $download->getContent());

        $preview = $this->get(route('despacho-productos.estado-cuenta.pdf', [
            ...$query,
            'preview' => 1,
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline; filename="', (string) $preview->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.pdf"', (string) $preview->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $preview->getContent());

        $this->getJson(route('despacho-productos.estado-cuenta.pdf', [
            ...$query,
            'preview' => ['invalid'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview');
    }

    private function statementUrl(
        ?int $clientId = null,
        string $dateFrom = '2026-07-01',
        string $dateTo = '2026-07-31',
        string $currency = 'PEN',
    ): string {
        return '/api/v1/despacho-productos/estado-cuenta?'.http_build_query([
            'client_id' => $clientId ?? $this->clientId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'currency' => $currency,
        ]);
    }

    /** @return array<string, mixed> */
    private function simpleLine(string $amount): array
    {
        return [
            'product_id' => $this->kgProductId,
            'product' => 'Pavo',
            'variation_id' => null,
            'variation' => null,
            'quantity' => 1,
            'net_weight_kg' => '1.000',
            'price_mode' => ProductoDespacho::PRICE_MODE_KG,
            'price' => number_format((float) $amount, 4, '.', ''),
            'subtotal' => $amount,
        ];
    }

    /** @return array{ticket_id: int, document_id: int} */
    private function simpleProductDocument(string $code, string $date, string $amount): array
    {
        return $this->createProductDocument(
            (int) $this->user->empresa_id,
            $this->branchId,
            (int) $this->user->id,
            $this->clientId,
            $code,
            $date,
            'PEN',
            [$this->simpleLine($amount)],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{ticket_id: int, document_id: int}
     */
    private function createProductDocument(
        int $companyId,
        int $branchId,
        int $actorId,
        int $clientId,
        string $ticketCode,
        string $date,
        string $currency,
        array $lines,
        string $ticketStatus = TicketDespachoProducto::STATUS_REGISTERED,
        string $documentStatus = Comprobante::STATUS_PENDING,
    ): array {
        $client = DB::table('terceros')->where('id', $clientId)->first();
        $total = collect($lines)->reduce(
            fn (string $sum, array $line): string => FinancialMoney::add($sum, (string) $line['subtotal']),
            '0.00',
        );
        $quantity = (int) collect($lines)->sum('quantity');
        $netWeight = number_format((float) collect($lines)->sum(
            fn (array $line): float => (float) $line['net_weight_kg'],
        ), 3, '.', '');
        $ticketId = (int) DB::table('tickets_despacho_productos')->insertGetId([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'referencia_externa' => (string) Str::uuid(),
            'numero_lista' => 1,
            'codigo' => $ticketCode,
            'titulo_ticket_snapshot' => 'CONTROL DE DESPACHO',
            'mensaje_ticket_snapshot' => null,
            'fecha_operativa' => $date,
            'cliente_id' => $clientId,
            'tipo_cliente' => TicketDespachoProducto::CUSTOMER_REGISTERED,
            'cliente_tipo_documento_snapshot' => $client?->tipo_documento,
            'cliente_numero_documento_snapshot' => $client?->numero_documento,
            'cliente_nombre_snapshot' => $client?->nombre_razon_social ?? 'Cliente',
            'moneda' => $currency,
            'cantidad_total' => $quantity,
            'peso_leido_total_kg' => $netWeight,
            'merma_total_gramos' => 0,
            'tara_total_gramos' => 0,
            'peso_neto_total_kg' => $netWeight,
            'subtotal' => $total,
            'total' => $total,
            'estado' => $ticketStatus,
            'registrado_at' => "{$date} 10:00:00",
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            $description = $line['variation']
                ? $line['product'].' · '.$line['variation']
                : $line['product'];
            DB::table('pesadas_despacho_productos')->insert([
                'ticket_despacho_producto_id' => $ticketId,
                'numero' => $index + 1,
                'producto_despacho_id' => $line['product_id'],
                'variacion_producto_despacho_id' => $line['variation_id'],
                'lectura_balanza_id' => null,
                'producto_nombre_snapshot' => $line['product'],
                'variacion_nombre_snapshot' => $line['variation'],
                'modo_precio_snapshot' => $line['price_mode'],
                'precio_catalogo_snapshot' => $line['price'],
                'precio_venta_snapshot' => $line['price'],
                'origen_precio' => 'CATALOGO',
                'cantidad' => $line['quantity'],
                'origen_peso' => 'MANUAL',
                'peso_leido_kg' => $line['net_weight_kg'],
                'merma_catalogo_gramos_unidad' => 0,
                'merma_aplicada_gramos_unidad' => 0,
                'merma_total_gramos' => 0,
                'tara_gramos' => 0,
                'peso_neto_kg' => $line['net_weight_kg'],
                'importe' => $line['subtotal'],
                'pesada_at' => "{$date} 10:00:00",
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $documentId = $this->createStandaloneDocument(
            $companyId,
            $actorId,
            $clientId,
            'VPD-'.$ticketId,
            $date,
            $currency,
            $total,
            $documentStatus,
            "VENTA:TICKET_PRODUCTOS:{$ticketId}",
        );
        foreach ($lines as $line) {
            DB::table('comprobante_detalles')->insert([
                'comprobante_id' => $documentId,
                'tipo_pollo_id' => null,
                'producto_despacho_id' => $line['product_id'],
                'variacion_producto_despacho_id' => $line['variation_id'],
                'descripcion' => $line['variation']
                    ? $line['product'].' · '.$line['variation']
                    : $line['product'],
                'cantidad_aves' => null,
                'cantidad_unidades' => $line['quantity'],
                'peso_neto_kg' => $line['net_weight_kg'],
                'modo_precio' => $line['price_mode'],
                'precio_kg' => $line['price_mode'] === ProductoDespacho::PRICE_MODE_KG
                    ? $line['price']
                    : null,
                'precio_unitario' => $line['price_mode'] === ProductoDespacho::PRICE_MODE_UNIT
                    ? $line['price']
                    : null,
                'subtotal' => $line['subtotal'],
                'created_at' => now(),
            ]);
        }
        DB::table('comprobante_tickets_despacho_productos')->insert([
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticketId,
            'importe_aplicado' => $total,
        ]);

        return ['ticket_id' => $ticketId, 'document_id' => $documentId];
    }

    private function createStandaloneDocument(
        int $companyId,
        int $actorId,
        int $clientId,
        string $code,
        string $date,
        string $currency,
        string $amount,
        string $status = Comprobante::STATUS_PENDING,
        ?string $originKey = null,
    ): int {
        $client = DB::table('terceros')->where('id', $clientId)->first();
        $voided = $status === Comprobante::STATUS_VOIDED;

        return (int) DB::table('comprobantes')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $clientId,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => $code,
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => $originKey ?? 'PRUEBA:'.$code,
            'fecha_emision' => $date,
            'fecha_vencimiento' => $date,
            'moneda' => $currency,
            'subtotal' => $amount,
            'impuesto' => '0.00',
            'total' => $amount,
            'saldo_pendiente' => $voided ? '0.00' : $amount,
            'estado' => $status,
            'contraparte_tipo_documento_snapshot' => $client?->tipo_documento,
            'contraparte_numero_documento_snapshot' => $client?->numero_documento,
            'contraparte_nombre_snapshot' => $client?->nombre_razon_social,
            'contraparte_direccion_snapshot' => $client?->direccion,
            'created_by' => $actorId,
            'anulada_por' => $voided ? $actorId : null,
            'anulada_at' => $voided ? "{$date} 18:00:00" : null,
            'motivo_anulacion' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createManualCustomerDebt(
        int $companyId,
        int $actorId,
        int $clientId,
        string $code,
        string $date,
        string $currency,
        string $amount,
        string $detail,
        string $status = Comprobante::STATUS_PENDING,
        ?string $originKey = null,
    ): int {
        $documentId = $this->createStandaloneDocument(
            $companyId,
            $actorId,
            $clientId,
            $code,
            $date,
            $currency,
            $amount,
            $status,
            $originKey ?? 'DEUDA_ANTERIOR_CLIENTE:'.Str::uuid(),
        );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'tipo_documento' => 'SALDO_ANTERIOR',
            'origen_codigo' => 'MANUAL',
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $documentId,
            'tipo_pollo_id' => null,
            'producto_despacho_id' => null,
            'variacion_producto_despacho_id' => null,
            'descripcion' => $detail,
            'cantidad_aves' => null,
            'cantidad_unidades' => null,
            'peso_neto_kg' => null,
            'modo_precio' => null,
            'precio_kg' => null,
            'precio_unitario' => null,
            'subtotal' => $amount,
            'created_at' => now(),
        ]);

        return $documentId;
    }

    private function createPayment(
        int $companyId,
        int $actorId,
        int $clientId,
        string $code,
        string $storedAt,
        string $amount,
        string $status = Pago::STATUS_REGISTERED,
        ?int $reversesPaymentId = null,
        string $type = Pago::TYPE_CUSTOMER_COLLECTION,
        ?string $observations = null,
        ?string $reference = null,
    ): int {
        $voided = $status === Pago::STATUS_VOIDED;

        return (int) DB::table('pagos')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'tercero_id' => $clientId,
            'tipo' => $type,
            'cliente_id' => $clientId,
            'proveedor_id' => null,
            'cuenta_origen_id' => null,
            'cuenta_destino_id' => null,
            'metodo_pago_id' => null,
            'direccion' => $type === Pago::TYPE_CUSTOMER_DISCOUNT
                ? Pago::DIRECTION_NO_FLOW
                : Pago::DIRECTION_INCOME,
            'fecha_hora' => $storedAt,
            'metodo' => 'EFECTIVO',
            'referencia' => $reference,
            'moneda' => 'PEN',
            'importe' => $amount,
            'estado' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'reversa_de_pago_id' => $reversesPaymentId,
            'observaciones' => $observations,
            'created_by' => $actorId,
            'anulada_por' => $voided ? $actorId : null,
            'anulada_at' => $voided ? now() : null,
            'motivo_anulacion' => $voided ? 'Anulación de prueba' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function applyPayment(int $paymentId, int $documentId, string $amount, bool $updateBalance = true): void
    {
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => $amount,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        if (! $updateBalance) {
            return;
        }

        $document = DB::table('comprobantes')->where('id', $documentId)->first();
        $balance = FinancialMoney::subtract((string) $document->saldo_pendiente, $amount);
        DB::table('comprobantes')->where('id', $documentId)->update([
            'saldo_pendiente' => $balance,
            'estado' => FinancialMoney::compare($balance, '0.00') === 0
                ? Comprobante::STATUS_PAID
                : Comprobante::STATUS_PARTIAL,
            'updated_at' => now(),
        ]);
    }

    private function createBranch(int $companyId, string $code, string $name, string $timezone): int
    {
        return (int) DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'zona_horaria' => $timezone,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClient(
        int $companyId,
        string $name,
        string $document,
        string $status = Tercero::STATUS_ACTIVE,
    ): int {
        $clientId = (int) DB::table('terceros')->insertGetId([
            'empresa_id' => $companyId,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Sin dirección',
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

    private function createProduct(
        int $companyId,
        int $actorId,
        string $name,
        string $priceMode,
        string $price,
    ): int {
        return (int) DB::table('productos_despacho')->insertGetId([
            'empresa_id' => $companyId,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'descripcion' => null,
            'modo_precio' => $priceMode,
            'precio_venta' => $price,
            'merma_gramos_unidad' => 0,
            'imagen_path' => null,
            'estado' => ProductoDespacho::STATUS_ACTIVE,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVariation(
        int $productId,
        int $actorId,
        string $name,
        string $priceMode,
        string $price,
    ): int {
        return (int) DB::table('variaciones_producto_despacho')->insertGetId([
            'producto_despacho_id' => $productId,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'modo_precio' => $priceMode,
            'precio_venta' => $price,
            'merma_gramos_unidad' => 0,
            'imagen_path' => null,
            'orden' => 1,
            'estado' => 'ACTIVO',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
