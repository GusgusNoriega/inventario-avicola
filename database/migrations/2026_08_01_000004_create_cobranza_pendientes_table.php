<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobranza_pendientes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cobranza_id')->unique()->constrained('cobranzas')->restrictOnDelete();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->decimal('importe', 14, 2);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranza_pendientes');
    }
};
