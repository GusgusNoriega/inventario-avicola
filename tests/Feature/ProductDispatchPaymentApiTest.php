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
