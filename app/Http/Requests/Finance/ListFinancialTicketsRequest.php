<?php

namespace App\Http\Requests\Finance;

use App\Models\Tercero;
use App\Models\TerceroRole;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ListFinancialTicketsRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ticket' => ['nullable', 'string', 'max:40'],
            'cliente' => [
                'nullable',
                'string',
                'max:120',
            ],
            'cliente_id' => [
                'nullable',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = DB::table('terceros as tercero')
                        ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
                        ->where('tercero.id', (int) $value)
                        ->where('tercero.empresa_id', $this->companyId())
                        ->whereIn('tercero.estado', [
                            Tercero::STATUS_ACTIVE,
                            Tercero::STATUS_INACTIVE,
                        ])
                        ->where('rol.rol', TerceroRole::CLIENT)
                        ->exists();

                    if (! $exists) {
                        $fail('Selecciona un cliente de esta empresa.');
                    }
                },
            ],
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
            'estado' => [
                'nullable',
                Rule::in(['VIGENTES', 'ANULADOS', 'TODOS']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('cliente') && $this->filled('cliente_id')) {
                $validator->errors()->add(
                    'cliente',
                    'Usa el nombre del cliente o el cliente seleccionado, no ambos.',
                );
                $validator->errors()->add(
                    'cliente_id',
                    'Usa el cliente seleccionado o el nombre escrito, no ambos.',
                );
            }

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
                && ! $this->filled('cliente_id')
                && ! ($this->filled('desde') && $this->filled('hasta'))
                && $this->input('estado') !== 'ANULADOS'
            ) {
                $validator->errors()->add(
                    'filtros',
                    'Debes filtrar por número de ticket, cliente, un rango completo de fecha y hora o seleccionar solo los anulados.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('estado');

        $this->merge([
            'ticket' => $this->trimmedNullable('ticket'),
            'cliente' => $this->trimmedNullable('cliente'),
            'desde' => $this->trimmedNullable('desde'),
            'hasta' => $this->trimmedNullable('hasta'),
            'estado' => ! $this->filled('estado')
                ? 'VIGENTES'
                : (is_scalar($status)
                    ? mb_strtoupper(trim((string) $status), 'UTF-8')
                    : $status),
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
            'estado.in' => 'Selecciona un estado de ticket válido.',
        ];
    }
}
