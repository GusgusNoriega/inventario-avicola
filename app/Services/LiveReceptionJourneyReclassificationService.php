<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LiveReceptionJourneyReclassificationService
{
    public function __construct(
        private readonly FinancialAuditService $audit,
    ) {}

    /**
     * Keep reception records and their inventory references together when the
     * company changes its operating cutoff. The caller owns the company lock.
     *
     * @param  callable(int, string, int): int  $journeyFor
     * @param  array<int, int>  $ticketJourneys
     * @return array{reception_tickets: int, reception_weighings: int, reception_numbers: int, reception_java_movements: int}
     */
    public function reclassify(
        int $companyId,
        string $cutoff,
        callable $journeyFor,
        array $ticketJourneys,
    ): array {
        return DB::transaction(function () use ($companyId, $cutoff, $journeyFor, $ticketJourneys): array {
            $counts = [
                'reception_tickets' => 0,
                'reception_weighings' => 0,
                'reception_numbers' => 0,
                'reception_java_movements' => 0,
            ];
            $branches = DB::table('sucursales')->where('empresa_id', $companyId)
                ->pluck('zona_horaria', 'id');
            $receptions = DB::table('recepciones_pollo_vivo as reception')
                ->join('jornadas_operativas as journey', 'journey.id', '=', 'reception.jornada_id')
                ->whereIn('journey.sucursal_id', $branches->keys())
                ->select(['reception.*', 'journey.sucursal_id'])
                ->orderBy('reception.id')->lockForUpdate()->get()->keyBy('id');

            if ($receptions->isEmpty()) {
                return $counts;
            }

            $receptionIds = $receptions->keys()->all();
            $receptionByJourney = $receptions->pluck('id', 'jornada_id')->all();
            $receptionFor = function (int $journeyId, object $source) use (&$receptionByJourney): int {
                if (! isset($receptionByJourney[$journeyId])) {
                    $receptionByJourney[$journeyId] = (int) DB::table('recepciones_pollo_vivo')->insertGetId([
                        'jornada_id' => $journeyId,
                        'origen' => $source->origen,
                        'estado' => $source->estado,
                        'created_by' => $source->created_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return (int) $receptionByJourney[$journeyId];
            };
            $actorId = auth()->id();
            $links = DB::table('recepcion_pollo_vivo_tickets')
                ->whereIn('recepcion_id', $receptionIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($links as $link) {
                $journeyId = $ticketJourneys[(int) $link->ticket_despacho_id] ?? null;
                if ($journeyId === null) {
                    continue;
                }

                $targetReceptionId = $receptionFor((int) $journeyId, $receptions[$link->recepcion_id]);
                if ($targetReceptionId === (int) $link->recepcion_id) {
                    continue;
                }

                $before = ['recepcion_id' => (int) $link->recepcion_id, 'revision' => (int) $link->revision];
                $after = ['recepcion_id' => $targetReceptionId, 'revision' => (int) $link->revision + 1];
                DB::table('recepcion_pollo_vivo_tickets')->where('id', $link->id)
                    ->update([...$after, 'updated_at' => $this->nextVersion($link)]);
                $this->audit->record($companyId, $actorId, 'recepcion_pollo_vivo_tickets', $link->id,
                    'RECLASIFICAR_JORNADA', $before, $after);
                $counts['reception_tickets']++;
            }

            $records = DB::table('pesadas_recepcion_pollo_vivo')
                ->whereIn('recepcion_id', $receptionIds)->orderBy('id')->lockForUpdate()->get();
            $plans = [];
            $occupied = [];
            $nextNumbers = [];
            $largestNumber = 0;

            foreach ($records as $record) {
                $source = $receptions[$record->recepcion_id];
                $branchId = (int) $source->sucursal_id;
                // These operational timestamps are stored as the branch's wall clock.
                $time = CarbonImmutable::parse((string) $record->pesada_at,
                    (string) ($branches[$branchId] ?: config('app.timezone')));
                $date = ($time->greaterThanOrEqualTo($time->startOfDay()->setTimeFromTimeString($cutoff))
                    ? $time->addDay() : $time)->format('Y-m-d');
                $journeyId = $journeyFor($branchId, $date, (int) $record->created_by);
                $receptionId = $receptionFor($journeyId, $source);
                $number = (int) $record->numero;
                $largestNumber = max($largestNumber, $number);
                $nextNumbers[$receptionId] = max($nextNumbers[$receptionId] ?? 0, $number);
                $plans[(int) $record->id] = [
                    'record' => $record,
                    'reception_id' => $receptionId,
                    'journey_id' => $journeyId,
                    'number' => $number,
                ];

                if ($receptionId === (int) $record->recepcion_id) {
                    $occupied[$receptionId][$number] = true;
                }
            }

            foreach ($plans as &$plan) {
                $record = $plan['record'];
                $receptionId = $plan['reception_id'];
                if ($receptionId === (int) $record->recepcion_id) {
                    continue;
                }
                if (isset($occupied[$receptionId][$plan['number']])) {
                    $plan['number'] = ++$nextNumbers[$receptionId];
                }
                $occupied[$receptionId][$plan['number']] = true;
                $largestNumber = max($largestNumber, $plan['number']);
            }
            unset($plan);

            // Vacate the moving numbers first, so two receptions exchanging
            // rows cannot hit the (recepcion_id, numero) unique constraint.
            foreach ($plans as $plan) {
                if ($plan['reception_id'] !== (int) $plan['record']->recepcion_id) {
                    DB::table('pesadas_recepcion_pollo_vivo')->where('id', $plan['record']->id)
                        ->update(['numero' => ++$largestNumber]);
                }
            }

            foreach ($plans as $plan) {
                $record = $plan['record'];
                if ($plan['reception_id'] !== (int) $record->recepcion_id) {
                    $before = ['recepcion_id' => (int) $record->recepcion_id, 'numero' => (int) $record->numero];
                    $after = ['recepcion_id' => $plan['reception_id'], 'numero' => $plan['number']];
                    DB::table('pesadas_recepcion_pollo_vivo')->where('id', $record->id)
                        ->update([...$after, 'updated_at' => $this->nextVersion($record)]);
                    $this->audit->record($companyId, $actorId, 'pesadas_recepcion_pollo_vivo', $record->id,
                        'RECLASIFICAR_JORNADA', $before, $after);
                    $counts['reception_weighings']++;
                    $counts['reception_numbers'] += (int) ($plan['number'] !== (int) $record->numero);
                }

                $movements = DB::table('movimientos_javas')->where('empresa_id', $companyId)
                    ->where('pesada_recepcion_pollo_vivo_id', $record->id)->lockForUpdate()->get();
                foreach ($movements as $movement) {
                    if ((int) $movement->jornada_id === $plan['journey_id']) {
                        continue;
                    }
                    $before = ['jornada_id' => $movement->jornada_id === null ? null : (int) $movement->jornada_id];
                    $after = ['jornada_id' => $plan['journey_id']];
                    DB::table('movimientos_javas')->where('id', $movement->id)
                        ->update([...$after, 'updated_at' => $this->nextVersion($movement)]);
                    $this->audit->record($companyId, $actorId, 'movimientos_javas', $movement->id,
                        'RECLASIFICAR_JORNADA', $before, $after);
                    $counts['reception_java_movements']++;
                }
            }

            return $counts;
        });
    }

    private function nextVersion(object $record): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return isset($record->updated_at)
            ? $now->max(CarbonImmutable::parse($record->updated_at)->addSecond())
            : $now;
    }
}
