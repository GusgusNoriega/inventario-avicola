<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\StoreTruckRequest;
use App\Http\Requests\Fleet\UpdateTruckRequest;
use App\Http\Resources\TruckResource;
use App\Models\Empresa;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class TruckController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim($validated['buscar'] ?? '');

        $trucks = $this->query($request)
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('placa', 'like', "%{$search}%")
                    ->orWhere('marca', 'like', "%{$search}%")
                    ->orWhere('modelo', 'like', "%{$search}%");
            }))
            ->orderBy('placa')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return TruckResource::collection($trucks);
    }

    public function show(Request $request, int $camion): TruckResource
    {
        return new TruckResource($this->findTruck($request, $camion));
    }

    public function store(StoreTruckRequest $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        $existingTruck = Vehiculo::query()
            ->where('empresa_id', $companyId)
            ->where('placa', $data['placa'])
            ->first();

        if ($existingTruck) {
            if (! $existingTruck->es_propio || $existingTruck->estado === Vehiculo::STATUS_ACTIVE) {
                $this->duplicatePlate();
            }

            $existingTruck->update([
                ...$data,
                'estado' => Vehiculo::STATUS_ACTIVE,
            ]);
            $truck = $existingTruck->refresh();
        } else {
            $truck = Vehiculo::query()->create([
                ...$data,
                'empresa_id' => $companyId,
                'tercero_propietario_id' => null,
                'es_propio' => true,
                'estado' => Vehiculo::STATUS_ACTIVE,
            ]);
        }

        return (new TruckResource($truck))
            ->additional(['message' => 'Camion creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateTruckRequest $request,
        int $camion
    ): TruckResource {
        $truck = $this->findTruck($request, $camion);
        $data = $request->validated();

        if (isset($data['placa']) && $data['placa'] !== $truck->placa) {
            $this->ensureUniquePlate((int) $truck->empresa_id, $data['placa'], $truck->id);
        }

        $truck->update($data);

        return (new TruckResource($truck->refresh()))
            ->additional(['message' => 'Camion actualizado correctamente.']);
    }

    public function destroy(Request $request, int $camion): JsonResponse
    {
        $truck = $this->findTruck($request, $camion);

        abort_if(
            $truck->proveedores()->exists(),
            409,
            'No se puede eliminar el camion porque tiene asignaciones registradas.'
        );

        $truck->update(['estado' => Vehiculo::STATUS_INACTIVE]);

        return response()->json([
            'message' => 'Camion eliminado correctamente.',
        ]);
    }

    /**
     * @return Builder<Vehiculo>
     */
    private function query(Request $request): Builder
    {
        return Vehiculo::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('es_propio', true)
            ->where('estado', Vehiculo::STATUS_ACTIVE);
    }

    private function findTruck(Request $request, int $truckId): Vehiculo
    {
        return $this->query($request)->findOrFail($truckId);
    }

    private function ensureUniquePlate(
        int $companyId,
        string $plate,
        ?int $exceptId = null
    ): void {
        $exists = Vehiculo::query()
            ->where('empresa_id', $companyId)
            ->where('placa', $plate)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            $this->duplicatePlate();
        }
    }

    private function duplicatePlate(): never
    {
        throw ValidationException::withMessages([
            'placa' => 'La placa ya esta registrada en la empresa.',
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
