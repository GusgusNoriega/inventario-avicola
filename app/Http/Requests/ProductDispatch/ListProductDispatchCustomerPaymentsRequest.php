<?php

namespace App\Http\Requests\ProductDispatch;

use Illuminate\Foundation\Http\FormRequest;

class ListProductDispatchCustomerPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'moneda' => ['nullable', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'buscar' => ['nullable', 'string', 'max:150'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
