<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMinorista;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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
            'scale' => [
                'sometimes',
                'array:connection_mode,device,configuration',
                'required_array_keys:connection_mode,configuration',
            ],
            'scale.connection_mode' => ['required_with:scale', Rule::in(['SERIAL', 'BLE', 'BLUETOOTH', 'MANUAL'])],
            'scale.device' => ['nullable', 'string', 'max:180'],
            'scale.configuration' => [
                'required_with:scale',
                'array:baudRate,dataBits,stopBits,parity,flowControl,profileId,profileLabel',
                'required_array_keys:baudRate,dataBits,stopBits,parity,flowControl',
            ],
            'scale.configuration.baudRate' => ['required_with:scale.configuration', 'integer', 'between:300,921600'],
            'scale.configuration.dataBits' => ['required_with:scale.configuration', 'integer', Rule::in([7, 8])],
            'scale.configuration.stopBits' => ['required_with:scale.configuration', 'integer', Rule::in([1, 2])],
            'scale.configuration.parity' => ['required_with:scale.configuration', Rule::in(['none', 'even', 'odd'])],
            'scale.configuration.flowControl' => ['required_with:scale.configuration', Rule::in(['none', 'hardware'])],
            'scale.configuration.profileId' => ['nullable', 'string', 'max:100'],
            'scale.configuration.profileLabel' => ['nullable', 'string', 'max:180'],
            'default_adjustment_code' => ['required', Rule::in(AjustePesoMinorista::codes())],
            'adjustments' => ['required', 'array', 'min:1', 'max:4'],
            'adjustments.*' => ['required', 'array:code,additional_grams'],
            'adjustments.*.code' => ['required', 'distinct', Rule::in(AjustePesoMinorista::codes())],
            'adjustments.*.additional_grams' => ['required', 'integer', 'between:0,1000000'],
            'payment_defaults' => [
                'sometimes',
                'array:method_id,account_id',
                'required_array_keys:method_id,account_id',
            ],
            'payment_defaults.method_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => filled($this->input('payment_defaults.account_id'))),
                'integer',
                Rule::exists('metodos_pago', 'id')->where(fn ($query) => $query
                    ->where('estado', 'ACTIVO')),
            ],
            'payment_defaults.account_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => filled($this->input('payment_defaults.method_id'))),
                'integer',
                Rule::exists('cuentas_financieras', 'id')->where(fn ($query) => $query
                    ->where('estado', 'ACTIVO')
                    ->where('moneda', 'PEN')
                    ->whereIn('entidad_financiera_id', DB::table('entidades_financieras')
                        ->where('empresa_id', $this->companyId())
                        ->where('tipo', 'PROPIA')
                        ->where('estado', 'ACTIVO')
                        ->select('id'))),
            ],
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

        $normalized = [
            'default_adjustment_code' => mb_strtoupper(
                trim((string) $this->input('default_adjustment_code', '')),
                'UTF-8'
            ),
            'adjustments' => $adjustments,
        ];

        if ($this->exists('scale')) {
            $normalized['scale'] = $scale;
        }

        $this->merge($normalized);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_defaults.array' => 'La configuracion de pago predeterminada no tiene un formato valido.',
            'payment_defaults.required_array_keys' => 'Selecciona el metodo de pago y la cuenta o caja predeterminada.',
            'payment_defaults.method_id.required' => 'Selecciona el metodo de pago predeterminado.',
            'payment_defaults.method_id.integer' => 'El metodo de pago predeterminado no es valido.',
            'payment_defaults.method_id.exists' => 'Selecciona un metodo de pago activo.',
            'payment_defaults.account_id.required' => 'Selecciona la cuenta o caja predeterminada.',
            'payment_defaults.account_id.integer' => 'La cuenta o caja predeterminada no es valida.',
            'payment_defaults.account_id.exists' => 'Selecciona una cuenta o caja propia, activa y en moneda PEN.',
        ];
    }

    private function companyId(): int
    {
        return (int) ($this->user()?->empresa_id
            ?? DB::table('empresas')->where('estado', 'ACTIVO')->orderBy('id')->value('id'));
    }
}
