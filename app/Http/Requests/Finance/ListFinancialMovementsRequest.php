<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class ListFinancialMovementsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in([
                'COBRO_CLIENTE', 'PAGO_DIRECTO', 'PAGO_PROVEEDOR', 'COBRO_MINORISTA',
                'REEMBOLSO_CLIENTE', 'SALDO_INICIAL', 'AJUSTE', 'TRANSFERENCIA_INTERNA',
            ])],
            'estado' => ['nullable', Rule::in(['REGISTRADO', 'ANULADO'])],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'cuenta_id' => ['nullable', 'integer', 'min:1'],
            'metodo_pago_id' => ['nullable', 'integer', 'min:1'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
