<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;

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
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'observations' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Selecciona el cliente que devuelve las javas.',
            'vehicle_id.required' => 'Selecciona el camión que recogió las javas.',
            'driver_id.required' => 'Selecciona el chofer que recogió las javas.',
            'quantity.required' => 'Indica cuántas javas devolvió el cliente.',
            'quantity.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
