<?php

namespace App\Http\Requests\Finance;

class FinancialTraceRequest extends ListFinancialMovementsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'entidad_financiera_id' => ['nullable', 'integer', 'min:1'],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
            'comprobante_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
