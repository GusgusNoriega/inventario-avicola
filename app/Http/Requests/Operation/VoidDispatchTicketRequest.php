<?php

namespace App\Http\Requests\Operation;

use Illuminate\Foundation\Http\FormRequest;

class VoidDispatchTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:250'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('motivo');

        $this->merge([
            'motivo' => is_scalar($reason) ? trim((string) $reason) : $reason,
        ]);
    }
}
