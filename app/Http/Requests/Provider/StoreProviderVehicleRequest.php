<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;

class StoreProviderVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'placa' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[A-Z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placa.regex' => 'La placa solo puede contener letras, números y guiones.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $plate = preg_replace('/\s+/', '', (string) $this->input('placa'));

        $this->merge([
            'placa' => mb_strtoupper($plate, 'UTF-8'),
        ]);
    }
}
