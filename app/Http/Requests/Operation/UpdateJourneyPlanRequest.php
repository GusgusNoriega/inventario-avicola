<?php

namespace App\Http\Requests\Operation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJourneyPlanRequest extends FormRequest
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
            'provider_vehicle_ids' => ['present', 'array', 'max:500'],
            'provider_vehicle_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'warehouse_ids' => ['present', 'array', 'max:100'],
            'warehouse_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'prices' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_vehicle_ids.*.distinct' => 'Cada camión solo puede seleccionarse una vez.',
        ];
    }
}
