<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReceptionSyncService;
use App\Services\ReceptionSyncSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReceptionSyncController extends Controller
{
    public function __construct(private readonly ReceptionSyncService $sync) {}

    public function status(Request $request): JsonResponse
    {
        $token = $request->attributes->get('reception_sync_token');

        return response()->json(['data' => [
            'schema_version' => 1, 'server_time' => now()->toISOString(),
            'company_id' => (int) $token->empresa_id, 'branch_id' => (int) $token->sucursal_id,
            'device_id' => $token->device_id, 'timezone' => $request->attributes->get('reception_sync_branch')->zona_horaria,
            'limits' => ['batch_operations' => 50, 'weighings_per_record' => 200, 'request_bytes' => 2097152, 'snapshot_page_size' => 200],
            'capabilities' => ['full_snapshot' => true, 'incremental_download' => false, 'cross_device_revision_control' => true, 'offline_reports' => true, 'inventory_movements' => false, 'financial_movements' => true, 'server_client_debt' => true, 'client_financial_data' => false],
        ]]);
    }

    public function snapshot(Request $request, ReceptionSyncSnapshotService $snapshots): JsonResponse
    {
        return response()->json(['data' => $snapshots->create($request->attributes->get('reception_sync_token'), $request->attributes->get('reception_sync_branch'))], 201);
    }

    public function snapshots(Request $request, ReceptionSyncSnapshotService $snapshots): JsonResponse
    {
        return response()->json(['data' => ['snapshots' => $snapshots->listActive($request->attributes->get('reception_sync_token'))]]);
    }

    public function releaseSnapshot(Request $request, ReceptionSyncSnapshotService $snapshots, string $snapshot): Response
    {
        $snapshots->release($request->attributes->get('reception_sync_token'), $snapshot);

        return response()->noContent();
    }

    public function snapshotPage(Request $request, ReceptionSyncSnapshotService $snapshots, string $snapshot): JsonResponse
    {
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:200']]);

        return response()->json(['data' => $snapshots->page($request->attributes->get('reception_sync_token'), $snapshot, (int) ($data['after'] ?? 0), (int) ($data['limit'] ?? 200))]);
    }

    public function push(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 2097152, 413, 'El lote supera el máximo de 2 MiB.');
        $data = $request->validate(['operations' => ['required', 'array', 'list', 'min:1', 'max:50']]);

        return response()->json(['data' => $this->sync->push($request->attributes->get('reception_sync_token'), $request->attributes->get('reception_sync_branch'), $data['operations'])]);
    }

    public function records(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sync->listRecords($request->attributes->get('reception_sync_token'), $this->filters($request))]);
    }

    public function record(Request $request, string $record): JsonResponse
    {
        return response()->json(['data' => $this->sync->findRecord($request->attributes->get('reception_sync_token'), $record)]);
    }

    public function report(Request $request): JsonResponse
    {
        $filters = $this->filters($request, true);
        if (CarbonImmutable::parse($filters['date_from'])->diffInDays(CarbonImmutable::parse($filters['date_to'])) > 366) {
            throw ValidationException::withMessages(['date_to' => 'Consulta como máximo 366 días por reporte.']);
        }

        return response()->json(['data' => $this->sync->report($request->attributes->get('reception_sync_token'), $filters)]);
    }

    private function filters(Request $request, bool $report = false): array
    {
        return $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'date_from' => [$report ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'date_to' => [$report ? 'required' : 'sometimes', 'date_format:Y-m-d', ...($request->has('date_from') ? ['after_or_equal:date_from'] : [])],
            'kind' => ['sometimes', Rule::in(['reception', 'ticket'])], 'status' => ['sometimes', Rule::in(['active', 'voided'])],
        ]);
    }
}
