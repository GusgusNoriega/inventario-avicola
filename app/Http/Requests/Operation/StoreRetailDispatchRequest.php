<?php

namespace App\Http\Requests\Operation;

use App\Models\AjustePesoMinorista;
use App\Models\Balanza;
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
        $scaleCode = $this->retailStation() === 2
            ? Balanza::CODE_RETAIL_2
            : Balanza::CODE_RETAIL_1;

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
                ...($this->requiresImmediatePayment() ? ['min:1'] : []),
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
                'array:local_id,chicken_type_code,adjustment_code,tray_type_code,weight_source,scale_reading,birds_per_tray,tray_count,read_weight_kg,weighed_at',
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
                Rule::in(['MANUAL', $scaleCode]),
            ],
            'weighings.*.scale_reading' => ['sometimes', 'nullable', 'array:raw_frame,connection_mode,device_name,captured_at'],
            'weighings.*.scale_reading.raw_frame' => ['nullable', 'string', 'max:500'],
            'weighings.*.scale_reading.connection_mode' => [
                'nullable',
                Rule::in(['SERIAL', 'BLE', 'BLUETOOTH']),
            ],
            'weighings.*.scale_reading.device_name' => ['nullable', 'string', 'max:180'],
            'weighings.*.scale_reading.captured_at' => ['nullable', 'date'],
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

                $normalized = [
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

                if (array_key_exists('scale_reading', $weighing)) {
                    $normalized['scale_reading'] = $this->normalizeScaleReading($weighing['scale_reading']);
                }

                return $normalized;
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

    private function retailStation(): int
    {
        return (int) $this->route('retail_station') === 2 ? 2 : 1;
    }

    private function normalizeScaleReading(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return [
            ...$value,
            'connection_mode' => filled($value['connection_mode'] ?? null)
                ? mb_strtoupper(trim((string) $value['connection_mode']), 'UTF-8')
                : null,
            'device_name' => filled($value['device_name'] ?? null)
                ? trim((string) $value['device_name'])
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'draft_id.required' => 'No se recibió el identificador temporal del ticket. Recarga la pantalla e inténtalo nuevamente.',
            'draft_id.uuid' => 'El identificador temporal del ticket no es válido. Recarga la pantalla e inténtalo nuevamente.',
            'client_id.integer' => 'El cliente seleccionado no es válido.',
            'client_id.min' => 'El cliente seleccionado no es válido.',
            'operation_type.required' => 'Selecciona si el registro corresponde a una venta o una devolución.',
            'operation_type.in' => 'El tipo de operación seleccionado no es válido.',
            'delivery.required' => 'Selecciona el camión y el chofer que realizarán la entrega.',
            'delivery.array' => 'Los datos de transporte no tienen un formato válido.',
            'delivery.vehicle_id.required' => 'Selecciona un camión de la flota para la entrega.',
            'delivery.vehicle_id.required_with' => 'Selecciona un camión de la flota para la entrega.',
            'delivery.vehicle_id.integer' => 'El camión seleccionado no es válido.',
            'delivery.vehicle_id.exists' => 'El camión seleccionado no pertenece a la flota activa de la empresa.',
            'delivery.driver_id.required' => 'Selecciona un chofer de la flota para la entrega.',
            'delivery.driver_id.required_with' => 'Selecciona un chofer de la flota para la entrega.',
            'delivery.driver_id.integer' => 'El chofer seleccionado no es válido.',
            'delivery.driver_id.exists' => 'El chofer seleccionado no pertenece a la empresa o está inactivo.',
            'price_overrides.array' => 'Los precios asignados a la lista no tienen un formato válido.',
            'price_overrides.*.numeric' => 'Cada precio manual debe ser un número válido.',
            'price_overrides.*.gt' => 'Cada precio manual debe ser mayor que cero.',
            'price_overrides.*.max' => 'Uno de los precios manuales supera el máximo permitido.',
            'payments.array' => 'Los datos del pago no tienen un formato válido.',
            'payments.min' => 'Una venta sin cliente debe registrar al menos una forma de pago.',
            'payments.max' => 'Solo puedes dividir el cobro entre cinco formas de pago.',
            'payments.*.required' => 'Completa los datos de cada forma de pago.',
            'payments.*.array' => 'Una de las formas de pago no tiene un formato válido.',
            'payments.*.idempotency_key.required' => 'No se recibió el identificador de una forma de pago.',
            'payments.*.idempotency_key.uuid' => 'El identificador de una forma de pago no es válido.',
            'payments.*.idempotency_key.distinct' => 'Cada forma de pago debe tener un identificador diferente.',
            'payments.*.metodo_pago_id.required' => 'Selecciona un método para cada forma de pago.',
            'payments.*.metodo_pago_id.integer' => 'Uno de los métodos de pago seleccionados no es válido.',
            'payments.*.cuenta_destino_id.required' => 'Selecciona la cuenta o caja que recibirá cada pago.',
            'payments.*.cuenta_destino_id.integer' => 'Una de las cuentas o cajas seleccionadas no es válida.',
            'payments.*.moneda.in' => 'La moneda del pago debe ser PEN.',
            'payments.*.importe.required' => 'Ingresa el importe de cada forma de pago.',
            'payments.*.importe.numeric' => 'Cada importe debe ser un número válido.',
            'payments.*.importe.gt' => 'Cada importe debe ser mayor que cero.',
            'payments.*.importe.max' => 'Uno de los importes supera el máximo permitido.',
            'payments.*.referencia.string' => 'La referencia del pago debe ser un texto.',
            'payments.*.referencia.max' => 'La referencia del pago no puede superar 100 caracteres.',
            'payments.*.observaciones.string' => 'Las observaciones del pago deben ser un texto.',
            'payments.*.observaciones.max' => 'Las observaciones del pago no pueden superar 1000 caracteres.',
            'payments.*.fecha_hora.date' => 'La fecha y hora de uno de los pagos no es válida.',
            'weighings.required' => 'Agrega al menos una pesada a la lista.',
            'weighings.array' => 'Las pesadas no tienen un formato válido.',
            'weighings.min' => 'Agrega al menos una pesada a la lista.',
            'weighings.max' => 'Un ticket no puede contener más de 100 pesadas.',
            'weighings.*.required' => 'Una de las pesadas está incompleta.',
            'weighings.*.array' => 'Una de las pesadas no tiene un formato válido.',
            'weighings.*.local_id.required' => 'Una de las pesadas no tiene identificador.',
            'weighings.*.local_id.integer' => 'El identificador de una pesada no es válido.',
            'weighings.*.local_id.min' => 'El identificador de una pesada no es válido.',
            'weighings.*.local_id.distinct' => 'Cada pesada debe tener un identificador diferente.',
            'weighings.*.chicken_type_code.required' => 'Selecciona el tipo de pollo de cada pesada.',
            'weighings.*.chicken_type_code.in' => 'Uno de los tipos de pollo seleccionados no está disponible para despacho minorista.',
            'weighings.*.adjustment_code.in' => 'Uno de los ajustes de peso seleccionados no está disponible.',
            'weighings.*.tray_type_code.required' => 'Selecciona el tipo de bandeja de cada pesada.',
            'weighings.*.tray_type_code.string' => 'Uno de los tipos de bandeja no es válido.',
            'weighings.*.tray_type_code.max' => 'El código de un tipo de bandeja es demasiado largo.',
            'weighings.*.weight_source.required' => 'No se recibió el origen del peso de una pesada.',
            'weighings.*.weight_source.in' => 'Una de las pesadas proviene de una balanza que no corresponde a esta estación.',
            'weighings.*.scale_reading.array' => 'Los datos enviados por la balanza no tienen un formato válido.',
            'weighings.*.scale_reading.raw_frame.string' => 'La trama de la balanza no tiene un formato válido.',
            'weighings.*.scale_reading.raw_frame.max' => 'La trama enviada por la balanza es demasiado larga.',
            'weighings.*.scale_reading.connection_mode.in' => 'El modo de conexión de la balanza no es válido.',
            'weighings.*.scale_reading.device_name.string' => 'El nombre de la balanza no es válido.',
            'weighings.*.scale_reading.device_name.max' => 'El nombre de la balanza es demasiado largo.',
            'weighings.*.scale_reading.captured_at.date' => 'La fecha de lectura de la balanza no es válida.',
            'weighings.*.birds_per_tray.required' => 'Indica la cantidad de aves por bandeja.',
            'weighings.*.birds_per_tray.integer' => 'La cantidad de aves por bandeja debe ser un número entero.',
            'weighings.*.birds_per_tray.min' => 'Cada bandeja debe contener al menos un ave.',
            'weighings.*.birds_per_tray.max' => 'La cantidad de aves por bandeja no puede superar 10.',
            'weighings.*.tray_count.required' => 'Indica la cantidad de bandejas de cada pesada.',
            'weighings.*.tray_count.integer' => 'La cantidad de bandejas debe ser un número entero.',
            'weighings.*.tray_count.min' => 'La cantidad de bandejas no puede ser negativa.',
            'weighings.*.tray_count.max' => 'La cantidad de bandejas no puede superar 1000.',
            'weighings.*.read_weight_kg.required' => 'Captura o ingresa el peso leído por la balanza.',
            'weighings.*.read_weight_kg.numeric' => 'El peso leído debe ser un número válido.',
            'weighings.*.read_weight_kg.gt' => 'El peso leído debe ser mayor que cero.',
            'weighings.*.read_weight_kg.max' => 'El peso leído supera el máximo permitido.',
            'weighings.*.weighed_at.required' => 'No se recibió la fecha y hora de una pesada.',
            'weighings.*.weighed_at.date' => 'La fecha y hora de una pesada no es válida.',
            'payments.required' => 'Una venta sin cliente debe registrar el pago completo.',
            'payments.prohibited' => 'Los reembolsos de devoluciones se registran desde Finanzas.',
            'price_overrides.*.decimal' => 'Los precios manuales minoristas solo pueden usar hasta dos decimales.',
            'payments.*.importe.decimal' => 'Los importes de pago solo pueden usar hasta dos decimales.',
            'payments.*.cuenta_destino_id.exists' => 'Selecciona una cuenta o caja activa de la empresa.',
            'payments.*.metodo_pago_id.exists' => 'Selecciona un método de pago activo.',
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
