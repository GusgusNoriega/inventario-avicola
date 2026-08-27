<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventarios_javas', function (Blueprint $table): void {
            $table->integer('cantidad_total')->change();
            $table->integer('cantidad_total_bandejas')->nullable()->change();
        });

        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->integer('cantidad_esperada')->change();
            $table->integer('cantidad_esperada_bandejas')->nullable()->change();
            $table->integer('cantidad_total_inventario')->nullable()->change();
            $table->integer('cantidad_total_inventario_bandejas')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('inventarios_javas')
            ->where('cantidad_total', '<', 0)
            ->update(['cantidad_total' => 0]);
        DB::table('inventarios_javas')
            ->where('cantidad_total_bandejas', '<', 0)
            ->update(['cantidad_total_bandejas' => 0]);
        DB::table('conteos_diarios_javas')
            ->where('cantidad_esperada', '<', 0)
            ->update(['cantidad_esperada' => 0]);
        DB::table('conteos_diarios_javas')
            ->where('cantidad_esperada_bandejas', '<', 0)
            ->update(['cantidad_esperada_bandejas' => 0]);
        DB::table('conteos_diarios_javas')
            ->where('cantidad_total_inventario', '<', 0)
            ->update(['cantidad_total_inventario' => 0]);
        DB::table('conteos_diarios_javas')
            ->where('cantidad_total_inventario_bandejas', '<', 0)
            ->update(['cantidad_total_inventario_bandejas' => 0]);

        Schema::table('inventarios_javas', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_total')->change();
            $table->unsignedInteger('cantidad_total_bandejas')->nullable()->change();
        });

        Schema::table('conteos_diarios_javas', function (Blueprint $table): void {
            $table->unsignedInteger('cantidad_esperada')->change();
            $table->unsignedInteger('cantidad_esperada_bandejas')->nullable()->change();
            $table->unsignedInteger('cantidad_total_inventario')->nullable()->change();
            $table->unsignedInteger('cantidad_total_inventario_bandejas')->nullable()->change();
        });
    }
};
