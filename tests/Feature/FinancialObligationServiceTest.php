<?php

namespace Tests\Feature;

use App\Models\ListaPrecio;
use App\Models\Pesada;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\FinancialObligationService;
use App\Services\TerceroDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialObligationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $journeyId;

    private int $clientId;

    private int $firstProviderId;

    private int $secondProviderId;

    private int $chickenTypeId;

    private int $salePriceHistoryId;

    private int $ticketSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => now()->toDateString(),
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => now()->subHours(8),
            'cierre_programado_at' => now()->subHour(),
            'cerrada_por' => $this->user->id,
            'cerrada_at' => now(),
        ]);
        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clientId = $this->createParty('Cliente mayorista', '20111111111');
        $this->firstProviderId = $this->createParty('Proveedor norte', '20222222222');
        $this->secondProviderId = $this->createParty('Proveedor sur', '20333333333');
        $this->salePriceHistoryId = $this->createPrice(
            ListaPrecio::OPERATION_SALE,
            $this->clientId,
            '10.0000'
        );
        $this->createPrice(
            ListaPrecio::OPERATION_PURCHASE,
            $this->firstProviderId,
            '6.2500'
        );
        $this->createPrice(
            ListaPrecio::OPERATION_PURCHASE,
            $this->secondProviderId,
            '7.5000'
        );
    }

    public function test_it_creates_one_sale_document_for_each_ticket(): void
    {
        $ticket = $this->createTicket([$this->firstProviderId]);

        $result = $this->sync($ticket);

        $this->assertNotNull($result['sale_document_id']);
        $this->assertSame([], $result['purchase_document_ids']);
        $this->assertSame(0, $result['pending_purchase_costs']);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $result['sale_document_id'],
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'origen_clave' => "VENTA:TICKET:{$ticket->id}",
            'total' => 100,
            'saldo_pendiente' => 100,
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('comprobante_tickets', [
            'comprobante_id' => $result['sale_document_id'],
            'ticket_id' => $ticket->id,
            'importe_aplicado' => 100,
        ]);
        $this->assertDatabaseMissing('comprobantes', ['operacion' => 'COMPRA']);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
    }

    public function test_it_keeps_provider_traceability_without_creating_purchase_obligations(): void
    {
        $ticket = $this->createTicket([
            $this->firstProviderId,
            $this->secondProviderId,
        ]);

        $result = $this->sync($ticket);

        $this->assertSame([], $result['purchase_document_ids']);
        $this->assertSame(0, $result['pending_purchase_costs']);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseMissing('comprobantes', ['operacion' => 'COMPRA']);
        $this->assertDatabaseCount('comprobante_pesadas', 0);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertEqualsCanonicalizing(
            [$this->firstProviderId, $this->secondProviderId],
            DB::table('pesadas')
                ->where('ticket_id', $ticket->id)
                ->pluck('proveedor_origen_id')
                ->map(fn ($providerId): int => (int) $providerId)
                ->all()
        );
    }

    public function test_syncing_the_same_ticket_is_idempotent(): void
    {
        $ticket = $this->createTicket([$this->firstProviderId]);

        $firstResult = $this->sync($ticket);
        $secondResult = $this->sync($ticket->fresh());

        $this->assertSame($firstResult, $secondResult);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('comprobante_detalles', 1);
        $this->assertDatabaseCount('comprobante_tickets', 1);
        $this->assertDatabaseCount('comprobante_pesadas', 0);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
    }

    public function test_a_return_creates_a_sale_credit_and_no_purchase_obligation(): void
    {
        $ticket = $this->createTicket(
            [$this->firstProviderId],
            TicketDespacho::OPERATION_RETURN
        );

        $result = $this->sync($ticket);

        $this->assertNotNull($result['sale_document_id']);
        $this->assertSame([], $result['purchase_document_ids']);
        $this->assertSame(0, $result['pending_purchase_costs']);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $result['sale_document_id'],
            'operacion' => 'VENTA',
            'naturaleza' => 'ABONO',
            'codigo' => "NCV-{$ticket->id}",
            'origen_clave' => "VENTA:TICKET:{$ticket->id}",
            'estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
    }

    public function test_a_provider_without_purchase_price_does_not_create_pending_dispatch_costs(): void
    {
        $providerWithoutPrice = $this->createParty('Proveedor sin precio', '20444444444');
        $ticket = $this->createTicket([$providerWithoutPrice]);

        $result = $this->sync($ticket);

        $this->assertSame([], $result['purchase_document_ids']);
        $this->assertSame(0, $result['pending_purchase_costs']);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseMissing('comprobantes', [
            'operacion' => 'COMPRA',
            'tercero_id' => $providerWithoutPrice,
        ]);
    }

    public function test_adding_a_provider_price_does_not_create_an_obligation_from_dispatch(): void
    {
        $providerId = $this->createParty('Proveedor valorizado despues', '20555555555');
        DB::table('tercero_roles')->insert([
            'tercero_id' => $providerId,
            'rol' => TerceroRole::PROVIDER,
            'created_at' => now(),
        ]);
        $ticket = $this->createTicket([$providerId]);

        $this->sync($ticket->fresh());

        $provider = Tercero::query()->findOrFail($providerId);
        app(TerceroDirectoryService::class)->update(
            $provider,
            (int) $this->user->id,
            TerceroRole::PROVIDER,
            [
                'numero_documento' => $provider->numero_documento,
                'nombre_razon_social' => $provider->nombre_razon_social,
                'direccion' => $provider->direccion,
                'precios' => [TipoPollo::CHICKEN_LIVE => '8.1250'],
            ],
        );

        $this->assertDatabaseHas('precios_historial', [
            'precio_kg' => 8.125,
        ]);
        $this->assertTrue(DB::table('precios_historial as precio')
            ->join('listas_precios as lista', 'lista.id', '=', 'precio.lista_precio_id')
            ->where('lista.tercero_id', $providerId)
            ->where('lista.operacion', ListaPrecio::OPERATION_PURCHASE)
            ->where('precio.tipo_pollo_id', $this->chickenTypeId)
            ->exists());
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseMissing('comprobantes', [
            'operacion' => 'COMPRA',
            'tercero_id' => $providerId,
        ]);
    }

    public function test_rebuild_command_only_rebuilds_sales_and_is_idempotent(): void
    {
        $this->createTicket([$this->firstProviderId]);

        $this->artisan('finanzas:reconstruir-obligaciones', ['--dry-run' => true])
            ->expectsOutputToContain('Documentos de venta')
            ->expectsOutputToContain('TOTAL')
            ->assertSuccessful();

        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
        $this->assertDatabaseCount('auditoria_eventos', 0);

        $this->artisan('finanzas:reconstruir-obligaciones')
            ->assertSuccessful();
        $this->artisan('finanzas:reconstruir-obligaciones')
            ->assertSuccessful();

        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('comprobante_detalles', 1);
        $this->assertDatabaseCount('comprobante_tickets', 1);
        $this->assertDatabaseCount('comprobante_pesadas', 0);
        $this->assertDatabaseCount('costos_compra_pesadas', 0);
    }

    /**
     * @param  array<int, int|null>  $providerIds
     */
    private function createTicket(
        array $providerIds,
        string $operation = TicketDespacho::OPERATION_DISPATCH
    ): TicketDespacho {
        $this->ticketSequence++;
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $this->journeyId,
            'codigo' => 'T-FIN-'.str_pad((string) $this->ticketSequence, 3, '0', STR_PAD_LEFT),
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'tipo_operacion' => $operation,
            'cliente_destino_id' => $this->clientId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ticket_precios')->insert([
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $this->chickenTypeId,
            'precio_historial_id' => $this->salePriceHistoryId,
            'precio_kg' => 10,
            'origen_precio' => 'CLIENTE',
            'congelado_por' => $this->user->id,
            'created_at' => now(),
        ]);

        foreach ($providerIds as $index => $providerId) {
            $weight = ($index + 1) * 10;
            DB::table('pesadas')->insert([
                'ticket_id' => $ticketId,
                'numero' => $index + 1,
                'tipo_pollo_id' => $this->chickenTypeId,
                'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
                'sexo' => Pesada::SEX_MALE,
                'proveedor_origen_id' => $providerId,
                'origen_peso' => 'MANUAL',
                'cantidad_aves' => 10,
                'peso_leido_kg' => $weight,
                'peso_bruto_kg' => $weight,
                'tara_total_kg' => 0,
                'peso_neto_kg' => $weight,
                'pesada_at' => now(),
                'estado' => Pesada::STATUS_ACTIVE,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return TicketDespacho::query()->findOrFail($ticketId);
    }

    /**
     * @return array{sale_document_id: ?int, purchase_document_ids: array<int, int>, pending_purchase_costs: int}
     */
    private function sync(TicketDespacho $ticket): array
    {
        return DB::transaction(fn (): array => app(FinancialObligationService::class)->syncTicket(
            (int) $this->user->empresa_id,
            $ticket,
            $this->user
        ));
    }

    private function createParty(string $name, string $document): int
    {
        return DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Av. Principal 123',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPrice(string $operation, int $partyId, string $price): int
    {
        $listId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $partyId,
            'codigo' => "{$operation}-{$partyId}",
            'nombre' => "Lista {$operation} {$partyId}",
            'operacion' => $operation,
            'estado' => ListaPrecio::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $this->chickenTypeId,
            'precio_kg' => $price,
            'vigente_desde' => now()->subDay(),
            'vigente_hasta' => null,
            'motivo_cambio' => 'Precio de prueba',
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
    }
}
