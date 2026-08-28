<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->recoverInterruptedTableCreation();

        Schema::create('tickets_despacho_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas', 'id', 'fk_tdp_empresa')
                ->restrictOnDelete();
            $table->foreignId('sucursal_id')
                ->constrained('sucursales', 'id', 'fk_tdp_sucursal')
                ->restrictOnDelete();
            $table->uuid('referencia_externa');
            $table->string('codigo', 50);
            $table->date('fecha_operativa');
            $table->foreignId('cliente_id')
                ->nullable()
                ->constrained('terceros', 'id', 'fk_tdp_cliente')
                ->restrictOnDelete();
            $table->string('tipo_cliente', 30);
            $table->string('cliente_tipo_documento_snapshot', 20)->nullable();
            $table->string('cliente_numero_documento_snapshot', 40)->nullable();
            $table->string('cliente_nombre_snapshot', 180);
            $table->char('moneda', 3)->default('PEN');
            $table->unsignedInteger('cantidad_total');
            $table->decimal('peso_leido_total_kg', 12, 3);
            $table->unsignedBigInteger('merma_total_gramos');
            $table->decimal('peso_neto_total_kg', 12, 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('estado', 20)->default('REGISTRADO');
            $table->timestamp('registrado_at');
            $table->foreignId('created_by')
                ->constrained('usuarios', 'id', 'fk_tdp_created_by')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'referencia_externa'],
                'ticket_producto_empresa_referencia_unique',
            );
            $table->unique(['empresa_id', 'codigo'], 'ticket_producto_empresa_codigo_unique');
            $table->index(
                ['sucursal_id', 'fecha_operativa', 'estado'],
                'ticket_producto_sucursal_fecha_index',
            );
        });

        Schema::create('pesadas_despacho_productos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_despacho_producto_id')
                ->constrained('tickets_despacho_productos', 'id', 'fk_pdp_ticket')
                ->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->foreignId('producto_despacho_id')
                ->constrained('productos_despacho', 'id', 'fk_pdp_producto')
                ->restrictOnDelete();
            $table->foreignId('variacion_producto_despacho_id')
                ->nullable()
                ->constrained('variaciones_producto_despacho', 'id', 'fk_pdp_variacion')
                ->restrictOnDelete();
            $table->foreignId('lectura_balanza_id')
                ->nullable()
                ->unique('pesada_producto_lectura_unique')
                ->constrained('lecturas_balanza', 'id', 'fk_pdp_lectura')
                ->nullOnDelete();
            $table->string('producto_nombre_snapshot', 120);
            $table->string('variacion_nombre_snapshot', 120)->nullable();
            $table->string('modo_precio_snapshot', 20);
            $table->decimal('precio_catalogo_snapshot', 14, 4);
            $table->decimal('precio_venta_snapshot', 14, 4);
            $table->string('origen_precio', 20);
            $table->unsignedInteger('cantidad');
            $table->string('origen_peso', 40);
            $table->decimal('peso_leido_kg', 12, 3);
            $table->unsignedInteger('merma_catalogo_gramos_unidad');
            $table->unsignedBigInteger('merma_total_gramos');
            $table->decimal('peso_neto_kg', 12, 3);
            $table->decimal('importe', 14, 2);
            $table->timestamp('pesada_at');
            $table->foreignId('created_by')
                ->constrained('usuarios', 'id', 'fk_pdp_created_by')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['ticket_despacho_producto_id', 'numero'],
                'pesada_producto_ticket_numero_unique',
            );
            $table->index(
                ['producto_despacho_id', 'variacion_producto_despacho_id'],
                'pesada_producto_catalogo_index',
            );
        });

        Schema::table('comprobante_detalles', function (Blueprint $table): void {
            $table->decimal('precio_kg', 14, 4)->nullable()->change();
            $table->foreignId('producto_despacho_id')
                ->nullable()
                ->after('tipo_pollo_id')
                ->constrained('productos_despacho', 'id', 'fk_cd_producto_despacho')
                ->restrictOnDelete();
            $table->foreignId('variacion_producto_despacho_id')
                ->nullable()
                ->after('producto_despacho_id')
                ->constrained('variaciones_producto_despacho', 'id', 'fk_cd_variacion_producto')
                ->restrictOnDelete();
            $table->unsignedInteger('cantidad_unidades')->nullable()->after('cantidad_aves');
            $table->string('modo_precio', 20)->nullable()->after('peso_neto_kg');
            $table->decimal('precio_unitario', 14, 4)->nullable()->after('precio_kg');
        });

        Schema::create('comprobante_tickets_despacho_productos', function (Blueprint $table): void {
            $table->foreignId('comprobante_id')
                ->constrained('comprobantes', 'id', 'fk_ctdp_comprobante')
                ->cascadeOnDelete();
            $table->foreignId('ticket_despacho_producto_id')
                ->constrained('tickets_despacho_productos', 'id', 'fk_ctdp_ticket')
                ->cascadeOnDelete();
            $table->decimal('importe_aplicado', 14, 2);
            $table->primary(
                ['comprobante_id', 'ticket_despacho_producto_id'],
                'comprobante_ticket_producto_primary',
            );
        });

        $this->registerTechnicalPermissions();
    }

    private function recoverInterruptedTableCreation(): void
    {
        $ticketsTableExists = Schema::hasTable('tickets_despacho_productos');
        $weighingsTableExists = Schema::hasTable('pesadas_despacho_productos');

        if (! $ticketsTableExists && ! $weighingsTableExists) {
            return;
        }

        $laterChangesExist = Schema::hasTable('comprobante_tickets_despacho_productos')
            || Schema::hasColumn('comprobante_detalles', 'producto_despacho_id')
            || Schema::hasColumn('comprobante_detalles', 'variacion_producto_despacho_id');

        if ($laterChangesExist) {
            throw new RuntimeException(
                'La migración de despacho de productos quedó incompleta después de modificar comprobantes. Revise el esquema antes de reintentarla.',
            );
        }

        foreach (array_filter([
            $ticketsTableExists ? 'tickets_despacho_productos' : null,
            $weighingsTableExists ? 'pesadas_despacho_productos' : null,
        ]) as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    "La tabla {$table} pertenece a una migración incompleta, pero contiene datos. No se eliminará automáticamente.",
                );
            }
        }

        Schema::dropIfExists('pesadas_despacho_productos');
        Schema::dropIfExists('tickets_despacho_productos');
    }

    public function down(): void
    {
        $this->removeTechnicalPermissions();

        Schema::dropIfExists('comprobante_tickets_despacho_productos');

        $usesSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('comprobante_detalles', function (Blueprint $table) use ($usesSqlite): void {
            $table->dropForeign(
                $usesSqlite ? ['variacion_producto_despacho_id'] : 'fk_cd_variacion_producto',
            );
            $table->dropForeign(
                $usesSqlite ? ['producto_despacho_id'] : 'fk_cd_producto_despacho',
            );
            $table->dropColumn([
                'variacion_producto_despacho_id',
                'producto_despacho_id',
                'cantidad_unidades',
                'modo_precio',
                'precio_unitario',
            ]);
            $table->decimal('precio_kg', 12, 4)->nullable()->change();
        });

        Schema::dropIfExists('pesadas_despacho_productos');
        Schema::dropIfExists('tickets_despacho_productos');
    }

    private function registerTechnicalPermissions(): void
    {
        $now = now();
        $modulePermissionId = DB::table('permisos')
            ->where('codigo', 'MODULO_DESPACHO_PRODUCTOS')
            ->value('id');
        $roleIds = $modulePermissionId
            ? DB::table('rol_permisos')->where('permiso_id', $modulePermissionId)->pluck('rol_id')
            : collect();

        foreach ([
            'PRODUCTOS_DESPACHO_GESTIONAR' => 'Administrar el catálogo de productos para despacho',
            'PRODUCTOS_DESPACHO_DESPACHAR' => 'Registrar despachos de productos',
        ] as $code => $description) {
            DB::table('permisos')->updateOrInsert(
                ['codigo' => $code],
                [
                    'descripcion' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $permissionId = DB::table('permisos')->where('codigo', $code)->value('id');
            foreach ($roleIds as $roleId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $roleId,
                    'permiso_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function removeTechnicalPermissions(): void
    {
        $permissionIds = DB::table('permisos')
            ->whereIn('codigo', [
                'PRODUCTOS_DESPACHO_GESTIONAR',
                'PRODUCTOS_DESPACHO_DESPACHAR',
            ])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('rol_permisos')->whereIn('permiso_id', $permissionIds)->delete();
            DB::table('permisos')->whereIn('id', $permissionIds)->delete();
        }
    }
};
