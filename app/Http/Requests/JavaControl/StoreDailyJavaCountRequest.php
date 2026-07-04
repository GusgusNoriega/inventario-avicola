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
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Indica cuántas javas se contaron dentro del local.',
            'quantity.integer' => 'El conteo diario debe ser un número entero.',
            'quantity.min' => 'El conteo diario no puede ser negativo.',
        ];
    }
}
