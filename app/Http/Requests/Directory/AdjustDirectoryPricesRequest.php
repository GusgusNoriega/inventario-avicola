<?php

namespace App\Http\Requests\Directory;

use App\Models\TerceroRole;
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
        $priceCodes = [
            TipoPollo::CHICKEN_LIVE,
            TipoPollo::CHICKEN_DRESSED,
            TipoPollo::CHICKEN_PROCESSED,
        ];

        if ($this->route('directory_role') === TerceroRole::CLIENT) {
            $priceCodes = [...$priceCodes, ...TipoPollo::wholesaleTwoClientPriceCodes()];
        }

        return [
            'tipo_pollo' => [
                'required',
                Rule::in($priceCodes),
            ],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'direccion' => ['required', Rule::in(['AUMENTAR', 'DISMINUIR'])],
        ];
    }
}
