<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Pesada;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyDispatchTicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    private int $warehouseId;

    private int $providerId;

    private int $vehicleId;

    private int $liveTypeId;

    private int $dressedTypeId;

    private int $cageTypeId;

    private int $trayTypeId;

    private int $retailAdjustmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permission = Permission::query()->updateOrCreate(
            ['codigo' => 'TICKETS_DIA_VER'],
            ['descripcion' => 'Ver resumen de tickets del dia']
        );
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'DESPACHO',
            'nombre' => 'Despacho',
        ]);
        $role->permissions()->attach($permission);
        $this->user->roles()->attach($role);

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

        $this->liveTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->dressedTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_DRESSED,
            'nombre' => 'Pollo pelado',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_700',
            'nombre' => 'Java 7.00 kg',
            'peso_kg' => 7,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trayTypeId = (int) DB::table('tipos_bandeja')
            ->where('codigo', 'BANDEJA_ESTANDAR')
            ->value('id');
        $this->retailAdjustmentId = DB::table('ajustes_peso_minorista')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'MACHO_CERRADO',
            'nombre' => 'Macho cerrado',
            'sexo' => Pesada::SEX_MALE,
            'presentacion' => 'CERRADO',
            'gramos_adicionales' => 250,
            'predeterminado' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clientId = $this->createParty('Cliente destino', '20111111111');
        $this->providerId = $this->createParty('Proveedor origen', '20222222222');
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => 'ALMACEN_1',
            'nombre' => 'Almacen principal',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->vehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'ABC-123',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['api']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_summary_returns_day_tickets_without_financial_fields(): void
    {
        $this->createTicket('T-20260626-001', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 2,
                'gross_weight' => 114,
                'tare_weight' => 14,
                'net_weight' => 100,
                'weighed_at' => '2026-06-26 09:15:00',
            ],
        ]);
        $this->createTicket('T-20260626-002', '2026-06-26', [
            [
                'type_id' => $this->dressedTypeId,
                'birds_per_cage' => 20,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-26 10:10:00',
                'warehouse_origin' => true,
            ],
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 22,
                'cages' => 1,
                'gross_weight' => 67,
                'tare_weight' => 7,
                'net_weight' => 60,
                'weighed_at' => '2026-06-26 10:20:00',
            ],
        ], true);
        $this->createTicket('D-20260626-001', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 10,
                'cages' => 1,
                'gross_weight' => 37,
                'tare_weight' => 7,
                'net_weight' => 30,
                'weighed_at' => '2026-06-26 11:30:00',
            ],
        ], false, TicketDespacho::OPERATION_RETURN);
        $this->createTicket('T-20260625-001', '2026-06-25', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-25 09:15:00',
            ],
        ]);

        $response = $this->getJson('/api/v1/operacion/tickets-dia?date=2026-06-26')
            ->assertOk()
            ->assertJsonPath('data.operating_date', '2026-06-26')
            ->assertJsonPath('data.range.from_date', '2026-06-25')
            ->assertJsonPath('data.range.from_time', '21:00')
            ->assertJsonPath('data.range.to_date', '2026-06-26')
            ->assertJsonPath('data.range.to_time', '21:00')
            ->assertJsonPath('data.summary.tickets', 3)
            ->assertJsonPath('data.summary.records', 4)
            ->assertJsonPath('data.summary.cages', 5)
            ->assertJsonPath('data.summary.trays', 0)
            ->assertJsonPath('data.summary.birds', 102)
            ->assertJsonPath('data.summary.gross_weight_kg', 275)
            ->assertJsonPath('data.summary.tare_weight_kg', 35)
            ->assertJsonPath('data.summary.net_weight_kg', 240)
            ->assertJsonPath('data.summary.by_operation.0.operation_type', TicketDespacho::OPERATION_DISPATCH)
            ->assertJsonPath('data.summary.by_operation.0.tickets', 2)
            ->assertJsonPath('data.summary.by_operation.0.net_weight_kg', 210)
            ->assertJsonPath('data.summary.by_operation.1.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.summary.by_operation.1.tickets', 1)
            ->assertJsonPath('data.summary.by_operation.1.net_weight_kg', 30)
            ->assertJsonCount(1, 'data.summary.by_client')
            ->assertJsonPath('data.summary.by_client.0.client.name', 'Cliente destino')
            ->assertJsonPath('data.summary.by_client.0.cages', 2)
            ->assertJsonPath('data.summary.by_client.0.birds', 50)
            ->assertJsonPath('data.summary.by_client.0.gross_weight_kg', 114)
            ->assertJsonPath('data.summary.by_client.0.tare_weight_kg', 14)
            ->assertJsonPath('data.summary.by_client.0.dispatch_net_weight_kg', 100)
            ->assertJsonPath('data.summary.by_client.0.return_net_weight_kg', 30)
            ->assertJsonPath('data.summary.by_client.0.net_weight_kg', 70)
            ->assertJsonPath('data.tickets.0.destination.type', 'ALMACEN')
            ->assertJsonPath('data.tickets.0.channel', TicketDespacho::CHANNEL_WHOLESALE)
            ->assertJsonPath('data.tickets.0.summary.trays', 0)
            ->assertJsonPath('data.tickets.0.records.0.origin.type', 'ALMACEN')
            ->assertJsonPath('data.tickets.0.records.0.chicken_sex', Pesada::SEX_MALE)
            ->assertJsonPath('data.tickets.0.records.0.presentation', null)
            ->assertJsonPath('data.tickets.0.records.0.adjustment', null)
            ->assertJsonPath('data.tickets.0.records.0.read_weight_kg', 57);

        $ticket = $response->json('data.tickets.0');
        $record = $ticket['records'][0];

        $this->assertArrayNotHasKey('prices', $ticket);
        $this->assertArrayNotHasKey('amount', $ticket['summary']);
        $this->assertArrayNotHasKey('price_kg', $record);
        $this->assertArrayNotHasKey('amount', $record);
    }

    public function test_daily_summary_defaults_to_current_operating_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 22:30:00', 'America/Lima'));

        $this->createTicket('T-20260626-001', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-26 09:15:00',
            ],
        ]);
        $this->createTicket('T-20260627-001', '2026-06-27', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 2,
                'gross_weight' => 114,
                'tare_weight' => 14,
                'net_weight' => 100,
                'weighed_at' => '2026-06-26 22:15:00',
            ],
        ]);

        $this->getJson('/api/v1/operacion/tickets-dia')
            ->assertOk()
            ->assertJsonPath('data.operating_date', '2026-06-27')
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.summary.net_weight_kg', 100);
    }

    public function test_daily_summary_filters_by_start_and_end_datetime(): void
    {
        $this->createTicket('T-20260626-001', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-26 09:15:00',
            ],
        ]);
        $this->createTicket('T-20260626-002', '2026-06-26', [
            [
                'type_id' => $this->dressedTypeId,
                'birds_per_cage' => 20,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-26 10:10:00',
                'warehouse_origin' => true,
            ],
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 22,
                'cages' => 1,
                'gross_weight' => 67,
                'tare_weight' => 7,
                'net_weight' => 60,
                'weighed_at' => '2026-06-26 10:20:00',
            ],
        ], true);

        $this->getJson(
            '/api/v1/operacion/tickets-dia?from_date=2026-06-26&from_time=10:00&to_date=2026-06-26&to_time=10:15'
        )
            ->assertOk()
            ->assertJsonPath('data.range.from_date', '2026-06-26')
            ->assertJsonPath('data.range.from_time', '10:00')
            ->assertJsonPath('data.range.to_date', '2026-06-26')
            ->assertJsonPath('data.range.to_time', '10:15')
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.net_weight_kg', 50)
            ->assertJsonPath('data.tickets.0.code', 'T-20260626-002')
            ->assertJsonCount(1, 'data.tickets.0.records');
    }

    public function test_daily_summary_serializes_retail_trays_and_weight_adjustment(): void
    {
        $this->createTicket('M-20260626-001', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_tray' => 5,
                'trays' => 2,
                'read_weight' => 12,
                'gross_weight' => 12.25,
                'tare_weight' => 0,
                'net_weight' => 12.25,
                'weighed_at' => '2026-06-26 09:15:00',
                'presentation' => 'CERRADO',
                'adjustment_grams' => 250,
            ],
        ], false, TicketDespacho::OPERATION_DISPATCH, TicketDespacho::CHANNEL_RETAIL);

        $this->getJson('/api/v1/operacion/tickets-dia?date=2026-06-26')
            ->assertOk()
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.cages', 0)
            ->assertJsonPath('data.summary.trays', 2)
            ->assertJsonPath('data.summary.birds', 10)
            ->assertJsonPath('data.summary.by_type.0.trays', 2)
            ->assertJsonPath('data.summary.by_client.0.trays', 2)
            ->assertJsonPath('data.tickets.0.channel', TicketDespacho::CHANNEL_RETAIL)
            ->assertJsonPath('data.tickets.0.summary.cages', 0)
            ->assertJsonPath('data.tickets.0.summary.trays', 2)
            ->assertJsonPath('data.tickets.0.records.0.chicken_sex', Pesada::SEX_MALE)
            ->assertJsonPath('data.tickets.0.records.0.presentation', 'CERRADO')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.code', 'MACHO_CERRADO')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.name', 'Macho cerrado')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.additional_grams', 250)
            ->assertJsonPath('data.tickets.0.records.0.cage_type.code', null)
            ->assertJsonPath('data.tickets.0.records.0.tray_type.code', 'BANDEJA_ESTANDAR')
            ->assertJsonPath('data.tickets.0.records.0.tray_type.name', 'Bandeja estandar')
            ->assertJsonPath('data.tickets.0.records.0.birds_per_tray', 5)
            ->assertJsonPath('data.tickets.0.records.0.trays', 2)
            ->assertJsonPath('data.tickets.0.records.0.read_weight_kg', 12)
            ->assertJsonPath('data.tickets.0.records.0.gross_weight_kg', 12.25)
            ->assertJsonPath('data.tickets.0.records.0.net_weight_kg', 12.25);
    }

    public function test_only_an_administrator_can_void_a_ticket(): void
    {
        $ticketId = $this->createTicket('T-20260626-ADMIN', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 1,
                'gross_weight' => 57,
                'tare_weight' => 7,
                'net_weight' => 50,
                'weighed_at' => '2026-06-26 09:15:00',
            ],
        ]);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Error de digitación',
        ])->assertForbidden();

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'anulado_at' => null,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'estado' => Pesada::STATUS_ACTIVE,
        ]);
    }

    public function test_administrator_void_keeps_audited_ticket_and_removes_all_operational_effects(): void
    {
        $administrator = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $this->user->roles()->attach($administrator);

        $ticketId = $this->createTicket('T-20260626-VOID', '2026-06-26', [
            [
                'type_id' => $this->liveTypeId,
                'birds_per_cage' => 25,
                'cages' => 2,
                'gross_weight' => 114,
                'tare_weight' => 14,
                'net_weight' => 100,
                'weighed_at' => '2026-06-26 09:15:00',
            ],
        ]);
        $journeyId = (int) DB::table('tickets_despacho')
            ->where('id', $ticketId)
            ->value('jornada_id');
        DB::table('movimientos_javas')->insert([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'jornada_id' => $journeyId,
            'cliente_id' => $this->clientId,
            'tipo' => 'DESPACHO',
            'cantidad' => 2,
            'cantidad_bandejas' => 0,
            'ticket_despacho_id' => $ticketId,
            'vehiculo_id' => null,
            'conductor_id' => null,
            'fecha_movimiento' => '2026-06-26 09:15:00',
            'observaciones' => null,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $documentId = DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'INTERNO',
            'codigo' => "V-{$ticketId}",
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => "VENTA:TICKET:{$ticketId}",
            'fecha_emision' => '2026-06-26',
            'fecha_vencimiento' => '2026-06-26',
            'moneda' => 'PEN',
            'subtotal' => 100,
            'impuesto' => 0,
            'total' => 100,
            'saldo_pendiente' => 0,
            'estado' => 'PAGADO',
            'contraparte_tipo_documento_snapshot' => 'RUC',
            'contraparte_numero_documento_snapshot' => '20111111111',
            'contraparte_nombre_snapshot' => 'Cliente destino',
            'contraparte_direccion_snapshot' => 'Av. Principal 123',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('comprobante_tickets')->insert([
            'comprobante_id' => $documentId,
            'ticket_id' => $ticketId,
            'importe_aplicado' => 100,
        ]);
        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'PAG-PRUEBA-TICKET',
            'tipo' => 'COBRO_MINORISTA',
            'cliente_id' => $this->clientId,
            'proveedor_id' => null,
            'cuenta_origen_id' => null,
            'cuenta_destino_id' => null,
            'metodo_pago_id' => null,
            'direccion' => 'INGRESO',
            'fecha_hora' => '2026-06-26 09:30:00',
            'metodo' => 'EFECTIVO',
            'referencia' => null,
            'moneda' => 'PEN',
            'importe' => 100,
            'estado' => 'REGISTRADO',
            'idempotency_key' => (string) Str::uuid(),
            'reversa_de_pago_id' => null,
            'observaciones' => 'Cobro del ticket T-20260626-VOID',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXC',
            'importe_aplicado' => 100,
            'created_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Ticket duplicado por error operativo',
        ])->assertOk()
            ->assertJsonPath('data.status', TicketDespacho::STATUS_VOIDED)
            ->assertJsonPath('meta.idempotent', false);

        $this->assertDatabaseHas('tickets_despacho', [
            'id' => $ticketId,
            'estado' => TicketDespacho::STATUS_VOIDED,
            'anulado_por' => $this->user->id,
            'motivo_anulacion' => 'Ticket duplicado por error operativo',
        ]);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'estado' => Pesada::STATUS_VOIDED,
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseMissing('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
        ]);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $documentId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'estado' => 'ANULADO',
            'anulada_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $response->json('data.reversed_payment_ids.0'),
            'reversa_de_pago_id' => $paymentId,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $this->user->empresa_id,
            'usuario_id' => $this->user->id,
            'entidad' => 'tickets_despacho',
            'entidad_id' => (string) $ticketId,
            'accion' => 'ANULAR',
        ]);

        $this->getJson('/api/v1/operacion/tickets-dia?date=2026-06-26&include_voided=1')
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', true)
            ->assertJsonPath('data.summary.tickets', 0)
            ->assertJsonPath('data.summary.net_weight_kg', 0)
            ->assertJsonPath('data.tickets.0.id', $ticketId)
            ->assertJsonPath('data.tickets.0.status', TicketDespacho::STATUS_VOIDED)
            ->assertJsonPath('data.tickets.0.historical_summary.net_weight_kg', 100)
            ->assertJsonPath('data.tickets.0.void_reason', 'Ticket duplicado por error operativo')
            ->assertJsonPath('data.tickets.0.can_void', false);

        $this->postJson("/api/v1/operacion/tickets/{$ticketId}/anular", [
            'motivo' => 'Reintento de anulación',
        ])->assertOk()
            ->assertJsonPath('meta.idempotent', true);
        $this->assertDatabaseCount('pagos', 2);

        $this->user->roles()->detach($administrator);
        $this->getJson('/api/v1/operacion/tickets-dia?date=2026-06-26&include_voided=1')
            ->assertOk()
            ->assertJsonPath('data.access.is_administrator', false)
            ->assertJsonPath('data.summary.tickets', 0)
            ->assertJsonCount(0, 'data.tickets');
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

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function createTicket(
        string $code,
        string $operatingDate,
        array $records,
        bool $toWarehouse = false,
        string $operationType = TicketDespacho::OPERATION_DISPATCH,
        string $channel = TicketDespacho::CHANNEL_WHOLESALE
    ): int {
        $journeyId = DB::table('jornadas_operativas')
            ->where('sucursal_id', $this->branchId)
            ->whereDate('fecha_operativa', $operatingDate)
            ->value('id');

        if (! $journeyId) {
            $journeyId = DB::table('jornadas_operativas')->insertGetId([
                'sucursal_id' => $this->branchId,
                'fecha_operativa' => $operatingDate,
                'estado' => 'ABIERTA',
                'abierta_por' => $this->user->id,
                'inicio_at' => "{$operatingDate} 06:00:00",
                'cierre_programado_at' => "{$operatingDate} 21:00:00",
            ]);
        }

        $closedAt = $toWarehouse ? "{$operatingDate} 12:00:00" : "{$operatingDate} 11:00:00";
        $createdAt = $toWarehouse ? "{$operatingDate} 10:30:00" : "{$operatingDate} 10:00:00";

        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => $channel,
            'tipo_operacion' => $operationType,
            'cliente_destino_id' => $toWarehouse ? null : $this->clientId,
            'almacen_destino_id' => $toWarehouse ? $this->warehouseId : null,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => $closedAt,
            'created_by' => $this->user->id,
            'created_at' => $createdAt,
            'updated_at' => $closedAt,
        ]);

        foreach ($records as $index => $record) {
            $warehouseOrigin = (bool) ($record['warehouse_origin'] ?? false);
            $isRetail = $channel === TicketDespacho::CHANNEL_RETAIL;
            $cages = (int) ($record['cages'] ?? 0);
            $birdsPerCage = (int) ($record['birds_per_cage'] ?? 0);
            $trays = (int) ($record['trays'] ?? 0);
            $birdsPerTray = (int) ($record['birds_per_tray'] ?? 0);

            DB::table('pesadas')->insert([
                'ticket_id' => $ticketId,
                'numero' => $index + 1,
                'tipo_pollo_id' => $record['type_id'],
                'condicion_pollo' => Pesada::CHICKEN_CONDITION_LIVE,
                'sexo' => Pesada::SEX_MALE,
                'presentacion_pollo' => $record['presentation'] ?? null,
                'ajuste_peso_minorista_id' => $isRetail ? $this->retailAdjustmentId : null,
                'ajuste_peso_gramos' => $record['adjustment_grams'] ?? null,
                'tipo_java_id' => $isRetail ? null : $this->cageTypeId,
                'tipo_bandeja_id' => $isRetail ? $this->trayTypeId : null,
                'proveedor_origen_id' => $warehouseOrigin || $isRetail ? null : $this->providerId,
                'almacen_origen_id' => $warehouseOrigin ? $this->warehouseId : null,
                'vehiculo_id' => $warehouseOrigin || $isRetail ? null : $this->vehicleId,
                'placa_snapshot' => $warehouseOrigin || $isRetail ? null : 'ABC-123',
                'origen_peso' => 'BALANZA',
                'aves_por_java' => $isRetail ? null : $birdsPerCage,
                'aves_por_bandeja' => $isRetail ? $birdsPerTray : null,
                'cantidad_javas' => $isRetail ? null : $cages,
                'cantidad_bandejas' => $isRetail ? $trays : null,
                'cantidad_aves' => $isRetail ? $birdsPerTray * $trays : $birdsPerCage * $cages,
                'peso_java_kg_snapshot' => $isRetail ? null : 7,
                'peso_bandeja_kg_snapshot' => $isRetail ? 0 : null,
                'peso_leido_kg' => $record['read_weight'] ?? $record['gross_weight'],
                'peso_bruto_kg' => $record['gross_weight'],
                'tara_total_kg' => $record['tare_weight'],
                'peso_neto_kg' => $record['net_weight'],
                'pesada_at' => $record['weighed_at'],
                'estado' => Pesada::STATUS_ACTIVE,
                'created_by' => $this->user->id,
                'created_at' => $record['weighed_at'],
                'updated_at' => $record['weighed_at'],
            ]);
        }

        return $ticketId;
    }
}
