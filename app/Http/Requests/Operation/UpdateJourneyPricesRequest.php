<?php

namespace App\Http\Requests\Operation;

use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJourneyPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'global_prices' => [
                'required',
                'array:'.implode(',', [
                    TipoPollo::CHICKEN_LIVE,
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
                'min:1',
            ],
            'global_prices.'.TipoPollo::CHICKEN_LIVE => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'global_prices.'.TipoPollo::CHICKEN_DRESSED => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'global_prices.'.TipoPollo::CHICKEN_PROCESSED => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'expected_prices' => [
                'required',
                'array:'.implode(',', [
                    TipoPollo::CHICKEN_LIVE,
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
                'min:1',
            ],
            'expected_prices.'.TipoPollo::CHICKEN_LIVE => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'expected_prices.'.TipoPollo::CHICKEN_DRESSED => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'expected_prices.'.TipoPollo::CHICKEN_PROCESSED => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'global_prices.min' => 'Debes enviar al menos un precio de la jornada.',
            'global_prices.*.decimal' => 'Los precios de la jornada deben usar como máximo dos decimales.',
            'global_prices.*.gt' => 'Todos los precios de la jornada deben ser mayores que cero.',
            'expected_prices.required' => 'Los precios de la pantalla están desactualizados. Recarga la información e inténtalo nuevamente.',
            'expected_prices.min' => 'Debes confirmar el precio vigente de cada producto que deseas actualizar.',
            'expected_prices.*.decimal' => 'Los precios esperados de la jornada deben usar como máximo dos decimales.',
            'expected_prices.*.gt' => 'Los precios esperados de la jornada deben ser mayores que cero.',
        ];
    }
}
