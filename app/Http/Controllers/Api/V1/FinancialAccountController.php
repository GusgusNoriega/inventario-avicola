<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinancialAccountRequest;
use App\Http\Requests\Finance\UpdateFinancialAccountRequest;
use App\Services\FinancialDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAccountController extends Controller
{
    public function __construct(private readonly FinancialDirectoryService $directory) {}

    public function store(StoreFinancialAccountRequest $request, int $entidad): JsonResponse
    {
        $account = $this->directory->createAccount(
            (int) $request->user()->empresa_id,
            $request->user(),
            $entidad,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Cuenta financiera creada correctamente.',
            'data' => $account,
        ], 201);
    }

    public function update(UpdateFinancialAccountRequest $request, int $cuenta): JsonResponse
    {
        $account = $this->directory->updateAccount(
            (int) $request->user()->empresa_id,
            $request->user(),
            $cuenta,
            $request->validated(),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Cuenta financiera actualizada correctamente.',
            'data' => $account,
        ]);
    }

    public function destroy(Request $request, int $cuenta): JsonResponse
    {
        $this->directory->deactivateAccount(
            (int) $request->user()->empresa_id,
            $request->user(),
            $cuenta,
            $request->ip(),
        );

        return response()->json(['message' => 'Cuenta financiera desactivada correctamente.']);
    }
}
