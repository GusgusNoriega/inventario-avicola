<?php

namespace App\Services;

use App\Models\ListaPrecio;
use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialObligationService
{
    /**
     * Genera o sincroniza los documentos internos de venta y compra de un ticket.
     *
     * Este método es idempotente por `origen_clave` y debe ejecutarse dentro de
     * la misma transacción que cierra el ticket.
     *
     * @return array{sale_document_id: ?int, purchase_document_ids: array<int, int>, pending_purchase_costs: int}
     */
    public function syncTicket(int $companyId, TicketDespacho $ticket, User $actor): array
    {
        return DB::transaction(function () use ($companyId, $ticket, $actor): array {
            DB::table('tickets_despacho')->where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            $ticket = TicketDespacho::query()->findOrFail($ticket->id);
            $ticket->load([
                'jornada',
                'clienteDestino',
                'pesadas.tipoPollo',
                'precios',
            ]);

            $belongsToCompany = DB::table('sucursales')
                ->where('id', $ticket->jornada?->sucursal_id)
                ->where('empresa_id', $companyId)
                ->exists();

            abort_unless($belongsToCompany, 404);

            $saleDocumentId = $this->syncSaleDocument($companyId, $ticket, $actor);
            $purchases = $this->syncPurchaseDocuments($companyId, $ticket, $actor);

            return [
                'sale_document_id' => $saleDocumentId,
                'purchase_document_ids' => $purchases['document_ids'],
                'pending_purchase_costs' => $purchases['pending_costs'],
            ];
        }, 3);
    }

    /**
     * Reintenta valorizar las pesadas que quedaron pendientes cuando el proveedor
     * todavia no tenia un precio de compra configurado.
     */
    public function refreshPendingPurchaseCosts(
        int $companyId,
        int $providerId,
        User $actor,
    ): int {
        $pendingQuery = DB::table('costos_compra_pesadas as costo')
            ->join('pesadas as pesada', 'pesada.id', '=', 'costo.pesada_id')
            ->join('tickets_despacho as ticket', 'ticket.id', '=', 'pesada.ticket_id')
            ->join('jornadas_operativas as jornada', 'jornada.id', '=', 'ticket.jornada_id')
            ->join('sucursales as sucursal', 'sucursal.id', '=', 'jornada.sucursal_id')
            ->where('sucursal.empresa_id', $companyId)
            ->where('costo.proveedor_id', $providerId)
            ->where('costo.estado', 'PENDIENTE');
        $before = (clone $pendingQuery)->count();

        if ($before === 0) {
            return 0;
        }

        $ticketIds = (clone $pendingQuery)
            ->distinct()
            ->pluck('ticket.id');

        TicketDespacho::query()
            ->whereIn('id', $ticketIds)
            ->orderBy('id')
            ->get()
            ->each(fn (TicketDespacho $ticket) => $this->syncTicket($companyId, $ticket, $actor));

        $after = (clone $pendingQuery)->count();

        return max(0, $before - $after);
    }

    private function syncSaleDocument(
        int $companyId,
        TicketDespacho $ticket,
        User $actor
    ): ?int {
        $isAnonymousRetail = $ticket->canal === TicketDespacho::CHANNEL_RETAIL
            && $ticket->cliente_destino_id === null;

        if (! $ticket->cliente_destino_id && ! $isAnonymousRetail) {
            return null;
        }

        $originKey = "VENTA:TICKET:{$ticket->id}";

        $records = $ticket->pesadas
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->values();

        if ($records->isEmpty()) {
            $this->voidDocument($companyId, $originKey, $actor, 'El ticket ya no tiene pesadas activas.');

            return null;
        }

        $prices = $ticket->precios->keyBy('tipo_pollo_id');
        $lines = $records
            ->groupBy('tipo_pollo_id')
            ->map(function (Collection $typeRecords, int|string $typeId) use ($prices): array {
                $price = $prices->get((int) $typeId);
                $priceKg = (string) ($price?->precio_kg ?? '0');
                $subtotal = '0.00';

                foreach ($typeRecords as $record) {
                    $subtotal = bcadd(
                        $subtotal,
                        $this->moneyProduct((string) $record->peso_neto_kg, $priceKg),
                        2
                    );
                }

                return [
                    'tipo_pollo_id' => (int) $typeId,
                    'descripcion' => (string) ($typeRecords->first()?->tipoPollo?->nombre ?? 'Pollo'),
                    'cantidad_aves' => (int) $typeRecords->sum('cantidad_aves'),
                    'peso_neto_kg' => $this->decimalSum($typeRecords->pluck('peso_neto_kg'), 3),
                    'precio_kg' => $priceKg,
                    'subtotal' => $subtotal,
                ];
            })
            ->values();
        $total = $this->decimalSum($lines->pluck('subtotal'), 2);

        if (bccomp($total, '0.00', 2) <= 0) {
            $this->voidDocument($companyId, $originKey, $actor, 'El ticket ya no tiene un importe valorizado.');

            return null;
        }

        $nature = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN
            ? 'ABONO'
            : 'CARGO';
        $client = $ticket->clienteDestino;
        $documentId = $this->upsertDocument(
            companyId: $companyId,
            actor: $actor,
            originKey: $originKey,
            attributes: [
                'tercero_id' => $client?->id,
                'operacion' => 'VENTA',
                'naturaleza' => $nature,
                'tipo_documento' => 'INTERNO',
                'codigo' => ($nature === 'ABONO' ? 'NCV-' : 'V-').$ticket->id,
                'origen_codigo' => 'AUTOMATICO',
                'fecha_emision' => $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                    ?? now()->toDateString(),
                'fecha_vencimiento' => $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                    ?? now()->toDateString(),
                'moneda' => $this->companyCurrency($companyId),
                'subtotal' => $total,
                'impuesto' => '0.00',
                'total' => $total,
                'contraparte_tipo_documento_snapshot' => $client?->tipo_documento,
                'contraparte_numero_documento_snapshot' => $client?->numero_documento,
                'contraparte_nombre_snapshot' => $client?->nombre_razon_social
                    ?? 'VENTA MINORISTA SIN CLIENTE',
                'contraparte_direccion_snapshot' => $client?->direccion,
            ],
            applicationSide: 'CXC'
        );

        $this->syncDocumentDetails($documentId, $lines);

        DB::table('comprobante_tickets')->updateOrInsert(
            ['comprobante_id' => $documentId, 'ticket_id' => $ticket->id],
            ['importe_aplicado' => $total]
        );

        return $documentId;
    }

    /**
     * @return array{document_ids: array<int, int>, pending_costs: int}
     */
    private function syncPurchaseDocuments(
        int $companyId,
        TicketDespacho $ticket,
        User $actor
    ): array {
        if ($ticket->tipo_operacion !== TicketDespacho::OPERATION_DISPATCH) {
            return ['document_ids' => [], 'pending_costs' => 0];
        }

        $inactiveRecordIds = $ticket->pesadas
            ->where('estado', '!=', Pesada::STATUS_ACTIVE)
            ->pluck('id');
        if ($inactiveRecordIds->isNotEmpty()) {
            DB::table('costos_compra_pesadas')
                ->whereIn('pesada_id', $inactiveRecordIds)
                ->where('estado', '<>', 'ANULADO')
                ->update(['estado' => 'ANULADO', 'updated_at' => now()]);
        }

        $originPrefix = "COMPRA:TICKET:{$ticket->id}:PROVEEDOR:";
        $existingDocuments = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', 'like', $originPrefix.'%')
            ->get()
            ->keyBy('origen_clave');

        $providerRecords = $ticket->pesadas
            ->where('estado', Pesada::STATUS_ACTIVE)
            ->filter(fn (Pesada $record): bool => $record->proveedor_origen_id !== null)
            ->groupBy('proveedor_origen_id');
        $documentIds = [];
        $activeOriginKeys = [];
        $pendingCosts = 0;

        foreach ($providerRecords as $providerId => $records) {
            $originKey = $originPrefix.$providerId;
            $costs = $records->mapWithKeys(function (Pesada $record) use (
                $companyId,
                $actor
            ): array {
                return [$record->id => $this->syncPurchaseCost($companyId, $record, $actor)];
            });
            $pendingForProvider = $costs->where('estado', 'PENDIENTE')->count();
            $pendingCosts += $pendingForProvider;

            if ($pendingForProvider > 0) {
                continue;
            }

            $provider = DB::table('terceros')
                ->where('empresa_id', $companyId)
                ->where('id', (int) $providerId)
                ->first();

            if (! $provider) {
                continue;
            }

            $lines = $records
                ->groupBy(function (Pesada $record) use ($costs): string {
                    $cost = $costs->get($record->id);

                    return $record->tipo_pollo_id.'|'.$cost['precio_kg'];
                })
                ->map(function (Collection $lineRecords) use ($costs): array {
                    $first = $lineRecords->first();
                    $priceKg = $costs->get($first->id)['precio_kg'];

                    return [
                        'tipo_pollo_id' => (int) $first->tipo_pollo_id,
                        'descripcion' => (string) ($first->tipoPollo?->nombre ?? 'Pollo').
                            ' - compra a proveedor',
                        'cantidad_aves' => (int) $lineRecords->sum('cantidad_aves'),
                        'peso_neto_kg' => $this->decimalSum($lineRecords->pluck('peso_neto_kg'), 3),
                        'precio_kg' => $priceKg,
                        'subtotal' => $this->decimalSum(
                            $lineRecords->map(
                                fn (Pesada $record): string => $costs->get($record->id)['importe']
                            ),
                            2
                        ),
                    ];
                })
                ->values();
            $total = $this->decimalSum($lines->pluck('subtotal'), 2);

            if (bccomp($total, '0.00', 2) <= 0) {
                continue;
            }

            $documentId = $this->upsertDocument(
                companyId: $companyId,
                actor: $actor,
                originKey: $originKey,
                attributes: [
                    'tercero_id' => (int) $providerId,
                    'operacion' => 'COMPRA',
                    'naturaleza' => 'CARGO',
                    'tipo_documento' => 'INTERNO',
                    'codigo' => "CP-{$ticket->id}-{$providerId}",
                    'origen_codigo' => 'AUTOMATICO',
                    'fecha_emision' => $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                        ?? now()->toDateString(),
                    'fecha_vencimiento' => $ticket->jornada?->fecha_operativa?->format('Y-m-d')
                        ?? now()->toDateString(),
                    'moneda' => $this->companyCurrency($companyId),
                    'subtotal' => $total,
                    'impuesto' => '0.00',
                    'total' => $total,
                    'contraparte_tipo_documento_snapshot' => $provider->tipo_documento,
                    'contraparte_numero_documento_snapshot' => $provider->numero_documento,
                    'contraparte_nombre_snapshot' => $provider->nombre_razon_social,
                    'contraparte_direccion_snapshot' => $provider->direccion,
                ],
                applicationSide: 'CXP'
            );

            $this->syncDocumentDetails($documentId, $lines);

            foreach ($records as $record) {
                DB::table('comprobante_pesadas')->updateOrInsert(
                    ['comprobante_id' => $documentId, 'pesada_id' => $record->id],
                    ['importe_aplicado' => $costs->get($record->id)['importe']]
                );
            }

            DB::table('comprobante_pesadas')
                ->where('comprobante_id', $documentId)
                ->whereNotIn('pesada_id', $records->pluck('id'))
                ->delete();

            $documentIds[] = $documentId;
            $activeOriginKeys[] = $originKey;
        }

        $existingDocuments
            ->reject(fn (object $document): bool => in_array($document->origen_clave, $activeOriginKeys, true))
            ->each(fn (object $document) => $this->voidDocument(
                $companyId,
                (string) $document->origen_clave,
                $actor,
                'El ticket ya no tiene compras valorizadas para este proveedor.',
            ));

        return ['document_ids' => $documentIds, 'pending_costs' => $pendingCosts];
    }

    /**
     * @return array{estado: string, precio_kg: string, importe: string}
     */
    private function syncPurchaseCost(int $companyId, Pesada $record, User $actor): array
    {
        $existing = DB::table('costos_compra_pesadas')
            ->where('pesada_id', $record->id)
            ->lockForUpdate()
            ->first();

        $sourceTypeId = DB::table('tipos_pollo')
            ->where('id', $record->tipo_pollo_id)
            ->value('precio_fuente_tipo_pollo_id') ?: $record->tipo_pollo_id;
        $listId = DB::table('listas_precios')
            ->where('empresa_id', $companyId)
            ->where('tercero_id', $record->proveedor_origen_id)
            ->where('operacion', ListaPrecio::OPERATION_PURCHASE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->value('id');
        $history = $listId
            ? DB::table('precios_historial')
                ->where('lista_precio_id', $listId)
                ->where('tipo_pollo_id', $sourceTypeId)
                ->where('vigente_desde', '<=', $record->pesada_at)
                ->where(function ($query) use ($record): void {
                    $query->whereNull('vigente_hasta')
                        ->orWhere('vigente_hasta', '>', $record->pesada_at);
                })
                ->orderByDesc('vigente_desde')
                ->first()
            : null;
        $usedLaterPrice = false;

        if (! $history && $listId) {
            $history = DB::table('precios_historial')
                ->where('lista_precio_id', $listId)
                ->where('tipo_pollo_id', $sourceTypeId)
                ->whereNull('vigente_hasta')
                ->orderByDesc('vigente_desde')
                ->first();
            $usedLaterPrice = $history !== null;
        }

        if (! $history && $existing?->precio_historial_id) {
            $history = DB::table('precios_historial as precio')
                ->join('listas_precios as lista', 'lista.id', '=', 'precio.lista_precio_id')
                ->where('precio.id', $existing->precio_historial_id)
                ->where('precio.tipo_pollo_id', $sourceTypeId)
                ->where('lista.empresa_id', $companyId)
                ->where('lista.tercero_id', $record->proveedor_origen_id)
                ->where('lista.operacion', ListaPrecio::OPERATION_PURCHASE)
                ->select('precio.*')
                ->first();
        }

        $keepsSnapshot = $history
            && $existing
            && $existing->estado === 'ACTIVO'
            && (int) ($existing->precio_historial_id ?? 0) === (int) $history->id
            && (int) $existing->proveedor_id === (int) $record->proveedor_origen_id;
        $priceKg = $keepsSnapshot
            ? (string) $existing->precio_kg
            : (string) ($history->precio_kg ?? '0.0000');
        $amount = $history
            ? $this->moneyProduct((string) $record->peso_neto_kg, $priceKg)
            : '0.00';
        $attributes = [
            'proveedor_id' => $record->proveedor_origen_id,
            'precio_historial_id' => $history?->id,
            'precio_kg' => $priceKg,
            'peso_kg' => (string) $record->peso_neto_kg,
            'importe' => $amount,
            'estado' => $history ? 'ACTIVO' : 'PENDIENTE',
            'origen' => $keepsSnapshot
                ? (string) $existing->origen
                : ($history
                    ? ($usedLaterPrice ? 'LISTA_POSTERIOR' : 'LISTA_PROVEEDOR')
                    : 'SIN_PRECIO'),
        ];

        if ($existing) {
            if ($this->documentValuesChanged($existing, $attributes)) {
                DB::table('costos_compra_pesadas')->where('id', $existing->id)->update([
                    ...$attributes,
                    'updated_at' => now(),
                ]);
            }
        } else {
            DB::table('costos_compra_pesadas')->insert([
                'pesada_id' => $record->id,
                ...$attributes,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'estado' => $attributes['estado'],
            'precio_kg' => $priceKg,
            'importe' => $amount,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertDocument(
        int $companyId,
        User $actor,
        string $originKey,
        array $attributes,
        string $applicationSide
    ): int {
        $existing = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', $originKey)
            ->lockForUpdate()
            ->first();
        $applied = $existing
            ? (string) DB::table('pago_aplicaciones as aplicaciones')
                ->join('pagos', 'pagos.id', '=', 'aplicaciones.pago_id')
                ->where('aplicaciones.comprobante_id', $existing->id)
                ->where('aplicaciones.lado', $applicationSide)
                ->where('pagos.estado', 'REGISTRADO')
                ->sum('aplicaciones.importe_aplicado')
            : '0.00';
        $balance = bcsub((string) $attributes['total'], $applied, 2);

        if (bccomp($balance, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'importe' => 'La revalorizacion no puede dejar el documento por debajo del importe ya aplicado. Anula primero los movimientos financieros relacionados.',
            ]);
        }

        $status = bccomp($balance, '0.00', 2) === 0
            ? 'PAGADO'
            : (bccomp($applied, '0.00', 2) > 0 ? 'PARCIAL' : 'PENDIENTE');
        $values = [
            ...$attributes,
            'empresa_id' => $companyId,
            'origen_clave' => $originKey,
            'saldo_pendiente' => $balance,
            'estado' => $status,
        ];

        if ($existing) {
            foreach ([
                'contraparte_tipo_documento_snapshot',
                'contraparte_numero_documento_snapshot',
                'contraparte_nombre_snapshot',
                'contraparte_direccion_snapshot',
            ] as $snapshotField) {
                unset($values[$snapshotField]);
            }
            $values['anulada_por'] = null;
            $values['anulada_at'] = null;
            $values['motivo_anulacion'] = null;

            if (! $this->documentValuesChanged($existing, $values)) {
                return (int) $existing->id;
            }

            $values['updated_at'] = now();
            DB::table('comprobantes')->where('id', $existing->id)->update($values);
            $documentId = (int) $existing->id;
            $action = 'REVALORIZAR';
        } else {
            $values['updated_at'] = now();
            $documentId = (int) DB::table('comprobantes')->insertGetId([
                ...$values,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
            $action = 'GENERAR';
        }

        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actor->id,
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $documentId,
            'accion' => $action,
            'datos_antes' => $existing ? json_encode($existing, JSON_THROW_ON_ERROR) : null,
            'datos_despues' => json_encode($values, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $documentId;
    }

    private function voidDocument(
        int $companyId,
        string $originKey,
        User $actor,
        string $reason,
    ): void {
        $document = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', $originKey)
            ->lockForUpdate()
            ->first();

        if (! $document || $document->estado === 'ANULADO') {
            return;
        }

        $hasApplications = DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('aplicacion.comprobante_id', $document->id)
            ->where('pago.estado', 'REGISTRADO')
            ->exists();

        if ($hasApplications) {
            throw ValidationException::withMessages([
                'ticket' => 'No se puede anular una obligacion con movimientos financieros aplicados.',
            ]);
        }

        $after = [
            'estado' => 'ANULADO',
            'anulada_por' => $actor->id,
            'anulada_at' => now(),
            'motivo_anulacion' => $reason,
            'updated_at' => now(),
        ];
        DB::table('comprobantes')->where('id', $document->id)->update($after);
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actor->id,
            'entidad' => 'comprobantes',
            'entidad_id' => (string) $document->id,
            'accion' => 'ANULAR_AUTOMATICO',
            'datos_antes' => json_encode($document, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function syncDocumentDetails(int $documentId, Collection $lines): void
    {
        $requested = $lines
            ->map(fn (array $line): array => $this->canonicalDocumentLine($line))
            ->sortBy(fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR))
            ->values()
            ->all();
        $stored = DB::table('comprobante_detalles')
            ->where('comprobante_id', $documentId)
            ->get([
                'tipo_pollo_id',
                'descripcion',
                'cantidad_aves',
                'peso_neto_kg',
                'precio_kg',
                'subtotal',
            ])
            ->map(fn (object $line): array => $this->canonicalDocumentLine((array) $line))
            ->sortBy(fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        if ($stored === $requested) {
            return;
        }

        DB::table('comprobante_detalles')->where('comprobante_id', $documentId)->delete();
        foreach ($lines as $line) {
            DB::table('comprobante_detalles')->insert([
                'comprobante_id' => $documentId,
                ...$line,
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $line @return array<string, int|string|null> */
    private function canonicalDocumentLine(array $line): array
    {
        return [
            'tipo_pollo_id' => isset($line['tipo_pollo_id']) ? (int) $line['tipo_pollo_id'] : null,
            'descripcion' => (string) $line['descripcion'],
            'cantidad_aves' => isset($line['cantidad_aves']) ? (int) $line['cantidad_aves'] : null,
            'peso_neto_kg' => isset($line['peso_neto_kg']) ? bcadd((string) $line['peso_neto_kg'], '0', 3) : null,
            'precio_kg' => isset($line['precio_kg']) ? bcadd((string) $line['precio_kg'], '0', 4) : null,
            'subtotal' => bcadd((string) $line['subtotal'], '0', 2),
        ];
    }

    /** @param array<string, mixed> $values */
    private function documentValuesChanged(object $document, array $values): bool
    {
        foreach ($values as $field => $value) {
            $stored = $document->{$field} ?? null;
            if ($stored instanceof \DateTimeInterface) {
                $stored = $stored->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if (($stored === null ? '' : (string) $stored) !== ($value === null ? '' : (string) $value)) {
                return true;
            }
        }

        return false;
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN');
    }

    private function moneyProduct(string $quantity, string $unitPrice): string
    {
        return bcadd(bcmul($quantity, $unitPrice, 6), '0.005', 2);
    }

    /** @param Collection<int, mixed> $values */
    private function decimalSum(Collection $values, int $scale): string
    {
        return $values->reduce(
            fn (string $sum, mixed $value): string => bcadd($sum, (string) $value, $scale),
            number_format(0, $scale, '.', '')
        );
    }
}
