<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table) {
            $table->uuid('referencia_externa')
                ->nullable()
                ->unique()
                ->after('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table) {
            $table->dropUnique(['referencia_externa']);
            $table->dropColumn('referencia_externa');
        });
    }
};
