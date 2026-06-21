<?php

namespace App\Http\Requests\Directory;

use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustDirectoryPricesRequest extends FormRequest
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
            'tipo_pollo' => [
                'required',
                Rule::in([
                    TipoPollo::CHICKEN_LIVE,
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
            ],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'direccion' => ['required', Rule::in(['AUMENTAR', 'DISMINUIR'])],
        ];
    }
}
