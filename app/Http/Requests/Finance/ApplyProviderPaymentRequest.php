<?php

namespace App\Http\Requests\Finance;

class ApplyProviderPaymentRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'aplicaciones' => ['required', 'array', 'min:1', 'max:100'],
            'aplicaciones.*.comprobante_id' => ['required', 'integer', 'min:1', 'distinct'],
            'aplicaciones.*.importe_aplicado' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{2})$/',
                'not_in:0.00',
            ],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $applications = collect($this->input('aplicaciones', []))
            ->map(function (mixed $application): mixed {
                if (! is_array($application)) {
                    return $application;
                }

                if (! array_key_exists('importe_aplicado', $application)
                    && array_key_exists('importe', $application)) {
                    $application['importe_aplicado'] = $application['importe'];
                }
                if (array_key_exists('importe_aplicado', $application)) {
                    $application['importe_aplicado'] = $this->normalizeAmount(
                        $application['importe_aplicado']
                    );
                }

                return $application;
            })
            ->all();

        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'aplicaciones' => $applications,
            'observaciones' => $this->trimmedNullable('observaciones'),
        ]);
    }

    private function normalizeAmount(mixed $value): mixed
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
