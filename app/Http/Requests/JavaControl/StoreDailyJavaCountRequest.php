<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyJavaCountRequest extends FormRequest
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
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'java_quantity.required' => 'Indica cuantas javas se contaron dentro del local.',
            'java_quantity.integer' => 'El conteo diario de javas debe ser un numero entero.',
            'java_quantity.min' => 'El conteo diario de javas no puede ser negativo.',
            'tray_quantity.integer' => 'El conteo diario de bandejas debe ser un numero entero.',
            'tray_quantity.min' => 'El conteo diario de bandejas no puede ser negativo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('java_quantity') && $this->exists('quantity')) {
            $this->merge(['java_quantity' => $this->input('quantity')]);
        }
    }
}
