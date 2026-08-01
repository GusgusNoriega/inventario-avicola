<?php

namespace App\Http\Requests\Finance;

use App\Models\Cobrador;
use Illuminate\Validation\Rule;

class UpdateCollectorRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:180',
                Rule::unique('cobradores', 'nombre')
                    ->where(fn ($query) => $query->where('empresa_id', $this->companyId()))
                    ->ignore((int) $this->route('cobrador')),
            ],
            'estado' => ['required', Rule::in([
                Cobrador::STATUS_ACTIVE,
                Cobrador::STATUS_INACTIVE,
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => trim((string) $this->input('nombre')),
            'estado' => strtoupper(trim((string) $this->input('estado'))),
        ]);
    }
}
