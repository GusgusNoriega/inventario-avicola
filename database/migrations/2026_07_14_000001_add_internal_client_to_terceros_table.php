<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros', function (Blueprint $table): void {
            $table->boolean('es_cliente_interno')
                ->default(false)
                ->after('direccion')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('terceros', function (Blueprint $table): void {
            $table->dropIndex(['es_cliente_interno']);
            $table->dropColumn('es_cliente_interno');
        });
    }
};
