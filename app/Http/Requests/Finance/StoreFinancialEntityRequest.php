<?php

namespace App\Http\Requests\Finance;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StoreFinancialEntityRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['PROPIA', 'EXTERNA'])],
            'proveedor_id' => [
                Rule::requiredIf(fn (): bool => $this->input('tipo') === 'EXTERNA'),
                'nullable',
                'integer',
                Rule::exists('terceros', 'id')->where(fn (Builder $query) => $query
                    ->where('empresa_id', $this->companyId())
                    ->where('estado', 'ACTIVO')),
            ],
            'tipo_documento' => ['nullable', 'string', 'max:20'],
            'numero_documento' => ['nullable', 'string', 'max:30'],
            'razon_social' => ['required', 'string', 'max:180'],
            'nombre_comercial' => ['nullable', 'string', 'max:180'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:180'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tipo' => strtoupper(trim((string) $this->input('tipo'))),
            'tipo_documento' => $this->trimmedNullable('tipo_documento') === null
                ? null
                : strtoupper((string) $this->trimmedNullable('tipo_documento')),
            'numero_documento' => $this->trimmedNullable('numero_documento'),
            'razon_social' => mb_strtoupper(trim((string) $this->input('razon_social'))),
            'nombre_comercial' => $this->trimmedNullable('nombre_comercial') === null
                ? null
                : mb_strtoupper((string) $this->trimmedNullable('nombre_comercial')),
            'direccion' => $this->trimmedNullable('direccion'),
            'telefono' => $this->trimmedNullable('telefono'),
            'email' => $this->trimmedNullable('email'),
        ]);
    }
}
