<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentDataCleanupSeeder extends Seeder
{
    /**
     * Tables are ordered from children to parents so the cleanup remains
     * compatible with MySQL and SQLite without disabling foreign keys.
     *
     * @var list<string>
     */
    public const TABLES_TO_CLEAN = [
        'compra_detalles',
        'compras',
        'pago_aplicacion_operaciones',
        'pago_aplicaciones',
        'pagos',
        'comprobante_tickets',
        'comprobante_pesadas',
        'comprobante_detalles',
        'comprobantes',
        'costos_compra_pesadas',
        'movimiento_detalles',
        'movimientos_inventario',
        'movimientos_javas',
        'conteos_diarios_javas_camiones',
        'conteos_diarios_javas',
        'inventarios_javas',
        'existencias_almacen',
        'ticket_precios',
        'pesadas',
        'lecturas_balanza',
        'tickets_despacho',
        'programacion_recepcion_almacenes',
        'programacion_recepcion_detalles',
        'programaciones_recepcion',
        'jornadas_operativas',
        'precios_historial',
        'listas_precios',
        'auditoria_eventos',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'cache_locks',
        'cache',
        'failed_jobs',
        'job_batches',
        'jobs',
    ];

    /**
     * Explicit inventory of tables that are intentionally not modified.
     * It includes the requested master data and the minimum infrastructure
     * required to keep the application usable after the cleanup.
     *
     * @var list<string>
     */
    public const PRESERVED_TABLES = [
        'migrations',
        'empresas',
        'sucursales',
        'usuarios',
        'roles',
        'permisos',
        'usuario_roles',
        'rol_permisos',
        'terceros',
        'tercero_roles',
        'almacenes',
        'tipos_pollo',
        'tipos_java',
        'balanzas',
        'conductores',
        'vehiculos',
        'proveedor_vehiculos',
        'tipos_bandeja',
        'ajustes_peso_minorista',
        'configuraciones_despacho_minorista',
        'metodos_pago',
        'entidades_financieras',
        'cuentas_financieras',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'La limpieza de datos de prueba solo puede ejecutarse en los entornos local o testing.'
            );
        }

        if (app()->environment('local')
            && $this->command
            && ! $this->command->confirm(
                'Se eliminarán compras, pagos, despachos, pesadas, jornadas, inventarios, precios, sesiones, caché y colas. Se conservarán empresas, cuentas financieras y usuarios. ¿Deseas continuar?',
                false
            )) {
            $this->command->warn('Limpieza cancelada. No se modificó ningún registro.');

            return;
        }

        $deletedByTable = DB::transaction(function (): array {
            // Both columns have self-referencing RESTRICT foreign keys.
            DB::table('pagos')->update(['reversa_de_pago_id' => null]);
            DB::table('precios_historial')->update(['reemplaza_precio_id' => null]);

            $deleted = [];
            foreach (self::TABLES_TO_CLEAN as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            return $deleted;
        }, 3);

        $this->command?->info(sprintf(
            'Limpieza finalizada: %d registros de prueba eliminados.',
            array_sum($deletedByTable)
        ));
        $this->command?->line(
            'Se conservaron empresas, cuentas financieras, usuarios, clientes, proveedores, camiones, choferes, asignaciones y configuración técnica.'
        );
    }
}
