<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE_CODE = 'MODULO_DESPACHO_PRODUCTOS';

    public function up(): void
    {
        Schema::create('productos_despacho', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('nombre_normalizado', 120);
            $table->string('descripcion', 500)->nullable();
            $table->string('modo_precio', 20)->default('POR_KG');
            $table->decimal('precio_venta', 14, 4);
            $table->unsignedInteger('merma_gramos_unidad')->default(0);
            $table->string('imagen_path')->nullable();
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre_normalizado'], 'producto_despacho_empresa_nombre_unique');
            $table->index(['empresa_id', 'estado', 'nombre'], 'producto_despacho_empresa_estado_index');
        });

        Schema::create('variaciones_producto_despacho', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto_despacho_id')
                ->constrained('productos_despacho')
                ->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('nombre_normalizado', 120);
            $table->string('modo_precio', 20)->default('POR_KG');
            $table->decimal('precio_venta', 14, 4);
            $table->unsignedInteger('merma_gramos_unidad')->default(0);
            $table->string('imagen_path')->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 20)->default('ACTIVO');
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['producto_despacho_id', 'nombre_normalizado'],
                'variacion_producto_nombre_unique',
            );
            $table->index(
                ['producto_despacho_id', 'estado', 'orden'],
                'variacion_producto_estado_orden_index',
            );
        });

        $this->registerModule();
    }

    public function down(): void
    {
        Schema::dropIfExists('variaciones_producto_despacho');
        Schema::dropIfExists('productos_despacho');

        $permissionId = DB::table('permisos')
            ->where('codigo', self::MODULE_CODE)
            ->value('id');

        if ($permissionId) {
            DB::table('rol_permisos')->where('permiso_id', $permissionId)->delete();
            DB::table('permisos')->where('id', $permissionId)->delete();
        }

        if (Schema::hasTable('modulos_sistema')) {
            DB::table('modulos_sistema')->where('codigo', self::MODULE_CODE)->delete();
        }
    }

    private function registerModule(): void
    {
        $now = now();

        DB::table('permisos')->updateOrInsert(
            ['codigo' => self::MODULE_CODE],
            [
                'descripcion' => 'Despacho de productos',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        if (Schema::hasTable('modulos_sistema')) {
            DB::table('modulos_sistema')->updateOrInsert(
                ['codigo' => self::MODULE_CODE],
                [
                    'activo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionId = DB::table('permisos')
            ->where('codigo', self::MODULE_CODE)
            ->value('id');
        $roleIds = DB::table('roles')
            ->whereIn('codigo', ['ADMINISTRADOR', 'OPERADOR'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
