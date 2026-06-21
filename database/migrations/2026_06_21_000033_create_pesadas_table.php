<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_despacho')->restrictOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('tipo_pollo_id')->constrained('tipos_pollo')->restrictOnDelete();
            $table->foreignId('tipo_java_id')->constrained('tipos_java')->restrictOnDelete();
            $table->foreignId('lectura_balanza_id')->nullable()->unique()->constrained('lecturas_balanza')->nullOnDelete();
            $table->foreignId('proveedor_origen_id')->nullable()->constrained('terceros')->restrictOnDelete();
            $table->foreignId('almacen_origen_id')->nullable()->constrained('almacenes')->restrictOnDelete();
            $table->foreignId('vehiculo_id')->nullable()->constrained('vehiculos')->nullOnDelete();
            $table->foreignId('programacion_recepcion_detalle_id')->nullable()->constrained('programacion_recepcion_detalles')->nullOnDelete();
            $table->string('placa_snapshot', 20)->nullable();
            $table->string('origen_peso', 20);
            $table->unsignedInteger('aves_por_java');
            $table->unsignedInteger('cantidad_javas');
            $table->unsignedInteger('cantidad_aves');
            $table->decimal('peso_java_kg_snapshot', 12, 3);
            $table->decimal('peso_leido_kg', 12, 3);
            $table->decimal('peso_bruto_kg', 12, 3);
            $table->decimal('tara_total_kg', 12, 3);
            $table->decimal('peso_neto_kg', 12, 3);
            $table->timestamp('pesada_at');
            $table->string('estado', 20)->default('ACTIVA');
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();
            $table->foreignId('created_by')->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['ticket_id', 'numero']);
            $table->index(['programacion_recepcion_detalle_id', 'pesada_at'], 'pesada_programacion_fecha_index');
            $table->index(['proveedor_origen_id', 'pesada_at']);
            $table->index(['almacen_origen_id', 'pesada_at']);
            $table->index(['tipo_pollo_id', 'pesada_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesadas');
    }
};
