<?php

namespace App\Http\Requests\Expense;

use App\Http\Requests\Finance\FinancialFormRequest;
use App\Models\GastoEmpresa;
use Illuminate\Validation\Rule;

class ListCompanyExpensesRequest extends FinancialFormRequest
{
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::in(GastoEmpresa::CATEGORIES)],
            'estado' => ['nullable', Rule::in([
                GastoEmpresa::STATUS_REGISTERED,
                GastoEmpresa::STATUS_VOIDED,
            ])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'categoria' => $this->filled('categoria')
                ? strtoupper(trim((string) $this->input('categoria')))
                : null,
            'estado' => $this->filled('estado')
                ? strtoupper(trim((string) $this->input('estado')))
                : null,
        ]);
    }
}
