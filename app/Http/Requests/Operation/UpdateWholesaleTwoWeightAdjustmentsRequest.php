<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMayoristaDos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWholesaleTwoWeightAdjustmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'adjustments' => [
                'required',
                'array',
                'min:'.count(AjustePesoMayoristaDos::configurableCodes()),
                'max:'.count(AjustePesoMayoristaDos::codes()),
            ],
            'adjustments.*' => ['required', 'array:code,additional_grams'],
            'adjustments.*.code' => [
                'required',
                'distinct',
                Rule::in(AjustePesoMayoristaDos::codes()),
            ],
            'adjustments.*.additional_grams' => [
                'required',
                'integer',
                'between:0,1000000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $adjustments = collect($this->input('adjustments', []))
            ->map(function (mixed $adjustment): mixed {
                if (! is_array($adjustment)) {
                    return $adjustment;
                }

                return [
                    ...$adjustment,
                    'code' => mb_strtoupper(
                        trim((string) ($adjustment['code'] ?? '')),
                        'UTF-8'
                    ),
                ];
            })
            ->all();

        $this->merge(['adjustments' => $adjustments]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $adjustments = collect($this->input('adjustments', []))
                ->filter(fn (mixed $adjustment): bool => is_array($adjustment));
            $submittedCodes = $adjustments->pluck('code');
            $missing = collect(AjustePesoMayoristaDos::configurableCodes())
                ->diff($submittedCodes);

            if ($missing->isNotEmpty()) {
                $validator->errors()->add(
                    'adjustments',
                    'Envía exactamente todas las mermas configurables de Despacho mayorista 2.'
                );
            }

            $invalidLockedAdjustment = $adjustments->contains(
                fn (array $adjustment): bool => in_array(
                    $adjustment['code'] ?? null,
                    AjustePesoMayoristaDos::nonConfigurableCodes(),
                    true,
                ) && (int) ($adjustment['additional_grams'] ?? 0) !== 0
            );
            if ($invalidLockedAdjustment) {
                $validator->errors()->add(
                    'adjustments',
                    'Las clasificaciones bloqueadas no admiten merma.'
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'adjustments.required' => 'Envía la configuración de mermas.',
            'adjustments.min' => 'Debes configurar todas las clasificaciones que admiten merma.',
            'adjustments.max' => 'La configuración contiene clasificaciones adicionales.',
            'adjustments.*.code.distinct' => 'Cada clasificación solo puede aparecer una vez.',
            'adjustments.*.code.in' => 'Una de las clasificaciones no está disponible.',
            'adjustments.*.additional_grams.required' => 'Indica la merma en gramos por ave.',
            'adjustments.*.additional_grams.integer' => 'La merma debe expresarse en gramos enteros.',
            'adjustments.*.additional_grams.between' => 'La merma debe estar entre 0 y 1.000.000 de gramos por ave.',
        ];
    }
}
