<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class ListProductDispatchAccountStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'currency' => ['required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/'],
            'preview' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clientId = $this->input('client_id');
        $dateFrom = $this->input('date_from');
        $dateTo = $this->input('date_to');
        $currency = $this->input('currency');

        if (is_string($clientId)) {
            $clientId = trim($clientId);
        }
        if (is_string($dateFrom)) {
            $dateFrom = trim($dateFrom);
        }
        if (is_string($dateTo)) {
            $dateTo = trim($dateTo);
        }
        if (is_string($currency)) {
            $currency = strtoupper(trim($currency));
        }

        $this->merge([
            'client_id' => $clientId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'currency' => $currency,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Selecciona el cliente que deseas consultar.',
            'client_id.integer' => 'El cliente seleccionado no es válido.',
            'client_id.min' => 'El cliente seleccionado no es válido.',
            'date_from.required' => 'Selecciona la fecha inicial del reporte.',
            'date_from.date_format' => 'La fecha inicial no tiene un formato válido.',
            'date_to.required' => 'Selecciona la fecha final del reporte.',
            'date_to.date_format' => 'La fecha final no tiene un formato válido.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'currency.required' => 'Selecciona la moneda del reporte.',
            'currency.string' => 'La moneda seleccionada no es válida.',
            'currency.size' => 'La moneda debe tener tres letras.',
            'currency.regex' => 'La moneda debe usar un código válido de tres letras.',
            'preview.boolean' => 'La opción de previsualización no es válida.',
        ];
    }
}
