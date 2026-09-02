<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class ListProductDispatchClientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'buscar.string' => 'La búsqueda debe ser un texto.',
            'buscar.max' => 'La búsqueda no puede superar los 100 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('buscar');

        if (is_string($search)) {
            $this->merge(['buscar' => trim($search)]);
        }
    }
}
