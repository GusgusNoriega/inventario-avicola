<?php

namespace App\Http\Requests\Directory;

use App\Models\TerceroRole;
use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;

class StoreTerceroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $priceCodes = [
            TipoPollo::CHICKEN_LIVE,
            TipoPollo::CHICKEN_DRESSED,
            TipoPollo::CHICKEN_PROCESSED,
        ];
        $pricesAreRequired = $this->route('directory_role') === TerceroRole::PROVIDER;

        return [
            'nombre_razon_social' => ['required', 'string', 'max:180'],
            'numero_documento' => ['required', 'string', 'regex:/^(?:\d{8}|\d{11})$/'],
            'direccion' => ['required', 'string', 'max:250'],
            'precios' => $pricesAreRequired
                ? ['required', 'array:'.implode(',', $priceCodes)]
                : ['sometimes', 'array:'.implode(',', $priceCodes)],
            'precios.'.TipoPollo::CHICKEN_LIVE => $this->priceRules($pricesAreRequired),
            'precios.'.TipoPollo::CHICKEN_DRESSED => $this->priceRules($pricesAreRequired),
            'precios.'.TipoPollo::CHICKEN_PROCESSED => $this->priceRules($pricesAreRequired),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_documento.regex' => 'El documento debe tener 8 dígitos para DNI u 11 dígitos para RUC.',
            'precios.*.gt' => 'Todos los precios deben ser mayores que cero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_razon_social' => trim((string) $this->input('nombre_razon_social')),
            'numero_documento' => preg_replace('/\D+/', '', (string) $this->input('numero_documento')),
            'direccion' => trim((string) $this->input('direccion')),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function priceRules(bool $required): array
    {
        return $required
            ? ['required', 'numeric', 'gt:0', 'max:99999999.9999']
            : ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99999999.9999'];
    }
}
