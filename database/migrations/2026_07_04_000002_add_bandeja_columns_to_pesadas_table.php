<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->foreignId('tipo_java_id')->nullable()->change();
            $table->unsignedInteger('aves_por_java')->nullable()->change();
            $table->unsignedInteger('cantidad_javas')->nullable()->change();
            $table->decimal('peso_java_kg_snapshot', 12, 3)->nullable()->change();

            $table->foreignId('tipo_bandeja_id')
                ->nullable()
                ->after('tipo_java_id')
                ->constrained('tipos_bandeja')
                ->restrictOnDelete();
            $table->unsignedInteger('aves_por_bandeja')->nullable()->after('aves_por_java');
            $table->unsignedInteger('cantidad_bandejas')->nullable()->after('cantidad_javas');
            $table->decimal('peso_bandeja_kg_snapshot', 12, 3)
                ->nullable()
                ->after('peso_java_kg_snapshot');
            $table->index(['tipo_bandeja_id', 'pesada_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->dropIndex(['tipo_bandeja_id', 'pesada_at']);
            $table->dropConstrainedForeignId('tipo_bandeja_id');
            $table->dropColumn([
                'aves_por_bandeja',
                'cantidad_bandejas',
                'peso_bandeja_kg_snapshot',
            ]);

            $table->foreignId('tipo_java_id')->nullable(false)->change();
            $table->unsignedInteger('aves_por_java')->nullable(false)->change();
            $table->unsignedInteger('cantidad_javas')->nullable(false)->change();
            $table->decimal('peso_java_kg_snapshot', 12, 3)->nullable(false)->change();
        });
    }
};
