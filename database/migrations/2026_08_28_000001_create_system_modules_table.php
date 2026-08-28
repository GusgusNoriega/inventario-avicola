<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('modulos_sistema')) {
            return;
        }

        Schema::create('modulos_sistema', function (Blueprint $table): void {
            $table->string('codigo', 100)->primary();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $now = now();
        $rows = collect(array_keys(config('access_modules.modules', [])))
            ->map(fn (string $code): array => [
                'codigo' => $code,
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('modulos_sistema')->insert($rows);
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('modulos_sistema');
    }
};
