<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class ListFinancialEntitiesRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(['PROPIA', 'EXTERNA'])],
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in(['ACTIVO', 'INACTIVO'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
