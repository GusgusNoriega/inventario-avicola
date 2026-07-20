<?php

namespace App\Services;

use App\Models\ProgramacionRecepcion;
use App\Models\ProgramacionRecepcionDetalle;
use App\Models\ProveedorVehiculo;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JourneyPlanService
{
    /**
     * @return array<string, mixed>
     */
    public function current(int $companyId, object $branch): array
    {
        $window = $this->currentWindow($companyId, $branch);
        $program = ProgramacionRecepcion::query()
            ->where('sucursal_id', $branch->id)
            ->whereDate('fecha_operativa', $window['operating_date']->format('Y-m-d'))
            ->first();
        $vehicles = $this->availableVehicles($companyId);
        $selectedIds = $program
            ? ProgramacionRecepcionDetalle::query()
                ->where('programacion_id', $program->id)
                ->where('estado', '!=', ProgramacionRecepcionDetalle::STATUS_CANCELLED)
                ->pluck('proveedor_vehiculo_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        $selectedLookup = array_fill_keys($selectedIds, true);
        $warehouses = $this->availableWarehouses((int) $branch->id);
        $selectedWarehouseIds = $program
            ? DB::table('programacion_recepcion_almacenes')
                ->where('programacion_id', $program->id)
                ->pluck('almacen_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        $selectedWarehouseLookup = array_fill_keys($selectedWarehouseIds, true);

        return [
            'program_id' => $program?->id,
            'configured' => (bool) $program,
            'status' => $program?->estado ?? 'SIN_CONFIGURAR',
            'operating_date' => $window['operating_date']->format('Y-m-d'),
            'starts_at' => $window['starts_at']->toIso8601String(),
            'ends_at' => $window['ends_at']->toIso8601String(),
            'cutoff' => $window['cutoff'],
            'timezone' => $branch->zona_horaria,
            'selected_count' => count($selectedIds),
            'selected_warehouse_count' => count($selectedWarehouseIds),
            'trucks' => $vehicles->map(function (ProveedorVehiculo $association) use ($selectedLookup): array {
                return [
                    'provider_vehicle_id' => $association->id,
                    'vehicle_id' => $association->vehiculo_id,
                    'provider_id' => $association->proveedor_id,
                    'provider_name' => $association->proveedor->nombre_razon_social,
                    'document' => $association->proveedor->numero_documento,
                    'plate' => $association->vehiculo->placa,
                    'alias' => $association->alias,
                    'selected' => isset($selectedLookup[$association->id]),
                ];
            })->values(),
            'warehouses' => $warehouses->map(fn (object $warehouse) => [
                'id' => (int) $warehouse->id,
                'code' => $warehouse->codigo,
                'name' => $warehouse->nombre,
                'address' => $warehouse->direccion,
                'selected' => isset($selectedWarehouseLookup[$warehouse->id]),
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(
        int $companyId,
        object $branch,
        User $actor,
        array $data
    ): array {
        $window = $this->currentWindow($companyId, $branch);
        $selectedIds = collect($data['provider_vehicle_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $vehicles = $this->availableVehicles($companyId)
            ->whereIn('id', $selectedIds)
            ->values();
        $selectedWarehouseIds = collect($data['warehouse_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $warehouses = $this->availableWarehouses((int) $branch->id)
            ->whereIn('id', $selectedWarehouseIds)
            ->values();

        if ($vehicles->count() !== $selectedIds->count()) {
            throw ValidationException::withMessages([
                'provider_vehicle_ids' => 'Uno o más camiones seleccionados ya no están disponibles.',
            ]);
        }

        if ($warehouses->count() !== $selectedWarehouseIds->count()) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Uno o más almacenes seleccionados ya no están disponibles.',
            ]);
        }

        DB::transaction(function () use (
            $actor,
            $branch,
            $selectedIds,
            $selectedWarehouseIds,
            $vehicles,
            $window
        ): void {
            $program = ProgramacionRecepcion::query()->firstOrCreate(
                [
                    'sucursal_id' => $branch->id,
                    'fecha_operativa' => $window['operating_date']->format('Y-m-d'),
                ],
                [
                    'estado' => ProgramacionRecepcion::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ]
            );

            ProgramacionRecepcionDetalle::query()
                ->where('programacion_id', $program->id)
                ->when(
                    $selectedIds->isNotEmpty(),
                    fn ($query) => $query->whereNotIn('proveedor_vehiculo_id', $selectedIds),
                )
                ->update([
                    'estado' => ProgramacionRecepcionDetalle::STATUS_CANCELLED,
                    'estado_actualizado_por' => $actor->id,
                ]);

            if ($selectedIds->isEmpty()) {
                ProgramacionRecepcionDetalle::query()
                    ->where('programacion_id', $program->id)
                    ->update([
                        'estado' => ProgramacionRecepcionDetalle::STATUS_CANCELLED,
                        'estado_actualizado_por' => $actor->id,
                    ]);
            }

            foreach ($vehicles->values() as $index => $vehicle) {
                $detail = ProgramacionRecepcionDetalle::query()->firstOrNew([
                    'programacion_id' => $program->id,
                    'proveedor_vehiculo_id' => $vehicle->id,
                    'numero_visita' => 1,
                ]);

                if (! $detail->exists) {
                    $detail->created_by = $actor->id;
                }
                if (! $detail->exists || $detail->estado === ProgramacionRecepcionDetalle::STATUS_CANCELLED) {
                    $detail->estado = ProgramacionRecepcionDetalle::STATUS_PENDING;
                }
                $detail->orden_llegada = $index + 1;
                $detail->estado_actualizado_por = $actor->id;
                $detail->save();
            }

            $warehouseSelection = DB::table('programacion_recepcion_almacenes')
                ->where('programacion_id', $program->id);

            if ($selectedWarehouseIds->isEmpty()) {
                $warehouseSelection->delete();
            } else {
                $warehouseSelection
                    ->whereNotIn('almacen_id', $selectedWarehouseIds)
                    ->delete();

                DB::table('programacion_recepcion_almacenes')->upsert(
                    $selectedWarehouseIds->map(fn (int $warehouseId) => [
                        'programacion_id' => $program->id,
                        'almacen_id' => $warehouseId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                    ['programacion_id', 'almacen_id'],
                    ['updated_at']
                );
            }

            $program->update([
                'estado' => ProgramacionRecepcion::STATUS_PUBLISHED,
                'publicada_por' => $actor->id,
                'publicada_at' => now(),
            ]);
        }, 3);

        return $this->current($companyId, $branch);
    }

    /**
     * @return array{operating_date: CarbonImmutable, starts_at: CarbonImmutable, ends_at: CarbonImmutable, cutoff: string}
     */
    public function currentWindow(int $companyId, object $branch): array
    {
        $cutoff = (string) DB::table('empresas')
            ->where('id', $companyId)
            ->value('hora_corte_operativo') ?: '21:00:00';
        $now = CarbonImmutable::now($branch->zona_horaria);
        $cutoffToday = $now->startOfDay()->setTimeFromTimeString($cutoff);
        $operatingDate = $now->greaterThanOrEqualTo($cutoffToday)
            ? $now->addDay()->startOfDay()
            : $now->startOfDay();

        return [
            'operating_date' => $operatingDate,
            'starts_at' => $operatingDate->subDay()->setTimeFromTimeString($cutoff),
            'ends_at' => $operatingDate->setTimeFromTimeString($cutoff),
            'cutoff' => $cutoff,
        ];
    }

    /**
     * @return Collection<int, ProveedorVehiculo>
     */
    private function availableVehicles(int $companyId): Collection
    {
        return ProveedorVehiculo::query()
            ->vigente()
            ->whereHas('proveedor', fn ($query) => $query
                ->where('empresa_id', $companyId)
                ->where('estado', Tercero::STATUS_ACTIVE)
                ->conRol(TerceroRole::PROVIDER))
            ->whereHas('vehiculo', fn ($query) => $query
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO'))
            ->with(['proveedor', 'vehiculo'])
            ->get()
            ->sortBy([
                fn (ProveedorVehiculo $vehicle) => $vehicle->proveedor->nombre_razon_social,
                fn (ProveedorVehiculo $vehicle) => $vehicle->vehiculo->placa,
            ])
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function availableWarehouses(int $branchId): Collection
    {
        return DB::table('almacenes')
            ->where('sucursal_id', $branchId)
            ->where('estado', 'ACTIVO')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'direccion']);
    }
}
