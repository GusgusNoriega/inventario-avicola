<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class DeleteProductDispatchTicketRequest extends FormRequest
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $version = $this->input('version');

        if (is_string($version)) {
            $this->merge(['version' => trim($version)]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'version.required' => 'Actualiza el detalle del ticket antes de eliminarlo.',
            'version.date' => 'La versión del ticket no es válida.',
        ];
    }
}
