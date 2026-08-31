<?php

namespace App\Http\Requests\Operation;

use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWholesaleTwoDispatchTicketRequest extends StoreDispatchTicketRequest
{
    /** @var list<string>|null */
    private ?array $clientHenPriceCodes = null;

    protected function requiresDelivery(): bool
    {
        return false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['weighings.*'] = [
            'required',
            'array:local_id,chicken_type_code,chicken_condition,chicken_sex,chicken_variant_code,cage_type_code,origin,weight_source,scale_reading,birds_per_cage,cage_count,read_weight_kg,gross_weight_kg,weighed_at',
        ];
        $rules['weighings.*.chicken_type_code'] = [
            'required',
            Rule::in([
                TipoPollo::CHICKEN_LIVE,
                TipoPollo::CHICKEN_DEAD,
                TipoPollo::CHICKEN_DRESSED,
                TipoPollo::CHICKEN_PROCESSED,
                ...TipoPollo::wholesaleTwoManualPriceCodes(),
            ]),
        ];
        $rules['weighings.*.chicken_variant_code'] = [
            Rule::requiredIf(fn (): bool => $this->input('operation_type') === TicketDespacho::OPERATION_DISPATCH),
            'nullable',
            Rule::in(WholesaleTwoChickenVariant::codes()),
        ];
        $rules['weighings.*.chicken_sex'] = [
            Rule::requiredIf(fn (): bool => $this->input('operation_type') === TicketDespacho::OPERATION_RETURN),
            'nullable',
            Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE]),
        ];
        $rules['weighings.*.gross_weight_kg'] = [
            'sometimes',
            'nullable',
            'numeric',
            'gt:0',
            'max:99999999.999',
        ];
        $rules['manual_prices'] = [
            Rule::requiredIf(fn (): bool => $this->requiredTicketPriceCodes() !== []),
            'array:'.implode(',', TipoPollo::wholesaleTwoManualPriceCodes()),
        ];

        foreach (TipoPollo::wholesaleTwoManualPriceCodes() as $code) {
            $rules["manual_prices.{$code}"] = [
                Rule::requiredIf(
                    fn (): bool => in_array($code, $this->requiredTicketPriceCodes(), true)
                ),
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:99999999.99',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $weighings = collect($this->input('weighings', []))
            ->map(function (mixed $weighing): mixed {
                if (! is_array($weighing)) {
                    return $weighing;
                }

                return [
                    ...$weighing,
                    'chicken_variant_code' => filled($weighing['chicken_variant_code'] ?? null)
                        ? mb_strtoupper(trim((string) $weighing['chicken_variant_code']), 'UTF-8')
                        : null,
                ];
            })
            ->all();

        $manualPrices = $this->input('manual_prices');
        if (is_array($manualPrices)) {
            $manualPrices = collect($manualPrices)
                ->mapWithKeys(fn (mixed $price, mixed $code): array => [
                    mb_strtoupper(trim((string) $code), 'UTF-8') => $price,
                ])
                ->all();
        }

        $normalized = ['weighings' => $weighings];
        if ($this->exists('manual_prices')) {
            $normalized['manual_prices'] = $manualPrices;
        }

        $this->merge($normalized);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $operationType = $this->input('operation_type', TicketDespacho::OPERATION_DISPATCH);
            $destinationType = $this->input('destination.type');

            if ($operationType === TicketDespacho::OPERATION_RETURN && $destinationType !== 'CLIENTE') {
                $validator->errors()->add(
                    'destination.type',
                    'Las devoluciones deben registrarse contra un cliente.'
                );
            }

            $allowedManualPrices = collect($this->ticketSpecialPriceCodes());
            $requiredManualPrices = collect($this->requiredTicketPriceCodes());
            $submittedManualPrices = collect(
                is_array($this->input('manual_prices'))
                    ? array_keys($this->input('manual_prices'))
                    : []
            );

            if (
                $submittedManualPrices->diff($allowedManualPrices)->isNotEmpty()
                || $requiredManualPrices->diff($submittedManualPrices)->isNotEmpty()
            ) {
                $validator->errors()->add(
                    'manual_prices',
                    'Envía solo los precios manuales presentes en el ticket y asigna un precio a cada Gallina que no tenga tarifa de cliente.'
                );
            }

            foreach ($this->input('weighings', []) as $index => $weighing) {
                if (! is_array($weighing)) {
                    continue;
                }

                $origin = $weighing['origin'] ?? null;
                if (
                    is_array($origin)
                    && ! in_array($origin['type'] ?? null, ['PROVEEDOR', 'ALMACEN'], true)
                    && collect($origin)
                        ->except('type')
                        ->contains(fn (mixed $value): bool => filled($value))
                ) {
                    $validator->errors()->add(
                        "weighings.{$index}.origin.type",
                        'Indica si el origen seleccionado es un proveedor o un almacén.'
                    );
                }

                if ($operationType !== TicketDespacho::OPERATION_DISPATCH) {
                    continue;
                }

                $chickenTypeCode = $weighing['chicken_type_code'] ?? null;
                if ($chickenTypeCode === TipoPollo::CHICKEN_DEAD) {
                    $validator->errors()->add(
                        "weighings.{$index}.chicken_type_code",
                        'El pollo muerto solo aplica para devoluciones.'
                    );
                }

                $definition = WholesaleTwoChickenVariant::definition(
                    $weighing['chicken_variant_code'] ?? null
                );
                if (! $definition) {
                    continue;
                }

                if ($definition['chicken_type_code'] !== $chickenTypeCode) {
                    $validator->errors()->add(
                        "weighings.{$index}.chicken_variant_code",
                        'La clasificación seleccionada no corresponde al tipo de pollo.'
                    );
                }

                $submittedSex = filled($weighing['chicken_sex'] ?? null)
                    ? $weighing['chicken_sex']
                    : null;
                if ($submittedSex !== null && $submittedSex !== $definition['sex']) {
                    $validator->errors()->add(
                        "weighings.{$index}.chicken_sex",
                        'El sexo enviado no corresponde a la clasificación seleccionada.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'weighings.*.chicken_variant_code.required' => 'Selecciona la clasificación de cada pesada.',
            'weighings.*.chicken_variant_code.in' => 'Una de las clasificaciones de pollo no está disponible.',
            'manual_prices.required' => 'Asigna el precio de cada Gallina sin tarifa de cliente y de cada Otro incluido antes de registrar el ticket.',
            'manual_prices.array' => 'Los precios manuales no tienen un formato válido.',
            'manual_prices.*.required' => 'Asigna el precio del producto especial incluido en el ticket.',
            'manual_prices.*.numeric' => 'Cada precio manual debe ser un número válido.',
            'manual_prices.*.decimal' => 'Los precios manuales solo pueden usar hasta dos decimales.',
            'manual_prices.*.gt' => 'Cada precio manual debe ser mayor que cero.',
            'manual_prices.*.max' => 'Uno de los precios manuales supera el máximo permitido.',
        ];
    }

    /** @return list<string> */
    private function ticketSpecialPriceCodes(): array
    {
        if ($this->input('operation_type') !== TicketDespacho::OPERATION_DISPATCH) {
            return [];
        }

        return collect($this->input('weighings', []))
            ->filter(fn (mixed $weighing): bool => is_array($weighing))
            ->pluck('chicken_type_code')
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->filter(fn (string $code): bool => TipoPollo::requiresWholesaleTwoManualPrice($code))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function requiredTicketPriceCodes(): array
    {
        $clientHenPrices = $this->availableClientHenPriceCodes();

        return collect($this->ticketSpecialPriceCodes())
            ->filter(fn (string $code): bool => $code === TipoPollo::OTHER
                || ! in_array($code, $clientHenPrices, true))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function availableClientHenPriceCodes(): array
    {
        if ($this->clientHenPriceCodes !== null) {
            return $this->clientHenPriceCodes;
        }

        $companyId = (int) ($this->user()?->empresa_id ?? 0);
        $clientId = (int) $this->input('destination.id');
        if (
            $companyId <= 0
            || $clientId <= 0
            || $this->input('destination.type') !== 'CLIENTE'
        ) {
            return $this->clientHenPriceCodes = [];
        }

        return $this->clientHenPriceCodes = DB::table('precios_historial as precios')
            ->join('listas_precios as listas', 'listas.id', '=', 'precios.lista_precio_id')
            ->join('tipos_pollo as tipos', 'tipos.id', '=', 'precios.tipo_pollo_id')
            ->where('listas.empresa_id', $companyId)
            ->where('listas.tercero_id', $clientId)
            ->where('listas.operacion', 'VENTA')
            ->where('listas.estado', 'ACTIVO')
            ->whereNull('precios.vigente_hasta')
            ->whereIn('tipos.codigo', TipoPollo::wholesaleTwoClientPriceCodes())
            ->pluck('tipos.codigo')
            ->unique()
            ->values()
            ->all();
    }
}
