<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\SaveDispatchProductRequest;
use App\Http\Resources\DispatchProductResource;
use App\Models\ProductoDespacho;
use App\Models\VariacionProductoDespacho;
use App\Services\DispatchProductCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchProductController extends Controller
{
    public function __construct(
        private readonly DispatchProductCatalogService $catalog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in([
                ProductoDespacho::STATUS_ACTIVE,
                ProductoDespacho::STATUS_INACTIVE,
                'TODOS',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = (int) $request->user()->empresa_id;
        $search = trim((string) ($validated['buscar'] ?? ''));
        $status = (string) ($validated['estado'] ?? ProductoDespacho::STATUS_ACTIVE);
        $baseQuery = ProductoDespacho::query()->where('empresa_id', $companyId);

        $products = (clone $baseQuery)
            ->with(['variaciones' => fn ($query) => $query
                ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
                ->orderBy('orden')])
            ->when($status !== 'TODOS', fn (Builder $query) => $query->where('estado', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('nombre', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%")
                        ->orWhereHas('variaciones', fn (Builder $variationQuery) => $variationQuery
                            ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
                            ->where('nombre', 'like', "%{$search}%"));
                });
            })
            ->orderBy('estado')
            ->orderBy('nombre')
            ->paginate((int) ($validated['per_page'] ?? 24))
            ->withQueryString();

        $activeProducts = (clone $baseQuery)
            ->where('estado', ProductoDespacho::STATUS_ACTIVE)
            ->count();
        $inactiveProducts = (clone $baseQuery)
            ->where('estado', ProductoDespacho::STATUS_INACTIVE)
            ->count();
        $activeVariations = VariacionProductoDespacho::query()
            ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
            ->whereHas('producto', fn (Builder $query) => $query
                ->where('empresa_id', $companyId)
                ->where('estado', ProductoDespacho::STATUS_ACTIVE))
            ->count();

        return DispatchProductResource::collection($products)->additional([
            'summary' => [
                'active_products' => $activeProducts,
                'inactive_products' => $inactiveProducts,
                'active_variations' => $activeVariations,
            ],
        ]);
    }

    public function show(Request $request, int $producto): DispatchProductResource
    {
        return new DispatchProductResource(
            $this->findProduct($request, $producto)->load([
                'variaciones' => fn ($query) => $query
                    ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
                    ->orderBy('orden'),
            ]),
        );
    }

    public function store(SaveDispatchProductRequest $request): JsonResponse
    {
        $product = $this->catalog->create($request->user(), $request->validated());

        return (new DispatchProductResource($this->loadActiveVariations($product)))
            ->additional(['message' => 'Producto guardado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        SaveDispatchProductRequest $request,
        int $producto,
    ): DispatchProductResource {
        $product = $this->catalog->update(
            $this->findProduct($request, $producto),
            $request->user(),
            $request->validated(),
        );

        return (new DispatchProductResource($this->loadActiveVariations($product)))
            ->additional(['message' => 'Producto actualizado correctamente.']);
    }

    public function destroy(Request $request, int $producto): JsonResponse
    {
        $product = $this->findProduct($request, $producto);

        if ($product->estado === ProductoDespacho::STATUS_INACTIVE) {
            return response()->json(['message' => 'El producto ya se encuentra eliminado.']);
        }

        $this->catalog->deactivate($product, $request->user());

        return response()->json([
            'message' => 'Producto eliminado del catálogo. Puedes restaurarlo desde el filtro de eliminados.',
        ]);
    }

    public function image(Request $request, int $producto): StreamedResponse
    {
        $product = $this->findProduct($request, $producto);

        return $this->imageResponse($product->imagen_path);
    }

    public function variationImage(
        Request $request,
        int $producto,
        int $variacion,
    ): StreamedResponse {
        $product = $this->findProduct($request, $producto);
        $variation = VariacionProductoDespacho::query()
            ->where('producto_despacho_id', $product->id)
            ->findOrFail($variacion);

        return $this->imageResponse($variation->imagen_path);
    }

    private function findProduct(Request $request, int $id): ProductoDespacho
    {
        return ProductoDespacho::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->findOrFail($id);
    }

    private function loadActiveVariations(ProductoDespacho $product): ProductoDespacho
    {
        return $product->load([
            'variaciones' => fn ($query) => $query
                ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
                ->orderBy('orden'),
        ]);
    }

    private function imageResponse(?string $path): StreamedResponse
    {
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
