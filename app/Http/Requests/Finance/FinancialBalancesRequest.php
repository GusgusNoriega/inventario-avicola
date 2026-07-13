<?php

namespace App\Http\Requests\Finance;

class FinancialBalancesRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'moneda' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'incluir_inactivas' => ['nullable', 'boolean'],
        ];
    }
}
