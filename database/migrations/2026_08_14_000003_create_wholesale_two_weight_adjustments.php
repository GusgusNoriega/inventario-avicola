<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_peso_mayorista_2', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre', 100);
            $table->string('sexo', 10)->nullable();
            $table->string('presentacion', 20)->nullable();
            $table->unsignedInteger('gramos_adicionales')->default(0);
            $table->string('estado', 20)->default('ACTIVO');
            $table->timestamps();
            $table->unique(
                ['empresa_id', 'codigo'],
                'ajuste_mayorista_2_empresa_codigo_unique'
            );
            $table->index(
                ['empresa_id', 'estado'],
                'ajuste_mayorista_2_empresa_estado_index'
            );
        });

        Schema::table('pesadas', function (Blueprint $table): void {
            $table->foreignId('ajuste_peso_mayorista_2_id')
                ->nullable()
                ->after('ajuste_peso_minorista_id')
                ->constrained('ajustes_peso_mayorista_2')
                ->restrictOnDelete();
            $table->unsignedInteger('ajuste_peso_mayorista_2_gramos')
                ->nullable()
                ->after('ajuste_peso_gramos');
            $table->index(
                ['ajuste_peso_mayorista_2_id', 'pesada_at'],
                'pesada_ajuste_mayorista_2_fecha_index'
            );
        });

        $now = now();
        $definitions = [
            ['codigo' => 'MACHO', 'nombre' => 'Macho vivo', 'sexo' => 'MACHO', 'presentacion' => null],
            ['codigo' => 'HEMBRA', 'nombre' => 'Hembra viva', 'sexo' => 'HEMBRA', 'presentacion' => null],
            ['codigo' => 'MACHO_ABIERTO', 'nombre' => 'Macho abierto', 'sexo' => 'MACHO', 'presentacion' => 'ABIERTO'],
            ['codigo' => 'MACHO_CERRADO', 'nombre' => 'Macho cerrado', 'sexo' => 'MACHO', 'presentacion' => 'CERRADO'],
            ['codigo' => 'HEMBRA_ABIERTA', 'nombre' => 'Hembra abierta', 'sexo' => 'HEMBRA', 'presentacion' => 'ABIERTA'],
            ['codigo' => 'HEMBRA_CERRADA', 'nombre' => 'Hembra cerrada', 'sexo' => 'HEMBRA', 'presentacion' => 'CERRADA'],
            ['codigo' => 'POLLO_BENEFICIADO', 'nombre' => 'Pollo beneficiado', 'sexo' => null, 'presentacion' => null],
        ];

        DB::table('empresas')->orderBy('id')->pluck('id')->each(
            function (int $companyId) use ($definitions, $now): void {
                DB::table('ajustes_peso_mayorista_2')->insert(array_map(
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
            }
        );
    }

    public function down(): void
    {
        Schema::table('pesadas', function (Blueprint $table): void {
            $table->dropIndex('pesada_ajuste_mayorista_2_fecha_index');
            $table->dropConstrainedForeignId('ajuste_peso_mayorista_2_id');
            $table->dropColumn('ajuste_peso_mayorista_2_gramos');
        });

        Schema::dropIfExists('ajustes_peso_mayorista_2');
    }
};
