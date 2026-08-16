<?php

namespace App\Services;

use App\Models\AjustePesoMayoristaDos;
use App\Models\ProgramacionRecepcion;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WholesaleTwoDispatchTicketService extends DispatchTicketService
{
    /** @var Collection<string, AjustePesoMayoristaDos> */
    private Collection $resolvedAdjustments;

    public function __construct(
        JavaControlService $javaControl,
        FinancialObligationService $financialObligations,
        ScaleReadingService $scaleReadings,
        private readonly WholesaleTwoWeightAdjustmentService $weightAdjustments,
    ) {
        parent::__construct($javaControl, $financialObligations, $scaleReadings);
        $this->resolvedAdjustments = collect();
    }

    protected function sourceModule(): ?string
    {
        return TicketDespacho::SOURCE_WHOLESALE_TWO;
    }

    protected function ownsExistingTicket(TicketDespacho $ticket): bool
    {
        return $ticket->canal === TicketDespacho::CHANNEL_WHOLESALE
            && $ticket->modulo_origen === TicketDespacho::SOURCE_WHOLESALE_TWO;
    }

    /**
     * @param  Collection<string, TipoPollo>  $types
     * @param  array<string, mixed>  $data
     * @return array<int, array{history_id: ?int, price_kg: mixed, source: string}>
     */
    protected function registrationPrices(
        int $companyId,
        ?int $clientId,
        Collection $types,
        array $data,
    ): array {
        $specialTypes = $types->filter(
            fn (TipoPollo $type): bool => TipoPollo::requiresWholesaleTwoManualPrice($type->codigo)
        );
        $standardTypes = $types->reject(
            fn (TipoPollo $type): bool => TipoPollo::requiresWholesaleTwoManualPrice($type->codigo)
        );
        $manualPrices = collect($data['manual_prices'] ?? []);

        if (
            $manualPrices->keys()->sort()->values()->all()
            !== $specialTypes->keys()->sort()->values()->all()
        ) {
            throw ValidationException::withMessages([
                'manual_prices' => 'Envía exactamente los precios manuales de los productos especiales presentes en el ticket.',
            ]);
        }

        $prices = parent::registrationPrices(
            $companyId,
            $clientId,
            $standardTypes,
            $data,
        );

        foreach ($specialTypes as $code => $type) {
            $price = round((float) $manualPrices->get($code), 2, PHP_ROUND_HALF_UP);
            if ($price <= 0 || $price > 99_999_999.99) {
                throw ValidationException::withMessages([
                    "manual_prices.{$code}" => "El precio manual de {$type->nombre} no es válido.",
                ]);
            }

            $prices[(int) $type->id] = [
                'history_id' => null,
                'price_kg' => $price,
                'source' => 'MANUAL',
            ];
        }

        return $prices;
    }

    /** @param Collection<int, array<string, mixed>> $weighings */
    protected function prepareWeighingsForRegistration(
        int $companyId,
        string $operationType,
        Collection $weighings,
    ): void {
        $this->resolvedAdjustments = $operationType === TicketDespacho::OPERATION_DISPATCH
            ? $this->weightAdjustments->resolveForVariants(
                $companyId,
                $weighings->pluck('chicken_variant_code'),
                true,
            )
            : collect();
    }

    /**
     * @param  array<string, mixed>  $weighing
     * @return array{
     *     read_weight_kg: float,
     *     gross_weight_kg: float,
     *     tare_weight_kg: float,
     *     net_weight_kg: float,
     *     wholesale_two_adjustment_id: ?int,
     *     wholesale_two_adjustment_grams: ?int
     * }
     */
    protected function weighingWeightBreakdown(
        int $companyId,
        string $operationType,
        array $weighing,
        int $cageCount,
        int $birdsPerCage,
        float $cageWeight,
        int $index,
    ): array {
        $adjustment = $operationType === TicketDespacho::OPERATION_DISPATCH
            ? $this->resolvedAdjustments->get($weighing['chicken_variant_code'] ?? '')
            : null;

        if ($operationType === TicketDespacho::OPERATION_DISPATCH && ! $adjustment) {
            throw ValidationException::withMessages([
                "weighings.{$index}.chicken_variant_code" => 'La merma de la clasificación seleccionada no está disponible.',
            ]);
        }

        $birdCount = $birdsPerCage * max($cageCount, 1);
        $adjustmentGrams = $adjustment && $adjustment->isConfigurable()
                ? (int) $adjustment->gramos_adicionales
                : 0;
        $readWeight = round((float) $weighing['read_weight_kg'], 3);
        $grossWeight = round(
            $readWeight + (($adjustmentGrams * $birdCount) / 1000),
            3
        );
        $tareWeight = round($cageCount * $cageWeight, 3);
        $netWeight = round($grossWeight - $tareWeight, 3);

        if ($netWeight <= 0) {
            throw ValidationException::withMessages([
                "weighings.{$index}.read_weight_kg" => 'El peso leído con la merma aplicada debe ser mayor que la tara total de las javas.',
            ]);
        }

        return [
            'read_weight_kg' => $readWeight,
            'gross_weight_kg' => $grossWeight,
            'tare_weight_kg' => $tareWeight,
            'net_weight_kg' => $netWeight,
            'wholesale_two_adjustment_id' => $adjustment?->id,
            'wholesale_two_adjustment_grams' => $operationType === TicketDespacho::OPERATION_DISPATCH
                ? $adjustmentGrams
                : 0,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $weighings */
    protected function requiresConfiguredProgram(string $operationType, Collection $weighings): bool
    {
        if ($operationType !== TicketDespacho::OPERATION_DISPATCH) {
            return false;
        }

        return $weighings->contains(
            fn (array $weighing): bool => $this->hasOrigin($weighing['origin'] ?? null)
        );
    }

    /**
     * @param  array<string, mixed>  $weighing
     * @return array{provider_id: ?int, warehouse_id: ?int, vehicle_id: ?int, plate: ?string, program_detail_id: ?int}
     */
    protected function resolveWeighingOrigin(
        int $companyId,
        int $branchId,
        ?ProgramacionRecepcion $program,
        array $weighing,
        string $field,
    ): array {
        if (! $this->hasOrigin($weighing['origin'] ?? null)) {
            return $this->emptyOrigin();
        }

        return parent::resolveWeighingOrigin(
            $companyId,
            $branchId,
            $program,
            $weighing,
            $field,
        );
    }

    /** @param array<string, mixed> $weighing */
    protected function weighingSex(string $operationType, array $weighing): ?string
    {
        if ($operationType !== TicketDespacho::OPERATION_DISPATCH) {
            return parent::weighingSex($operationType, $weighing);
        }

        return WholesaleTwoChickenVariant::definition(
            $weighing['chicken_variant_code'] ?? null
        )['sex'] ?? null;
    }

    /** @param array<string, mixed> $weighing */
    protected function weighingPresentation(string $operationType, array $weighing): ?string
    {
        if ($operationType !== TicketDespacho::OPERATION_DISPATCH) {
            return null;
        }

        return WholesaleTwoChickenVariant::definition(
            $weighing['chicken_variant_code'] ?? null
        )['presentation'] ?? null;
    }

    private function hasOrigin(mixed $origin): bool
    {
        return is_array($origin)
            && in_array($origin['type'] ?? null, ['PROVEEDOR', 'ALMACEN'], true);
    }
}
