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

        $this->assertCount(114, $migrationFiles);

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
                '2026_08_01_000002_add_provider_report_module.php',
                '2026_08_14_000001_add_second_wholesale_dispatch_module.php',
                '2026_08_15_000002_add_java_680_to_tipos_java_table.php' => 0,
                '2026_07_12_000002_add_trays_to_java_movements.php' => 3,
                '2026_07_12_000008_extend_pagos_and_pago_aplicaciones.php' => 2,
                '2026_07_22_000001_add_station_to_retail_weight_adjustments.php' => 2,
                '2026_08_01_000003_create_cobranzas_tables.php' => 3,
                '2026_08_04_000001_create_cobranza_asignaciones_table.php' => 2,
                '2026_08_14_000003_create_wholesale_two_weight_adjustments.php' => 2,
                '2026_08_22_000001_create_live_chicken_reception_module.php' => 5,
                '2026_08_27_000001_allow_negative_java_inventory_balances.php' => 2,
                '2026_08_28_000002_create_product_dispatch_catalog.php' => 2,
                '2026_08_28_000003_create_product_dispatch_operation.php' => 4,
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
            'modulos_sistema',
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
            'ajustes_peso_mayorista_2',
            'configuraciones_despacho_minorista',
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
            'configuraciones_recepcion_pollo_vivo',
            'recepciones_pollo_vivo',
            'pesadas_recepcion_pollo_vivo',
            'recepcion_pollo_vivo_tickets',
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
            'ticket_precio_ajuste_operaciones',
            'auditoria_eventos',
            'movimientos_javas',
            'ajustes_saldos_javas',
            'inventarios_javas',
            'conteos_diarios_javas',
            'conteos_diarios_javas_camiones',
            'entidades_financieras',
            'cuentas_financieras',
            'metodos_pago',
            'costos_compra_pesadas',
            'compras',
            'compra_detalles',
            'gastos_empresa',
            'movimientos_caja_efectivo',
            'cobradores',
            'cobranzas',
            'cobranza_detalles',
            'cobranza_pendientes',
            'cobranza_asignaciones',
            'productos_despacho',
            'variaciones_producto_despacho',
            'tickets_despacho_productos',
            'pesadas_despacho_productos',
            'comprobante_tickets_despacho_productos',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "No se creó la tabla {$table}.");
        }
    }

    public function test_core_tables_include_the_columns_required_by_the_domain(): void
    {
        $expectations = [
            'empresas' => ['mensaje_ticket', 'titulo_ticket', 'paleta_reportes'],
            'usuarios' => ['empresa_id', 'sucursal_id', 'nombre', 'email', 'password_hash', 'estado'],
            'modulos_sistema' => ['codigo', 'activo', 'created_at', 'updated_at'],
            'terceros' => ['empresa_id', 'nombre_razon_social', 'numero_documento', 'direccion', 'es_cliente_interno', 'estado'],
            'tipos_pollo' => ['codigo', 'nombre', 'permite_despacho', 'precio_fuente_tipo_pollo_id', 'estado'],
            'precios_historial' => ['lista_precio_id', 'tipo_pollo_id', 'precio_kg', 'vigente_desde', 'vigente_hasta'],
            'conductores' => ['empresa_id', 'nombre_completo', 'tipo_documento', 'numero_documento', 'telefono', 'estado'],
            'vehiculos' => ['empresa_id', 'placa', 'marca', 'modelo', 'color', 'descripcion', 'es_propio', 'estado'],
            'programacion_recepcion_detalles' => ['programacion_id', 'proveedor_vehiculo_id', 'estado', 'hora_estimada'],
            'tickets_despacho' => ['jornada_id', 'codigo', 'referencia_externa', 'canal', 'modulo_origen', 'tipo_operacion', 'cliente_destino_id', 'almacen_destino_id', 'vehiculo_entrega_id', 'conductor_entrega_id', 'asignacion_transporte_posterior', 'estado', 'anulado_por', 'anulado_at', 'motivo_anulacion'],
            'movimientos_javas' => ['jornada_id', 'cliente_id', 'tipo', 'cantidad', 'cantidad_bandejas', 'ticket_despacho_id', 'pesada_recepcion_pollo_vivo_id', 'vehiculo_id', 'fecha_movimiento'],
            'ajustes_saldos_javas' => ['empresa_id', 'sucursal_id', 'jornada_id', 'cliente_id', 'saldo_anterior_javas', 'saldo_nuevo_javas', 'diferencia_javas', 'saldo_anterior_bandejas', 'saldo_nuevo_bandejas', 'diferencia_bandejas', 'motivo', 'created_by'],
            'inventarios_javas' => ['empresa_id', 'cantidad_total', 'cantidad_total_bandejas', 'updated_by'],
            'conteos_diarios_javas' => ['empresa_id', 'jornada_id', 'cantidad_en_empresa', 'cantidad_en_local', 'cantidad_esperada', 'diferencia', 'cantidad_en_empresa_bandejas', 'cantidad_en_local_bandejas', 'cantidad_esperada_bandejas', 'cantidad_clientes_externos', 'cantidad_clientes_externos_bandejas', 'cantidad_clientes_internos', 'cantidad_clientes_internos_bandejas', 'cantidad_total_inventario', 'cantidad_total_inventario_bandejas', 'diferencia_bandejas', 'contado_at', 'contado_por'],
            'conteos_diarios_javas_camiones' => ['conteo_diario_java_id', 'vehiculo_id', 'placa_snapshot', 'cantidad_javas', 'cantidad_bandejas'],
            'tipos_bandeja' => ['codigo', 'nombre', 'peso_kg', 'capacidad_aves', 'estado'],
            'ajustes_peso_minorista' => ['empresa_id', 'estacion', 'codigo', 'nombre', 'sexo', 'presentacion', 'gramos_adicionales', 'predeterminado', 'estado'],
            'ajustes_peso_mayorista_2' => ['empresa_id', 'codigo', 'nombre', 'sexo', 'presentacion', 'gramos_adicionales', 'estado'],
            'configuraciones_despacho_minorista' => ['empresa_id', 'sucursal_id', 'estacion', 'metodo_pago_id', 'cuenta_destino_id'],
            'balanzas' => ['sucursal_id', 'codigo', 'modo_conexion', 'dispositivo', 'configuracion', 'estado'],
            'pesadas' => ['ticket_id', 'tipo_pollo_id', 'condicion_pollo', 'sexo', 'presentacion_pollo', 'tipo_java_id', 'tipo_bandeja_id', 'ajuste_peso_minorista_id', 'ajuste_peso_mayorista_2_id', 'aves_por_bandeja', 'cantidad_bandejas', 'peso_bandeja_kg_snapshot', 'peso_leido_kg', 'ajuste_peso_gramos', 'ajuste_peso_mayorista_2_gramos', 'peso_bruto_kg', 'tara_total_kg', 'peso_neto_kg'],
            'configuraciones_recepcion_pollo_vivo' => ['sucursal_id', 'propietario_externo_predeterminado_id', 'almacen_columna_1_id', 'almacen_columna_2_id', 'almacen_columna_3_id', 'almacen_columna_4_id', 'cliente_columna_3_id', 'cliente_columna_4_id', 'aves_por_java_macho', 'aves_por_java_hembra', 'cantidad_javas_predeterminada', 'tipo_java_predeterminado_id', 'updated_by'],
            'recepciones_pollo_vivo' => ['jornada_id', 'origen', 'estado', 'created_by'],
            'pesadas_recepcion_pollo_vivo' => ['recepcion_id', 'idempotency_key', 'numero', 'columna', 'propietario_tipo', 'propietario_externo_id', 'destino_tipo', 'almacen_destino_id', 'cliente_destino_id', 'sexo', 'tipo_pollo_id', 'tipo_java_id', 'lectura_balanza_id', 'origen_peso', 'aves_por_java', 'cantidad_javas', 'cantidad_aves', 'peso_java_kg_snapshot', 'peso_leido_kg', 'peso_bruto_kg', 'tara_total_kg', 'peso_neto_kg', 'pesada_at', 'estado', 'anulada_por', 'anulada_at', 'motivo_anulacion', 'created_by'],
            'recepcion_pollo_vivo_tickets' => ['recepcion_id', 'ticket_despacho_id', 'movimiento_inventario_id', 'columna', 'request_hash', 'cantidad_javas_aplicada', 'revision', 'created_by'],
            'movimientos_inventario' => ['tipo', 'almacen_origen_id', 'almacen_destino_id', 'estado', 'fecha_hora'],
            'movimiento_detalles' => ['movimiento_id', 'pesada_id', 'pesada_recepcion_pollo_vivo_id', 'tipo_pollo_id', 'cantidad_aves', 'peso_neto_kg'],
            'entidades_financieras' => ['empresa_id', 'tipo', 'proveedor_id', 'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial', 'direccion', 'telefono', 'email', 'estado', 'created_by'],
            'cuentas_financieras' => ['entidad_financiera_id', 'tipo', 'alias', 'banco', 'numero_cuenta', 'cci', 'moneda', 'estado', 'created_by'],
            'metodos_pago' => ['codigo', 'nombre', 'requiere_referencia', 'estado'],
            'costos_compra_pesadas' => ['pesada_id', 'proveedor_id', 'precio_historial_id', 'precio_kg', 'peso_kg', 'importe', 'estado', 'origen', 'created_by'],
            'comprobantes' => ['operacion', 'naturaleza', 'codigo', 'origen_codigo', 'origen_clave', 'total', 'saldo_pendiente', 'contraparte_tipo_documento_snapshot', 'contraparte_numero_documento_snapshot', 'contraparte_nombre_snapshot', 'contraparte_direccion_snapshot', 'anulada_por', 'anulada_at', 'motivo_anulacion'],
            'comprobante_detalles' => ['comprobante_id', 'tipo_pollo_id', 'producto_despacho_id', 'variacion_producto_despacho_id', 'descripcion', 'cantidad_aves', 'cantidad_unidades', 'peso_neto_kg', 'modo_precio', 'precio_kg', 'precio_unitario', 'subtotal'],
            'tickets_despacho_productos' => ['empresa_id', 'sucursal_id', 'referencia_externa', 'codigo', 'fecha_operativa', 'cliente_id', 'tipo_cliente', 'cliente_nombre_snapshot', 'cantidad_total', 'peso_leido_total_kg', 'merma_total_gramos', 'peso_neto_total_kg', 'subtotal', 'total', 'estado', 'registrado_at', 'created_by'],
            'pesadas_despacho_productos' => ['ticket_despacho_producto_id', 'numero', 'producto_despacho_id', 'variacion_producto_despacho_id', 'lectura_balanza_id', 'producto_nombre_snapshot', 'variacion_nombre_snapshot', 'modo_precio_snapshot', 'precio_catalogo_snapshot', 'precio_venta_snapshot', 'origen_precio', 'cantidad', 'origen_peso', 'peso_leido_kg', 'merma_catalogo_gramos_unidad', 'merma_total_gramos', 'peso_neto_kg', 'importe', 'pesada_at', 'created_by'],
            'pagos' => ['empresa_id', 'codigo', 'tercero_id', 'tipo', 'cliente_id', 'proveedor_id', 'cuenta_origen_id', 'cuenta_destino_id', 'metodo_pago_id', 'direccion', 'fecha_hora', 'metodo', 'referencia', 'importe', 'estado', 'idempotency_key', 'reversa_de_pago_id', 'anulada_por', 'anulada_at', 'motivo_anulacion', 'created_at', 'updated_at'],
            'pago_aplicaciones' => ['pago_id', 'comprobante_id', 'lado', 'importe_aplicado', 'created_by', 'created_at'],
            'pago_aplicacion_operaciones' => ['empresa_id', 'pago_id', 'idempotency_key', 'payload_hash', 'importe_total', 'aplicaciones', 'observaciones', 'created_by', 'created_at'],
            'ticket_precio_ajuste_operaciones' => ['empresa_id', 'idempotency_key', 'payload_hash', 'resultado', 'created_by', 'created_at'],
            'auditoria_eventos' => ['usuario_id', 'entidad', 'entidad_id', 'accion', 'datos_antes', 'datos_despues'],
            'compras' => ['empresa_id', 'proveedor_id', 'comprobante_id', 'pago_inicial_id', 'codigo', 'idempotency_key', 'tipo_documento', 'numero_documento', 'numero_documento_activo', 'fecha_compra', 'fecha_vencimiento', 'condicion', 'moneda', 'subtotal', 'impuesto', 'total', 'estado', 'observaciones', 'created_by', 'anulada_por', 'anulada_at', 'motivo_anulacion'],
            'compra_detalles' => ['compra_id', 'tipo_pollo_id', 'descripcion', 'cantidad_aves', 'peso_kg', 'precio_kg', 'subtotal', 'created_at'],
            'gastos_empresa' => ['empresa_id', 'pago_id', 'codigo', 'idempotency_key', 'categoria', 'concepto', 'destino', 'numero_documento', 'estado', 'created_by', 'anulada_por', 'anulada_at', 'motivo_anulacion'],
            'movimientos_caja_efectivo' => ['empresa_id', 'pago_id', 'codigo', 'idempotency_key', 'caja_id', 'direccion', 'contraparte_tipo', 'cliente_id', 'otra_caja_id', 'detalle', 'estado', 'created_by'],
            'cobradores' => ['empresa_id', 'nombre', 'estado', 'created_by', 'created_at', 'updated_at'],
            'cobranzas' => ['empresa_id', 'cobrador_id', 'cobrador_nombre_snapshot', 'codigo', 'idempotency_key', 'payload_hash', 'cuenta_destino_id', 'proveedor_id', 'metodo_pago_id', 'fecha_hora', 'referencia', 'moneda', 'importe_total', 'observaciones', 'estado', 'recibido_en_caja', 'recepcion_caja_actualizada_at', 'recepcion_caja_actualizada_por', 'recepcion_caja_actualizada_por_nombre', 'created_by', 'anulada_por', 'anulada_at', 'motivo_anulacion', 'created_at', 'updated_at'],
            'cobranza_detalles' => ['cobranza_id', 'asignacion_id', 'pago_id', 'cliente_id', 'fecha_recepcion', 'medio_recepcion', 'importe', 'orden', 'created_at'],
            'cobranza_pendientes' => ['cobranza_id', 'pago_id', 'importe', 'created_at'],
            'cobranza_asignaciones' => ['empresa_id', 'cobranza_id', 'idempotency_key', 'payload_hash', 'importe_pendiente_antes', 'importe_asignado', 'importe_pendiente_despues', 'pago_pendiente_anterior_id', 'pago_reversa_id', 'pago_pendiente_nuevo_id', 'created_by', 'created_at'],
            'productos_despacho' => ['empresa_id', 'nombre', 'nombre_normalizado', 'descripcion', 'modo_precio', 'precio_venta', 'merma_gramos_unidad', 'imagen_path', 'estado', 'created_by', 'updated_by'],
            'variaciones_producto_despacho' => ['producto_despacho_id', 'nombre', 'nombre_normalizado', 'modo_precio', 'precio_venta', 'merma_gramos_unidad', 'imagen_path', 'orden', 'estado', 'created_by', 'updated_by'],
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
        $this->assertTrue($weighingColumns->get('ajuste_peso_mayorista_2_id')['nullable']);
        $this->assertTrue($weighingColumns->get('presentacion_pollo')['nullable']);
        $this->assertTrue($weighingColumns->get('ajuste_peso_gramos')['nullable']);
        $this->assertTrue($weighingColumns->get('ajuste_peso_mayorista_2_gramos')['nullable']);

        $ticketPriceColumns = collect(Schema::getColumns('ticket_precios'))->keyBy('name');
        $this->assertTrue($ticketPriceColumns->get('precio_historial_id')['nullable']);

        $this->assertTrue(collect(Schema::getColumns('comprobantes'))->keyBy('name')->get('tercero_id')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('pagos'))->keyBy('name')->get('tercero_id')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('ticket_precio_ajuste_operaciones'))->keyBy('name')->get('resultado')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('empresas'))->keyBy('name')->get('mensaje_ticket')['nullable']);
        $this->assertFalse(collect(Schema::getColumns('empresas'))->keyBy('name')->get('titulo_ticket')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('empresas'))->keyBy('name')->get('paleta_reportes')['nullable']);
        $this->assertFalse(Schema::hasColumn('cuentas_financieras', 'saldo_actual'));

        $paymentIndexes = collect(Schema::getIndexes('pagos'))->keyBy('name');
        $originIndex = $paymentIndexes->get('pago_empresa_origen_estado_reversa_fecha_index');
        $destinationIndex = $paymentIndexes->get('pago_empresa_destino_estado_reversa_fecha_index');
        $this->assertNotNull($originIndex);
        $this->assertNotNull($destinationIndex);
        $this->assertSame(
            ['empresa_id', 'cuenta_origen_id', 'estado', 'reversa_de_pago_id', 'fecha_hora'],
            $originIndex['columns'],
        );
        $this->assertSame(
            ['empresa_id', 'cuenta_destino_id', 'estado', 'reversa_de_pago_id', 'fecha_hora'],
            $destinationIndex['columns'],
        );
        $this->assertFalse($originIndex['unique']);
        $this->assertFalse($destinationIndex['unique']);

        $collectionColumns = collect(Schema::getColumns('cobranzas'))->keyBy('name');
        $this->assertTrue($collectionColumns->get('recibido_en_caja')['nullable']);
        $this->assertTrue($collectionColumns->get('recepcion_caja_actualizada_at')['nullable']);
        $this->assertTrue($collectionColumns->get('recepcion_caja_actualizada_por')['nullable']);
        $this->assertTrue($collectionColumns->get('recepcion_caja_actualizada_por_nombre')['nullable']);
        $collectionReceiptIndex = collect(Schema::getIndexes('cobranzas'))
            ->keyBy('name')
            ->get('cobranza_empresa_cuenta_estado_fecha_index');
        $this->assertNotNull($collectionReceiptIndex);
        $this->assertSame(
            ['empresa_id', 'cuenta_destino_id', 'estado', 'fecha_hora'],
            $collectionReceiptIndex['columns'],
        );
        $this->assertFalse($collectionReceiptIndex['unique']);
        $pendingReceiptIndex = collect(Schema::getIndexes('cobranzas'))
            ->keyBy('name')
            ->get('cobranza_recepcion_pendiente_fecha_index');
        $this->assertNotNull($pendingReceiptIndex);
        $this->assertSame(
            ['empresa_id', 'cuenta_destino_id', 'estado', 'recibido_en_caja', 'fecha_hora'],
            $pendingReceiptIndex['columns'],
        );
        $this->assertFalse($pendingReceiptIndex['unique']);
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

    public function test_product_dispatch_migration_recovers_empty_tables_from_an_interrupted_attempt(): void
    {
        $migration = require database_path(
            'migrations/2026_08_28_000003_create_product_dispatch_operation.php'
        );

        $migration->down();

        Schema::create('tickets_despacho_productos', function ($table): void {
            $table->id();
        });
        Schema::create('pesadas_despacho_productos', function ($table): void {
            $table->id();
        });

        $migration->up();

        $this->assertTrue(Schema::hasColumn('tickets_despacho_productos', 'referencia_externa'));
        $this->assertTrue(Schema::hasColumn('pesadas_despacho_productos', 'variacion_producto_despacho_id'));
        $this->assertTrue(Schema::hasColumn('comprobante_detalles', 'producto_despacho_id'));
        $this->assertTrue(Schema::hasTable('comprobante_tickets_despacho_productos'));
    }

    public function test_product_dispatch_foreign_key_names_fit_the_mysql_identifier_limit(): void
    {
        $contents = file_get_contents(database_path(
            'migrations/2026_08_28_000003_create_product_dispatch_operation.php'
        ));

        preg_match_all('/->constrained\(([^)]*)\)/', $contents, $constraints);
        $this->assertNotEmpty($constraints[1]);

        foreach ($constraints[1] as $arguments) {
            preg_match_all("/'([^']+)'/", $arguments, $quotedArguments);
            $this->assertGreaterThanOrEqual(
                3,
                count($quotedArguments[1]),
                "La llave foránea constrained({$arguments}) debe tener un nombre explícito.",
            );
            $this->assertLessThanOrEqual(64, strlen($quotedArguments[1][2]));
        }
    }

    public function test_database_seeder_keeps_financial_catalogs_and_admin_permissions(): void
    {
        $this->seed();

        $administratorId = DB::table('roles')
            ->where('codigo', 'ADMINISTRADOR')
            ->value('id');
        $operatorId = DB::table('roles')
            ->where('codigo', 'OPERADOR')
            ->value('id');

        $this->assertNotNull($administratorId);
        $this->assertNotNull($operatorId);
        $this->assertSame(7, DB::table('metodos_pago')->count());
        $this->assertDatabaseCount('tipos_java', 3);
        $this->assertDatabaseHas('tipos_java', [
            'codigo' => 'JAVA_680',
            'nombre' => 'Java 6.80 kg',
            'peso_kg' => 6.800,
            'estado' => 'ACTIVO',
        ]);
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

        foreach ([$administratorId, $operatorId] as $roleId) {
            $this->assertSame(
                2,
                DB::table('rol_permisos')
                    ->where('rol_id', $roleId)
                    ->whereIn('permiso_id', DB::table('permisos')
                        ->whereIn('codigo', [
                            'PRODUCTOS_DESPACHO_GESTIONAR',
                            'PRODUCTOS_DESPACHO_DESPACHAR',
                        ])
                        ->select('id'))
                    ->count()
            );
        }
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
            database_path('migrations/2026_08_08_000001_add_cash_ledger_indexes_to_pagos_table.php'),
            database_path('migrations/2026_08_08_000002_add_cash_receipt_tracking_to_cobranzas_table.php'),
            database_path('migrations/2026_08_08_000003_add_pending_cash_receipt_lookup_index_to_cobranzas_table.php'),
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
        $this->assertFalse(Schema::hasColumn('cobranzas', 'recibido_en_caja'));

        $migrations->each(fn ($migration) => $migration->up());

        $this->assertTrue(Schema::hasTable('entidades_financieras'));
        $this->assertTrue(Schema::hasTable('cuentas_financieras'));
        $this->assertTrue(Schema::hasTable('metodos_pago'));
        $this->assertTrue(Schema::hasTable('costos_compra_pesadas'));
        $this->assertTrue(Schema::hasColumn('comprobantes', 'naturaleza'));
        $this->assertTrue(Schema::hasColumn('pagos', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('pago_aplicaciones', 'lado'));
        $this->assertTrue(Schema::hasColumn('cobranzas', 'recibido_en_caja'));
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

    public function test_retail_dispatch_configuration_schema_rolls_back_and_reapplies_on_sqlite(): void
    {
        $migration = require database_path(
            'migrations/2026_07_24_000001_create_configuraciones_despacho_minorista_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('configuraciones_despacho_minorista'));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('configuraciones_despacho_minorista', [
            'empresa_id',
            'sucursal_id',
            'estacion',
            'metodo_pago_id',
            'cuenta_destino_id',
        ]));
    }

    public function test_cash_register_movement_schema_rolls_back_and_reapplies_on_sqlite(): void
    {
        $migration = require database_path(
            'migrations/2026_07_31_000001_create_movimientos_caja_efectivo_table.php'
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('movimientos_caja_efectivo'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('movimientos_caja_efectivo', [
            'empresa_id',
            'pago_id',
            'caja_id',
            'direccion',
            'contraparte_tipo',
            'cliente_id',
            'otra_caja_id',
            'detalle',
        ]));
    }

    public function test_ticket_message_schema_rolls_back_and_reapplies_on_sqlite(): void
    {
        $user = User::factory()->create();
        DB::table('empresas')->where('id', $user->empresa_id)->update([
            'mensaje_ticket' => 'Mensaje existente',
        ]);
        $migration = require database_path(
            'migrations/2026_08_01_000001_add_ticket_message_to_empresas_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('empresas', 'mensaje_ticket'));
        $this->assertDatabaseHas('empresas', ['id' => $user->empresa_id]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('empresas', 'mensaje_ticket'));
        $this->assertNull(DB::table('empresas')
            ->where('id', $user->empresa_id)
            ->value('mensaje_ticket'));
    }

    public function test_ticket_title_schema_rolls_back_and_reapplies_with_its_default_on_sqlite(): void
    {
        $user = User::factory()->create();
        DB::table('empresas')->where('id', $user->empresa_id)->update([
            'titulo_ticket' => 'Titulo existente',
        ]);
        $migration = require database_path(
            'migrations/2026_08_15_000001_add_ticket_title_to_empresas_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('empresas', 'titulo_ticket'));
        $this->assertDatabaseHas('empresas', ['id' => $user->empresa_id]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('empresas', 'titulo_ticket'));
        $this->assertSame(
            'DISTRIBUIDORA DIEGO ALBERTO',
            DB::table('empresas')->where('id', $user->empresa_id)->value('titulo_ticket')
        );

        $newCompanyId = User::factory()->create()->empresa_id;
        $this->assertSame(
            'DISTRIBUIDORA DIEGO ALBERTO',
            DB::table('empresas')->where('id', $newCompanyId)->value('titulo_ticket')
        );
    }

    public function test_report_palette_schema_rolls_back_and_reapplies_on_sqlite(): void
    {
        $user = User::factory()->create();
        DB::table('empresas')->where('id', $user->empresa_id)->update([
            'paleta_reportes' => json_encode(['primary' => '#123456'], JSON_THROW_ON_ERROR),
        ]);
        $migration = require database_path(
            'migrations/2026_08_21_000001_add_report_palette_to_empresas_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('empresas', 'paleta_reportes'));
        $this->assertDatabaseHas('empresas', ['id' => $user->empresa_id]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('empresas', 'paleta_reportes'));
        $this->assertNull(DB::table('empresas')
            ->where('id', $user->empresa_id)
            ->value('paleta_reportes'));
    }

    public function test_java_680_migration_rolls_back_reapplies_and_is_idempotent(): void
    {
        $migration = require database_path(
            'migrations/2026_08_15_000002_add_java_680_to_tipos_java_table.php'
        );

        DB::table('tipos_java')->where('codigo', 'JAVA_680')->update([
            'nombre' => 'Java anterior',
            'peso_kg' => 6.750,
            'estado' => 'ACTIVO',
        ]);

        $migration->down();

        $this->assertDatabaseHas('tipos_java', [
            'codigo' => 'JAVA_680',
            'estado' => 'INACTIVO',
        ]);

        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('tipos_java', [
            'codigo' => 'JAVA_680',
            'nombre' => 'Java 6.80 kg',
            'peso_kg' => 6.800,
            'estado' => 'ACTIVO',
        ]);
        $this->assertSame(
            1,
            DB::table('tipos_java')->where('codigo', 'JAVA_680')->count()
        );
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

    public function test_wholesale_two_source_module_migration_rolls_back_and_reapplies_on_sqlite(): void
    {
        $migration = require database_path(
            'migrations/2026_08_14_000002_add_source_module_to_dispatch_tickets.php'
        );

        $this->assertTrue(Schema::hasColumn('tickets_despacho', 'modulo_origen'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('tickets_despacho', 'modulo_origen'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('tickets_despacho', 'modulo_origen'));
    }

    public function test_wholesale_two_weight_adjustment_migration_rolls_back_and_reapplies_on_sqlite(): void
    {
        $companyId = User::factory()->create()->empresa_id;
        $migration = require database_path(
            'migrations/2026_08_14_000003_create_wholesale_two_weight_adjustments.php'
        );

        $this->assertTrue(Schema::hasTable('ajustes_peso_mayorista_2'));
        $this->assertTrue(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_id'));
        $this->assertTrue(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_gramos'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('ajustes_peso_mayorista_2'));
        $this->assertFalse(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_id'));
        $this->assertFalse(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_gramos'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ajustes_peso_mayorista_2'));
        $this->assertTrue(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_id'));
        $this->assertTrue(Schema::hasColumn('pesadas', 'ajuste_peso_mayorista_2_gramos'));
        $this->assertSame(
            7,
            DB::table('ajustes_peso_mayorista_2')->where('empresa_id', $companyId)->count()
        );
    }

    public function test_wholesale_two_special_product_migration_rolls_back_and_reapplies_on_sqlite(): void
    {
        $migration = require database_path(
            'migrations/2026_08_15_000003_add_wholesale_two_special_products.php'
        );

        $this->assertEqualsCanonicalizing(
            ['GALLINA_ROJA', 'GALLINA_DOBLE', 'OTROS'],
            DB::table('tipos_pollo')
                ->whereIn('codigo', ['GALLINA_ROJA', 'GALLINA_DOBLE', 'OTROS'])
                ->pluck('codigo')
                ->all()
        );
        $this->assertTrue(
            collect(Schema::getColumns('ticket_precios'))
                ->keyBy('name')
                ->get('precio_historial_id')['nullable']
        );

        $migration->down();

        $this->assertDatabaseMissing('tipos_pollo', ['codigo' => 'GALLINA_ROJA']);
        $this->assertDatabaseMissing('tipos_pollo', ['codigo' => 'GALLINA_DOBLE']);
        $this->assertDatabaseMissing('tipos_pollo', ['codigo' => 'OTROS']);
        $this->assertFalse(
            collect(Schema::getColumns('ticket_precios'))
                ->keyBy('name')
                ->get('precio_historial_id')['nullable']
        );

        $migration->up();
        $migration->up();

        $this->assertSame(
            3,
            DB::table('tipos_pollo')
                ->whereIn('codigo', ['GALLINA_ROJA', 'GALLINA_DOBLE', 'OTROS'])
                ->count()
        );
        $this->assertTrue(
            collect(Schema::getColumns('ticket_precios'))
                ->keyBy('name')
                ->get('precio_historial_id')['nullable']
        );
    }

    public function test_wholesale_two_special_product_migration_refuses_partial_rollback_with_history(): void
    {
        $migration = require database_path(
            'migrations/2026_08_15_000003_add_wholesale_two_special_products.php'
        );
        $user = User::factory()->create();
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ROLLBACK-SPECIAL',
            'nombre' => 'Sucursal de prueba',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $branchId,
            'codigo' => 'ROLLBACK-SPECIAL',
            'nombre' => 'Almacén de prueba',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $specialTypeId = DB::table('tipos_pollo')
            ->where('codigo', 'GALLINA_ROJA')
            ->value('id');

        DB::table('existencias_almacen')->insert([
            'almacen_id' => $warehouseId,
            'tipo_pollo_id' => $specialTypeId,
            'cantidad_aves' => 0,
            'peso_neto_kg' => 0,
            'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('La reversión debía detenerse antes de modificar el esquema.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('movimientos o historiales', $exception->getMessage());
        }

        $this->assertDatabaseHas('tipos_pollo', ['codigo' => 'GALLINA_ROJA']);
        $this->assertTrue(
            collect(Schema::getColumns('ticket_precios'))
                ->keyBy('name')
                ->get('precio_historial_id')['nullable']
        );
    }
}
