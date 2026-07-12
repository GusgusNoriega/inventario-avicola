<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->string('sexo', 10)->nullable()->default(null)->change();
            $table->foreignId('ajuste_peso_minorista_id')
                ->nullable()
                ->after('tipo_bandeja_id')
                ->constrained('ajustes_peso_minorista')
                ->restrictOnDelete();
            $table->string('presentacion_pollo', 20)->nullable()->after('sexo');
            $table->unsignedInteger('ajuste_peso_gramos')->nullable()->after('peso_leido_kg');
            $table->index(
                ['ajuste_peso_minorista_id', 'pesada_at'],
                'pesada_ajuste_minorista_fecha_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->dropIndex('pesada_ajuste_minorista_fecha_index');
            $table->dropConstrainedForeignId('ajuste_peso_minorista_id');
            $table->dropColumn(['presentacion_pollo', 'ajuste_peso_gramos']);
            $table->string('sexo', 10)->nullable(false)->default('MACHO')->change();
        });
    }
};
