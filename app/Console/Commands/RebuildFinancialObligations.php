<?php

namespace App\Console\Commands;

use App\Models\TicketDespacho;
use App\Models\User;
use App\Services\FinancialObligationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RebuildFinancialObligations extends Command
{
    protected $signature = 'finanzas:reconstruir-obligaciones
        {--dry-run : Simula la reconstruccion y revierte todos los cambios}';

    protected $description = 'Reconstruye los comprobantes internos de venta de los tickets cerrados';

    public function handle(FinancialObligationService $obligations): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companies = DB::table('empresas')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('tickets_despacho as tickets')
                    ->join('jornadas_operativas as jornadas', 'jornadas.id', '=', 'tickets.jornada_id')
                    ->join('sucursales', 'sucursales.id', '=', 'jornadas.sucursal_id')
                    ->whereColumn('sucursales.empresa_id', 'empresas.id')
                    ->where('tickets.estado', TicketDespacho::STATUS_CLOSED);
            })
            ->orderBy('id')
            ->get(['id', 'razon_social']);

        if ($companies->isEmpty()) {
            $this->info('No hay tickets cerrados para reconstruir.');

            return self::SUCCESS;
        }

        $this->components->info($dryRun
            ? 'Simulando la reconstruccion de comprobantes de venta...'
            : 'Reconstruyendo comprobantes de venta...');

        $rows = [];
        $totals = [
            'tickets' => 0,
            'documents' => 0,
            'errors' => 0,
        ];

        foreach ($companies as $company) {
            $companyTotals = [
                'tickets' => 0,
                'documents' => 0,
                'errors' => 0,
            ];
            $actors = [];

            TicketDespacho::query()
                ->where('estado', TicketDespacho::STATUS_CLOSED)
                ->whereHas('jornada', fn ($query) => $query->whereIn(
                    'sucursal_id',
                    DB::table('sucursales')
                        ->where('empresa_id', $company->id)
                        ->select('id')
                ))
                ->orderBy('id')
                ->chunkById(100, function ($tickets) use (
                    $obligations,
                    $company,
                    $dryRun,
                    &$actors,
                    &$companyTotals
                ): void {
                    foreach ($tickets as $ticket) {
                        try {
                            $actorId = (int) ($ticket->cerrado_por ?: $ticket->created_by);
                            $actor = $actors[$actorId] ??= User::query()
                                ->where('empresa_id', $company->id)
                                ->findOrFail($actorId);
                            $result = $this->syncTicket(
                                $obligations,
                                (int) $company->id,
                                $ticket,
                                $actor,
                                $dryRun
                            );

                            $companyTotals['tickets']++;
                            $companyTotals['documents'] += $result['sale_document_id'] ? 1 : 0;
                        } catch (Throwable $exception) {
                            $companyTotals['errors']++;
                            $this->components->error(
                                "Ticket {$ticket->codigo} ({$ticket->id}): {$exception->getMessage()}"
                            );
                        }
                    }
                });

            $rows[] = [
                "{$company->razon_social} ({$company->id})",
                $companyTotals['tickets'],
                $companyTotals['documents'],
                $companyTotals['errors'],
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] += $companyTotals[$key];
            }
        }

        $rows[] = [
            'TOTAL',
            $totals['tickets'],
            $totals['documents'],
            $totals['errors'],
        ];
        $this->table(
            ['Empresa', 'Tickets', 'Documentos de venta', 'Errores'],
            $rows
        );

        if ($dryRun) {
            $this->components->warn('Simulacion finalizada: no se guardo ningun cambio.');
        } else {
            $this->components->info('Reconstruccion finalizada.');
        }

        return $totals['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{sale_document_id: ?int, purchase_document_ids: array<int, int>, pending_purchase_costs: int}
     */
    private function syncTicket(
        FinancialObligationService $obligations,
        int $companyId,
        TicketDespacho $ticket,
        User $actor,
        bool $dryRun
    ): array {
        DB::beginTransaction();

        try {
            $result = $obligations->syncTicket($companyId, $ticket, $actor);

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
