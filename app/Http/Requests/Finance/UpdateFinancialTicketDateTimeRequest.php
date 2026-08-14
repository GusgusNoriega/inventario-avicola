<?php

namespace App\Http\Requests\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;

class UpdateFinancialTicketDateTimeRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_hora' => [
                'required',
                'string',
                'date_format:Y-m-d\TH:i',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('fecha_hora')) {
                return;
            }

            $timezone = (string) (
                DB::table('empresas')
                    ->where('id', $this->companyId())
                    ->value('zona_horaria')
                ?: config('app.timezone', 'UTC')
            );
            $requested = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) $this->input('fecha_hora'),
                $timezone,
            );

            if ($requested->greaterThan(CarbonImmutable::now($timezone)->addMinutes(5))) {
                $validator->errors()->add(
                    'fecha_hora',
                    'La fecha y hora del ticket no puede estar en el futuro.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fecha_hora' => $this->trimmedNullable('fecha_hora'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha_hora.required' => 'Indica la nueva fecha y hora del ticket.',
            'fecha_hora.date_format' => 'La fecha y hora no tiene un formato válido.',
        ];
    }
}
