<?php

namespace App\Http\Requests\ProductDispatch;

use App\Models\ProductoDespacho;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductDispatchTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'date'],
            'correction_reason' => ['nullable', 'string', 'min:3', 'max:250'],
            'ticket_title' => ['required', 'string', 'max:180'],
            'list_number' => ['required', 'integer', 'between:1,8'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'registered_at' => [
                'required',
                'string',
                'date_format:Y-m-d\TH:i:s',
                'after_or_equal:1970-01-02T00:00:00',
            ],
            'weighings' => ['required', 'array', 'min:1', 'max:100'],
            'weighings.*' => [
                'required',
                'array:id,product_id,variation_id,quantity,price_mode,unit_price,waste_grams_per_unit,waste_total_grams,tare_grams,read_weight_kg',
            ],
            'weighings.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'weighings.*.product_id' => ['required', 'integer', 'min:1'],
            'weighings.*.variation_id' => ['nullable', 'integer', 'min:1'],
            'weighings.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
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
            'weighings.*.waste_grams_per_unit' => [
                'required',
                'integer',
                'min:0',
                'max:1000000',
            ],
            'weighings.*.waste_total_grams' => [
                'required',
                'integer',
                'min:0',
                'max:1000000000',
            ],
            'weighings.*.tare_grams' => [
                'required',
                'integer',
                'min:0',
                'max:1000000000',
            ],
            'weighings.*.read_weight_kg' => [
                'required',
                'numeric',
                'decimal:0,3',
                'gt:0',
                'max:999999999.999',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $registeredAt = $this->input('registered_at');

        if (is_string($registeredAt)) {
            $registeredAt = trim($registeredAt);

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $registeredAt) === 1) {
                $registeredAt .= ':00';
            }
        }

        $correctionReason = $this->input('correction_reason');
        $ticketTitle = $this->input('ticket_title');

        if (is_string($correctionReason)) {
            $correctionReason = trim($correctionReason);
            $correctionReason = $correctionReason !== '' ? $correctionReason : null;
        }

        if (is_string($ticketTitle)) {
            $ticketTitle = trim($ticketTitle);
        }

        $this->merge([
            'correction_reason' => $correctionReason,
            'ticket_title' => $ticketTitle,
            'registered_at' => $registeredAt,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'version.required' => 'Actualiza el detalle del ticket antes de guardar.',
            'correction_reason.min' => 'El motivo de la corrección debe tener al menos 3 caracteres.',
            'ticket_title.required' => 'Indica el título que debe conservar el ticket.',
            'list_number.between' => 'El número de lista debe estar entre 1 y 8.',
            'registered_at.required' => 'Indica la fecha y hora del ticket.',
            'registered_at.date_format' => 'La fecha y hora del ticket no tiene un formato válido.',
            'registered_at.after_or_equal' => 'La fecha y hora del ticket debe ser posterior al 1 de enero de 1970.',
            'weighings.required' => 'El ticket debe conservar al menos una pesada.',
            'weighings.min' => 'El ticket debe conservar al menos una pesada.',
            'weighings.max' => 'Un ticket puede contener hasta 100 pesadas.',
            'weighings.*.id.distinct' => 'Una pesada aparece más de una vez.',
            'weighings.*.product_id.required' => 'Selecciona el producto de cada pesada.',
            'weighings.*.quantity.required' => 'Indica la cantidad de cada pesada.',
            'weighings.*.quantity.min' => 'La cantidad no puede ser negativa.',
            'weighings.*.price_mode.in' => 'El modo de precio de una pesada no es válido.',
            'weighings.*.unit_price.gt' => 'El precio debe ser mayor que cero.',
            'weighings.*.unit_price.decimal' => 'El precio puede tener hasta dos decimales.',
            'weighings.*.waste_grams_per_unit.required' => 'Indica la merma por unidad de cada pesada.',
            'weighings.*.waste_total_grams.required' => 'Indica la merma total de cada pesada.',
            'weighings.*.tare_grams.required' => 'Indica la tara de cada pesada.',
            'weighings.*.read_weight_kg.required' => 'Indica el peso leído de cada pesada.',
            'weighings.*.read_weight_kg.gt' => 'El peso leído debe ser mayor que cero.',
            'weighings.*.read_weight_kg.decimal' => 'El peso puede tener hasta tres decimales.',
        ];
    }
}
