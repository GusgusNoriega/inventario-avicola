<?php

namespace App\Http\Requests\Finance;

class ListCashRegisterMovementsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'caja_id' => ['required', 'integer', 'min:1'],
            'fecha' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fecha' => $this->filled('fecha')
                ? trim((string) $this->input('fecha'))
                : now()->toDateString(),
        ]);
    }
}
