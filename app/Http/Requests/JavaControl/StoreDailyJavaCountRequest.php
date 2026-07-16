<?php

namespace App\Http\Requests\JavaControl;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyJavaCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'java_quantity' => ['required_without:local_java_quantity', 'integer', 'min:0', 'max:1000000'],
            'tray_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'local_java_quantity' => ['required_without:java_quantity', 'integer', 'min:0', 'max:1000000'],
            'local_tray_quantity' => ['required_with:local_java_quantity', 'integer', 'min:0', 'max:1000000'],
            'truck_counts' => ['sometimes', 'array', 'max:500'],
            'truck_counts.*.vehicle_id' => ['required', 'integer', 'distinct'],
            'truck_counts.*.java_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'truck_counts.*.tray_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'java_quantity.required' => 'Indica cuantas javas se contaron dentro del local.',
            'java_quantity.integer' => 'El conteo diario de javas debe ser un numero entero.',
            'java_quantity.min' => 'El conteo diario de javas no puede ser negativo.',
            'tray_quantity.integer' => 'El conteo diario de bandejas debe ser un numero entero.',
            'tray_quantity.min' => 'El conteo diario de bandejas no puede ser negativo.',
            'local_java_quantity.required_without' => 'Indica cuantas javas quedaron en el local fuera de los camiones.',
            'local_java_quantity.integer' => 'Las javas del local deben ser un numero entero.',
            'local_java_quantity.min' => 'Las javas del local no pueden ser negativas.',
            'local_tray_quantity.required_with' => 'Indica cuantas bandejas quedaron en el local fuera de los camiones.',
            'local_tray_quantity.integer' => 'Las bandejas del local deben ser un numero entero.',
            'local_tray_quantity.min' => 'Las bandejas del local no pueden ser negativas.',
            'truck_counts.array' => 'El detalle de camiones no tiene un formato valido.',
            'truck_counts.*.vehicle_id.required' => 'Cada fila debe identificar el camion contado.',
            'truck_counts.*.vehicle_id.distinct' => 'Un camion no puede aparecer dos veces en el mismo conteo.',
            'truck_counts.*.java_quantity.required' => 'Indica las javas de cada camion, incluso cuando sean cero.',
            'truck_counts.*.tray_quantity.required' => 'Indica las bandejas de cada camion, incluso cuando sean cero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('java_quantity') && $this->exists('quantity')) {
            $this->merge(['java_quantity' => $this->input('quantity')]);
        }

        if (! $this->exists('truck_counts') && $this->exists('trucks')) {
            $this->merge(['truck_counts' => $this->input('trucks')]);
        }
    }
}
