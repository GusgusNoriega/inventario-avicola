<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
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
            'owner_type' => [
                'sometimes',
                Rule::in([
                    PesadaRecepcionPolloVivo::OWNER_OWN,
                    PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
                ]),
            ],
            'external_owner_id' => ['prohibited'],
            'lane' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'destination_id' => ['prohibited'],
            'dispatch_client_id' => ['prohibited'],
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

        if ($this->has('owner_type')) {
            $normalized['owner_type'] = mb_strtoupper(
                trim((string) $this->input('owner_type')),
                'UTF-8',
            );
        }

        if ($this->has('weight_source')) {
            $normalized['weight_source'] = mb_strtoupper(
                trim((string) $this->input('weight_source')),
                'UTF-8',
            );
        }

        $this->merge($normalized);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'owner_type.in' => 'Selecciona Mi empresa o la empresa externa configurada en esta vista.',
            'external_owner_id.prohibited' => 'La empresa externa se toma de la configuración de esta vista.',
            'lane.prohibited' => 'La columna se define automáticamente con el propietario y el sexo.',
            'warehouse_id.prohibited' => 'El almacén se toma de la columna configurada para el propietario y el sexo.',
            'destination_id.prohibited' => 'El destino se toma de la columna configurada para el propietario y el sexo.',
            'dispatch_client_id.prohibited' => 'Una pesada de entrada a almacén no admite un cliente de despacho.',
        ];
    }
}
