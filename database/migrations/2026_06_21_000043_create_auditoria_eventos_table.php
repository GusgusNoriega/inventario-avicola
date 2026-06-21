<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('entidad', 80);
            $table->string('entidad_id', 80);
            $table->string('accion', 40);
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->string('direccion_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['empresa_id', 'entidad', 'entidad_id'], 'auditoria_empresa_entidad_id_index');
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_eventos');
    }
};
