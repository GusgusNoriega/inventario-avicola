<?php

namespace App\Services;

use App\Models\ReceptionSyncRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReceptionSyncJourneyReclassificationService
{
    /** The caller holds the company lock and includes this work in its transaction. */
    public function reclassify(int $companyId, string $cutoff): int
    {
        $cutoff = substr($cutoff, 0, 5).':00';
        $timezones = DB::table('sucursales')->where('empresa_id', $companyId)
            ->pluck('zona_horaria', 'id');
        $changed = 0;

        foreach (ReceptionSyncRecord::query()->where('company_id', $companyId)->lazyById(200) as $record) {
            $payload = $record->payload;
            $date = $this->operatingDate(
                $payload['weighings'] ?? [],
                (string) $timezones->get($record->branch_id),
                $cutoff,
                $record->created_at,
            );
            $oldDate = $record->operating_date->format('Y-m-d');
            if ($oldDate === $date && ($payload['operating_date'] ?? null) === $date
                && ($payload['operating_cutoff'] ?? null) === $cutoff) {
                continue;
            }

            // Keep every captured fact and local identifier, including voided weighings.
            $payload['operating_date'] = $date;
            $payload['operating_cutoff'] = $cutoff;
            $record->update([
                'operating_date' => $date,
                'payload' => $payload,
                'revision' => $record->revision + 1,
            ]);
            if ($record->kind === 'ticket' && $oldDate !== $date) {
                $this->moveDocumentDates($record, $oldDate, $date);
            }
            $changed++;
        }

        // Immutable pages cannot be patched without invalidating a resumed download.
        // Expiration makes every device restart from a complete, consistent snapshot.
        $previousCutoff = DB::table('empresas')->where('id', $companyId)->value('hora_corte_operativo');
        if ($changed > 0 || $previousCutoff !== $cutoff) {
            DB::table('reception_sync_snapshots')->where('company_id', $companyId)
                ->where('expires_at', '>', now())->update(['expires_at' => now()->subSecond()]);
        }

        return $changed;
    }

    public function operatingDate(array $weighings, string $timezone, string $cutoff, ?CarbonInterface $fallback = null): string
    {
        $first = null;
        foreach ($weighings as $weighing) {
            $at = CarbonImmutable::parse($weighing['weighed_at']);
            if ($first === null || $at->lessThan($first)) {
                $first = $at;
            }
        }
        $at = ($first ?? CarbonImmutable::instance($fallback ?? now()))->setTimezone($timezone);

        return ($at->format('H:i:s') >= $cutoff ? $at->addDay() : $at)->format('Y-m-d');
    }

    private function moveDocumentDates(ReceptionSyncRecord $record, string $oldDate, string $date): void
    {
        // These internal documents derive their dates from the operating day. Do not
        // revalue them or touch payment applications, balances, prices or custom dates.
        $documents = DB::table('comprobantes')->where('empresa_id', $record->company_id)
            ->where('origen_clave', 'VENTA:RECEPCION_SYNC:'.$record->id)
            ->where('tipo_documento', 'INTERNO')
            ->where('origen_codigo', 'AUTOMATICO')->lockForUpdate()->get();
        foreach ($documents as $document) {
            $updates = [];
            foreach (['fecha_emision', 'fecha_vencimiento'] as $field) {
                if ($document->{$field} === $oldDate) {
                    $updates[$field] = $date;
                }
            }
            if ($updates !== []) {
                DB::table('comprobantes')->where('id', $document->id)->update([...$updates, 'updated_at' => now()]);
            }
        }
    }
}
