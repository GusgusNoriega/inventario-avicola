<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_sync_financial_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->unique()->constrained('reception_sync_records')->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained('comprobantes')->restrictOnDelete();
            $table->foreignId('price_history_id')->nullable()->constrained('precios_historial')->restrictOnDelete();
            $table->foreignId('chicken_type_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('terceros')->restrictOnDelete();
            $table->decimal('price_kg', 14, 4);
            $table->string('price_source', 20);
            $table->timestamp('priced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_sync_financial_links');
    }
};
