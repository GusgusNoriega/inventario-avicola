<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\StoreRetailDispatchRequest;
use App\Http\Requests\Operation\UpdateRetailConfigurationRequest;
use App\Models\CuentaFinanciera;
use App\Models\EntidadFinanciera;
use App\Models\MetodoPago;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespacho;
use App\Models\TipoBandeja;
use App\Models\TipoPollo;
use App\Services\OperationContextService;
use App\Services\RetailConfigurationService;
use App\Services\RetailDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetailDispatchController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly RetailDispatchService $dispatches,
        private readonly RetailConfigurationService $configuration
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $clients = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->orderBy('nombre_razon_social')
            ->get(['id', 'nombre_razon_social', 'numero_documento']);
        $chickenTypes = TipoPollo::query()
            ->whereIn('codigo', [
                TipoPollo::CHICKEN_DRESSED,
                TipoPollo::CHICKEN_PROCESSED,
            ])
            ->where('estado', TipoPollo::STATUS_ACTIVE)
            ->where('permite_despacho', true)
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'precio_fuente_tipo_pollo_id']);
        $prices = $this->configuration->pricesForClients($companyId, $clients, $chickenTypes);
        $generalPrices = $this->configuration->generalPrices($companyId, $chickenTypes);
        $retailConfiguration = $this->configuration->configuration(
            $companyId,
            (int) $branch->id,
            $this->retailStation($request)
        );

        return response()->json([
            'data' => [
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->nombre,
                    'timezone' => $branch->zona_horaria,
                ],
                'clients' => $clients
                    ->map(fn (Tercero $client): array => [
                        'id' => $client->id,
                        'name' => $client->nombre_razon_social,
                        'document' => $client->numero_documento,
                        'prices' => $prices[$client->id] ?? [],
                    ])
                    ->values(),
                'general_prices' => $generalPrices,
                'delivery_trucks' => DB::table('vehiculos')
                    ->where('empresa_id', $companyId)
                    ->where('estado', 'ACTIVO')
                    ->orderBy('placa')
                    ->get(['id', 'placa', 'marca', 'modelo', 'color', 'descripcion'])
                    ->map(fn (object $truck): array => [
                        'id' => $truck->id,
                        'plate' => $truck->placa,
                        'brand' => $truck->marca,
                        'model' => $truck->modelo,
                        'color' => $truck->color,
                        'description' => $truck->descripcion,
                    ])
                    ->values(),
                'delivery_drivers' => DB::table('conductores')
                    ->where('empresa_id', $companyId)
                    ->where('estado', 'ACTIVO')
                    ->orderBy('nombre_completo')
                    ->get(['id', 'nombre_completo', 'tipo_documento', 'numero_documento', 'telefono'])
                    ->map(fn (object $driver): array => [
                        'id' => $driver->id,
                        'name' => $driver->nombre_completo,
                        'document_type' => $driver->tipo_documento,
                        'document_number' => $driver->numero_documento,
                        'phone' => $driver->telefono,
                    ])
                    ->values(),
                'chicken_types' => $chickenTypes
                    ->map(fn (TipoPollo $type): array => [
                        'id' => $type->id,
                        'code' => $type->codigo,
                        'name' => $type->nombre,
                    ])
                    ->values(),
                'tray_types' => TipoBandeja::query()
                    ->where('estado', TipoBandeja::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->get(['id', 'codigo', 'nombre', 'peso_kg', 'capacidad_aves'])
                    ->map(fn (TipoBandeja $tray): array => [
                        'id' => $tray->id,
                        'code' => $tray->codigo,
                        'name' => $tray->nombre,
                        'weight_kg' => (float) $tray->peso_kg,
                        'bird_capacity' => $tray->capacidad_aves,
                    ])
                    ->values(),
                'adjustments' => $retailConfiguration['adjustments'],
                'scale' => $retailConfiguration['scale'],
                'financial' => [
                    'default_method_id' => $retailConfiguration['payment_defaults']['method_id'],
                    'default_account_id' => $retailConfiguration['payment_defaults']['account_id'],
                    'methods' => MetodoPago::query()
                        ->where('estado', MetodoPago::STATUS_ACTIVE)
                        ->orderBy('id')
                        ->get(['id', 'codigo', 'nombre', 'requiere_referencia'])
                        ->map(fn (MetodoPago $method): array => [
                            'id' => $method->id,
                            'code' => $method->codigo,
                            'name' => $method->nombre,
                            'requires_reference' => (bool) $method->requiere_referencia,
                        ])
                        ->values(),
                    'own_accounts' => CuentaFinanciera::query()
                        ->where('estado', CuentaFinanciera::STATUS_ACTIVE)
                        ->where('moneda', 'PEN')
                        ->whereHas('entidadFinanciera', fn ($query) => $query
                            ->where('empresa_id', $companyId)
                            ->where('tipo', EntidadFinanciera::TYPE_OWN)
                            ->where('estado', EntidadFinanciera::STATUS_ACTIVE))
                        ->with('entidadFinanciera:id,razon_social,nombre_comercial')
                        ->orderBy('alias')
                        ->get()
                        ->map(fn (CuentaFinanciera $account): array => [
                            'id' => $account->id,
                            'type' => $account->tipo,
                            'alias' => $account->alias,
                            'bank' => $account->banco,
                            'masked_number' => $this->maskedAccount($account->numero_cuenta),
                            'currency' => $account->moneda,
                            'entity' => [
                                'id' => $account->entidad_financiera_id,
                                'name' => $account->entidadFinanciera?->nombre_comercial
                                    ?: $account->entidadFinanciera?->razon_social,
                            ],
                        ])
                        ->values(),
                ],
            ],
        ]);
    }

    public function updateConfiguration(UpdateRetailConfigurationRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $configuration = $this->configuration->update(
            $companyId,
            (int) $branch->id,
            $request->validated(),
            $this->retailStation($request)
        );

        return response()->json([
            'message' => 'Configuracion minorista actualizada correctamente.',
            'data' => $configuration,
        ]);
    }

    public function store(StoreRetailDispatchRequest $request): JsonResponse
    {
        $branch = $this->context->branch($request);
        $station = $this->retailStation($request);
        $result = $this->dispatches->register(
            $this->context->companyId($request),
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $request->validated(),
            $station
        );

        return response()->json([
            'message' => $result['already_registered']
                ? 'El despacho ya estaba registrado.'
                : 'Despacho minorista registrado correctamente.',
            'already_registered' => $result['already_registered'],
            'data' => $this->formatTicket($result['ticket']),
        ], $result['already_registered'] ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function formatTicket(TicketDespacho $ticket): array
    {
        $prices = $ticket->precios->keyBy('tipo_pollo_id');
        $sign = $ticket->tipo_operacion === TicketDespacho::OPERATION_RETURN ? -1 : 1;
        $totalAmount = $ticket->pesadas->sum(function ($weighing) use ($prices, $sign): float {
            $price = round(
                (float) ($prices->get($weighing->tipo_pollo_id)?->precio_kg ?? 0),
                2,
                PHP_ROUND_HALF_UP
            );

            return $sign * round((float) $weighing->peso_neto_kg * $price, 2);
        });

        return [
            'id' => $ticket->id,
            'draft_id' => $ticket->referencia_externa,
            'code' => $ticket->codigo,
            'channel' => $ticket->canal,
            'operation_type' => $ticket->tipo_operacion,
            'status' => $ticket->estado,
            'operating_date' => $ticket->jornada->fecha_operativa?->format('Y-m-d'),
            'registered_at' => $ticket->cerrado_at?->toISOString(),
            'client' => $ticket->clienteDestino
                ? [
                    'id' => $ticket->clienteDestino->id,
                    'name' => $ticket->clienteDestino->nombre_razon_social,
                ]
                : null,
            'delivery' => $ticket->tipo_operacion === TicketDespacho::OPERATION_DISPATCH
                ? [
                    'vehicle' => $ticket->vehiculoEntrega
                        ? [
                            'id' => $ticket->vehiculoEntrega->id,
                            'plate' => $ticket->vehiculoEntrega->placa,
                            'description' => $ticket->vehiculoEntrega->descripcion,
                        ]
                        : null,
                    'driver' => $ticket->conductorEntrega
                        ? [
                            'id' => $ticket->conductorEntrega->id,
                            'name' => $ticket->conductorEntrega->nombre_completo,
                            'document_number' => $ticket->conductorEntrega->numero_documento,
                        ]
                        : null,
                ]
                : null,
            'prices' => $ticket->precios->mapWithKeys(fn ($price): array => [
                $price->tipoPollo?->codigo ?? (string) $price->tipo_pollo_id => [
                    'price_kg' => round((float) $price->precio_kg, 2, PHP_ROUND_HALF_UP),
                    'source' => $price->origen_precio,
                    'history_id' => $price->precio_historial_id,
                ],
            ]),
            'totals' => [
                'weighings' => $ticket->pesadas->count(),
                'trays' => (int) $ticket->pesadas->sum('cantidad_bandejas'),
                'birds' => (int) $ticket->pesadas->sum('cantidad_aves'),
                'read_weight_kg' => round((float) $ticket->pesadas->sum('peso_leido_kg'), 3),
                'gross_weight_kg' => round((float) $ticket->pesadas->sum('peso_bruto_kg'), 3),
                'tare_weight_kg' => round((float) $ticket->pesadas->sum('tara_total_kg'), 3),
                'net_weight_kg' => round((float) $ticket->pesadas->sum('peso_neto_kg'), 3),
                'amount' => round($totalAmount, 2),
            ],
            'weighings' => $ticket->pesadas->map(function ($weighing) use ($prices, $sign): array {
                $frozenPrice = $prices->get($weighing->tipo_pollo_id);
                $price = round(
                    (float) ($frozenPrice?->precio_kg ?? 0),
                    2,
                    PHP_ROUND_HALF_UP
                );

                return [
                    'id' => $weighing->id,
                    'number' => $weighing->numero,
                    'chicken_type_code' => $weighing->tipoPollo->codigo,
                    'chicken_type' => $weighing->tipoPollo->nombre,
                    'chicken_sex' => $weighing->sexo,
                    'presentation' => $weighing->presentacion_pollo,
                    'adjustment' => [
                        'code' => $weighing->ajustePesoMinorista?->codigo,
                        'name' => $weighing->ajustePesoMinorista?->nombre,
                        'additional_grams' => (int) $weighing->ajuste_peso_gramos,
                    ],
                    'tray_type_code' => $weighing->tipoBandeja->codigo,
                    'tray_type' => $weighing->tipoBandeja->nombre,
                    'birds_per_tray' => $weighing->aves_por_bandeja,
                    'tray_count' => $weighing->cantidad_bandejas,
                    'birds' => $weighing->cantidad_aves,
                    'weight_source' => $weighing->origen_peso,
                    'read_weight_kg' => (float) $weighing->peso_leido_kg,
                    'gross_weight_kg' => (float) $weighing->peso_bruto_kg,
                    'tare_weight_kg' => (float) $weighing->tara_total_kg,
                    'net_weight_kg' => (float) $weighing->peso_neto_kg,
                    'price_kg' => $price,
                    'price_origin' => $frozenPrice?->origen_precio,
                    'price_history_id' => $frozenPrice?->precio_historial_id,
                    'amount' => $sign * round((float) $weighing->peso_neto_kg * $price, 2),
                    'weighed_at' => $weighing->pesada_at?->toISOString(),
                ];
            })->values(),
        ];
    }

    private function maskedAccount(?string $number): ?string
    {
        if (! filled($number)) {
            return null;
        }

        $visible = mb_substr((string) $number, -4);

        return str_repeat('•', max(mb_strlen((string) $number) - 4, 0)).$visible;
    }

    private function retailStation(Request $request): int
    {
        return (int) $request->route('retail_station') === 2 ? 2 : 1;
    }
}
