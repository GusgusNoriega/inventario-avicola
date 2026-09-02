<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductDispatchTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search')
                ? trim((string) $this->input('search'))
                : null,
            'date_from' => $this->filled('date_from')
                ? trim((string) $this->input('date_from'))
                : null,
            'date_to' => $this->filled('date_to')
                ? trim((string) $this->input('date_to'))
                : null,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'search.max' => 'La búsqueda puede tener hasta 120 caracteres.',
            'date_from.date_format' => 'La fecha inicial no tiene un formato válido.',
            'date_to.date_format' => 'La fecha final no tiene un formato válido.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'per_page.in' => 'La cantidad de tickets por página no es válida.',
        ];
    }
}
