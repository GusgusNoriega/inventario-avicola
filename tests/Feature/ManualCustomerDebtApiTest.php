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

class ManualCustomerDebtApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'DEUDAS_CLIENTES_TEST',
            'nombre' => 'Deudas de clientes test',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id'),
        );
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_an_previous_customer_debt_becomes_a_receivable_without_moving_cash_and_accepts_payments(): void
    {
        $client = $this->client('CLIENTE CON DEUDA ANTERIOR', '10444555');
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'cliente_id' => $client,
            'fecha_emision' => today()->subDays(5)->toDateString(),
            'moneda' => 'pen',
            'importe' => '125.50',
            'detalle' => '  Saldo anterior pendiente, origen no identificado.  ',
        ];

        $response = $this->postJson('/api/v1/finanzas/deudas-clientes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.lado', 'CXC')
            ->assertJsonPath('data.operacion', 'VENTA')
            ->assertJsonPath('data.naturaleza', 'CARGO')
            ->assertJsonPath('data.tipo_documento', 'SALDO_ANTERIOR')
            ->assertJsonPath('data.total', '125.50')
            ->assertJsonPath('data.saldo_pendiente', '125.50')
            ->assertJsonPath('data.detalle', 'Saldo anterior pendiente, origen no identificado.')
            ->assertJsonPath('data.cliente.id', $client)
            ->assertJsonPath('meta.idempotent', false);
        $documentId = (int) $response->json('data.id');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'SALDO_ANTERIOR',
            'origen_codigo' => 'MANUAL',
            'origen_clave' => "DEUDA_ANTERIOR_CLIENTE:{$key}",
            'total' => 125.50,
            'saldo_pendiente' => 125.50,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('comprobante_detalles', [
            'comprobante_id' => $documentId,
            'descripcion' => 'Saldo anterior pendiente, origen no identificado.',
            'subtotal' => 125.50,
        ]);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $documentId,
            'accion' => 'REGISTRAR_DEUDA_ANTERIOR',
        ]);

        $this->getJson("/api/v1/finanzas/cartera?lado=CXC&cliente_id={$client}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentId)
            ->assertJsonPath('data.0.saldo_pendiente', '125.50')
            ->assertJsonPath('data.0.detalle', 'Saldo anterior pendiente, origen no identificado.')
            ->assertJsonPath('resumen.saldo_neto', '125.50');
        $this->getJson("/api/v1/finanzas/clientes/{$client}/resumen")
            ->assertOk()
            ->assertJsonPath('data.pending', '125.50');

        $this->postJson('/api/v1/finanzas/deudas-clientes', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $documentId)
            ->assertJsonPath('meta.idempotent', true);
        $this->postJson('/api/v1/finanzas/deudas-clientes', [
            ...$payload,
            'importe' => '200.00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertDatabaseCount('comprobantes', 1);

        $account = $this->ownAccount();
        $method = DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(),
            'tipo' => 'COBRO_CLIENTE',
            'fecha_hora' => now()->toDateTimeString(),
            'cliente_id' => $client,
            'cuenta_destino_id' => $account,
            'metodo_pago_id' => $method,
            'moneda' => 'PEN',
            'importe' => '40.00',
            'aplicaciones' => [[
                'lado' => 'CXC',
                'comprobante_id' => $documentId,
                'importe_aplicado' => '40.00',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'saldo_pendiente' => 85.50,
            'estado' => 'PARCIAL',
        ]);
        $this->getJson("/api/v1/finanzas/clientes/{$client}/resumen")
            ->assertOk()
            ->assertJsonPath('data.pending', '85.50');
    }

    public function test_manual_customer_debt_list_filters_by_issue_day(): void
    {
        $client = $this->client('CLIENTE LISTADO DIARIO', '10444556');
        $targetDate = today()->toDateString();
        $otherDate = today()->subDay()->toDateString();

        $register = function (string $date, string $amount, string $detail) use ($client): int {
            return (int) $this->postJson('/api/v1/finanzas/deudas-clientes', [
                'idempotency_key' => (string) Str::uuid(),
                'cliente_id' => $client,
                'fecha_emision' => $date,
                'moneda' => 'PEN',
                'importe' => $amount,
                'detalle' => $detail,
            ])->assertCreated()->json('data.id');
        };

        $firstTargetId = $register($targetDate, '25.00', 'Primera deuda del día consultado.');
        $secondTargetId = $register($targetDate, '35.00', 'Segunda deuda del día consultado.');
        $register($otherDate, '45.00', 'Deuda de un día diferente.');

        $response = $this->getJson(
            "/api/v1/finanzas/deudas-clientes?desde={$targetDate}&hasta={$targetDate}&per_page=100",
        )->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(
            [$secondTargetId, $firstTargetId],
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertSame(
            [$targetDate],
            collect($response->json('data'))->pluck('fecha_emision')->unique()->values()->all(),
        );
    }

    public function test_manual_customer_debt_validates_the_client_date_amount_and_detail(): void
    {
        $client = $this->client('CLIENTE VALIDACIONES', '10444666');
        $base = [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $client,
            'fecha_emision' => today()->toDateString(),
            'moneda' => 'PEN',
            'importe' => '10.00',
            'detalle' => 'Saldo anterior.',
        ];

        $this->postJson('/api/v1/finanzas/deudas-clientes', [
            ...$base,
            'fecha_emision' => today()->addDay()->toDateString(),
            'detalle' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fecha_emision', 'detalle']);

        $foreignUser = User::factory()->create();
        $foreignClient = $this->client(
            'CLIENTE DE OTRA EMPRESA',
            '10444777',
            (int) $foreignUser->empresa_id,
        );
        $this->postJson('/api/v1/finanzas/deudas-clientes', [
            ...$base,
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $foreignClient,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_id');

        DB::table('terceros')->where('id', $client)->update(['estado' => 'INACTIVO']);
        $this->postJson('/api/v1/finanzas/deudas-clientes', [
            ...$base,
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_id');

        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertDatabaseCount('pagos', 0);
    }

    private function client(string $name, string $document, ?int $companyId = null): int
    {
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $companyId ?? $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'SIN DIRECCION',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $id,
            'rol' => 'CLIENTE',
            'created_at' => now(),
        ]);

        return $id;
    }

    private function ownAccount(): int
    {
        $entity = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'EMPRESA PROPIA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entity,
            'tipo' => 'CAJA',
            'alias' => 'CAJA PRINCIPAL',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
