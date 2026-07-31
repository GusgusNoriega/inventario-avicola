<?php

namespace App\Http\Requests\Finance;

use App\Models\MovimientoCajaEfectivo;
use Illuminate\Validation\Rule;

class StoreCashRegisterMovementRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...$this->movementRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeMovement();
    }

    /** @return array<string, mixed> */
    protected function movementRules(): array
    {
        $direction = strtoupper(trim((string) $this->input('direccion')));
        $counterpart = strtoupper(trim((string) $this->input('contraparte_tipo')));
        $allowedCounterparts = $direction === MovimientoCajaEfectivo::DIRECTION_EXPENSE
            ? [
                MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER,
                MovimientoCajaEfectivo::COUNTERPART_OTHER,
            ]
            : MovimientoCajaEfectivo::COUNTERPARTS;

        return [
            'caja_id' => ['required', 'integer', 'min:1'],
            'direccion' => ['required', Rule::in(MovimientoCajaEfectivo::DIRECTIONS)],
            'contraparte_tipo' => ['required', Rule::in($allowedCounterparts)],
            'cliente_id' => [
                'nullable',
                Rule::requiredIf($counterpart === MovimientoCajaEfectivo::COUNTERPART_CUSTOMER),
                Rule::prohibitedIf($counterpart !== MovimientoCajaEfectivo::COUNTERPART_CUSTOMER),
                'integer',
                'min:1',
            ],
            'otra_caja_id' => [
                'nullable',
                Rule::requiredIf($counterpart === MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER),
                Rule::prohibitedIf($counterpart !== MovimientoCajaEfectivo::COUNTERPART_CASH_REGISTER),
                'integer',
                'min:1',
                'different:caja_id',
            ],
            'fecha_hora' => ['required', 'date'],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'detalle' => ['required', 'string', 'max:500'],
        ];
    }

    protected function normalizeMovement(): void
    {
        $this->merge([
            'idempotency_key' => $this->filled('idempotency_key')
                ? strtolower(trim((string) $this->input('idempotency_key')))
                : null,
            'direccion' => strtoupper(trim((string) $this->input('direccion'))),
            'contraparte_tipo' => strtoupper(trim((string) $this->input('contraparte_tipo'))),
            'importe' => $this->normalizedMoney('importe'),
            'detalle' => trim((string) $this->input('detalle')),
        ]);
    }
}
