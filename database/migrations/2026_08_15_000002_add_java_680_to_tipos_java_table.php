<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tipos_java')->updateOrInsert(
            ['codigo' => 'JAVA_680'],
            [
                'nombre' => 'Java 6.80 kg',
                'peso_kg' => 6.800,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('tipos_java')
            ->where('codigo', 'JAVA_680')
            ->update([
                'estado' => 'INACTIVO',
                'updated_at' => now(),
            ]);
    }
};
