<?php

namespace App\Http\Requests\ProductDispatch;

use App\Models\ProductoDespacho;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductDispatchConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) $this->user()?->empresa_id;

        return [
            'waste_presets' => ['sometimes', 'required', 'array', 'size:3'],
            'waste_presets.*' => ['required', 'integer', 'min:0', 'max:1000000'],
            'quick_product_ids' => ['sometimes', 'array', 'size:4'],
            'quick_product_ids.*' => [
                'bail',
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('productos_despacho', 'id')->where(
                    fn ($query) => $query
                        ->where('empresa_id', $companyId)
                        ->where('estado', ProductoDespacho::STATUS_ACTIVE),
                ),
            ],
            'customer_display_title' => ['sometimes', 'required', 'string', 'max:120'],
            'product_ticket_title' => ['sometimes', 'required', 'string', 'max:180'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('customer_display_title')
            && is_string($this->input('customer_display_title'))) {
            $this->merge([
                'customer_display_title' => trim($this->input('customer_display_title')),
            ]);
        }

        if ($this->exists('product_ticket_title')
            && is_string($this->input('product_ticket_title'))) {
            $this->merge([
                'product_ticket_title' => trim($this->input('product_ticket_title')),
            ]);
        }
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $input = $this->all();

                if (! array_key_exists('waste_presets', $input)
                    && ! array_key_exists('quick_product_ids', $input)
                    && ! array_key_exists('customer_display_title', $input)
                    && ! array_key_exists('product_ticket_title', $input)) {
                    $validator->errors()->add(
                        'configuration',
                        'Indica la configuración de mermas, productos rápidos, pantalla cliente o ticket que deseas guardar.',
                    );
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'waste_presets.required' => 'Configura las tres mermas rápidas del despacho.',
            'waste_presets.array' => 'Las mermas rápidas deben enviarse como una lista.',
            'waste_presets.size' => 'Debes configurar exactamente tres mermas rápidas.',
            'waste_presets.*.required' => 'Indica el valor de cada merma rápida.',
            'waste_presets.*.integer' => 'Cada merma rápida debe expresarse en gramos enteros por unidad.',
            'waste_presets.*.min' => 'Las mermas rápidas no pueden ser negativas.',
            'waste_presets.*.max' => 'Cada merma rápida puede ser de hasta 1.000.000 de gramos por unidad.',
            'quick_product_ids.array' => 'Los productos rápidos deben enviarse como una lista.',
            'quick_product_ids.size' => 'Debes seleccionar exactamente cuatro productos rápidos.',
            'quick_product_ids.*.required' => 'Cada producto rápido debe tener un identificador.',
            'quick_product_ids.*.integer' => 'Cada producto rápido debe tener un identificador válido.',
            'quick_product_ids.*.distinct' => 'No puedes repetir un producto en la selección rápida.',
            'quick_product_ids.*.exists' => 'El producto rápido debe estar activo y pertenecer a tu empresa.',
            'customer_display_title.required' => 'Indica el título de la pantalla cliente.',
            'customer_display_title.string' => 'El título de la pantalla cliente debe ser texto.',
            'customer_display_title.max' => 'El título de la pantalla cliente puede tener hasta 120 caracteres.',
            'product_ticket_title.required' => 'Indica el título del ticket de este despacho.',
            'product_ticket_title.string' => 'El título del ticket debe ser texto.',
            'product_ticket_title.max' => 'El título del ticket puede tener hasta 180 caracteres.',
        ];
    }
}
