<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\ListProductDispatchClientsRequest;
use App\Http\Requests\ProductDispatch\StoreProductDispatchClientRequest;
use App\Http\Requests\ProductDispatch\UpdateProductDispatchClientRequest;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Services\OperationContextService;
use App\Services\TerceroDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductDispatchClientController extends Controller
{
    private const MAX_RESULTS = 100;

    public function __construct(
        private readonly OperationContextService $context,
        private readonly TerceroDirectoryService $directory,
    ) {}

    public function index(ListProductDispatchClientsRequest $request): JsonResponse
    {
        $search = (string) $request->validated('buscar', '');
        $query = Tercero::query()
            ->where('empresa_id', $this->context->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->where('es_cliente_interno', false)
            ->conRol(TerceroRole::CLIENT)
            ->when($search !== '', function ($clientQuery) use ($search): void {
                $pattern = $this->escapedLikePattern($search);

                $clientQuery->where(function ($searchQuery) use ($pattern): void {
                    $searchQuery
                        ->whereRaw("nombre_razon_social LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("numero_documento LIKE ? ESCAPE '!'", [$pattern]);
                });
            });
        $total = (clone $query)->count();
        $clients = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get([
                'id',
                'tipo_documento',
                'numero_documento',
                'nombre_razon_social',
                'direccion',
                'created_at',
            ]);

        return response()->json([
            'data' => $clients
                ->map(fn (Tercero $client): array => $this->formatClient($client))
                ->values(),
            'meta' => [
                'total' => $total,
                'limit' => self::MAX_RESULTS,
            ],
        ]);
    }

    public function store(StoreProductDispatchClientRequest $request): JsonResponse
    {
        $record = $this->directory->create(
            $this->context->companyId($request),
            (int) $request->user()->id,
            TerceroRole::CLIENT,
            [
                ...$request->safe()->only([
                    'nombre_razon_social',
                    'numero_documento',
                    'direccion',
                ]),
                'es_cliente_interno' => false,
                'precios' => [],
            ],
        );

        return response()->json([
            'message' => 'Cliente externo registrado correctamente.',
            'data' => $this->formatClient($record),
        ], 201);
    }

    public function update(
        UpdateProductDispatchClientRequest $request,
        int $cliente,
    ): JsonResponse {
        $record = DB::transaction(fn (): Tercero => $this->directory->update(
            $this->findScopedClient($request, $cliente),
            (int) $request->user()->id,
            TerceroRole::CLIENT,
            [
                ...$request->safe()->only([
                    'nombre_razon_social',
                    'numero_documento',
                    'direccion',
                ]),
                'es_cliente_interno' => false,
            ],
        ));

        return response()->json([
            'message' => 'Cliente externo actualizado correctamente.',
            'data' => $this->formatClient($record),
        ]);
    }

    public function destroy(Request $request, int $cliente): JsonResponse
    {
        DB::transaction(fn () => $this->directory->deactivate(
            $this->findScopedClient($request, $cliente),
            TerceroRole::CLIENT,
        ));

        return response()->json([
            'message' => 'Cliente externo eliminado correctamente.',
        ]);
    }

    private function findScopedClient(Request $request, int $clientId): Tercero
    {
        return Tercero::query()
            ->where('empresa_id', $this->context->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->where('es_cliente_interno', false)
            ->conRol(TerceroRole::CLIENT)
            ->lockForUpdate()
            ->findOrFail($clientId);
    }

    private function escapedLikePattern(string $value): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value,
        ).'%';
    }

    /** @return array<string, bool|int|string|null> */
    private function formatClient(Tercero $client): array
    {
        return [
            'id' => (int) $client->id,
            'document_type' => (string) $client->tipo_documento,
            'document' => (string) $client->numero_documento,
            'name' => (string) $client->nombre_razon_social,
            'address' => (string) $client->direccion,
            'created_at' => $client->created_at?->toISOString(),
            'is_external' => true,
        ];
    }
}
