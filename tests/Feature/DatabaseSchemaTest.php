<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_migration_has_one_schema_operation(): void
    {
        $migrationFiles = glob(database_path('migrations/*.php'));

        $this->assertCount(48, $migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $contents = file_get_contents($migrationFile);
            $upContents = explode('public function down', $contents, 2)[0];
            $schemaOperations = preg_match_all(
                "/Schema::(?:create|table)\\('([^']+)'/",
                $upContents
            );
            $expectedOperations = basename($migrationFile) === '2026_06_26_000004_add_tickets_dia_permission.php'
                ? 0
                : 1;

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
            'auditoria_eventos',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "No se creó la tabla {$table}.");
        }
    }

    public function test_core_tables_include_the_columns_required_by_the_domain(): void
    {
        $expectations = [
            'usuarios' => ['empresa_id', 'sucursal_id', 'nombre', 'email', 'password_hash', 'estado'],
            'tipos_pollo' => ['codigo', 'nombre', 'permite_despacho', 'precio_fuente_tipo_pollo_id', 'estado'],
            'precios_historial' => ['lista_precio_id', 'tipo_pollo_id', 'precio_kg', 'vigente_desde', 'vigente_hasta'],
            'programacion_recepcion_detalles' => ['programacion_id', 'proveedor_vehiculo_id', 'estado', 'hora_estimada'],
            'tickets_despacho' => ['jornada_id', 'codigo', 'referencia_externa', 'canal', 'tipo_operacion', 'cliente_destino_id', 'almacen_destino_id'],
            'pesadas' => ['ticket_id', 'tipo_pollo_id', 'condicion_pollo', 'tipo_java_id', 'peso_bruto_kg', 'tara_total_kg', 'peso_neto_kg'],
            'movimientos_inventario' => ['tipo', 'almacen_origen_id', 'almacen_destino_id', 'estado', 'fecha_hora'],
            'comprobantes' => ['operacion', 'codigo', 'origen_codigo', 'total', 'saldo_pendiente'],
            'auditoria_eventos' => ['usuario_id', 'entidad', 'entidad_id', 'accion', 'datos_antes', 'datos_despues'],
        ];

        foreach ($expectations as $table => $columns) {
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "La tabla {$table} no contiene todas las columnas requeridas."
            );
        }
    }
}
