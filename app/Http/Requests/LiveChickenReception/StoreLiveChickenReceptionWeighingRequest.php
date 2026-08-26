<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
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
            'layout_version' => ['required', 'integer', Rule::in([3])],
            'idempotency_key' => ['required', 'uuid'],
            'lane' => ['required', 'integer', 'between:1,6'],
            'owner_type' => ['prohibited'],
            'external_owner_id' => ['prohibited'],
            'sex' => [
                Rule::prohibitedIf(fn (): bool => in_array((int) $this->input('lane'), [1, 2, 3, 4], true)),
                Rule::requiredIf(fn (): bool => in_array((int) $this->input('lane'), [5, 6], true)),
                'nullable',
                Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE]),
            ],
            'dispatch_client_id' => [
                Rule::prohibitedIf(fn (): bool => in_array((int) $this->input('lane'), [1, 2, 3, 4], true)),
                Rule::requiredIf(fn (): bool => in_array((int) $this->input('lane'), [5, 6], true)),
                'nullable',
                'integer',
                'min:1',
            ],
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
            'lane.between' => 'Selecciona una de las seis columnas de recepción.',
            'owner_type.prohibited' => 'El propietario se define automáticamente según la columna.',
            'external_owner_id.prohibited' => 'La empresa externa se toma de la configuración de esta vista.',
            'sex.prohibited' => 'El sexo se define automáticamente en las columnas 1 a 4.',
            'sex.required' => 'Selecciona si los pollos del despacho directo son machos o hembras.',
            'sex.in' => 'Selecciona si los pollos son machos o hembras.',
            'dispatch_client_id.prohibited' => 'El cliente de despacho solo aplica a las columnas 5 y 6.',
            'dispatch_client_id.required' => 'Selecciona el cliente que recibirá este despacho directo.',
            'dispatch_client_id.integer' => 'Selecciona un cliente de despacho válido.',
            'dispatch_client_id.min' => 'Selecciona un cliente de despacho válido.',
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
