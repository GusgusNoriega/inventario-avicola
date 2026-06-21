<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Directory\AdjustDirectoryPricesRequest;
use App\Http\Requests\Directory\StoreTerceroRequest;
use App\Http\Requests\Directory\UpdateTerceroRequest;
use App\Http\Resources\TerceroResource;
use App\Models\Empresa;
use App\Models\ListaPrecio;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use App\Services\TerceroDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DirectoryController extends Controller
{
    public function __construct(
        private readonly TerceroDirectoryService $directoryService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $role = $this->routeRole($request);
        $operation = $this->directoryService->operationForRole($role);
        $search = trim($validated['buscar'] ?? '');
        $perPage = (int) ($validated['per_page'] ?? 100);

        $records = Tercero::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol($role)
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('nombre_razon_social', 'like', "%{$search}%")
                    ->orWhere('numero_documento', 'like', "%{$search}%");
            }))
            ->with([
                'roles',
                'listasPrecios' => fn ($query) => $query
                    ->where('operacion', $operation)
                    ->where('estado', ListaPrecio::STATUS_ACTIVE),
                'listasPrecios.preciosVigentes.tipoPollo',
            ])
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();

        $records->getCollection()->transform(function (Tercero $tercero) use ($role): Tercero {
            $tercero->setAttribute('_directory_role', $role);

            return $tercero;
        });

        return TerceroResource::collection($records);
    }

    public function store(StoreTerceroRequest $request): JsonResponse
    {
        $role = $this->routeRole($request);
        $record = $this->directoryService->create(
            $this->companyId($request),
            $this->actor($request)->id,
            $role,
            $request->validated()
        );

        return (new TerceroResource($record))
            ->additional(['message' => 'Registro creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateTerceroRequest $request, int $tercero): TerceroResource
    {
        $role = $this->routeRole($request);
        $record = $this->directoryService->update(
            $this->findScopedRecord($request, $tercero, $role),
            $this->actor($request)->id,
            $role,
            $request->validated()
        );

        return (new TerceroResource($record))
            ->additional(['message' => 'Registro actualizado correctamente.']);
    }

    public function destroy(Request $request, int $tercero): JsonResponse
    {
        $role = $this->routeRole($request);
        $record = $this->findScopedRecord($request, $tercero, $role);

        $this->directoryService->deactivate($record, $role);

        return response()->json([
            'message' => 'Registro desactivado correctamente.',
        ]);
    }

    public function adjustPrices(AdjustDirectoryPricesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $affected = $this->directoryService->adjustPrices(
            $this->companyId($request),
            $this->actor($request)->id,
            $this->routeRole($request),
            $data['tipo_pollo'],
            (float) $data['monto'],
            $data['direccion']
        );

        return response()->json([
            'message' => 'Precios actualizados correctamente.',
            'affected' => $affected,
        ]);
    }

    private function routeRole(Request $request): string
    {
        $role = (string) $request->route('directory_role');

        abort_unless(
            in_array($role, [TerceroRole::CLIENT, TerceroRole::PROVIDER], true),
            404
        );

        return $role;
    }

    private function companyId(Request $request): int
    {
        if ($request->user()) {
            return (int) $request->user()->empresa_id;
        }

        abort_unless(config('directory.public_access'), 401);

        $companyId = Empresa::query()
            ->where('estado', Empresa::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('id');

        abort_unless($companyId, 503, 'No existe una empresa activa configurada.');

        return (int) $companyId;
    }

    private function actor(Request $request): User
    {
        if ($request->user()) {
            return $request->user();
        }

        $companyId = $this->companyId($request);

        return User::query()->firstOrCreate(
            [
                'empresa_id' => $companyId,
                'email' => 'sistema-directorio@local.invalid',
            ],
            [
                'nombre' => 'Sistema directorio local',
                'password_hash' => Hash::make(Str::random(64)),
                'estado' => User::STATUS_INACTIVE,
            ]
        );
    }

    private function findScopedRecord(
        Request $request,
        int $recordId,
        string $role
    ): Tercero {
        return Tercero::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol($role)
            ->findOrFail($recordId);
    }
}
