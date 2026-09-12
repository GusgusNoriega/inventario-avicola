<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\User;
use App\Services\GeneralConfigurationService;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerStatementOperatingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'America/Lima', 'database.connections.sqlite.timezone' => 'America/Lima']);
        $this->user = User::factory()->create();
        DB::table('empresas')->where('id', $this->user->empresa_id)->update([
            'hora_corte_operativo' => '21:00:00', 'zona_horaria' => 'America/Lima',
        ]);
        $this->customerId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'nombre_razon_social' => 'Cliente jornada y cobro',
            'tipo_documento' => 'DNI', 'numero_documento' => '10887766', 'direccion' => 'Dirección de prueba', 'estado' => 'ACTIVO',
        ]);
        DB::table('tercero_roles')->insert(['tercero_id' => $this->customerId, 'rol' => 'CLIENTE']);
    }

    #[DataProvider('cutoffReports')]
    public function test_sale_and_collection_remain_balanced_after_reclassifying_the_cutoff(string $cutoff, string $report): void
    {
        $documentId = $this->automaticSale();
        $paymentId = $this->payment('PG-PAGADO', '2026-09-10 15:30:00', '100.00');
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId, 'comprobante_id' => $documentId, 'lado' => 'CXC', 'importe_aplicado' => 100,
        ]);
        $beforePayments = DB::table('pagos')->get();
        $beforeApplications = DB::table('pago_aplicaciones')->get();

        app(GeneralConfigurationService::class)->update((int) $this->user->empresa_id, $cutoff, '21:00', $this->user->id);

        $this->assertDatabaseHas('comprobantes', ['id' => $documentId, 'fecha_emision' => '2026-09-11']);
        $this->assertEquals($beforePayments, DB::table('pagos')->get());
        $this->assertEquals($beforeApplications, DB::table('pago_aplicaciones')->get());
        $previous = $this->report($report, '2026-09-10');
        $this->assertSame(0.0, (float) $previous['balance'], 'No collection belongs to the preceding operating day.');
        $this->assertSame(0.0, (float) $previous['credits']);

        $current = $this->report($report, '2026-09-11');
        $this->assertSame(0.0, (float) $current['opening']);
        $this->assertSame(100.0, (float) $current['charges']);
        $this->assertSame(100.0, (float) $current['credits']);
        $this->assertSame(0.0, (float) $current['balance']);
        if (isset($current['rows'])) {
            $this->assertSame(['2026-09-11'], $current['rows']->pluck('date')->unique()->values()->all());
            $this->assertTrue($current['rows']->every(fn (array $row): bool => (float) $row['balance'] >= 0));
        }
        $next = $this->report($report, '2026-09-13');
        $this->assertSame(0.0, (float) $next['opening']);
        $this->assertSame(0.0, (float) $next['balance']);
    }

    public static function cutoffReports(): array
    {
        $cases = [];
        foreach (['12:00', '00:00'] as $cutoff) {
            foreach (['statement', 'summary', 'route'] as $report) {
                $cases[$cutoff.' '.$report] = [$cutoff, $report];
            }
        }

        return $cases;
    }

    #[DataProvider('boundaryPayments')]
    public function test_direct_payments_use_the_exact_local_cutoff_with_utc_storage(
        string $cutoff,
        string $localTime,
        string $expectedDate,
    ): void {
        config(['app.timezone' => 'UTC', 'database.connections.sqlite.timezone' => 'UTC']);
        DB::table('empresas')->where('id', $this->user->empresa_id)->update(['hora_corte_operativo' => $cutoff]);
        $storedAt = CarbonImmutable::parse($localTime, 'America/Lima')->utc()->format('Y-m-d H:i:s');
        $paymentId = $this->payment('PG-LIMITE', $storedAt, '25.00');

        foreach (['statement', 'summary', 'route'] as $report) {
            $result = $this->report($report, $expectedDate);
            $this->assertSame(0.0, (float) $result['opening'], $report);
            $this->assertSame(25.0, (float) $result['credits'], $report);
            $this->assertSame(-25.0, (float) $result['balance'], 'A real customer advance must remain negative: '.$report);
            if (isset($result['rows'])) {
                $this->assertSame([$expectedDate], $result['rows']->pluck('date')->all(), $report);
            }
            $dayBefore = CarbonImmutable::parse($expectedDate)->subDay()->toDateString();
            $this->assertSame(0.0, (float) $this->report($report, $dayBefore)['balance'], $report);
        }
        $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'fecha_hora' => $storedAt, 'importe' => 25]);
    }

    public static function boundaryPayments(): array
    {
        return [
            'before noon' => ['12:00:00', '2026-09-10 11:59:59', '2026-09-10'],
            'at noon' => ['12:00:00', '2026-09-10 12:00:00', '2026-09-11'],
            'before midnight' => ['00:00:00', '2026-09-10 23:59:59', '2026-09-11'],
            'at midnight' => ['00:00:00', '2026-09-11 00:00:00', '2026-09-12'],
        ];
    }

    public function test_payments_within_one_journey_keep_their_actual_chronological_order(): void
    {
        $this->payment('PG-MANANA', '2026-09-11 08:00:00', '20.00');
        $this->payment('PG-NOCHE', '2026-09-10 22:00:00', '10.00');

        foreach (['statement', 'route'] as $report) {
            $result = $this->report($report, '2026-09-11');
            $codeColumn = $report === 'statement' ? 'code' : 'detail';
            $this->assertSame(['PG-NOCHE', 'PG-MANANA'], $result['rows']->pluck($codeColumn)->all(), $report);
            $this->assertSame(['2026-09-11', '2026-09-11'], $result['rows']->pluck('date')->all(), $report);
            $this->assertSame([-10.0, -30.0], $result['rows']->pluck('balance')->map(fn ($balance): float => (float) $balance)->all(), $report);
        }
    }

    public function test_explicit_route_receipt_date_stays_selected_after_the_cutoff_changes(): void
    {
        $paymentId = $this->payment('PG-RUTA', '2026-09-12 15:30:00', '30.00');
        $this->routeReceipt($paymentId, '2026-09-10');

        app(GeneralConfigurationService::class)->update((int) $this->user->empresa_id, '12:00', '21:00', $this->user->id);

        foreach (['statement', 'summary', 'route'] as $report) {
            $result = $this->report($report, '2026-09-10');
            $this->assertSame(0.0, (float) $result['opening'], $report);
            $this->assertSame(30.0, (float) $result['credits'], $report);
            $this->assertSame(-30.0, (float) $result['balance'], $report);
            if (isset($result['rows'])) {
                $this->assertSame(['2026-09-10'], $result['rows']->pluck('date')->all(), $report);
            }
            $later = $this->report($report, '2026-09-13');
            $this->assertSame(-30.0, (float) $later['opening'], $report);
            $this->assertSame(0.0, (float) $later['credits'], $report);
        }
        $this->assertDatabaseHas('cobranza_detalles', ['pago_id' => $paymentId, 'fecha_recepcion' => '2026-09-10']);
        $this->assertDatabaseHas('pagos', ['id' => $paymentId, 'fecha_hora' => '2026-09-12 15:30:00']);
    }

    #[DataProvider('noonTransitions')]
    public function test_void_and_restore_audit_events_follow_the_operating_cutoff(string $time, int $dayOffset): void
    {
        config(['app.timezone' => 'UTC', 'database.connections.sqlite.timezone' => 'UTC']);
        DB::table('empresas')->where('id', $this->user->empresa_id)->update(['hora_corte_operativo' => '12:00:00']);
        $documentId = $this->automaticSale();
        DB::table('comprobantes')->where('id', $documentId)->update(['fecha_emision' => '2026-09-08']);
        $active = (array) DB::table('comprobantes')->where('id', $documentId)->first();
        $firstVoidAt = CarbonImmutable::parse('2026-09-10 '.$time, 'America/Lima')->utc()->toDateTimeString();
        $restoredAt = CarbonImmutable::parse('2026-09-11 '.$time, 'America/Lima')->utc()->toDateTimeString();
        $latestVoidAt = CarbonImmutable::parse('2026-09-13 '.$time, 'America/Lima')->utc()->toDateTimeString();
        $firstVoided = [...$active, 'estado' => Comprobante::STATUS_VOIDED, 'anulada_at' => $firstVoidAt];
        $latestVoided = [...$active, 'estado' => Comprobante::STATUS_VOIDED, 'anulada_at' => $latestVoidAt];
        DB::table('comprobantes')->where('id', $documentId)->update([
            'estado' => Comprobante::STATUS_VOIDED, 'anulada_at' => $latestVoidAt,
        ]);
        foreach ([
            [$active, $firstVoided, $firstVoidAt],
            [$firstVoided, $active, $restoredAt],
            [$active, $latestVoided, $latestVoidAt],
        ] as [$before, $after, $at]) {
            DB::table('auditoria_eventos')->insert([
                'empresa_id' => $this->user->empresa_id, 'usuario_id' => $this->user->id,
                'entidad' => 'comprobantes', 'entidad_id' => (string) $documentId,
                'accion' => $after['estado'] === Comprobante::STATUS_VOIDED ? 'ANULAR_AUTOMATICO' : 'REVALORIZAR',
                'datos_antes' => json_encode($before, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => $at,
            ]);
        }

        foreach ([
            ['2026-09-09', 100.0, 0.0, 100.0],
            ['2026-09-10', 100.0, -100.0, 0.0],
            ['2026-09-11', 0.0, 100.0, 100.0],
            ['2026-09-13', 100.0, -100.0, 0.0],
            ['2026-09-14', 0.0, 0.0, 0.0],
        ] as [$baseDate, $opening, $debt, $balance]) {
            $date = CarbonImmutable::parse($baseDate)->addDays($dayOffset)->toDateString();
            $result = $this->report('summary', $date);
            $this->assertSame($opening, (float) $result['opening'], $date);
            $this->assertSame($debt, (float) $result['charges'], $date);
            $this->assertSame($balance, (float) $result['balance'], $date);
        }
    }

    #[DataProvider('noonTransitions')]
    public function test_collection_void_uses_the_operating_cutoff(string $time, int $dayOffset): void
    {
        config(['app.timezone' => 'UTC', 'database.connections.sqlite.timezone' => 'UTC']);
        DB::table('empresas')->where('id', $this->user->empresa_id)->update(['hora_corte_operativo' => '12:00:00']);
        $paymentId = $this->payment('PG-ANULADO', '2026-09-08 13:00:00', '25.00');
        DB::table('pagos')->where('id', $paymentId)->update([
            'estado' => Pago::STATUS_VOIDED,
            'anulada_at' => CarbonImmutable::parse('2026-09-10 '.$time, 'America/Lima')->utc()->toDateTimeString(),
        ]);
        $voidDate = CarbonImmutable::parse('2026-09-10')->addDays($dayOffset);
        $previous = $this->report('summary', $voidDate->subDay()->toDateString());
        $voided = $this->report('summary', $voidDate->toDateString());
        $next = $this->report('summary', $voidDate->addDay()->toDateString());

        $this->assertSame(-25.0, (float) $previous['balance']);
        $this->assertSame(-25.0, (float) $voided['opening']);
        $this->assertSame(-25.0, (float) $voided['credits']);
        $this->assertSame(0.0, (float) $voided['balance']);
        $this->assertSame(0.0, (float) $next['opening']);
        $this->assertSame(0.0, (float) $next['balance']);
    }

    public static function noonTransitions(): array
    {
        return ['before cutoff' => ['11:59:59', 0], 'exact cutoff' => ['12:00:00', 1]];
    }

    private function report(string $type, string $date): array
    {
        $service = app(ReportDataService::class);
        $companyId = (int) $this->user->empresa_id;
        if ($type === 'statement') {
            return $service->customerStatement($companyId, $this->customerId, $date, $date);
        }
        if ($type === 'summary') {
            $row = $service->customerDebtSummary($companyId, $date, $date)['rows']->firstWhere('customer_id', $this->customerId);

            return [
                'opening' => $row['opening'] ?? '0.00', 'charges' => $row['period_debt'] ?? '0.00',
                'credits' => $row['payments'] ?? '0.00', 'balance' => $row['balance'] ?? '0.00',
            ];
        }
        $row = $service->collectionRouteTwo($companyId, $date)['customers']->firstWhere('id', $this->customerId);

        return [...$row, 'charges' => $row['rows']->sum('outflow'), 'credits' => $row['rows']->sum('inflow')];
    }

    private function payment(string $code, string $time, string $amount): int
    {
        return DB::table('pagos')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tercero_id' => $this->customerId,
            'cliente_id' => $this->customerId, 'codigo' => $code, 'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'direccion' => Pago::DIRECTION_INCOME, 'fecha_hora' => $time, 'metodo' => 'EFECTIVO',
            'moneda' => 'PEN', 'importe' => $amount, 'estado' => Pago::STATUS_REGISTERED, 'created_by' => $this->user->id,
        ]);
    }

    private function automaticSale(): int
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'codigo' => 'REPORTE', 'nombre' => 'Sucursal reportes',
            'zona_horaria' => 'America/Lima', 'estado' => 'ACTIVO',
        ]);
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId, 'fecha_operativa' => '2026-09-10', 'estado' => 'CERRADA',
            'inicio_at' => '2026-09-09 21:00:00', 'cierre_programado_at' => '2026-09-10 21:00:00',
            'abierta_por' => $this->user->id,
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId, 'codigo' => 'T-PAGADO', 'referencia_externa' => (string) Str::uuid(),
            'canal' => 'MAYORISTA', 'tipo_operacion' => 'DESPACHO', 'cliente_destino_id' => $this->customerId,
            'estado' => 'CERRADO', 'cerrado_at' => '2026-09-10 15:05:00', 'cerrado_por' => $this->user->id,
            'created_by' => $this->user->id, 'created_at' => '2026-09-10 14:55:00',
        ]);
        $chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'VIVO', 'nombre' => 'Pollo vivo', 'permite_despacho' => true, 'estado' => 'ACTIVO',
        ]);
        $cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA-REPORTE', 'nombre' => 'Java', 'peso_kg' => 7, 'estado' => 'ACTIVO',
        ]);
        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId, 'numero' => 1, 'tipo_pollo_id' => $chickenTypeId, 'tipo_java_id' => $cageTypeId,
            'origen_peso' => 'MANUAL', 'aves_por_java' => 25, 'cantidad_javas' => 2, 'cantidad_aves' => 50,
            'peso_java_kg_snapshot' => 7, 'peso_leido_kg' => 114, 'peso_bruto_kg' => 114,
            'tara_total_kg' => 14, 'peso_neto_kg' => 100, 'pesada_at' => '2026-09-10 15:00:00',
            'estado' => 'ACTIVA', 'created_by' => $this->user->id,
        ]);

        return DB::table('comprobantes')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tercero_id' => $this->customerId,
            'operacion' => Comprobante::OPERATION_SALE, 'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO', 'codigo' => 'V-PAGADO', 'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => 'VENTA:TICKET:'.$ticketId, 'fecha_emision' => '2026-09-10',
            'fecha_vencimiento' => '2026-09-10', 'moneda' => 'PEN', 'subtotal' => 100,
            'impuesto' => 0, 'total' => 100, 'saldo_pendiente' => 0, 'estado' => Comprobante::STATUS_PAID,
            'created_by' => $this->user->id,
        ]);
    }

    private function routeReceipt(int $paymentId, string $receivedDate): void
    {
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tipo' => 'PROPIA', 'razon_social' => 'Caja de prueba',
            'estado' => 'ACTIVO', 'created_by' => $this->user->id,
        ]);
        $accountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId, 'tipo' => 'CAJA', 'alias' => 'Caja', 'moneda' => 'PEN',
            'estado' => 'ACTIVO', 'created_by' => $this->user->id,
        ]);
        $collectorId = DB::table('cobradores')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'nombre' => 'Cobrador', 'estado' => 'ACTIVO', 'created_by' => $this->user->id,
        ]);
        $methodId = (int) DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id');
        $collectionId = DB::table('cobranzas')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'cobrador_id' => $collectorId, 'cobrador_nombre_snapshot' => 'Cobrador',
            'codigo' => 'COB-PRUEBA', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'cobro-ruta'),
            'cuenta_destino_id' => $accountId, 'metodo_pago_id' => $methodId, 'fecha_hora' => '2026-09-12 15:30:00',
            'referencia' => 'RECIBO-PRUEBA', 'moneda' => 'PEN', 'importe_total' => 30,
            'estado' => 'REGISTRADO', 'created_by' => $this->user->id,
        ]);
        DB::table('cobranza_detalles')->insert([
            'cobranza_id' => $collectionId, 'pago_id' => $paymentId, 'cliente_id' => $this->customerId,
            'fecha_recepcion' => $receivedDate, 'medio_recepcion' => 'EFECTIVO', 'importe' => 30, 'orden' => 1,
        ]);
    }
}
