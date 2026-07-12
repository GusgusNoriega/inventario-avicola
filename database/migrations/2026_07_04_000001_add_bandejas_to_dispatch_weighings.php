<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_bandeja', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->decimal('peso_kg', 12, 3)->default(0);
            $table->unsignedInteger('capacidad_aves')->nullable();
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
        });

        DB::table('tipos_bandeja')->insert([
            [
                'codigo' => 'BANDEJA_ESTANDAR',
                'nombre' => 'Bandeja estandar',
                'peso_kg' => 0,
                'capacidad_aves' => 5,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_bandeja');
    }
};
