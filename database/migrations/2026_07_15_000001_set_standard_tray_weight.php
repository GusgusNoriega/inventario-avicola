<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tipos_bandeja')
            ->where('codigo', 'BANDEJA_ESTANDAR')
            ->update([
                'peso_kg' => 2.500,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('tipos_bandeja')
            ->where('codigo', 'BANDEJA_ESTANDAR')
            ->update([
                'peso_kg' => 0,
                'updated_at' => now(),
            ]);
    }
};
