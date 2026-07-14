<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            $table->dropUnique('compra_proveedor_documento_unique');
            $table->string('numero_documento_activo', 80)->nullable()->after('numero_documento');
            $table->unique(
                ['empresa_id', 'proveedor_id', 'tipo_documento', 'numero_documento_activo'],
                'compra_proveedor_documento_activo_unique'
            );
        });

        DB::table('compras')
            ->where('estado', '<>', 'ANULADA')
            ->whereNotNull('numero_documento')
            ->update(['numero_documento_activo' => DB::raw('numero_documento')]);
    }

    public function down(): void
    {
        $hasRepeatedDocuments = DB::table('compras')
            ->whereNotNull('numero_documento')
            ->select([
                'empresa_id',
                'proveedor_id',
                'tipo_documento',
                'numero_documento',
            ])
            ->groupBy([
                'empresa_id',
                'proveedor_id',
                'tipo_documento',
                'numero_documento',
            ])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedDocuments) {
            throw new RuntimeException(
                'No se puede revertir la migracion porque existen documentos reutilizados despues de una anulacion.'
            );
        }

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropUnique('compra_proveedor_documento_activo_unique');
            $table->dropColumn('numero_documento_activo');
            $table->unique(
                ['empresa_id', 'proveedor_id', 'tipo_documento', 'numero_documento'],
                'compra_proveedor_documento_unique'
            );
        });
    }
};
