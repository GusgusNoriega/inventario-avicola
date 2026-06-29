<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->string('sexo', 10)
                ->default('MACHO')
                ->after('condicion_pollo');
            $table->index(['sexo', 'pesada_at']);
        });

        DB::table('pesadas')
            ->where('aves_por_java', 9)
            ->update(['sexo' => 'HEMBRA']);
    }

    public function down(): void
    {
        Schema::table('pesadas', function (Blueprint $table) {
            $table->dropIndex(['sexo', 'pesada_at']);
            $table->dropColumn('sexo');
        });
    }
};
