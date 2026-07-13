<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->foreignId('tercero_id')->nullable()->change();
            $table->string('naturaleza', 20)->default('CARGO')->after('operacion');
            $table->string('origen_clave', 120)->nullable()->after('origen_codigo');
            $table->string('contraparte_tipo_documento_snapshot', 20)->nullable();
            $table->string('contraparte_numero_documento_snapshot', 30)->nullable();
            $table->string('contraparte_nombre_snapshot', 180)->nullable();
            $table->string('contraparte_direccion_snapshot', 250)->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion', 250)->nullable();

            $table->unique(
                ['empresa_id', 'origen_clave'],
                'comprobante_empresa_origen_clave_unique'
            );
            $table->index(
                ['empresa_id', 'operacion', 'estado', 'fecha_vencimiento'],
                'comprobante_cartera_index'
            );
        });
    }

    public function down(): void
    {
        // Los comprobantes anónimos nacieron con este módulo y no caben en el
        // esquema anterior, donde tercero_id era obligatorio.
        $anonymousDocumentIds = DB::table('comprobantes')
            ->whereNull('tercero_id')
            ->pluck('id');
        if ($anonymousDocumentIds->isNotEmpty()) {
            DB::table('pago_aplicaciones')
                ->whereIn('comprobante_id', $anonymousDocumentIds)
                ->delete();
            DB::table('comprobantes')
                ->whereIn('id', $anonymousDocumentIds)
                ->delete();
        }

        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropIndex('comprobante_cartera_index');
            $table->dropUnique('comprobante_empresa_origen_clave_unique');
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropColumn([
                'naturaleza',
                'origen_clave',
                'contraparte_tipo_documento_snapshot',
                'contraparte_numero_documento_snapshot',
                'contraparte_nombre_snapshot',
                'contraparte_direccion_snapshot',
                'anulada_at',
                'motivo_anulacion',
            ]);
            $table->foreignId('tercero_id')->nullable(false)->change();
        });
    }
};
