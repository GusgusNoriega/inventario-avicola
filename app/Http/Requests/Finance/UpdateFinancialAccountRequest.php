<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends StoreFinancialAccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'estado' => ['sometimes', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }
}
