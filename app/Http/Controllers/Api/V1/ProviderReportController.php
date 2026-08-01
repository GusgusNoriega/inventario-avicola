<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OperationContextService;
use App\Services\ProviderReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderReportController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProviderReportService $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'jornada' => ['nullable', 'date_format:Y-m-d'],
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'camion' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $branch = $this->context->branch($request);

        return response()->json([
            'data' => $this->reports->report(
                $this->context->companyId($request),
                $branch,
                $filters,
            ),
        ]);
    }
}
