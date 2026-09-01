<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductDispatchConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'waste_presets' => ['required', 'array', 'size:3'],
            'waste_presets.*' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'waste_presets.required' => 'Configura las tres mermas rápidas del despacho.',
            'waste_presets.array' => 'Las mermas rápidas deben enviarse como una lista.',
            'waste_presets.size' => 'Debes configurar exactamente tres mermas rápidas.',
            'waste_presets.*.required' => 'Indica el valor de cada merma rápida.',
            'waste_presets.*.integer' => 'Cada merma rápida debe expresarse en gramos enteros por unidad.',
            'waste_presets.*.min' => 'Las mermas rápidas no pueden ser negativas.',
            'waste_presets.*.max' => 'Cada merma rápida puede ser de hasta 1.000.000 de gramos por unidad.',
        ];
    }
}
