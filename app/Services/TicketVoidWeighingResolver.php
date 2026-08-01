<?php

namespace App\Services;

use App\Models\Pesada;
use App\Models\TicketDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketVoidWeighingResolver
{
    /**
     * Identifica las pesadas anuladas por el mismo ciclo de anulación del ticket.
     * La acción de auditoría evita reactivar pesadas anuladas individualmente.
     *
     * @param  Collection<int, Pesada>  $records
     * @return Collection<int, Pesada>
     */
    public function recordsForCycle(
        int $companyId,
        TicketDespacho $ticket,
        Collection $records,
    ): Collection {
        $voidedRecords = $records
            ->where('estado', Pesada::STATUS_VOIDED)
            ->values();

        if (
            $voidedRecords->isEmpty()
            || $ticket->anulado_por === null
            || $ticket->getRawOriginal('anulado_at') === null
        ) {
            return collect();
        }

        $voidedAt = $this->normalizedDate($ticket->getRawOriginal('anulado_at'));
        $voidedBy = (int) $ticket->anulado_por;
        $expectedReason = mb_substr(
            "Ticket {$ticket->codigo} anulado: {$ticket->motivo_anulacion}",
            0,
            250,
        );
        $recordsById = $voidedRecords->keyBy(
            fn (Pesada $record): int => (int) $record->id,
        );

        $matchingIds = DB::table('auditoria_eventos')
            ->where('empresa_id', $companyId)
            ->where('entidad', 'pesadas')
            ->where('accion', 'ANULAR_POR_TICKET')
            ->whereIn(
                'entidad_id',
                $recordsById->keys()->map(fn (int $id): string => (string) $id)->all(),
            )
            ->orderByDesc('id')
            ->get(['entidad_id', 'datos_antes', 'datos_despues'])
            ->filter(function (object $event) use (
                $recordsById,
                $voidedAt,
                $voidedBy,
                $expectedReason,
            ): bool {
                $record = $recordsById->get((int) $event->entidad_id);
                if (! $record) {
                    return false;
                }

                $before = json_decode((string) $event->datos_antes, true);
                $after = json_decode((string) $event->datos_despues, true);
                if (! is_array($before) || ! is_array($after)) {
                    return false;
                }

                return ($before['estado'] ?? null) === Pesada::STATUS_ACTIVE
                    && (int) ($before['ticket_id'] ?? 0) === (int) $record->ticket_id
                    && (int) ($after['ticket_id'] ?? 0) === (int) $record->ticket_id
                    && ($after['estado'] ?? null) === Pesada::STATUS_VOIDED
                    && (int) ($after['anulada_por'] ?? 0) === $voidedBy
                    && $this->normalizedDate($after['anulada_at'] ?? null) === $voidedAt
                    && (string) ($after['motivo_anulacion'] ?? '') === $expectedReason
                    && (int) $record->anulada_por === $voidedBy
                    && $this->normalizedDate($record->getRawOriginal('anulada_at')) === $voidedAt
                    && (string) $record->motivo_anulacion === $expectedReason;
            })
            ->map(fn (object $event): int => (int) $event->entidad_id)
            ->unique()
            ->values();

        return $matchingIds
            ->map(fn (int $id): ?Pesada => $recordsById->get($id))
            ->filter()
            ->values();
    }

    private function normalizedDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
