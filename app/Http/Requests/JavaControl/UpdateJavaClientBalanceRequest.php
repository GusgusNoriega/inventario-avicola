<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJavaClientBalanceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_java_balance' => ['required', 'integer', 'min:0', 'max:100000'],
            'expected_tray_balance' => ['required', 'integer', 'min:0', 'max:100000'],
            'java_balance' => ['required', 'integer', 'min:0', 'max:100000'],
            'tray_balance' => ['required', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_java_balance.required' => 'Recarga la pantalla para confirmar el saldo actual de javas.',
            'expected_tray_balance.required' => 'Recarga la pantalla para confirmar el saldo actual de bandejas.',
            'java_balance.required' => 'Indica el nuevo total de javas que debe el cliente.',
            'java_balance.integer' => 'El nuevo saldo de javas debe ser un número entero.',
            'java_balance.min' => 'El nuevo saldo de javas no puede ser negativo.',
            'tray_balance.required' => 'Indica el nuevo total de bandejas que debe el cliente.',
            'tray_balance.integer' => 'El nuevo saldo de bandejas debe ser un número entero.',
            'tray_balance.min' => 'El nuevo saldo de bandejas no puede ser negativo.',
            'reason.required' => 'Explica el motivo de la corrección para conservar la trazabilidad.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
            'reason.max' => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
