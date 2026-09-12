<?php

namespace Tests\Feature;

use App\Models\ProductoDespacho;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Services\ProductDispatchJourneyReclassificationService;
use App\Services\ProductDispatchOperationService;
use App\Services\ProductDispatchSaleDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductDispatchJourneyReclassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reclassifies_all_branches_at_the_exact_cutoff_and_preserves_other_companies(): void
    {
        $user = User::factory()->create();
        $before = $this->ticket($user, 'America/Lima', ['2026-09-10 21:59:59'], '2026-09-11');
        $exact = $this->ticket($user, 'America/Los_Angeles', ['2026-09-10 22:00:00'], '2026-09-10');
        $deleted = $this->ticket($user, 'America/Lima', ['2026-09-10 21:30:00'], '2026-09-11');
        DB::table('tickets_despacho_productos')->where('id', $deleted)->update(['estado' => 'ELIMINADO']);
        DB::table('sucursales')
            ->where('id', DB::table('tickets_despacho_productos')->where('id', $deleted)->value('sucursal_id'))
            ->update(['estado' => 'INACTIVO']);
        $other = $this->ticket(User::factory()->create(), 'America/Lima', ['2026-09-10 21:30:00'], '2026-09-11');

        $this->assertSame(3, $this->reclassify((int) $user->empresa_id, '22:00:00'));
        $this->assertDate($before, '2026-09-10');
        $this->assertDate($exact, '2026-09-11');
        $this->assertDate($deleted, '2026-09-10');
        $this->assertDate($other, '2026-09-11');
        $this->assertSame(0, $this->reclassify((int) $user->empresa_id, '22:00:00'));
    }

    public function test_uses_the_first_weighing_and_keeps_a_ticket_crossing_the_new_cutoff_intact(): void
    {
        $user = User::factory()->create();
        $id = $this->ticket($user, 'America/Los_Angeles', [
            '2026-09-10 22:15:00',
            '2026-09-10 21:30:00',
        ], '2026-09-11');
        $before = DB::table('pesadas_despacho_productos')->where('ticket_despacho_producto_id', $id)->get();

        $this->assertSame(1, $this->reclassify((int) $user->empresa_id, '22:00:00'));

        $this->assertDate($id, '2026-09-10');
        $this->assertEquals($before, DB::table('pesadas_despacho_productos')->where('ticket_despacho_producto_id', $id)->get());
    }

    public function test_fallback_registration_time_is_converted_to_the_branch_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $user = User::factory()->create();
        $id = $this->ticket($user, 'America/Los_Angeles', [], '2026-09-11');
        DB::table('tickets_despacho_productos')->where('id', $id)->update([
            'registrado_at' => '2026-09-11 04:30:00',
        ]);

        $this->assertSame(1, $this->reclassify((int) $user->empresa_id, '22:00:00'));
        $this->assertDate($id, '2026-09-10');
    }

    public function test_a_reclassified_ticket_crossing_the_cutoff_can_still_be_corrected_without_changing_its_times(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 12)->setTime(10, 0));
        $user = User::factory()->create();
        $id = $this->ticket($user, 'America/Lima', ['2026-09-10 21:30:00', '2026-09-10 22:15:00'], '2026-09-11');
        DB::table('empresas')->where('id', $user->empresa_id)->update(['hora_corte_operativo' => '22:00:00']);
        $this->reclassify((int) $user->empresa_id, '22:00:00');
        $ticket = TicketDespachoProducto::query()->with(['pesadas', 'sucursal'])->findOrFail($id);
        $times = $ticket->pesadas->map(fn ($weighing) => $weighing->getRawOriginal('pesada_at'))->all();

        $updated = app(ProductDispatchOperationService::class)->updateTicket(
            (int) $user->empresa_id,
            $ticket->sucursal,
            $user,
            $id,
            [
                'version' => $ticket->updated_at->toIso8601String(),
                'registered_at' => $ticket->registrado_at->setTimezone('America/Lima')->format('Y-m-d\TH:i:s'),
                'list_number' => 1,
                'ticket_title' => 'Ticket corregido',
                'client_id' => null,
                'weighings' => $ticket->pesadas->map(fn ($weighing): array => [
                    'id' => $weighing->id,
                    'product_id' => $weighing->producto_despacho_id,
                    'variation_id' => null,
                    'quantity' => 2,
                    'price_mode' => 'POR_KG',
                    'unit_price' => 10,
                    'waste_grams_per_unit' => 0,
                    'waste_total_grams' => 0,
                    'tare_grams' => 0,
                    'read_weight_kg' => 2,
                ])->all(),
            ],
        );

        $this->assertSame('2026-09-10', $updated->fecha_operativa->toDateString());
        $this->assertSame(4, $updated->cantidad_total);
        $this->assertSame($times, $updated->pesadas->map(fn ($weighing) => $weighing->getRawOriginal('pesada_at'))->all());
    }

    public function test_reclassifies_internal_document_dates_without_changing_financial_values_or_references(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 12)->setTime(10, 0));
        $user = User::factory()->create();
        $id = $this->ticket($user, 'America/Lima', ['2026-09-10 21:30:00'], '2026-09-11');
        $documentId = app(ProductDispatchSaleDocumentService::class)->create(
            (int) $user->empresa_id,
            TicketDespachoProducto::query()->findOrFail($id),
            $user,
        );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'saldo_pendiente' => 3,
            'estado' => 'PARCIAL',
        ]);
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'COBRO-PRODUCTOS',
            'tipo' => 'COBRO_CLIENTE',
            'direccion' => 'ENTRADA',
            'fecha_hora' => '2026-09-11 15:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => 7,
            'estado' => 'REGISTRADO',
            'created_by' => $user->id,
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'importe_aplicado' => 7,
            'lado' => 'CXC',
        ]);
        $beforeTicket = (array) DB::table('tickets_despacho_productos')->where('id', $id)->first();
        $beforeDocument = (array) DB::table('comprobantes')->where('id', $documentId)->first();
        $beforeLinks = DB::table('comprobante_tickets_despacho_productos')->get();
        $beforePayments = DB::table('pagos')->get();
        $beforeApplications = DB::table('pago_aplicaciones')->get();

        $this->assertSame(1, $this->reclassify((int) $user->empresa_id, '22:00:00'));

        $afterTicket = (array) DB::table('tickets_despacho_productos')->where('id', $id)->first();
        $afterDocument = (array) DB::table('comprobantes')->where('id', $documentId)->first();
        $this->assertSame('2026-09-10', $afterDocument['fecha_emision']);
        $this->assertSame('2026-09-10', $afterDocument['fecha_vencimiento']);
        $this->assertGreaterThan($beforeTicket['updated_at'], $afterTicket['updated_at']);
        unset($beforeTicket['fecha_operativa'], $beforeTicket['updated_at'], $afterTicket['fecha_operativa'], $afterTicket['updated_at']);
        unset($beforeDocument['fecha_emision'], $beforeDocument['fecha_vencimiento'], $beforeDocument['updated_at']);
        unset($afterDocument['fecha_emision'], $afterDocument['fecha_vencimiento'], $afterDocument['updated_at']);
        $this->assertSame($beforeTicket, $afterTicket);
        $this->assertSame($beforeDocument, $afterDocument);
        $this->assertEquals($beforeLinks, DB::table('comprobante_tickets_despacho_productos')->get());
        $this->assertEquals($beforePayments, DB::table('pagos')->get());
        $this->assertEquals($beforeApplications, DB::table('pago_aplicaciones')->get());
        $this->assertDatabaseHas('auditoria_eventos', ['entidad' => 'tickets_despacho_productos', 'entidad_id' => (string) $id, 'accion' => 'RECALCULAR_JORNADA']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad' => 'comprobantes', 'entidad_id' => (string) $documentId, 'accion' => 'RECALCULAR_JORNADA']);
    }

    public function test_reclassification_preserves_custom_document_issue_and_due_dates(): void
    {
        $user = User::factory()->create();
        $id = $this->ticket($user, 'America/Lima', ['2026-09-10 21:30:00'], '2026-09-11');
        $documentId = app(ProductDispatchSaleDocumentService::class)->create(
            (int) $user->empresa_id, TicketDespachoProducto::query()->findOrFail($id), $user,
        );
        DB::table('comprobantes')->where('id', $documentId)->update([
            'fecha_emision' => '2026-09-12', 'fecha_vencimiento' => '2026-10-12',
        ]);

        $this->assertSame(1, $this->reclassify((int) $user->empresa_id, '22:00:00'));

        $this->assertDate($id, '2026-09-10');
        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'fecha_emision' => '2026-09-12', 'fecha_vencimiento' => '2026-10-12']);
    }

    private function reclassify(int $companyId, string $cutoff): int
    {
        return DB::transaction(fn (): int => app(ProductDispatchJourneyReclassificationService::class)->reclassify($companyId, $cutoff));
    }

    private function assertDate(int $ticketId, string $date): void
    {
        $this->assertDatabaseHas('tickets_despacho_productos', ['id' => $ticketId, 'fecha_operativa' => $date]);
    }

    /** @param list<string> $times */
    private function ticket(User $user, string $timezone, array $times, string $date): int
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => (string) Str::uuid(),
            'nombre' => 'Sucursal de prueba',
            'zona_horaria' => $timezone,
            'estado' => 'ACTIVO',
        ]);
        $product = ProductoDespacho::query()->create([
            'empresa_id' => $user->empresa_id,
            'nombre' => 'Producto '.$branchId,
            'nombre_normalizado' => 'producto '.$branchId,
            'modo_precio' => 'POR_KG',
            'precio_venta' => 10,
            'merma_gramos_unidad' => 0,
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $ticketId = DB::table('tickets_despacho_productos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'sucursal_id' => $branchId,
            'referencia_externa' => (string) Str::uuid(),
            'codigo' => 'PD-'.$branchId,
            'fecha_operativa' => $date,
            'tipo_cliente' => 'VENTA_PUBLICO',
            'cliente_nombre_snapshot' => 'Venta al público',
            'moneda' => 'PEN',
            'cantidad_total' => max(1, count($times)),
            'peso_leido_total_kg' => max(1, count($times)),
            'merma_total_gramos' => 0,
            'peso_neto_total_kg' => max(1, count($times)),
            'subtotal' => 10 * max(1, count($times)),
            'total' => 10 * max(1, count($times)),
            'estado' => 'REGISTRADO',
            'registrado_at' => '2026-09-11 12:00:00',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($times as $index => $time) {
            DB::table('pesadas_despacho_productos')->insert([
                'ticket_despacho_producto_id' => $ticketId,
                'numero' => $index + 1,
                'producto_despacho_id' => $product->id,
                'producto_nombre_snapshot' => $product->nombre,
                'modo_precio_snapshot' => 'POR_KG',
                'precio_catalogo_snapshot' => 10,
                'precio_venta_snapshot' => 10,
                'origen_precio' => 'CATALOGO',
                'cantidad' => 1,
                'origen_peso' => 'MANUAL',
                'peso_leido_kg' => 1,
                'merma_catalogo_gramos_unidad' => 0,
                'merma_total_gramos' => 0,
                'peso_neto_kg' => 1,
                'importe' => 10,
                'pesada_at' => $time,
                'created_by' => $user->id,
            ]);
        }

        return $ticketId;
    }
}
