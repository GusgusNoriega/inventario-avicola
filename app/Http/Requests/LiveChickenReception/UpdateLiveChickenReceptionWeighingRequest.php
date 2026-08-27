<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLiveChickenReceptionWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'layout_version' => ['required', 'integer', Rule::in([4])],
            'expected_updated_at' => ['required', 'date'],
            'sex' => ['required', Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE])],
            'cage_type_id' => ['required', 'integer', 'min:1'],
            'birds_per_cage' => ['required', 'integer', 'between:1,1000'],
            'cage_count' => ['required', 'integer', 'between:1,10000'],
            'weight_source' => [
                'sometimes',
                Rule::in(['MANUAL', 'BALANZA', Balanza::CODE_LIVE_CHICKEN_RECEPTION]),
            ],
            'read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighed_at' => ['required', 'date'],
            'correction_reason' => ['required', 'string', 'min:3', 'max:250'],
            'scale_reading' => ['sometimes', 'nullable', 'array:raw_frame,connection_mode,device_name,captured_at'],
            'scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'scale_reading.connection_mode' => ['nullable', Rule::in(['SERIAL', 'BLE', 'BLUETOOTH'])],
            'scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'scale_reading.captured_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'sex' => mb_strtoupper(trim((string) $this->input('sex')), 'UTF-8'),
        ];

        if ($this->has('weight_source')) {
            $normalized['weight_source'] = mb_strtoupper(
                trim((string) $this->input('weight_source')),
                'UTF-8',
            );
        }

        $this->merge($normalized);
    }
}
