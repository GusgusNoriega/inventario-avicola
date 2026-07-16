<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AccessModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessModuleController extends AccessManagementController
{
    public function index(Request $request, AccessModuleRegistry $modules): JsonResponse
    {
        $actor = $this->actor($request);
        $branches = DB::table('sucursales')
            ->where('empresa_id', $actor->empresa_id)
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'estado'])
            ->map(fn (object $branch): array => [
                'id' => (int) $branch->id,
                'code' => $branch->codigo,
                'name' => $branch->nombre,
                'status' => $branch->estado,
            ])
            ->all();

        return response()->json([
            'data' => [
                'modules' => $modules->catalogue(),
                'branches' => $branches,
            ],
        ]);
    }
}
