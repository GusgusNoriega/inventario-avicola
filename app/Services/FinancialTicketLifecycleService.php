<?php

namespace App\Services;

use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialTicketLifecycleService
{
    public function __construct(
        private readonly DispatchTicketVoidService $ticketVoids,
        private readonly JavaControlService $javaControl,
        private readonly FinancialObligationService $financialObligations,
        private readonly AccessAuditService $audit,
        private readonly TicketVoidWeighingResolver $voidedRecords,
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
        User $actor,
        int $ticketId,
        string $reason,
        ?string $ip = null,
    ): array {
        $this->assertAdministrator($companyId, $actor);

        return $this->ticketVoids->void(
            $companyId,
            $this->branchIdForTicket($companyId, $ticketId),
            $actor,
            $ticketId,
            $reason,
            $ip,
        );
    }

    /**
     * @return array{
     *     ticket: TicketDespacho,
     *     idempotent: bool,
     *     restored_weighing_ids: list<int>
     * }
     */
    public function restore(
        int $companyId,
        User $actor,
        int $ticketId,
        ?string $ip = null,
    ): array {
        $this->assertAdministrator($companyId, $actor);

        return DB::transaction(function () use (
            $companyId,
            $actor,
            $ticketId,
            $ip,
        ): array {
            $ticket = TicketDespacho::query()
                ->whereKey($ticketId)
                ->whereHas(
                    'jornada.sucursal',
                    fn ($query) => $query->where('empresa_id', $companyId),
                )
                ->lockForUpdate()
                ->first();
            abort_unless($ticket, 404, 'Ticket no encontrado.');

            if ($ticket->estado === TicketDespacho::STATUS_CLOSED) {
                return [
                    'ticket' => $ticket,
                    'idempotent' => true,
                    'restored_weighing_ids' => [],
                ];
            }

            if ($ticket->estado !== TicketDespacho::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'ticket' => 'Solo se pueden restablecer tickets anulados.',
                ]);
            }

            $ticketBefore = $ticket->attributesToArray();
            $records = Pesada::query()
                ->where('ticket_id', $ticket->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $recordsToRestore = $this->voidedRecords->recordsForCycle(
                $companyId,
                $ticket,
                $records,
            );
            $restoredWeighingIds = [];

            foreach ($recordsToRestore as $record) {
                $before = $record->attributesToArray();
                $record->update([
                    'estado' => Pesada::STATUS_ACTIVE,
                    'anulada_por' => null,
                    'anulada_at' => null,
                    'motivo_anulacion' => null,
                ]);
                $record->refresh();
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'pesadas',
                    (int) $record->id,
                    'RESTABLECER_POR_TICKET',
                    $before,
                    $record->attributesToArray(),
                    $ip,
                );
                $restoredWeighingIds[] = (int) $record->id;
            }

            $ticket->update([
                'estado' => TicketDespacho::STATUS_CLOSED,
                'anulado_por' => null,
                'anulado_at' => null,
                'motivo_anulacion' => null,
            ]);
            $ticket->refresh();
            $ticket->loadMissing('jornada:id,sucursal_id');

            $this->javaControl->syncDispatchMovement(
                $ticket,
                $companyId,
                (int) $ticket->jornada->sucursal_id,
            );
            $restoredJavaMovement = DB::table('movimientos_javas')
                ->where('ticket_despacho_id', $ticket->id)
                ->first();
            if ($restoredJavaMovement) {
                $this->audit->record(
                    $companyId,
                    (int) $actor->id,
                    'movimientos_javas',
                    (int) $restoredJavaMovement->id,
                    'RECREAR_POR_TICKET_RESTABLECIDO',
                    null,
                    (array) $restoredJavaMovement,
                    $ip,
                );
            }
            $this->financialObligations->syncTicket($companyId, $ticket, $actor);

            $this->audit->record(
                $companyId,
                (int) $actor->id,
                'tickets_despacho',
                (int) $ticket->id,
                'RESTABLECER',
                $ticketBefore,
                $ticket->attributesToArray(),
                $ip,
            );

            return [
                'ticket' => $ticket,
                'idempotent' => false,
                'restored_weighing_ids' => $restoredWeighingIds,
            ];
        }, 3);
    }

    private function branchIdForTicket(int $companyId, int $ticketId): int
    {
        $branchId = DB::table('tickets_despacho as ticket')
            ->join('jornadas_operativas as jornada', 'jornada.id', '=', 'ticket.jornada_id')
            ->join('sucursales as sucursal', 'sucursal.id', '=', 'jornada.sucursal_id')
            ->where('ticket.id', $ticketId)
            ->where('sucursal.empresa_id', $companyId)
            ->value('sucursal.id');
        abort_unless($branchId, 404, 'Ticket no encontrado.');

        return (int) $branchId;
    }

    private function assertAdministrator(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->isAdministrator(),
            403,
            'Solo un administrador puede anular o restablecer tickets.',
        );
    }
}
