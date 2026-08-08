<?php

namespace App\Http\Requests\Finance;

class UpdateCollectionCashReceiptRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recibido' => ['required', 'boolean'],
            'estado_esperado' => ['present', 'nullable', 'boolean'],
        ];
    }
}
