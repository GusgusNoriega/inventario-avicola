<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->index(
                ['empresa_id', 'cliente_id'],
                'movimientos_javas_empresa_cliente_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_javas', function (Blueprint $table): void {
            $table->dropIndex('movimientos_javas_empresa_cliente_index');
        });
    }
};
