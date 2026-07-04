<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventarios_javas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('empresas')->restrictOnDelete();
            $table->unsignedInteger('cantidad_total');
            $table->foreignId('updated_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventarios_javas');
    }
};
