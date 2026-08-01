<?php

namespace App\Http\Requests\Operation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ticket_message' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ticket_message.present' => 'Debes enviar el mensaje para los tickets.',
            'ticket_message.string' => 'El mensaje para los tickets debe ser un texto.',
            'ticket_message.max' => 'El mensaje para los tickets no puede superar los 255 caracteres.',
        ];
    }
}
