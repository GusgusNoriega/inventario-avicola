<?php

namespace App\Services;

use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispatchTicketVoidService
{
    public function __construct(
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations,
        private readonly FinancialMovementService $financialMovements,
        private readonly AccessAuditService $audit,
    ) {}

    /**
     * @return array{
     *     ticket: TicketDespacho,
     *     idempotent: bool,
     *     reversed_payment_ids: list<int>
     * }
     */
    public function void(
        int $companyId,
        int $branchId,
        User $actor,
        int $ticketId,
        string $reason,
        ?string $ip = null,
    ): array {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->isAdministrator(),
            403,
            'Solo un administrador puede anular tickets.'
        );

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $actor,
            $ticketId,
            $reason,
            $ip,
        ): array {
            $ticket = TicketDespacho::query()
                ->whereKey($ticketId)
                ->whereHas(
                    'jornada',
                    fn (Builder $query) => $query->where('sucursal_id', $branchId)
                )
                ->lockForUpdate()
                ->first();
            abort_unless($ticket, 404, 'Ticket no encontrado.');

            if ($ticket->estado === TicketDespacho::STATUS_VOIDED) {
                return [
                    'ticket' => $ticket,
                    'idempotent' => true,
                    'reversed_payment_ids' => [],
                ];
            }

            if ($ticket->estado !== TicketDespacho::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'ticket' => 'Solo se pueden anular tickets cerrados.',
                ]);
            }

            $ticketBefore = $ticket->attributesToArray();
            $records = Pesada::query()
                ->where('ticket_id', $ticket->id)
                ->where('estado', Pesada::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $paymentIds = $this->exclusiveActivePaymentIds($companyId, $ticket);
            $reversedPaymentIds = [];

            foreach ($paymentIds as $paymentId) {
                $result = $this->financialMovements->void(
                    $companyId,
                    $actor,
                    $paymentId,
                    "Anulación del ticket {$ticket->codigo}: {$reason}",
                    $ip,
                );
                $reversedPaymentIds[] = (int) $result['reversa_id'];
            }

            $voidedAt = now();
            foreach ($records as $record) {
                $before = $record->attributesToArray();
                $after = [
                    'estado' => Pesada::STATUS_VOIDED,
                    'anulada_por' => $actor->id,
                    'anulada_at' => $voidedAt,
                    'motivo_anulacion' => mb_substr(
                        "Ticket {$ticket->codigo} anulado: {$reason}",
                        0,
                        250
                    ),
                    'updated_at' => $voidedAt,
                ];
                $record->update($after);
                $this->audit->record(
                    $companyId,
                    $actor->id,
                    'pesadas',
                    (int) $record->id,
                    'ANULAR_POR_TICKET',
                    $before,
                    [...$before, ...$this->auditDates($after)],
                    $ip,
                );
            }

            $movement = DB::table('movimientos_javas')
                ->where('ticket_despacho_id', $ticket->id)
                ->lockForUpdate()
                ->first();

            $ticket->update([
                'estado' => TicketDespacho::STATUS_VOIDED,
                'anulado_por' => $actor->id,
                'anulado_at' => $voidedAt,
                'motivo_anulacion' => $reason,
            ]);
            $ticket->refresh();

            $this->javaControl->syncDispatchMovement($ticket, $companyId, $branchId);
            if ($movement) {
                $this->audit->record(
                    $companyId,
                    $actor->id,
                    'movimientos_javas',
                    (int) $movement->id,
                    'RETIRAR_POR_TICKET_ANULADO',
                    (array) $movement,
                    null,
                    $ip,
                );
            }

            $this->financialObligations->syncTicket($companyId, $ticket, $actor);

            $ticketAfter = $ticket->fresh()?->attributesToArray() ?? [];
            $this->audit->record(
                $companyId,
                $actor->id,
                'tickets_despacho',
                (int) $ticket->id,
                'ANULAR',
                $ticketBefore,
                $ticketAfter,
                $ip,
            );

            return [
                'ticket' => $ticket->fresh(['anuladoPor']),
                'idempotent' => false,
                'reversed_payment_ids' => $reversedPaymentIds,
            ];
        }, 3);
    }

    /**
     * Cada movimiento que se revierta debe pertenecer únicamente al documento
     * de este ticket. Así se evita alterar cobros aplicados también a otras ventas.
     *
     * @return Collection<int, int>
     */
    private function exclusiveActivePaymentIds(int $companyId, TicketDespacho $ticket): Collection
    {
        $document = DB::table('comprobantes')
            ->where('empresa_id', $companyId)
            ->where('origen_clave', "VENTA:TICKET:{$ticket->id}")
            ->lockForUpdate()
            ->first();

        if (! $document) {
            return collect();
        }

        $paymentIds = DB::table('pago_aplicaciones as aplicacion')
            ->join('pagos as pago', 'pago.id', '=', 'aplicacion.pago_id')
            ->where('aplicacion.comprobante_id', $document->id)
            ->where('pago.estado', 'REGISTRADO')
            ->orderBy('pago.id')
            ->lockForUpdate()
            ->pluck('pago.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($paymentIds as $paymentId) {
            $otherApplications = DB::table('pago_aplicaciones')
                ->where('pago_id', $paymentId)
                ->where('comprobante_id', '!=', $document->id)
                ->exists();

            if ($otherApplications) {
                throw ValidationException::withMessages([
                    'ticket' => 'El ticket tiene un cobro compartido con otras ventas. Desaplica o anula ese movimiento desde Finanzas antes de anular el ticket.',
                ]);
            }
        }

        return $paymentIds;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function auditDates(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value)
            ->all();
    }
}
