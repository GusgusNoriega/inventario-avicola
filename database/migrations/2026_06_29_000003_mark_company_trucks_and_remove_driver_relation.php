<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conductor_habitual_id');
            $table->boolean('es_propio')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehiculos', function (Blueprint $table): void {
            $table->dropColumn('es_propio');
            $table->foreignId('conductor_habitual_id')
                ->nullable()
                ->constrained('conductores')
                ->nullOnDelete();
        });
    }
};
