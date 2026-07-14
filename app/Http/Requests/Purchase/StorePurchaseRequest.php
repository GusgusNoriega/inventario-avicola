<?php

namespace App\Http\Requests\Purchase;

use App\Http\Requests\Finance\FinancialFormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'proveedor_id' => ['required', 'integer', 'min:1'],
            'tipo_documento' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_ -]+$/'],
            'numero_documento' => ['nullable', 'string', 'max:80'],
            'fecha_compra' => ['required', 'date_format:Y-m-d'],
            'fecha_vencimiento' => [
                'required_if:condicion,CREDITO',
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:fecha_compra',
            ],
            'condicion' => ['required', Rule::in(['CONTADO', 'CREDITO'])],
            'moneda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'impuesto' => ['sometimes', 'regex:/^\d{1,12}(?:\.\d{2})$/'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'detalles' => ['required', 'array', 'min:1', 'max:100'],
            'detalles.*.tipo_pollo_id' => ['nullable', 'integer', 'min:1'],
            'detalles.*.descripcion' => ['required', 'string', 'max:250'],
            'detalles.*.cantidad_aves' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'detalles.*.peso_kg' => ['required', 'regex:/^\d{1,9}(?:\.\d{3})$/', 'not_in:0.000'],
            'detalles.*.precio_kg' => ['required', 'regex:/^\d{1,8}(?:\.\d{4})$/', 'not_in:0.0000'],
            'pago' => ['required_if:condicion,CONTADO', 'prohibited_unless:condicion,CONTADO', 'array'],
            'pago.cuenta_origen_id' => ['required_if:condicion,CONTADO', 'integer', 'min:1'],
            'pago.cuenta_destino_id' => [
                'required_if:condicion,CONTADO',
                'integer',
                'min:1',
                'different:pago.cuenta_origen_id',
            ],
            'pago.metodo_pago_id' => ['required_if:condicion,CONTADO', 'integer', 'min:1'],
            'pago.referencia' => ['nullable', 'string', 'max:100'],
            'pago.fecha_hora' => ['nullable', 'date'],
            'pago.observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $details = collect($this->input('detalles', []))
            ->map(function (mixed $detail): mixed {
                if (! is_array($detail)) {
                    return $detail;
                }

                $detail['descripcion'] = $this->trimmedValue($detail['descripcion'] ?? null);
                if (array_key_exists('peso_kg', $detail)) {
                    $detail['peso_kg'] = $this->normalizeDecimal($detail['peso_kg'], 3);
                }
                if (array_key_exists('precio_kg', $detail)) {
                    $detail['precio_kg'] = $this->normalizeDecimal($detail['precio_kg'], 4);
                }

                return $detail;
            })
            ->all();
        $payment = $this->input('pago');
        if (is_array($payment)) {
            $payment['referencia'] = $this->trimmedArrayValue($payment, 'referencia');
            $payment['observaciones'] = $this->trimmedArrayValue($payment, 'observaciones');
        }

        $values = [
            'idempotency_key' => $this->lowercaseValue($this->input('idempotency_key')),
            'tipo_documento' => $this->uppercaseValue($this->input('tipo_documento')),
            'numero_documento' => $this->filled('numero_documento')
                ? $this->uppercaseValue($this->input('numero_documento'))
                : null,
            'condicion' => $this->uppercaseValue($this->input('condicion'), false),
            'moneda' => $this->uppercaseValue(
                in_array($this->input('moneda'), [null, ''], true) ? 'PEN' : $this->input('moneda'),
                false,
            ),
            'impuesto' => $this->normalizedMoneyValue('impuesto') ?? '0.00',
            'observaciones' => $this->nullableTrimmedValue($this->input('observaciones')),
            'detalles' => $details,
        ];
        if (is_array($payment)) {
            $values['pago'] = $payment;
        }

        $this->merge($values);
    }

    private function normalizeDecimal(mixed $value, int $scale): mixed
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return number_format((float) $value, $scale, '.', '');
        }

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/', $value)) {
            return $value;
        }

        [$integer, $decimals] = array_pad(explode('.', $value, 2), 2, '');

        return $integer.'.'.str_pad($decimals, $scale, '0');
    }

    /** @param array<string, mixed> $values */
    private function trimmedArrayValue(array $values, string $key): mixed
    {
        return $this->nullableTrimmedValue($values[$key] ?? null);
    }

    private function normalizedMoneyValue(string $key): mixed
    {
        $value = $this->input($key);
        if (! is_int($value) && ! is_float($value) && ! is_string($value) && $value !== null) {
            return $value;
        }

        return $this->normalizedMoney($key);
    }

    private function trimmedValue(mixed $value): mixed
    {
        return is_scalar($value) ? trim((string) $value) : $value;
    }

    private function nullableTrimmedValue(mixed $value): mixed
    {
        $value = $this->trimmedValue($value);

        return $value === '' ? null : $value;
    }

    private function uppercaseValue(mixed $value, bool $multibyte = true): mixed
    {
        $value = $this->trimmedValue($value);
        if (! is_string($value)) {
            return $value;
        }

        return $multibyte ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function lowercaseValue(mixed $value): mixed
    {
        $value = $this->trimmedValue($value);

        return is_string($value) ? strtolower($value) : $value;
    }
}
