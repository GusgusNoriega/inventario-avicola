<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 100);
            $table->boolean('requiere_referencia')->default(false);
            $table->string('estado', 20)->default('ACTIVO')->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('metodos_pago')->insert([
            ['codigo' => 'DEPOSITO', 'nombre' => 'Deposito', 'requiere_referencia' => true, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'TRANSFERENCIA', 'nombre' => 'Transferencia', 'requiere_referencia' => true, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'EFECTIVO', 'nombre' => 'Efectivo', 'requiere_referencia' => false, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'YAPE', 'nombre' => 'Yape', 'requiere_referencia' => true, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'PLIN', 'nombre' => 'Plin', 'requiere_referencia' => true, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'CHEQUE', 'nombre' => 'Cheque', 'requiere_referencia' => true, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
            ['codigo' => 'OTRO', 'nombre' => 'Otro', 'requiere_referencia' => false, 'estado' => 'ACTIVO', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pago');
    }
};
