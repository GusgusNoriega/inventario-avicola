<?php

namespace App\Http\Requests\LiveChickenReception;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveChickenReceptionConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'default_external_owner_id' => ['nullable', 'integer', 'min:1'],
            'default_male_birds_per_cage' => ['sometimes', 'integer', 'between:1,1000'],
            'default_female_birds_per_cage' => ['sometimes', 'integer', 'between:1,1000'],
            'default_cage_count' => ['sometimes', 'integer', 'between:1,10000'],
            'default_cage_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lane_1_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_2_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_3_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_4_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_5_client_id' => ['required', 'integer', 'min:1'],
            'lane_6_client_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'default_male_birds_per_cage.between' => 'Las aves por java para macho deben estar entre 1 y 1000.',
            'default_female_birds_per_cage.between' => 'Las aves por java para hembra deben estar entre 1 y 1000.',
            'default_cage_count.between' => 'La cantidad predeterminada de javas debe estar entre 1 y 10000.',
            'lane_1_warehouse_id.required' => 'Selecciona el almacén de la columna 1.',
            'lane_2_warehouse_id.required' => 'Selecciona el almacén de la columna 2.',
            'lane_3_warehouse_id.required' => 'Selecciona el almacén de la columna 3.',
            'lane_4_warehouse_id.required' => 'Selecciona el almacén de la columna 4.',
            'lane_5_client_id.required' => 'Selecciona el cliente de despacho de la columna 5.',
            'lane_6_client_id.required' => 'Selecciona el cliente de despacho de la columna 6.',
        ];
    }
}
