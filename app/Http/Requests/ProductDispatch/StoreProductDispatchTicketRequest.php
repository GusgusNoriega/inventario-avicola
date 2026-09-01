<?php

namespace App\Http\Requests\ProductDispatch;

use App\Models\Balanza;
use App\Models\ProductoDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class StoreProductDispatchTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'draft_id' => ['required', 'uuid'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'weighings' => ['required', 'array', 'min:1', 'max:100'],
            'weighings.*' => ['array'],
            'weighings.*.product_id' => ['required', 'integer', 'min:1'],
            'weighings.*.variation_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'weighings.*.price_mode' => ['required', Rule::in([
                ProductoDespacho::PRICE_MODE_KG,
                ProductoDespacho::PRICE_MODE_UNIT,
            ])],
            'weighings.*.unit_price' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999.99',
            ],
            'weighings.*.waste_total_grams' => [
                'required',
                'integer',
                'min:0',
                'max:1000000000',
            ],
            'weighings.*.waste_grams_per_unit' => [
                'sometimes',
                'integer',
                'min:0',
                'max:1000000',
            ],
            'weighings.*.tare_grams' => [
                'sometimes',
                'integer',
                'min:0',
                'max:1000000000',
            ],
            'weighings.*.weight_source' => ['required', Rule::in([
                'MANUAL',
                Balanza::CODE_PRODUCT_DISPATCH,
            ])],
            'weighings.*.read_weight_kg' => [
                'required',
                'numeric',
                'decimal:0,3',
                'gt:0',
                'max:999999999.999',
            ],
            'weighings.*.weighed_at' => ['required', 'date'],
            'weighings.*.scale_reading' => ['nullable', 'array'],
            'weighings.*.scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'weighings.*.scale_reading.connection_mode' => [
                'nullable',
                Rule::in(['ble', 'serial']),
            ],
            'weighings.*.scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'weighings.*.scale_reading.captured_at' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'draft_id.required' => 'No se recibió el identificador de esta lista.',
            'draft_id.uuid' => 'El identificador de la lista no es válido.',
            'weighings.required' => 'Agrega al menos una pesada antes de guardar.',
            'weighings.min' => 'Agrega al menos una pesada antes de guardar.',
            'weighings.max' => 'Un ticket puede contener hasta 100 pesadas.',
            'weighings.*.array' => 'Una de las pesadas no tiene un formato válido.',
            'weighings.*.product_id.required' => 'Selecciona el producto de cada pesada.',
            'weighings.*.quantity.required' => 'Indica la cantidad de cada pesada.',
            'weighings.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'weighings.*.price_mode.required' => 'No se recibió el modo de precio de una pesada.',
            'weighings.*.price_mode.in' => 'El modo de precio de una pesada no es válido.',
            'weighings.*.unit_price.required' => 'Indica el precio de cada pesada.',
            'weighings.*.unit_price.gt' => 'El precio debe ser mayor que cero.',
            'weighings.*.unit_price.decimal' => 'El precio puede tener hasta dos decimales.',
            'weighings.*.unit_price.max' => 'El precio supera el máximo permitido.',
            'weighings.*.waste_total_grams.required' => 'Indica la merma total de cada pesada.',
            'weighings.*.waste_total_grams.min' => 'La merma no puede ser negativa.',
            'weighings.*.waste_total_grams.max' => 'La merma total no puede superar 1.000.000.000 de gramos.',
            'weighings.*.waste_grams_per_unit.integer' => 'La merma aplicada por unidad debe expresarse en gramos enteros.',
            'weighings.*.waste_grams_per_unit.min' => 'La merma aplicada por unidad no puede ser negativa.',
            'weighings.*.waste_grams_per_unit.max' => 'La merma aplicada por unidad no puede superar 1.000.000 de gramos.',
            'weighings.*.tare_grams.integer' => 'La tara debe expresarse en gramos enteros.',
            'weighings.*.tare_grams.min' => 'La tara no puede ser negativa.',
            'weighings.*.tare_grams.max' => 'La tara no puede superar 1.000.000.000 de gramos.',
            'weighings.*.weight_source.required' => 'Indica si el peso viene de la balanza o fue manual.',
            'weighings.*.weight_source.in' => 'La procedencia del peso no corresponde a este despacho.',
            'weighings.*.read_weight_kg.required' => 'Captura o ingresa el peso de cada pesada.',
            'weighings.*.read_weight_kg.gt' => 'El peso debe ser mayor que cero.',
            'weighings.*.read_weight_kg.decimal' => 'El peso puede tener hasta tres decimales.',
            'weighings.*.weighed_at.required' => 'No se recibió la fecha de una pesada.',
            'weighings.*.weighed_at.date' => 'La fecha de una pesada no es válida.',
            'weighings.*.scale_reading.array' => 'Los datos enviados por la balanza no son válidos.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('weighings', []) as $index => $weighing) {
                if (! is_array($weighing)) {
                    continue;
                }

                $field = "weighings.{$index}.scale_reading";
                $source = (string) ($weighing['weight_source'] ?? '');
                $metadata = $weighing['scale_reading'] ?? null;

                if ($source === Balanza::CODE_PRODUCT_DISPATCH) {
                    if (! is_array($metadata)) {
                        $validator->errors()->add(
                            $field,
                            'La captura física debe incluir la evidencia de la balanza.',
                        );

                        continue;
                    }

                    if (blank($metadata['captured_at'] ?? null)) {
                        $validator->errors()->add(
                            "{$field}.captured_at",
                            'La captura física debe incluir su fecha y hora.',
                        );

                        continue;
                    }

                    try {
                        $capturedAt = CarbonImmutable::parse((string) $metadata['captured_at']);
                        $weighedAt = CarbonImmutable::parse((string) ($weighing['weighed_at'] ?? ''));

                        if ($capturedAt->greaterThan(now()->addMinutes(5))) {
                            $validator->errors()->add(
                                "{$field}.captured_at",
                                'La fecha de captura de la balanza no puede estar en el futuro.',
                            );
                        }

                        if ($capturedAt->diffInSeconds($weighedAt) > 300) {
                            $validator->errors()->add(
                                "{$field}.captured_at",
                                'La captura de la balanza debe corresponder al momento de la pesada.',
                            );
                        }
                    } catch (Throwable) {
                        // Las reglas `date` agregan el mensaje de formato correspondiente.
                    }
                } elseif ($source === 'MANUAL' && filled($metadata)) {
                    $validator->errors()->add(
                        $field,
                        'Una pesada manual no debe incluir evidencia de una balanza.',
                    );
                }
            }
        }];
    }
}
