<?php

namespace App\Http\Requests\ProductDispatch;

use App\Http\Requests\Finance\FinancialFormRequest;

class SaveProductDispatchCustomerPaymentRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'uuid'],
            'cliente_id' => ['required', 'integer', 'min:1'],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'metodo_pago_id' => ['required', 'integer', 'min:1'],
            'fecha_hora' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'moneda' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'cuenta_destino_id' => ['nullable', 'integer', 'min:1'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['prohibited'],
            'proveedor_id' => ['prohibited'],
            'cuenta_origen_id' => ['prohibited'],
            'aplicaciones' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('importe');
        $currency = $this->input('moneda');
        $reference = $this->input('referencia');
        $notes = $this->input('observaciones');
        $data = [
            'importe' => is_string($amount) || is_int($amount) || is_float($amount)
                ? $this->normalizedMoney('importe') : $amount,
            'moneda' => is_string($currency) ? strtoupper(trim($currency)) : $currency,
            'referencia' => is_string($reference) ? (trim($reference) === '' ? null : trim($reference)) : $reference,
            'observaciones' => is_string($notes) ? (trim($notes) === '' ? null : trim($notes)) : $notes,
        ];
        if ($this->exists('idempotency_key')) {
            $key = $this->input('idempotency_key');
            $data['idempotency_key'] = is_string($key) ? strtolower(trim($key)) : $key;
        }
        $this->merge($data);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cliente_id.required' => 'Selecciona el cliente que realizó el pago.',
            'importe.required' => 'Ingresa el importe del pago.',
            'importe.regex' => 'Ingresa un importe válido con un máximo de dos decimales.',
            'importe.not_in' => 'El importe debe ser mayor que cero.',
            'metodo_pago_id.required' => 'Selecciona el método de pago.',
            'fecha_hora.date_format' => 'La fecha y hora del pago no son válidas.',
        ];
    }
}
