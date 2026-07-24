<?php

namespace App\Http\Requests\Finance;

class UpdateFinancialMovementRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_hora' => ['required', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'referencia' => $this->trimmedNullable('referencia'),
            'observaciones' => $this->trimmedNullable('observaciones'),
        ]);
    }
}
