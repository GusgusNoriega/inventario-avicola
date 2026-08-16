<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TipoPollo;
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

    public function test_purchase_catalog_and_registration_exclude_wholesale_two_special_products(): void
    {
        $response = $this->getJson('/api/v1/compras/catalogo')->assertOk();
        $catalogCodes = collect($response->json('data.tipos_pollo'))->pluck('codigo')->all();

        $this->assertEmpty(array_intersect(
            TipoPollo::wholesaleTwoManualPriceCodes(),
            $catalogCodes,
        ));

        $provider = $this->provider('PROVEEDOR AISLAMIENTO', '20100000999');
        $specialTypeId = (int) DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::HEN_RED)
            ->value('id');
        $payload = $this->purchasePayload(
            $provider,
            $specialTypeId,
            'CREDITO',
            (string) Str::uuid(),
        );

        $this->postJson('/api/v1/compras', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('detalles');

        $this->assertDatabaseCount('compras', 0);
        $this->assertDatabaseCount('compra_detalles', 0);
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

    public function test_cash_purchase_can_leave_a_negative_balance_during_initial_setup(): void
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
        ))->assertCreated()
            ->assertJsonPath('data.estado', 'PAGADO')
            ->assertJsonPath('data.saldo_pendiente', '0.00')
            ->assertJsonPath('data.pago_inicial.importe', '118.00');

        $this->assertDatabaseCount('compras', 1);
        $this->assertDatabaseCount('compra_detalles', 1);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('pago_aplicaciones', 1);
        $this->assertDatabaseCount('pagos', 2);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '-68.00');
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

    public function test_unpaid_credit_purchase_correction_voids_the_original_creates_a_synchronized_replacement_and_is_strictly_idempotent(): void
    {
        $provider = $this->provider('PROVEEDOR CORRECCION CREDITO', '20100000012');
        $type = $this->chickenType();
        $original = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.edit_restriction', null)
            ->json('data');
        $originalDocumentId = $original['comprobante']['id'];
        $correctionKey = (string) Str::uuid();
        $correction = $this->purchasePayload($provider, $type, 'CREDITO', $correctionKey);
        $correction['fecha_compra'] = '2026-07-20';
        $correction['fecha_vencimiento'] = '2026-09-20';
        $correction['impuesto'] = '14.00';
        $correction['observaciones'] = 'Importes corregidos contra la factura fisica';
        $correction['detalles'][0] = [
            'tipo_pollo_id' => $type,
            'descripcion' => 'Pollo de proveedor corregido',
            'cantidad_aves' => 60,
            'peso_kg' => '12.500',
            'precio_kg' => '11.2000',
            'subtotal' => '0.01',
        ];

        $response = $this->putJson("/api/v1/compras/{$original['id']}", $correction)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('original_compra_id', $original['id'])
            ->assertJsonPath('reversa_id', null)
            ->assertJsonPath('data.numero_documento', 'F-001')
            ->assertJsonPath('data.fecha_compra', '2026-07-20')
            ->assertJsonPath('data.fecha_vencimiento', '2026-09-20')
            ->assertJsonPath('data.subtotal', '140.00')
            ->assertJsonPath('data.impuesto', '14.00')
            ->assertJsonPath('data.total', '154.00')
            ->assertJsonPath('data.saldo_pendiente', '154.00')
            ->assertJsonPath('data.estado', 'PENDIENTE')
            ->assertJsonPath('data.estado_compra', 'REGISTRADA')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.edit_restriction', null)
            ->assertJsonPath('data.detalles.0.peso_kg', '12.500')
            ->assertJsonPath('data.detalles.0.precio_kg', '11.2000');
        $replacementId = $response->json('data.id');
        $replacementDocumentId = $response->json('data.comprobante.id');

        $this->assertNotSame($original['id'], $replacementId);
        $this->assertNotSame($originalDocumentId, $replacementDocumentId);
        $this->assertDatabaseHas('compras', [
            'id' => $original['id'],
            'estado' => 'ANULADA',
            'numero_documento' => 'F-001',
            'numero_documento_activo' => null,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $originalDocumentId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('compras', [
            'id' => $replacementId,
            'proveedor_id' => $provider,
            'idempotency_key' => $correctionKey,
            'numero_documento' => 'F-001',
            'numero_documento_activo' => 'F-001',
            'subtotal' => 140,
            'impuesto' => 14,
            'total' => 154,
            'estado' => 'REGISTRADA',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $replacementDocumentId,
            'tercero_id' => $provider,
            'operacion' => 'COMPRA',
            'naturaleza' => 'CARGO',
            'origen_clave' => "COMPRA:REGISTRO:{$replacementId}",
            'fecha_emision' => '2026-07-20',
            'fecha_vencimiento' => '2026-09-20',
            'subtotal' => 140,
            'impuesto' => 14,
            'total' => 154,
            'saldo_pendiente' => 154,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('compra_detalles', [
            'compra_id' => $replacementId,
            'tipo_pollo_id' => $type,
            'descripcion' => 'Pollo de proveedor corregido',
            'cantidad_aves' => 60,
            'peso_kg' => 12.5,
            'precio_kg' => 11.2,
            'subtotal' => 140,
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $replacementDocumentId,
            'tipo_pollo_id' => $type,
            'descripcion' => 'Pollo de proveedor corregido',
            'cantidad_aves' => 60,
            'peso_neto_kg' => 12.5,
            'precio_kg' => 11.2,
            'subtotal' => 140,
        ]);
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseCount('compra_detalles', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('comprobante_detalles', 2);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('movimiento_detalles', 0);
        $this->assertDatabaseCount('existencias_almacen', 0);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'compras',
            'entidad_id' => (string) $original['id'],
            'accion' => 'CORREGIR',
        ]);
        $auditAfter = DB::table('auditoria_eventos')
            ->where('entidad', 'compras')
            ->where('entidad_id', (string) $original['id'])
            ->where('accion', 'CORREGIR')
            ->value('datos_despues');
        $this->assertIsString($auditAfter);
        $auditData = json_decode($auditAfter, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($original['id'], $auditData['compra_original_id']);
        $this->assertSame($replacementId, $auditData['compra_reemplazo_id']);
        $this->assertSame($correctionKey, $auditData['idempotency_key']);

        $this->getJson('/api/v1/compras?moneda=PEN')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('resumen.total', '154.00')
            ->assertJsonPath('resumen.credito', '154.00')
            ->assertJsonPath('resumen.pendiente', '154.00');
        $this->getJson("/api/v1/compras/{$original['id']}")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.estado', 'ANULADO');

        $this->putJson("/api/v1/compras/{$original['id']}", $correction)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('original_compra_id', $original['id'])
            ->assertJsonPath('data.id', $replacementId)
            ->assertJsonPath('reversa_id', null);
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('pagos', 0);

        $changedRetry = $correction;
        $changedRetry['detalles'][0]['precio_kg'] = '11.3000';
        $this->putJson("/api/v1/compras/{$original['id']}", $changedRetry)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseCount('comprobantes', 2);
    }

    public function test_credit_purchase_correction_with_an_active_payment_is_rejected_and_fully_rolled_back(): void
    {
        $provider = $this->provider('PROVEEDOR CORRECCION CON ABONO', '20100000013');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA CORRECCION ABONO');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'DESTINO CORRECCION ABONO');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'TRANSFERENCIA')->value('id');
        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');
        $payment = $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider,
            'cuenta_origen_id' => $ownAccount,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '50.00',
            'referencia' => 'ABONO-CORRECCION-50',
            'aplicaciones' => [[
                'lado' => 'CXP',
                'comprobante_id' => $purchase['comprobante']['id'],
                'importe_aplicado' => '50.00',
            ]],
        ])->assertCreated()->json('data');
        $this->getJson("/api/v1/compras/{$purchase['id']}")
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.estado', 'PARCIAL');
        $correctionKey = (string) Str::uuid();
        $correction = $this->purchasePayload($provider, $type, 'CREDITO', $correctionKey);
        $correction['numero_documento'] = 'F-001-CORREGIDA';
        $correction['detalles'][0]['precio_kg'] = '12.0000';
        $countsBefore = collect([
            'compras',
            'compra_detalles',
            'comprobantes',
            'comprobante_detalles',
            'pagos',
            'pago_aplicaciones',
            'auditoria_eventos',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        $this->putJson("/api/v1/compras/{$purchase['id']}", $correction)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('compra');

        $this->assertDatabaseHas('compras', [
            'id' => $purchase['id'],
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-001',
        ]);
        $this->assertDatabaseMissing('compras', ['idempotency_key' => $correctionKey]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $purchase['comprobante']['id'],
            'estado' => 'PARCIAL',
            'saldo_pendiente' => 68,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $payment['id'],
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $payment['id'],
            'comprobante_id' => $purchase['comprobante']['id'],
            'importe_aplicado' => 50,
        ]);
        foreach ($countsBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "La tabla {$table} cambio pese al rollback.");
        }
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $purchase['id'],
            'accion' => 'CORREGIR',
        ]);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('movimiento_detalles', 0);
        $this->assertDatabaseCount('existencias_almacen', 0);
    }

    public function test_cash_purchase_correction_reverses_the_original_payment_and_applies_the_replacement_only_once(): void
    {
        $provider = $this->provider('PROVEEDOR CORRECCION CONTADO', '20100000014');
        $type = $this->chickenType();
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA CORRECCION CONTADO');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $provider, 'DESTINO CORRECCION CONTADO');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $originalPayload = $this->purchasePayload(
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
        $original = $this->postJson('/api/v1/compras', $originalPayload)
            ->assertCreated()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.edit_restriction', null)
            ->json('data');
        $oldPaymentId = $original['pago_inicial']['id'];
        $correctionKey = (string) Str::uuid();
        $correction = $this->purchasePayload(
            $provider,
            $type,
            'CONTADO',
            $correctionKey,
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        );
        $correction['impuesto'] = '0.00';
        $correction['observaciones'] = 'Compra al contado corregida';
        $correction['detalles'][0]['peso_kg'] = '8.000';
        $correction['detalles'][0]['precio_kg'] = '10.0000';

        $response = $this->putJson("/api/v1/compras/{$original['id']}", $correction)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('original_compra_id', $original['id'])
            ->assertJsonPath('data.condicion', 'CONTADO')
            ->assertJsonPath('data.total', '80.00')
            ->assertJsonPath('data.saldo_pendiente', '0.00')
            ->assertJsonPath('data.estado', 'PAGADO')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.edit_restriction', null)
            ->assertJsonPath('data.pago_inicial.importe', '80.00')
            ->assertJsonPath('data.pago_inicial.estado', 'REGISTRADO');
        $replacementId = $response->json('data.id');
        $replacementDocumentId = $response->json('data.comprobante.id');
        $newPaymentId = $response->json('data.pago_inicial.id');
        $reverseId = $response->json('reversa_id');

        $this->assertNotNull($reverseId);
        $this->assertNotSame($original['id'], $replacementId);
        $this->assertNotSame($oldPaymentId, $newPaymentId);
        $this->assertDatabaseHas('compras', [
            'id' => $original['id'],
            'estado' => 'ANULADA',
            'numero_documento_activo' => null,
            'pago_inicial_id' => $oldPaymentId,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $original['comprobante']['id'],
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $oldPaymentId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $reverseId,
            'reversa_de_pago_id' => $oldPaymentId,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('compras', [
            'id' => $replacementId,
            'idempotency_key' => $correctionKey,
            'pago_inicial_id' => $newPaymentId,
            'condicion' => 'CONTADO',
            'total' => 80,
            'estado' => 'REGISTRADA',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $replacementDocumentId,
            'total' => 80,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $newPaymentId,
            'tipo' => 'PAGO_PROVEEDOR',
            'cuenta_origen_id' => $ownAccount,
            'cuenta_destino_id' => $providerAccount,
            'importe' => 80,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $oldPaymentId,
            'comprobante_id' => $original['comprobante']['id'],
            'lado' => 'CXP',
            'importe_aplicado' => 118,
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $newPaymentId,
            'comprobante_id' => $replacementDocumentId,
            'lado' => 'CXP',
            'importe_aplicado' => 80,
        ]);
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('pagos', 4);
        $this->assertDatabaseCount('pago_aplicaciones', 2);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownAccount)
            ->assertJsonPath('data.0.saldo', '120.00');
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('movimiento_detalles', 0);
        $this->assertDatabaseCount('existencias_almacen', 0);
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $original['id'],
            'accion' => 'CORREGIR',
        ]);

        $this->putJson("/api/v1/compras/{$original['id']}", $correction)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('data.id', $replacementId)
            ->assertJsonPath('reversa_id', $reverseId);
        $this->assertDatabaseCount('compras', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('pagos', 4);
        $this->assertDatabaseCount('pago_aplicaciones', 2);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '120.00');
    }

    public function test_purchase_correction_requires_purchase_void_permission_and_cash_correction_also_requires_payment_void_permission(): void
    {
        $type = $this->chickenType();
        $creditProvider = $this->provider('PROVEEDOR SIN PERMISO CORRECCION', '20100000015');
        $credit = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $creditProvider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');
        $creditCorrectionKey = (string) Str::uuid();
        $creditCorrection = $this->purchasePayload(
            $creditProvider,
            $type,
            'CREDITO',
            $creditCorrectionKey,
        );
        $technicalPermissions = config('access_modules.modules.MODULO_FINANZAS.technical_permissions');
        config()->set(
            'access_modules.modules.MODULO_FINANZAS.technical_permissions',
            array_values(array_diff($technicalPermissions, ['COMPRAS_ANULAR'])),
        );

        $this->putJson("/api/v1/compras/{$credit['id']}", $creditCorrection)
            ->assertForbidden();
        $this->assertDatabaseHas('compras', [
            'id' => $credit['id'],
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-001',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $credit['comprobante']['id'],
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);
        $this->assertDatabaseMissing('compras', ['idempotency_key' => $creditCorrectionKey]);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $credit['id'],
            'accion' => 'CORREGIR',
        ]);

        config()->set(
            'access_modules.modules.MODULO_FINANZAS.technical_permissions',
            $technicalPermissions,
        );
        $cashProvider = $this->provider('PROVEEDOR SIN PERMISO REVERSA', '20100000016');
        [, $ownAccount] = $this->financialAccount('PROPIA', null, 'CAJA SIN PERMISO REVERSA');
        [, $providerAccount] = $this->financialAccount('EXTERNA', $cashProvider, 'DESTINO SIN PERMISO REVERSA');
        $this->openingBalance($ownAccount, '200.00');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $cash = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $cashProvider,
            $type,
            'CONTADO',
            (string) Str::uuid(),
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        ))->assertCreated()->json('data');
        $cashCorrectionKey = (string) Str::uuid();
        $cashCorrection = $this->purchasePayload(
            $cashProvider,
            $type,
            'CONTADO',
            $cashCorrectionKey,
            [
                'cuenta_origen_id' => $ownAccount,
                'cuenta_destino_id' => $providerAccount,
                'metodo_pago_id' => $method,
            ],
        );
        $countsBefore = collect(['compras', 'comprobantes', 'pagos', 'pago_aplicaciones'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        config()->set(
            'access_modules.modules.MODULO_FINANZAS.technical_permissions',
            array_values(array_diff($technicalPermissions, ['PAGOS_ANULAR'])),
        );

        $this->putJson("/api/v1/compras/{$cash['id']}", $cashCorrection)
            ->assertForbidden();
        $this->assertDatabaseHas('compras', [
            'id' => $cash['id'],
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-001',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $cash['comprobante']['id'],
            'estado' => 'PAGADO',
            'saldo_pendiente' => 0,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $cash['pago_inicial']['id'],
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseMissing('pagos', [
            'reversa_de_pago_id' => $cash['pago_inicial']['id'],
        ]);
        $this->assertDatabaseMissing('compras', ['idempotency_key' => $cashCorrectionKey]);
        foreach ($countsBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "La tabla {$table} cambio sin PAGOS_ANULAR.");
        }
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownAccount)
            ->assertJsonPath('data.0.saldo', '82.00');
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $cash['id'],
            'accion' => 'CORREGIR',
        ]);
        config()->set(
            'access_modules.modules.MODULO_FINANZAS.technical_permissions',
            $technicalPermissions,
        );
    }

    public function test_purchase_correction_requires_a_uuid_and_duplicate_document_failure_rolls_the_original_back(): void
    {
        $provider = $this->provider('PROVEEDOR VALIDACION CORRECCION', '20100000017');
        $type = $this->chickenType();
        $firstPayload = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $firstPayload['numero_documento'] = 'F-CORRECCION-001';
        $first = $this->postJson('/api/v1/compras', $firstPayload)
            ->assertCreated()
            ->json('data');
        $secondPayload = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $secondPayload['numero_documento'] = 'F-CORRECCION-002';
        $second = $this->postJson('/api/v1/compras', $secondPayload)
            ->assertCreated()
            ->json('data');
        $correction = $this->purchasePayload($provider, $type, 'CREDITO', (string) Str::uuid());
        $correction['numero_documento'] = 'F-CORRECCION-001';
        $correction['detalles'][0]['precio_kg'] = '12.0000';

        $missingKey = $correction;
        unset($missingKey['idempotency_key']);
        $this->putJson("/api/v1/compras/{$second['id']}", $missingKey)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $invalidKey = $correction;
        $invalidKey['idempotency_key'] = 'no-es-un-uuid';
        $this->putJson("/api/v1/compras/{$second['id']}", $invalidKey)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $countsBefore = collect([
            'compras',
            'compra_detalles',
            'comprobantes',
            'comprobante_detalles',
            'pagos',
            'pago_aplicaciones',
            'auditoria_eventos',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $this->putJson("/api/v1/compras/{$second['id']}", $correction)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_documento');

        $this->assertDatabaseHas('compras', [
            'id' => $first['id'],
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-CORRECCION-001',
        ]);
        $this->assertDatabaseHas('compras', [
            'id' => $second['id'],
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-CORRECCION-002',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $second['comprobante']['id'],
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);
        $this->assertDatabaseMissing('compras', [
            'idempotency_key' => $correction['idempotency_key'],
        ]);
        foreach ($countsBefore as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "La tabla {$table} cambio tras el documento duplicado.");
        }
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $second['id'],
            'accion' => 'CORREGIR',
        ]);
    }

    public function test_legacy_purchase_cannot_be_corrected(): void
    {
        $provider = $this->provider('PROVEEDOR LEGADO CORRECCION', '20100000018');
        $type = $this->chickenType();
        $documentId = $this->financialDocument(
            'COMPRA',
            $provider,
            '118.00',
            'CXP-LEGADO-CORRECCION',
        );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'tipo_documento' => 'INTERNO',
            'origen_codigo' => 'TICKET',
            'origen_clave' => 'COMPRA:TICKET:9101:PROVEEDOR:'.$provider,
            'fecha_emision' => '2026-07-01',
            'fecha_vencimiento' => '2026-07-31',
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $documentId,
            'tipo_pollo_id' => $type,
            'descripcion' => 'Pollo historico no editable',
            'cantidad_aves' => 50,
            'peso_neto_kg' => '10.000',
            'precio_kg' => '11.8000',
            'subtotal' => '118.00',
            'created_at' => now(),
        ]);
        $migration = require database_path(
            'migrations/2026_07_14_000005_backfill_legacy_dispatch_purchases.php'
        );
        $migration->up();
        $legacy = DB::table('compras')->where('comprobante_id', $documentId)->first();
        $this->assertNotNull($legacy);
        $correctionKey = (string) Str::uuid();

        $this->getJson("/api/v1/compras/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('data.editable', false);
        $this->putJson(
            "/api/v1/compras/{$legacy->id}",
            $this->purchasePayload($provider, $type, 'CREDITO', $correctionKey),
        )->assertUnprocessable()
            ->assertJsonValidationErrors('compra');

        $this->assertDatabaseHas('compras', [
            'id' => $legacy->id,
            'condicion' => 'LEGADO',
            'estado' => 'REGISTRADA',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);
        $this->assertDatabaseMissing('compras', ['idempotency_key' => $correctionKey]);
        $this->assertDatabaseCount('compras', 1);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $legacy->id,
            'accion' => 'CORREGIR',
        ]);
    }

    public function test_purchase_correction_is_tenant_scoped_and_does_not_disclose_foreign_records(): void
    {
        $provider = $this->provider('PROVEEDOR CORRECCION AISLADA', '20100000019');
        $type = $this->chickenType();
        $purchase = $this->postJson('/api/v1/compras', $this->purchasePayload(
            $provider,
            $type,
            'CREDITO',
            (string) Str::uuid(),
        ))->assertCreated()->json('data');
        $correctionKey = (string) Str::uuid();
        $otherUser = User::factory()->create();
        $otherRole = Role::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'COMPRAS_CORRECCION_OTRA_EMPRESA',
            'nombre' => 'Correccion de compras de otra empresa',
        ]);
        $otherRole->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id')
        );
        $otherUser->roles()->attach($otherRole);
        Sanctum::actingAs($otherUser, ['api']);

        $this->putJson(
            "/api/v1/compras/{$purchase['id']}",
            $this->purchasePayload($provider, $type, 'CREDITO', $correctionKey),
        )->assertNotFound();

        $this->assertDatabaseHas('compras', [
            'id' => $purchase['id'],
            'empresa_id' => $this->user->empresa_id,
            'estado' => 'REGISTRADA',
            'numero_documento_activo' => 'F-001',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $purchase['comprobante']['id'],
            'estado' => 'PENDIENTE',
            'saldo_pendiente' => 118,
        ]);
        $this->assertDatabaseMissing('compras', [
            'empresa_id' => $otherUser->empresa_id,
            'idempotency_key' => $correctionKey,
        ]);
        $this->assertDatabaseCount('compras', 1);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseMissing('auditoria_eventos', [
            'entidad' => 'compras',
            'entidad_id' => (string) $purchase['id'],
            'accion' => 'CORREGIR',
        ]);
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
