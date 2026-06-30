<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\StoreDriverRequest;
use App\Http\Requests\Fleet\UpdateDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Conductor;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DriverController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['buscar'] ?? '');

        $drivers = $this->query($request)
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('nombre_completo', 'like', "%{$search}%")
                    ->orWhere('numero_documento', 'like', "%{$search}%");
            }))
            ->orderBy('nombre_completo')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return DriverResource::collection($drivers);
    }

    public function show(Request $request, int $chofer): DriverResource
    {
        return new DriverResource($this->findDriver($request, $chofer));
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        $existingDriver = isset($data['numero_documento'])
            ? Conductor::query()
                ->where('empresa_id', $companyId)
                ->where('numero_documento', $data['numero_documento'])
                ->first()
            : null;

        if ($existingDriver) {
            if ($existingDriver->estado === Conductor::STATUS_ACTIVE) {
                $this->duplicateDocument();
            }

            $existingDriver->update([
                ...$data,
                'estado' => Conductor::STATUS_ACTIVE,
            ]);
            $driver = $existingDriver->refresh();
        } else {
            $driver = Conductor::query()->create([
                ...$data,
                'empresa_id' => $companyId,
                'estado' => Conductor::STATUS_ACTIVE,
            ]);
        }

        return (new DriverResource($driver))
            ->additional(['message' => 'Chofer creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateDriverRequest $request,
        int $chofer
    ): DriverResource {
        $driver = $this->findDriver($request, $chofer);
        $data = $request->validated();
        $documentType = array_key_exists('tipo_documento', $data)
            ? $data['tipo_documento']
            : $driver->tipo_documento;
        $documentNumber = array_key_exists('numero_documento', $data)
            ? $data['numero_documento']
            : $driver->numero_documento;

        if (($documentType === null) !== ($documentNumber === null)) {
            throw ValidationException::withMessages([
                'tipo_documento' => 'El tipo y el numero de documento deben enviarse juntos.',
                'numero_documento' => 'El tipo y el numero de documento deben enviarse juntos.',
            ]);
        }

        $this->ensureUniqueDocument(
            (int) $driver->empresa_id,
            $documentNumber,
            $driver->id
        );

        $driver->update($data);

        return (new DriverResource($driver->refresh()))
            ->additional(['message' => 'Chofer actualizado correctamente.']);
    }

    public function destroy(Request $request, int $chofer): JsonResponse
    {
        $driver = $this->findDriver($request, $chofer);
        $driver->update(['estado' => Conductor::STATUS_INACTIVE]);

        return response()->json([
            'message' => 'Chofer eliminado correctamente.',
        ]);
    }

    /**
     * @return Builder<Conductor>
     */
    private function query(Request $request): Builder
    {
        return Conductor::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Conductor::STATUS_ACTIVE);
    }

    private function findDriver(Request $request, int $driverId): Conductor
    {
        return $this->query($request)->findOrFail($driverId);
    }

    private function ensureUniqueDocument(
        int $companyId,
        ?string $documentNumber,
        ?int $exceptId = null
    ): void {
        if ($documentNumber === null) {
            return;
        }

        $exists = Conductor::query()
            ->where('empresa_id', $companyId)
            ->where('numero_documento', $documentNumber)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            $this->duplicateDocument();
        }
    }

    private function duplicateDocument(): never
    {
        throw ValidationException::withMessages([
            'numero_documento' => 'El numero de documento ya esta registrado en la empresa.',
        ]);
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
}
