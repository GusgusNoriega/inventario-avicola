<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Services\FinancialCounterpartySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialCounterpartyController extends Controller
{
    public function __construct(
        private readonly FinancialCounterpartySummaryService $summaries,
    ) {}

    public function customer(Request $request, int $tercero): JsonResponse
    {
        $customer = $this->thirdParty($request, $tercero, TerceroRole::CLIENT);

        return response()->json([
            'data' => $this->summaries->forCustomer(
                (int) $customer->empresa_id,
                (int) $customer->id,
            ),
        ]);
    }

    public function provider(Request $request, int $tercero): JsonResponse
    {
        $provider = $this->thirdParty($request, $tercero, TerceroRole::PROVIDER);

        return response()->json([
            'data' => $this->summaries->forProvider(
                (int) $provider->empresa_id,
                (int) $provider->id,
            ),
        ]);
    }

    private function thirdParty(Request $request, int $thirdPartyId, string $role): Tercero
    {
        return Tercero::query()
            ->where('empresa_id', (int) $request->user()->empresa_id)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol($role)
            ->findOrFail($thirdPartyId);
    }
}
