<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMinorista;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRetailDispatchRequest extends FormRequest
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
            'client_id' => ['required', 'integer', 'min:1'],
            'operation_type' => ['required', Rule::in([
                TicketDespacho::OPERATION_DISPATCH,
                TicketDespacho::OPERATION_RETURN,
            ])],
            'price_overrides' => [
                'sometimes',
                'array:'.implode(',', [
                    TipoPollo::CHICKEN_LIVE,
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
            ],
            'price_overrides.*' => ['numeric', 'gt:0', 'max:99999999.9999'],
            'weighings' => ['required', 'array', 'min:1', 'max:100'],
            'weighings.*' => [
                'required',
                'array:local_id,chicken_type_code,adjustment_code,tray_type_code,weight_source,birds_per_tray,tray_count,read_weight_kg,weighed_at',
            ],
            'weighings.*.local_id' => ['required', 'integer', 'min:1', 'distinct'],
            'weighings.*.chicken_type_code' => [
                'required',
                Rule::in([
                    TipoPollo::CHICKEN_LIVE,
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
            ],
            'weighings.*.adjustment_code' => ['nullable', Rule::in(AjustePesoMinorista::codes())],
            'weighings.*.tray_type_code' => ['required', 'string', 'max:40'],
            'weighings.*.weight_source' => [
                'required',
                Rule::in(['MANUAL', 'BALANZA_MINORISTA']),
            ],
            'weighings.*.birds_per_tray' => ['required', 'integer', 'min:1', 'max:100'],
            'weighings.*.tray_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.weighed_at' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $weighings = collect($this->input('weighings', []))
            ->map(function ($weighing): mixed {
                if (! is_array($weighing)) {
                    return $weighing;
                }

                return [
                    ...$weighing,
                    'chicken_type_code' => mb_strtoupper(
                        trim((string) ($weighing['chicken_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'adjustment_code' => filled($weighing['adjustment_code'] ?? null)
                        ? mb_strtoupper(
                            trim((string) $weighing['adjustment_code']),
                            'UTF-8'
                        )
                        : null,
                    'tray_type_code' => mb_strtoupper(
                        trim((string) ($weighing['tray_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'weight_source' => mb_strtoupper(
                        trim((string) ($weighing['weight_source'] ?? '')),
                        'UTF-8'
                    ),
                ];
            })
            ->all();
        $priceOverrides = $this->input('price_overrides');

        if (is_array($priceOverrides)) {
            $priceOverrides = collect($priceOverrides)
                ->mapWithKeys(fn (mixed $price, mixed $code): array => [
                    mb_strtoupper(trim((string) $code), 'UTF-8') => $price,
                ])
                ->all();
        }

        $normalized = [
            'operation_type' => mb_strtoupper(
                trim((string) $this->input('operation_type', '')),
                'UTF-8'
            ),
            'weighings' => $weighings,
        ];

        if ($this->exists('price_overrides')) {
            $normalized['price_overrides'] = $priceOverrides;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Asigna un cliente antes de grabar el despacho.',
            'weighings.min' => 'Agrega al menos una pesada a la lista.',
            'weighings.*.local_id.distinct' => 'Cada pesada debe tener un identificador diferente.',
            'weighings.*.tray_count.min' => 'La cantidad de bandejas debe ser al menos 1.',
            'weighings.*.read_weight_kg.required' => 'Captura o ingresa el peso leido por la balanza.',
        ];
    }
}
