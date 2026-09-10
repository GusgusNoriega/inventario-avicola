<?php

namespace App\Services;

use App\Models\ListaPrecio;
use App\Models\ReceptionSyncRecord;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceptionSyncFinancialService
{
    public function __construct(
        private readonly FinancialObligationService $obligations,
        private readonly FinancialAuditService $audit,
    ) {}

    /** Synchronize only the customer debt; captured facts remain in the independent module. */
    public function sync(ReceptionSyncRecord $record, User $actor): void
    {
        if ($record->kind !== 'ticket') {
            return;
        }

        DB::transaction(function () use ($record, $actor): void {
            $companyId = (int) $record->company_id;
            $record = ReceptionSyncRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $actor->empresa_id === $companyId, 403);
            $link = DB::table('reception_sync_financial_links')
                ->where('record_id', $record->id)->lockForUpdate()->first();

            if ($link) {
                DB::table('comprobantes')->where('id', $link->document_id)
                    ->where('empresa_id', $companyId)->lockForUpdate()->firstOrFail();
                $hasPayments = DB::table('pago_aplicaciones as application')
                    ->join('pagos as payment', 'payment.id', '=', 'application.pago_id')
                    ->where('application.comprobante_id', $link->document_id)
                    ->where('payment.estado', 'REGISTRADO')
                    ->lockForUpdate()->get(['application.pago_id'])->isNotEmpty();
                if ($hasPayments) {
                    throw ValidationException::withMessages([
                        'ticket' => 'El despacho tiene cobros o pagos aplicados en el servidor. Revisa los movimientos financieros antes de corregirlo o anularlo.',
                    ]);
                }
            }

            // A historical record may have been captured before financial integration.
            // Voiding it must not require a current price or create a new obligation.
            if ($record->status === 'voided') {
                if ($link) {
                    $this->obligations->syncReceptionDispatch(
                        $record,
                        $actor,
                        (int) $link->chicken_type_id,
                        (string) $link->price_kg,
                    );
                }

                return;
            }

            $clientId = (int) $record->payload['destination_id'];
            $client = Tercero::query()->where('empresa_id', $companyId)
                ->conRol(TerceroRole::CLIENT)->whereKey($clientId)->lockForUpdate()->first();
            if (! $client) {
                throw ValidationException::withMessages([
                    'destination_id' => 'El cliente no pertenece a la empresa autorizada.',
                ]);
            }

            $refreshPrice = ! $link || (int) $link->client_id !== $clientId;
            $price = $refreshPrice
                ? $this->currentPrice($companyId, $clientId)
                : [
                    'price_history_id' => $link->price_history_id,
                    'chicken_type_id' => (int) $link->chicken_type_id,
                    'client_id' => $clientId,
                    'price_kg' => (string) $link->price_kg,
                    'price_source' => $link->price_source,
                    'priced_at' => $link->priced_at,
                ];
            $documentId = $this->obligations->syncReceptionDispatch(
                $record,
                $actor,
                $price['chicken_type_id'],
                $price['price_kg'],
                $link && (int) $link->client_id !== $clientId,
            );
            $values = [...$price, 'document_id' => $documentId, 'updated_at' => now()];

            if ($link) {
                DB::table('reception_sync_financial_links')->where('id', $link->id)->update($values);
                $linkId = (int) $link->id;
            } else {
                $linkId = (int) DB::table('reception_sync_financial_links')->insertGetId([
                    ...$values,
                    'record_id' => (int) $record->id,
                    'created_at' => now(),
                ]);
            }

            if ($refreshPrice) {
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'reception_sync_financial_links',
                    $linkId,
                    $link ? 'CAMBIAR_CLIENTE_SINCRONIZADO' : 'VALORIZAR_DESPACHO_SINCRONIZADO',
                    $link ? (array) $link : null,
                    (array) DB::table('reception_sync_financial_links')->where('id', $linkId)->first(),
                );
            }
        }, 3);
    }

    /** @return array{price_history_id: int, chicken_type_id: int, client_id: int, price_kg: string, price_source: string, priced_at: CarbonInterface} */
    private function currentPrice(int $companyId, int $clientId): array
    {
        $type = TipoPollo::query()->where('codigo', TipoPollo::CHICKEN_LIVE)
            ->where('estado', TipoPollo::STATUS_ACTIVE)->first();
        $sourceType = $type ? TipoPollo::query()->whereKey($type->priceSourceTypeId())
            ->where('estado', TipoPollo::STATUS_ACTIVE)->first() : null;
        if (! $type || ! $sourceType) {
            throw ValidationException::withMessages([
                'destination_id' => 'La configuración de venta de pollo vivo no está disponible en el servidor.',
            ]);
        }

        $at = now();
        $query = DB::table('precios_historial as price')
            ->join('listas_precios as price_list', 'price_list.id', '=', 'price.lista_precio_id')
            ->where('price_list.empresa_id', $companyId)
            ->where('price_list.operacion', ListaPrecio::OPERATION_SALE)
            ->where('price_list.estado', ListaPrecio::STATUS_ACTIVE)
            ->where('price.tipo_pollo_id', $sourceType->id)
            ->where('price.vigente_desde', '<=', $at)
            ->where(fn ($query) => $query->whereNull('price.vigente_hasta')->orWhere('price.vigente_hasta', '>', $at))
            ->orderByDesc('price.vigente_desde')->orderByDesc('price.id');
        $specific = (clone $query)->where('price_list.tercero_id', $clientId)
            ->lockForUpdate()->first(['price.id', 'price.precio_kg']);
        $history = $specific ?: (clone $query)->whereNull('price_list.tercero_id')
            ->lockForUpdate()->first(['price.id', 'price.precio_kg']);

        if (! $history || bccomp((string) $history->precio_kg, '0.0000', 4) <= 0) {
            throw ValidationException::withMessages([
                'destination_id' => 'Falta un precio de venta válido para este cliente en el servidor. Configúralo antes de volver a sincronizar el despacho.',
            ]);
        }

        return [
            'price_history_id' => (int) $history->id,
            'chicken_type_id' => (int) $type->id,
            'client_id' => $clientId,
            'price_kg' => bcadd((string) $history->precio_kg, '0', 4),
            'price_source' => $specific ? 'CLIENTE' : 'GENERAL',
            'priced_at' => $at,
        ];
    }
}
