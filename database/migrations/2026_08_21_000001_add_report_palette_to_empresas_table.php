<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->json('paleta_reportes')->nullable()->after('titulo_ticket');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn('paleta_reportes');
        });
    }
};
