<?php

namespace App\Services;

use App\Models\AjustePesoMayoristaDos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WholesaleTwoWeightAdjustmentService
{
    public function ensureDefaults(int $companyId): void
    {
        $now = now();
        $rows = collect(AjustePesoMayoristaDos::definitions())
            ->map(fn (array $definition, string $code): array => [
                'empresa_id' => $companyId,
                'codigo' => $code,
                'nombre' => $definition['name'],
                'sexo' => $definition['sex'],
                'presentacion' => $definition['presentation'],
                'gramos_adicionales' => 0,
                'estado' => AjustePesoMayoristaDos::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::table('ajustes_peso_mayorista_2')->insertOrIgnore($rows);

        DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $companyId)
            ->whereIn('codigo', AjustePesoMayoristaDos::nonConfigurableCodes())
            ->where('gramos_adicionales', '!=', 0)
            ->update([
                'gramos_adicionales' => 0,
                'updated_at' => $now,
            ]);
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     sex: ?string,
     *     presentation: ?string,
     *     additional_grams: int,
     *     configurable: bool
     * }>
     */
    public function configuration(int $companyId): Collection
    {
        $this->ensureDefaults($companyId);
        $adjustments = AjustePesoMayoristaDos::query()
            ->where('empresa_id', $companyId)
            ->where('estado', AjustePesoMayoristaDos::STATUS_ACTIVE)
            ->get()
            ->keyBy('codigo');

        if (collect(AjustePesoMayoristaDos::codes())->diff($adjustments->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'adjustments' => 'La configuración de mermas de Despacho mayorista 2 está incompleta.',
            ]);
        }

        return collect(AjustePesoMayoristaDos::definitions())
            ->map(function (array $definition, string $code) use ($adjustments): array {
                /** @var AjustePesoMayoristaDos $adjustment */
                $adjustment = $adjustments->get($code);

                return [
                    'id' => (int) $adjustment->id,
                    'code' => $code,
                    'name' => $adjustment->nombre,
                    'sex' => $adjustment->sexo,
                    'presentation' => $adjustment->presentacion,
                    'additional_grams' => $definition['configurable']
                        ? (int) $adjustment->gramos_adicionales
                        : 0,
                    'configurable' => $definition['configurable'],
                ];
            })
            ->values();
    }

    /**
     * @param  list<array{code: string, additional_grams: int}>  $adjustments
     * @return Collection<int, array<string, mixed>>
     */
    public function update(int $companyId, array $adjustments): Collection
    {
        $hasInvalidGrams = collect($adjustments)->contains(
            function (mixed $adjustment): bool {
                if (! is_array($adjustment) || ! array_key_exists('additional_grams', $adjustment)) {
                    return true;
                }

                return filter_var(
                    $adjustment['additional_grams'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0, 'max_range' => 1_000_000]],
                ) === false;
            }
        );

        if ($hasInvalidGrams) {
            throw ValidationException::withMessages([
                'adjustments' => 'Cada merma debe estar entre 0 y 1.000.000 de gramos por ave.',
            ]);
        }

        $normalized = collect($adjustments)
            ->map(fn (array $adjustment): array => [
                'code' => mb_strtoupper(trim((string) ($adjustment['code'] ?? '')), 'UTF-8'),
                'additional_grams' => (int) ($adjustment['additional_grams'] ?? 0),
            ]);
        $submittedCodes = $normalized->pluck('code');
        $configurableCodes = collect(AjustePesoMayoristaDos::configurableCodes());
        $allowedCodes = collect(AjustePesoMayoristaDos::codes());

        if (
            $submittedCodes->duplicates()->isNotEmpty()
            || $configurableCodes->diff($submittedCodes)->isNotEmpty()
            || $submittedCodes->diff($allowedCodes)->isNotEmpty()
        ) {
            throw ValidationException::withMessages([
                'adjustments' => 'Envía exactamente todas las mermas configurables de Despacho mayorista 2.',
            ]);
        }

        $invalidLockedAdjustment = $normalized->contains(
            fn (array $adjustment): bool => in_array(
                $adjustment['code'],
                AjustePesoMayoristaDos::nonConfigurableCodes(),
                true,
            ) && (int) $adjustment['additional_grams'] !== 0
        );
        if ($invalidLockedAdjustment) {
            throw ValidationException::withMessages([
                'adjustments' => 'Las clasificaciones bloqueadas no admiten merma.',
            ]);
        }

        DB::transaction(function () use ($companyId, $normalized, $configurableCodes): void {
            $this->ensureDefaults($companyId);
            $stored = AjustePesoMayoristaDos::query()
                ->where('empresa_id', $companyId)
                ->where('estado', AjustePesoMayoristaDos::STATUS_ACTIVE)
                ->whereIn('codigo', AjustePesoMayoristaDos::codes())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');

            if ($stored->count() !== count(AjustePesoMayoristaDos::codes())) {
                throw ValidationException::withMessages([
                    'adjustments' => 'La configuración de mermas de Despacho mayorista 2 está incompleta.',
                ]);
            }

            foreach ($configurableCodes as $code) {
                $values = $normalized->firstWhere('code', $code);
                $stored->get($code)->update([
                    'gramos_adicionales' => (int) $values['additional_grams'],
                ]);
            }

            foreach (AjustePesoMayoristaDos::nonConfigurableCodes() as $code) {
                $stored->get($code)->update(['gramos_adicionales' => 0]);
            }
        }, 3);

        return $this->configuration($companyId);
    }

    public function resolveForVariant(
        int $companyId,
        string $variantCode,
        bool $lockForUpdate = false,
    ): AjustePesoMayoristaDos {
        $adjustment = $this->resolveForVariants(
            $companyId,
            [$variantCode],
            $lockForUpdate,
        )->first();

        if (! $adjustment) {
            throw ValidationException::withMessages([
                'chicken_variant_code' => 'La merma de la clasificación seleccionada no está disponible.',
            ]);
        }

        return $adjustment;
    }

    /**
     * @param  iterable<int, string>  $variantCodes
     * @return Collection<string, AjustePesoMayoristaDos>
     */
    public function resolveForVariants(
        int $companyId,
        iterable $variantCodes,
        bool $lockForUpdate = false,
    ): Collection {
        $codes = collect($variantCodes)
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        if ($codes->diff(AjustePesoMayoristaDos::codes())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'chicken_variant_code' => 'Una de las clasificaciones no admite configuración de merma.',
            ]);
        }

        $this->ensureDefaults($companyId);
        $query = AjustePesoMayoristaDos::query()
            ->where('empresa_id', $companyId)
            ->where('estado', AjustePesoMayoristaDos::STATUS_ACTIVE)
            ->whereIn('codigo', $codes)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $adjustments = $query->get()->keyBy('codigo');
        if ($adjustments->count() !== $codes->count()) {
            throw ValidationException::withMessages([
                'chicken_variant_code' => 'La configuración de mermas de Despacho mayorista 2 está incompleta.',
            ]);
        }

        return $adjustments;
    }
}
