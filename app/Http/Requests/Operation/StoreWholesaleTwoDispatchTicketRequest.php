<?php

namespace App\Http\Requests\Operation;

use App\Models\Pesada;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWholesaleTwoDispatchTicketRequest extends StoreDispatchTicketRequest
{
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

        $this->merge(['weighings' => $weighings]);
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
        ];
    }
}
