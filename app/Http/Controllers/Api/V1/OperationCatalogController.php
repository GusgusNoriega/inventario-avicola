<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TipoPollo;
use App\Services\OperationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationCatalogController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $branch = $this->context->branch($request);

        return response()->json([
            'data' => [
                'branch' => [
                    'id' => $branch->id,
                    'code' => $branch->codigo,
                    'name' => $branch->nombre,
                    'timezone' => $branch->zona_horaria,
                ],
                'warehouses' => DB::table('almacenes')
                    ->where('sucursal_id', $branch->id)
                    ->where('estado', 'ACTIVO')
                    ->orderBy('id')
                    ->get(['id', 'codigo', 'nombre', 'direccion'])
                    ->map(fn (object $warehouse) => [
                        'id' => $warehouse->id,
                        'code' => $warehouse->codigo,
                        'name' => $warehouse->nombre,
                        'address' => $warehouse->direccion,
                    ])
                    ->values(),
                'chicken_types' => TipoPollo::query()
                    ->where('estado', TipoPollo::STATUS_ACTIVE)
                    ->where('permite_despacho', true)
                    ->orderBy('id')
                    ->get(['id', 'codigo', 'nombre'])
                    ->map(fn (TipoPollo $type) => [
                        'id' => $type->id,
                        'code' => $type->codigo,
                        'name' => $type->nombre,
                    ])
                    ->values(),
                'cage_types' => DB::table('tipos_java')
                    ->where('estado', 'ACTIVO')
                    ->orderBy('id')
                    ->get(['id', 'codigo', 'nombre', 'peso_kg'])
                    ->map(fn (object $type) => [
                        'id' => $type->id,
                        'code' => $type->codigo,
                        'name' => $type->nombre,
                        'weight_kg' => (float) $type->peso_kg,
                    ])
                    ->values(),
            ],
        ]);
    }
}
