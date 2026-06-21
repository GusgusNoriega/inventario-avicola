<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('tercero_id')->constrained('terceros')->restrictOnDelete();
            $table->string('direccion', 20);
            $table->timestamp('fecha_hora');
            $table->string('metodo', 40);
            $table->string('referencia', 100)->nullable();
            $table->char('moneda', 3)->default('PEN');
            $table->decimal('importe', 14, 2);
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tercero_id', 'fecha_hora']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
