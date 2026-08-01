<?php

namespace App\Http\Requests\Finance;

class ListCollectorsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'incluir_inactivos' => ['nullable', 'boolean'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'buscar' => $this->trimmedNullable('buscar'),
        ]);
    }
}
