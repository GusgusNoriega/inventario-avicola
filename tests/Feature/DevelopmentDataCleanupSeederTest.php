<?php

namespace Tests\Feature;

use App\Models\Conductor;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use App\Models\Vehiculo;
use Database\Seeders\DevelopmentDataCleanupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DevelopmentDataCleanupSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cleans_development_data_and_preserves_requested_master_records(): void
    {
        $records = $this->createDevelopmentScenario();

        foreach (DevelopmentDataCleanupSeeder::TABLES_TO_CLEAN as $table) {
            $this->assertGreaterThan(
                0,
                DB::table($table)->count(),
                "El escenario debe poblar la tabla {$table} antes de probar la limpieza."
            );
        }

        $this->seed(DevelopmentDataCleanupSeeder::class);

        foreach (DevelopmentDataCleanupSeeder::TABLES_TO_CLEAN as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertDatabaseHas('usuarios', ['id' => $records['user_id']]);
        $this->assertDatabaseHas('terceros', ['id' => $records['client_id']]);
        $this->assertDatabaseHas('terceros', ['id' => $records['provider_id']]);
        $this->assertDatabaseHas('tercero_roles', [
            'tercero_id' => $records['client_id'],
            'rol' => TerceroRole::CLIENT,
        ]);
        $this->assertDatabaseHas('tercero_roles', [
            'tercero_id' => $records['provider_id'],
            'rol' => TerceroRole::PROVIDER,
        ]);
        $this->assertDatabaseHas('conductores', ['id' => $records['driver_id']]);
        $this->assertDatabaseHas('vehiculos', ['id' => $records['truck_id']]);
        $this->assertDatabaseHas('proveedor_vehiculos', [
            'id' => $records['assignment_id'],
            'proveedor_id' => $records['provider_id'],
            'vehiculo_id' => $records['truck_id'],
        ]);
        $this->assertDatabaseHas('tipos_pollo', ['id' => $records['chicken_type_id']]);

        // A second run must be safe when every target table is already empty.
        $this->seed(DevelopmentDataCleanupSeeder::class);
        foreach (DevelopmentDataCleanupSeeder::TABLES_TO_CLEAN as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_every_database_table_is_explicitly_classified_for_cleanup_or_preservation(): void
    {
        $actualTables = collect(Schema::getTableListing())
            ->map(fn (string $table): string => str($table)->afterLast('.')->toString())
            ->reject(fn (string $table): bool => str_starts_with($table, 'sqlite_'))
            ->sort()
            ->values()
            ->all();
        $classifiedTables = collect([
            ...DevelopmentDataCleanupSeeder::TABLES_TO_CLEAN,
            ...DevelopmentDataCleanupSeeder::PRESERVED_TABLES,
        ])->sort()->values()->all();

        $this->assertSame($actualTables, $classifiedTables);
    }

    public function test_it_refuses_to_delete_data_outside_local_or_testing(): void
    {
        $user = User::factory()->create();
        $auditId = DB::table('auditoria_eventos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'usuario_id' => $user->id,
            'entidad' => 'PRUEBA',
            'entidad_id' => '1',
            'accion' => 'CREAR',
            'created_at' => now(),
        ]);
        $originalEnvironment = $this->app->environment();

        try {
            $this->app->instance('env', 'production');

            try {
                (new DevelopmentDataCleanupSeeder)->run();
                $this->fail('El seeder debió rechazar la ejecución en producción.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('local o testing', $exception->getMessage());
            }
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

        $this->assertDatabaseHas('auditoria_eventos', ['id' => $auditId]);
    }

    /**
     * @return array<string, int>
     */
    private function createDevelopmentScenario(): array
    {
        $user = User::factory()->create();
        $companyId = (int) $user->empresa_id;
        $now = now();
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $client = Tercero::query()->create([
            'empresa_id' => $companyId,
            'tipo_documento' => 'DNI',
            'numero_documento' => '10000001',
            'nombre_razon_social' => 'CLIENTE CONSERVADO',
            'direccion' => 'Dirección cliente',
            'estado' => 'ACTIVO',
        ]);
        $client->roles()->create(['rol' => TerceroRole::CLIENT]);

        $provider = Tercero::query()->create([
            'empresa_id' => $companyId,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20100000001',
            'nombre_razon_social' => 'PROVEEDOR CONSERVADO',
            'direccion' => 'Dirección proveedor',
            'estado' => 'ACTIVO',
        ]);
        $provider->roles()->create(['rol' => TerceroRole::PROVIDER]);

        $driver = Conductor::query()->create([
            'empresa_id' => $companyId,
            'nombre_completo' => 'CHOFER CONSERVADO',
            'tipo_documento' => 'DNI',
            'numero_documento' => '20000001',
            'estado' => 'ACTIVO',
        ]);
        $truck = Vehiculo::query()->create([
            'empresa_id' => $companyId,
            'placa' => 'PRU-001',
            'estado' => 'ACTIVO',
        ]);
        $assignmentId = DB::table('proveedor_vehiculos')->insertGetId([
            'proveedor_id' => $provider->id,
            'vehiculo_id' => $truck->id,
            'vigente_desde' => today(),
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'POLLO_PRUEBA',
            'nombre' => 'Pollo de prueba',
            'permite_despacho' => true,
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $cageTypeId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'JAVA_PRUEBA',
            'nombre' => 'Java de prueba',
            'peso_kg' => 7,
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $branchId,
            'codigo' => 'ALMACEN_PRUEBA',
            'nombre' => 'Almacén de prueba',
            'permite_stock_negativo' => false,
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scaleId = DB::table('balanzas')->insertGetId([
            'sucursal_id' => $branchId,
            'codigo' => 'BALANZA_PRUEBA',
            'nombre' => 'Balanza de prueba',
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceListId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $provider->id,
            'codigo' => 'PROVEEDOR-PRUEBA',
            'nombre' => 'Precios proveedor prueba',
            'operacion' => 'COMPRA',
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $firstPriceId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $chickenTypeId,
            'precio_kg' => 8.5,
            'vigente_desde' => $now->copy()->subDay(),
            'vigente_hasta' => $now,
            'registrado_por' => $user->id,
            'created_at' => $now,
        ]);
        $currentPriceId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $chickenTypeId,
            'precio_kg' => 9,
            'vigente_desde' => $now,
            'reemplaza_precio_id' => $firstPriceId,
            'registrado_por' => $user->id,
            'created_at' => $now,
        ]);

        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $companyId,
            'tipo' => 'PROPIA',
            'razon_social' => 'Caja de prueba',
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => 'CAJA',
            'alias' => 'Caja prueba',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $documentId = DB::table('comprobantes')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $provider->id,
            'operacion' => 'COMPRA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'FACTURA',
            'codigo' => 'DOC-PRUEBA',
            'origen_codigo' => 'COMPRA',
            'origen_clave' => 'PRUEBA:LIMPIEZA',
            'fecha_emision' => today(),
            'moneda' => 'PEN',
            'subtotal' => 100,
            'impuesto' => 0,
            'total' => 100,
            'saldo_pendiente' => 100,
            'estado' => 'PENDIENTE',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $documentId,
            'tipo_pollo_id' => $chickenTypeId,
            'descripcion' => 'Compra de prueba',
            'peso_neto_kg' => 10,
            'precio_kg' => 10,
            'subtotal' => 100,
            'created_at' => $now,
        ]);

        $paymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $provider->id,
            'codigo' => 'PAG-PRUEBA',
            'tipo' => 'PAGO_PROVEEDOR',
            'proveedor_id' => $provider->id,
            'cuenta_origen_id' => $accountId,
            'direccion' => 'SALIDA',
            'fecha_hora' => $now,
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => 100,
            'estado' => 'REGISTRADO',
            'created_by' => $user->id,
            'created_at' => $now,
        ]);
        DB::table('pagos')->insert([
            'empresa_id' => $companyId,
            'tercero_id' => $provider->id,
            'codigo' => 'REV-PRUEBA',
            'tipo' => 'REVERSA',
            'proveedor_id' => $provider->id,
            'cuenta_destino_id' => $accountId,
            'direccion' => 'ENTRADA',
            'fecha_hora' => $now,
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => 100,
            'estado' => 'REGISTRADO',
            'reversa_de_pago_id' => $paymentId,
            'created_by' => $user->id,
            'created_at' => $now,
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $paymentId,
            'comprobante_id' => $documentId,
            'lado' => 'CXP',
            'importe_aplicado' => 100,
            'created_by' => $user->id,
            'created_at' => $now,
        ]);
        DB::table('pago_aplicacion_operaciones')->insert([
            'empresa_id' => $companyId,
            'pago_id' => $paymentId,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'aplicacion-prueba'),
            'importe_total' => 100,
            'aplicaciones' => json_encode([[
                'comprobante_id' => $documentId,
                'importe_aplicado' => '100.00',
            ]], JSON_THROW_ON_ERROR),
            'observaciones' => 'Aplicación de prueba para limpieza',
            'created_by' => $user->id,
            'created_at' => $now,
        ]);

        $purchaseId = DB::table('compras')->insertGetId([
            'empresa_id' => $companyId,
            'proveedor_id' => $provider->id,
            'comprobante_id' => $documentId,
            'pago_inicial_id' => $paymentId,
            'codigo' => 'COM-PRUEBA',
            'idempotency_key' => (string) Str::uuid(),
            'tipo_documento' => 'FACTURA',
            'numero_documento' => 'F-PRUEBA',
            'numero_documento_activo' => 'F-PRUEBA',
            'fecha_compra' => today(),
            'condicion' => 'CONTADO',
            'moneda' => 'PEN',
            'subtotal' => 100,
            'impuesto' => 0,
            'total' => 100,
            'estado' => 'REGISTRADA',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('compra_detalles')->insert([
            'compra_id' => $purchaseId,
            'tipo_pollo_id' => $chickenTypeId,
            'descripcion' => 'Detalle de prueba',
            'peso_kg' => 10,
            'precio_kg' => 10,
            'subtotal' => 100,
            'created_at' => $now,
        ]);

        $scheduleId = DB::table('programaciones_recepcion')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => today(),
            'estado' => 'PUBLICADA',
            'publicada_por' => $user->id,
            'publicada_at' => $now,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('programacion_recepcion_almacenes')->insert([
            'programacion_id' => $scheduleId,
            'almacen_id' => $warehouseId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleDetailId = DB::table('programacion_recepcion_detalles')->insertGetId([
            'programacion_id' => $scheduleId,
            'proveedor_vehiculo_id' => $assignmentId,
            'conductor_id' => $driver->id,
            'conductor_nombre_snapshot' => $driver->nombre_completo,
            'numero_visita' => 1,
            'estado' => 'PENDIENTE',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => today(),
            'estado' => 'ABIERTA',
            'abierta_por' => $user->id,
            'inicio_at' => $now,
            'cierre_programado_at' => $now->copy()->addDay(),
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => 'TCK-PRUEBA',
            'referencia_externa' => (string) Str::uuid(),
            'canal' => 'MAYORISTA',
            'tipo_operacion' => 'DESPACHO',
            'cliente_destino_id' => $client->id,
            'vehiculo_entrega_id' => $truck->id,
            'conductor_entrega_id' => $driver->id,
            'estado' => 'CERRADO',
            'cerrado_por' => $user->id,
            'cerrado_at' => $now,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('ticket_precios')->insert([
            'ticket_id' => $ticketId,
            'tipo_pollo_id' => $chickenTypeId,
            'precio_historial_id' => $currentPriceId,
            'precio_kg' => 9,
            'origen_precio' => 'PROVEEDOR',
            'congelado_por' => $user->id,
            'created_at' => $now,
        ]);
        $scaleReadingId = DB::table('lecturas_balanza')->insertGetId([
            'balanza_id' => $scaleId,
            'peso_kg' => 32,
            'trama_cruda' => '32.000',
            'modo_conexion' => 'SERIAL',
            'capturada_at' => $now,
            'capturada_por' => $user->id,
        ]);
        $weighingId = DB::table('pesadas')->insertGetId([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $chickenTypeId,
            'tipo_java_id' => $cageTypeId,
            'lectura_balanza_id' => $scaleReadingId,
            'condicion_pollo' => 'VIVO',
            'sexo' => 'MACHO',
            'proveedor_origen_id' => $provider->id,
            'vehiculo_id' => $truck->id,
            'programacion_recepcion_detalle_id' => $scheduleDetailId,
            'placa_snapshot' => $truck->placa,
            'origen_peso' => 'BALANZA',
            'aves_por_java' => 10,
            'cantidad_javas' => 1,
            'cantidad_aves' => 10,
            'peso_java_kg_snapshot' => 7,
            'peso_leido_kg' => 32,
            'peso_bruto_kg' => 32,
            'tara_total_kg' => 7,
            'peso_neto_kg' => 25,
            'pesada_at' => $now,
            'estado' => 'ACTIVA',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('comprobante_tickets')->insert([
            'comprobante_id' => $documentId,
            'ticket_id' => $ticketId,
            'importe_aplicado' => 100,
        ]);
        DB::table('comprobante_pesadas')->insert([
            'comprobante_id' => $documentId,
            'pesada_id' => $weighingId,
            'importe_aplicado' => 100,
        ]);
        DB::table('costos_compra_pesadas')->insert([
            'pesada_id' => $weighingId,
            'proveedor_id' => $provider->id,
            'precio_historial_id' => $currentPriceId,
            'precio_kg' => 9,
            'peso_kg' => 25,
            'importe' => 225,
            'estado' => 'ACTIVO',
            'origen' => 'MANUAL',
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inventoryMovementId = DB::table('movimientos_inventario')->insertGetId([
            'sucursal_id' => $branchId,
            'ticket_id' => $ticketId,
            'tipo' => 'ENTRADA',
            'almacen_destino_id' => $warehouseId,
            'tercero_origen_id' => $provider->id,
            'estado' => 'CONFIRMADO',
            'fecha_hora' => $now,
            'confirmado_por' => $user->id,
            'confirmado_at' => $now,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('movimiento_detalles')->insert([
            'movimiento_id' => $inventoryMovementId,
            'pesada_id' => $weighingId,
            'tipo_pollo_id' => $chickenTypeId,
            'cantidad_aves' => 10,
            'peso_neto_kg' => 25,
            'created_at' => $now,
        ]);
        DB::table('existencias_almacen')->insert([
            'almacen_id' => $warehouseId,
            'tipo_pollo_id' => $chickenTypeId,
            'cantidad_aves' => 10,
            'peso_neto_kg' => 25,
            'updated_at' => $now,
        ]);
        DB::table('movimientos_javas')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'jornada_id' => $journeyId,
            'cliente_id' => $client->id,
            'tipo' => 'DESPACHO',
            'cantidad' => 1,
            'cantidad_bandejas' => 0,
            'ticket_despacho_id' => $ticketId,
            'vehiculo_id' => $truck->id,
            'conductor_id' => $driver->id,
            'fecha_movimiento' => $now,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inventarios_javas')->insert([
            'empresa_id' => $companyId,
            'cantidad_total' => 100,
            'cantidad_total_bandejas' => 50,
            'updated_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('conteos_diarios_javas')->insert([
            'empresa_id' => $companyId,
            'jornada_id' => $journeyId,
            'cantidad_en_empresa' => 99,
            'cantidad_en_empresa_bandejas' => 50,
            'cantidad_esperada' => 99,
            'cantidad_esperada_bandejas' => 50,
            'diferencia' => 0,
            'diferencia_bandejas' => 0,
            'contado_at' => $now,
            'contado_por' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $user->id,
            'entidad' => 'PRUEBA',
            'entidad_id' => '1',
            'accion' => 'CREAR',
            'created_at' => $now,
        ]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'token-prueba',
            'token' => hash('sha256', 'token-prueba'),
            'abilities' => '["api"]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sessions')->insert([
            'id' => 'sesion-prueba',
            'user_id' => $user->id,
            'payload' => 'datos-prueba',
            'last_activity' => $now->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'token-restablecimiento-prueba',
            'created_at' => $now,
        ]);
        DB::table('cache')->insert([
            'key' => 'saldo-prueba',
            'value' => '100.00',
            'expiration' => $now->copy()->addHour()->timestamp,
        ]);
        DB::table('cache_locks')->insert([
            'key' => 'limpieza-prueba',
            'owner' => 'test',
            'expiration' => $now->copy()->addHour()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => $now->timestamp,
            'created_at' => $now->timestamp,
        ]);
        DB::table('job_batches')->insert([
            'id' => 'lote-prueba',
            'name' => 'Lote de prueba',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'created_at' => $now->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Excepción de prueba',
            'failed_at' => $now,
        ]);

        return [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'driver_id' => $driver->id,
            'truck_id' => $truck->id,
            'assignment_id' => $assignmentId,
            'chicken_type_id' => $chickenTypeId,
        ];
    }
}
