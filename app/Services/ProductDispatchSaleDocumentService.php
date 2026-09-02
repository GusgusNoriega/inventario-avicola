<?php

namespace App\Services;

use App\Models\Comprobante;
use App\Models\ProductoDespacho;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchSaleDocumentService
{
    public function __construct(
        private readonly AccessAuditService $audit,
    ) {}

    public function create(
        int $companyId,
        TicketDespachoProducto $ticket,
        User $actor,
    ): int {
        return $this->sync($companyId, $ticket, $actor);
    }

    public function sync(
        int $companyId,
        TicketDespachoProducto $ticket,
        User $actor,
        ?string $ip = null,
        ?string $correctionReason = null,
    ): int {
        $ticket->loadMissing(['cliente', 'pesadas']);
        $originKey = "VENTA:TICKET_PRODUCTOS:{$ticket->id}";
        $existing = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', $originKey)
            ->lockForUpdate()
            ->first();

        if ($existing && $existing->estado === Comprobante::STATUS_VOIDED) {
            throw ValidationException::withMessages([
                'weighings' => 'El comprobante financiero asociado está anulado y no puede reactivarse mediante una corrección del ticket.',
            ]);
        }

        $applied = $existing
            ? FinancialMoney::subtract(
                FinancialMoney::normalize((string) $existing->total),
                FinancialMoney::normalize((string) $existing->saldo_pendiente),
            )
            : '0.00';
        $total = FinancialMoney::normalize((string) $ticket->total);

        if (FinancialMoney::compare($applied, '0.00') < 0) {
            throw ValidationException::withMessages([
                'weighings' => 'El comprobante asociado tiene un saldo financiero inconsistente y no puede corregirse.',
            ]);
        }

        if ($existing) {
            $documentClientId = $existing->tercero_id === null
                ? null
                : (int) $existing->tercero_id;
            $ticketClientId = $ticket->cliente_id === null
                ? null
                : (int) $ticket->cliente_id;

            if ($documentClientId !== $ticketClientId
                && FinancialMoney::compare($applied, '0.00') > 0) {
                throw ValidationException::withMessages([
                    'client_id' => 'No se puede cambiar el cliente porque el ticket ya tiene cobros aplicados. Anula primero los movimientos financieros relacionados.',
                ]);
            }
        }

        if (FinancialMoney::compare($total, $applied) < 0) {
            throw ValidationException::withMessages([
                'weighings' => "El nuevo total no puede ser menor que lo ya cobrado ({$applied} {$ticket->moneda}).",
            ]);
        }

        $balance = FinancialMoney::subtract($total, $applied);
        $status = match (true) {
            FinancialMoney::compare($balance, '0.00') === 0 => Comprobante::STATUS_PAID,
            FinancialMoney::compare($applied, '0.00') > 0 => Comprobante::STATUS_PARTIAL,
            default => Comprobante::STATUS_PENDING,
        };
        $now = now();
        $values = [
            'empresa_id' => $companyId,
            'tercero_id' => $ticket->cliente_id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => "VPD-{$ticket->id}",
            'origen_codigo' => 'AUTOMATICO',
            'origen_clave' => $originKey,
            'fecha_emision' => $ticket->fecha_operativa->format('Y-m-d'),
            'fecha_vencimiento' => $ticket->fecha_operativa->format('Y-m-d'),
            'moneda' => $ticket->moneda,
            'subtotal' => $total,
            'impuesto' => '0.00',
            'total' => $total,
            'saldo_pendiente' => $balance,
            'estado' => $status,
            'contraparte_tipo_documento_snapshot' => $ticket->cliente_tipo_documento_snapshot,
            'contraparte_numero_documento_snapshot' => $ticket->cliente_numero_documento_snapshot,
            'contraparte_nombre_snapshot' => $ticket->cliente_nombre_snapshot,
            'contraparte_direccion_snapshot' => $existing
                && (int) $existing->tercero_id === (int) $ticket->cliente_id
                    ? $existing->contraparte_direccion_snapshot
                    : $ticket->cliente?->direccion,
            'anulada_por' => null,
            'anulada_at' => null,
            'motivo_anulacion' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('comprobantes')->where('id', $existing->id)->update($values);
            $documentId = (int) $existing->id;
            $action = 'REVALORIZAR';
        } else {
            $documentId = (int) DB::table('comprobantes')->insertGetId([
                ...$values,
                'created_by' => $actor->id,
                'created_at' => $now,
            ]);
            $action = 'GENERAR';
        }

        $this->syncDetails($documentId, $ticket, $now);
        DB::table('comprobante_tickets_despacho_productos')->updateOrInsert(
            [
                'comprobante_id' => $documentId,
                'ticket_despacho_producto_id' => $ticket->id,
            ],
            ['importe_aplicado' => $total],
        );

        $after = [
            ...$values,
            'ticket_id' => (int) $ticket->id,
            'ticket_codigo' => (string) $ticket->codigo,
        ];
        if (filled($correctionReason)) {
            $after['correction_reason'] = trim((string) $correctionReason);
        }
        $this->audit->record(
            $companyId,
            (int) $actor->id,
            'comprobantes',
            $documentId,
            $action,
            $existing ? (array) $existing : null,
            $after,
            $ip,
        );

        return $documentId;
    }

    private function syncDetails(
        int $documentId,
        TicketDespachoProducto $ticket,
        \DateTimeInterface $createdAt,
    ): void {
        DB::table('comprobante_detalles')
            ->where('comprobante_id', $documentId)
            ->delete();

        if ($ticket->pesadas->isEmpty()) {
            return;
        }

        DB::table('comprobante_detalles')->insert(
            $ticket->pesadas->map(function ($weighing) use ($documentId, $createdAt): array {
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
                    'created_at' => $createdAt,
                ];
            })->all(),
        );
    }
}
