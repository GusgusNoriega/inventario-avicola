<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class ListCustomerDiscountsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['REGISTRADO', 'ANULADO'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'buscar' => $this->filled('buscar')
                ? trim((string) $this->input('buscar'))
                : null,
            'estado' => $this->filled('estado')
                ? strtoupper(trim((string) $this->input('estado')))
                : null,
        ]);
    }
}
