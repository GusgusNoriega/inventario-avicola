<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\CuentaFinanciera;
use App\Models\EntidadFinanciera;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use App\Services\FinancialCounterpartySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchPaymentApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private const URL = '/api/v1/despacho-productos/pagos';

    private User $user;

    private Tercero $client;

    private int $branchId;

    private int $methodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->branchId = $this->createBranch($this->user, 'PAGOS');
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, [
            'MODULO_DESPACHO_PRODUCTOS',
            'PRODUCTOS_DESPACHO_DESPACHAR',
        ]);
        Sanctum::actingAs($this->user, ['api']);

        $this->client = $this->createClient($this->user, 'CLIENTE EL SOL', '20111111111');
        $this->methodId = (int) MetodoPago::query()->where('codigo', MetodoPago::CODE_TRANSFER)->value('id');
        MetodoPago::query()->whereKey($this->methodId)->update(['requiere_referencia' => true]);
    }

    public function test_dispatch_permissions_allow_registering_without_reference_or_account_and_without_finance_access(): void
    {
        $payload = $this->payload();
        unset($payload['referencia'], $payload['cuenta_destino_id']);

        $response = $this->postJson(self::URL, $payload)
            ->assertCreated()
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.amount', '12.34')
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.payment_method.id', $this->methodId)
            ->assertJsonPath('data.account', null)
            ->assertJsonPath('data.reference', null)
            ->assertJsonPath('data.date_time', '2026-09-04T09:15');

        $this->assertDatabaseHas('pagos_despacho_productos', [
            'id' => $response->json('data.id'),
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'idempotency_key' => $payload['idempotency_key'],
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $this->paymentId((int) $response->json('data.id')),
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'direccion' => Pago::DIRECTION_INCOME,
            'cliente_id' => $this->client->id,
            'proveedor_id' => null,
            'cuenta_origen_id' => null,
            'cuenta_destino_id' => null,
            'referencia' => null,
            'importe' => 12.34,
        ]);
        $this->assertSummary($this->client, '12.34', '-12.34');
        $this->getJson('/api/v1/finanzas/movimientos')->assertForbidden();
    }

    public function test_catalog_exposes_active_methods_and_only_active_company_owned_accounts(): void
    {
        $own = $this->createAccount($this->user, 'Cuenta empresa');
        $external = $this->createAccount($this->user, 'Cuenta proveedor', EntidadFinanciera::TYPE_EXTERNAL);
        $inactive = $this->createAccount($this->user, 'Cuenta inactiva');
        $inactive->update(['estado' => CuentaFinanciera::STATUS_INACTIVE]);
        $inactiveEntity = $this->createAccount($this->user, 'Entidad inactiva');
        $inactiveEntity->entidadFinanciera()->update(['estado' => EntidadFinanciera::STATUS_INACTIVE]);
        $foreign = $this->createAccount(User::factory()->create(), 'Otra empresa');
        $inactiveMethod = MetodoPago::query()->create([
            'codigo' => 'PRUEBA_INACTIVO',
            'nombre' => 'Método inactivo',
            'requiere_referencia' => false,
            'estado' => MetodoPago::STATUS_INACTIVE,
        ]);

        $response = $this->getJson(self::URL.'/catalogo')
            ->assertOk()
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.branch.id', $this->branchId)
            ->assertJsonStructure(['data' => [
                'methods' => [['id', 'code', 'name']],
                'accounts' => [['id', 'name', 'currency']],
                'now',
                'branch' => ['id', 'name'],
            ]]);

        $accountIds = collect($response->json('data.accounts'))->pluck('id')->all();
        $this->assertContains($own->id, $accountIds);
        foreach ([$external, $inactive, $inactiveEntity, $foreign] as $account) {
            $this->assertNotContains($account->id, $accountIds);
        }
        $methodIds = collect($response->json('data.methods'))->pluck('id')->all();
        $this->assertContains($this->methodId, $methodIds);
        $this->assertNotContains($inactiveMethod->id, $methodIds);
    }

    public function test_listing_searches_client_and_reference_and_filters_inclusive_dates(): void
    {
        $first = $this->postJson(self::URL, $this->payload([
            'fecha_hora' => '2026-09-04T00:00',
            'referencia' => 'OPERACION-ALFA',
        ]))->assertCreated()->json('data.id');
        $second = $this->postJson(self::URL, $this->payload([
            'fecha_hora' => '2026-09-04T23:59',
        ]))->assertCreated()->json('data.id');
        $this->postJson(self::URL, $this->payload(['fecha_hora' => '2026-09-03T23:59']))
            ->assertCreated();

        $response = $this->getJson(self::URL.'?date_from=2026-09-04&date_to=2026-09-04')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonStructure(['meta' => ['per_page']])
            ->assertJsonCount(2, 'data');
        $this->assertSame([$second, $first], collect($response->json('data'))->pluck('id')->all());
        $this->getJson(self::URL.'?date_from=2026-09-04&date_to=2026-09-04&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $first);

        $this->getJson(self::URL.'?buscar=OPERACION-ALFA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first);
        $this->getJson(self::URL.'?buscar=20111111111')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?buscar=EL%20SOL')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?buscar[]=sol&date_from=no-fecha&page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['buscar', 'date_from', 'page']);
    }

    public function test_repeated_uuid_creates_only_one_payment(): void
    {
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('data.id');

        $this->postJson(self::URL, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('pagos_despacho_productos', 1);
        $this->assertDatabaseCount('pagos', 1);
        $this->assertSummary($this->client, '12.34', '-12.34');
    }

    public function test_uuid_retries_after_edit_or_delete_cannot_recreate_or_overwrite_a_payment(): void
    {
        $original = $this->payload();
        $id = (int) $this->postJson(self::URL, $original)->assertCreated()->json('data.id');
        $changed = $original;
        $changed['importe'] = '30.00';
        unset($changed['idempotency_key']);
        $this->putJson(self::URL.'/'.$id, $changed)->assertOk();

        $this->postJson(self::URL, $original)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.amount', '30.00');
        $this->postJson(self::URL, [...$original, 'importe' => '40.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertSummary($this->client, '30.00', '-30.00');

        $this->deleteJson(self::URL.'/'.$id)->assertSuccessful();
        $paymentCount = DB::table('pagos')->count();
        $this->postJson(self::URL, $original)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('pagos', $paymentCount);
        $this->assertDatabaseCount('pagos_despacho_productos', 1);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSummary($this->client, '0.00', '0.00');
    }

    public function test_general_finance_still_requires_the_account_and_method_reference(): void
    {
        $this->grantFinanceAccess();
        $account = $this->createAccount($this->user, 'Cuenta finanzas');

        $this->postJson('/api/v1/finanzas/movimientos', $this->payload([
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'referencia' => 'OP-100',
        ]))->assertUnprocessable()->assertJsonStructure(['errors']);
        $this->postJson('/api/v1/finanzas/movimientos', $this->payload([
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cuenta_destino_id' => $account->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('referencia');
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_general_finance_cannot_edit_or_void_a_product_dispatch_payment(): void
    {
        $id = (int) $this->postJson(self::URL, $this->payload())->assertCreated()->json('data.id');
        $paymentId = $this->paymentId($id);
        $this->grantFinanceAccess();

        $this->putJson('/api/v1/finanzas/movimientos/'.$paymentId, [
            'fecha_hora' => '2026-09-04 10:45:00',
            'referencia' => 'NO-CAMBIAR',
        ])->assertUnprocessable()->assertJsonValidationErrors('movimiento');
        $this->postJson('/api/v1/finanzas/movimientos/'.$paymentId.'/anular', [
            'motivo' => 'Intento desde finanzas',
        ])->assertUnprocessable()->assertJsonValidationErrors('movimiento');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.date_time', '2026-09-04T09:15')
            ->assertJsonPath('data.0.reference', null);
        $this->assertDatabaseCount('pagos', 1);
        $this->assertSummary($this->client, '12.34', '-12.34');
    }

    public function test_editing_all_payment_fields_and_deleting_restore_applied_document_balances(): void
    {
        $otherClient = $this->createClient($this->user, 'CLIENTE LA LUNA', '20222222222');
        $firstDocument = $this->createDocument($this->client, '100.00');
        $secondDocument = $this->createDocument($otherClient, '80.00');
        $id = (int) $this->postJson(self::URL, $this->payload(['importe' => '40.00']))
            ->assertCreated()->json('data.id');
        $originalPaymentId = $this->paymentId($id);
        $this->applyPayment($originalPaymentId, $firstDocument, '20.00');
        $this->assertSummary($this->client, '40.00', '60.00');

        $account = $this->createAccount($this->user, 'Cuenta nueva');
        $cashMethod = (int) MetodoPago::query()->where('codigo', MetodoPago::CODE_CASH)->value('id');
        $updated = $this->payload([
            'cliente_id' => $otherClient->id,
            'importe' => '25.00',
            'metodo_pago_id' => $cashMethod,
            'cuenta_destino_id' => $account->id,
            'fecha_hora' => '2026-09-03T11:35',
            'referencia' => 'CORREGIDO-123',
            'observaciones' => 'Cliente e importe corregidos',
        ]);
        unset($updated['idempotency_key']);

        $this->putJson(self::URL.'/'.$id, $updated)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.client.id', $otherClient->id)
            ->assertJsonPath('data.amount', '25.00')
            ->assertJsonPath('data.payment_method.id', $cashMethod)
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.date_time', '2026-09-03T11:35')
            ->assertJsonPath('data.reference', 'CORREGIDO-123')
            ->assertJsonPath('data.notes', 'Cliente e importe corregidos');

        $this->assertSummary($this->client, '0.00', '100.00');
        $this->assertSummary($otherClient, '25.00', '55.00');
        $this->assertDatabaseHas('comprobantes', [
            'id' => $firstDocument->id,
            'saldo_pendiente' => 100,
            'estado' => Comprobante::STATUS_PENDING,
        ]);

        $this->applyPayment($this->paymentId($id), $secondDocument, '25.00');
        $this->deleteJson(self::URL.'/'.$id)->assertSuccessful();

        $this->assertSummary($otherClient, '0.00', '80.00');
        $this->assertDatabaseHas('comprobantes', [
            'id' => $secondDocument->id,
            'saldo_pendiente' => 80,
            'estado' => Comprobante::STATUS_PENDING,
        ]);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(self::URL.'/'.$id, $updated)->assertUnprocessable();
    }

    public function test_update_can_remove_the_reference_and_destination_account(): void
    {
        $account = $this->createAccount($this->user, 'Cuenta opcional');
        $id = (int) $this->postJson(self::URL, $this->payload([
            'cuenta_destino_id' => $account->id,
            'referencia' => 'TRANS-001',
        ]))->assertCreated()->json('data.id');
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        $this->putJson(self::URL.'/'.$id, $payload)
            ->assertOk()
            ->assertJsonPath('data.reference', null)
            ->assertJsonPath('data.account', null)
            ->assertJsonPath('data.payment_method.id', $this->methodId);
        $this->assertSummary($this->client, '12.34', '-12.34');
    }

    public function test_editing_date_and_notes_preserves_existing_payment_applications(): void
    {
        $document = $this->createDocument($this->client, '100.00');
        $payload = $this->payload(['importe' => '40.00']);
        $id = (int) $this->postJson(self::URL, $payload)->assertCreated()->json('data.id');
        $paymentId = $this->paymentId($id);
        $this->applyPayment($paymentId, $document, '20.00');
        $payload['fecha_hora'] = '2026-09-02T10:20';
        $payload['observaciones'] = 'Fecha corregida';
        unset($payload['idempotency_key']);

        $this->putJson(self::URL.'/'.$id, $payload)
            ->assertOk()
            ->assertJsonPath('data.date_time', '2026-09-02T10:20')
            ->assertJsonPath('data.notes', 'Fecha corregida')
            ->assertJsonPath('data.reference', null);

        $this->assertSame($paymentId, $this->paymentId($id));
        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $document->id,
            'importe_aplicado' => 20,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document->id,
            'saldo_pendiente' => 80,
            'estado' => Comprobante::STATUS_PARTIAL,
        ]);
        $this->assertSummary($this->client, '40.00', '60.00');
    }

    public function test_failed_financial_edit_rolls_back_the_reversal_and_preserves_applications(): void
    {
        $document = $this->createDocument($this->client, '100.00');
        $id = (int) $this->postJson(self::URL, $this->payload(['importe' => '40.00']))
            ->assertCreated()->json('data.id');
        $paymentId = $this->paymentId($id);
        $this->applyPayment($paymentId, $document, '20.00');
        $foreign = $this->createAccount(User::factory()->create(), 'Cuenta ajena');
        $payload = $this->payload(['importe' => '50.00', 'cuenta_destino_id' => $foreign->id]);
        unset($payload['idempotency_key']);

        $this->putJson(self::URL.'/'.$id, $payload)->assertUnprocessable();

        $this->assertSame($paymentId, $this->paymentId($id));
        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'estado' => Pago::STATUS_REGISTERED]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document->id,
            'saldo_pendiente' => 80,
            'estado' => Comprobante::STATUS_PARTIAL,
        ]);
        $this->assertSummary($this->client, '40.00', '60.00');
    }

    public function test_payments_from_other_companies_and_branches_cannot_be_listed_edited_or_deleted(): void
    {
        $ownId = $this->postJson(self::URL, $this->payload())->assertCreated()->json('data.id');
        $otherBranchId = $this->createBranch($this->user, 'OTRA');
        $this->user->update(['sucursal_id' => $otherBranchId]);
        $otherBranchPayment = $this->postJson(self::URL, $this->payload())->assertCreated()->json('data.id');
        $this->user->update(['sucursal_id' => $this->branchId]);

        $foreignUser = User::factory()->create();
        $foreignUser->update(['sucursal_id' => $this->createBranch($foreignUser, 'AJENA')]);
        $this->grantModules($foreignUser, ['MODULO_DESPACHO_PRODUCTOS', 'PRODUCTOS_DESPACHO_DESPACHAR']);
        $foreignClient = $this->createClient($foreignUser, 'CLIENTE AJENO', '20333333333');
        Sanctum::actingAs($foreignUser, ['api']);
        $foreignPayment = $this->postJson(self::URL, $this->payload(['cliente_id' => $foreignClient->id]))
            ->assertCreated()->json('data.id');
        Sanctum::actingAs($this->user, ['api']);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownId);
        foreach ([$otherBranchPayment, $foreignPayment] as $protectedId) {
            $this->putJson(self::URL.'/'.$protectedId, $this->payload())->assertNotFound();
            $this->deleteJson(self::URL.'/'.$protectedId)->assertNotFound();
        }
    }

    public function test_registration_rejects_internal_inactive_foreign_and_provider_only_clients(): void
    {
        $internal = $this->createClient($this->user, 'INTERNO', '20444444444');
        $internal->update(['es_cliente_interno' => true]);
        $inactive = $this->createClient($this->user, 'INACTIVO', '20555555555');
        $inactive->update(['estado' => Tercero::STATUS_INACTIVE]);
        $foreign = $this->createClient(User::factory()->create(), 'AJENO', '20666666666');
        $provider = $this->createClient($this->user, 'PROVEEDOR', '20777777777');
        $provider->roles()->update(['rol' => TerceroRole::PROVIDER]);

        foreach ([$internal, $inactive, $foreign, $provider] as $client) {
            $this->postJson(self::URL, $this->payload(['cliente_id' => $client->id]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cliente_id');
        }
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_registration_rejects_invalid_methods_accounts_amounts_and_non_collection_fields(): void
    {
        $external = $this->createAccount($this->user, 'Cuenta de proveedor', EntidadFinanciera::TYPE_EXTERNAL);
        $foreign = $this->createAccount(User::factory()->create(), 'Cuenta ajena');
        $inactive = $this->createAccount($this->user, 'Cuenta desactivada');
        $inactive->update(['estado' => CuentaFinanciera::STATUS_INACTIVE]);
        $wrongCurrency = $this->createAccount($this->user, 'Cuenta en dólares');
        $wrongCurrency->update(['moneda' => 'USD']);

        foreach ([$external, $foreign, $inactive, $wrongCurrency] as $account) {
            $this->postJson(self::URL, $this->payload(['cuenta_destino_id' => $account->id]))
                ->assertUnprocessable()
                ->assertJsonStructure(['errors']);
        }

        MetodoPago::query()->whereKey($this->methodId)->update(['estado' => MetodoPago::STATUS_INACTIVE]);
        $this->postJson(self::URL, $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metodo_pago_id');
        MetodoPago::query()->whereKey($this->methodId)->update(['estado' => MetodoPago::STATUS_ACTIVE]);

        foreach (['0.00', '-1.00', '12.345', 'no-numero'] as $amount) {
            $this->postJson(self::URL, $this->payload(['importe' => $amount]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('importe');
        }
        $this->postJson(self::URL, $this->payload([
            'idempotency_key' => 'no-uuid',
            'fecha_hora' => 'no-fecha',
            'metodo_pago_id' => null,
            'referencia' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'idempotency_key', 'fecha_hora', 'metodo_pago_id', 'referencia',
        ]);
        $this->postJson(self::URL, $this->payload([
            'idempotency_key' => [],
            'importe' => [],
            'moneda' => [],
            'referencia' => [],
            'observaciones' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'idempotency_key', 'importe', 'moneda', 'referencia', 'observaciones',
        ]);
        $this->postJson(self::URL, $this->payload([
            'tipo' => Pago::TYPE_PROVIDER_PAYMENT,
            'proveedor_id' => $this->client->id,
            'cuenta_origen_id' => $inactive->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['tipo', 'proveedor_id', 'cuenta_origen_id']);
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_credit_reduces_prior_debt_and_excess_is_available_without_cash_or_double_counting(): void
    {
        $debtId = (int) $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '100.00'))
            ->assertCreated()->json('data.id');
        $documentId = (int) DB::table('ajustes_despacho_productos')->where('id', $debtId)->value('comprobante_id');
        $creditPayload = $this->adjustmentPayload('CREDIT', '40.00');
        $creditId = (int) $this->postJson(self::URL.'/ajustes', $creditPayload)
            ->assertCreated()->assertJsonPath('data.kind', 'CREDIT')->json('data.id');
        $this->getJson($this->accountUrl())->assertOk()
            ->assertJsonPath('summary.balance', '60.00')->assertJsonPath('summary.debt', '60.00')
            ->assertJsonPath('summary.credit', '0.00')->assertJsonPath('summary.transaction_count', 2)
            ->assertJsonPath('summary.payments_total', '40.00');
        $this->assertSummary($this->client, '40.00', '60.00');
        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'saldo_pendiente' => 60, 'estado' => 'PARCIAL']);
        $paymentId = (int) DB::table('ajustes_despacho_productos')->where('id', $creditId)->value('pago_id');
        $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'tipo' => Pago::TYPE_CUSTOMER_DISCOUNT,
            'direccion' => Pago::DIRECTION_NO_FLOW, 'cuenta_destino_id' => null, 'cuenta_origen_id' => null]);
        $this->assertDatabaseHas('pago_aplicaciones', ['pago_id' => $paymentId, 'comprobante_id' => $documentId, 'importe_aplicado' => 40]);

        $this->putJson(self::URL.'/ajustes/'.$creditId, $this->adjustmentPayload('CREDIT', '150.00'))
            ->assertOk()->assertJsonPath('data.id', $creditId);
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '-50.00')
            ->assertJsonPath('summary.credit', '50.00')->assertJsonPath('summary.debt', '0.00')
            ->assertJsonPath('summary.payments_total', '150.00')->assertJsonPath('summary.transaction_count', 2);
        $this->assertSummary($this->client, '150.00', '-50.00');
        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'saldo_pendiente' => 0, 'estado' => 'PAGADO']);
        $this->deleteJson(self::URL.'/ajustes/'.$debtId)->assertUnprocessable();
        $this->deleteJson(self::URL.'/ajustes/'.$creditId)->assertOk();
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '100.00');
        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'saldo_pendiente' => 100, 'estado' => 'PENDIENTE']);
        $this->deleteJson(self::URL.'/ajustes/'.$debtId)->assertOk();
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '0.00')->assertJsonCount(0, 'data');
        $this->assertSummary($this->client, '0.00', '0.00');
    }

    public function test_account_balance_is_unfiltered_and_credit_without_sales_is_retained_for_future_debts(): void
    {
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '100.00', ['fecha_hora' => '2026-01-02T09:00']))
            ->assertCreated();
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.credit', '100.00');
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '70.00', ['fecha_hora' => '2026-09-01T11:00']))
            ->assertCreated();
        $this->postJson(self::URL, $this->payload(['importe' => '10.00']))->assertCreated();
        $this->getJson($this->accountUrl().'&date_from=2026-09-01&date_to=2026-09-30&per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.balance', '-40.00')->assertJsonPath('summary.transaction_count', 3);
        $this->getJson($this->accountUrl().'&buscar=NO-EXISTE')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.balance', '-40.00');
        $this->assertSummary($this->client, '110.00', '-40.00');
        config(['access_modules.modules.MODULO_DESPACHO_PRODUCTOS.technical_permissions' => array_values(array_diff(
            config('access_modules.modules.MODULO_DESPACHO_PRODUCTOS.technical_permissions'),
            ['PRODUCTOS_DESPACHO_TICKETS_GESTIONAR'],
        ))]);
        $this->getJson(self::URL.'/catalogo')->assertOk()->assertJsonPath('data.clients.0.id', $this->client->id)
            ->assertJsonPath('data.default_currency', 'PEN')->assertJsonPath('data.currencies.0', 'PEN');
        $this->getJson('/api/v1/despacho-productos/estado-cuenta/catalogo')->assertForbidden();
    }

    public function test_adjustment_retries_preserve_the_original_id_after_edit_or_delete_and_metadata_keeps_allocations(): void
    {
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '90.00'))->assertCreated();
        $payload = $this->adjustmentPayload('CREDIT', '30.00');
        $id = (int) $this->postJson(self::URL.'/ajustes', $payload)->assertCreated()->json('data.id');
        $paymentId = (int) DB::table('ajustes_despacho_productos')->where('id', $id)->value('pago_id');
        $this->postJson(self::URL.'/ajustes', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson(self::URL.'/ajustes/'.$id, [...$payload, 'fecha_hora' => '2026-09-02T14:45', 'observaciones' => 'Corrección de fecha'])
            ->assertOk()->assertJsonPath('data.date_time', '2026-09-02T14:45');
        $this->assertSame($paymentId, (int) DB::table('ajustes_despacho_productos')->where('id', $id)->value('pago_id'));
        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseCount('pago_aplicaciones', 1);
        $this->postJson(self::URL.'/ajustes', $payload)->assertOk()->assertJsonPath('data.date_time', '2026-09-02T14:45');
        $this->postJson(self::URL.'/ajustes', [...$payload, 'importe' => '31.00'])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->deleteJson(self::URL.'/ajustes/'.$id)->assertOk();
        $count = DB::table('pagos')->count();
        $this->postJson(self::URL.'/ajustes', $payload)->assertOk()->assertJsonPath('data.state', 'ANULADO');
        $this->assertDatabaseCount('pagos', $count);
        $this->assertDatabaseCount('ajustes_despacho_productos', 2);
        $this->putJson(self::URL.'/ajustes/'.$id, $payload)->assertUnprocessable();
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '90.00');
    }

    public function test_adjustments_and_account_history_are_scoped_to_company_branch_and_currency(): void
    {
        $id = (int) $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '20.00'))->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '40.00', ['moneda' => 'USD']))->assertCreated();
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '-20.00');
        $this->getJson($this->accountUrl('USD'))->assertOk()->assertJsonPath('summary.balance', '40.00');
        $this->user->update(['sucursal_id' => $this->createBranch($this->user, 'AJUSTE-OTRA')]);
        $this->getJson($this->accountUrl())->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.balance', '0.00');
        $this->getJson($this->accountUrl('USD'))->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(self::URL.'/ajustes/'.$id, $this->adjustmentPayload('CREDIT', '25.00'))->assertNotFound();
        $this->deleteJson(self::URL.'/ajustes/'.$id)->assertNotFound();
        $foreignClient = $this->createClient(User::factory()->create(), 'AJENO AJUSTES', '20999999999');
        $this->getJson(self::URL.'/cuenta?cliente_id='.$foreignClient->id.'&moneda=PEN')->assertUnprocessable();
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '20.00', ['cliente_id' => $foreignClient->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '0.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('importe');
    }

    public function test_historical_manual_debt_is_editable_from_payments_and_inactive_history_remains_visible(): void
    {
        $document = $this->createDocument($this->client, '100.00');
        $document->update(['tipo_documento' => 'SALDO_ANTERIOR', 'origen_codigo' => 'MANUAL',
            'origen_clave' => 'DEUDA_ANTERIOR_CLIENTE:'.Str::uuid()]);
        DB::table('comprobante_detalles')->insert(['comprobante_id' => $document->id, 'descripcion' => 'Deuda histórica',
            'subtotal' => '100.00', 'created_at' => now()]);
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('data.0.kind', 'PRIOR_DEBT')
            ->assertJsonPath('data.0.edit_url', '/despacho-productos/pagos/deudas/'.$document->id);
        $this->putJson(self::URL.'/deudas/'.$document->id, $this->adjustmentPayload('PRIOR_DEBT', '80.00'))
            ->assertOk()->assertJsonPath('data.amount', '80.00');
        $this->client->update(['estado' => Tercero::STATUS_INACTIVE]);
        $this->getJson(self::URL.'/catalogo')->assertOk()->assertJsonPath('data.clients.0.id', $this->client->id);
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '80.00');
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '20.00'))->assertUnprocessable();
        $this->deleteJson(self::URL.'/deudas/'.$document->id)->assertOk();
    }

    public function test_adjustment_correction_moves_client_and_invalid_edits_roll_back_allocations(): void
    {
        $debtId = $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '100.00'))->assertCreated()->json('data.id');
        $id = $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '40.00'))->assertCreated()->json('data.id');
        $documentId = DB::table('ajustes_despacho_productos')->where('id', $debtId)->value('comprobante_id');
        $this->putJson(self::URL.'/ajustes/'.$debtId, $this->adjustmentPayload('PRIOR_DEBT', '20.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('importe');
        $other = $this->createClient($this->user, 'OTRO AJUSTE', '20888888888');
        $this->putJson(self::URL.'/ajustes/'.$id, $this->adjustmentPayload('CREDIT', '25.00', ['cliente_id' => $other->id]))
            ->assertOk()->assertJsonPath('data.client.id', $other->id);
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '100.00');
        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'saldo_pendiente' => 100]);
        $this->getJson(self::URL.'/cuenta?cliente_id='.$other->id.'&moneda=PEN')->assertOk()->assertJsonPath('summary.credit', '25.00');
    }

    public function test_credit_applied_to_shared_legacy_debt_is_visible_in_other_branch_without_exposing_unapplied_excess(): void
    {
        $document = $this->createDocument($this->client, '100.00');
        $document->update(['tipo_documento' => 'SALDO_ANTERIOR', 'origen_codigo' => 'MANUAL',
            'origen_clave' => 'DEUDA_ANTERIOR_CLIENTE:'.Str::uuid()]);
        DB::table('comprobante_detalles')->insert(['comprobante_id' => $document->id, 'descripcion' => 'Deuda compartida',
            'subtotal' => '100.00', 'created_at' => now()]);
        $creditId = $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '140.00'))->assertCreated()->json('data.id');
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.credit', '40.00');
        $this->user->update(['sucursal_id' => $this->createBranch($this->user, 'COMPARTIDA')]);
        $response = $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.balance', '0.00')
            ->assertJsonPath('summary.payments_total', '100.00')->assertJsonPath('summary.transaction_count', 2);
        $external = collect($response->json('data'))->firstWhere('kind', 'APPLIED_PAYMENT');
        $this->assertSame('100.00', $external['amount']);
        $this->assertFalse($external['can_edit']);
        $this->assertNull($external['origin_url']);
        $this->assertStringContainsString('otra sucursal', $external['action_reason']);
        $this->deleteJson(self::URL.'/ajustes/'.$creditId)->assertNotFound();
    }

    public function test_finance_cannot_bypass_adjustment_ownership_and_debt_notes_respect_storage_limits(): void
    {
        $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('PRIOR_DEBT', '10.00', ['observaciones' => str_repeat('a', 251)]))
            ->assertUnprocessable()->assertJsonValidationErrors('observaciones');
        $id = $this->postJson(self::URL.'/ajustes', $this->adjustmentPayload('CREDIT', '10.00'))->assertCreated()->json('data.id');
        $paymentId = DB::table('ajustes_despacho_productos')->where('id', $id)->value('pago_id');
        $this->grantFinanceAccess();
        $this->postJson('/api/v1/finanzas/movimientos/'.$paymentId.'/anular', ['motivo' => 'Intento ajeno al módulo'])
            ->assertUnprocessable()->assertJsonValidationErrors('movimiento');
        $this->putJson('/api/v1/finanzas/movimientos/'.$paymentId, ['observaciones' => 'No cambiar', 'fecha_hora' => '2026-09-04 10:00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('movimiento');
        $this->getJson($this->accountUrl())->assertOk()->assertJsonPath('summary.credit', '10.00');
    }

    public function test_paginated_account_does_not_query_editable_details_for_the_entire_history(): void
    {
        $this->postJson(self::URL, $this->payload())->assertCreated();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->accountUrl().'&per_page=1')->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 30) as $index) {
            $this->postJson(self::URL, $this->payload(['observaciones' => 'Operación '.$index]))->assertCreated();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->accountUrl().'&per_page=1')->assertOk()->assertJsonPath('meta.total', 31);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($smallCount + 3, $largeCount);
    }

    private function adjustmentPayload(string $kind, string $amount, array $overrides = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'cliente_id' => $this->client->id,
            'tipo' => $kind, 'importe' => $amount, 'moneda' => 'PEN', 'fecha_hora' => '2026-09-04T09:15',
            'observaciones' => 'Saldo inicial de prueba'], $overrides);
    }

    private function accountUrl(string $currency = 'PEN'): string
    {
        return self::URL.'/cuenta?cliente_id='.$this->client->id.'&moneda='.$currency;
    }

    private function createBranch(User $user, string $code): int
    {
        return (int) DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => $code,
            'nombre' => 'Sucursal '.$code,
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClient(User $user, string $name, string $document): Tercero
    {
        $client = Tercero::query()->create([
            'empresa_id' => $user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Prueba 123',
            'es_cliente_interno' => false,
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        $client->roles()->create(['rol' => TerceroRole::CLIENT]);

        return $client;
    }

    private function createAccount(User $user, string $name, string $type = EntidadFinanciera::TYPE_OWN): CuentaFinanciera
    {
        $entity = EntidadFinanciera::query()->create([
            'empresa_id' => $user->empresa_id,
            'tipo' => $type,
            'razon_social' => $name,
            'estado' => EntidadFinanciera::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        return CuentaFinanciera::query()->create([
            'entidad_financiera_id' => $entity->id,
            'tipo' => CuentaFinanciera::TYPE_BANK,
            'alias' => $name,
            'moneda' => 'PEN',
            'estado' => CuentaFinanciera::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
    }

    private function createDocument(Tercero $client, string $amount): Comprobante
    {
        return Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'CXC-PAGO-'.$client->id,
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-09-01',
            'fecha_vencimiento' => '2026-09-30',
            'moneda' => 'PEN',
            'subtotal' => $amount,
            'impuesto' => '0.00',
            'total' => $amount,
            'saldo_pendiente' => $amount,
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
    }

    private function applyPayment(int $paymentId, Comprobante $document, string $amount): void
    {
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $document->id,
            'lado' => 'CXC',
            'importe_aplicado' => $amount,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        $document->update([
            'saldo_pendiente' => bcsub($document->total, $amount, 2),
            'estado' => Comprobante::STATUS_PARTIAL,
        ]);
    }

    private function paymentId(int $id): int
    {
        return (int) DB::table('pagos_despacho_productos')->where('id', $id)->value('pago_id');
    }

    private function assertSummary(Tercero $client, string $payments, string $pending): void
    {
        $summary = app(FinancialCounterpartySummaryService::class)->forCustomer(
            (int) $this->user->empresa_id,
            (int) $client->id,
        );
        $this->assertSame($payments, $summary['payments']);
        $this->assertSame($pending, $summary['pending']);
    }

    private function grantFinanceAccess(): void
    {
        $this->grantModules($this->user, [
            'MODULO_FINANZAS',
            'PAGOS_REGISTRAR',
            'PAGOS_ANULAR',
        ], 'FINANZAS_TEST');
        $this->user->unsetRelation('roles');
        Sanctum::actingAs($this->user, ['api']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $this->client->id,
            'importe' => '12.34',
            'metodo_pago_id' => $this->methodId,
            'fecha_hora' => '2026-09-04T09:15',
            'moneda' => 'PEN',
            'cuenta_destino_id' => null,
            'referencia' => null,
            'observaciones' => null,
        ], $overrides);
    }
}
