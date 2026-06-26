<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->string('condicion_pollo', 20)
                ->default('VIVO')
                ->after('tipo_pollo_id');
            $table->index(['condicion_pollo', 'pesada_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->dropIndex(['condicion_pollo', 'pesada_at']);
            $table->dropColumn('condicion_pollo');
        });
    }
};
