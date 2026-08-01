<?php

namespace Tests\Feature;

use App\Models\Balanza;
use App\Models\Permission;
use App\Models\Pesada;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashRegisterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $cashEntityId;

    private int $cashRegisterId;

    private int $otherCashRegisterId;

    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'CAJA_EFECTIVO_TEST',
            'nombre' => 'Caja efectivo test',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id'),
        );
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);

        $this->cashEntityId = $this->financialEntity(
            (int) $this->user->empresa_id,
            $this->user->id,
            'PROPIA',
            'Cajas de la empresa',
        );
        $this->cashRegisterId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja principal',
        );
        $this->otherCashRegisterId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja secundaria',
        );
        $this->cashMethodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'EFECTIVO')
            ->value('id');
    }

    public function test_catalog_only_returns_active_own_cash_registers_and_active_clients_from_the_company(): void
    {
        $clientId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'CLIENTE',
            'Cliente visible',
            '10111111',
        );
        $inactiveClientId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'CLIENTE',
            'Cliente inactivo',
            '10222222',
            'INACTIVO',
        );
        $providerId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'PROVEEDOR',
            'Proveedor no cliente',
            '20333333333',
        );
        $bankAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Banco propio',
            'BANCO',
        );
        $inactiveCashId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja inactiva',
            'CAJA',
            'INACTIVO',
        );
        $externalEntityId = $this->financialEntity(
            (int) $this->user->empresa_id,
            $this->user->id,
            'EXTERNA',
            'Entidad externa',
        );
        $externalCashId = $this->financialAccount(
            $externalEntityId,
            $this->user->id,
            'Caja externa',
        );
        $foreignUser = User::factory()->create();
        $foreignEntityId = $this->financialEntity(
            (int) $foreignUser->empresa_id,
            $foreignUser->id,
            'PROPIA',
            'Entidad de otra empresa',
        );
        $foreignCashId = $this->financialAccount(
            $foreignEntityId,
            $foreignUser->id,
            'Caja de otra empresa',
        );
        $foreignClientId = $this->thirdParty(
            (int) $foreignUser->empresa_id,
            'CLIENTE',
            'Cliente de otra empresa',
            '10444444',
        );

        $response = $this->getJson('/api/v1/finanzas/caja-efectivo/catalogo')
            ->assertOk();

        $cashRegisterIds = collect($response->json('data.cajas'))->pluck('id')->all();
        $clientIds = collect($response->json('data.clientes'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$this->cashRegisterId, $this->otherCashRegisterId],
            $cashRegisterIds,
        );
        $this->assertContains($clientId, $clientIds);
        $this->assertNotContains($inactiveClientId, $clientIds);
        $this->assertNotContains($providerId, $clientIds);
        $this->assertNotContains($foreignClientId, $clientIds);
        $this->assertNotContains($bankAccountId, $cashRegisterIds);
        $this->assertNotContains($inactiveCashId, $cashRegisterIds);
        $this->assertNotContains($externalCashId, $cashRegisterIds);
        $this->assertNotContains($foreignCashId, $cashRegisterIds);
    }

    public function test_income_and_expense_are_cash_only_idempotent_and_update_the_daily_totals_immediately(): void
    {
        $clientId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'CLIENTE',
            'Cliente caja',
            '10555555',
        );
        $incomeKey = (string) Str::uuid();
        $incomePayload = $this->payload($incomeKey, [
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'CLIENTE',
            'cliente_id' => $clientId,
            'fecha_hora' => '2026-07-31 09:15:00',
            'importe' => '150.25',
            'detalle' => 'Pago en efectivo del cliente',
        ]);

        $incomeResponse = $this->postJson(
            '/api/v1/finanzas/caja-efectivo',
            $incomePayload,
        )->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.direccion', 'INGRESO')
            ->assertJsonPath('data.contraparte_tipo', 'CLIENTE')
            ->assertJsonPath('data.cliente.id', $clientId)
            ->assertJsonPath('data.caja.id', $this->cashRegisterId)
            ->assertJsonPath('data.importe', '150.25')
            ->assertJsonPath('data.detalle', 'Pago en efectivo del cliente');
        $incomeId = (int) $incomeResponse->json('data.id');
        $incomePaymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $incomeId)
            ->value('pago_id');

        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $incomeId,
            'empresa_id' => $this->user->empresa_id,
            'pago_id' => $incomePaymentId,
            'caja_id' => $this->cashRegisterId,
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'CLIENTE',
            'cliente_id' => $clientId,
            'otra_caja_id' => null,
            'detalle' => 'Pago en efectivo del cliente',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $incomePaymentId,
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'COBRO_CLIENTE',
            'cliente_id' => $clientId,
            'cuenta_origen_id' => null,
            'cuenta_destino_id' => $this->cashRegisterId,
            'metodo_pago_id' => $this->cashMethodId,
            'metodo' => 'EFECTIVO',
            'direccion' => 'INGRESO',
            'referencia' => null,
            'moneda' => 'PEN',
            'importe' => 150.25,
            'estado' => 'REGISTRADO',
        ]);

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('resumen.ingresos', '150.25')
            ->assertJsonPath('resumen.egresos', '0.00')
            ->assertJsonPath('resumen.neto', '150.25');

        $this->postJson('/api/v1/finanzas/caja-efectivo', $incomePayload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('data.id', $incomeId);
        $this->assertDatabaseCount('movimientos_caja_efectivo', 1);

        $changedRequest = $incomePayload;
        $changedRequest['importe'] = '151.25';
        $this->postJson('/api/v1/finanzas/caja-efectivo', $changedRequest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $expenseResponse = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'ADMINISTRATIVO',
                'fecha_hora' => '2026-07-31 11:45:00',
                'importe' => '40.10',
                'detalle' => 'Compra menor pagada por caja',
            ],
        ))->assertCreated()
            ->assertJsonPath('data.direccion', 'EGRESO')
            ->assertJsonPath('data.contraparte_tipo', 'ADMINISTRATIVO')
            ->assertJsonPath('data.importe', '40.10');
        $expensePaymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $expenseResponse->json('data.id'))
            ->value('pago_id');

        $this->assertDatabaseHas('pagos', [
            'id' => $expensePaymentId,
            'tipo' => 'AJUSTE',
            'cliente_id' => null,
            'cuenta_origen_id' => $this->cashRegisterId,
            'cuenta_destino_id' => null,
            'metodo_pago_id' => $this->cashMethodId,
            'metodo' => 'EFECTIVO',
            'direccion' => 'EGRESO',
            'referencia' => null,
            'importe' => 40.10,
        ]);

        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'fecha_hora' => '2026-08-01 00:05:00',
                'importe' => '999.00',
                'detalle' => 'Movimiento de un dia diferente',
            ],
        ))->assertCreated();

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $incomeId)
            ->assertJsonPath('data.1.id', $expenseResponse->json('data.id'))
            ->assertJsonPath('resumen.ingresos', '150.25')
            ->assertJsonPath('resumen.egresos', '40.10')
            ->assertJsonPath('resumen.neto', '110.15')
            ->assertJsonPath('resumen.moneda', 'PEN');
    }

    public function test_daily_list_places_the_newly_registered_movement_last_even_with_an_earlier_effective_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:00', 'America/Lima'));
        $firstResponse = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'fecha_hora' => '2026-07-31 18:00:00',
                'detalle' => 'Registro creado primero',
            ],
        ))->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:05:00', 'America/Lima'));
        $secondResponse = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'fecha_hora' => '2026-07-31 08:00:00',
                'detalle' => 'Registro recién agregado',
            ],
        ))->assertCreated();

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstResponse->json('data.id'))
            ->assertJsonPath('data.1.id', $secondResponse->json('data.id'));
    }

    public function test_daily_summary_shows_backdated_income_to_own_bank_and_wallet_accounts_without_counting_cash(): void
    {
        $clientId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'CLIENTE',
            'Cliente con depósitos',
            '10777777',
        );
        $providerId = $this->thirdParty(
            (int) $this->user->empresa_id,
            'PROVEEDOR',
            'Proveedor con cuenta externa',
            '20777777777',
        );
        $bankAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Banco propio',
            'BANCO',
        );
        $walletAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Billetera propia',
            'BILLETERA',
        );
        $otherAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Cuenta de otro tipo',
            'OTRA',
        );
        $usdBankAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Banco propio USD',
            'BANCO',
            'ACTIVO',
            'USD',
        );
        $usdCashRegisterId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja USD',
            'CAJA',
            'ACTIVO',
            'USD',
        );
        $externalEntityId = $this->financialEntity(
            (int) $this->user->empresa_id,
            $this->user->id,
            'EXTERNA',
            'Entidad externa del proveedor',
        );
        DB::table('entidades_financieras')
            ->where('id', $externalEntityId)
            ->update(['proveedor_id' => $providerId]);
        $externalBankAccountId = $this->financialAccount(
            $externalEntityId,
            $this->user->id,
            'Banco externo',
            'BANCO',
        );

        $registerIncome = function (
            int $accountId,
            string $amount,
            string $date,
            string $currency = 'PEN',
        ) use ($clientId): int {
            return (int) $this->postJson('/api/v1/finanzas/movimientos', [
                'idempotency_key' => (string) Str::uuid(),
                'tipo' => 'COBRO_CLIENTE',
                'cliente_id' => $clientId,
                'cuenta_destino_id' => $accountId,
                'metodo_pago_id' => $this->cashMethodId,
                'fecha_hora' => $date,
                'moneda' => $currency,
                'importe' => $amount,
                'aplicaciones' => [],
            ])->assertCreated()->json('data.id');
        };

        $bankPaymentId = $registerIncome($bankAccountId, '100.10', '2026-07-31 00:00:00');
        $walletPaymentId = $registerIncome($walletAccountId, '50.25', '2026-07-31 23:59:59');
        $cashPaymentId = $registerIncome($this->cashRegisterId, '800.00', '2026-07-31 10:00:00');
        $otherPaymentId = $registerIncome($otherAccountId, '900.00', '2026-07-31 11:00:00');
        $usdPaymentId = $registerIncome($usdBankAccountId, '75.00', '2026-07-31 12:00:00', 'USD');
        $nextDayPaymentId = $registerIncome($bankAccountId, '500.00', '2026-08-01 00:00:00');
        $openingBalanceId = (int) $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'SALDO_INICIAL',
            'cuenta_destino_id' => $bankAccountId,
            'fecha_hora' => '2026-07-31 12:30:00',
            'moneda' => 'PEN',
            'importe' => '1000.00',
            'aplicaciones' => [],
        ])->assertCreated()->json('data.id');
        $internalTransferId = (int) $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'TRANSFERENCIA_INTERNA',
            'cuenta_origen_id' => $this->cashRegisterId,
            'cuenta_destino_id' => $bankAccountId,
            'fecha_hora' => '2026-07-31 12:45:00',
            'moneda' => 'PEN',
            'importe' => '70.00',
            'aplicaciones' => [],
        ])->assertCreated()->json('data.id');
        $externalPaymentId = (int) $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'PAGO_DIRECTO',
            'cliente_id' => $clientId,
            'proveedor_id' => $providerId,
            'cuenta_destino_id' => $externalBankAccountId,
            'metodo_pago_id' => $this->cashMethodId,
            'fecha_hora' => '2026-07-31 13:00:00',
            'moneda' => 'PEN',
            'importe' => '60.00',
            'aplicaciones' => [],
        ])->assertCreated()->json('data.id');

        DB::table('pagos')
            ->whereIn('id', [
                $bankPaymentId,
                $walletPaymentId,
                $cashPaymentId,
                $otherPaymentId,
                $usdPaymentId,
                $nextDayPaymentId,
                $openingBalanceId,
                $internalTransferId,
                $externalPaymentId,
            ])
            ->update(['created_at' => '2026-08-01 12:00:00']);
        DB::table('cuentas_financieras')
            ->where('id', $walletAccountId)
            ->update(['estado' => 'INACTIVO']);

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('resumen.ingresos', '0.00')
            ->assertJsonPath('resumen.egresos', '0.00')
            ->assertJsonPath('resumen.neto', '0.00')
            ->assertJsonPath('resumen.ingresos_cuentas.0.moneda', 'PEN')
            ->assertJsonPath('resumen.ingresos_cuentas.0.importe', '150.35')
            ->assertJsonPath('resumen.ingresos_cuentas.1.moneda', 'USD')
            ->assertJsonPath('resumen.ingresos_cuentas.1.importe', '75.00');

        $this->getJson($this->dailyUrl($this->otherCashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonPath('resumen.ingresos_cuentas.0.importe', '150.35')
            ->assertJsonPath('resumen.ingresos_cuentas.1.importe', '75.00');
        $this->getJson($this->dailyUrl($usdCashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonPath('resumen.moneda', 'USD')
            ->assertJsonPath('resumen.ingresos_cuentas.0.importe', '150.35')
            ->assertJsonPath('resumen.ingresos_cuentas.1.importe', '75.00');
        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-08-01'))
            ->assertOk()
            ->assertJsonCount(1, 'resumen.ingresos_cuentas')
            ->assertJsonPath('resumen.ingresos_cuentas.0.moneda', 'PEN')
            ->assertJsonPath('resumen.ingresos_cuentas.0.importe', '500.00');
        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-30'))
            ->assertOk()
            ->assertJsonCount(0, 'resumen.ingresos_cuentas');

        $this->postJson("/api/v1/finanzas/movimientos/{$bankPaymentId}/anular", [
            'motivo' => 'El depósito bancario estaba duplicado',
        ])->assertOk();

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonPath('resumen.ingresos_cuentas.0.importe', '50.25')
            ->assertJsonPath('resumen.ingresos_cuentas.1.importe', '75.00');
    }

    public function test_daily_summary_reports_station_two_retail_dispatch_for_the_filtered_journey_without_changing_cash_totals(): void
    {
        $context = $this->retailDispatchSummaryContext($this->user);

        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'INGRESO',
                'contraparte_tipo' => 'OTRO',
                'fecha_hora' => '2026-07-31 09:00:00',
                'importe' => '150.25',
                'detalle' => 'Ingreso que sí pertenece a la caja',
            ],
        ))->assertCreated();
        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'ADMINISTRATIVO',
                'fecha_hora' => '2026-07-31 10:00:00',
                'importe' => '40.10',
                'detalle' => 'Gasto que sí pertenece a la caja',
            ],
        ))->assertCreated();

        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-BALANZA',
            '2026-07-31 11:00:00',
            station: 2,
            weightSource: Balanza::CODE_RETAIL_2,
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-MANUAL',
            '2026-07-30 22:30:00',
            operatingDate: '2026-07-31',
            station: 2,
            weightSource: 'MANUAL',
        );

        $this->createRetailDispatchSummaryTicket(
            $context,
            'M1-CONTROL',
            '2026-07-31 13:00:00',
            station: 1,
            weightSource: 'MANUAL',
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-ANULADO',
            '2026-07-31 14:00:00',
            station: 2,
            weightSource: 'MANUAL',
            ticketStatus: TicketDespacho::STATUS_VOIDED,
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-DEVOLUCION',
            '2026-07-31 15:00:00',
            station: 2,
            weightSource: 'MANUAL',
            operationType: TicketDespacho::OPERATION_RETURN,
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-PESADA-ANULADA',
            '2026-07-31 16:00:00',
            station: 2,
            weightSource: 'MANUAL',
            weighingStatus: Pesada::STATUS_VOIDED,
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'MAYORISTA-CONTROL',
            '2026-07-31 17:00:00',
            station: 2,
            weightSource: Balanza::CODE_RETAIL_2,
            channel: TicketDespacho::CHANNEL_WHOLESALE,
        );
        $this->createRetailDispatchSummaryTicket(
            $context,
            'M2-DIA-SIGUIENTE',
            '2026-07-31 22:30:00',
            operatingDate: '2026-08-01',
            station: 2,
            weightSource: 'MANUAL',
            weight: '2.000',
        );

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('resumen.ingresos', '150.25')
            ->assertJsonPath('resumen.egresos', '40.10')
            ->assertJsonPath('resumen.total', '110.15')
            ->assertJsonPath('resumen.neto', '110.15')
            ->assertJsonCount(0, 'resumen.ingresos_cuentas')
            ->assertJsonPath('resumen.despacho_minorista_2.importe', '2.46')
            ->assertJsonPath('resumen.despacho_minorista_2.moneda', 'PEN')
            ->assertJsonPath('resumen.despacho_minorista_2.solo_informativo', true);

        $usdCashRegisterId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja USD para resumen minorista',
            'CAJA',
            'ACTIVO',
            'USD',
        );
        $this->getJson($this->dailyUrl($usdCashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonPath('resumen.moneda', 'USD')
            ->assertJsonPath('resumen.despacho_minorista_2.importe', '2.46')
            ->assertJsonPath('resumen.despacho_minorista_2.moneda', 'PEN');

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-08-01'))
            ->assertOk()
            ->assertJsonPath('resumen.despacho_minorista_2.importe', '2.46');
    }

    public function test_a_cash_transfer_is_an_expense_in_the_source_and_income_in_the_destination(): void
    {
        $response = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'OTRA_CAJA',
                'otra_caja_id' => $this->otherCashRegisterId,
                'fecha_hora' => '2026-07-31 14:20:00',
                'importe' => '60.00',
                'detalle' => 'Traslado de efectivo a la caja secundaria',
            ],
        ))->assertCreated()
            ->assertJsonPath('data.direccion', 'EGRESO')
            ->assertJsonPath('data.caja.id', $this->cashRegisterId)
            ->assertJsonPath('data.otra_caja.id', $this->otherCashRegisterId)
            ->assertJsonPath('data.importe', '60.00');
        $movementId = (int) $response->json('data.id');
        $paymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movementId)
            ->value('pago_id');

        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'tipo' => 'TRANSFERENCIA_INTERNA',
            'cuenta_origen_id' => $this->cashRegisterId,
            'cuenta_destino_id' => $this->otherCashRegisterId,
            'metodo_pago_id' => $this->cashMethodId,
            'metodo' => 'EFECTIVO',
            'direccion' => 'TRANSFERENCIA',
            'referencia' => null,
            'importe' => 60,
        ]);

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $movementId)
            ->assertJsonPath('data.0.direccion', 'EGRESO')
            ->assertJsonPath('data.0.caja.id', $this->cashRegisterId)
            ->assertJsonPath('data.0.otra_caja.id', $this->otherCashRegisterId)
            ->assertJsonPath('resumen.ingresos', '0.00')
            ->assertJsonPath('resumen.egresos', '60.00')
            ->assertJsonPath('resumen.neto', '-60.00');

        $this->getJson($this->dailyUrl($this->otherCashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $movementId)
            ->assertJsonPath('data.0.direccion', 'INGRESO')
            ->assertJsonPath('data.0.caja.id', $this->otherCashRegisterId)
            ->assertJsonPath('data.0.otra_caja.id', $this->cashRegisterId)
            ->assertJsonPath('resumen.ingresos', '60.00')
            ->assertJsonPath('resumen.egresos', '0.00')
            ->assertJsonPath('resumen.neto', '60.00');
    }

    public function test_expense_counterpart_accepts_exactly_the_supported_categories(): void
    {
        $validCategories = [
            'ADMINISTRATIVO' => [],
            'TRANSPORTE' => [],
            'DEPOSITO' => [],
            'OTRA_CAJA' => ['otra_caja_id' => $this->otherCashRegisterId],
        ];

        foreach (array_keys($validCategories) as $index => $category) {
            $response = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
                (string) Str::uuid(),
                [
                    'direccion' => 'EGRESO',
                    'contraparte_tipo' => $category,
                    'fecha_hora' => sprintf('2026-07-31 12:%02d:00', $index),
                    'importe' => number_format(($index + 1) * 10, 2, '.', ''),
                    'detalle' => "Egreso de categoria {$category}",
                    ...$validCategories[$category],
                ],
            ))->assertCreated()
                ->assertJsonPath('data.direccion', 'EGRESO')
                ->assertJsonPath('data.contraparte_tipo', $category);

            $this->assertDatabaseHas('movimientos_caja_efectivo', [
                'id' => $response->json('data.id'),
                'caja_id' => $this->cashRegisterId,
                'direccion' => 'EGRESO',
                'contraparte_tipo' => $category,
                'otra_caja_id' => $category === 'OTRA_CAJA'
                    ? $this->otherCashRegisterId
                    : null,
            ]);
        }

        $daily = $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('resumen.ingresos', '0.00')
            ->assertJsonPath('resumen.egresos', '100.00')
            ->assertJsonPath('resumen.neto', '-100.00');
        $this->assertEqualsCanonicalizing(
            array_keys($validCategories),
            collect($daily->json('data'))->pluck('contraparte_tipo')->all(),
        );

        foreach (['OTRO', 'CLIENTE', 'PROVEEDOR', 'ADMINISTRACION'] as $invalidCategory) {
            $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
                (string) Str::uuid(),
                [
                    'direccion' => 'EGRESO',
                    'contraparte_tipo' => $invalidCategory,
                ],
            ))->assertUnprocessable()
                ->assertJsonValidationErrors('contraparte_tipo');
        }

        $this->assertDatabaseCount('movimientos_caja_efectivo', 4);
        $this->assertDatabaseCount('pagos', 4);
    }

    public function test_a_legacy_other_expense_can_still_be_edited_without_reclassifying_it(): void
    {
        $movement = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'ADMINISTRATIVO',
                'fecha_hora' => '2026-07-31 13:00:00',
                'importe' => '35.00',
                'detalle' => 'Egreso que simula un registro anterior',
            ],
        ))->assertCreated()->json('data');
        $paymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->value('pago_id');

        DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->update(['contraparte_tipo' => 'OTRO']);

        $this->putJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}", $this->payload(
            null,
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'OTRO',
                'fecha_hora' => '2026-07-31 13:00:00',
                'importe' => '35.00',
                'detalle' => 'Detalle corregido sin reclasificar el egreso historico',
            ],
        ))->assertOk()
            ->assertJsonPath('data.id', $movement['id'])
            ->assertJsonPath('data.contraparte_tipo', 'OTRO')
            ->assertJsonPath('data.detalle', 'Detalle corregido sin reclasificar el egreso historico');

        $this->assertSame(
            $paymentId,
            (int) DB::table('movimientos_caja_efectivo')
                ->where('id', $movement['id'])
                ->value('pago_id'),
        );
        $this->assertDatabaseCount('pagos', 1);
    }

    public function test_editing_financial_fields_reverses_the_old_payment_and_links_the_replacement(): void
    {
        $movement = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'INGRESO',
                'contraparte_tipo' => 'OTRO',
                'fecha_hora' => '2026-07-31 08:00:00',
                'importe' => '120.00',
                'detalle' => 'Fondo recibido al abrir la caja',
            ],
        ))->assertCreated()->json('data');
        $originalPaymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->value('pago_id');

        $this->putJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}", $this->payload(
            null,
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'TRANSPORTE',
                'fecha_hora' => '2026-07-31 08:30:00',
                'importe' => '80.00',
                'detalle' => 'Correccion: el efectivo salio de la caja',
            ],
        ))->assertOk()
            ->assertJsonPath('data.id', $movement['id'])
            ->assertJsonPath('data.direccion', 'EGRESO')
            ->assertJsonPath('data.contraparte_tipo', 'TRANSPORTE')
            ->assertJsonPath('data.importe', '80.00')
            ->assertJsonPath('data.detalle', 'Correccion: el efectivo salio de la caja');

        $replacementPaymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->value('pago_id');
        $this->assertNotSame($originalPaymentId, $replacementPaymentId);
        $this->assertDatabaseCount('movimientos_caja_efectivo', 1);
        $this->assertDatabaseHas('pagos', [
            'id' => $originalPaymentId,
            'estado' => 'ANULADO',
            'importe' => 120,
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $originalPaymentId,
            'cuenta_origen_id' => $this->cashRegisterId,
            'cuenta_destino_id' => null,
            'direccion' => 'EGRESO',
            'importe' => 120,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $replacementPaymentId,
            'cuenta_origen_id' => $this->cashRegisterId,
            'cuenta_destino_id' => null,
            'metodo_pago_id' => $this->cashMethodId,
            'metodo' => 'EFECTIVO',
            'direccion' => 'EGRESO',
            'referencia' => null,
            'importe' => 80,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'movimientos_caja_efectivo',
            'entidad_id' => (string) $movement['id'],
            'accion' => 'CORREGIR_CON_REVERSA',
        ]);

        $this->getJson($this->dailyUrl($this->cashRegisterId, '2026-07-31'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $movement['id'])
            ->assertJsonPath('data.0.direccion', 'EGRESO')
            ->assertJsonPath('resumen.ingresos', '0.00')
            ->assertJsonPath('resumen.egresos', '80.00')
            ->assertJsonPath('resumen.neto', '-80.00');
    }

    public function test_deleting_a_cash_transfer_voids_and_reverses_it_and_removes_it_from_both_daily_views(): void
    {
        $movement = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'OTRA_CAJA',
                'otra_caja_id' => $this->otherCashRegisterId,
                'fecha_hora' => '2026-07-31 15:00:00',
                'importe' => '70.00',
                'detalle' => 'Transferencia que sera anulada',
            ],
        ))->assertCreated()->json('data');
        $paymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->value('pago_id');

        $deleted = $this->deleteJson(
            "/api/v1/finanzas/caja-efectivo/{$movement['id']}",
        )->assertOk()
            ->assertJsonPath('data.id', $movement['id'])
            ->assertJsonPath('data.estado', 'ANULADO')
            ->assertJsonPath('meta.idempotent', false);
        $reverseId = (int) $deleted->json('reversa_id');

        $this->assertGreaterThan(0, $reverseId);
        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $movement['id'],
            'pago_id' => $paymentId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
        ]);
        $voidedPayment = DB::table('pagos')->where('id', $paymentId)->first();
        $this->assertNotNull($voidedPayment->anulada_at);
        $this->assertNotEmpty($voidedPayment->motivo_anulacion);
        $this->assertDatabaseHas('pagos', [
            'id' => $reverseId,
            'reversa_de_pago_id' => $paymentId,
            'cuenta_origen_id' => $this->otherCashRegisterId,
            'cuenta_destino_id' => $this->cashRegisterId,
            'direccion' => 'REVERSA',
            'importe' => 70,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'movimientos_caja_efectivo',
            'entidad_id' => (string) $movement['id'],
            'accion' => 'ANULAR',
        ]);

        foreach ([$this->cashRegisterId, $this->otherCashRegisterId] as $cashRegisterId) {
            $this->getJson($this->dailyUrl($cashRegisterId, '2026-07-31'))
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('resumen.ingresos', '0.00')
                ->assertJsonPath('resumen.egresos', '0.00')
                ->assertJsonPath('resumen.neto', '0.00');
        }

        $this->deleteJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}")
            ->assertOk()
            ->assertJsonPath('reversa_id', $reverseId)
            ->assertJsonPath('meta.idempotent', true);
        $this->assertDatabaseCount('movimientos_caja_efectivo', 1);
        $this->assertDatabaseCount('pagos', 2);
    }

    public function test_deleting_a_cash_movement_respects_company_isolation_and_void_permission(): void
    {
        $movement = $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'importe' => '45.00',
                'detalle' => 'Movimiento protegido para anulacion',
            ],
        ))->assertCreated()->json('data');
        $paymentId = (int) DB::table('movimientos_caja_efectivo')
            ->where('id', $movement['id'])
            ->value('pago_id');

        $foreignUser = User::factory()->create();
        $this->grantFinanceModule($foreignUser, 'CAJA_DELETE_FOREIGN');
        Sanctum::actingAs($foreignUser, ['api']);

        $this->deleteJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}")
            ->assertNotFound();
        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $movement['id'],
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'REGISTRADO',
        ]);

        $restrictedUser = User::factory()->create([
            'empresa_id' => $this->user->empresa_id,
        ]);
        $this->grantFinanceModule($restrictedUser, 'CAJA_DELETE_RESTRICTED');
        $permissionPath = 'access_modules.modules.MODULO_FINANZAS.technical_permissions';
        $technicalPermissions = config($permissionPath, []);
        config()->set($permissionPath, array_values(array_diff(
            $technicalPermissions,
            ['PAGOS_ANULAR'],
        )));

        try {
            Sanctum::actingAs($restrictedUser, ['api']);
            $this->deleteJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}")
                ->assertForbidden();
        } finally {
            config()->set($permissionPath, $technicalPermissions);
        }

        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $movement['id'],
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseMissing('pagos', [
            'reversa_de_pago_id' => $paymentId,
        ]);

        Sanctum::actingAs($this->user, ['api']);
        $this->deleteJson("/api/v1/finanzas/caja-efectivo/{$movement['id']}")
            ->assertOk();
    }

    public function test_cash_register_movements_reject_invalid_accounts_clients_and_counterpart_combinations(): void
    {
        $bankAccountId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Cuenta bancaria invalida',
            'BANCO',
        );
        $inactiveCashId = $this->financialAccount(
            $this->cashEntityId,
            $this->user->id,
            'Caja bloqueada',
            'CAJA',
            'INACTIVO',
        );
        $externalEntityId = $this->financialEntity(
            (int) $this->user->empresa_id,
            $this->user->id,
            'EXTERNA',
            'Entidad externa invalida',
        );
        $externalCashId = $this->financialAccount(
            $externalEntityId,
            $this->user->id,
            'Caja externa invalida',
        );
        $foreignUser = User::factory()->create();
        $foreignEntityId = $this->financialEntity(
            (int) $foreignUser->empresa_id,
            $foreignUser->id,
            'PROPIA',
            'Entidad extranjera invalida',
        );
        $foreignCashId = $this->financialAccount(
            $foreignEntityId,
            $foreignUser->id,
            'Caja extranjera invalida',
        );

        foreach ([$bankAccountId, $inactiveCashId, $externalCashId, $foreignCashId] as $accountId) {
            $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
                (string) Str::uuid(),
                ['caja_id' => $accountId],
            ))->assertUnprocessable()
                ->assertJsonValidationErrors('caja_id');
        }

        $foreignClientId = $this->thirdParty(
            (int) $foreignUser->empresa_id,
            'CLIENTE',
            'Cliente extranjero invalido',
            '10666666',
        );
        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'INGRESO',
                'contraparte_tipo' => 'CLIENTE',
                'cliente_id' => $foreignClientId,
            ],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_id');

        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'direccion' => 'EGRESO',
                'contraparte_tipo' => 'CLIENTE',
                'cliente_id' => $foreignClientId,
            ],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('contraparte_tipo');

        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'contraparte_tipo' => 'OTRA_CAJA',
                'otra_caja_id' => $this->cashRegisterId,
            ],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('otra_caja_id');

        $this->postJson('/api/v1/finanzas/caja-efectivo', $this->payload(
            (string) Str::uuid(),
            [
                'contraparte_tipo' => 'OTRA_CAJA',
                'otra_caja_id' => $bankAccountId,
            ],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('otra_caja_id');

        $this->assertDatabaseCount('movimientos_caja_efectivo', 0);
        $this->assertDatabaseCount('pagos', 0);
    }

    private function grantFinanceModule(User $user, string $roleCode): void
    {
        $role = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => $roleCode,
            'nombre' => 'Acceso de prueba a finanzas',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id'),
        );
        $user->roles()->attach($role);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(?string $idempotencyKey, array $overrides = []): array
    {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'caja_id' => $this->cashRegisterId,
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'OTRO',
            'fecha_hora' => '2026-07-31 10:00:00',
            'importe' => '25.00',
            'detalle' => 'Movimiento manual de prueba',
        ];

        if ($idempotencyKey === null) {
            unset($payload['idempotency_key']);
        }

        return [...$payload, ...$overrides];
    }

    private function dailyUrl(int $cashRegisterId, string $date): string
    {
        return '/api/v1/finanzas/caja-efectivo?'.http_build_query([
            'caja_id' => $cashRegisterId,
            'fecha' => $date,
        ]);
    }

    /**
     * @return array{
     *     company_id: int,
     *     user_id: int,
     *     branch_id: int,
     *     chicken_type_id: int,
     *     tray_type_id: int,
     *     price_history_id: int,
     *     adjustments: array<int, int>
     * }
     */
    private function retailDispatchSummaryContext(User $user): array
    {
        $companyId = (int) $user->empresa_id;
        $userId = (int) $user->id;
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => "CAJA-{$companyId}",
            'nombre' => 'Sucursal para resumen de caja',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => "POLLO_CAJA_{$companyId}",
            'nombre' => 'Pollo para resumen de caja',
            'permite_despacho' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priceListId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => null,
            'codigo' => "LISTA-CAJA-{$companyId}",
            'nombre' => 'Lista para resumen de caja',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priceHistoryId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $chickenTypeId,
            'precio_kg' => '1.2300',
            'vigente_desde' => '2026-07-01 00:00:00',
            'vigente_hasta' => null,
            'motivo_cambio' => 'Precio para probar el indicador informativo',
            'reemplaza_precio_id' => null,
            'registrado_por' => $userId,
            'created_at' => now(),
        ]);
        $adjustments = [];

        foreach ([1, 2] as $station) {
            $adjustments[$station] = DB::table('ajustes_peso_minorista')->insertGetId([
                'empresa_id' => $companyId,
                'estacion' => $station,
                'codigo' => 'CAJA_RESUMEN',
                'nombre' => "Ajuste caja puesto {$station}",
                'sexo' => 'MACHO',
                'presentacion' => 'CERRADO',
                'gramos_adicionales' => 0,
                'predeterminado' => true,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'company_id' => $companyId,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'chicken_type_id' => $chickenTypeId,
            'tray_type_id' => (int) DB::table('tipos_bandeja')->value('id'),
            'price_history_id' => (int) $priceHistoryId,
            'adjustments' => $adjustments,
        ];
    }

    /** @param array<string, mixed> $context */
    private function createRetailDispatchSummaryTicket(
        array $context,
        string $code,
        string $closedAt,
        ?string $operatingDate = null,
        int $station = 2,
        string $weightSource = 'MANUAL',
        string $ticketStatus = TicketDespacho::STATUS_CLOSED,
        string $operationType = TicketDespacho::OPERATION_DISPATCH,
        string $weighingStatus = Pesada::STATUS_ACTIVE,
        string $channel = TicketDespacho::CHANNEL_RETAIL,
        string $weight = '1.004',
        string $price = '1.2300',
    ): int {
        $operatingDate ??= substr($closedAt, 0, 10);
        $journeyId = DB::table('jornadas_operativas')
            ->where('sucursal_id', $context['branch_id'])
            ->whereDate('fecha_operativa', $operatingDate)
            ->value('id');

        if (! $journeyId) {
            $journeyId = DB::table('jornadas_operativas')->insertGetId([
                'sucursal_id' => $context['branch_id'],
                'fecha_operativa' => $operatingDate,
                'estado' => 'ABIERTA',
                'abierta_por' => $context['user_id'],
                'inicio_at' => "{$operatingDate} 06:00:00",
                'cierre_programado_at' => "{$operatingDate} 21:00:00",
            ]);
        }

        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => $channel,
            'tipo_operacion' => $operationType,
            'cliente_destino_id' => null,
            'almacen_destino_id' => null,
            'estado' => $ticketStatus,
            'cerrado_por' => $context['user_id'],
            'cerrado_at' => $closedAt,
            'created_by' => $context['user_id'],
            'created_at' => $closedAt,
            'updated_at' => $closedAt,
        ]);
        DB::table('ticket_precios')->insert([
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $context['chicken_type_id'],
            'precio_historial_id' => $context['price_history_id'],
            'precio_kg' => $price,
            'origen_precio' => 'GENERAL',
            'congelado_por' => $context['user_id'],
            'created_at' => $closedAt,
        ]);
        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $context['chicken_type_id'],
            'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
            'sexo' => Pesada::SEX_MALE,
            'presentacion_pollo' => 'CERRADO',
            'tipo_java_id' => null,
            'tipo_bandeja_id' => $context['tray_type_id'],
            'ajuste_peso_minorista_id' => $context['adjustments'][$station],
            'origen_peso' => $weightSource,
            'aves_por_java' => null,
            'aves_por_bandeja' => 5,
            'cantidad_javas' => null,
            'cantidad_bandejas' => 1,
            'cantidad_aves' => 5,
            'peso_java_kg_snapshot' => null,
            'peso_bandeja_kg_snapshot' => '0.000',
            'peso_leido_kg' => $weight,
            'ajuste_peso_gramos' => 0,
            'peso_bruto_kg' => $weight,
            'tara_total_kg' => '0.000',
            'peso_neto_kg' => $weight,
            'pesada_at' => $closedAt,
            'estado' => $weighingStatus,
            'created_by' => $context['user_id'],
            'created_at' => $closedAt,
            'updated_at' => $closedAt,
        ]);

        return (int) $ticketId;
    }

    private function financialEntity(
        int $companyId,
        int $creatorId,
        string $type,
        string $name,
    ): int {
        return DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $companyId,
            'tipo' => $type,
            'razon_social' => $name,
            'estado' => 'ACTIVO',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function financialAccount(
        int $entityId,
        int $creatorId,
        string $alias,
        string $type = 'CAJA',
        string $status = 'ACTIVO',
        string $currency = 'PEN',
    ): int {
        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => $type,
            'alias' => $alias,
            'moneda' => $currency,
            'estado' => $status,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function thirdParty(
        int $companyId,
        string $role,
        string $name,
        string $document,
        string $status = 'ACTIVO',
    ): int {
        $thirdPartyId = DB::table('terceros')->insertGetId([
            'empresa_id' => $companyId,
            'tipo_documento' => strlen($document) === 11 ? 'RUC' : 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Direccion de prueba',
            'estado' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $thirdPartyId,
            'rol' => $role,
            'created_at' => now(),
        ]);

        return $thirdPartyId;
    }
}
