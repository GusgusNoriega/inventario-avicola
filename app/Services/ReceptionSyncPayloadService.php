<?php

namespace App\Services;

use App\Models\ReceptionSyncRecord;
use App\Models\ReceptionSyncToken;
use App\Models\TerceroRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Validates captured facts without invoking inventory, dispatch or finance services. */
class ReceptionSyncPayloadService
{
    public function __construct(private readonly ReceptionSyncJourneyReclassificationService $journeys) {}

    public function normalize(ReceptionSyncToken $token, object $branch, string $kind, array $payload, ?ReceptionSyncRecord $previous): array
    {
        $data = Validator::make($payload, [
            'operating_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'operating_cutoff' => ['required', 'date_format:H:i:s'],
            'lane' => ['required', 'integer', Rule::in($kind === 'ticket' ? [5, 6] : [1, 2, 3, 4])],
            'origin' => ['sometimes', 'string', 'max:80'],
            'destination_id' => ['required', 'integer', 'min:1'],
            'external_owner_id' => ['nullable', 'integer', 'min:1'],
            'delivery_vehicle_id' => ['nullable', 'integer', 'min:1', 'required_with:delivery_driver_id'],
            'delivery_driver_id' => ['nullable', 'integer', 'min:1', 'required_with:delivery_vehicle_id'],
            'local_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'weighings' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'weighings.*' => ['required', 'array:uuid,sex,cage_type_id,cage_weight_kg,birds_per_cage,cage_count,read_weight_kg,weight_source,weighed_at,status,void_reason'],
            'weighings.*.uuid' => ['required', 'uuid', 'distinct:ignore_case'],
            'weighings.*.sex' => ['required', Rule::in(['MACHO', 'HEMBRA'])],
            'weighings.*.cage_type_id' => ['required', 'integer', 'min:1'],
            'weighings.*.cage_weight_kg' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,3'],
            'weighings.*.birds_per_cage' => ['required', 'integer', 'min:1', 'max:1000'],
            'weighings.*.cage_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,3'],
            'weighings.*.weight_source' => ['required', Rule::in(['MANUAL', 'BALANZA'])],
            'weighings.*.weighed_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'weighings.*.status' => ['sometimes', Rule::in(['active', 'voided'])],
            'weighings.*.void_reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $unknown = array_diff(array_keys($payload), array_keys($data));
        $this->check($unknown === [], 'payload', 'El contenido contiene campos no admitidos.');
        $lane = (int) $data['lane'];
        $external = in_array($lane, [3, 4], true);
        $this->check($external || empty($data['external_owner_id']), 'external_owner_id', 'Solo las columnas externas admiten propietario externo.');
        $this->check($kind === 'ticket' || (empty($data['delivery_vehicle_id']) && empty($data['delivery_driver_id'])), 'delivery_vehicle_id', 'La entrega solo corresponde a tickets.');
        $cutoff = substr((string) (DB::table('empresas')->where('id', $token->empresa_id)
            ->sharedLock()->value('hora_corte_operativo') ?: '21:00:00'), 0, 5).':00';

        // Inactive catalog entries remain valid for facts captured before deactivation.
        $destination = $kind === 'ticket'
            ? $this->party((int) $token->empresa_id, (int) $data['destination_id'], TerceroRole::CLIENT, 'destination_id')
            : DB::table('almacenes')->where('sucursal_id', $token->sucursal_id)->where('id', $data['destination_id'])->first();
        $this->check($destination !== null, 'destination_id', 'El destino no pertenece a la sucursal autorizada.');
        $owner = $external ? $this->party((int) $token->empresa_id, (int) ($data['external_owner_id'] ?? 0), TerceroRole::PROVIDER, 'external_owner_id') : null;
        $vehicle = ! empty($data['delivery_vehicle_id']) ? $this->companyCatalog('vehiculos', (int) $token->empresa_id, (int) $data['delivery_vehicle_id'], 'delivery_vehicle_id') : null;
        $driver = ! empty($data['delivery_driver_id']) ? $this->companyCatalog('conductores', (int) $token->empresa_id, (int) $data['delivery_driver_id'], 'delivery_driver_id') : null;
        $cages = DB::table('tipos_java')->whereIn('id', array_column($data['weighings'], 'cage_type_id'))->get()->keyBy('id');
        $oldLines = collect($previous?->payload['weighings'] ?? [])->keyBy('uuid');
        $lines = [];
        foreach ($data['weighings'] as $index => $line) {
            $prefix = "weighings.{$index}";
            $uuid = strtolower($line['uuid']);
            $old = $oldLines->get($uuid);
            $status = $line['status'] ?? 'active';
            $this->check($status !== 'voided' || filled(trim((string) ($line['void_reason'] ?? ''))), "{$prefix}.void_reason", 'Indica el motivo de anulación.');
            $this->check(! $old || $old['status'] !== 'voided' || $status === 'voided', "{$prefix}.status", 'No se puede reactivar una pesada anulada.');
            $type = $cages->get((int) $line['cage_type_id']);
            $this->check($type !== null, "{$prefix}.cage_type_id", 'El tipo de tara no existe. Descarga el catálogo y revisa el registro.');
            $this->check($kind === 'ticket' || $line['sex'] === ($lane % 2 === 1 ? 'MACHO' : 'HEMBRA'), "{$prefix}.sex", 'El sexo no corresponde a la columna.');
            $at = CarbonImmutable::parse($line['weighed_at'])->setTimezone($branch->zona_horaria);
            $this->check($at->lessThanOrEqualTo(CarbonImmutable::now()->addMinutes(5)), "{$prefix}.weighed_at", 'La fecha de captura no puede estar en el futuro.');
            $this->check($at->format('Y-m-d') >= '2000-01-01', "{$prefix}.weighed_at", 'La fecha de captura debe ser posterior al año 1999.');
            // Integer grams make server and mobile calculations deterministic.
            $gross = (int) round((float) $line['read_weight_kg'] * 1000);
            $cageGrams = (int) round((float) $line['cage_weight_kg'] * 1000);
            $tare = (int) $line['cage_count'] * $cageGrams;
            $this->check($gross > $tare, "{$prefix}.read_weight_kg", 'El peso bruto debe ser mayor que la tara total.');
            $lines[] = [
                'uuid' => $uuid,
                'number' => $index + 1,
                'sex' => $line['sex'],
                'cage_type_id' => (int) $type->id,
                'cage_type_name' => $old && $old['cage_type_id'] === (int) $type->id ? $old['cage_type_name'] : $type->nombre,
                'cage_weight_kg' => $cageGrams / 1000,
                'birds_per_cage' => (int) $line['birds_per_cage'],
                'cage_count' => (int) $line['cage_count'],
                'birds' => (int) $line['birds_per_cage'] * (int) $line['cage_count'],
                'read_weight_kg' => $gross / 1000,
                'gross_weight_kg' => $gross / 1000,
                'tare_weight_kg' => $tare / 1000,
                'net_weight_kg' => ($gross - $tare) / 1000,
                'weight_source' => $line['weight_source'],
                'weighed_at' => $at->toISOString(),
                'status' => $status,
                'void_reason' => $status === 'voided' ? trim($line['void_reason']) : null,
            ];
        }
        $this->check($oldLines->keys()->diff(array_column($lines, 'uuid'))->isEmpty(), 'weighings', 'No omitas pesadas anteriores: envíalas con estado voided y su motivo.');
        foreach ($lines as $line) {
            $old = $oldLines->get($line['uuid']);
            if ($old && $old['status'] === 'voided') {
                $before = $old;
                $after = $line;
                unset($before['number'], $after['number']);
                $this->check($before == $after, 'weighings', 'Las pesadas anuladas conservan sus datos históricos.');
            }
        }
        $active = array_values(array_filter($lines, fn (array $line): bool => $line['status'] === 'active'));
        $this->check($active !== [], 'weighings', 'Para anular todas las pesadas utiliza la acción void del registro.');
        $oldPayload = $previous?->payload ?? [];
        // Offline devices can upload after the company changes its schedule. Derive
        // the whole record from its first weighing using the current server cutoff.
        $date = $this->journeys->operatingDate($lines, $branch->zona_horaria, $cutoff);
        if ($previous) {
            $this->check($date === $previous->operating_date->format('Y-m-d'), 'operating_date', 'Una corrección no puede cambiar la jornada; anula el registro y crea otro.');
        }

        return [
            'operating_date' => $date,
            'operating_cutoff' => $cutoff,
            'lane' => $lane,
            'origin' => $data['origin'] ?? 'Camión del día',
            'local_number' => $data['local_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'destination_id' => (int) $destination->id,
            'destination' => [
                'id' => (int) $destination->id,
                'type' => $kind === 'ticket' ? 'CLIENTE' : 'ALMACEN',
                'name' => ($oldPayload['destination_id'] ?? null) === (int) $destination->id ? $oldPayload['destination']['name'] : ($destination->nombre_razon_social ?? $destination->nombre),
            ],
            'external_owner_id' => $owner ? (int) $owner->id : null,
            'owner' => ['type' => $external ? 'EXTERNA' : 'PROPIA', 'id' => $owner ? (int) $owner->id : null, 'name' => $owner ? (($oldPayload['external_owner_id'] ?? null) === (int) $owner->id ? $oldPayload['owner']['name'] : $owner->nombre_razon_social) : 'Mi empresa'],
            'delivery_vehicle_id' => $vehicle ? (int) $vehicle->id : null,
            'delivery_driver_id' => $driver ? (int) $driver->id : null,
            'delivery' => $vehicle ? ['vehicle' => ['id' => (int) $vehicle->id, 'plate' => $vehicle->placa], 'driver' => ['id' => (int) $driver->id, 'name' => $driver->nombre_completo]] : null,
            'weighings' => $lines,
            'totals' => $this->totals($active),
        ];
    }

    public function totals(array $lines): array
    {
        $rows = collect($lines);

        return [
            'weighings' => $rows->count(),
            'cages' => (int) $rows->sum('cage_count'),
            'birds' => (int) $rows->sum('birds'),
            'male_birds' => (int) $rows->where('sex', 'MACHO')->sum('birds'),
            'female_birds' => (int) $rows->where('sex', 'HEMBRA')->sum('birds'),
            'gross_weight_kg' => round((float) $rows->sum('gross_weight_kg'), 3),
            'tare_weight_kg' => round((float) $rows->sum('tare_weight_kg'), 3),
            'net_weight_kg' => round((float) $rows->sum('net_weight_kg'), 3),
        ];
    }

    private function party(int $company, int $id, string $role, string $field): object
    {
        $party = DB::table('terceros')->where('empresa_id', $company)->where('id', $id)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('tercero_roles')->whereColumn('tercero_id', 'terceros.id')->where('rol', $role))->first();
        $this->check($party !== null, $field, 'El tercero no existe en la empresa autorizada o no tiene el rol requerido.');

        return $party;
    }

    private function companyCatalog(string $table, int $company, int $id, string $field): object
    {
        $row = DB::table($table)->where('empresa_id', $company)->where('id', $id)->first();
        $this->check($row !== null, $field, 'El registro no pertenece a la empresa autorizada.');

        return $row;
    }

    private function check(bool $valid, string $field, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
