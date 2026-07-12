<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_peso_minorista', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre', 100);
            $table->string('sexo', 10);
            $table->string('presentacion', 20);
            $table->unsignedInteger('gramos_adicionales')->default(0);
            $table->boolean('predeterminado')->default(false);
            $table->string('estado', 20)->default('ACTIVO');
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'estado', 'predeterminado'], 'ajuste_minorista_empresa_estado_default_index');
        });

        $now = now();
        $definitions = [
            ['codigo' => 'MACHO_CERRADO', 'nombre' => 'Macho cerrado', 'sexo' => 'MACHO', 'presentacion' => 'CERRADO', 'predeterminado' => true],
            ['codigo' => 'MACHO_ABIERTO', 'nombre' => 'Macho abierto', 'sexo' => 'MACHO', 'presentacion' => 'ABIERTO', 'predeterminado' => false],
            ['codigo' => 'HEMBRA_CERRADA', 'nombre' => 'Hembra cerrada', 'sexo' => 'HEMBRA', 'presentacion' => 'CERRADA', 'predeterminado' => false],
            ['codigo' => 'HEMBRA_ABIERTA', 'nombre' => 'Hembra abierta', 'sexo' => 'HEMBRA', 'presentacion' => 'ABIERTA', 'predeterminado' => false],
        ];

        DB::table('empresas')->orderBy('id')->pluck('id')->each(function (int $companyId) use ($definitions, $now): void {
            DB::table('ajustes_peso_minorista')->insert(array_map(
                fn (array $definition): array => [
                    ...$definition,
                    'empresa_id' => $companyId,
                    'gramos_adicionales' => 0,
                    'estado' => 'ACTIVO',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $definitions
            ));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_peso_minorista');
    }
};
