<?php

namespace App\Http\Requests\Operation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ticket_title' => ['present', 'required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ticket_title.present' => 'Debes enviar el título para los tickets.',
            'ticket_title.required' => 'El título para los tickets es obligatorio.',
            'ticket_title.string' => 'El título para los tickets debe ser un texto.',
            'ticket_title.max' => 'El título para los tickets no puede superar los 120 caracteres.',
        ];
    }
}
