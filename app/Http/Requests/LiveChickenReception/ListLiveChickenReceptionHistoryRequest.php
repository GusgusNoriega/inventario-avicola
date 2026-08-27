<?php

namespace App\Http\Requests\LiveChickenReception;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLiveChickenReceptionHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'journey_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['TODAS', 'ACTIVA', 'ANULADA'])],
            'source' => ['required', Rule::in(['TODAS', 'RECEPCION', 'TICKET'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'journey_id' => filled($this->input('journey_id'))
                ? $this->input('journey_id')
                : null,
            'status' => $this->normalizedOption('status', 'TODAS'),
            'source' => $this->normalizedOption('source', 'TODAS'),
        ]);
    }

    private function normalizedOption(string $field, string $default): mixed
    {
        $value = $this->input($field, $default);

        if (! is_scalar($value)) {
            return $value;
        }

        $value = mb_strtoupper(trim((string) $value), 'UTF-8');

        return $value === '' ? $default : $value;
    }
}
