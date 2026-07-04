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
            'total_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'total_quantity.required' => 'Indica el total completo de javas propiedad de la empresa.',
            'total_quantity.integer' => 'El total de javas debe ser un número entero.',
            'total_quantity.min' => 'El total de javas no puede ser negativo.',
        ];
    }
}
