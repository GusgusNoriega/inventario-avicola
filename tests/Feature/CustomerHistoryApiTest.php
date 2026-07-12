<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Pesada;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $customerId;

    private int $chickenTypeId;

    private int $cageTypeId;

    private int $trayTypeId;

    private int $retailAdjustmentId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permissions = collect([
            ['TERCEROS_GESTIONAR', 'Gestionar terceros'],
            ['PRECIOS_GESTIONAR', 'Gestionar precios'],
        ])->map(fn (array $permission) => Permission::query()->create([
            'codigo' => $permission[0],
            'descripcion' => $permission[1],
        ]));
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $role->permissions()->attach($permissions);
        $this->user->roles()->attach($role);

        collect([
            [TipoPollo::CHICKEN_LIVE, 'Pollo vivo'],
            [TipoPollo::CHICKEN_DRESSED, 'Pollo pelado'],
            [TipoPollo::CHICKEN_PROCESSED, 'Pollo beneficiado'],
        ])->each(fn (array $type) => DB::table('tipos_pollo')->insert([
            'codigo' => $type[0],
            'nombre' => $type[1],
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
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
        $this->chickenTypeId = (int) DB::table('tipos_pollo')
            ->where('codigo', TipoPollo::CHICKEN_LIVE)
            ->value('id');
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

        Sanctum::actingAs($this->user, ['api']);

        $this->customerId = $this->postJson('/api/v1/clientes', [
            'nombre_razon_social' => 'Cliente historial',
            'numero_documento' => '20123456789',
            'direccion' => 'Av. Principal 123',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 8.5,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_customer_history_returns_tickets_records_totals_and_prices(): void
    {
        $this->createTicket('T-20260620-001', '2026-06-20', 10, 8.5);

        $this->putJson("/api/v1/clientes/{$this->customerId}", [
            'nombre_razon_social' => 'Cliente historial',
            'numero_documento' => '20123456789',
            'direccion' => 'Av. Principal 123',
            'precios' => [
                TipoPollo::CHICKEN_LIVE => 9.25,
                TipoPollo::CHICKEN_DRESSED => 9.5,
                TipoPollo::CHICKEN_PROCESSED => 10.5,
            ],
        ])->assertOk();

        $this->getJson("/api/v1/clientes/{$this->customerId}/historial")
            ->assertOk()
            ->assertJsonPath('data.client.name', 'CLIENTE HISTORIAL')
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.cages', 2)
            ->assertJsonPath('data.summary.trays', 0)
            ->assertJsonPath('data.summary.net_weight_kg', 10)
            ->assertJsonPath('data.summary.amount', 85)
            ->assertJsonPath('data.tickets.0.code', 'T-20260620-001')
            ->assertJsonPath('data.tickets.0.channel', TicketDespacho::CHANNEL_WHOLESALE)
            ->assertJsonPath('data.tickets.0.operation_type', TicketDespacho::OPERATION_DISPATCH)
            ->assertJsonPath('data.tickets.0.records.0.chicken_condition', Pesada::CHICKEN_CONDITION_LIVE)
            ->assertJsonPath('data.tickets.0.records.0.chicken_sex', Pesada::SEX_MALE)
            ->assertJsonPath('data.tickets.0.records.0.presentation', null)
            ->assertJsonPath('data.tickets.0.records.0.adjustment', null)
            ->assertJsonPath('data.tickets.0.records.0.read_weight_kg', 24)
            ->assertJsonPath('data.tickets.0.records.0.price_kg', 8.5)
            ->assertJsonPath('data.tickets.0.records.0.amount', 85)
            ->assertJsonCount(4, 'data.price_history');
    }

    public function test_customer_history_marks_return_tickets_and_dead_chicken_records(): void
    {
        $this->createTicket(
            'D-20260620-001',
            '2026-06-20',
            10,
            8.5,
            TicketDespacho::OPERATION_RETURN,
            Pesada::CHICKEN_CONDITION_DEAD
        );

        $this->getJson("/api/v1/clientes/{$this->customerId}/historial")
            ->assertOk()
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.summary.net_weight_kg', -10)
            ->assertJsonPath('data.summary.amount', -85)
            ->assertJsonPath('data.tickets.0.code', 'D-20260620-001')
            ->assertJsonPath('data.tickets.0.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.tickets.0.summary.net_weight_kg', -10)
            ->assertJsonPath('data.tickets.0.summary.amount', -85)
            ->assertJsonPath('data.tickets.0.records.0.chicken_type.code', TipoPollo::CHICKEN_DEAD)
            ->assertJsonPath('data.tickets.0.records.0.chicken_condition', Pesada::CHICKEN_CONDITION_DEAD)
            ->assertJsonPath('data.tickets.0.records.0.net_weight_kg', 10)
            ->assertJsonPath('data.tickets.0.records.0.movement_net_weight_kg', -10)
            ->assertJsonPath('data.tickets.0.records.0.price_kg', 8.5)
            ->assertJsonPath('data.tickets.0.records.0.amount', -85);
    }

    public function test_customer_history_subtracts_returns_from_weight_and_amount_totals(): void
    {
        $this->createTicket('T-20260620-001', '2026-06-20', 100, 8.5);
        $this->createTicket(
            'D-20260621-001',
            '2026-06-21',
            10,
            8.5,
            TicketDespacho::OPERATION_RETURN,
            Pesada::CHICKEN_CONDITION_DEAD
        );

        $this->getJson("/api/v1/clientes/{$this->customerId}/historial")
            ->assertOk()
            ->assertJsonPath('data.summary.tickets', 2)
            ->assertJsonPath('data.summary.records', 2)
            ->assertJsonPath('data.summary.net_weight_kg', 90)
            ->assertJsonPath('data.summary.amount', 765)
            ->assertJsonPath('data.tickets.0.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.tickets.0.summary.net_weight_kg', -10)
            ->assertJsonPath('data.tickets.0.summary.amount', -85);
    }

    public function test_customer_history_filters_by_ticket_and_operating_date(): void
    {
        $this->createTicket('T-20260620-001', '2026-06-20', 10, 8.5);
        $this->createTicket('T-20260621-002', '2026-06-21', 20, 8.5);

        $this->getJson("/api/v1/clientes/{$this->customerId}/historial?ticket=002")
            ->assertOk()
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.tickets.0.code', 'T-20260621-002');

        $this->getJson(
            "/api/v1/clientes/{$this->customerId}/historial?fecha_desde=2026-06-20&fecha_hasta=2026-06-20"
        )
            ->assertOk()
            ->assertJsonPath('data.summary.tickets', 1)
            ->assertJsonPath('data.tickets.0.code', 'T-20260620-001');
    }

    public function test_customer_history_serializes_retail_trays_and_weight_adjustment(): void
    {
        $this->createTicket(
            'M-20260622-001',
            '2026-06-22',
            12.25,
            8.5,
            TicketDespacho::OPERATION_DISPATCH,
            Pesada::CHICKEN_CONDITION_LIVE,
            TicketDespacho::CHANNEL_RETAIL,
            [
                'read_weight_kg' => 12,
                'gross_weight_kg' => 12.25,
                'birds_per_tray' => 5,
                'trays' => 2,
                'presentation' => 'CERRADO',
                'adjustment_grams' => 250,
            ]
        );

        $this->getJson("/api/v1/clientes/{$this->customerId}/historial")
            ->assertOk()
            ->assertJsonPath('data.summary.cages', 0)
            ->assertJsonPath('data.summary.trays', 2)
            ->assertJsonPath('data.summary.net_weight_kg', 12.25)
            ->assertJsonPath('data.tickets.0.channel', TicketDespacho::CHANNEL_RETAIL)
            ->assertJsonPath('data.tickets.0.summary.cages', 0)
            ->assertJsonPath('data.tickets.0.summary.trays', 2)
            ->assertJsonPath('data.tickets.0.records.0.chicken_sex', Pesada::SEX_MALE)
            ->assertJsonPath('data.tickets.0.records.0.presentation', 'CERRADO')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.code', 'MACHO_CERRADO')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.name', 'Macho cerrado')
            ->assertJsonPath('data.tickets.0.records.0.adjustment.additional_grams', 250)
            ->assertJsonPath('data.tickets.0.records.0.tray_type', 'Bandeja estandar')
            ->assertJsonPath('data.tickets.0.records.0.birds_per_tray', 5)
            ->assertJsonPath('data.tickets.0.records.0.trays', 2)
            ->assertJsonPath('data.tickets.0.records.0.read_weight_kg', 12)
            ->assertJsonPath('data.tickets.0.records.0.gross_weight_kg', 12.25)
            ->assertJsonPath('data.tickets.0.records.0.net_weight_kg', 12.25);
    }

    private function createTicket(
        string $code,
        string $operatingDate,
        float $netWeight,
        float $price,
        string $operationType = TicketDespacho::OPERATION_DISPATCH,
        string $chickenCondition = Pesada::CHICKEN_CONDITION_LIVE,
        string $channel = TicketDespacho::CHANNEL_WHOLESALE,
        ?array $retailWeighing = null
    ): void {
        $recordTypeCode = $chickenCondition === Pesada::CHICKEN_CONDITION_DEAD
            ? TipoPollo::CHICKEN_DEAD
            : TipoPollo::CHICKEN_LIVE;
        $recordTypeId = (int) DB::table('tipos_pollo')
            ->where('codigo', $recordTypeCode)
            ->value('id');
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => $operatingDate,
            'estado' => 'CERRADA',
            'abierta_por' => $this->user->id,
            'inicio_at' => "{$operatingDate} 06:00:00",
            'cierre_programado_at' => "{$operatingDate} 21:00:00",
            'cerrada_por' => $this->user->id,
            'cerrada_at' => "{$operatingDate} 20:00:00",
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $code,
            'canal' => $channel,
            'tipo_operacion' => $operationType,
            'cliente_destino_id' => $this->customerId,
            'estado' => 'CERRADO',
            'cerrado_por' => $this->user->id,
            'cerrado_at' => "{$operatingDate} 10:30:00",
            'created_by' => $this->user->id,
            'created_at' => "{$operatingDate} 10:00:00",
            'updated_at' => "{$operatingDate} 10:30:00",
        ]);
        $priceHistoryId = DB::table('precios_historial')
            ->join('listas_precios', 'listas_precios.id', '=', 'precios_historial.lista_precio_id')
            ->where('listas_precios.tercero_id', $this->customerId)
            ->where('precios_historial.tipo_pollo_id', $this->chickenTypeId)
            ->orderByDesc('precios_historial.id')
            ->value('precios_historial.id');

        DB::table('ticket_precios')->insert([
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $recordTypeId,
            'precio_historial_id' => $priceHistoryId,
            'precio_kg' => $price,
            'origen_precio' => 'CLIENTE',
            'congelado_por' => $this->user->id,
            'created_at' => "{$operatingDate} 10:00:00",
        ]);
        $readWeight = (float) ($retailWeighing['read_weight_kg'] ?? ($netWeight + 14));
        $grossWeight = (float) ($retailWeighing['gross_weight_kg'] ?? ($netWeight + 14));
        $isRetail = $channel === TicketDespacho::CHANNEL_RETAIL;

        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $recordTypeId,
            'condicion_pollo' => $chickenCondition,
            'sexo' => Pesada::SEX_MALE,
            'presentacion_pollo' => $retailWeighing['presentation'] ?? null,
            'ajuste_peso_minorista_id' => $isRetail ? $this->retailAdjustmentId : null,
            'ajuste_peso_gramos' => $retailWeighing['adjustment_grams'] ?? null,
            'tipo_java_id' => $isRetail ? null : $this->cageTypeId,
            'tipo_bandeja_id' => $isRetail ? $this->trayTypeId : null,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => $isRetail ? null : 10,
            'aves_por_bandeja' => $isRetail ? $retailWeighing['birds_per_tray'] : null,
            'cantidad_javas' => $isRetail ? null : 2,
            'cantidad_bandejas' => $isRetail ? $retailWeighing['trays'] : null,
            'cantidad_aves' => $isRetail
                ? $retailWeighing['birds_per_tray'] * $retailWeighing['trays']
                : 20,
            'peso_java_kg_snapshot' => $isRetail ? null : 7,
            'peso_bandeja_kg_snapshot' => $isRetail ? 0 : null,
            'peso_leido_kg' => $readWeight,
            'peso_bruto_kg' => $grossWeight,
            'tara_total_kg' => $isRetail ? 0 : 14,
            'peso_neto_kg' => $netWeight,
            'pesada_at' => "{$operatingDate} 10:15:00",
            'estado' => 'ACTIVA',
            'created_by' => $this->user->id,
            'created_at' => "{$operatingDate} 10:15:00",
            'updated_at' => "{$operatingDate} 10:15:00",
        ]);
    }
}
