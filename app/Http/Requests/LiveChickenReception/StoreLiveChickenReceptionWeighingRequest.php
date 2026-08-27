<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLiveChickenReceptionWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'layout_version' => ['required', 'integer', Rule::in([3, 4])],
            'idempotency_key' => ['required', 'uuid'],
            'lane' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'owner_type' => ['prohibited'],
            'external_owner_id' => ['prohibited'],
            'sex' => ['prohibited'],
            'dispatch_client_id' => ['prohibited'],
            'cage_type_id' => ['required', 'integer', 'min:1'],
            'birds_per_cage' => ['required', 'integer', 'between:1,1000'],
            'cage_count' => ['required', 'integer', 'between:1,10000'],
            'weight_source' => [
                'required',
                Rule::in(['MANUAL', Balanza::CODE_LIVE_CHICKEN_RECEPTION]),
            ],
            'read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighed_at' => ['required', 'date'],
            'scale_reading' => ['sometimes', 'nullable', 'array:raw_frame,connection_mode,device_name,captured_at'],
            'scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'scale_reading.connection_mode' => ['nullable', Rule::in(['SERIAL', 'BLE', 'BLUETOOTH'])],
            'scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'scale_reading.captured_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'layout_version.required' => 'Actualiza la vista de recepción antes de registrar otra pesada.',
            'layout_version.in' => 'La distribución de columnas cambió. Recarga la vista antes de continuar.',
            'idempotency_key.required' => 'No se recibió el identificador único de la pesada.',
            'lane.in' => 'Las pesadas de entrada solo se registran en las columnas 1 a 4.',
            'owner_type.prohibited' => 'El propietario se define automáticamente según la columna.',
            'external_owner_id.prohibited' => 'La empresa externa se toma de la configuración de esta vista.',
            'sex.prohibited' => 'El sexo se define automáticamente en las columnas 1 a 4.',
            'dispatch_client_id.prohibited' => 'Los despachos se registran como tickets desde las columnas 5 y 6.',
            'cage_type_id.required' => 'Selecciona el tipo de java.',
            'birds_per_cage.between' => 'La cantidad de aves por java debe estar entre 1 y 1000.',
            'cage_count.between' => 'La cantidad de javas debe estar entre 1 y 10000.',
            'read_weight_kg.gt' => 'La balanza debe tener un peso mayor que cero.',
            'weighed_at.required' => 'No se recibió la fecha y hora de la pesada.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $scaleReading = is_array($this->input('scale_reading'))
            ? $this->input('scale_reading')
            : null;

        if ($scaleReading !== null && filled($scaleReading['connection_mode'] ?? null)) {
            $scaleReading['connection_mode'] = mb_strtoupper(
                trim((string) $scaleReading['connection_mode']),
                'UTF-8',
            );
        }

        $normalized = [
            'weight_source' => mb_strtoupper(trim((string) $this->input('weight_source')), 'UTF-8'),
            'scale_reading' => $scaleReading,
        ];

        if (filled($this->input('sex'))) {
            $normalized['sex'] = mb_strtoupper(trim((string) $this->input('sex')), 'UTF-8');
        }

        $this->merge($normalized);
    }
}
