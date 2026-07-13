<?php

namespace App\Services;

use App\Models\AjustePesoMinorista;
use App\Models\ListaPrecio;
use App\Models\Tercero;
use App\Models\TipoPollo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailConfigurationService
{
    public const SCALE_CODE = 'BALANZA_MINORISTA';

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

    public function ensureDefaults(int $companyId, int $branchId): void
    {
        $now = now();
        $rows = collect(self::adjustmentDefinitions())->map(fn (array $definition): array => [
            'empresa_id' => $companyId,
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
            ['empresa_id', 'codigo'],
            ['nombre', 'sexo', 'presentacion', 'updated_at']
        );

        $hasDefault = AjustePesoMinorista::query()
            ->where('empresa_id', $companyId)
            ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
            ->where('predeterminado', true)
            ->exists();

        if (! $hasDefault) {
            AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('codigo', AjustePesoMinorista::MALE_CLOSED)
                ->update([
                    'predeterminado' => true,
                    'estado' => AjustePesoMinorista::STATUS_ACTIVE,
                ]);
        }

        DB::table('balanzas')->insertOrIgnore([
            'sucursal_id' => $branchId,
            'codigo' => self::SCALE_CODE,
            'nombre' => 'Balanza despacho minorista',
            'modo_conexion' => 'SERIAL',
            'dispositivo' => null,
            'configuracion' => json_encode(self::defaultSerialConfiguration(), JSON_THROW_ON_ERROR),
            'estado' => 'ACTIVO',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{
     *     scale: array{code: string, name: string, connection_mode: ?string, device: ?string, configuration: array<string, mixed>},
     *     adjustments: Collection<int, array<string, mixed>>
     * }
     */
    public function configuration(int $companyId, int $branchId): array
    {
        $this->ensureDefaults($companyId, $branchId);

        $scale = DB::table('balanzas')
            ->where('sucursal_id', $branchId)
            ->where('codigo', self::SCALE_CODE)
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
                'code' => self::SCALE_CODE,
                'name' => $scale?->nombre ?? 'Balanza despacho minorista',
                'connection_mode' => $scale?->modo_conexion ?? 'SERIAL',
                'device' => $scale?->dispositivo,
                'configuration' => $serialConfiguration,
            ],
            'adjustments' => AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{scale: array<string, mixed>, adjustments: Collection<int, array<string, mixed>>}
     */
    public function update(int $companyId, int $branchId, array $data): array
    {
        $this->ensureDefaults($companyId, $branchId);

        DB::transaction(function () use ($companyId, $branchId, $data): void {
            $adjustments = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
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
                ->update(['predeterminado' => false]);
            $updated = AjustePesoMinorista::query()
                ->where('empresa_id', $companyId)
                ->where('estado', AjustePesoMinorista::STATUS_ACTIVE)
                ->where('codigo', $data['default_adjustment_code'])
                ->update(['predeterminado' => true]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'default_adjustment_code' => 'El ajuste predeterminado no esta disponible para la empresa.',
                ]);
            }

            DB::table('balanzas')
                ->where('sucursal_id', $branchId)
                ->where('codigo', self::SCALE_CODE)
                ->update([
                    'modo_conexion' => $data['scale']['connection_mode'],
                    'dispositivo' => $data['scale']['device'],
                    'configuracion' => json_encode($data['scale']['configuration'], JSON_THROW_ON_ERROR),
                    'estado' => 'ACTIVO',
                    'updated_at' => now(),
                ]);
        }, 3);

        return $this->configuration($companyId, $branchId);
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
                    'price_kg' => $history ? (float) $history->precio_kg : null,
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
                'price_kg' => $history ? (float) $history->precio_kg : null,
                'source' => $history ? 'GENERAL' : null,
                'history_id' => $history?->id,
            ]];
        })->all();
    }
}
