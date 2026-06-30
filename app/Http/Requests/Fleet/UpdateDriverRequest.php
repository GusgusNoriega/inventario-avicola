<?php

namespace App\Http\Requests\Fleet;

class UpdateDriverRequest extends StoreDriverRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'nombre_completo' => ['sometimes', 'required', 'string', 'max:150'],
            'tipo_documento' => ['sometimes', 'nullable', 'string', 'max:30'],
            'numero_documento' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
                'regex:/^[A-Z0-9-]+$/',
            ],
            'telefono' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }
}
