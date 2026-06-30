<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
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
            'nombre_completo' => ['required', 'string', 'max:150'],
            'tipo_documento' => ['nullable', 'required_with:numero_documento', 'string', 'max:30'],
            'numero_documento' => [
                'nullable',
                'required_with:tipo_documento',
                'string',
                'max:30',
                'regex:/^[A-Z0-9-]+$/',
            ],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_documento.required_with' => 'El tipo de documento es obligatorio cuando se envia un numero de documento.',
            'numero_documento.required_with' => 'El numero de documento es obligatorio cuando se envia un tipo de documento.',
            'numero_documento.regex' => 'El numero de documento solo puede contener letras, numeros y guiones.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('nombre_completo')) {
            $values['nombre_completo'] = $this->uppercase('nombre_completo');
        }

        foreach (['tipo_documento', 'numero_documento'] as $key) {
            if ($this->exists($key)) {
                $values[$key] = $this->uppercaseNullable($key);
            }
        }

        if ($this->exists('telefono')) {
            $values['telefono'] = $this->nullableText('telefono');
        }

        $this->merge($values);
    }

    private function uppercase(string $key): string
    {
        return mb_strtoupper(trim((string) $this->input($key)), 'UTF-8');
    }

    private function uppercaseNullable(string $key): ?string
    {
        $value = $this->uppercase($key);

        return $value === '' ? null : $value;
    }

    private function nullableText(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
