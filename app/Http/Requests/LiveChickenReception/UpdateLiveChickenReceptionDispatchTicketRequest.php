<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLiveChickenReceptionDispatchTicketRequest extends FormRequest
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
            'expected_revision' => ['required', 'integer', 'min:0'],
            'correction_reason' => ['required', 'string', 'min:3', 'max:250'],
            'owner_type' => ['prohibited'],
            'external_owner_id' => ['prohibited'],
            'lane' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'destination_id' => ['prohibited'],
            'dispatch_client_id' => ['prohibited'],
            'weighings' => ['required', 'array', 'min:1', 'max:500'],
            'weighings.*' => [
                'required',
                'array:id,sex,cage_type_id,birds_per_cage,cage_count,weight_source,read_weight_kg,weighed_at,scale_reading,expected_updated_at',
            ],
            'weighings.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'weighings.*.expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'weighings.*.sex' => ['required', Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE])],
            'weighings.*.cage_type_id' => ['required', 'integer', 'min:1'],
            'weighings.*.birds_per_cage' => ['required', 'integer', 'between:1,1000'],
            'weighings.*.cage_count' => ['required', 'integer', 'between:1,10000'],
            'weighings.*.weight_source' => [
                'sometimes',
                Rule::in(['MANUAL', 'BALANZA', Balanza::CODE_LIVE_CHICKEN_RECEPTION]),
            ],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.weighed_at' => ['required', 'date'],
            'weighings.*.scale_reading' => ['sometimes', 'nullable', 'array:raw_frame,connection_mode,device_name,captured_at'],
            'weighings.*.scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'weighings.*.scale_reading.connection_mode' => ['nullable', Rule::in(['SERIAL', 'BLE', 'BLUETOOTH'])],
            'weighings.*.scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'weighings.*.scale_reading.captured_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'owner_type.prohibited' => 'Los tickets de recepción siempre pertenecen a Mi empresa.',
            'external_owner_id.prohibited' => 'Los tickets de recepción no admiten una empresa propietaria externa.',
            'lane.prohibited' => 'La columna original del ticket se conserva para mantener su trazabilidad.',
            'warehouse_id.prohibited' => 'El destino del ticket registrado se conserva para mantener su trazabilidad.',
            'destination_id.prohibited' => 'El destino del ticket registrado se conserva para mantener su trazabilidad.',
            'dispatch_client_id.prohibited' => 'El cliente del ticket registrado se conserva para mantener su trazabilidad.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $weighings = collect($this->input('weighings', []))
            ->map(function (mixed $weighing): mixed {
                if (! is_array($weighing)) {
                    return $weighing;
                }

                $normalized = [
                    ...$weighing,
                    'sex' => mb_strtoupper(trim((string) ($weighing['sex'] ?? '')), 'UTF-8'),
                ];

                if (array_key_exists('weight_source', $weighing)) {
                    $normalized['weight_source'] = mb_strtoupper(
                        trim((string) $weighing['weight_source']),
                        'UTF-8',
                    );
                }

                return $normalized;
            })
            ->all();

        $this->merge(['weighings' => $weighings]);
    }
}
