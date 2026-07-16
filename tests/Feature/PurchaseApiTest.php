<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'COMPRAS_TEST',
            'nombre' => 'Compras test',
        ]);
        $this->role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id')
        );
        $this->user->roles()->attach($this->role);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_credit_purchase_creates_an_independent_payable_and_is_strictly_idempotent(): void
    {
        $provider = $this->provider('PROVEEDOR CREDITO', '20100000001');
        $type = $this->chickenType();
        $key = (string) Str::uuid();
        $payload = $this->purchasePayload($provider, $type, 'CREDITO', $key);

        $response = $this->postJson('/api/v1/compras', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.estado', 'PENDIENTE')
            ->assertJsonPath('data.estado_compra', 'REGISTRADA')
            ->assertJsonPath('data.numero_documento', 'F-001')
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.impuesto', '18.00')
            ->assertJsonPath('data.total', '118.00')
            ->assertJsonPath('data.saldo_pendiente', '118.00');
        $purchaseId = $response->json('data.id');
        $documentId = $response->json('data.comprobante.id');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'tercero_id' => $provider,
            'operacion' => 'COMPRA',
            'naturaleza' => 'CARGO',
            'origen_clave' => "COMPRA:REGISTRO:{$purchaseId}",
            'total' => 118,
            'saldo_pendiente' => 118,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('compra_detalles', [
            'compra_id' => $purchaseId,
            'peso_kg' => 10,
            'precio_kg' => 10,
            'subtotal' => 100,
        ]);
        $this->assertDatabaseCount('pagos', 0);

        $this->postJson('/api/v1/compras', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('data.id', $purchaseId);
        $this->assertDatabaseCount('compras', 1);
        $this->assertDatabaseCount('comprobantes', 1);

        $changed = $payload;
        $changed['detalles'][0]['precio_kg'] = '11.0000';
        $this->postJson('/api/v1/compras', $changed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->getJson('/api/v1/compras?estado=PENDIENTE&condicion=CREDITO')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('resumen.total', '118.00')
            ->assertJsonPath('resumen.contado', '0.00')
            ->assertJsonPath('resumen.credito', '118.00')
            ->assertJsonPath('resumen.pendiente', '118.00');

        $duplicateDocument = $payload;
        $duplicateDocument['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/compras', $duplicateDocument)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');

        DB::table('terceros')->where('id', $provider)->update([
            'numero_documento' => '20999999999',
            'nombre_razon_social' => 'PROVEEDOR RENOMBRADO',
        ]);
        $this->getJson("/api/v1/compras/{$purchaseId}")
            ->assertOk()
            ->assertJsonPath('data.proveedor.numero_documento', '20100000001')
            ->assertJsonPath('data.proveedor.nombre', 'PROVEEDOR CREDITO');
        $this->getJson('/api/v1/compras?buscar=20100000001')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $purchaseId);
    }

    public function test_cash_purchase_creates_and_fully_applies_the_initial_provider_payment(): void
    {
        $provider = $this->provider('PROVEEDOR CONTADO', '20100000002');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA PROPIA');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'CAJA PROVEEDOR');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $payload = $this->purchasePayload(
            $provider,
            $type,
            'CONTADO',
            (string) Str::uuid(),
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        );

        $response = $this->postJson('/api/v1/compras', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'PAGADO')
            ->assertJsonPath('data.saldo_pendiente', '0.00')
            ->assertJsonPath('data.pago_inicial.importe', '118.00')
            ->assertJsonPath('data.pago_inicial.estado', 'REGISTRADO');
        $purchaseId = $response->json('data.id');
        $documentId = $response->json('data.comprobante.id');
        $paymentId = $response->json('data.pago_inicial.id');

        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXP',
            'importe_aplicado' => 118,
        ]);
        $this->assertDatabaseHas('compras', [
            'id' => $purchaseId,
            'pago_inicial_id' => $paymentId,
            'condicion' => 'CONTADO',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '82.00');
    }

    public function test_credit_purchase_cannot_be_voided_while_it_has_an_active_payment(): void
    {
        $provider = $this->provider('PROVEEDOR CON ABONO', '20100000003');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'BANCO PROPIO');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'BANCO PROVEEDOR');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider,
            'cuenta_origen_id' => $ownAccount,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '50.00',
            'referencia' => 'ABONO-50',
            'aplicaciones' => [[
                'lado' => 'CXP',
                'comprobante_id' => $purchase['comprobante']['id'],
                'importe_aplicado' => '50.00',
            ]],
        ])->assertCreated();

        $this->postJson("/api/v1/compras/{$purchase['id']}/anular", [
            'motivo' => 'Factura cancelada',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('compra');

        $this->assertDatabaseHas('compras', ['id' => $purchase['id'], 'estado' => 'REGISTRADA']);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $purchase['comprobante']['id'],
            'estado' => 'PARCIAL',
            'saldo_pendiente' => 68,
        ]);
    }

    public function test_voiding_a_cash_purchase_reverses_only_its_initial_payment_and_restores_cash(): void
    {
        $provider = $this->provider('PROVEEDOR ANULACION', '20100000004');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA ANULACION');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'DESTINO ANULACION');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CONTADO',
            (string) Str::uuid(),
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        ))->assertCreated()->json('data');

        $this->postJson("/api/v1/finanzas/movimientos/{$purchase['pago_inicial']['id']}/anular", [
            'motivo' => 'Intento de anular solo el pago inicial',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('movimiento');
        $this->assertDatabaseHas('compras', [
            'id' => $purchase['id'],
            'estado' => 'REGISTRADA',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $purchase['pago_inicial']['id'],
            'estado' => 'REGISTRADO',
        ]);

        $response = $this->postJson("/api/v1/compras/{$purchase['id']}/anular", [
            'motivo' => 'Compra ingresada por duplicado',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('data.saldo_pendiente', '0.00')
            ->assertJsonPath('data.comprobante.saldo_pendiente', '0.00')
            ->assertJsonPath('data.estado_compra', 'ANULADA')
            ->assertJsonPath('data.pago_inicial.estado', 'ANULADO');
        $reverseId = $response->json('reversa_id');

        $this->assertNotNull($reverseId);
        $this->assertDatabaseHas('pagos', [
            'id' => $reverseId,
            'reversa_de_pago_id' => $purchase['pago_inicial']['id'],
            'estado' => 'REGISTRADO',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '200.00');

        $this->postJson("/api/v1/compras/{$purchase['id']}/anular", [
            'motivo' => 'Compra ingresada por duplicado',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('reversa_id', $reverseId);
        $this->assertDatabaseCount('pagos', 3);
    }

    public function test_cash_purchase_with_insufficient_balance_rolls_back_every_purchase_record(): void
    {
        $provider = $this->provider('PROVEEDOR SIN SALDO', '20100000005');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA SIN SALDO');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'DESTINO SIN SALDO');
        $this->openingBalance($ownAccount, '50.00');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');

        $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CONTADO',
            (string) Str::uuid(),
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('importe');

        $this->assertDatabaseCount('compras', 0);
        $this->assertDatabaseCount('compra_detalles', 0);
        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_direct_customer_payment_reduces_a_purchase_payable_and_receivable_without_touching_own_cash(): void
    {
        $provider = $this->provider('PROVEEDOR PAGO DIRECTO', '20100000006');
        $client = $this->client('CLIENTE PAGO DIRECTO', '10100006');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA SIN MOVIMIENTO');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'CUENTA PAGO DIRECTO');
        $this->openingBalance($ownAccount, '100.00');
        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');
        $receivable = $this->financialDocument('VENTA', $client, '118.00', 'CXC-COMPRA-DIRECTO');
        $method = DB::table('metodos_pago')->where('codigo', 'DEPOSITO')->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_DIRECTO',
            'cliente_id' => $client,
            'proveedor_id' => $provider,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '50.00',
            'referencia' => 'DIRECTO-COMPRA-50',
            'aplicaciones' => [
                [
                    'lado' => 'CXC',
                    'comprobante_id' => $receivable,
                    'importe_aplicado' => '50.00',
                ],
                [
                    'lado' => 'CXP',
                    'comprobante_id' => $purchase['comprobante']['id'],
                    'importe_aplicado' => '50.00',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('comprobantes', [
            'id' => $receivable,
            'estado' => 'PARCIAL',
            'saldo_pendiente' => 68,
        ]);
        $this->getJson("/api/v1/compras/{$purchase['id']}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'PARCIAL')
            ->assertJsonPath('data.saldo_pendiente', '68.00');
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownAccount)
            ->assertJsonPath('data.0.saldo', '100.00');
    }

    public function test_cash_purchase_requires_finance_module_and_purchases_are_tenant_scoped(): void
    {
        $provider = $this->provider('PROVEEDOR PERMISOS', '20100000007');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA PERMISOS');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'DESTINO PERMISOS');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $financeModule = Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id');
        $this->role->permissions()->detach($financeModule);

        $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CONTADO',
            (string) Str::uuid(),
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        ))->assertForbidden();
        $this->assertDatabaseCount('compras', 0);

        $this->role->permissions()->attach($financeModule);

        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');

        $otherUser = User::factory()->create();
        $otherRole = Role::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'COMPRAS_OTRA_EMPRESA',
            'nombre' => 'Compras otra empresa',
        ]);
        $otherRole->permissions()->attach(
            Permission::query()->where('codigo', 'COMPRAS_VER')->value('id')
        );
        $otherUser->roles()->attach($otherRole);
        Sanctum::actingAs($otherUser, ['api']);

        $this->getJson("/api/v1/compras/{$purchase['id']}")->assertNotFound();
    }

    public function test_purchase_list_keeps_currency_totals_separate(): void
    {
        $provider = $this->provider('PROVEEDOR MULTIMONEDA', '20100000009');
        $type = $this->chickenType();
        $penPurchase = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $penPurchase['numero_documento'] = 'F-PEN-001';
        $usdPurchase = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $usdPurchase['numero_documento'] = 'F-USD-001';
        $usdPurchase['moneda'] = 'USD';

        $this->postJson('/api/v1/compras', $penPurchase)->assertCreated();
        $this->postJson('/api/v1/compras', $usdPurchase)->assertCreated();

        $this->getJson('/api/v1/compras')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('resumen.moneda', 'PEN')
            ->assertJsonPath('resumen.total', '118.00')
            ->assertJsonPath('data.0.moneda', 'PEN');

        $this->getJson('/api/v1/compras?moneda=USD')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('resumen.moneda', 'USD')
            ->assertJsonPath('resumen.total', '118.00')
            ->assertJsonPath('data.0.moneda', 'USD');
    }

    public function test_voided_purchase_keeps_its_document_history_and_allows_a_corrected_entry(): void
    {
        $provider = $this->provider('PROVEEDOR CORRECCION', '20100000010');
        $type = $this->chickenType();
        $payload = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $payload['numero_documento'] = 'F-CORREGIBLE-001';
        $first = $this->postJson('/api/v1/compras', $payload)
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/v1/compras/{$first['id']}/anular", [
            'motivo' => 'El importe de la factura fue capturado incorrectamente',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('data.saldo_pendiente', '0.00');
        $this->assertDatabaseHas('compras', [
            'id' => $first['id'],
            'numero_documento' => 'F-CORREGIBLE-001',
            'numero_documento_activo' => null,
            'estado' => 'ANULADA',
        ]);

        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['observaciones'] = 'Registro corregido de la compra';
        $corrected = $this->postJson('/api/v1/compras', $payload)
            ->assertCreated()
            ->assertJsonPath('data.numero_documento', 'F-CORREGIBLE-001')
            ->json('data');

        $this->assertNotSame($first['id'], $corrected['id']);
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseHas('compras', [
            'id' => $corrected['id'],
            'numero_documento_activo' => 'F-CORREGIBLE-001',
            'estado' => 'REGISTRADA',
        ]);
    }

    public function test_malformed_purchase_inputs_are_rejected_with_validation_errors(): void
    {
        $provider = $this->provider('PROVEEDOR VALIDACION', '20100000011');
        $type = $this->chickenType();

        $this->postJson('/api/v1/compras', [
            'idempotency_key' => [],
            'proveedor_id' => $provider,
            'tipo_documento' => [],
            'numero_documento' => [],
            'fecha_compra' => '2026-07-14',
            'fecha_vencimiento' => [],
            'condicion' => [],
            'moneda' => [],
            'impuesto' => [],
            'observaciones' => [],
            'detalles' => [[
                'tipo_pollo_id' => $type,
                'descripcion' => [],
                'cantidad_aves' => [],
                'peso_kg' => [],
                'precio_kg' => [],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'idempotency_key',
                'tipo_documento',
                'condicion',
                'moneda',
                'impuesto',
                'detalles.0.descripcion',
                'detalles.0.peso_kg',
                'detalles.0.precio_kg',
            ]);

        $this->getJson('/api/v1/compras?condicion[]=CREDITO&buscar[]=factura')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['condicion', 'buscar']);

        $this->postJson('/api/v1/compras/1/anular', ['motivo' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
        $this->assertDatabaseCount('compras', 0);
    }

    public function test_legacy_dispatch_payable_is_imported_without_changing_its_financial_state(): void
    {
        $provider = $this->provider('PROVEEDOR HISTORICO', '20100000008');
        $type = $this->chickenType();
        $documentId = $this->financialDocument(
            'COMPRA',
            $provider,
            '118.00',
            'CXP-HISTORICA-DESPACHO'
        );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'tipo_documento' => 'INTERNO',
            'origen_codigo' => 'TICKET',
            'origen_clave' => 'COMPRA:TICKET:9001:PROVEEDOR:'.$provider,
            'fecha_emision' => '2026-07-01',
            'fecha_vencimiento' => '2026-07-31',
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $documentId,
            'tipo_pollo_id' => $type,
            'descripcion' => 'Pollo historico del despacho',
            'cantidad_aves' => 50,
            'peso_neto_kg' => '10.000',
            'precio_kg' => '11.8000',
            'subtotal' => '118.00',
            'created_at' => now(),
        ]);

        $documentsBefore = DB::table('comprobantes')->count();
        $paymentsBefore = DB::table('pagos')->count();
        $migration = require database_path(
            'migrations/2026_07_14_000005_backfill_legacy_dispatch_purchases.php'
        );

        $migration->up();
        $migration->up();

        $purchase = DB::table('compras')->where('comprobante_id', $documentId)->first();
        $this->assertNotNull($purchase);
        $this->assertSame('LEGADO', $purchase->condicion);
        $this->assertSame('REGISTRADA', $purchase->estado);
        $this->assertNull($purchase->pago_inicial_id);
        $this->assertDatabaseCount('compras', 1);
        $this->assertDatabaseCount('compra_detalles', 1);
        $this->assertDatabaseHas('compra_detalles', [
            'compra_id' => $purchase->id,
            'tipo_pollo_id' => $type,
            'peso_kg' => 10,
            'precio_kg' => 11.8,
            'subtotal' => 118,
        ]);
        $this->assertSame($documentsBefore, DB::table('comprobantes')->count());
        $this->assertSame($paymentsBefore, DB::table('pagos')->count());
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);

        $this->getJson('/api/v1/compras?condicion=LEGADO')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $purchase->id)
            ->assertJsonPath('data.0.comprobante.id', $documentId)
            ->assertJsonPath('data.0.condicion', 'LEGADO')
            ->assertJsonPath('data.0.saldo_pendiente', '118.00')
            ->assertJsonPath('resumen.total', '118.00')
            ->assertJsonPath('resumen.sin_clasificar', '118.00')
            ->assertJsonPath('resumen.pendiente', '118.00');

        $this->postJson("/api/v1/compras/{$purchase->id}/anular", [
            'motivo' => 'No corresponde al registro actual',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('compra');
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);
    }

    private function provider(string $name, string $document): int
    {
        return $this->thirdParty('PROVEEDOR', $name, $document);
    }

    private function client(string $name, string $document): int
    {
        return $this->thirdParty('CLIENTE', $name, $document);
    }

    private function thirdParty(string $role, string $name, string $document): int
    {
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => $role === 'PROVEEDOR' ? 'RUC' : 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Direccion de prueba',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $id,
            'rol' => $role,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function chickenType(): int
    {
        return (int) DB::table('tipos_pollo')->where('codigo', 'POLLO_MUERTO')->value('id');
    }

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function purchasePayload(
        int $provider,
        int $type,
        string $condition,
        string $key,
        ?array $payment = null,
    ): array {
        $payload = [
            'idempotency_key' => $key,
            'proveedor_id' => $provider,
            'tipo_documento' => 'factura',
            'numero_documento' => 'f-001',
            'fecha_compra' => '2026-07-14',
            'fecha_vencimiento' => $condition === 'CREDITO' ? '2026-08-14' : null,
            'condicion' => $condition,
            'moneda' => 'PEN',
            'impuesto' => '18.00',
            'observaciones' => 'Compra de prueba',
            'detalles' => [[
                'tipo_pollo_id' => $type,
                'descripcion' => 'Pollo de proveedor',
                'cantidad_aves' => 50,
                'peso_kg' => '10.000',
                'precio_kg' => '10.0000',
                'subtotal' => '99999.99',
            ]],
        ];
        if ($payment !== null) {
            $payload['pago'] = $payment;
        }

        return $payload;
    }

    /** @return array{int, int} */
    private function financialAccount(string $type, ?int $provider, string $alias): array
    {
        $entity = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => $type,
            'proveedor_id' => $provider,
            'razon_social' => $alias,
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $account = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entity,
            'tipo' => 'CAJA',
            'alias' => $alias,
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$entity, $account];
    }

    private function openingBalance(int $account, string $amount): void
    {
        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'SALDO_INICIAL',
            'cuenta_destino_id' => $account,
            'moneda' => 'PEN',
            'importe' => $amount,
            'aplicaciones' => [],
        ])->assertCreated();
    }

    private function financialDocument(string $operation, int $thirdParty, string $amount, string $code): int
    {
        return DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $thirdParty,
            'operacion' => $operation,
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'INTERNO',
            'codigo' => $code,
            'origen_codigo' => 'PRUEBA',
            'origen_clave' => 'PRUEBA:'.$code,
            'fecha_emision' => '2026-07-14',
            'fecha_vencimiento' => '2026-07-14',
            'moneda' => 'PEN',
            'subtotal' => $amount,
            'impuesto' => 0,
            'total' => $amount,
            'saldo_pendiente' => $amount,
            'estado' => 'PENDIENTE',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
