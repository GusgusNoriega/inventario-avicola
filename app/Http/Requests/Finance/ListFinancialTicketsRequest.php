<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\Validator;

class ListFinancialTicketsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ticket' => ['nullable', 'string', 'max:40'],
            'cliente' => ['nullable', 'string', 'max:120'],
            'desde' => [
                'nullable',
                'date_format:Y-m-d\TH:i',
                'required_with:hasta',
            ],
            'hasta' => [
                'nullable',
                'date_format:Y-m-d\TH:i',
                'required_with:desde',
                'after_or_equal:desde',
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['ticket', 'cliente'] as $field) {
                if (! $this->filled($field)) {
                    continue;
                }

                $literal = preg_replace(
                    '/[%_\s]+/u',
                    '',
                    (string) $this->input($field),
                );

                if ($literal === '') {
                    $validator->errors()->add(
                        $field,
                        'El filtro debe incluir al menos un carácter distinto de % o _.',
                    );
                }
            }

            if (
                ! $this->filled('ticket')
                && ! $this->filled('cliente')
                && ! ($this->filled('desde') && $this->filled('hasta'))
            ) {
                $validator->errors()->add(
                    'filtros',
                    'Debes filtrar por número de ticket, cliente o un rango completo de fecha y hora.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ticket' => $this->trimmedNullable('ticket'),
            'cliente' => $this->trimmedNullable('cliente'),
            'desde' => $this->trimmedNullable('desde'),
            'hasta' => $this->trimmedNullable('hasta'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'desde.required_with' => 'Completa la fecha y hora inicial del rango.',
            'hasta.required_with' => 'Completa la fecha y hora final del rango.',
            'desde.date_format' => 'La fecha y hora inicial no tiene un formato válido.',
            'hasta.date_format' => 'La fecha y hora final no tiene un formato válido.',
            'hasta.after_or_equal' => 'La fecha y hora final debe ser igual o posterior a la inicial.',
        ];
    }
}
