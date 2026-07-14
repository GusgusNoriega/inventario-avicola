<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('comprobantes')
            ->where('operacion', 'COMPRA')
            ->where('origen_clave', 'like', 'COMPRA:TICKET:%')
            ->whereNotNull('tercero_id')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                DB::transaction(function () use ($documents): void {
                    $documents->each(function (object $document): void {
                        if (DB::table('compras')->where('comprobante_id', $document->id)->exists()) {
                            return;
                        }

                        $purchaseId = DB::table('compras')->insertGetId([
                            'empresa_id' => $document->empresa_id,
                            'proveedor_id' => $document->tercero_id,
                            'comprobante_id' => $document->id,
                            'pago_inicial_id' => null,
                            'codigo' => 'LEG-CXP-'.str_pad((string) $document->id, 10, '0', STR_PAD_LEFT),
                            'idempotency_key' => (string) Str::uuid(),
                            'tipo_documento' => $document->tipo_documento ?: 'INTERNO',
                            'numero_documento' => null,
                            'fecha_compra' => $document->fecha_emision,
                            'fecha_vencimiento' => $document->fecha_vencimiento,
                            'condicion' => 'LEGADO',
                            'moneda' => $document->moneda,
                            'subtotal' => $document->subtotal,
                            'impuesto' => $document->impuesto,
                            'total' => $document->total,
                            'estado' => $document->estado === 'ANULADO' ? 'ANULADA' : 'REGISTRADA',
                            'observaciones' => 'Importada del historial financiero originado por despacho. La condicion de pago original no esta disponible.',
                            'created_by' => $document->created_by,
                            'anulada_por' => $document->anulada_por,
                            'anulada_at' => $document->anulada_at,
                            'motivo_anulacion' => $document->motivo_anulacion,
                            'created_at' => $document->created_at,
                            'updated_at' => $document->updated_at,
                        ]);

                        DB::table('comprobante_detalles')
                            ->where('comprobante_id', $document->id)
                            ->orderBy('id')
                            ->get()
                            ->each(function (object $detail) use ($purchaseId): void {
                                DB::table('compra_detalles')->insert([
                                    'compra_id' => $purchaseId,
                                    'tipo_pollo_id' => $detail->tipo_pollo_id,
                                    'descripcion' => $detail->descripcion,
                                    'cantidad_aves' => $detail->cantidad_aves,
                                    'peso_kg' => $detail->peso_neto_kg ?? 0,
                                    'precio_kg' => $detail->precio_kg ?? 0,
                                    'subtotal' => $detail->subtotal,
                                    'created_at' => $detail->created_at ?? now(),
                                ]);
                            });
                    });
                });
            });
    }

    public function down(): void
    {
        DB::table('compras')
            ->where('condicion', 'LEGADO')
            ->where('codigo', 'like', 'LEG-CXP-%')
            ->delete();
    }
};
