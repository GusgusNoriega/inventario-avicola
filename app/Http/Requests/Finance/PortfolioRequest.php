<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class PortfolioRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lado' => ['required', Rule::in(['CXC', 'CXP'])],
            'tercero_id' => ['nullable', 'integer', 'min:1'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in(['BORRADOR', 'PENDIENTE', 'PARCIAL', 'PAGADO', 'ANULADO'])],
            'naturaleza' => ['nullable', Rule::in(['CARGO', 'ABONO'])],
            'moneda' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'solo_pendientes' => ['nullable', 'boolean'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lado' => strtoupper(trim((string) $this->input('lado'))),
            'moneda' => $this->filled('moneda')
                ? strtoupper(trim((string) $this->input('moneda')))
                : null,
            'solo_pendientes' => $this->boolean('solo_pendientes', true),
        ]);
    }
}
