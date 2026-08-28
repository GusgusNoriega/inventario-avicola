<?php

namespace App\Services;

use App\Models\ProductoDespacho;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductDispatchSaleDocumentService
{
    public function create(
        int $companyId,
        TicketDespachoProducto $ticket,
        User $actor,
    ): int {
        $originKey = "VENTA:TICKET_PRODUCTOS:{$ticket->id}";
        $existingId = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', $originKey)
            ->lockForUpdate()
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        $now = now();
        $documentId = (int) DB::table('comprobantes')->insertGetId([
            'empresa_id' => $companyId,
            'tercero_id' => $ticket->cliente_id,
            'operacion' => 'VENTA',
            'naturaleza' => 'CARGO',
            'tipo_documento' => 'INTERNO',
            'codigo' => "VPD-{$ticket->id}",
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => $originKey,
            'fecha_emision' => $ticket->fecha_operativa->format('Y-m-d'),
            'fecha_vencimiento' => $ticket->fecha_operativa->format('Y-m-d'),
            'moneda' => $ticket->moneda,
            'subtotal' => $ticket->subtotal,
            'impuesto' => '0.00',
            'total' => $ticket->total,
            'saldo_pendiente' => $ticket->total,
            'estado' => 'PENDIENTE',
            'contraparte_tipo_documento_snapshot' => $ticket->cliente_tipo_documento_snapshot,
            'contraparte_numero_documento_snapshot' => $ticket->cliente_numero_documento_snapshot,
            'contraparte_nombre_snapshot' => $ticket->cliente_nombre_snapshot,
            'contraparte_direccion_snapshot' => $ticket->cliente?->direccion,
            'created_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('comprobante_detalles')->insert(
            $ticket->pesadas->map(function ($weighing) use ($documentId, $now): array {
                $description = $weighing->variacion_nombre_snapshot
                    ? "{$weighing->producto_nombre_snapshot} · {$weighing->variacion_nombre_snapshot}"
                    : $weighing->producto_nombre_snapshot;

                return [
                    'comprobante_id' => $documentId,
                    'tipo_pollo_id' => null,
                    'producto_despacho_id' => $weighing->producto_despacho_id,
                    'variacion_producto_despacho_id' => $weighing->variacion_producto_despacho_id,
                    'descripcion' => $description,
                    'cantidad_aves' => null,
                    'cantidad_unidades' => $weighing->cantidad,
                    'peso_neto_kg' => $weighing->peso_neto_kg,
                    'modo_precio' => $weighing->modo_precio_snapshot,
                    'precio_kg' => $weighing->modo_precio_snapshot === ProductoDespacho::PRICE_MODE_KG
                        ? $weighing->precio_venta_snapshot
                        : null,
                    'precio_unitario' => $weighing->modo_precio_snapshot === ProductoDespacho::PRICE_MODE_UNIT
                        ? $weighing->precio_venta_snapshot
                        : null,
                    'subtotal' => $weighing->importe,
                    'created_at' => $now,
                ];
            })->all(),
        );

        DB::table('comprobante_tickets_despacho_productos')->insert([
            'comprobante_id' => $documentId,
            'ticket_despacho_producto_id' => $ticket->id,
            'importe_aplicado' => $ticket->total,
        ]);

        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actor->id,
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $documentId,
            'accion' => 'GENERAR',
            'datos_despues' => json_encode([
                'origen' => 'DESPACHO_PRODUCTOS',
                'ticket_id' => $ticket->id,
                'ticket_codigo' => $ticket->codigo,
                'total' => $ticket->total,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return $documentId;
    }
}
