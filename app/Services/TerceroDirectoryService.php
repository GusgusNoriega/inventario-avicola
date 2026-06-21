<?php

namespace App\Services;

use App\Models\ListaPrecio;
use App\Models\PrecioHistorial;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TerceroDirectoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        int $companyId,
        int $actorId,
        string $role,
        array $data
    ): Tercero {
        return DB::transaction(function () use ($companyId, $actorId, $role, $data): Tercero {
            $tercero = Tercero::query()
                ->where('empresa_id', $companyId)
                ->where('numero_documento', $data['numero_documento'])
                ->lockForUpdate()
                ->first();

            if ($tercero?->estado === Tercero::STATUS_ACTIVE
                && $tercero->roles()->where('rol', $role)->exists()) {
                throw ValidationException::withMessages([
                    'numero_documento' => 'Ya existe un registro de este tipo con ese DNI/RUC.',
                ]);
            }

            $attributes = $this->thirdPartyAttributes($companyId, $data);

            if ($tercero) {
                $tercero->update($attributes);

                if (! $tercero->roles()->where('rol', $role)->exists()) {
                    $tercero->roles()->create(['rol' => $role]);
                }
            } else {
                $tercero = Tercero::query()->create($attributes);
                $tercero->roles()->create(['rol' => $role]);
            }

            $priceList = $this->ensurePriceList($tercero, $role, $actorId);
            $this->applyPrices($priceList, $data['precios'], $actorId, 'Precio inicial del directorio');

            return $this->loadForDirectory($tercero, $role);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Tercero $tercero,
        int $actorId,
        string $role,
        array $data
    ): Tercero {
        return DB::transaction(function () use ($tercero, $actorId, $role, $data): Tercero {
            $tercero = Tercero::query()->lockForUpdate()->findOrFail($tercero->id);

            $duplicate = Tercero::query()
                ->where('empresa_id', $tercero->empresa_id)
                ->where('numero_documento', $data['numero_documento'])
                ->whereKeyNot($tercero->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'numero_documento' => 'Ya existe otra persona o empresa con ese DNI/RUC.',
                ]);
            }

            $tercero->update($this->thirdPartyAttributes($tercero->empresa_id, $data));
            $priceList = $this->ensurePriceList($tercero, $role, $actorId);
            $priceList->update(['nombre' => $this->priceListName($tercero, $role)]);
            $this->applyPrices($priceList, $data['precios'], $actorId, 'Actualización desde el directorio');

            return $this->loadForDirectory($tercero, $role);
        });
    }

    public function deactivate(Tercero $tercero, string $role): void
    {
        DB::transaction(function () use ($tercero, $role): void {
            $tercero = Tercero::query()->lockForUpdate()->findOrFail($tercero->id);
            $operation = $this->operationForRole($role);

            $tercero->listasPrecios()
                ->where('operacion', $operation)
                ->update(['estado' => ListaPrecio::STATUS_INACTIVE]);

            if ($tercero->roles()->count() > 1) {
                $tercero->roles()->where('rol', $role)->delete();
            } else {
                $tercero->update(['estado' => Tercero::STATUS_INACTIVE]);
            }
        });
    }

    public function adjustPrices(
        int $companyId,
        int $actorId,
        string $role,
        string $chickenTypeCode,
        float $amount,
        string $direction
    ): int {
        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $role,
            $chickenTypeCode,
            $amount,
            $direction
        ): int {
            $operation = $this->operationForRole($role);
            $type = TipoPollo::query()->where('codigo', $chickenTypeCode)->firstOrFail();
            $lists = ListaPrecio::query()
                ->where('empresa_id', $companyId)
                ->where('operacion', $operation)
                ->where('estado', ListaPrecio::STATUS_ACTIVE)
                ->whereHas('tercero', fn ($query) => $query
                    ->where('estado', Tercero::STATUS_ACTIVE)
                    ->conRol($role))
                ->with(['preciosVigentes' => fn ($query) => $query
                    ->where('tipo_pollo_id', $type->id)])
                ->lockForUpdate()
                ->get();

            $changes = $lists->map(function (ListaPrecio $list) use ($amount, $direction): array {
                $current = $list->preciosVigentes->first();
                $newPrice = (float) ($current?->precio_kg ?? 0)
                    + ($direction === 'DISMINUIR' ? -$amount : $amount);

                if (! $current || $newPrice <= 0) {
                    throw ValidationException::withMessages([
                        'monto' => 'El ajuste dejaría al menos un precio en cero o con valor negativo.',
                    ]);
                }

                return [$list, $newPrice];
            });

            foreach ($changes as [$list, $newPrice]) {
                $this->applyPrices(
                    $list,
                    [$chickenTypeCode => $newPrice],
                    $actorId,
                    'Ajuste global desde el directorio'
                );
            }

            return $changes->count();
        });
    }

    public function loadForDirectory(Tercero $tercero, string $role): Tercero
    {
        $operation = $this->operationForRole($role);

        $tercero->load([
            'roles',
            'listasPrecios' => fn ($query) => $query
                ->where('operacion', $operation)
                ->where('estado', ListaPrecio::STATUS_ACTIVE),
            'listasPrecios.preciosVigentes.tipoPollo',
        ]);
        $tercero->setAttribute('_directory_role', $role);

        return $tercero;
    }

    public function operationForRole(string $role): string
    {
        return $role === TerceroRole::PROVIDER
            ? ListaPrecio::OPERATION_PURCHASE
            : ListaPrecio::OPERATION_SALE;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function thirdPartyAttributes(int $companyId, array $data): array
    {
        return [
            'empresa_id' => $companyId,
            'tipo_documento' => strlen($data['numero_documento']) === 11 ? 'RUC' : 'DNI',
            'numero_documento' => $data['numero_documento'],
            'nombre_razon_social' => Str::upper($data['nombre_razon_social']),
            'direccion' => $data['direccion'],
            'estado' => Tercero::STATUS_ACTIVE,
        ];
    }

    private function ensurePriceList(Tercero $tercero, string $role, int $actorId): ListaPrecio
    {
        $operation = $this->operationForRole($role);

        return ListaPrecio::query()->updateOrCreate(
            [
                'empresa_id' => $tercero->empresa_id,
                'tercero_id' => $tercero->id,
                'operacion' => $operation,
            ],
            [
                'codigo' => "TERCERO-{$tercero->id}-{$operation}",
                'nombre' => $this->priceListName($tercero, $role),
                'estado' => ListaPrecio::STATUS_ACTIVE,
                'created_by' => $actorId,
            ]
        );
    }

    private function priceListName(Tercero $tercero, string $role): string
    {
        $prefix = $role === TerceroRole::PROVIDER ? 'Compra' : 'Venta';

        return "{$prefix} - {$tercero->nombre_razon_social}";
    }

    /**
     * @param  array<string, float|int|string>  $prices
     */
    private function applyPrices(
        ListaPrecio $priceList,
        array $prices,
        int $actorId,
        string $reason
    ): void {
        $types = TipoPollo::query()
            ->whereIn('codigo', array_keys($prices))
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->get()
            ->keyBy('codigo');

        if ($types->count() !== count($prices)) {
            throw ValidationException::withMessages([
                'precios' => 'Uno o más tipos de pollo no están configurados.',
            ]);
        }

        foreach ($prices as $code => $value) {
            $type = $types->get($code);
            $current = PrecioHistorial::query()
                ->where('lista_precio_id', $priceList->id)
                ->where('tipo_pollo_id', $type->id)
                ->whereNull('vigente_hasta')
                ->lockForUpdate()
                ->first();
            $newPrice = round((float) $value, 4);

            if ($current && (float) $current->precio_kg === $newPrice) {
                continue;
            }

            $effectiveAt = $this->nextEffectiveAt($current?->vigente_desde);

            if ($current) {
                $current->update(['vigente_hasta' => $effectiveAt]);
            }

            PrecioHistorial::query()->create([
                'lista_precio_id' => $priceList->id,
                'tipo_pollo_id' => $type->id,
                'precio_kg' => $newPrice,
                'vigente_desde' => $effectiveAt,
                'motivo_cambio' => $reason,
                'reemplaza_precio_id' => $current?->id,
                'registrado_por' => $actorId,
            ]);
        }
    }

    private function nextEffectiveAt(?CarbonInterface $currentStart): CarbonInterface
    {
        $effectiveAt = now();

        if ($currentStart && $currentStart->gte($effectiveAt->copy()->startOfSecond())) {
            return $currentStart->copy()->addSecond();
        }

        return $effectiveAt;
    }
}
