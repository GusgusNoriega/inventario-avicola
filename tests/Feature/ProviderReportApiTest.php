<?php

namespace Tests\Feature;

use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\User;
use App\Services\WholesaleTwoWeightAdjustmentService;
use App\Support\WholesaleTwoChickenVariant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProviderReportApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $warehouseId;

    private int $customerId;

    private int $providerNorthId;

    private int $providerSouthId;

    private int $northTruckId;

    private int $southTruckId;

    private int $chickenTypeId;

    private int $cageTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-01 10:00:00', 'America/Lima')
        );

        $this->user = User::factory()->create();
        $this->grantModules($this->user, ['MODULO_REPORTE_PROVEEDORES']);
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
        $this->warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branchId,
            'codigo' => 'ALM-01',
            'nombre' => 'Almacén central',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->customerId = $this->createParty('Cliente Mercado', '20200000001', TerceroRole::CLIENT);
        $this->providerNorthId = $this->createParty('Proveedor Norte', '20100000001', TerceroRole::PROVIDER);
        $this->providerSouthId = $this->createParty('Proveedor Sur', '20100000002', TerceroRole::PROVIDER);
        $this->northTruckId = $this->createTruck('ABC-123');
        $this->southTruckId = $this->createTruck('XYZ-999');
        $this->chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'POLLO_VIVO',
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA-7',
            'nombre' => 'Java 7 kg',
            'peso_kg' => 7,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['api']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_report_defaults_to_current_journey_and_summarizes_trucks_and_destinations(): void
    {
        $journeyId = $this->createJourney('2026-08-01');
        $this->createWeighing(
            $journeyId,
            'T-001',
            $this->providerNorthId,
            $this->northTruckId,
            'ABC-123',
            cages: 2,
            birds: 20,
            netWeight: 36,
            weighedAt: '2026-08-01 08:00:00',
            customerId: $this->customerId,
        );
        $this->createWeighing(
            $journeyId,
            'T-002',
            $this->providerNorthId,
            $this->northTruckId,
            'ABC-123',
            cages: 3,
            birds: 30,
            netWeight: 54,
            weighedAt: '2026-08-01 09:00:00',
            warehouseId: $this->warehouseId,
        );
        $this->createWeighing(
            $journeyId,
            'T-003',
            $this->providerSouthId,
            $this->southTruckId,
            'XYZ-999',
            cages: 4,
            birds: 40,
            netWeight: 72,
            weighedAt: '2026-08-01 10:00:00',
            warehouseId: $this->warehouseId,
        );
        $this->createWeighing(
            $journeyId,
            'T-VOID',
            $this->providerNorthId,
            $this->northTruckId,
            'ABC-123',
            cages: 99,
            birds: 990,
            netWeight: 999,
            weighedAt: '2026-08-01 11:00:00',
            customerId: $this->customerId,
            ticketStatus: 'ANULADO',
        );

        $this->getJson('/api/v1/operacion/reporte-proveedores')
            ->assertOk()
            ->assertJsonPath('data.current_operating_date', '2026-08-01')
            ->assertJsonPath('data.selected_operating_date', '2026-08-01')
            ->assertJsonPath('data.is_current_journey', true)
            ->assertJsonPath('data.summary.records', 3)
            ->assertJsonPath('data.summary.tickets', 3)
            ->assertJsonPath('data.summary.providers', 2)
            ->assertJsonPath('data.summary.trucks', 2)
            ->assertJsonPath('data.summary.destinations', 2)
            ->assertJsonPath('data.summary.cages', 9)
            ->assertJsonPath('data.summary.birds', 90)
            ->assertJsonPath('data.summary.net_weight_kg', 162)
            ->assertJsonPath('data.summary.average_weight_per_bird_kg', 1.8)
            ->assertJsonPath('data.summary.by_truck.0.provider.name', 'Proveedor Norte')
            ->assertJsonPath('data.summary.by_truck.0.truck.plate', 'ABC-123')
            ->assertJsonPath('data.summary.by_truck.0.cages', 5)
            ->assertJsonCount(2, 'data.summary.by_truck.0.destinations')
            ->assertJsonPath('data.records.0.provider.name', 'Proveedor Sur')
            ->assertJsonPath('data.records.0.destination.name', 'Almacén central')
            ->assertJsonPath('data.records.0.truck.plate', 'XYZ-999')
            ->assertJsonCount(2, 'data.catalog.providers')
            ->assertJsonCount(2, 'data.catalog.trucks');
    }

    public function test_report_exposes_wholesale_two_adjustment_without_redefining_provider_weight(): void
    {
        $journeyId = $this->createJourney('2026-08-01');
        $this->createWeighing(
            $journeyId,
            'T-M2-001',
            $this->providerNorthId,
            $this->northTruckId,
            'ABC-123',
            cages: 2,
            birds: 20,
            netWeight: 36,
            weighedAt: '2026-08-01 08:00:00',
            customerId: $this->customerId,
        );
        app(WholesaleTwoWeightAdjustmentService::class)->ensureDefaults(
            (int) $this->user->empresa_id
        );
        $adjustment = DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', WholesaleTwoChickenVariant::MALE)
            ->first();
        DB::table('ajustes_peso_mayorista_2')
            ->where('id', $adjustment->id)
            ->update(['gramos_adicionales' => 100]);
        $ticketId = (int) DB::table('tickets_despacho')
            ->where('codigo', 'T-M2-001')
            ->value('id');
        DB::table('tickets_despacho')->where('id', $ticketId)->update([
            'modulo_origen' => TicketDespacho::SOURCE_WHOLESALE_TWO,
        ]);
        DB::table('pesadas')->where('ticket_id', $ticketId)->update([
            'ajuste_peso_mayorista_2_id' => $adjustment->id,
            'ajuste_peso_mayorista_2_gramos' => 100,
            'peso_leido_kg' => 48,
        ]);

        $this->getJson('/api/v1/operacion/reporte-proveedores')
            ->assertOk()
            ->assertJsonPath('data.summary.net_weight_kg', 36)
            ->assertJsonPath('data.records.0.read_weight_kg', 48)
            ->assertJsonPath('data.records.0.gross_weight_kg', 50)
            ->assertJsonPath('data.records.0.net_weight_kg', 36)
            ->assertJsonPath('data.records.0.adjustment.code', WholesaleTwoChickenVariant::MALE)
            ->assertJsonPath('data.records.0.adjustment.additional_grams', 100)
            ->assertJsonPath('data.records.0.adjustment.total_grams', 2000)
            ->assertJsonPath('data.records.0.adjustment.total_weight_kg', 2);
    }

    public function test_report_filters_by_journey_provider_and_historical_plate(): void
    {
        $currentJourneyId = $this->createJourney('2026-08-01');
        $historicalJourneyId = $this->createJourney('2026-07-31', 'CERRADA');
        $this->createWeighing(
            $currentJourneyId,
            'T-CURRENT',
            $this->providerSouthId,
            $this->southTruckId,
            'XYZ-999',
            cages: 8,
            birds: 80,
            netWeight: 144,
            weighedAt: '2026-08-01 09:00:00',
            warehouseId: $this->warehouseId,
        );
        $this->createWeighing(
            $historicalJourneyId,
            'T-HIST-1',
            $this->providerNorthId,
            null,
            'OLD-777',
            cages: 2,
            birds: 20,
            netWeight: 36,
            weighedAt: '2026-07-31 08:00:00',
            customerId: $this->customerId,
        );
        $this->createWeighing(
            $historicalJourneyId,
            'T-HIST-2',
            $this->providerSouthId,
            $this->southTruckId,
            'XYZ-999',
            cages: 3,
            birds: 30,
            netWeight: 54,
            weighedAt: '2026-07-31 09:00:00',
            warehouseId: $this->warehouseId,
        );

        $this->getJson(
            "/api/v1/operacion/reporte-proveedores?jornada=2026-07-31&proveedor_id={$this->providerNorthId}&camion=old-777"
        )
            ->assertOk()
            ->assertJsonPath('data.is_current_journey', false)
            ->assertJsonPath('data.selected_journey.status', 'CERRADA')
            ->assertJsonPath('data.applied_filters.provider_id', $this->providerNorthId)
            ->assertJsonPath('data.applied_filters.truck', $this->providerNorthId.':OLD-777')
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.cages', 2)
            ->assertJsonPath('data.summary.birds', 20)
            ->assertJsonPath('data.records.0.ticket.code', 'T-HIST-1')
            ->assertJsonPath('data.records.0.truck.vehicle_id', null)
            ->assertJsonPath('data.records.0.truck.plate', 'OLD-777')
            ->assertJsonCount(2, 'data.catalog.providers')
            ->assertJsonFragment([
                'value' => $this->providerNorthId.':OLD-777',
                'plate' => 'OLD-777',
                'provider_id' => $this->providerNorthId,
            ]);
    }

    public function test_truck_filter_distinguishes_the_same_plate_between_providers(): void
    {
        $journeyId = $this->createJourney('2026-08-01');
        $this->createWeighing(
            $journeyId,
            'T-SAME-NORTH',
            $this->providerNorthId,
            $this->northTruckId,
            'SAME-001',
            cages: 2,
            birds: 20,
            netWeight: 36,
            weighedAt: '2026-08-01 08:00:00',
            warehouseId: $this->warehouseId,
        );
        $this->createWeighing(
            $journeyId,
            'T-SAME-SOUTH',
            $this->providerSouthId,
            $this->southTruckId,
            'SAME-001',
            cages: 4,
            birds: 40,
            netWeight: 72,
            weighedAt: '2026-08-01 09:00:00',
            warehouseId: $this->warehouseId,
        );

        $this->getJson(
            "/api/v1/operacion/reporte-proveedores?camion={$this->providerSouthId}:same-001"
        )
            ->assertOk()
            ->assertJsonPath('data.applied_filters.provider_id', null)
            ->assertJsonPath('data.applied_filters.truck', $this->providerSouthId.':SAME-001')
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.cages', 4)
            ->assertJsonPath('data.records.0.ticket.code', 'T-SAME-SOUTH')
            ->assertJsonFragment([
                'value' => $this->providerNorthId.':SAME-001',
                'provider_id' => $this->providerNorthId,
            ])
            ->assertJsonFragment([
                'value' => $this->providerSouthId.':SAME-001',
                'provider_id' => $this->providerSouthId,
            ]);
    }

    public function test_report_paginates_the_detailed_weighings(): void
    {
        $journeyId = $this->createJourney('2026-08-01');

        foreach (range(1, 11) as $number) {
            $this->createWeighing(
                $journeyId,
                sprintf('T-PAGE-%02d', $number),
                $this->providerNorthId,
                $this->northTruckId,
                'ABC-123',
                cages: 1,
                birds: 10,
                netWeight: 18,
                weighedAt: sprintf('2026-08-01 08:%02d:00', $number),
                warehouseId: $this->warehouseId,
            );
        }

        $this->getJson('/api/v1/operacion/reporte-proveedores?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.total', 11)
            ->assertJsonPath('data.pagination.from', 11)
            ->assertJsonPath('data.pagination.to', 11)
            ->assertJsonCount(1, 'data.records');
    }

    public function test_report_isolated_by_the_users_branch(): void
    {
        $journeyId = $this->createJourney('2026-08-01');
        $this->createWeighing(
            $journeyId,
            'T-LOCAL',
            $this->providerNorthId,
            $this->northTruckId,
            'ABC-123',
            cages: 1,
            birds: 10,
            netWeight: 18,
            weighedAt: '2026-08-01 08:00:00',
            warehouseId: $this->warehouseId,
        );
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'SECUNDARIA',
            'nombre' => 'Sucursal secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherJourneyId = $this->createJourney('2026-08-01', branchId: $otherBranchId);
        $this->createWeighing(
            $otherJourneyId,
            'T-OTHER',
            $this->providerSouthId,
            $this->southTruckId,
            'XYZ-999',
            cages: 9,
            birds: 90,
            netWeight: 162,
            weighedAt: '2026-08-01 09:00:00',
            customerId: $this->customerId,
        );

        $this->getJson('/api/v1/operacion/reporte-proveedores')
            ->assertOk()
            ->assertJsonPath('data.summary.records', 1)
            ->assertJsonPath('data.summary.cages', 1)
            ->assertJsonPath('data.records.0.ticket.code', 'T-LOCAL');
    }

    private function createParty(string $name, string $document, string $role): int
    {
        $id = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => $document,
            'nombre_razon_social' => $name,
            'direccion' => 'Dirección de prueba',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $id,
            'rol' => $role,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function createTruck(string $plate): int
    {
        return DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => $plate,
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJourney(
        string $date,
        string $status = 'ABIERTA',
        ?int $branchId = null,
    ): int {
        return DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId ?? $this->branchId,
            'fecha_operativa' => $date,
            'estado' => $status,
            'abierta_por' => $this->user->id,
            'inicio_at' => "{$date} 06:00:00",
            'cierre_programado_at' => "{$date} 21:00:00",
            'cerrada_por' => $status === 'CERRADA' ? $this->user->id : null,
            'cerrada_at' => $status === 'CERRADA' ? "{$date} 20:00:00" : null,
        ]);
    }

    private function createWeighing(
        int $journeyId,
        string $ticketCode,
        int $providerId,
        ?int $vehicleId,
        string $plate,
        int $cages,
        int $birds,
        float $netWeight,
        string $weighedAt,
        ?int $customerId = null,
        ?int $warehouseId = null,
        string $ticketStatus = 'CERRADO',
    ): void {
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => $ticketCode,
            'canal' => 'MAYORISTA',
            'tipo_operacion' => 'DESPACHO',
            'cliente_destino_id' => $customerId,
            'almacen_destino_id' => $warehouseId,
            'estado' => $ticketStatus,
            'cerrado_por' => $this->user->id,
            'cerrado_at' => $weighedAt,
            'anulado_por' => $ticketStatus === 'ANULADO' ? $this->user->id : null,
            'anulado_at' => $ticketStatus === 'ANULADO' ? $weighedAt : null,
            'motivo_anulacion' => $ticketStatus === 'ANULADO' ? 'Registro de prueba anulado' : null,
            'created_by' => $this->user->id,
            'created_at' => $weighedAt,
            'updated_at' => $weighedAt,
        ]);
        $tare = $cages * 7;

        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $this->chickenTypeId,
            'condicion_pollo' => 'VIVO',
            'sexo' => 'MACHO',
            'tipo_java_id' => $this->cageTypeId,
            'proveedor_origen_id' => $providerId,
            'vehiculo_id' => $vehicleId,
            'placa_snapshot' => $plate,
            'origen_peso' => 'MANUAL',
            'aves_por_java' => $cages > 0 ? (int) ($birds / $cages) : $birds,
            'cantidad_javas' => $cages,
            'cantidad_aves' => $birds,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => $netWeight + $tare,
            'peso_bruto_kg' => $netWeight + $tare,
            'tara_total_kg' => $tare,
            'peso_neto_kg' => $netWeight,
            'pesada_at' => $weighedAt,
            'estado' => 'ACTIVA',
            'created_by' => $this->user->id,
            'created_at' => $weighedAt,
            'updated_at' => $weighedAt,
        ]);
    }
}
