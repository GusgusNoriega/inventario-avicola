<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMinorista;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreRetailDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'draft_id' => ['required', 'uuid'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'operation_type' => ['required', Rule::in([
                TicketDespacho::OPERATION_DISPATCH,
                TicketDespacho::OPERATION_RETURN,
            ])],
            'delivery' => [
                Rule::requiredIf(fn (): bool => $this->requiresDelivery()),
                'nullable',
                'array:vehicle_id,driver_id',
            ],
            'delivery.vehicle_id' => [
                Rule::requiredIf(fn (): bool => $this->requiresDelivery()),
                'required_with:delivery.driver_id',
                'nullable',
                'integer',
                Rule::exists('vehiculos', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $this->companyId())
                    ->where('estado', 'ACTIVO')),
            ],
            'delivery.driver_id' => [
                Rule::requiredIf(fn (): bool => $this->requiresDelivery()),
                'required_with:delivery.vehicle_id',
                'nullable',
                'integer',
                Rule::exists('conductores', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $this->companyId())
                    ->where('estado', 'ACTIVO')),
            ],
            'price_overrides' => [
                'sometimes',
                'array:'.implode(',', [
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
            ],
            'price_overrides.*' => ['numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'payments' => [
                Rule::requiredIf(fn (): bool => $this->requiresImmediatePayment()),
                Rule::prohibitedIf(fn (): bool => $this->input('operation_type') === TicketDespacho::OPERATION_RETURN),
                'array',
                'min:1',
                'max:5',
            ],
            'payments.*' => [
                'required',
                'array:idempotency_key,metodo_pago_id,cuenta_destino_id,moneda,importe,referencia,observaciones,fecha_hora',
            ],
            'payments.*.idempotency_key' => ['required', 'uuid', 'distinct'],
            'payments.*.metodo_pago_id' => [
                'required',
                'integer',
                Rule::exists('metodos_pago', 'id')->where(fn ($query) => $query
                    ->where('estado', 'ACTIVO')),
            ],
            'payments.*.cuenta_destino_id' => [
                'required',
                'integer',
                Rule::exists('cuentas_financieras', 'id')->where(fn ($query) => $query
                    ->where('estado', 'ACTIVO')
                    ->whereIn('entidad_financiera_id', DB::table('entidades_financieras')
                        ->where('empresa_id', $this->companyId())
                        ->where('tipo', 'PROPIA')
                        ->where('estado', 'ACTIVO')
                        ->select('id'))),
            ],
            'payments.*.moneda' => ['sometimes', Rule::in(['PEN'])],
            'payments.*.importe' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'payments.*.referencia' => ['nullable', 'string', 'max:100'],
            'payments.*.observaciones' => ['nullable', 'string', 'max:1000'],
            'payments.*.fecha_hora' => ['nullable', 'date'],
            'weighings' => ['required', 'array', 'min:1', 'max:100'],
            'weighings.*' => [
                'required',
                'array:local_id,chicken_type_code,adjustment_code,tray_type_code,weight_source,birds_per_tray,tray_count,read_weight_kg,weighed_at',
            ],
            'weighings.*.local_id' => ['required', 'integer', 'min:1', 'distinct'],
            'weighings.*.chicken_type_code' => [
                'required',
                Rule::in([
                    TipoPollo::CHICKEN_DRESSED,
                    TipoPollo::CHICKEN_PROCESSED,
                ]),
            ],
            'weighings.*.adjustment_code' => ['nullable', Rule::in(AjustePesoMinorista::codes())],
            'weighings.*.tray_type_code' => ['required', 'string', 'max:40'],
            'weighings.*.weight_source' => [
                'required',
                Rule::in(['MANUAL', 'BALANZA_MINORISTA']),
            ],
            'weighings.*.birds_per_tray' => ['required', 'integer', 'min:1', 'max:10'],
            'weighings.*.tray_count' => ['required', 'integer', 'min:0', 'max:1000'],
            'weighings.*.read_weight_kg' => ['required', 'numeric', 'gt:0', 'max:99999999.999'],
            'weighings.*.weighed_at' => ['required', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $weighings = collect($this->input('weighings', []))
            ->map(function ($weighing): mixed {
                if (! is_array($weighing)) {
                    return $weighing;
                }

                return [
                    ...$weighing,
                    'chicken_type_code' => mb_strtoupper(
                        trim((string) ($weighing['chicken_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'adjustment_code' => filled($weighing['adjustment_code'] ?? null)
                        ? mb_strtoupper(
                            trim((string) $weighing['adjustment_code']),
                            'UTF-8'
                        )
                        : null,
                    'tray_type_code' => mb_strtoupper(
                        trim((string) ($weighing['tray_type_code'] ?? '')),
                        'UTF-8'
                    ),
                    'weight_source' => mb_strtoupper(
                        trim((string) ($weighing['weight_source'] ?? '')),
                        'UTF-8'
                    ),
                ];
            })
            ->all();
        $priceOverrides = $this->input('price_overrides');

        if (is_array($priceOverrides)) {
            $priceOverrides = collect($priceOverrides)
                ->mapWithKeys(fn (mixed $price, mixed $code): array => [
                    mb_strtoupper(trim((string) $code), 'UTF-8') => $price,
                ])
                ->all();
        }

        $normalized = [
            'operation_type' => mb_strtoupper(
                trim((string) $this->input('operation_type', '')),
                'UTF-8'
            ),
            'weighings' => $weighings,
        ];

        if ($this->exists('price_overrides')) {
            $normalized['price_overrides'] = $priceOverrides;
        }

        if ($this->exists('payments') && is_array($this->input('payments'))) {
            $normalized['payments'] = collect($this->input('payments'))
                ->map(function (mixed $payment): mixed {
                    if (! is_array($payment)) {
                        return $payment;
                    }

                    return [
                        ...$payment,
                        'moneda' => mb_strtoupper(trim((string) ($payment['moneda'] ?? 'PEN')), 'UTF-8'),
                        'referencia' => filled($payment['referencia'] ?? null)
                            ? trim((string) $payment['referencia'])
                            : null,
                        'observaciones' => filled($payment['observaciones'] ?? null)
                            ? trim((string) $payment['observaciones'])
                            : null,
                    ];
                })
                ->all();
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'delivery.required' => 'Selecciona el camion y el chofer que realizaran la entrega.',
            'delivery.vehicle_id.required' => 'Selecciona un camion de la flota para la entrega.',
            'delivery.vehicle_id.required_with' => 'Selecciona un camion de la flota para la entrega.',
            'delivery.vehicle_id.exists' => 'El camion seleccionado no pertenece a la flota activa de la empresa.',
            'delivery.driver_id.required' => 'Selecciona un chofer de la flota para la entrega.',
            'delivery.driver_id.required_with' => 'Selecciona un chofer de la flota para la entrega.',
            'delivery.driver_id.exists' => 'El chofer seleccionado no pertenece a la empresa o esta inactivo.',
            'weighings.min' => 'Agrega al menos una pesada a la lista.',
            'weighings.*.local_id.distinct' => 'Cada pesada debe tener un identificador diferente.',
            'weighings.*.birds_per_tray.max' => 'La cantidad de aves por bandeja no puede superar 10.',
            'weighings.*.tray_count.min' => 'La cantidad de bandejas no puede ser negativa.',
            'weighings.*.read_weight_kg.required' => 'Captura o ingresa el peso leido por la balanza.',
            'payments.required' => 'Una venta sin cliente debe registrar el pago completo.',
            'payments.prohibited' => 'Los reembolsos de devoluciones se registran desde Finanzas.',
            'price_overrides.*.decimal' => 'Los precios manuales minoristas solo pueden usar hasta dos decimales.',
            'payments.*.importe.decimal' => 'Los importes de pago solo pueden usar hasta dos decimales.',
            'payments.*.cuenta_destino_id.exists' => 'Selecciona una cuenta o caja activa de la empresa.',
            'payments.*.metodo_pago_id.exists' => 'Selecciona un metodo de pago activo.',
        ];
    }

    private function requiresDelivery(): bool
    {
        if (
            $this->input('operation_type') !== TicketDespacho::OPERATION_DISPATCH
            || ! filled($this->input('client_id'))
        ) {
            return false;
        }

        return collect($this->input('weighings', []))->contains(
            fn (mixed $weighing): bool => is_array($weighing)
                && (int) ($weighing['tray_count'] ?? 0) > 0
        );
    }

    private function requiresImmediatePayment(): bool
    {
        return $this->input('operation_type') === TicketDespacho::OPERATION_DISPATCH
            && ! filled($this->input('client_id'));
    }

    private function companyId(): int
    {
        return (int) ($this->user()?->empresa_id
            ?? DB::table('empresas')->where('estado', 'ACTIVO')->orderBy('id')->value('id'));
    }
}
