<?php

namespace App\Http\Requests\LiveChickenReception;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidLiveChickenReceptionDispatchTicketWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'layout_version' => ['required', 'integer', Rule::in([4])],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
