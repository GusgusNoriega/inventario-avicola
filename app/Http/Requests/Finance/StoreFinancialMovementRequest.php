<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class StoreFinancialMovementRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'tipo' => ['required', Rule::in([
                'COBRO_CLIENTE',
                'PAGO_DIRECTO',
                'PAGO_PROVEEDOR',
                'COBRO_MINORISTA',
                'REEMBOLSO_CLIENTE',
                'SALDO_INICIAL',
                'AJUSTE',
                'TRANSFERENCIA_INTERNA',
            ])],
            'fecha_hora' => ['nullable', 'date'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'cuenta_origen_id' => ['nullable', 'integer', 'min:1'],
            'cuenta_destino_id' => ['nullable', 'integer', 'min:1', 'different:cuenta_origen_id'],
            'metodo_pago_id' => ['nullable', 'integer', 'min:1'],
            'moneda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'aplicaciones' => ['sometimes', 'array', 'max:100'],
            'aplicaciones.*.lado' => ['required', Rule::in(['CXC', 'CXP'])],
            'aplicaciones.*.comprobante_id' => ['required', 'integer', 'min:1', 'distinct'],
            'aplicaciones.*.importe_aplicado' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{2})$/',
                'not_in:0.00',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $applications = collect($this->input('aplicaciones', []))
            ->map(function (mixed $application): mixed {
                if (! is_array($application)) {
                    return $application;
                }

                $application['lado'] = strtoupper(trim((string) ($application['lado'] ?? '')));
                if (! array_key_exists('importe_aplicado', $application) && array_key_exists('importe', $application)) {
                    $application['importe_aplicado'] = $application['importe'];
                }
                if (array_key_exists('importe_aplicado', $application)) {
                    $application['importe_aplicado'] = $this->normalizeValue($application['importe_aplicado']);
                }

                return $application;
            })
            ->all();

        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'tipo' => strtoupper(trim((string) $this->input('tipo'))),
            'moneda' => strtoupper(trim((string) ($this->input('moneda') ?: 'PEN'))),
            'importe' => $this->normalizeValue($this->input('importe')),
            'referencia' => $this->trimmedNullable('referencia'),
            'observaciones' => $this->trimmedNullable('observaciones'),
            'aplicaciones' => $applications,
        ]);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_int($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_float($value) && is_finite($value)) {
            return number_format($value, 2, '.', '');
        }

        $value = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            [$integer, $decimals] = array_pad(explode('.', $value, 2), 2, '');

            return $integer.'.'.str_pad($decimals, 2, '0');
        }

        return $value;
    }
}
