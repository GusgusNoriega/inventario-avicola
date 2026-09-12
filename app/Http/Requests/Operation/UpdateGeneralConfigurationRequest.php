<?php

namespace App\Http\Requests\Operation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cutoff' => ['required', 'date_format:H:i'],
            'expected_cutoff' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cutoff.required' => 'Selecciona la hora de cierre y apertura de la jornada.',
            'cutoff.date_format' => 'La hora debe ser válida y usar el formato de 24 horas (HH:mm).',
            'expected_cutoff.required' => 'Recarga la configuración antes de guardar.',
            'expected_cutoff.date_format' => 'Recarga la configuración antes de guardar.',
        ];
    }
}
