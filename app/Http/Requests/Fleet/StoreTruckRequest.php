<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class StoreTruckRequest extends FormRequest
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
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:50'],
            'descripcion' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placa.regex' => 'La placa solo puede contener letras, numeros y guiones.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('placa')) {
            $plate = preg_replace('/\s+/', '', (string) $this->input('placa'));
            $values['placa'] = mb_strtoupper($plate, 'UTF-8');
        }

        foreach (['marca', 'modelo', 'color', 'descripcion'] as $key) {
            if ($this->exists($key)) {
                $values[$key] = $this->nullableText($key);
            }
        }

        $this->merge($values);
    }

    private function nullableText(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
