<?php

namespace App\Http\Requests\ProductDispatch;

use App\Services\OperationContextService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ListProductDispatchGeneralReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'preview' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $from = $this->input('date_from');
        $to = $this->input('date_to');
        $from = is_string($from) ? trim($from) : $from;
        $to = is_string($to) ? trim($to) : $to;

        if ($from === null || $from === '') {
            $branch = app(OperationContextService::class)->branch($this);
            $from = CarbonImmutable::now($branch->zona_horaria ?: config('app.timezone'))->toDateString();
        }

        $this->merge([
            'date_from' => $from,
            'date_to' => $to === null || $to === '' ? $from : $to,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.required' => 'Selecciona la fecha inicial del reporte.',
            'date_from.date_format' => 'La fecha inicial no tiene un formato válido.',
            'date_to.required' => 'Selecciona la fecha final del reporte.',
            'date_to.date_format' => 'La fecha final no tiene un formato válido.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'preview.boolean' => 'La opción de previsualización no es válida.',
        ];
    }
}
