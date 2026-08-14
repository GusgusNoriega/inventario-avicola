<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->string('modulo_origen', 80)
                ->nullable()
                ->after('canal')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_despacho', function (Blueprint $table): void {
            $table->dropIndex(['modulo_origen']);
            $table->dropColumn('modulo_origen');
        });
    }
};
