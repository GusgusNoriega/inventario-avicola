<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMinorista;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRetailConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'scale' => ['required', 'array:connection_mode,device,configuration'],
            'scale.connection_mode' => ['required', Rule::in(['SERIAL', 'BLE', 'BLUETOOTH', 'MANUAL'])],
            'scale.device' => ['nullable', 'string', 'max:180'],
            'scale.configuration' => ['required', 'array:baudRate,dataBits,stopBits,parity,flowControl,profileId,profileLabel'],
            'scale.configuration.baudRate' => ['required', 'integer', 'between:300,921600'],
            'scale.configuration.dataBits' => ['required', 'integer', Rule::in([7, 8])],
            'scale.configuration.stopBits' => ['required', 'integer', Rule::in([1, 2])],
            'scale.configuration.parity' => ['required', Rule::in(['none', 'even', 'odd'])],
            'scale.configuration.flowControl' => ['required', Rule::in(['none', 'hardware'])],
            'scale.configuration.profileId' => ['nullable', 'string', 'max:100'],
            'scale.configuration.profileLabel' => ['nullable', 'string', 'max:180'],
            'default_adjustment_code' => ['required', Rule::in(AjustePesoMinorista::codes())],
            'adjustments' => ['required', 'array', 'min:1', 'max:4'],
            'adjustments.*' => ['required', 'array:code,additional_grams'],
            'adjustments.*.code' => ['required', 'distinct', Rule::in(AjustePesoMinorista::codes())],
            'adjustments.*.additional_grams' => ['required', 'integer', 'between:0,1000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $scale = $this->input('scale');

        if (is_array($scale)) {
            $configuration = $scale['configuration'] ?? null;
            $scale['connection_mode'] = mb_strtoupper(trim((string) ($scale['connection_mode'] ?? '')), 'UTF-8');
            $scale['device'] = filled($scale['device'] ?? null)
                ? trim((string) $scale['device'])
                : null;

            if (is_array($configuration)) {
                $configuration['parity'] = mb_strtolower(trim((string) ($configuration['parity'] ?? '')), 'UTF-8');
                $configuration['flowControl'] = mb_strtolower(trim((string) ($configuration['flowControl'] ?? '')), 'UTF-8');
                $scale['configuration'] = $configuration;
            }
        }

        $adjustments = collect($this->input('adjustments', []))->map(function ($adjustment): mixed {
            if (! is_array($adjustment)) {
                return $adjustment;
            }

            return [
                ...$adjustment,
                'code' => mb_strtoupper(trim((string) ($adjustment['code'] ?? '')), 'UTF-8'),
            ];
        })->all();

        $this->merge([
            'scale' => $scale,
            'default_adjustment_code' => mb_strtoupper(
                trim((string) $this->input('default_adjustment_code', '')),
                'UTF-8'
            ),
            'adjustments' => $adjustments,
        ]);
    }
}
