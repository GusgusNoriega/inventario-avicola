<?php

namespace App\Http\Requests\Finance;

class StoreCustomerDiscountRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'cliente_id' => ['required', 'integer', 'min:1'],
            'fecha_transaccion' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:1970-01-01',
                'before_or_equal:today',
            ],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'motivo' => ['required', 'string', 'min:3', 'max:250'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha_transaccion.date_format' => 'La fecha de la transacción no tiene un formato válido.',
            'fecha_transaccion.after_or_equal' => 'La fecha de la transacción debe ser posterior a 1969.',
            'fecha_transaccion.before_or_equal' => 'La fecha de la transacción no puede ser futura.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'fecha_transaccion' => $this->trimmedNullable('fecha_transaccion'),
            'importe' => $this->normalizedMoney('importe'),
            'motivo' => trim((string) $this->input('motivo')),
        ]);
    }
}
