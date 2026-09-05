<?php

namespace App\Services;

use App\Models\ConfiguracionDespachoProducto;
use App\Models\ProductoDespacho;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchConfigurationService
{
    /** @var list<int> */
    public const DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT = [0, 50, 100, 150];

    /**
     * @return array{
     *     waste_presets: list<int>,
     *     quick_product_ids: list<int>,
     *     quick_products_configured: bool,
     *     customer_display_title: string,
     *     product_ticket_title: string
     * }
     */
    public function configuration(int $companyId, int $branchId): array
    {
        $this->ensureDefaults($companyId, $branchId);

        $configuration = ConfiguracionDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->firstOrFail();

        return $this->format($configuration, $companyId);
    }

    /**
     * @param  array{waste_presets?: list<int>, quick_product_ids?: list<int>, customer_display_title?: string, product_ticket_title?: string}  $changes
     * @return array{
     *     waste_presets: list<int>,
     *     quick_product_ids: list<int>,
     *     quick_products_configured: bool,
     *     customer_display_title: string,
     *     product_ticket_title: string
     * }
     */
    public function update(int $companyId, int $branchId, array $changes): array
    {
        if (! array_key_exists('waste_presets', $changes)
            && ! array_key_exists('quick_product_ids', $changes)
            && ! array_key_exists('customer_display_title', $changes)
            && ! array_key_exists('product_ticket_title', $changes)) {
            throw ValidationException::withMessages([
                'configuration' => 'No se indicó ninguna configuración para guardar.',
            ]);
        }

        $updates = [];

        if (array_key_exists('waste_presets', $changes)) {
            $presets = array_values(array_map(
                static fn (mixed $value): int => (int) $value,
                $changes['waste_presets'],
            ));

            if (count($presets) !== 4) {
                throw ValidationException::withMessages([
                    'waste_presets' => 'Debes configurar exactamente cuatro mermas rápidas.',
                ]);
            }

            $updates = [
                ...$updates,
                'merma_preset_1_gramos_unidad' => $presets[0],
                'merma_preset_2_gramos_unidad' => $presets[1],
                'merma_preset_3_gramos_unidad' => $presets[2],
                'merma_preset_4_gramos_unidad' => $presets[3],
            ];
        }

        if (array_key_exists('quick_product_ids', $changes)) {
            $quickProductIds = array_values(array_map(
                static fn (mixed $value): int => (int) $value,
                $changes['quick_product_ids'],
            ));

            if (count($quickProductIds) !== 4) {
                throw ValidationException::withMessages([
                    'quick_product_ids' => 'Debes seleccionar exactamente cuatro productos rápidos.',
                ]);
            }

            if (count(array_unique($quickProductIds, SORT_REGULAR)) !== count($quickProductIds)) {
                throw ValidationException::withMessages([
                    'quick_product_ids' => 'No puedes repetir un producto en la selección rápida.',
                ]);
            }

            $activeCompanyProductIds = ProductoDespacho::query()
                ->where('empresa_id', $companyId)
                ->where('estado', ProductoDespacho::STATUS_ACTIVE)
                ->whereIn('id', $quickProductIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (count($activeCompanyProductIds) !== count($quickProductIds)) {
                throw ValidationException::withMessages([
                    'quick_product_ids' => 'Todos los productos rápidos deben estar activos y pertenecer a tu empresa.',
                ]);
            }

            $updates = [
                ...$updates,
                'productos_rapidos_configurados' => true,
                'producto_rapido_1_id' => $quickProductIds[0],
                'producto_rapido_2_id' => $quickProductIds[1],
                'producto_rapido_3_id' => $quickProductIds[2],
                'producto_rapido_4_id' => $quickProductIds[3],
            ];
        }

        if (array_key_exists('customer_display_title', $changes)) {
            $updates['titulo_pantalla_cliente'] = trim((string) $changes['customer_display_title']);
        }

        if (array_key_exists('product_ticket_title', $changes)) {
            $updates['titulo_ticket_despacho'] = trim((string) $changes['product_ticket_title']);
        }

        $this->ensureDefaults($companyId, $branchId);

        DB::transaction(function () use ($companyId, $branchId, $updates): void {
            $configuration = ConfiguracionDespachoProducto::query()
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $configuration) {
                throw ValidationException::withMessages([
                    'configuration' => 'No existe una configuración para esta sucursal.',
                ]);
            }

            $configuration->update($updates);
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
                'configuration' => 'La sucursal no pertenece a la empresa de esta operación.',
            ]);
        }

        $now = now();
        DB::table('configuraciones_despacho_productos')->insertOrIgnore([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'merma_preset_1_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[0],
            'merma_preset_2_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[1],
            'merma_preset_3_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[2],
            'merma_preset_4_gramos_unidad' => self::DEFAULT_WASTE_PRESETS_GRAMS_PER_UNIT[3],
            'productos_rapidos_configurados' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{
     *     waste_presets: list<int>,
     *     quick_product_ids: list<int>,
     *     quick_products_configured: bool,
     *     customer_display_title: string,
     *     product_ticket_title: string
     * }
     */
    private function format(
        ConfiguracionDespachoProducto $configuration,
        int $companyId,
    ): array {
        return [
            'waste_presets' => [
                (int) $configuration->merma_preset_1_gramos_unidad,
                (int) $configuration->merma_preset_2_gramos_unidad,
                (int) $configuration->merma_preset_3_gramos_unidad,
                (int) $configuration->merma_preset_4_gramos_unidad,
            ],
            'quick_product_ids' => $this->effectiveQuickProductIds($configuration, $companyId),
            'quick_products_configured' => (bool) $configuration->productos_rapidos_configurados,
            'customer_display_title' => $this->effectiveCustomerDisplayTitle(
                $configuration,
                $companyId,
            ),
            'product_ticket_title' => $this->effectiveProductTicketTitle(
                $configuration,
                $companyId,
            ),
        ];
    }

    private function effectiveProductTicketTitle(
        ConfiguracionDespachoProducto $configuration,
        int $companyId,
    ): string {
        $configuredTitle = trim((string) $configuration->titulo_ticket_despacho);

        if ($configuredTitle !== '') {
            return $configuredTitle;
        }

        $company = DB::table('empresas')
            ->where('id', $companyId)
            ->first(['titulo_ticket', 'nombre_comercial', 'razon_social']);

        foreach (['titulo_ticket', 'nombre_comercial', 'razon_social'] as $field) {
            $candidate = trim((string) ($company?->{$field} ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'DESPACHO DE PRODUCTOS';
    }

    private function effectiveCustomerDisplayTitle(
        ConfiguracionDespachoProducto $configuration,
        int $companyId,
    ): string {
        $configuredTitle = trim((string) $configuration->titulo_pantalla_cliente);

        if ($configuredTitle !== '') {
            return $configuredTitle;
        }

        $company = DB::table('empresas')
            ->where('id', $companyId)
            ->first(['nombre_comercial', 'razon_social']);

        $commercialName = trim((string) ($company?->nombre_comercial ?? ''));

        if ($commercialName !== '') {
            return $commercialName;
        }

        $legalName = trim((string) ($company?->razon_social ?? ''));

        return $legalName !== '' ? $legalName : 'Despacho de productos';
    }

    /** @return list<int> */
    private function effectiveQuickProductIds(
        ConfiguracionDespachoProducto $configuration,
        int $companyId,
    ): array {
        $activeProductIds = ProductoDespacho::query()
            ->where('empresa_id', $companyId)
            ->where('estado', ProductoDespacho::STATUS_ACTIVE)
            ->orderBy('nombre')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $activeLookup = array_fill_keys($activeProductIds, true);
        $selected = [];

        if ($configuration->productos_rapidos_configurados) {
            for ($position = 1; $position <= 4; $position++) {
                $productId = $configuration->{"producto_rapido_{$position}_id"};

                if ($productId !== null && isset($activeLookup[(int) $productId])) {
                    $selected[] = (int) $productId;
                }
            }
        }

        foreach ($activeProductIds as $productId) {
            if (count($selected) >= 4) {
                break;
            }

            if (! in_array($productId, $selected, true)) {
                $selected[] = $productId;
            }
        }

        return $selected;
    }
}
