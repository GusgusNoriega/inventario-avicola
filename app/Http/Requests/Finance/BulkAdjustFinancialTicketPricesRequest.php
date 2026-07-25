<?php

namespace App\Http\Requests\Finance;

use App\Models\TipoPollo;
use Illuminate\Validation\Rule;

class BulkAdjustFinancialTicketPricesRequest extends ListFinancialTicketsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['required', 'uuid'],
            'operacion' => ['required', Rule::in(['AUMENTAR', 'DISMINUIR'])],
            'tipo_pollo_id' => [
                'required',
                'integer',
                Rule::exists('tipos_pollo', 'id')
                    ->where(fn ($query) => $query->where('estado', TipoPollo::STATUS_ACTIVE)),
            ],
            'monto' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'idempotency_key' => $this->filled('idempotency_key')
                ? mb_strtolower(trim((string) $this->input('idempotency_key')), 'UTF-8')
                : null,
            'operacion' => $this->filled('operacion')
                ? mb_strtoupper(trim((string) $this->input('operacion')), 'UTF-8')
                : null,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'idempotency_key.required' => 'Envía una clave UUID de idempotencia para procesar el ajuste.',
            'idempotency_key.uuid' => 'La clave de idempotencia debe ser un UUID válido.',
            'operacion.required' => 'Selecciona si deseas aumentar o disminuir los precios.',
            'operacion.in' => 'La operación seleccionada no es válida.',
            'tipo_pollo_id.required' => 'Selecciona el tipo de pollo cuyo precio deseas ajustar.',
            'tipo_pollo_id.exists' => 'El tipo de pollo seleccionado no está disponible.',
            'monto.required' => 'Ingresa el monto del ajuste.',
            'monto.gt' => 'El monto del ajuste debe ser mayor que cero.',
            'monto.decimal' => 'El monto puede tener hasta cuatro decimales.',
        ];
    }
}
