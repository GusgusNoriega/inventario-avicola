<?php

namespace App\Services;

use App\Models\ReceptionSyncRecord;
use App\Models\ReceptionSyncToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReceptionSyncService
{
    public function __construct(
        private readonly ReceptionSyncPayloadService $payloads,
        private readonly ReceptionSyncFinancialService $finances,
    ) {}

    public function push(ReceptionSyncToken $token, object $branch, array $operations): array
    {
        $results = [];
        foreach ($operations as $input) {
            try {
                $operation = Validator::make(['operation' => $input], [
                    'operation' => ['required', 'array:operation_id,entity_id,kind,action,expected_revision,payload,reason'],
                    'operation.operation_id' => ['required', 'uuid'],
                    'operation.entity_id' => ['required', 'uuid'],
                    'operation.kind' => ['required', Rule::in(['reception', 'ticket'])],
                    'operation.action' => ['required', Rule::in(['upsert', 'void'])],
                    'operation.expected_revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
                    'operation.payload' => ['required_if:operation.action,upsert', 'prohibited_if:operation.action,void', 'array'],
                    'operation.reason' => ['nullable', 'string', 'max:500'],
                ])->validate()['operation'];
                $operation['operation_id'] = strtolower($operation['operation_id']);
                $operation['entity_id'] = strtolower($operation['entity_id']);
                $results[] = $this->apply($token, $branch, $operation);
            } catch (ValidationException $exception) {
                $results[] = [
                    'operation_id' => is_array($input) ? ($input['operation_id'] ?? null) : null,
                    'entity_id' => is_array($input) ? ($input['entity_id'] ?? null) : null,
                    'status' => 'rejected', 'http_status' => 422,
                    'message' => 'Revisa los datos de esta operación.', 'errors' => $exception->errors(),
                ];
            }
        }

        return ['results' => $results, 'has_errors' => collect($results)->contains(fn (array $r): bool => $r['http_status'] >= 400)];
    }

    private function apply(ReceptionSyncToken $token, object $branch, array $operation): array
    {
        return DB::transaction(function () use ($token, $branch, $operation): array {
            // Schedule changes and device uploads share the same company mutex.
            DB::table('empresas')->where('id', $token->empresa_id)->lockForUpdate()->firstOrFail();
            // Serialize writes across ALL devices in the branch, including inserts and retries.
            $branch = DB::table('sucursales')->where('id', $token->sucursal_id)
                ->where('empresa_id', $token->empresa_id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($this->canonical($operation), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $logQuery = DB::table('reception_sync_operations')->where('company_id', $token->empresa_id)
                ->where('branch_id', $token->sucursal_id)->where('device_id', $token->device_id)
                ->where('operation_id', $operation['operation_id']);
            $prior = $logQuery->first();
            $base = ['operation_id' => $operation['operation_id'], 'entity_id' => $operation['entity_id']];
            if ($prior) {
                if (! hash_equals($prior->request_hash, $hash)) {
                    return [...$base, 'status' => 'conflict', 'http_status' => 409, 'message' => 'El operation_id ya se utilizó con otro contenido. Crea una operación nueva.'];
                }
                $result = json_decode($prior->result, true, 512, JSON_THROW_ON_ERROR);
                if ($result['status'] === 'applied') {
                    $result['status'] = 'replayed';
                    $result['http_status'] = 200;
                }
                if (isset($result['record'])) {
                    // Keep the receipt immutable while returning today's record state:
                    // an old retry must not restore obsolete schedule data on a device.
                    $result['record'] = $this->scope($token)->where('uuid', $operation['entity_id'])->firstOrFail()->document();
                }
                $result['replayed'] = true;

                return $result;
            }
            $record = $this->scope($token)->where('uuid', $operation['entity_id'])->lockForUpdate()->first();
            $expected = (int) $operation['expected_revision'];
            if (($record && ($record->revision !== $expected || $record->kind !== $operation['kind'] || $record->status === 'voided')) || (! $record && ($expected !== 0 || $operation['action'] === 'void'))) {
                $result = [...$base, 'status' => 'conflict', 'http_status' => 409, 'message' => 'El registro cambió, fue anulado o no existe con esa revisión. Descarga su estado y resuelve el conflicto.', 'record' => $record?->document()];
            } else {
                try {
                    // A rejected valuation must roll back the captured record and its keys too.
                    // Keep the operation receipt outside this savepoint so rejection is replayable.
                    $result = DB::transaction(function () use ($token, $branch, $operation, $record, $expected, $base): array {
                        if (($record || $operation['action'] === 'void') && blank(trim((string) ($operation['reason'] ?? '')))) {
                            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la corrección o anulación.']);
                        }
                        if ($operation['action'] === 'void') {
                            $payload = $record->payload;
                            $payload['void_reason'] = trim($operation['reason']);
                            $payload['voided_at'] = now()->toISOString();
                            $payload['voided_by'] = (int) $token->user_id;
                            $record->update(['status' => 'voided', 'revision' => $record->revision + 1, 'payload' => $payload]);
                        } else {
                            $payload = $this->payloads->normalize($token, $branch, $operation['kind'], $operation['payload'], $record);
                            $weighingIds = array_column($payload['weighings'], 'uuid');
                            $duplicate = DB::table('reception_sync_weighing_keys')->where('branch_id', $token->sucursal_id)
                                ->whereIn('uuid', $weighingIds)->when($record, fn ($q) => $q->where('record_id', '!=', $record->id))->exists();
                            if ($duplicate) {
                                throw ValidationException::withMessages(['weighings' => 'Una de las pesadas ya pertenece a otro registro de la sucursal.']);
                            }
                            if ($record) {
                                $record->update(['revision' => $record->revision + 1, 'operating_date' => $payload['operating_date'], 'payload' => $payload]);
                            } else {
                                $record = ReceptionSyncRecord::query()->create([
                                    'company_id' => (int) $token->empresa_id, 'branch_id' => (int) $token->sucursal_id,
                                    'device_id' => $token->device_id, 'uuid' => $operation['entity_id'],
                                    'kind' => $operation['kind'], 'operating_date' => $payload['operating_date'],
                                    'status' => 'active', 'revision' => 1, 'payload' => $payload, 'created_by' => (int) $token->user_id,
                                ]);
                            }
                            foreach ($weighingIds as $uuid) {
                                DB::table('reception_sync_weighing_keys')->insertOrIgnore(['branch_id' => (int) $token->sucursal_id, 'record_id' => (int) $record->id, 'uuid' => $uuid]);
                            }
                        }
                        $this->finances->sync($record->fresh(), $token->user);

                        return [...$base, 'status' => 'applied', 'http_status' => $expected === 0 ? 201 : 200, 'record' => $record->fresh()->document()];
                    });
                } catch (ValidationException $exception) {
                    $result = [...$base, 'status' => 'rejected', 'http_status' => 422, 'message' => 'Revisa los datos de esta operación.', 'errors' => $exception->errors()];
                }
            }
            // The result and record commit together: a lost HTTP response can be retried safely.
            DB::table('reception_sync_operations')->insert([
                'company_id' => (int) $token->empresa_id, 'branch_id' => (int) $token->sucursal_id,
                'device_id' => $token->device_id, 'operation_id' => $operation['operation_id'],
                'entity_id' => $operation['entity_id'], 'request_hash' => $hash,
                'request' => json_encode($operation, JSON_THROW_ON_ERROR), 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'actor_id' => (int) $token->user_id, 'created_at' => now(),
            ]);

            return $result;
        }, 3);
    }

    public function listRecords(ReceptionSyncToken $token, array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 100);
        $rows = $this->filtered($token, $filters)->where('id', '>', (int) ($filters['after'] ?? 0))->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return ['records' => $page->map(fn (ReceptionSyncRecord $row): array => $row->document())->values()->all(), 'has_more' => $hasMore, 'next_after' => $hasMore ? (int) $page->last()->id : null];
    }

    public function findRecord(ReceptionSyncToken $token, string $uuid): array
    {
        return $this->scope($token)->where('uuid', strtolower($uuid))->firstOrFail()->document();
    }

    public function report(ReceptionSyncToken $token, array $filters): array
    {
        $totals = $this->payloads->totals([]);
        $groups = ['by_day' => [], 'by_client' => [], 'by_owner' => [], 'by_sex' => [], 'by_lane' => []];
        $counts = ['active' => 0, 'voided' => 0];
        foreach ($this->filtered($token, $filters)->lazyById(200) as $record) {
            $counts[$record->status]++;
            if ($record->status === 'voided') {
                continue;
            }
            $payload = $record->payload;
            foreach ($payload['weighings'] as $line) {
                if ($line['status'] !== 'active') {
                    continue;
                }
                $lineTotals = $this->payloads->totals([$line]);
                $totals = $this->addTotals($totals, $lineTotals);
                $day = $record->operating_date->format('Y-m-d');
                $lane = $payload['lane'];
                $this->accumulate($groups['by_day'], $day, ['date' => $day], $lineTotals);
                $this->accumulate($groups['by_sex'], $line['sex'], ['sex' => $line['sex']], $lineTotals);
                $this->accumulate($groups['by_lane'], (string) $lane, ['lane' => $lane], $lineTotals);
                $owner = $payload['owner'];
                $this->accumulate($groups['by_owner'], $owner['type'].':'.($owner['id'] ?? 0), $owner, $lineTotals);
                if ($record->kind === 'ticket') {
                    $this->accumulate($groups['by_client'], (string) $payload['destination_id'], $payload['destination'], $lineTotals);
                }
            }
        }
        foreach ($groups as &$group) {
            ksort($group);
            $group = array_values($group);
        }

        return ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'scope' => 'offline_records', 'record_counts' => $counts, 'totals' => $totals, ...$groups];
    }

    private function accumulate(array &$group, string $key, array $labels, array $totals): void
    {
        $group[$key] = [...$labels, 'totals' => $this->addTotals($group[$key]['totals'] ?? $this->payloads->totals([]), $totals)];
    }

    private function addTotals(array $left, array $right): array
    {
        foreach ($left as $key => $value) {
            $left[$key] = str_ends_with($key, '_kg') ? round($value + $right[$key], 3) : $value + $right[$key];
        }

        return $left;
    }

    private function filtered(ReceptionSyncToken $token, array $filters): Builder
    {
        return $this->scope($token)
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->where('operating_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->where('operating_date', '<=', $filters['date_to']))
            ->when(isset($filters['kind']), fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']));
    }

    private function scope(ReceptionSyncToken $token): Builder
    {
        return ReceptionSyncRecord::query()->where('company_id', $token->empresa_id)->where('branch_id', $token->sucursal_id);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
