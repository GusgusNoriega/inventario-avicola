<?php

namespace App\Services;

use App\Models\ConfiguracionDespachoProducto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchConfigurationService
{
    /** @var list<int> */
    public const DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT = [0, 50, 100];

    /** @return array{waste_presets: list<int>} */
    public function configuration(int $companyId, int $branchId): array
    {
        $this->ensureDefaults($companyId, $branchId);

        $configuration = ConfiguracionDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->firstOrFail();

        return $this->format($configuration);
    }

    /**
     * @param  list<int>  $presets
     * @return array{waste_presets: list<int>}
     */
    public function update(int $companyId, int $branchId, array $presets): array
    {
        $values = array_values(array_map(static fn (mixed $value): int => (int) $value, $presets));

        if (count($values) !== 3) {
            throw ValidationException::withMessages([
                'waste_presets' => 'Debes configurar exactamente tres mermas rápidas.',
            ]);
        }

        $this->ensureDefaults($companyId, $branchId);

        DB::transaction(function () use ($companyId, $branchId, $values): void {
            $configuration = ConfiguracionDespachoProducto::query()
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $configuration) {
                throw ValidationException::withMessages([
                    'waste_presets' => 'No existe una configuración para esta sucursal.',
                ]);
            }

            $configuration->update([
                'merma_preset_1_gramos_unidad' => $values[0],
                'merma_preset_2_gramos_unidad' => $values[1],
                'merma_preset_3_gramos_unidad' => $values[2],
            ]);
        }, 3);

        return $this->configuration($companyId, $branchId);
    }

    public function ensureDefaults(int $companyId, int $branchId): void
    {
        $branchExists = DB::table('sucursales')
            ->where('id', $branchId)
            ->where('empresa_id', $companyId)
            ->exists();

        if (! $branchExists) {
            throw ValidationException::withMessages([
                'waste_presets' => 'La sucursal no pertenece a la empresa de esta operación.',
            ]);
        }

        $now = now();
        DB::table('configuraciones_despacho_productos')->insertOrIgnore([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'merma_preset_1_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[0],
            'merma_preset_2_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[1],
            'merma_preset_3_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[2],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{waste_presets: list<int>} */
    private function format(ConfiguracionDespachoProducto $configuration): array
    {
        return [
            'waste_presets' => [
                (int) $configuration->merma_preset_1_gramos_unidad,
                (int) $configuration->merma_preset_2_gramos_unidad,
                (int) $configuration->merma_preset_3_gramos_unidad,
            ],
        ];
    }
}
