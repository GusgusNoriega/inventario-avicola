<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialQueryService;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductDispatchFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'FINANZAS_PRODUCTOS_TEST',
            'nombre' => 'Finanzas productos test',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('codigo', 'MODULO_FINANZAS')->value('id')
        );
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);

        $this->branchId = (int) DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'FIN-PRODUCTOS',
            'nombre' => 'Sucursal integración productos',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->customerId = (int) DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => '10887766',
            'nombre_razon_social' => 'Cliente integración productos',
            'direccion' => 'Sin dirección',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $this->customerId,
            'rol' => 'CLIENTE',
            'created_at' => now(),
        ]);
    }

    public function test_financial_queries_link_and_filter_both_dispatch_ticket_types(): void
    {
        $productDocumentId = $this->createDocument('VPD-PRUEBA', '2026-08-20', '15.00');
        $productTicketId = $this->createProductTicket('PD-20260820-001', '15.00');
        DB::table('comprobante_tickets_despacho_productos')->insert([
            'comprobante_id' => $productDocumentId,
            'ticket_despacho_producto_id' => $productTicketId,
            'importe_aplicado' => '15.00',
        ]);

        $journeyId = (int) DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => '2026-08-20',
            'estado' => 'ABIERTA',
            'abierta_por' => $this->user->id,
            'inicio_at' => '2026-08-20 08:00:00',
            'cierre_programado_at' => '2026-08-20 21:00:00',
        ]);
        $classicTicketId = $this->createClassicTicket($journeyId, 'T-20260820-001');
        $this->assertSame($productTicketId, $classicTicketId, 'La prueba requiere una colisión real de IDs.');
        $classicDocumentId = $this->createDocument('VD-PRUEBA', '2026-08-20', '20.00');
        DB::table('comprobante_tickets')->insert([
            'comprobante_id' => $classicDocumentId,
            'ticket_id' => $classicTicketId,
            'importe_aplicado' => '20.00',
        ]);

        $service = app(FinancialQueryService::class);
        $portfolio = $service->portfolio((int) $this->user->empresa_id, [
            'lado' => 'CXC',
            'moneda' => 'PEN',
            'solo_pendientes' => false,
            'per_page' => 50,
        ]);
        $documents = collect($portfolio['data'])->keyBy('codigo');

        $this->assertSame([
            'id' => $productTicketId,
            'tipo' => 'DESPACHO_PRODUCTOS',
            'codigo' => 'PD-20260820-001',
            'canal' => 'PRODUCTOS',
            'estado' => 'REGISTRADO',
        ], $documents->get('VPD-PRUEBA')['tickets'][0]);
        $this->assertSame([
            'id' => $classicTicketId,
            'tipo' => 'DESPACHO_AVICOLA',
            'codigo' => 'T-20260820-001',
            'canal' => 'MAYORISTA',
            'estado' => 'ABIERTO',
        ], $documents->get('VD-PRUEBA')['tickets'][0]);

        $defaultFiltered = $service->portfolio((int) $this->user->empresa_id, [
            'lado' => 'CXC',
            'moneda' => 'PEN',
            'solo_pendientes' => false,
            'ticket_id' => $productTicketId,
            'per_page' => 50,
        ]);
        $this->assertSame(['VD-PRUEBA'], collect($defaultFiltered['data'])->pluck('codigo')->all());

        $productFiltered = $service->portfolio((int) $this->user->empresa_id, [
            'lado' => 'CXC',
            'moneda' => 'PEN',
            'solo_pendientes' => false,
            'ticket_id' => $productTicketId,
            'ticket_tipo' => 'DESPACHO_PRODUCTOS',
            'per_page' => 50,
        ]);
        $this->assertSame(['VPD-PRUEBA'], collect($productFiltered['data'])->pluck('codigo')->all());

        $paymentId = $this->createPayment('PG-PRODUCTOS', '15.00');
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $productDocumentId,
            'lado' => 'CXC',
            'importe_aplicado' => '15.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);
        $classicPaymentId = $this->createPayment('PG-AVICOLA', '20.00');
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $classicPaymentId,
            'comprobante_id' => $classicDocumentId,
            'lado' => 'CXC',
            'importe_aplicado' => '20.00',
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $defaultMovements = $service->movements((int) $this->user->empresa_id, [
            'ticket_id' => $productTicketId,
            'per_page' => 50,
        ]);
        $this->assertSame(['PG-AVICOLA'], collect($defaultMovements['data'])->pluck('codigo')->all());

        $productMovements = $service->movements((int) $this->user->empresa_id, [
            'ticket_id' => $productTicketId,
            'ticket_tipo' => 'DESPACHO_PRODUCTOS',
            'per_page' => 50,
        ]);

        $this->assertSame(['PG-PRODUCTOS'], collect($productMovements['data'])->pluck('codigo')->all());
        $this->assertSame(
            'DESPACHO_PRODUCTOS',
            $productMovements['data'][0]['aplicaciones'][0]['comprobante']['tickets'][0]['tipo'],
        );

        $this->getJson("/api/v1/finanzas/cartera?lado=CXC&solo_pendientes=0&ticket_id={$productTicketId}&ticket_tipo=DESPACHO_PRODUCTOS")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'VPD-PRUEBA');
        $this->getJson("/api/v1/finanzas/movimientos?ticket_id={$productTicketId}&ticket_tipo=DESPACHO_PRODUCTOS")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'PG-PRODUCTOS');
        $this->getJson("/api/v1/finanzas/trazabilidad?ticket_id={$productTicketId}&ticket_tipo=DESPACHO_PRODUCTOS")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'PG-PRODUCTOS');
    }

    public function test_financial_ticket_type_filter_is_validated_and_requires_a_ticket_id(): void
    {
        $this->getJson('/api/v1/finanzas/cartera?lado=CXC&ticket_id=1&ticket_tipo=OTRO')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket_tipo');
        $this->getJson('/api/v1/finanzas/movimientos?ticket_id=1&ticket_tipo=OTRO')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket_tipo');
        $this->getJson('/api/v1/finanzas/trazabilidad?ticket_tipo=DESPACHO_PRODUCTOS')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket_tipo');
    }

    public function test_reports_show_unit_price_for_unit_lines_and_keep_legacy_kg_price(): void
    {
        $unitDocumentId = $this->createDocument('VPD-UNIDADES', '2026-08-19', '8.25');
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $unitDocumentId,
            'tipo_pollo_id' => null,
            'producto_despacho_id' => null,
            'variacion_producto_despacho_id' => null,
            'descripcion' => 'Huevos por unidad',
            'cantidad_aves' => null,
            'cantidad_unidades' => 3,
            'peso_neto_kg' => '0.180',
            'modo_precio' => 'POR_UNIDAD',
            'precio_kg' => null,
            'precio_unitario' => '2.7500',
            'subtotal' => '8.25',
            'created_at' => now(),
        ]);

        $kgDocumentId = $this->createDocument('VD-KILOS', '2026-08-20', '10.00');
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $kgDocumentId,
            'tipo_pollo_id' => null,
            'producto_despacho_id' => null,
            'variacion_producto_despacho_id' => null,
            'descripcion' => 'Producto histórico por kilo',
            'cantidad_aves' => null,
            'cantidad_unidades' => null,
            'peso_neto_kg' => '2.000',
            'modo_precio' => null,
            'precio_kg' => '5.0000',
            'precio_unitario' => null,
            'subtotal' => '10.00',
            'created_at' => now(),
        ]);

        $service = app(ReportDataService::class);
        $route = $service->collectionRouteTwo(
            (int) $this->user->empresa_id,
            '2026-08-20',
            'PEN',
        );
        $routeRows = $route['customers']->firstWhere('id', $this->customerId)['rows']
            ->keyBy('detail');

        $this->assertSame(2.75, (float) $routeRows->get('HUEVOS POR UNIDAD')['price']);
        $this->assertSame(5.0, (float) $routeRows->get('PRODUCTO HISTÓRICO POR KILO')['price']);

        $statement = $service->customerStatement(
            (int) $this->user->empresa_id,
            $this->customerId,
            '2026-08-01',
            '2026-08-31',
        );
        $statementRows = $statement['rows']->keyBy('code');

        $this->assertSame(2.75, $statementRows->get('VPD-UNIDADES')['price']);
        $this->assertSame(5.0, $statementRows->get('VD-KILOS')['price']);
    }

    private function createDocument(string $code, string $date, string $amount): int
    {
        return (int) DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->customerId,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => $code,
            'origen_codigo' => 'PRUEBA_DESPACHO_PRODUCTOS',
            'origen_clave' => 'PRUEBA:'.$code,
            'fecha_emision' => $date,
            'fecha_vencimiento' => $date,
            'moneda' => 'PEN',
            'subtotal' => $amount,
            'impuesto' => '0.00',
            'total' => $amount,
            'saldo_pendiente' => $amount,
            'estado' => Comprobante::STATUS_PENDING,
            'contraparte_tipo_documento_snapshot' => 'DNI',
            'contraparte_numero_documento_snapshot' => '10887766',
            'contraparte_nombre_snapshot' => 'Cliente integración productos',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProductTicket(string $code, string $amount): int
    {
        return (int) DB::table('tickets_despacho_productos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'referencia_externa' => (string) Str::uuid(),
            'codigo' => $code,
            'fecha_operativa' => '2026-08-20',
            'cliente_id' => $this->customerId,
            'tipo_cliente' => 'CLIENTE_REGISTRADO',
            'cliente_tipo_documento_snapshot' => 'DNI',
            'cliente_numero_documento_snapshot' => '10887766',
            'cliente_nombre_snapshot' => 'Cliente integración productos',
            'moneda' => 'PEN',
            'cantidad_total' => 3,
            'peso_leido_total_kg' => '1.000',
            'merma_total_gramos' => 0,
            'peso_neto_total_kg' => '1.000',
            'subtotal' => $amount,
            'total' => $amount,
            'estado' => 'REGISTRADO',
            'registrado_at' => '2026-08-20 10:00:00',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClassicTicket(int $journeyId, string $code): int
    {
        return (int) DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => 'MAYORISTA',
            'tipo_operacion' => 'DESPACHO',
            'estado' => 'ABIERTO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPayment(string $code, string $amount): int
    {
        return (int) DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => $code,
            'tercero_id' => $this->customerId,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $this->customerId,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-08-20 12:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => $amount,
            'estado' => Pago::STATUS_REGISTERED,
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
