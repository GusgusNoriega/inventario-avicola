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

class CompanyExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $accountId;

    private int $methodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'GASTOS_TEST',
            'nombre' => 'Gastos test',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id'),
        );
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);

        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Caja de la empresa',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->accountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => 'CAJA',
            'alias' => 'Caja Debeto',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->methodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'EFECTIVO')
            ->value('id');

        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'SALDO_INICIAL',
            'cuenta_destino_id' => $this->accountId,
            'moneda' => 'PEN',
            'importe' => '500.00',
            'aplicaciones' => [],
        ])->assertCreated();
    }

    public function test_expense_registration_subtracts_the_selected_cash_account_and_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload($key, '120.00');

        $response = $this->postJson('/api/v1/finanzas/gastos', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.categoria', 'MANTENIMIENTO')
            ->assertJsonPath('data.concepto', 'Compra de llanta')
            ->assertJsonPath('data.destino', 'Taller San José')
            ->assertJsonPath('data.cuenta.alias', 'Caja Debeto')
            ->assertJsonPath('data.importe', '120.00')
            ->assertJsonPath('data.estado', 'REGISTRADO');
        $expenseId = $response->json('data.id');

        $this->assertDatabaseHas('pagos', [
            'tipo' => 'GASTO_EMPRESA',
            'cuenta_origen_id' => $this->accountId,
            'cuenta_destino_id' => null,
            'direccion' => 'EGRESO',
            'importe' => 120,
            'estado' => 'REGISTRADO',
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '380.00');

        $this->postJson('/api/v1/finanzas/gastos', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true)
            ->assertJsonPath('data.id', $expenseId);
        $this->assertDatabaseCount('gastos_empresa', 1);

        $changed = $payload;
        $changed['importe'] = '121.00';
        $this->postJson('/api/v1/finanzas/gastos', $changed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_editing_financial_fields_reverses_the_old_movement_and_registers_the_correction(): void
    {
        $expense = $this->postJson(
            '/api/v1/finanzas/gastos',
            $this->payload((string) Str::uuid(), '120.00'),
        )->assertCreated()->json('data');
        $originalPaymentId = DB::table('gastos_empresa')
            ->where('id', $expense['id'])
            ->value('pago_id');

        $updated = $this->payload(null, '80.00');
        unset($updated['idempotency_key']);
        $updated['concepto'] = 'Compra de dos camisas';
        $updated['categoria'] = 'INDUMENTARIA';

        $this->putJson("/api/v1/finanzas/gastos/{$expense['id']}", $updated)
            ->assertOk()
            ->assertJsonPath('data.concepto', 'Compra de dos camisas')
            ->assertJsonPath('data.categoria', 'INDUMENTARIA')
            ->assertJsonPath('data.importe', '80.00');

        $newPaymentId = DB::table('gastos_empresa')
            ->where('id', $expense['id'])
            ->value('pago_id');
        $this->assertNotSame($originalPaymentId, $newPaymentId);
        $this->assertDatabaseHas('pagos', [
            'id' => $originalPaymentId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $originalPaymentId,
            'direccion' => 'INGRESO',
            'importe' => 120,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $newPaymentId,
            'tipo' => 'GASTO_EMPRESA',
            'direccion' => 'EGRESO',
            'importe' => 80,
        ]);
        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '420.00');
        $this->assertDatabaseHas('auditoria_eventos', [
            'entidad' => 'gastos_empresa',
            'entidad_id' => (string) $expense['id'],
            'accion' => 'CORREGIR_CON_REVERSA',
        ]);
    }

    public function test_voiding_expense_reintegrates_money_and_keeps_history(): void
    {
        $expense = $this->postJson(
            '/api/v1/finanzas/gastos',
            $this->payload((string) Str::uuid(), '75.00'),
        )->assertCreated()->json('data');

        $this->postJson("/api/v1/finanzas/gastos/{$expense['id']}/anular", [
            'motivo' => 'El comprobante fue registrado por duplicado',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', false)
            ->assertJsonPath('data.estado', 'ANULADO');

        $this->getJson('/api/v1/finanzas/saldos')
            ->assertOk()
            ->assertJsonPath('data.0.saldo', '500.00');
        $this->getJson('/api/v1/finanzas/gastos')
            ->assertOk()
            ->assertJsonPath('resumen.total_vigente', '0.00')
            ->assertJsonPath('data.0.motivo_anulacion', 'El comprobante fue registrado por duplicado');

        $this->postJson("/api/v1/finanzas/gastos/{$expense['id']}/anular", [
            'motivo' => 'Segundo intento de anulación',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true);
    }

    public function test_expense_rejects_insufficient_balance_and_external_accounts(): void
    {
        $this->postJson(
            '/api/v1/finanzas/gastos',
            $this->payload((string) Str::uuid(), '501.00'),
        )->assertUnprocessable()
            ->assertJsonValidationErrors('importe');

        $providerId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20111111111',
            'nombre_razon_social' => 'Proveedor externo',
            'direccion' => 'Dirección de prueba',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $externalEntity = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'EXTERNA',
            'proveedor_id' => $providerId,
            'razon_social' => 'Cuenta externa',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $externalAccount = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $externalEntity,
            'tipo' => 'CAJA',
            'alias' => 'Caja externa',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = $this->payload((string) Str::uuid(), '10.00');
        $payload['cuenta_origen_id'] = $externalAccount;

        $this->postJson('/api/v1/finanzas/gastos', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cuenta_origen_id');
    }

    /** @return array<string, mixed> */
    private function payload(?string $key, string $amount): array
    {
        return [
            'idempotency_key' => $key,
            'fecha_hora' => '2026-07-24 10:30:00',
            'categoria' => 'MANTENIMIENTO',
            'concepto' => 'Compra de llanta',
            'destino' => 'Taller San José',
            'numero_documento' => 'F001-25',
            'cuenta_origen_id' => $this->accountId,
            'metodo_pago_id' => $this->methodId,
            'moneda' => 'PEN',
            'importe' => $amount,
            'referencia' => 'REC-25',
            'observaciones' => 'Para el camión principal',
        ];
    }
}
