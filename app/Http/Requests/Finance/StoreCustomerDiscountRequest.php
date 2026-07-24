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
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'motivo' => ['required', 'string', 'min:3', 'max:250'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'importe' => $this->normalizedMoney('importe'),
            'motivo' => trim((string) $this->input('motivo')),
        ]);
    }
}
