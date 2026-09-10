<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\JourneyPlanService;
use App\Services\OperationContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReceptionSyncRecordsWebController extends Controller
{
    public function index(Request $request, OperationContextService $context): View
    {
        $branch = $context->branch($request);
        $operatingDate = app(JourneyPlanService::class)->currentWindow($context->companyId($request), $branch)['operating_date'];
        $request->merge([
            'date_from' => $request->input('date_from') ?: $operatingDate->startOfMonth()->toDateString(),
            'date_to' => $request->input('date_to') ?: $operatingDate->toDateString(),
        ]);
        $filters = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'kind' => ['nullable', Rule::in(['reception', 'ticket'])],
            'status' => ['nullable', Rule::in(['active', 'voided'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = DB::table('reception_sync_records')
            ->where('company_id', $context->companyId($request))
            ->where('branch_id', $branch->id)
            ->whereBetween('operating_date', [$filters['date_from'], $filters['date_to']])
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        $summary = ['records' => 0, 'active_records' => 0, 'voided_records' => 0, 'birds' => 0, 'cages' => 0, 'gross_weight_kg' => 0, 'tare_weight_kg' => 0, 'net_weight_kg' => 0];
        foreach ((clone $query)->select(['status', 'payload'])->cursor() as $row) {
            $summary['records']++;
            if ($row->status === 'voided') {
                $summary['voided_records']++;

                continue;
            }
            $summary['active_records']++;
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            foreach (['birds', 'cages', 'gross_weight_kg', 'tare_weight_kg', 'net_weight_kg'] as $key) {
                $summary[$key] += (float) ($payload['totals'][$key] ?? 0);
            }
        }

        $records = $query->orderByDesc('operating_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $records->through(function ($record) {
            $record->payload = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);

            return $record;
        });

        return view('recepcion-pollo-vivo-sincronizados', compact('branch', 'filters', 'summary', 'records'));
    }
}
