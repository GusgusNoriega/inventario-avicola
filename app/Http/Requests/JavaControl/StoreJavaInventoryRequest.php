<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;

class StoreJavaInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'java_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'tray_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'total_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'tray_total_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'java_quantity.required' => 'Indica el total completo de javas propiedad de la empresa.',
            'java_quantity.integer' => 'El total de javas debe ser un numero entero.',
            'java_quantity.min' => 'El total de javas no puede ser negativo.',
            'tray_quantity.integer' => 'El total de bandejas debe ser un numero entero.',
            'tray_quantity.min' => 'El total de bandejas no puede ser negativo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (! $this->exists('java_quantity') && $this->exists('total_quantity')) {
            $values['java_quantity'] = $this->input('total_quantity');
        }
        if (! $this->exists('tray_quantity') && $this->exists('tray_total_quantity')) {
            $values['tray_quantity'] = $this->input('tray_total_quantity');
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }
}
