<?php

namespace App\Http\Requests\LiveChickenReception;

use App\Models\Balanza;
use App\Models\Pesada;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreLiveChickenReceptionDispatchTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'layout_version' => ['required', 'integer', Rule::in([4])],
            'draft_id' => ['required', 'uuid'],
            'lane' => ['required', 'integer', Rule::in([5, 6])],
            'owner_type' => ['prohibited'],
            'external_owner_id' => ['prohibited'],
            'dispatch_client_id' => [
                'required',
                'integer',
                Rule::exists('terceros', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $companyId)),
            ],
            'delivery_vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehiculos', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $companyId)),
            ],
            'delivery_driver_id' => [
                'required',
                'integer',
                Rule::exists('conductores', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $companyId)),
            ],
            'weighings' => ['required', 'array', 'min:1', 'max:500'],
            'weighings.*' => [
                'required',
                'array:idempotency_key,sex,cage_type_id,birds_per_cage,cage_count,weight_source,read_weight_kg,weighed_at,scale_reading',
            ],
            'weighings.*.idempotency_key' => ['required', 'uuid', 'distinct'],
            'weighings.*.sex' => [
                'required',
                Rule::in([Pesada::SEX_MALE, Pesada::SEX_FEMALE]),
            ],
            'weighings.*.cage_type_id' => ['required', 'integer', 'min:1'],
            'weighings.*.birds_per_cage' => ['required', 'integer', 'between:1,1000'],
            'weighings.*.cage_count' => ['required', 'integer', 'between:1,10000'],
            'weighings.*.weight_source' => [
                'required',
                Rule::in(['MANUAL', Balanza::CODE_LIVE_CHICKEN_RECEPTION]),
            ],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.weighed_at' => ['required', 'date'],
            'weighings.*.scale_reading' => [
                'sometimes',
                'nullable',
                'array:raw_frame,connection_mode,device_name,captured_at',
            ],
            'weighings.*.scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'weighings.*.scale_reading.connection_mode' => [
                'nullable',
                Rule::in(['SERIAL', 'BLE', 'BLUETOOTH']),
            ],
            'weighings.*.scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'weighings.*.scale_reading.captured_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'layout_version.in' => 'La distribución de columnas cambió. Recarga la vista antes de registrar el ticket.',
            'lane.in' => 'El ticket solo se puede registrar desde las columnas 5 o 6.',
            'dispatch_client_id.exists' => 'El cliente seleccionado no está disponible.',
            'delivery_vehicle_id.exists' => 'El camión seleccionado no pertenece a la flota activa de la empresa.',
            'delivery_driver_id.exists' => 'El chofer seleccionado no pertenece a la empresa o está inactivo.',
            'weighings.min' => 'El ticket debe contener al menos una pesada.',
            'weighings.*.idempotency_key.distinct' => 'Cada pesada debe tener un identificador local diferente.',
            'weighings.*.cage_count.between' => 'La cantidad de javas debe estar entre 1 y 10000.',
            'weighings.*.read_weight_kg.gt' => 'La balanza debe tener un peso mayor que cero.',
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
                    'weight_source' => mb_strtoupper(
                        trim((string) ($weighing['weight_source'] ?? '')),
                        'UTF-8',
                    ),
                ];

                if (is_array($weighing['scale_reading'] ?? null)) {
                    $normalized['scale_reading'] = [
                        ...$weighing['scale_reading'],
                        'connection_mode' => filled($weighing['scale_reading']['connection_mode'] ?? null)
                            ? mb_strtoupper(
                                trim((string) $weighing['scale_reading']['connection_mode']),
                                'UTF-8',
                            )
                            : null,
                        'device_name' => filled($weighing['scale_reading']['device_name'] ?? null)
                            ? trim((string) $weighing['scale_reading']['device_name'])
                            : null,
                    ];
                }

                return $normalized;
            })
            ->all();

        $this->merge(['weighings' => $weighings]);
    }

    private function companyId(): int
    {
        return (int) ($this->user()?->empresa_id
            ?? DB::table('empresas')->where('estado', 'ACTIVO')->orderBy('id')->value('id'));
    }
}
