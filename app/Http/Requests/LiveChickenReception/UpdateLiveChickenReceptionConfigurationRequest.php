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
            'lane_1_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_2_warehouse_id' => ['required', 'integer', 'min:1'],
            'lane_3_client_id' => ['required', 'integer', 'min:1'],
            'lane_4_client_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lane_1_warehouse_id.required' => 'Selecciona el almacén de la columna 1.',
            'lane_2_warehouse_id.required' => 'Selecciona el almacén de la columna 2.',
            'lane_3_client_id.required' => 'Selecciona el cliente de despacho de la columna 3.',
            'lane_4_client_id.required' => 'Selecciona el cliente de despacho de la columna 4.',
        ];
    }
}
