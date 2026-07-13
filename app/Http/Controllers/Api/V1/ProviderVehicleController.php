<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreProviderVehicleRequest;
use App\Models\Empresa;
use App\Models\ProveedorVehiculo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use App\Models\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProviderVehicleController extends Controller
{
    public function store(
        StoreProviderVehicleRequest $request,
        int $tercero
    ): JsonResponse {
        $provider = $this->provider($request, $tercero);
        $association = DB::transaction(function () use ($request, $provider): ProveedorVehiculo {
            $vehicle = Vehiculo::query()->firstOrCreate(
                [
                    'empresa_id' => $provider->empresa_id,
                    'placa' => $request->validated('placa'),
                ],
                [
                    'tercero_propietario_id' => null,
                    'es_propio' => true,
                    'estado' => Vehiculo::STATUS_ACTIVE,
                ]
            );

            $activeAssociation = ProveedorVehiculo::query()
                ->where('vehiculo_id', $vehicle->id)
                ->vigente()
                ->lockForUpdate()
                ->first();

            if ($activeAssociation?->proveedor_id === $provider->id) {
                throw ValidationException::withMessages([
                    'placa' => 'Esta placa ya está asignada al proveedor.',
                ]);
            }

            if ($activeAssociation) {
                throw ValidationException::withMessages([
                    'placa' => 'Esta placa está asignada actualmente a otro proveedor.',
                ]);
            }

            $vehicle->update([
                'tercero_propietario_id' => null,
                'es_propio' => true,
                'estado' => Vehiculo::STATUS_ACTIVE,
            ]);
            $sameDayAssociation = ProveedorVehiculo::query()
                ->where('proveedor_id', $provider->id)
                ->where('vehiculo_id', $vehicle->id)
                ->whereDate('vigente_desde', today())
                ->where('estado', ProveedorVehiculo::STATUS_INACTIVE)
                ->first();

            if ($sameDayAssociation) {
                $sameDayAssociation->update([
                    'vigente_hasta' => null,
                    'estado' => ProveedorVehiculo::STATUS_ACTIVE,
                    'created_by' => $this->actor($request)->id,
                ]);

                return $sameDayAssociation->load('vehiculo');
            }

            return ProveedorVehiculo::query()->create([
                'proveedor_id' => $provider->id,
                'vehiculo_id' => $vehicle->id,
                'vigente_desde' => today(),
                'estado' => ProveedorVehiculo::STATUS_ACTIVE,
                'created_by' => $this->actor($request)->id,
            ])->load('vehiculo');
        });

        return response()->json([
            'message' => 'Camión de la empresa asignado correctamente al proveedor.',
            'data' => $this->formatAssociation($association),
        ], 201);
    }

    public function destroy(
        Request $request,
        int $tercero,
        int $association
    ): JsonResponse {
        $provider = $this->provider($request, $tercero);
        $providerVehicle = ProveedorVehiculo::query()
            ->where('proveedor_id', $provider->id)
            ->vigente()
            ->findOrFail($association);

        $providerVehicle->update([
            'estado' => ProveedorVehiculo::STATUS_INACTIVE,
            'vigente_hasta' => today(),
        ]);

        return response()->json([
            'message' => 'Asignación retirada. El camión permanece disponible en Mi flota.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatAssociation(ProveedorVehiculo $association): array
    {
        return [
            'id' => $association->id,
            'vehicle_id' => $association->vehiculo_id,
            'plate' => $association->vehiculo->placa,
            'valid_from' => $association->vigente_desde?->format('Y-m-d'),
            'valid_until' => $association->vigente_hasta?->format('Y-m-d'),
            'status' => $association->estado,
        ];
    }

    private function provider(Request $request, int $providerId): Tercero
    {
        return Tercero::query()
            ->where('empresa_id', $this->companyId($request))
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::PROVIDER)
            ->findOrFail($providerId);
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
}
