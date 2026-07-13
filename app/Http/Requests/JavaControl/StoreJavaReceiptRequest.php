<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJavaReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'java_quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'tray_quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'observations' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Selecciona el cliente que devuelve los envases.',
            'vehicle_id.required' => 'Selecciona el camion que recogio los envases.',
            'driver_id.required' => 'Selecciona el chofer que recogio los envases.',
            'java_quantity.min' => 'La cantidad de javas no puede ser negativa.',
            'quantity.min' => 'La cantidad de javas no puede ser negativa.',
            'tray_quantity.min' => 'La cantidad de bandejas no puede ser negativa.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                (int) $this->input('java_quantity', 0) === 0
                && (int) $this->input('tray_quantity', 0) === 0
            ) {
                $validator->errors()->add(
                    $this->exists('quantity') ? 'quantity' : 'java_quantity',
                    'Indica al menos una java o una bandeja devuelta por el cliente.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('java_quantity') && $this->exists('quantity')) {
            $this->merge(['java_quantity' => $this->input('quantity')]);
        }
    }
}
