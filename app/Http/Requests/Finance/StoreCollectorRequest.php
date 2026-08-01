<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class StoreCollectorRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:180',
                Rule::unique('cobradores', 'nombre')->where(
                    fn ($query) => $query->where('empresa_id', $this->companyId())
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nombre' => trim((string) $this->input('nombre'))]);
    }
}
