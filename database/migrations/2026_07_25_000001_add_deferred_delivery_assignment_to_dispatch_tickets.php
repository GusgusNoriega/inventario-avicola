<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->boolean('asignacion_transporte_posterior')
                ->default(false)
                ->after('conductor_entrega_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->dropColumn('asignacion_transporte_posterior');
        });
    }
};
