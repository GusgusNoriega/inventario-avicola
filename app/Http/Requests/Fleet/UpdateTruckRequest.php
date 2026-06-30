<?php

namespace App\Http\Requests\Fleet;

class UpdateTruckRequest extends StoreTruckRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'placa' => ['sometimes', 'required', 'string', 'min:3', 'max:20', 'regex:/^[A-Z0-9-]+$/'],
            'marca' => ['sometimes', 'nullable', 'string', 'max:80'],
            'modelo' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
