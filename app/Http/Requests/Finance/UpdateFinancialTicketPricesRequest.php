<?php

namespace App\Http\Requests\Finance;

class UpdateFinancialTicketPricesRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'precios' => ['required', 'array', 'min:1'],
            'precios.*.id' => ['required', 'integer', 'distinct', 'min:1'],
            'precios.*.precio_kg' => [
                'required',
                'numeric',
                'decimal:0,4',
                'gt:0',
                'max:99999999.9999',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'precios.required' => 'El ticket no tiene precios para actualizar.',
            'precios.*.id.distinct' => 'No puedes enviar el mismo precio más de una vez.',
            'precios.*.precio_kg.gt' => 'Todos los precios deben ser mayores que cero.',
            'precios.*.precio_kg.decimal' => 'Los precios pueden tener hasta cuatro decimales.',
        ];
    }
}
