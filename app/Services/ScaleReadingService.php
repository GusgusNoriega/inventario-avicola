<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\LecturaBalanza;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScaleReadingService
{
    /**
     * @param  array<string, mixed>  $weighing
     */
    public function record(
        int $branchId,
        User $actor,
        array $weighing,
        DateTimeInterface $fallbackCapturedAt,
        string $field
    ): ?LecturaBalanza {
        $source = (string) ($weighing['weight_source'] ?? 'MANUAL');

        if ($source === 'MANUAL') {
            return null;
        }

        $logicalName = Balanza::logicalName($source);
        if (! $logicalName) {
            throw ValidationException::withMessages([
                "{$field}.weight_source" => 'La balanza seleccionada no esta disponible.',
            ]);
        }

        $now = now();
        DB::table('balanzas')->insertOrIgnore([
            'sucursal_id' => $branchId,
            'codigo' => $source,
            'nombre' => $logicalName,
            'modo_conexion' => null,
            'dispositivo' => null,
            'configuracion' => null,
            'estado' => Balanza::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $scale = Balanza::query()
            ->where('sucursal_id', $branchId)
            ->where('codigo', $source)
            ->lockForUpdate()
            ->first();

        if (! $scale) {
            throw ValidationException::withMessages([
                "{$field}.weight_source" => 'La balanza seleccionada no pudo inicializarse.',
            ]);
        }

        if ($scale->estado !== Balanza::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                "{$field}.weight_source" => 'La balanza seleccionada esta inactiva.',
            ]);
        }

        $metadata = is_array($weighing['scale_reading'] ?? null)
            ? $weighing['scale_reading']
            : [];
        $fallbackTimezone = $fallbackCapturedAt->getTimezone();
        $timezone = $fallbackTimezone !== false
            ? $fallbackTimezone
            : (string) config('app.timezone');
        $capturedAt = array_key_exists('captured_at', $metadata)
            && filled($metadata['captured_at'])
                ? CarbonImmutable::parse((string) $metadata['captured_at'])->setTimezone($timezone)
                : CarbonImmutable::instance($fallbackCapturedAt)->setTimezone($timezone);

        return LecturaBalanza::query()->create([
            'balanza_id' => $scale->id,
            'peso_kg' => round((float) $weighing['read_weight_kg'], 3),
            'trama_cruda' => $metadata['raw_frame'] ?? null,
            'modo_conexion' => $metadata['connection_mode'] ?? null,
            'dispositivo' => $metadata['device_name'] ?? null,
            'capturada_at' => $capturedAt,
            'capturada_por' => $actor->id,
        ]);
    }
}
