<?php

namespace App\Http\Requests\Finance;

class SearchFinancialTicketClientsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'buscar' => $this->trimmedNullable('buscar'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'buscar.max' => 'La búsqueda de clientes no puede superar los 120 caracteres.',
        ];
    }
}
