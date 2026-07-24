<?php

namespace App\Http\Requests\Finance;

class UpdateManualCustomerDebtRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', 'min:1'],
            'fecha_emision' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'moneda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'detalle' => ['required', 'string', 'max:250'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'moneda' => strtoupper(trim((string) ($this->input('moneda') ?: 'PEN'))),
            'importe' => $this->normalizedMoney('importe'),
            'detalle' => trim((string) $this->input('detalle')),
        ]);
    }
}
