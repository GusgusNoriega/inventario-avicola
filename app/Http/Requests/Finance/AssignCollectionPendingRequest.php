<?php

namespace App\Http\Requests\Finance;

class AssignCollectionPendingRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'detalles' => ['required', 'array', 'min:1', 'max:200'],
            'detalles.*.cliente_id' => ['required', 'integer', 'min:1'],
            'detalles.*.fecha_recepcion' => ['required', 'date_format:Y-m-d'],
            'detalles.*.importe' => [
                'required',
                'regex:/^\d{1,12}(?:\.\d{2})$/',
                'not_in:0.00',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $details = collect($this->input('detalles', []))
            ->map(function (mixed $detail): mixed {
                if (! is_array($detail)) {
                    return $detail;
                }

                if (array_key_exists('importe', $detail)) {
                    $detail['importe'] = $this->normalizeAmount($detail['importe']);
                }

                return $detail;
            })
            ->all();

        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'detalles' => $details,
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
