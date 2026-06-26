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
            ->assertJsonPath('data.summary.net_weight_kg', 10)
            ->assertJsonPath('data.summary.amount', 85)
            ->assertJsonPath('data.tickets.0.code', 'T-20260620-001')
            ->assertJsonPath('data.tickets.0.operation_type', TicketDespacho::OPERATION_DISPATCH)
            ->assertJsonPath('data.tickets.0.records.0.chicken_condition', Pesada::CHICKEN_CONDITION_LIVE)
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
            ->assertJsonPath('data.summary.amount', 85)
            ->assertJsonPath('data.tickets.0.code', 'D-20260620-001')
            ->assertJsonPath('data.tickets.0.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.tickets.0.records.0.chicken_type.code', TipoPollo::CHICKEN_DEAD)
            ->assertJsonPath('data.tickets.0.records.0.chicken_condition', Pesada::CHICKEN_CONDITION_DEAD)
            ->assertJsonPath('data.tickets.0.records.0.price_kg', 8.5)
            ->assertJsonPath('data.tickets.0.records.0.amount', 85);
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

    private function createTicket(
        string $code,
        string $operatingDate,
        float $netWeight,
        float $price,
        string $operationType = TicketDespacho::OPERATION_DISPATCH,
        string $chickenCondition = Pesada::CHICKEN_CONDITION_LIVE
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
            'canal' => 'MAYORISTA',
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
        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $recordTypeId,
            'condicion_pollo' => $chickenCondition,
            'tipo_java_id' => $this->cageTypeId,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => 10,
            'cantidad_javas' => 2,
            'cantidad_aves' => 20,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => $netWeight + 14,
            'peso_bruto_kg' => $netWeight + 14,
            'tara_total_kg' => 14,
            'peso_neto_kg' => $netWeight,
            'pesada_at' => "{$operatingDate} 10:15:00",
            'estado' => 'ACTIVA',
            'created_by' => $this->user->id,
            'created_at' => "{$operatingDate} 10:15:00",
            'updated_at' => "{$operatingDate} 10:15:00",
        ]);
    }
}
