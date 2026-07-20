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
            ],
            'global_prices.'.TipoPollo::CHICKEN_LIVE => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'global_prices.'.TipoPollo::CHICKEN_DRESSED => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'global_prices.'.TipoPollo::CHICKEN_PROCESSED => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'global_prices.*.gt' => 'Todos los precios de la jornada deben ser mayores que cero.',
        ];
    }
}
