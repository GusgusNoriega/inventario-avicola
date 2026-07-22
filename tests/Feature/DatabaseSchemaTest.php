<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_migration_has_one_schema_operation(): void
    {
        $migrationFiles = glob(database_path('migrations/*.php'));

        $this->assertCount(84, $migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $contents = file_get_contents($migrationFile);
            $upContents = explode('public function down', $contents, 2)[0];
            $schemaOperations = preg_match_all(
                "/Schema::(?:create|table)\\('([^']+)'/",
                $upContents
            );
            $expectedOperations = match (basename($migrationFile)) {
                '2026_06_26_000004_add_tickets_dia_permission.php',
                '2026_06_27_000001_add_pesadas_gestionar_permission.php',
                '2026_07_12_000009_add_financial_permissions.php',
                '2026_07_14_000004_add_purchase_permissions.php',
                '2026_07_14_000005_backfill_legacy_dispatch_purchases.php',
                '2026_07_15_000001_set_standard_tray_weight.php' => 0,
                '2026_07_12_000002_add_trays_to_java_movements.php' => 3,
                '2026_07_12_000008_extend_pagos_and_pago_aplicaciones.php' => 2,
                '2026_07_22_000001_add_station_to_retail_weight_adjustments.php' => 2,
                default => 1,
            };

            $this->assertSame(
                $expectedOperations,
                $schemaOperations,
                basename($migrationFile)." debe contener exactamente {$expectedOperations} operacion(es) de esquema en up()."
            );
        }
    }

    public function test_complete_database_structure_is_created(): void
    {
        $tables = [
            'empresas',
            'sucursales',
            'usuarios',
            'password_reset_tokens',
            'sessions',
            'personal_access_tokens',
            'roles',
            'permisos',
            'usuario_roles',
            'rol_permisos',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'terceros',
            'tercero_roles',
            'almacenes',
            'tipos_pollo',
            'tipos_java',
            'tipos_bandeja',
            'ajustes_peso_minorista',
            'balanzas',
            'conductores',
            'vehiculos',
            'proveedor_vehiculos',
            'listas_precios',
            'precios_historial',
            'programaciones_recepcion',
            'programacion_recepcion_detalles',
            'jornadas_operativas',
            'tickets_despacho',
            'ticket_precios',
            'lecturas_balanza',
            'pesadas',
            'movimientos_inventario',
            'movimiento_detalles',
            'existencias_almacen',
            'comprobantes',
            'comprobante_detalles',
            'comprobante_tickets',
            'comprobante_pesadas',
            'pagos',
            'pago_aplicaciones',
            'pago_aplicacion_operaciones',
            'auditoria_eventos',
            'movimientos_javas',
            'inventarios_javas',
            'conteos_diarios_javas',
            'conteos_diarios_javas_camiones',
            'entidades_financieras',
            'cuentas_financieras',
            'metodos_pago',
            'costos_compra_pesadas',
            'compras',
            'compra_detalles',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "No se creó la tabla {$table}.");
        }
    }

    public function test_core_tables_include_the_columns_required_by_the_domain(): void
    {
        $expectations = [
            'usuarios' => ['empresa_id', 'sucursal_id', 'nombre', 'email', 'password_hash', 'estado'],
            'terceros' => ['empresa_id', 'nombre_razon_social', 'numero_documento', 'direccion', 'es_cliente_interno', 'estado'],
            'tipos_pollo' => ['codigo', 'nombre', 'permite_despacho', 'precio_fuente_tipo_pollo_id', 'estado'],
            'precios_historial' => ['lista_precio_id', 'tipo_pollo_id', 'precio_kg', 'vigente_desde', 'vigente_hasta'],
            'conductores' => ['empresa_id', 'nombre_completo', 'tipo_documento', 'numero_documento', 'telefono', 'estado'],
            'vehiculos' => ['empresa_id', 'placa', 'marca', 'modelo', 'color', 'descripcion', 'es_propio', 'estado'],
            'programacion_recepcion_detalles' => ['programacion_id', 'proveedor_vehiculo_id', 'estado', 'hora_estimada'],
            'tickets_despacho' => ['jornada_id', 'codigo', 'referencia_externa', 'canal', 'tipo_operacion', 'cliente_destino_id', 'almacen_destino_id', 'vehiculo_entrega_id', 'conductor_entrega_id'],
            'movimientos_javas' => ['jornada_id', 'cliente_id', 'tipo', 'cantidad', 'cantidad_bandejas', 'vehiculo_id', 'fecha_movimiento'],
            'inventarios_javas' => ['empresa_id', 'cantidad_total', 'cantidad_total_bandejas', 'updated_by'],
            'conteos_diarios_javas' => ['empresa_id', 'jornada_id', 'cantidad_en_empresa', 'cantidad_en_local', 'cantidad_esperada', 'diferencia', 'cantidad_en_empresa_bandejas', 'cantidad_en_local_bandejas', 'cantidad_esperada_bandejas', 'cantidad_clientes_externos', 'cantidad_clientes_externos_bandejas', 'cantidad_clientes_internos', 'cantidad_clientes_internos_bandejas', 'cantidad_total_inventario', 'cantidad_total_inventario_bandejas', 'diferencia_bandejas', 'contado_at', 'contado_por'],
            'conteos_diarios_javas_camiones' => ['conteo_diario_java_id', 'vehiculo_id', 'placa_snapshot', 'cantidad_javas', 'cantidad_bandejas'],
            'tipos_bandeja' => ['codigo', 'nombre', 'peso_kg', 'capacidad_aves', 'estado'],
            'ajustes_peso_minorista' => ['empresa_id', 'estacion', 'codigo', 'nombre', 'sexo', 'presentacion', 'gramos_adicionales', 'predeterminado', 'estado'],
            'balanzas' => ['sucursal_id', 'codigo', 'modo_conexion', 'dispositivo', 'configuracion', 'estado'],
            'pesadas' => ['ticket_id', 'tipo_pollo_id', 'condicion_pollo', 'sexo', 'presentacion_pollo', 'tipo_java_id', 'tipo_bandeja_id', 'ajuste_peso_minorista_id', 'aves_por_bandeja', 'cantidad_bandejas', 'peso_bandeja_kg_snapshot', 'peso_leido_kg', 'ajuste_peso_gramos', 'peso_bruto_kg', 'tara_total_kg', 'peso_neto_kg'],
            'movimientos_inventario' => ['tipo', 'almacen_origen_id', 'almacen_destino_id', 'estado', 'fecha_hora'],
            'entidades_financieras' => ['empresa_id', 'tipo', 'proveedor_id', 'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial', 'direccion', 'telefono', 'email', 'estado', 'created_by'],
            'cuentas_financieras' => ['entidad_financiera_id', 'tipo', 'alias', 'banco', 'numero_cuenta', 'cci', 'moneda', 'estado', 'created_by'],
            'metodos_pago' => ['codigo', 'nombre', 'requiere_referencia', 'estado'],
            'costos_compra_pesadas' => ['pesada_id', 'proveedor_id', 'precio_historial_id', 'precio_kg', 'peso_kg', 'importe', 'estado', 'origen', 'created_by'],
            'comprobantes' => ['operacion', 'naturaleza', 'codigo', 'origen_codigo', 'origen_clave', 'total', 'saldo_pendiente', 'contraparte_tipo_documento_snapshot', 'contraparte_numero_documento_snapshot', 'contraparte_nombre_snapshot', 'contraparte_direccion_snapshot', 'anulada_por', 'anulada_at', 'motivo_anulacion'],
            'pagos' => ['empresa_id', 'codigo', 'tercero_id', 'tipo', 'cliente_id', 'proveedor_id', 'cuenta_origen_id', 'cuenta_destino_id', 'metodo_pago_id', 'direccion', 'fecha_hora', 'metodo', 'referencia', 'importe', 'estado', 'idempotency_key', 'reversa_de_pago_id', 'anulada_por', 'anulada_at', 'motivo_anulacion', 'created_at', 'updated_at'],
            'pago_aplicaciones' => ['pago_id', 'comprobante_id', 'lado', 'importe_aplicado', 'created_by', 'created_at'],
            'pago_aplicacion_operaciones' => ['empresa_id', 'pago_id', 'idempotency_key', 'payload_hash', 'importe_total', 'aplicaciones', 'observaciones', 'created_by', 'created_at'],
            'auditoria_eventos' => ['usuario_id', 'entidad', 'entidad_id', 'accion', 'datos_antes', 'datos_despues'],
            'compras' => ['empresa_id', 'proveedor_id', 'comprobante_id', 'pago_inicial_id', 'codigo', 'idempotency_key', 'tipo_documento', 'numero_documento', 'numero_documento_activo', 'fecha_compra', 'fecha_vencimiento', 'condicion', 'moneda', 'subtotal', 'impuesto', 'total', 'estado', 'observaciones', 'created_by', 'anulada_por', 'anulada_at', 'motivo_anulacion'],
            'compra_detalles' => ['compra_id', 'tipo_pollo_id', 'descripcion', 'cantidad_aves', 'peso_kg', 'precio_kg', 'subtotal', 'created_at'],
        ];

        foreach ($expectations as $table => $columns) {
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "La tabla {$table} no contiene todas las columnas requeridas."
            );
        }

        $this->assertFalse(Schema::hasColumn('vehiculos', 'conductor_habitual_id'));

        $weighingColumns = collect(Schema::getColumns('pesadas'))->keyBy('name');
        $this->assertTrue($weighingColumns->get('sexo')['nullable']);
        $this->assertTrue($weighingColumns->get('ajuste_peso_minorista_id')['nullable']);
        $this->assertTrue($weighingColumns->get('presentacion_pollo')['nullable']);
        $this->assertTrue($weighingColumns->get('ajuste_peso_gramos')['nullable']);

        $this->assertTrue(collect(Schema::getColumns('comprobantes'))->keyBy('name')->get('tercero_id')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('pagos'))->keyBy('name')->get('tercero_id')['nullable']);
        $this->assertFalse(Schema::hasColumn('cuentas_financieras', 'saldo_actual'));
    }

    public function test_financial_catalogs_and_permissions_are_created_by_migrations(): void
    {
        $this->assertEqualsCanonicalizing(
            ['DEPOSITO', 'TRANSFERENCIA', 'EFECTIVO', 'YAPE', 'PLIN', 'CHEQUE', 'OTRO'],
            DB::table('metodos_pago')->pluck('codigo')->all()
        );

        $this->assertEqualsCanonicalizing(
            [
                'FINANZAS_VER',
                'CUENTAS_FINANCIERAS_GESTIONAR',
                'PAGOS_REGISTRAR',
                'PAGOS_ANULAR',
                'SALDOS_AJUSTAR',
                'COMPRAS_VER',
                'COMPRAS_REGISTRAR',
                'COMPRAS_ANULAR',
            ],
            DB::table('permisos')
                ->whereIn('codigo', [
                    'FINANZAS_VER',
                    'CUENTAS_FINANCIERAS_GESTIONAR',
                    'PAGOS_REGISTRAR',
                    'PAGOS_ANULAR',
                    'SALDOS_AJUSTAR',
                    'COMPRAS_VER',
                    'COMPRAS_REGISTRAR',
                    'COMPRAS_ANULAR',
                ])
                ->pluck('codigo')
                ->all()
        );
    }

    public function test_financial_permission_migration_assigns_permissions_to_existing_administrators(): void
    {
        $user = User::factory()->create();
        $administratorId = DB::table('roles')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador existente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path(
            'migrations/2026_07_12_000009_add_financial_permissions.php'
        );

        $migration->down();
        $migration->up();

        $this->assertSame(
            5,
            DB::table('rol_permisos')
                ->where('rol_id', $administratorId)
                ->whereIn('permiso_id', DB::table('permisos')
                    ->whereIn('codigo', [
                        'FINANZAS_VER',
                        'CUENTAS_FINANCIERAS_GESTIONAR',
                        'PAGOS_REGISTRAR',
                        'PAGOS_ANULAR',
                        'SALDOS_AJUSTAR',
                    ])
                    ->select('id'))
                ->count()
        );
    }

    public function test_purchase_permission_migration_assigns_permissions_to_existing_administrators(): void
    {
        $user = User::factory()->create();
        $administratorId = DB::table('roles')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador de compras existente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require database_path(
            'migrations/2026_07_14_000004_add_purchase_permissions.php'
        );

        $migration->down();
        $migration->up();

        $this->assertSame(
            3,
            DB::table('rol_permisos')
                ->where('rol_id', $administratorId)
                ->whereIn('permiso_id', DB::table('permisos')
                    ->whereIn('codigo', ['COMPRAS_VER', 'COMPRAS_REGISTRAR', 'COMPRAS_ANULAR'])
                    ->select('id'))
                ->count()
        );
    }

    public function test_active_purchase_document_migration_rolls_back_and_can_be_applied_again(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_000006_allow_reusing_voided_purchase_documents.php'
        );

        $this->assertTrue(Schema::hasColumn('compras', 'numero_documento_activo'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('compras', 'numero_documento_activo'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('compras', 'numero_documento_activo'));
    }

    public function test_database_seeder_keeps_financial_catalogs_and_admin_permissions(): void
    {
        $this->seed();

        $administratorId = DB::table('roles')
            ->where('codigo', 'ADMINISTRADOR')
            ->value('id');

        $this->assertNotNull($administratorId);
        $this->assertSame(7, DB::table('metodos_pago')->count());
        $this->assertSame(
            8,
            DB::table('rol_permisos')
                ->where('rol_id', $administratorId)
                ->whereIn('permiso_id', DB::table('permisos')
                    ->whereIn('codigo', [
                        'FINANZAS_VER',
                        'CUENTAS_FINANCIERAS_GESTIONAR',
                        'PAGOS_REGISTRAR',
                        'PAGOS_ANULAR',
                        'SALDOS_AJUSTAR',
                        'COMPRAS_VER',
                        'COMPRAS_REGISTRAR',
                        'COMPRAS_ANULAR',
                    ])
                    ->select('id'))
                ->count()
        );
    }

    public function test_financial_schema_rolls_back_and_can_be_applied_again_on_sqlite(): void
    {
        $paths = [
            database_path('migrations/2026_07_12_000003_create_entidades_financieras_table.php'),
            database_path('migrations/2026_07_12_000004_create_cuentas_financieras_table.php'),
            database_path('migrations/2026_07_12_000005_create_metodos_pago_table.php'),
            database_path('migrations/2026_07_12_000006_create_costos_compra_pesadas_table.php'),
            database_path('migrations/2026_07_12_000007_extend_comprobantes_for_financial_control.php'),
            database_path('migrations/2026_07_12_000008_extend_pagos_and_pago_aplicaciones.php'),
            database_path('migrations/2026_07_12_000009_add_financial_permissions.php'),
        ];
        $migrations = collect($paths)->map(fn (string $path) => require $path);

        $user = User::factory()->create();
        $anonymousDocumentId = DB::table('comprobantes')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'tercero_id' => null,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'INTERNO',
            'codigo' => 'ROLLBACK-ANONIMO',
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => 'ROLLBACK:ANONIMO',
            'fecha_emision' => now()->toDateString(),
            'moneda' => 'PEN',
            'subtotal' => 10,
            'impuesto' => 0,
            'total' => 10,
            'saldo_pendiente' => 10,
            'estado' => 'PENDIENTE',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $anonymousPaymentId = DB::table('pagos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'tercero_id' => null,
            'codigo' => 'MOV-ROLLBACK-1',
            'tipo' => 'SALDO_INICIAL',
            'direccion' => 'INGRESO',
            'fecha_hora' => now(),
            'metodo' => 'SALDO_INICIAL',
            'moneda' => 'PEN',
            'importe' => 10,
            'estado' => 'REGISTRADO',
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $anonymousReverseId = DB::table('pagos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'tercero_id' => null,
            'codigo' => 'MOV-ROLLBACK-2',
            'tipo' => 'SALDO_INICIAL',
            'direccion' => 'EGRESO',
            'fecha_hora' => now(),
            'metodo' => 'SALDO_INICIAL',
            'moneda' => 'PEN',
            'importe' => 10,
            'estado' => 'REGISTRADO',
            'idempotency_key' => (string) Str::uuid(),
            'reversa_de_pago_id' => $anonymousPaymentId,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pago_aplicaciones')->insert([
            'pago_id' => $anonymousPaymentId,
            'comprobante_id' => $anonymousDocumentId,
            'lado' => 'CXC',
            'importe_aplicado' => 10,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $migrations->reverse()->each(fn ($migration) => $migration->down());

        $this->assertDatabaseMissing('pagos', ['id' => $anonymousPaymentId]);
        $this->assertDatabaseMissing('pagos', ['id' => $anonymousReverseId]);
        $this->assertDatabaseMissing('comprobantes', ['id' => $anonymousDocumentId]);

        $this->assertFalse(Schema::hasTable('entidades_financieras'));
        $this->assertFalse(Schema::hasTable('cuentas_financieras'));
        $this->assertFalse(Schema::hasTable('metodos_pago'));
        $this->assertFalse(Schema::hasTable('costos_compra_pesadas'));
        $this->assertFalse(Schema::hasColumn('comprobantes', 'naturaleza'));
        $this->assertFalse(Schema::hasColumn('pagos', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('pago_aplicaciones', 'lado'));

        $migrations->each(fn ($migration) => $migration->up());

        $this->assertTrue(Schema::hasTable('entidades_financieras'));
        $this->assertTrue(Schema::hasTable('cuentas_financieras'));
        $this->assertTrue(Schema::hasTable('metodos_pago'));
        $this->assertTrue(Schema::hasTable('costos_compra_pesadas'));
        $this->assertTrue(Schema::hasColumn('comprobantes', 'naturaleza'));
        $this->assertTrue(Schema::hasColumn('pagos', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('pago_aplicaciones', 'lado'));
    }

    public function test_retail_schema_rolls_back_and_can_be_applied_again_on_sqlite(): void
    {
        $paths = [
            database_path('migrations/2026_07_04_000001_add_bandejas_to_dispatch_weighings.php'),
            database_path('migrations/2026_07_04_000002_add_bandeja_columns_to_pesadas_table.php'),
            database_path('migrations/2026_07_04_000003_create_ajustes_peso_minorista_table.php'),
            database_path('migrations/2026_07_04_000004_add_ajuste_minorista_columns_to_pesadas_table.php'),
        ];
        $migrations = collect($paths)->map(fn (string $path) => require $path);

        $migrations->reverse()->each(fn ($migration) => $migration->down());

        $this->assertFalse(Schema::hasTable('tipos_bandeja'));
        $this->assertFalse(Schema::hasTable('ajustes_peso_minorista'));
        $this->assertFalse(Schema::hasColumn('pesadas', 'tipo_bandeja_id'));
        $this->assertFalse(Schema::hasColumn('pesadas', 'ajuste_peso_minorista_id'));

        $migrations->each(fn ($migration) => $migration->up());

        $this->assertTrue(Schema::hasTable('tipos_bandeja'));
        $this->assertTrue(Schema::hasTable('ajustes_peso_minorista'));
        $this->assertTrue(Schema::hasColumns('pesadas', [
            'tipo_bandeja_id',
            'ajuste_peso_minorista_id',
            'presentacion_pollo',
            'ajuste_peso_gramos',
        ]));
    }

    public function test_retail_adjustment_station_migration_rolls_back_and_reapplies_on_sqlite(): void
    {
        $user = User::factory()->create();
        $migration = require database_path(
            'migrations/2026_07_22_000001_add_station_to_retail_weight_adjustments.php'
        );
        $base = [
            'empresa_id' => $user->empresa_id,
            'codigo' => 'MACHO_CERRADO',
            'nombre' => 'Macho cerrado',
            'sexo' => 'MACHO',
            'presentacion' => 'CERRADO',
            'predeterminado' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('ajustes_peso_minorista')->insert([
            [...$base, 'estacion' => 1, 'gramos_adicionales' => 175],
            [...$base, 'estacion' => 2, 'gramos_adicionales' => 325],
        ]);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('ajustes_peso_minorista', 'estacion'));
        $this->assertDatabaseCount('ajustes_peso_minorista', 1);
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $user->empresa_id,
            'codigo' => 'MACHO_CERRADO',
            'gramos_adicionales' => 175,
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('ajustes_peso_minorista', 'estacion'));
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $user->empresa_id,
            'estacion' => 1,
            'codigo' => 'MACHO_CERRADO',
            'gramos_adicionales' => 175,
        ]);
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $user->empresa_id,
            'estacion' => 2,
            'codigo' => 'MACHO_CERRADO',
            'gramos_adicionales' => 175,
        ]);
    }

    public function test_vehicle_ownership_migration_normalizes_legacy_data_and_default(): void
    {
        $migration = require database_path(
            'migrations/2026_07_12_000001_normalize_company_vehicle_ownership.php'
        );
        $migration->down();
        $user = User::factory()->create();
        $providerId = DB::table('terceros')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900111222',
            'nombre_razon_social' => 'Proveedor legacy',
            'direccion' => 'Direccion legacy',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'placa' => 'LEG-001',
            'tercero_propietario_id' => $providerId,
            'es_propio' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseHas('vehiculos', [
            'id' => $legacyVehicleId,
            'tercero_propietario_id' => null,
            'es_propio' => true,
        ]);

        $defaultVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'placa' => 'DEF-001',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('vehiculos', [
            'id' => $defaultVehicleId,
            'tercero_propietario_id' => null,
            'es_propio' => true,
        ]);
    }

    public function test_tray_movement_migration_backfills_existing_retail_dispatches(): void
    {
        $user = User::factory()->create();
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'BACKFILL',
            'nombre' => 'Sucursal backfill',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900777888',
            'nombre_razon_social' => 'Cliente bandejas legacy',
            'direccion' => 'Direccion legacy',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'placa' => 'BAN-001',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverId = DB::table('conductores')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'nombre_completo' => 'CHOFER BANDEJAS LEGACY',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => '2026-07-12',
            'estado' => 'ABIERTA',
            'abierta_por' => $user->id,
            'inicio_at' => '2026-07-11 21:00:00',
            'cierre_programado_at' => '2026-07-12 21:00:00',
        ]);
        $ticketId = DB::table('tickets_despacho')->insertGetId([
            'jornada_id' => $journeyId,
            'codigo' => 'M-BACKFILL-001',
            'canal' => 'MINORISTA',
            'tipo_operacion' => 'DESPACHO',
            'cliente_destino_id' => $clientId,
            'vehiculo_entrega_id' => $vehicleId,
            'conductor_entrega_id' => $driverId,
            'estado' => 'CERRADO',
            'cerrado_por' => $user->id,
            'cerrado_at' => '2026-07-12 10:00:00',
            'created_by' => $user->id,
            'created_at' => '2026-07-12 09:45:00',
            'updated_at' => '2026-07-12 10:00:00',
        ]);
        $chickenTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'BACKFILL_BANDEJAS',
            'nombre' => 'Pollo backfill bandejas',
            'permite_despacho' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $trayTypeId = (int) DB::table('tipos_bandeja')->value('id');

        DB::table('pesadas')->insert([
            'ticket_id' => $ticketId,
            'numero' => 1,
            'tipo_pollo_id' => $chickenTypeId,
            'condicion_pollo' => 'VIVO',
            'tipo_bandeja_id' => $trayTypeId,
            'origen_peso' => 'MANUAL',
            'aves_por_bandeja' => 5,
            'cantidad_bandejas' => 3,
            'cantidad_aves' => 15,
            'peso_bandeja_kg_snapshot' => 0,
            'peso_leido_kg' => 12,
            'peso_bruto_kg' => 12,
            'tara_total_kg' => 0,
            'peso_neto_kg' => 12,
            'pesada_at' => '2026-07-12 09:55:00',
            'estado' => 'ACTIVA',
            'created_by' => $user->id,
            'created_at' => '2026-07-12 09:55:00',
            'updated_at' => '2026-07-12 09:55:00',
        ]);

        $migration = require database_path(
            'migrations/2026_07_12_000002_add_trays_to_java_movements.php'
        );
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $ticketId,
            'empresa_id' => $user->empresa_id,
            'sucursal_id' => $branchId,
            'jornada_id' => $journeyId,
            'cliente_id' => $clientId,
            'tipo' => 'DESPACHO',
            'cantidad' => 0,
            'cantidad_bandejas' => 3,
            'vehiculo_id' => $vehicleId,
            'conductor_id' => $driverId,
        ]);
    }
}
