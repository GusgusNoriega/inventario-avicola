<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class CustomerDiscountApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->makeAdministrator($this->user);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_discount_is_applied_to_customer_debt_and_excess_becomes_credit(): void
    {
        $clientId = $this->client('CLIENTE CON DESCUENTO', '10990011');
        $transactionDate = today()->subDays(7)->toDateString();
        $debt = $this->postJson('/api/v1/finanzas/deudas-clientes', [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'fecha_emision' => today()->subDay()->toDateString(),
            'moneda' => 'PEN',
            'importe' => '100.00',
            'detalle' => 'Deuda para probar descuentos.',
        ])->assertCreated();
        $documentId = (int) $debt->json('data.id');

        $discount = $this->postJson('/api/v1/finanzas/descuentos-clientes', [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'fecha_transaccion' => $transactionDate,
            'importe' => '150.00',
            'motivo' => 'Acuerdo comercial con el cliente.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.cliente.id', $clientId)
            ->assertJsonPath('data.fecha_transaccion', $transactionDate)
            ->assertJsonPath('data.fecha_hora', "{$transactionDate} 00:00:00")
            ->assertJsonPath('data.importe', '150.00')
            ->assertJsonPath('data.importe_aplicado', '100.00')
            ->assertJsonPath('data.saldo_favor', '50.00')
            ->assertJsonPath('data.estado', 'REGISTRADO');
        $discountId = (int) $discount->json('data.id');

        $this->assertDatabaseHas('pagos', [
            'id' => $discountId,
            'tipo' => 'DESCUENTO_CLIENTE',
            'cliente_id' => $clientId,
            'fecha_hora' => "{$transactionDate} 00:00:00",
            'direccion' => 'SIN_FLUJO',
            'importe' => 150,
            'observaciones' => 'Acuerdo comercial con el cliente.',
            'estado' => 'REGISTRADO',
        ]);
        $registeredAt = DB::table('pagos')->where('id', $discountId)->value('created_at');
        $this->assertSame(today()->toDateString(), substr((string) $registeredAt, 0, 10));
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $discountId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => 100,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
        ]);

        $this->getJson("/api/v1/finanzas/clientes/{$clientId}/resumen")
            ->assertOk()
            ->assertJsonPath('data.unapplied', '50.00')
            ->assertJsonPath('data.pending', '-50.00');
        $this->getJson('/api/v1/finanzas/descuentos-clientes?buscar=10990011')
            ->assertOk()
            ->assertJsonPath('data.0.id', $discountId)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_discount_can_be_edited_and_voided_while_restoring_debt(): void
    {
        $clientId = $this->client('CLIENTE PARA CORREGIR', '10990022');
        $debt = $this->postJson('/api/v1/finanzas/deudas-clientes', [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'fecha_emision' => today()->toDateString(),
            'moneda' => 'PEN',
            'importe' => '100.00',
            'detalle' => 'Deuda editable.',
        ])->assertCreated();
        $documentId = (int) $debt->json('data.id');

        $discount = $this->postJson('/api/v1/finanzas/descuentos-clientes', [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'importe' => '80.00',
            'motivo' => 'Motivo inicial del descuento.',
        ])->assertCreated();
        $originalId = (int) $discount->json('data.id');

        $updated = $this->putJson("/api/v1/finanzas/descuentos-clientes/{$originalId}", [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'fecha_transaccion' => today()->subDay()->toDateString(),
            'importe' => '40.00',
            'motivo' => 'Monto corregido por acuerdo final.',
        ])
            ->assertOk()
            ->assertJsonPath('data.fecha_transaccion', today()->subDay()->toDateString())
            ->assertJsonPath('data.importe', '40.00')
            ->assertJsonPath('data.importe_aplicado', '40.00')
            ->assertJsonPath('data.saldo_favor', '0.00')
            ->assertJsonPath('data.motivo', 'Monto corregido por acuerdo final.');
        $replacementId = (int) $updated->json('data.id');

        $this->assertNotSame($originalId, $replacementId);
        $this->assertDatabaseHas('pagos', [
            'id' => $originalId,
            'estado' => 'ANULADO',
            'motivo_anulacion' => 'Registro reemplazado mediante edición.',
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'saldo_pendiente' => 60,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $replacementId,
            'fecha_hora' => today()->subDay()->startOfDay()->toDateTimeString(),
        ]);

        $this->postJson("/api/v1/finanzas/descuentos-clientes/{$replacementId}/anular", [
            'motivo' => 'El descuento fue cancelado.',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'ANULADO');

        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'saldo_pendiente' => 100,
            'estado' => 'PENDIENTE',
        ]);
        $this->getJson("/api/v1/finanzas/clientes/{$clientId}/resumen")
            ->assertOk()
            ->assertJsonPath('data.pending', '100.00');
    }

    public function test_future_transaction_date_is_rejected(): void
    {
        $clientId = $this->client('CLIENTE FECHA FUTURA', '10990033');

        $this->postJson('/api/v1/finanzas/descuentos-clientes', [
            'idempotency_key' => (string) Str::uuid(),
            'cliente_id' => $clientId,
            'fecha_transaccion' => today()->addDay()->toDateString(),
            'importe' => '25.00',
            'motivo' => 'Intento con fecha futura.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_transaccion');

        $this->assertDatabaseMissing('pagos', [
            'tipo' => 'DESCUENTO_CLIENTE',
            'cliente_id' => $clientId,
        ]);
    }

    private function client(string $name, string $document): int
    {
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'SIN DIRECCIÓN',
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
}
