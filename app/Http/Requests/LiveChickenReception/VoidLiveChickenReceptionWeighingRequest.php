<?php

namespace App\Http\Requests\LiveChickenReception;

use Illuminate\Foundation\Http\FormRequest;

class VoidLiveChickenReceptionWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_updated_at' => ['sometimes', 'date'],
        ];
    }
}
