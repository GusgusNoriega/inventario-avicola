<?php

namespace App\Http\Requests\Operation;

use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDispatchTicketRequest extends FormRequest
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
            'draft_id' => ['required', 'uuid'],
            'general_prices' => ['prohibited'],
            'prices' => ['prohibited'],
            'destination' => ['required', 'array:type,id'],
            'destination.type' => ['required', Rule::in(['CLIENTE', 'ALMACEN'])],
            'destination.id' => ['required', 'integer', 'min:1'],
            'weighings' => ['required', 'array', 'min:1', 'max:500'],
            'weighings.*' => [
                'required',
                'array:local_id,chicken_type_code,cage_type_code,origin,weight_source,birds_per_cage,cage_count,read_weight_kg,gross_weight_kg,weighed_at',
            ],
            'weighings.*.local_id' => ['required', 'integer', 'min:1', 'distinct'],
            'weighings.*.chicken_type_code' => [
                'required',
                Rule::in([TipoPollo::CHICKEN_LIVE, TipoPollo::CHICKEN_DRESSED]),
            ],
            'weighings.*.cage_type_code' => ['required', 'string', 'max:40'],
            'weighings.*.origin' => [
                'required',
                'array:type,provider_id,warehouse_id,provider_vehicle_id,vehicle_id,plate',
            ],
            'weighings.*.origin.type' => ['required', Rule::in(['PROVEEDOR', 'ALMACEN'])],
            'weighings.*.origin.provider_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.origin.warehouse_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.origin.provider_vehicle_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.origin.vehicle_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.origin.plate' => [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'regex:/^[A-Z0-9-]+$/',
            ],
            'weighings.*.weight_source' => [
                'required',
                Rule::in(['MANUAL', 'BALANZA_1', 'BALANZA_2']),
            ],
            'weighings.*.birds_per_cage' => ['required', 'integer', 'min:1', 'max:1000'],
            'weighings.*.cage_count' => ['required', 'integer', 'min:1', 'max:10000'],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.gross_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.weighed_at' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weighings.min' => 'El ticket debe contener al menos una pesada.',
            'weighings.*.origin.plate.regex' => 'La placa solo puede contener letras, números y guiones.',
            'weighings.*.local_id.distinct' => 'Cada pesada debe tener un identificador local diferente.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $weighings = collect($this->input('weighings', []))
            ->map(function ($weighing): mixed {
                if (! is_array($weighing)) {
                    return $weighing;
                }

                $origin = is_array($weighing['origin'] ?? null)
                    ? $weighing['origin']
                    : [];

                if (array_key_exists('plate', $origin)) {
                    $origin['plate'] = mb_strtoupper(
                        preg_replace('/\s+/', '', (string) $origin['plate']),
                        'UTF-8'
                    );
                }

                return [
                    ...$weighing,
                    'chicken_type_code' => mb_strtoupper(
                        trim((string) ($weighing['chicken_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'cage_type_code' => mb_strtoupper(
                        trim((string) ($weighing['cage_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'weight_source' => mb_strtoupper(
                        trim((string) ($weighing['weight_source'] ?? '')),
                        'UTF-8'
                    ),
                    'origin' => [
                        ...$origin,
                        'type' => mb_strtoupper(
                            trim((string) ($origin['type'] ?? '')),
                            'UTF-8'
                        ),
                    ],
                ];
            })
            ->all();

        $destination = is_array($this->input('destination'))
            ? $this->input('destination')
            : [];

        $this->merge([
            'destination' => [
                ...$destination,
                'type' => mb_strtoupper(
                    trim((string) ($destination['type'] ?? '')),
                    'UTF-8'
                ),
            ],
            'weighings' => $weighings,
        ]);
    }
}
