<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table) {
            $table->string('tipo_operacion', 20)
                ->default('DESPACHO')
                ->after('canal');
            $table->index(['tipo_operacion', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table) {
            $table->dropIndex(['tipo_operacion', 'estado']);
            $table->dropColumn('tipo_operacion');
        });
    }
};
