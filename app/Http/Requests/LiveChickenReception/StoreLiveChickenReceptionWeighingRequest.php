<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
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
            'idempotency_key' => ['required', 'uuid'],
            'lane' => ['required', 'integer', 'between:1,4'],
            'owner_type' => [
                'required',
                Rule::in([
                    PesadaRecepcionPolloVivo::OWNER_OWN,
                    PesadaRecepcionPolloVivo::OWNER_EXTERNAL,
                ]),
            ],
            'external_owner_id' => [
                Rule::requiredIf(fn (): bool => $this->input('owner_type') === PesadaRecepcionPolloVivo::OWNER_EXTERNAL),
                'nullable',
                'integer',
                'min:1',
                Rule::prohibitedIf(fn (): bool => $this->input('owner_type') === PesadaRecepcionPolloVivo::OWNER_OWN),
            ],
            'sex' => ['required', Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE])],
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
            'idempotency_key.required' => 'No se recibió el identificador único de la pesada.',
            'lane.between' => 'Selecciona una de las cuatro columnas de recepción.',
            'external_owner_id.required' => 'Selecciona la empresa externa propietaria de los pollos.',
            'sex.in' => 'Selecciona si los pollos son machos o hembras.',
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

        $this->merge([
            'owner_type' => mb_strtoupper(trim((string) $this->input('owner_type')), 'UTF-8'),
            'sex' => mb_strtoupper(trim((string) $this->input('sex')), 'UTF-8'),
            'weight_source' => mb_strtoupper(trim((string) $this->input('weight_source')), 'UTF-8'),
            'scale_reading' => $scaleReading,
        ]);
    }
}
