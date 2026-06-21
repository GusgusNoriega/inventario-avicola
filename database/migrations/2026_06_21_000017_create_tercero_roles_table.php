<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tercero_roles', function (Blueprint $table) {
            $table->foreignId('tercero_id')->constrained('terceros')->cascadeOnDelete();
            $table->string('rol', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['tercero_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tercero_roles');
    }
};
