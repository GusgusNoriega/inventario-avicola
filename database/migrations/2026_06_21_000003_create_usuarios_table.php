<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('nombre', 150);
            $table->string('email', 180);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash');
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamp('ultimo_acceso_at')->nullable();
            $table->string('ultimo_acceso_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['empresa_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
