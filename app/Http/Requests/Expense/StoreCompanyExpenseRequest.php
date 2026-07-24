<?php

namespace App\Http\Requests\Expense;

use App\Http\Requests\Finance\FinancialFormRequest;
use App\Models\GastoEmpresa;
use Illuminate\Validation\Rule;

class StoreCompanyExpenseRequest extends FinancialFormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            ...$this->expenseRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeExpense();
    }

    /** @return array<string, mixed> */
    protected function expenseRules(): array
    {
        return [
            'fecha_hora' => ['required', 'date'],
            'categoria' => ['required', Rule::in(GastoEmpresa::CATEGORIES)],
            'concepto' => ['required', 'string', 'max:250'],
            'destino' => ['required', 'string', 'max:250'],
            'numero_documento' => ['nullable', 'string', 'max:100'],
            'cuenta_origen_id' => ['required', 'integer', 'min:1'],
            'metodo_pago_id' => ['required', 'integer', 'min:1'],
            'moneda' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'importe' => ['required', 'regex:/^\d{1,12}(?:\.\d{2})$/', 'not_in:0.00'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function normalizeExpense(): void
    {
        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'categoria' => strtoupper(trim((string) $this->input('categoria'))),
            'concepto' => trim((string) $this->input('concepto')),
            'destino' => trim((string) $this->input('destino')),
            'numero_documento' => $this->trimmedNullable('numero_documento'),
            'moneda' => strtoupper(trim((string) ($this->input('moneda') ?: 'PEN'))),
            'importe' => $this->normalizedMoney('importe'),
            'referencia' => $this->trimmedNullable('referencia'),
            'observaciones' => $this->trimmedNullable('observaciones'),
        ]);
    }
}
