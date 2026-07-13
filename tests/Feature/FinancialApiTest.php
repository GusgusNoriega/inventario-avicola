<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'FINANZAS_TEST',
            'nombre' => 'Finanzas test',
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('codigo', [
            'FINANZAS_VER',
            'CUENTAS_FINANCIERAS_GESTIONAR',
            'PAGOS_REGISTRAR',
            'PAGOS_ANULAR',
            'SALDOS_AJUSTAR',
        ])->pluck('id'));
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_financial_routes_never_use_public_directory_bypass(): void
    {
        config()->set('directory.public_access', true);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/finanzas/entidades')->assertUnauthorized();
    }

    public function test_entities_and_accounts_are_scoped_and_deactivated_instead_of_deleted(): void
    {
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR NORTE', '20111111111');

        $entityId = $this->postJson('/api/v1/finanzas/entidades', [
            'tipo' => 'EXTERNA',
            'proveedor_id' => $provider,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20555555555',
            'razon_social' => 'Banco del proveedor',
        ])->assertCreated()
            ->assertJsonPath('data.tipo', 'EXTERNA')
            ->json('data.id');

        $accountId = $this->postJson("/api/v1/finanzas/entidades/{$entityId}/cuentas", [
            'tipo' => 'BANCO',
            'alias' => 'Cuenta principal proveedor',
            'banco' => 'Banco Uno',
            'numero_cuenta' => '001-999',
            'moneda' => 'PEN',
        ])->assertCreated()
            ->assertJsonPath('data.estado', 'ACTIVO')
            ->json('data.id');

        $this->deleteJson("/api/v1/finanzas/entidades/{$entityId}")
            ->assertUnprocessable();
        $this->deleteJson("/api/v1/finanzas/cuentas/{$accountId}")->assertOk();
        $this->deleteJson("/api/v1/finanzas/entidades/{$entityId}")->assertOk();

        $this->assertDatabaseHas('cuentas_financieras', ['id' => $accountId, 'estado' => 'INACTIVO']);
        $this->assertDatabaseHas('entidades_financieras', ['id' => $entityId, 'estado' => 'INACTIVO']);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'cuentas_financieras',
            'entidad_id' => (string) $accountId,
            'accion' => 'DESACTIVAR',
        ]);
    }

    public function test_financial_entities_from_another_company_are_not_visible_or_mutable(): void
    {
        $otherUser = User::factory()->create();
        $foreignEntity = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'ENTIDAD DE OTRA EMPRESA',
            'estado' => 'ACTIVO',
            'created_by' => $otherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/finanzas/entidades')
            ->assertOk()
            ->assertJsonMissing(['razon_social' => 'ENTIDAD DE OTRA EMPRESA']);
        $this->deleteJson("/api/v1/finanzas/entidades/{$foreignEntity}")->assertNotFound();
    }

    public function test_customer_collection_updates_receivable_balance_and_is_idempotent(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE UNO', '10111111');
        [$entity, $account] = $this->ownAccount();
        $document = $this->document('VENTA', $client, '100.00', 'CXC-1');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '40.00',
            'aplicaciones' => [[
                'lado' => 'CXC',
                'comprobante_id' => $document,
                'importe_aplicado' => '40.00',
            ]],
        ];

        $this->postJson('/api/v1/finanzas/movimientos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.importe', '40.00')
            ->assertJsonPath('meta.idempotent', false);
        $this->postJson('/api/v1/finanzas/movimientos', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true);

        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document,
            'saldo_pendiente' => '60',
            'estado' => 'PARCIAL',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.entidad.id', $entity)
            ->assertJsonPath('data.0.saldo', '40.00')
            ->assertJsonPath('cartera.por_cobrar', '60.00');
    }

    public function test_each_movement_flow_rejects_fields_that_do_not_belong_to_it(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE FLUJOS', '10555555');
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR FLUJOS', '20555555555');
        [, $ownDestination] = $this->ownAccount();
        [, $ownOrigin] = $this->ownAccount();
        [, $externalDestination] = $this->externalAccount($provider);
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');

        $cases = [
            'customer collection with an origin account' => [
                'field' => 'cuenta_origen_id',
                'payload' => [
                    'tipo' => 'COBRO_CLIENTE',
                    'cliente_id' => $client,
                    'cuenta_origen_id' => $ownOrigin,
                    'cuenta_destino_id' => $ownDestination,
                    'metodo_pago_id' => $method,
                ],
            ],
            'provider payment with a customer' => [
                'field' => 'cliente_id',
                'payload' => [
                    'tipo' => 'PAGO_PROVEEDOR',
                    'cliente_id' => $client,
                    'proveedor_id' => $provider,
                    'cuenta_origen_id' => $ownOrigin,
                    'cuenta_destino_id' => $externalDestination,
                    'metodo_pago_id' => $method,
                ],
            ],
            'provider payment without an external destination' => [
                'field' => 'cuenta_destino_id',
                'payload' => [
                    'tipo' => 'PAGO_PROVEEDOR',
                    'proveedor_id' => $provider,
                    'cuenta_origen_id' => $ownOrigin,
                    'metodo_pago_id' => $method,
                ],
            ],
            'direct payment with an own origin account' => [
                'field' => 'cuenta_origen_id',
                'payload' => [
                    'tipo' => 'PAGO_DIRECTO',
                    'cliente_id' => $client,
                    'proveedor_id' => $provider,
                    'cuenta_origen_id' => $ownOrigin,
                    'cuenta_destino_id' => $externalDestination,
                    'metodo_pago_id' => $method,
                ],
            ],
            'retail collection with a provider' => [
                'field' => 'proveedor_id',
                'payload' => [
                    'tipo' => 'COBRO_MINORISTA',
                    'cliente_id' => $client,
                    'proveedor_id' => $provider,
                    'cuenta_destino_id' => $ownDestination,
                    'metodo_pago_id' => $method,
                ],
            ],
            'customer refund with a destination account' => [
                'field' => 'cuenta_destino_id',
                'payload' => [
                    'tipo' => 'REEMBOLSO_CLIENTE',
                    'cliente_id' => $client,
                    'cuenta_origen_id' => $ownOrigin,
                    'cuenta_destino_id' => $ownDestination,
                    'metodo_pago_id' => $method,
                ],
            ],
            'opening balance with a customer' => [
                'field' => 'cliente_id',
                'payload' => [
                    'tipo' => 'SALDO_INICIAL',
                    'cliente_id' => $client,
                    'cuenta_destino_id' => $ownDestination,
                ],
            ],
            'adjustment with a provider' => [
                'field' => 'proveedor_id',
                'payload' => [
                    'tipo' => 'AJUSTE',
                    'proveedor_id' => $provider,
                    'cuenta_destino_id' => $ownDestination,
                ],
            ],
            'internal transfer with a customer' => [
                'field' => 'cliente_id',
                'payload' => [
                    'tipo' => 'TRANSFERENCIA_INTERNA',
                    'cliente_id' => $client,
                    'cuenta_origen_id' => $ownOrigin,
                    'cuenta_destino_id' => $ownDestination,
                ],
            ],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/v1/finanzas/movimientos', [
                'idempotency_key' => (string) Str::uuid(),
                'moneda' => 'PEN',
                'importe' => '10.00',
                'aplicaciones' => [],
                ...$case['payload'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($case['field']);
        }

        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_a_foreign_or_inactive_actor_cannot_void_a_company_movement(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE ACTOR', '10666666');
        [, $account] = $this->ownAccount();
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $paymentId = $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '15.00',
            'aplicaciones' => [],
        ])->assertCreated()->json('data.id');
        $foreignActor = User::factory()->create();

        try {
            app(FinancialMovementService::class)->void(
                (int) $this->user->empresa_id,
                $foreignActor,
                (int) $paymentId,
                'Intento de otra empresa',
            );
            $this->fail('La anulacion debio rechazar al actor de otra empresa.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->user->update(['estado' => 'INACTIVO']);
        try {
            app(FinancialMovementService::class)->void(
                (int) $this->user->empresa_id,
                $this->user,
                (int) $paymentId,
                'Intento de un usuario inactivo',
            );
            $this->fail('La anulacion debio rechazar al actor inactivo.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'REGISTRADO',
            'reversa_de_pago_id' => null,
        ]);
        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_an_outflow_is_rejected_when_the_own_account_has_insufficient_balance(): void
    {
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR SIN SALDO', '20666666666');
        [, $account] = $this->ownAccount();
        [, $providerAccount] = $this->externalAccount($provider);
        $payable = $this->document('COMPRA', $provider, '80.00', 'CXP-SIN-SALDO');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider,
            'cuenta_origen_id' => $account,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '10.00',
            'aplicaciones' => [[
                'lado' => 'CXP',
                'comprobante_id' => $payable,
                'importe_aplicado' => '10.00',
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('importe');

        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $payable,
            'saldo_pendiente' => 80,
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_an_income_cannot_be_voided_after_its_funds_were_spent(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE FONDOS USADOS', '10999999');
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR FONDOS USADOS', '20999999999');
        [, $account] = $this->ownAccount();
        [, $providerAccount] = $this->externalAccount($provider);
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');

        $collectionId = $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '100.00',
            'aplicaciones' => [],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider,
            'cuenta_origen_id' => $account,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '100.00',
            'aplicaciones' => [],
        ])->assertCreated();

        $this->postJson("/api/v1/finanzas/movimientos/{$collectionId}/anular", [
            'motivo' => 'El ingreso ya fue utilizado',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('importe');

        $this->assertDatabaseHas('pagos', [
            'id' => $collectionId,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseCount('pagos', 2);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '0.00');
    }

    public function test_an_idempotency_uuid_cannot_be_reused_with_a_different_reference(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE IDEMPOTENCIA', '10777777');
        [, $account] = $this->ownAccount();
        $document = $this->document('VENTA', $client, '50.00', 'CXC-IDEMPOTENCIA');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '20.00',
            'referencia' => 'REF-ORIGINAL',
            'aplicaciones' => [[
                'lado' => 'CXC',
                'comprobante_id' => $document,
                'importe_aplicado' => '20.00',
            ]],
        ];

        $this->postJson('/api/v1/finanzas/movimientos', $payload)->assertCreated();
        $this->postJson('/api/v1/finanzas/movimientos', [
            ...$payload,
            'referencia' => 'REF-DIFERENTE',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('pagos', 1);
        $this->assertDatabaseHas('pagos', [
            'idempotency_key' => $key,
            'referencia' => 'REF-ORIGINAL',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document,
            'saldo_pendiente' => 30,
        ]);
    }

    public function test_direct_customer_payment_reduces_both_sides_without_increasing_own_cash(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE DIRECTO', '10222222');
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR DIRECTO', '20222222222');
        [, $ownAccount] = $this->ownAccount();
        [, $externalAccount] = $this->externalAccount($provider);
        $receivable = $this->document('VENTA', $client, '100.00', 'CXC-DIRECTO');
        $payable = $this->document('COMPRA', $provider, '80.00', 'CXP-DIRECTO');
        $method = DB::table('metodos_pago')->where('codigo', 'DEPOSITO')->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_DIRECTO',
            'cliente_id' => $client,
            'proveedor_id' => $provider,
            'cuenta_destino_id' => $externalAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '50.00',
            'referencia' => 'OP-500',
            'aplicaciones' => [
                ['lado' => 'CXC', 'comprobante_id' => $receivable, 'importe_aplicado' => '50.00'],
                ['lado' => 'CXP', 'comprobante_id' => $payable, 'importe_aplicado' => '50.00'],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('comprobantes', ['id' => $receivable, 'saldo_pendiente' => '50']);
        $this->assertDatabaseHas('comprobantes', ['id' => $payable, 'saldo_pendiente' => '30']);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownAccount)
            ->assertJsonPath('data.0.saldo', '0.00')
            ->assertJsonPath('pagos_proveedores.directos_clientes', '50.00');
    }

    public function test_void_creates_reversal_restores_payable_and_nets_own_balance(): void
    {
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR REVERSA', '20333333333');
        [, $account] = $this->ownAccount();
        [, $providerAccount] = $this->externalAccount($provider);
        $payable = $this->document('COMPRA', $provider, '80.00', 'CXP-REVERSA');
        $method = DB::table('metodos_pago')->where('codigo', 'TRANSFERENCIA')->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'SALDO_INICIAL',
            'cuenta_destino_id' => $account,
            'moneda' => 'PEN',
            'importe' => '200.00',
            'aplicaciones' => [],
        ])->assertCreated();
        $paymentId = $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider,
            'cuenta_origen_id' => $account,
            'cuenta_destino_id' => $providerAccount,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '40.00',
            'referencia' => 'TRX-40',
            'aplicaciones' => [[
                'lado' => 'CXP',
                'comprobante_id' => $payable,
                'importe_aplicado' => '40.00',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.direccion', 'EGRESO')
            ->json('data.id');

        $this->postJson("/api/v1/finanzas/movimientos/{$paymentId}/anular", [
            'motivo' => 'Transferencia bancaria rechazada',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('reversa.reversa_de_pago_id', $paymentId)
            ->assertJsonPath('reversa.direccion', 'INGRESO');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $payable,
            'saldo_pendiente' => '80',
            'estado' => 'PENDIENTE',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '200.00')
            ->assertJsonPath('pagos_proveedores.realizados_por_empresa', '0.00');
    }

    public function test_customer_credit_can_be_refunded_and_the_refund_can_be_reversed(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE REEMBOLSO', '10888888');
        [, $account] = $this->ownAccount();
        $credit = $this->document('VENTA', $client, '30.00', 'ABONO-REEMBOLSO', 'ABONO');
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'SALDO_INICIAL',
            'cuenta_destino_id' => $account,
            'moneda' => 'PEN',
            'importe' => '50.00',
            'aplicaciones' => [],
        ])->assertCreated();

        $refundId = $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'REEMBOLSO_CLIENTE',
            'cliente_id' => $client,
            'cuenta_origen_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '20.00',
            'aplicaciones' => [[
                'lado' => 'CXC',
                'comprobante_id' => $credit,
                'importe_aplicado' => '20.00',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.tipo', 'REEMBOLSO_CLIENTE')
            ->assertJsonPath('data.direccion', 'EGRESO')
            ->json('data.id');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $credit,
            'saldo_pendiente' => 10,
            'estado' => 'PARCIAL',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '30.00');
        $this->getJson('/api/v1/finanzas/movimientos?tipo=REEMBOLSO_CLIENTE')
            ->assertOk()
            ->assertJsonPath('data.0.id', $refundId);

        $voidResponse = $this->postJson("/api/v1/finanzas/movimientos/{$refundId}/anular", [
            'motivo' => 'El cliente no recibio el efectivo',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('reversa.reversa_de_pago_id', $refundId)
            ->assertJsonPath('reversa.direccion', 'INGRESO')
            ->assertJsonPath('meta.idempotent', false);
        $reverseId = $voidResponse->json('reversa.id');

        $this->postJson("/api/v1/finanzas/movimientos/{$refundId}/anular", [
            'motivo' => 'Reintento de la misma anulacion',
        ])->assertOk()
            ->assertJsonPath('reversa.id', $reverseId)
            ->assertJsonPath('meta.idempotent', true);

        $this->postJson("/api/v1/finanzas/movimientos/{$reverseId}/anular", [
            'motivo' => 'Una reversa no puede anularse',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movimiento');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $credit,
            'saldo_pendiente' => 30,
            'estado' => 'PENDIENTE',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '50.00');
    }

    public function test_provider_financial_summary_is_protected_and_preserves_three_weight_decimals(): void
    {
        $provider = $this->thirdParty('PROVEEDOR', 'PROVEEDOR RESUMEN', '20777777777');
        $this->createPendingPurchaseCost($provider, '10.123');

        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertOk()
            ->assertJsonPath('data.pending_costs.count', 1)
            ->assertJsonPath('data.pending_costs.weight_kg', '10.123');

        config()->set('directory.public_access', true);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/finanzas/proveedores/{$provider}/resumen")
            ->assertUnauthorized();
    }

    public function test_portfolio_accounts_for_credit_documents_with_a_negative_sign(): void
    {
        $client = $this->thirdParty('CLIENTE', 'CLIENTE CREDITO', '10444444');
        $this->document('VENTA', $client, '100.00', 'CARGO-CXC');
        $this->document('VENTA', $client, '25.00', 'ABONO-CXC', 'ABONO');

        $this->getJson('/api/v1/finanzas/cartera?lado=CXC')
            ->assertOk()
            ->assertJsonPath('resumen.cargos_pendientes', '100.00')
            ->assertJsonPath('resumen.abonos_pendientes', '25.00')
            ->assertJsonPath('resumen.saldo_neto', '75.00');
    }

    /** @return array{int, int} */
    private function ownAccount(): array
    {
        $entity = $this->entity('PROPIA', null, 'EMPRESA PROPIA '.Str::random(5));

        return [$entity, $this->account($entity, 'CAJA '.Str::random(5))];
    }

    /** @return array{int, int} */
    private function externalAccount(int $provider): array
    {
        $entity = $this->entity('EXTERNA', $provider, 'EMPRESA EXTERNA '.Str::random(5));

        return [$entity, $this->account($entity, 'CUENTA EXTERNA '.Str::random(5))];
    }

    private function entity(string $type, ?int $provider, string $name): int
    {
        return DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => $type,
            'proveedor_id' => $provider,
            'razon_social' => $name,
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(int $entity, string $alias): int
    {
        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entity,
            'tipo' => 'CAJA',
            'alias' => $alias,
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function thirdParty(string $role, string $name, string $document): int
    {
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => strlen($document) === 11 ? 'RUC' : 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'SIN DIRECCION',
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

    private function document(
        string $operation,
        ?int $thirdParty,
        string $amount,
        string $originKey,
        string $nature = 'CARGO',
    ): int {
        return DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $thirdParty,
            'operacion' => $operation,
            'naturaleza' => $nature,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'DOC-'.Str::random(10),
            'origen_codigo' => 'TEST',
            'origen_clave' => $originKey,
            'fecha_emision' => today(),
            'fecha_vencimiento' => today()->addDays(7),
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

    private function createPendingPurchaseCost(int $provider, string $weight): void
    {
        $branch = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'FIN-'.Str::upper(Str::random(6)),
            'nombre' => 'Sucursal resumen financiero',
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journey = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branch,
            'fecha_operativa' => now()->toDateString(),
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => now()->subHours(2),
            'cierre_programado_at' => now()->subHour(),
            'cerrada_por' => $this->user->id,
            'cerrada_at' => now(),
        ]);
        $ticket = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journey,
            'codigo' => 'T-RESUMEN-'.Str::upper(Str::random(6)),
            'canal' => 'MAYORISTA',
            'tipo_operacion' => 'DESPACHO',
            'estado' => 'CERRADO',
            'cerrado_por' => $this->user->id,
            'cerrado_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $type = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'FIN_RESUMEN_'.Str::upper(Str::random(5)),
            'nombre' => 'Pollo resumen financiero',
            'permite_despacho' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $weighing = DB::table('pesadas')->insertGetId([
            'ticket_id' => $ticket,
            'numero' => 1,
            'tipo_pollo_id' => $type,
            'condicion_pollo' => 'VIVO',
            'sexo' => 'MACHO',
            'proveedor_origen_id' => $provider,
            'origen_peso' => 'MANUAL',
            'cantidad_aves' => 10,
            'peso_leido_kg' => $weight,
            'peso_bruto_kg' => $weight,
            'tara_total_kg' => 0,
            'peso_neto_kg' => $weight,
            'pesada_at' => now(),
            'estado' => 'ACTIVA',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('costos_compra_pesadas')->insert([
            'pesada_id' => $weighing,
            'proveedor_id' => $provider,
            'precio_historial_id' => null,
            'precio_kg' => 0,
            'peso_kg' => $weight,
            'importe' => 0,
            'estado' => 'PENDIENTE',
            'origen' => 'SIN_PRECIO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
