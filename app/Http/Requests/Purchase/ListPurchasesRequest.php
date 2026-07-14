<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPurchasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'proveedor_id' => ['nullable', 'integer', 'min:1'],
            'condicion' => ['nullable', Rule::in(['CONTADO', 'CREDITO', 'LEGADO'])],
            'estado' => ['nullable', Rule::in(['PENDIENTE', 'PARCIAL', 'PAGADO', 'ANULADO'])],
            'moneda' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'condicion' => $this->normalizedFilter('condicion', true),
            'estado' => $this->normalizedFilter('estado', true),
            'moneda' => $this->normalizedFilter('moneda', true),
            'buscar' => $this->normalizedFilter('buscar'),
        ]);
    }

    private function normalizedFilter(string $key, bool $uppercase = false): mixed
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value)) {
            return $value;
        }

        $value = trim((string) $value);

        return $uppercase ? strtoupper($value) : $value;
    }
}
