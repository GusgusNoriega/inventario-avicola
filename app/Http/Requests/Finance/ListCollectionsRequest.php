<?php

namespace App\Http\Requests\Finance;

use App\Models\Cobranza;
use Illuminate\Validation\Rule;

class ListCollectionsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'cobrador_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in([
                Cobranza::STATUS_REGISTERED,
                Cobranza::STATUS_VOIDED,
            ])],
            'conciliacion' => ['nullable', Rule::in(['PENDIENTE', 'COMPLETA'])],
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'estado' => $this->filled('estado')
                ? strtoupper(trim((string) $this->input('estado')))
                : null,
            'conciliacion' => $this->filled('conciliacion')
                ? strtoupper(trim((string) $this->input('conciliacion')))
                : null,
            'buscar' => $this->trimmedNullable('buscar'),
        ]);
    }
}
