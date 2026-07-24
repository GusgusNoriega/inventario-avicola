<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class ListManualCustomerDebtsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['PENDIENTE', 'PARCIAL', 'PAGADO', 'ANULADO'])],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'moneda' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => $this->filled('estado')
                ? strtoupper(trim((string) $this->input('estado')))
                : null,
            'moneda' => $this->filled('moneda')
                ? strtoupper(trim((string) $this->input('moneda')))
                : null,
        ]);
    }
}
