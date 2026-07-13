<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['BANCO', 'CAJA', 'BILLETERA', 'OTRA'])],
            'alias' => ['required', 'string', 'max:100'],
            'banco' => ['nullable', 'string', 'max:120'],
            'numero_cuenta' => ['nullable', 'string', 'max:80'],
            'cci' => ['nullable', 'string', 'max:80'],
            'moneda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tipo' => strtoupper(trim((string) $this->input('tipo'))),
            'alias' => mb_strtoupper(trim((string) $this->input('alias'))),
            'banco' => $this->trimmedNullable('banco') === null
                ? null
                : mb_strtoupper((string) $this->trimmedNullable('banco')),
            'numero_cuenta' => $this->trimmedNullable('numero_cuenta'),
            'cci' => $this->trimmedNullable('cci'),
            'moneda' => strtoupper(trim((string) ($this->input('moneda') ?: 'PEN'))),
        ]);
    }
}
