<?php

namespace App\Services;

use App\Models\AjustePesoMinorista;
use App\Models\Balanza;
use App\Models\ConfiguracionDespachoMinorista;
use App\Models\CuentaFinanciera;
use App\Models\EntidadFinanciera;
use App\Models\ListaPrecio;
use App\Models\MetodoPago;
use App\Models\Tercero;
use App\Models\TipoPollo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailConfigurationService
{
    public const SCALE_CODE = Balanza::CODE_RETAIL_1;

    public const SCALE_CODE_2 = Balanza::CODE_RETAIL_2;

    /** @return array{baudRate: int, dataBits: int, stopBits: int, parity: string, flowControl: string} */
    public static function defaultSerialConfiguration(): array
    {
        return [
            'baudRate' => 9600,
            'dataBits' => 8,
            'stopBits' => 1,
            'parity' => 'none',
            'flowControl' => 'none',
        ];
    }

    /** @return list<array{code: string, name: string, sex: string, presentation: string, is_default: bool}> */
    private static function adjustmentDefinitions(): array
    {
        return [
            ['code' => AjustePesoMinorista::MALE_CLOSED, 'name' => 'Macho cerrado', 'sex' => 'MACHO', 'presentation' => 'CERRADO', 'is_default' => true],
            ['code' => AjustePesoMinorista::MALE_OPEN, 'name' => 'Macho abierto', 'sex' => 'MACHO', 'presentation' => 'ABIERTO', 'is_default' => false],
            ['code' => AjustePesoMinorista::FEMALE_CLOSED, 'name' => 'Hembra cerrada', 'sex' => 'HEMBRA', 'presentation' => 'CERRADA', 'is_default' => false],
            ['code' => AjustePesoMinorista::FEMALE_OPEN, 'name' => 'Hembra abierta', 'sex' => 'HEMBRA', 'presentation' => 'ABIERTA', 'is_default' => false],
        ];
    }

    public function ensureDefaults(int $companyId, int $branchId, int $station = 1): void
    {
        $now = now();
        $rows = collect(self::adjustmentDefinitions())->map(fn (array $definition): array => [
            'empresa_id' => $companyId,
            'estacion' => $station,
            'codigo' => $definition['code'],
            'nombre' => $definition['name'],
            'sexo' => $definition['sex'],
            'presentacion' => $definition['presentation'],
            'gramos_adicionales' => 0,
            'predeterminado' => $definition['is_default'],
            'estado' => AjustePesoMinorista::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        AjustePesoMinorista::query()->upsert(
            $rows,
            ['empresa_id', 'estacion', 'codigo'],
            ['nombre', 'sexo', 'presentacion', 'updated_at']
        );

        $hasDefault = AjustePesoMinorista::query()
            ->where('empresa_id', $companyId)
            ->where('estacion', $station)
            ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
            ->where('predeterminado', true)
            ->exists();

        if (! $hasDefault) {
            AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->where('codigo', AjustePesoMinorista::MALE_CLOSED)
                ->update([
                    'predeterminado' => true,
                    'estado' => AjustePesoMinorista::STATUS_ACTIVE,
                ]);
        }

        $scaleCode = $this->scaleCode($station);
        $scaleName = $this->scaleName($station);
        DB::table('balanzas')->insertOrIgnore([
            'sucursal_id' => $branchId,
            'codigo' => $scaleCode,
            'nombre' => $scaleName,
            'modo_conexion' => 'SERIAL',
            'dispositivo' => null,
            'configuracion' => json_encode(self::defaultSerialConfiguration(), JSON_THROW_ON_ERROR),
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hasStationConfiguration = ConfiguracionDespachoMinorista::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->where('estacion', $station)
            ->exists();

        if (! $hasStationConfiguration) {
            $defaultMethodId = DB::table('metodos_pago')
                ->where('codigo', MetodoPago::CODE_CASH)
                ->where('estado', MetodoPago::STATUS_ACTIVE)
                ->value('id');
            $defaultAccountId = DB::table('cuentas_financieras as cuenta')
                ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
                ->where('entidad.empresa_id', $companyId)
                ->where('entidad.tipo', EntidadFinanciera::TYPE_OWN)
                ->where('entidad.estado', EntidadFinanciera::STATUS_ACTIVE)
                ->where('cuenta.estado', CuentaFinanciera::STATUS_ACTIVE)
                ->where('cuenta.moneda', 'PEN')
                ->orderByRaw(
                    'CASE WHEN cuenta.tipo = ? THEN 0 ELSE 1 END',
                    [CuentaFinanciera::TYPE_CASH]
                )
                ->orderBy('cuenta.id')
                ->value('cuenta.id');
            $hasPaymentDefaults = $defaultMethodId !== null && $defaultAccountId !== null;

            DB::table('configuraciones_despacho_minorista')->insertOrIgnore([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'estacion' => $station,
                'metodo_pago_id' => $hasPaymentDefaults ? $defaultMethodId : null,
                'cuenta_destino_id' => $hasPaymentDefaults ? $defaultAccountId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{
     *     scale: array{code: string, name: string, connection_mode: ?string, device: ?string, configuration: array<string, mixed>},
     *     adjustments: Collection<int, array<string, mixed>>,
     *     payment_defaults: array{method_id: ?int, account_id: ?int}
     * }
     */
    public function configuration(int $companyId, int $branchId, int $station = 1): array
    {
        $this->ensureDefaults($companyId, $branchId, $station);
        $scaleCode = $this->scaleCode($station);
        $scaleName = $this->scaleName($station);

        $scale = DB::table('balanzas')
            ->where('sucursal_id', $branchId)
            ->where('codigo', $scaleCode)
            ->first();
        $storedConfiguration = is_string($scale?->configuracion)
            ? json_decode($scale->configuracion, true)
            : $scale?->configuracion;
        $serialConfiguration = [
            ...self::defaultSerialConfiguration(),
            ...(is_array($storedConfiguration) ? $storedConfiguration : []),
        ];

        return [
            'scale' => [
                'code' => $scaleCode,
                'name' => $scale?->nombre ?? $scaleName,
                'connection_mode' => $scale?->modo_conexion ?? 'SERIAL',
                'device' => $scale?->dispositivo,
                'configuration' => $serialConfiguration,
            ],
            'adjustments' => AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
                ->orderBy('id')
                ->get()
                ->map(fn (AjustePesoMinorista $adjustment): array => [
                    'code' => $adjustment->codigo,
                    'name' => $adjustment->nombre,
                    'sex' => $adjustment->sexo,
                    'presentation' => $adjustment->presentacion,
                    'additional_grams' => $adjustment->gramos_adicionales,
                    'is_default' => $adjustment->predeterminado,
                ])
                ->values(),
            'payment_defaults' => $this->paymentDefaults($companyId, $branchId, $station),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     scale: array<string, mixed>,
     *     adjustments: Collection<int, array<string, mixed>>,
     *     payment_defaults: array{method_id: ?int, account_id: ?int}
     * }
     */
    public function update(int $companyId, int $branchId, array $data, int $station = 1): array
    {
        $this->ensureDefaults($companyId, $branchId, $station);
        $scaleCode = $this->scaleCode($station);

        DB::transaction(function () use ($companyId, $branchId, $data, $scaleCode, $station): void {
            $adjustments = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->whereIn('codigo', collect($data['adjustments'])->pluck('code'))
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');

            foreach ($data['adjustments'] as $index => $values) {
                $adjustment = $adjustments->get($values['code']);

                if (! $adjustment) {
                    throw ValidationException::withMessages([
                        "adjustments.{$index}.code" => 'El ajuste minorista seleccionado no pertenece a la empresa.',
                    ]);
                }

                $adjustment->update(['gramos_adicionales' => $values['additional_grams']]);
            }

            AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->update(['predeterminado' => false]);
            $updated = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estacion', $station)
                ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
                ->where('codigo', $data['default_adjustment_code'])
                ->update(['predeterminado' => true]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'default_adjustment_code' => 'El ajuste predeterminado no esta disponible para la empresa.',
                ]);
            }

            if (isset($data['scale'])) {
                DB::table('balanzas')
                    ->where('sucursal_id', $branchId)
                    ->where('codigo', $scaleCode)
                    ->update([
                        'modo_conexion' => $data['scale']['connection_mode'],
                        'dispositivo' => $data['scale']['device'],
                        'configuracion' => json_encode($data['scale']['configuration'], JSON_THROW_ON_ERROR),
                        'estado' => Balanza::STATUS_ACTIVE,
                        'updated_at' => now(),
                    ]);
            }

            if (array_key_exists('payment_defaults', $data)) {
                $defaults = $this->validatedPaymentDefaults(
                    $companyId,
                    $data['payment_defaults']
                );

                $configuration = ConfiguracionDespachoMinorista::query()
                    ->where('empresa_id', $companyId)
                    ->where('sucursal_id', $branchId)
                    ->where('estacion', $station)
                    ->lockForUpdate()
                    ->first();

                if (! $configuration) {
                    throw ValidationException::withMessages([
                        'payment_defaults' => 'No existe una configuracion para esta estacion minorista.',
                    ]);
                }

                $configuration->update([
                    'metodo_pago_id' => $defaults['method_id'],
                    'cuenta_destino_id' => $defaults['account_id'],
                ]);
            }
        }, 3);

        return $this->configuration($companyId, $branchId, $station);
    }

    public function scaleCode(int $station): string
    {
        return $station === 2 ? self::SCALE_CODE_2 : self::SCALE_CODE;
    }

    private function scaleName(int $station): string
    {
        return $station === 2 ? 'Balanza despacho minorista 2' : 'Balanza despacho minorista';
    }

    /** @return array{method_id: ?int, account_id: ?int} */
    private function paymentDefaults(int $companyId, int $branchId, int $station): array
    {
        $configuration = ConfiguracionDespachoMinorista::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->where('estacion', $station)
            ->first(['metodo_pago_id', 'cuenta_destino_id']);

        if (! $configuration?->metodo_pago_id || ! $configuration?->cuenta_destino_id) {
            return ['method_id' => null, 'account_id' => null];
        }

        $methodIsAvailable = DB::table('metodos_pago')
            ->where('id', $configuration->metodo_pago_id)
            ->where('estado', 'ACTIVO')
            ->exists();
        $accountIsAvailable = $this->validOwnPenAccountQuery(
            $companyId,
            (int) $configuration->cuenta_destino_id
        )->exists();

        if (! $methodIsAvailable || ! $accountIsAvailable) {
            return ['method_id' => null, 'account_id' => null];
        }

        return [
            'method_id' => (int) $configuration->metodo_pago_id,
            'account_id' => (int) $configuration->cuenta_destino_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array{method_id: ?int, account_id: ?int}
     */
    private function validatedPaymentDefaults(int $companyId, array $defaults): array
    {
        $methodId = filled($defaults['method_id'] ?? null)
            ? (int) $defaults['method_id']
            : null;
        $accountId = filled($defaults['account_id'] ?? null)
            ? (int) $defaults['account_id']
            : null;

        if (($methodId === null) !== ($accountId === null)) {
            throw ValidationException::withMessages([
                'payment_defaults' => 'Selecciona tanto el metodo de pago como la cuenta o caja predeterminada.',
            ]);
        }

        if ($methodId === null) {
            return ['method_id' => null, 'account_id' => null];
        }

        $methodIsAvailable = DB::table('metodos_pago')
            ->where('id', $methodId)
            ->where('estado', 'ACTIVO')
            ->lockForUpdate()
            ->first(['id']) !== null;

        if (! $methodIsAvailable) {
            throw ValidationException::withMessages([
                'payment_defaults.method_id' => 'El metodo de pago predeterminado no existe o esta inactivo.',
            ]);
        }

        if ($this->validOwnPenAccountQuery($companyId, (int) $accountId)
            ->lockForUpdate()
            ->first(['cuenta.id']) === null) {
            throw ValidationException::withMessages([
                'payment_defaults.account_id' => 'La cuenta o caja predeterminada debe estar activa, ser propia, pertenecer a la empresa y usar moneda PEN.',
            ]);
        }

        return ['method_id' => $methodId, 'account_id' => $accountId];
    }

    private function validOwnPenAccountQuery(int $companyId, int $accountId): Builder
    {
        return DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('cuenta.id', $accountId)
            ->where('cuenta.estado', CuentaFinanciera::STATUS_ACTIVE)
            ->where('cuenta.moneda', 'PEN')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.tipo', EntidadFinanciera::TYPE_OWN)
            ->where('entidad.estado', EntidadFinanciera::STATUS_ACTIVE);
    }

    /**
     * @param  Collection<int, Tercero>  $clients
     * @param  Collection<int, TipoPollo>  $types
     * @return array<int, array<string, array{price_kg: ?float, source: ?string, history_id: ?int}>>
     */
    public function pricesForClients(int $companyId, Collection $clients, Collection $types): array
    {
        $clientIds = $clients->pluck('id');
        $sourceIds = $types
            ->map(fn (TipoPollo $type): int => $type->priceSourceTypeId())
            ->unique()
            ->values();
        $lists = ListaPrecio::query()
            ->where('empresa_id', $companyId)
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->where(function ($query) use ($clientIds): void {
                $query->whereNull('tercero_id')->orWhereIn('tercero_id', $clientIds);
            })
            ->with(['preciosVigentes' => fn ($query) => $query
                ->whereIn('tipo_pollo_id', $sourceIds)
                ->orderByDesc('vigente_desde')])
            ->orderBy('id')
            ->get();
        $general = $lists->firstWhere('tercero_id', null);
        $specificByClient = $lists->whereNotNull('tercero_id')->groupBy('tercero_id');
        $result = [];

        foreach ($clients as $client) {
            $specific = $specificByClient->get($client->id)?->first();
            $prices = [];

            foreach ($types as $type) {
                $sourceTypeId = $type->priceSourceTypeId();
                $history = $specific?->preciosVigentes->firstWhere('tipo_pollo_id', $sourceTypeId);
                $source = $history ? 'CLIENTE' : null;

                if (! $history) {
                    $history = $general?->preciosVigentes->firstWhere('tipo_pollo_id', $sourceTypeId);
                    $source = $history ? 'GENERAL' : null;
                }

                $prices[$type->codigo] = [
                    'price_kg' => $history
                        ? round((float) $history->precio_kg, 2, PHP_ROUND_HALF_UP)
                        : null,
                    'source' => $source,
                    'history_id' => $history?->id,
                ];
            }

            $result[$client->id] = $prices;
        }

        return $result;
    }

    /**
     * @param  Collection<int, TipoPollo>  $types
     * @return array<string, array{price_kg: ?float, source: ?string, history_id: ?int}>
     */
    public function generalPrices(int $companyId, Collection $types): array
    {
        $sourceIds = $types
            ->map(fn (TipoPollo $type): int => $type->priceSourceTypeId())
            ->unique()
            ->values();
        $general = ListaPrecio::query()
            ->where('empresa_id', $companyId)
            ->whereNull('tercero_id')
            ->where('operacion', ListaPrecio::OPERATION_SALE)
            ->where('estado', ListaPrecio::STATUS_ACTIVE)
            ->with(['preciosVigentes' => fn ($query) => $query
                ->whereIn('tipo_pollo_id', $sourceIds)
                ->orderByDesc('vigente_desde')])
            ->orderBy('id')
            ->first();

        return $types->mapWithKeys(function (TipoPollo $type) use ($general): array {
            $history = $general?->preciosVigentes
                ->firstWhere('tipo_pollo_id', $type->priceSourceTypeId());

            return [$type->codigo => [
                'price_kg' => $history
                    ? round((float) $history->precio_kg, 2, PHP_ROUND_HALF_UP)
                    : null,
                'source' => $history ? 'GENERAL' : null,
                'history_id' => $history?->id,
            ]];
        })->all();
    }
}
