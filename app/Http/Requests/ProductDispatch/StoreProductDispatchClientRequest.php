<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductDispatchClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'nombre_razon_social' => ['required', 'string', 'max:180'],
            'numero_documento' => ['required', 'string', 'regex:/^(?:\d{8}|\d{11})$/'],
            'direccion' => ['required', 'string', 'max:250'],
            'precios' => ['prohibited'],
            'es_cliente_interno' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre_razon_social.required' => 'Ingresa el nombre o razón social.',
            'nombre_razon_social.string' => 'El nombre o razón social debe ser un texto.',
            'nombre_razon_social.max' => 'El nombre o razón social no puede superar los 180 caracteres.',
            'numero_documento.required' => 'Ingresa el DNI o RUC.',
            'numero_documento.string' => 'El DNI o RUC debe ser un texto.',
            'numero_documento.regex' => 'El documento debe tener 8 dígitos para DNI u 11 dígitos para RUC.',
            'direccion.required' => 'Ingresa la dirección.',
            'direccion.string' => 'La dirección debe ser un texto.',
            'direccion.max' => 'La dirección no puede superar los 250 caracteres.',
            'precios.prohibited' => 'Esta vista no permite registrar precios para el cliente.',
            'es_cliente_interno.prohibited' => 'Los clientes registrados desde este módulo siempre son externos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('nombre_razon_social');
        $document = $this->input('numero_documento');
        $address = $this->input('direccion');
        $normalized = [];

        if (is_string($name)) {
            $normalized['nombre_razon_social'] = trim($name);
        }

        if (is_string($document)) {
            $normalized['numero_documento'] = preg_replace('/\D+/', '', $document);
        }

        if (is_string($address)) {
            $normalized['direccion'] = trim($address);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
