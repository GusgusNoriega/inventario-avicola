<?php

namespace App\Services;

use App\Models\Balanza;
use App\Models\PesadaDespachoProducto;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDispatchOperationService
{
    private const MAX_TOTAL_WEIGHT_KG = 999999999.999;

    private const MAX_TOTAL_AMOUNT = 999999999999.99;

    public function __construct(
        private readonly ScaleReadingService $scaleReadings,
        private readonly ProductDispatchSaleDocumentService $saleDocuments,
    ) {}

    /**
     * @param  object{id: int, empresa_id: int, codigo: string, nombre: string, zona_horaria: string}  $branch
     * @param  array<string, mixed>  $data
     * @return array{ticket: TicketDespachoProducto, already_registered: bool}
     */
    public function register(
        int $companyId,
        object $branch,
        User $actor,
        array $data,
    ): array {
        return DB::transaction(function () use ($companyId, $branch, $actor, $data): array {
            $company = DB::table('empresas')
                ->where('id', $companyId)
                ->lockForUpdate()
                ->first(['id', 'moneda', 'hora_corte_operativo']);

            if (! $company) {
                throw ValidationException::withMessages([
                    'draft_id' => 'La empresa de esta operación ya no se encuentra disponible.',
                ]);
            }

            $lockedBranch = DB::table('sucursales')
                ->where('id', $branch->id)
                ->where('empresa_id', $companyId)
                ->where('estado', 'ACTIVO')
                ->lockForUpdate()
                ->first(['id', 'zona_horaria']);

            if (! $lockedBranch) {
                throw ValidationException::withMessages([
                    'draft_id' => 'La sucursal de esta operación ya no se encuentra disponible.',
                ]);
            }

            $existing = TicketDespachoProducto::query()
                ->where('empresa_id', $companyId)
                ->where('referencia_externa', $data['draft_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->sucursal_id !== (int) $branch->id) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Este identificador ya pertenece a otra sucursal.',
                    ]);
                }

                return [
                    'ticket' => $this->loadTicket($existing),
                    'already_registered' => true,
                ];
            }

            $client = $this->resolveClient($companyId, $data['client_id'] ?? null);
            $weighings = collect($data['weighings'])->values();
            $weighedAt = $this->weighedTimes($weighings, (string) $lockedBranch->zona_horaria);
            $operatingDate = $this->resolveOperatingDate(
                $weighedAt,
                (string) $lockedBranch->zona_horaria,
                (string) ($company->hora_corte_operativo ?: '21:00:00'),
            );
            $products = $this->activeProducts($companyId, $weighings);
            $variations = $this->activeVariations($companyId, $weighings);
            $prepared = $this->prepareWeighings(
                $weighings,
                $weighedAt,
                $products,
                $variations,
            );
            $totals = $this->totals($prepared);
            $now = now();
            $ticket = TicketDespachoProducto::query()->create([
                'empresa_id' => $companyId,
                'sucursal_id' => $branch->id,
                'referencia_externa' => $data['draft_id'],
                'codigo' => $this->nextTicketCode($companyId, $operatingDate),
                'fecha_operativa' => $operatingDate->format('Y-m-d'),
                'cliente_id' => $client?->id,
                'tipo_cliente' => $client
                    ? TicketDespachoProducto::CUSTOMER_REGISTERED
                    : TicketDespachoProducto::CUSTOMER_PUBLIC,
                'cliente_tipo_documento_snapshot' => $client?->tipo_documento,
                'cliente_numero_documento_snapshot' => $client?->numero_documento,
                'cliente_nombre_snapshot' => $client?->nombre_razon_social
                    ?? TicketDespachoProducto::PUBLIC_SALE_LABEL,
                'moneda' => strtoupper((string) ($company->moneda ?: 'PEN')),
                'cantidad_total' => $totals['quantity'],
                'peso_leido_total_kg' => $totals['read_weight_kg'],
                'merma_total_gramos' => $totals['waste_grams'],
                'peso_neto_total_kg' => $totals['net_weight_kg'],
                'subtotal' => $totals['amount'],
                'total' => $totals['amount'],
                'estado' => TicketDespachoProducto::STATUS_REGISTERED,
                'registrado_at' => $now,
                'created_by' => $actor->id,
            ]);

            foreach ($prepared as $index => $weighing) {
                $scaleReading = $this->scaleReadings->record(
                    (int) $branch->id,
                    $actor,
                    $weighing['input'],
                    $weighing['weighed_at'],
                    "weighings.{$index}",
                );

                PesadaDespachoProducto::query()->create([
                    'ticket_despacho_producto_id' => $ticket->id,
                    'numero' => $index + 1,
                    'producto_despacho_id' => $weighing['product']->id,
                    'variacion_producto_despacho_id' => $weighing['variation']?->id,
                    'lectura_balanza_id' => $scaleReading?->id,
                    'producto_nombre_snapshot' => $weighing['product']->nombre,
                    'variacion_nombre_snapshot' => $weighing['variation']?->nombre,
                    'modo_precio_snapshot' => $weighing['price_mode'],
                    'precio_catalogo_snapshot' => $weighing['catalog_price'],
                    'precio_venta_snapshot' => $weighing['unit_price'],
                    'origen_precio' => $weighing['price_origin'],
                    'cantidad' => $weighing['quantity'],
                    'origen_peso' => $weighing['weight_source'],
                    'peso_leido_kg' => $weighing['read_weight_kg'],
                    'merma_catalogo_gramos_unidad' => $weighing['catalog_waste_grams_per_unit'],
                    'merma_total_gramos' => $weighing['waste_total_grams'],
                    'peso_neto_kg' => $weighing['net_weight_kg'],
                    'importe' => $weighing['amount'],
                    'pesada_at' => $weighing['weighed_at'],
                    'created_by' => $actor->id,
                ]);
            }

            $ticket = $this->loadTicket($ticket);
            $this->saleDocuments->create($companyId, $ticket, $actor);
            $this->auditTicket($companyId, $ticket, $actor);

            return [
                'ticket' => $ticket,
                'already_registered' => false,
            ];
        }, 3);
    }

    private function resolveClient(int $companyId, mixed $clientId): ?Tercero
    {
        if (! filled($clientId)) {
            return null;
        }

        $client = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->lockForUpdate()
            ->find((int) $clientId);

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'El cliente seleccionado no está disponible.',
            ]);
        }

        return $client;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, CarbonImmutable>
     */
    private function weighedTimes(Collection $weighings, string $timezone): Collection
    {
        $now = CarbonImmutable::now($timezone);

        return $weighings->map(function (array $weighing, int $index) use ($timezone, $now): CarbonImmutable {
            $time = CarbonImmutable::parse((string) $weighing['weighed_at'], $timezone)
                ->setTimezone($timezone);

            if ($time->greaterThan($now->addMinutes(5))) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weighed_at" => 'La fecha de la pesada no puede estar en el futuro.',
                ]);
            }

            return $time;
        });
    }

    /** @param Collection<int, CarbonImmutable> $weighedAt */
    private function resolveOperatingDate(
        Collection $weighedAt,
        string $timezone,
        string $cutoff,
    ): CarbonImmutable {
        $dates = $weighedAt->map(function (CarbonImmutable $time) use ($cutoff): string {
            $cutoffAt = $time->startOfDay()->setTimeFromTimeString($cutoff);

            return ($time->greaterThanOrEqualTo($cutoffAt) ? $time->addDay() : $time)
                ->format('Y-m-d');
        })->unique()->values();

        if ($dates->count() !== 1) {
            throw ValidationException::withMessages([
                'weighings' => 'Todas las pesadas deben pertenecer a la misma fecha operativa.',
            ]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', (string) $dates->first(), $timezone)
            ->startOfDay();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, ProductoDespacho>
     */
    private function activeProducts(int $companyId, Collection $weighings): Collection
    {
        $ids = $weighings->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $products = ProductoDespacho::query()
            ->where('empresa_id', $companyId)
            ->where('estado', ProductoDespacho::STATUS_ACTIVE)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($weighings as $index => $weighing) {
            if (! $products->has((int) $weighing['product_id'])) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.product_id" => 'El producto seleccionado no está disponible.',
                ]);
            }
        }

        return $products;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return Collection<int, VariacionProductoDespacho>
     */
    private function activeVariations(int $companyId, Collection $weighings): Collection
    {
        $ids = $weighings
            ->pluck('variation_id')
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        $variations = VariacionProductoDespacho::query()
            ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
            ->whereIn('id', $ids)
            ->whereHas('producto', fn ($query) => $query
                ->where('empresa_id', $companyId)
                ->where('estado', ProductoDespacho::STATUS_ACTIVE))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($weighings as $index => $weighing) {
            if (! filled($weighing['variation_id'] ?? null)) {
                continue;
            }

            $variation = $variations->get((int) $weighing['variation_id']);

            if (! $variation || (int) $variation->producto_despacho_id !== (int) $weighing['product_id']) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.variation_id" => 'La variación seleccionada no está disponible para este producto.',
                ]);
            }
        }

        return $variations;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @param  Collection<int, CarbonImmutable>  $weighedAt
     * @param  Collection<int, ProductoDespacho>  $products
     * @param  Collection<int, VariacionProductoDespacho>  $variations
     * @return Collection<int, array<string, mixed>>
     */
    private function prepareWeighings(
        Collection $weighings,
        Collection $weighedAt,
        Collection $products,
        Collection $variations,
    ): Collection {
        return $weighings->map(function (array $input, int $index) use (
            $weighedAt,
            $products,
            $variations,
        ): array {
            /** @var ProductoDespacho $product */
            $product = $products->get((int) $input['product_id']);
            /** @var VariacionProductoDespacho|null $variation */
            $variation = filled($input['variation_id'] ?? null)
                ? $variations->get((int) $input['variation_id'])
                : null;
            $priceMode = (string) ($variation?->modo_precio ?? $product->modo_precio);

            if (! in_array($priceMode, [
                ProductoDespacho::PRICE_MODE_KG,
                ProductoDespacho::PRICE_MODE_UNIT,
            ], true)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.product_id" => 'El producto no tiene un modo de precio válido.',
                ]);
            }

            if ($priceMode !== (string) ($input['price_mode'] ?? '')) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.price_mode" => 'El modo de precio del producto cambió. Actualiza la vista antes de guardar el ticket.',
                ]);
            }

            $weightSource = (string) $input['weight_source'];

            if (! in_array($weightSource, ['MANUAL', Balanza::CODE_PRODUCT_DISPATCH], true)) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.weight_source" => 'La procedencia del peso no corresponde a este despacho.',
                ]);
            }

            $quantity = (int) $input['quantity'];
            $readWeightKg = round((float) $input['read_weight_kg'], 3, PHP_ROUND_HALF_UP);
            $readWeightGrams = (int) round($readWeightKg * 1000, 0, PHP_ROUND_HALF_UP);
            $wasteTotalGrams = (int) $input['waste_total_grams'];
            $netWeightGrams = $readWeightGrams - $wasteTotalGrams;

            if ($netWeightGrams <= 0) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.waste_total_grams" => 'La merma total debe ser menor que el peso leído.',
                ]);
            }

            $netWeightKg = round($netWeightGrams / 1000, 3, PHP_ROUND_HALF_UP);
            $catalogPrice = round(
                (float) ($variation?->precio_venta ?? $product->precio_venta),
                4,
                PHP_ROUND_HALF_UP,
            );
            $unitPrice = round((float) $input['unit_price'], 4, PHP_ROUND_HALF_UP);
            $amountBase = $priceMode === ProductoDespacho::PRICE_MODE_KG
                ? $netWeightKg
                : $quantity;
            $amount = round($amountBase * $unitPrice, 2, PHP_ROUND_HALF_UP);

            if ($amount < 0.01) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.unit_price" => 'El precio y la base de cobro deben producir un importe mínimo de 0.01.',
                ]);
            }

            if ($amount > self::MAX_TOTAL_AMOUNT) {
                throw ValidationException::withMessages([
                    "weighings.{$index}.unit_price" => 'El importe de la pesada excede el máximo permitido.',
                ]);
            }

            return [
                'input' => $input,
                'product' => $product,
                'variation' => $variation,
                'price_mode' => $priceMode,
                'catalog_price' => $catalogPrice,
                'unit_price' => $unitPrice,
                'price_origin' => number_format($catalogPrice, 4, '.', '') === number_format($unitPrice, 4, '.', '')
                    ? PesadaDespachoProducto::PRICE_CATALOG
                    : PesadaDespachoProducto::PRICE_MANUAL,
                'quantity' => $quantity,
                'weight_source' => $weightSource,
                'read_weight_kg' => $readWeightKg,
                'catalog_waste_grams_per_unit' => (int) ($variation?->merma_gramos_unidad
                    ?? $product->merma_gramos_unidad),
                'waste_total_grams' => $wasteTotalGrams,
                'net_weight_kg' => $netWeightKg,
                'amount' => $amount,
                'weighed_at' => $weighedAt->get($index),
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $weighings
     * @return array{quantity: int, read_weight_kg: float, waste_grams: int, net_weight_kg: float, amount: float}
     */
    private function totals(Collection $weighings): array
    {
        $readWeight = round((float) $weighings->sum('read_weight_kg'), 3, PHP_ROUND_HALF_UP);
        $netWeight = round((float) $weighings->sum('net_weight_kg'), 3, PHP_ROUND_HALF_UP);
        $amount = round((float) $weighings->sum('amount'), 2, PHP_ROUND_HALF_UP);

        if ($readWeight > self::MAX_TOTAL_WEIGHT_KG || $netWeight > self::MAX_TOTAL_WEIGHT_KG) {
            throw ValidationException::withMessages([
                'weighings' => 'El peso acumulado del ticket excede el máximo permitido.',
            ]);
        }

        if ($amount > self::MAX_TOTAL_AMOUNT) {
            throw ValidationException::withMessages([
                'weighings' => 'El importe acumulado del ticket excede el máximo permitido.',
            ]);
        }

        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'weighings' => 'El total del ticket debe ser mayor o igual a 0.01.',
            ]);
        }

        return [
            'quantity' => (int) $weighings->sum('quantity'),
            'read_weight_kg' => $readWeight,
            'waste_grams' => (int) $weighings->sum('waste_total_grams'),
            'net_weight_kg' => $netWeight,
            'amount' => $amount,
        ];
    }

    private function nextTicketCode(int $companyId, CarbonImmutable $operatingDate): string
    {
        $prefix = 'PD-'.$operatingDate->format('Ymd').'-';
        $next = TicketDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('codigo', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('codigo')
            ->map(fn (string $code): int => ctype_digit(substr($code, strlen($prefix)))
                ? (int) substr($code, strlen($prefix))
                : 0)
            ->max() + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function loadTicket(TicketDespachoProducto $ticket): TicketDespachoProducto
    {
        return $ticket->load([
            'sucursal',
            'cliente',
            'pesadas.producto',
            'pesadas.variacion',
        ]);
    }

    private function auditTicket(
        int $companyId,
        TicketDespachoProducto $ticket,
        User $actor,
    ): void {
        DB::table('auditoria_eventos')->insert([
            'empresa_id' => $companyId,
            'usuario_id' => $actor->id,
            'entidad' => 'tickets_despacho_productos',
            'entidad_id' => (string) $ticket->id,
            'accion' => 'REGISTRAR',
            'datos_despues' => json_encode([
                'codigo' => $ticket->codigo,
                'referencia_externa' => $ticket->referencia_externa,
                'cliente_id' => $ticket->cliente_id,
                'tipo_cliente' => $ticket->tipo_cliente,
                'total' => $ticket->total,
                'pesadas' => $ticket->pesadas->map(fn (PesadaDespachoProducto $weighing): array => [
                    'numero' => $weighing->numero,
                    'producto_id' => $weighing->producto_despacho_id,
                    'variacion_id' => $weighing->variacion_producto_despacho_id,
                    'precio_catalogo' => $weighing->precio_catalogo_snapshot,
                    'precio_aplicado' => $weighing->precio_venta_snapshot,
                    'origen_precio' => $weighing->origen_precio,
                    'merma_catalogo_total_gramos' => (int) $weighing->merma_catalogo_gramos_unidad
                        * (int) $weighing->cantidad,
                    'merma_aplicada_total_gramos' => $weighing->merma_total_gramos,
                    'origen_peso' => $weighing->origen_peso,
                    'peso_leido_kg' => $weighing->peso_leido_kg,
                    'peso_neto_kg' => $weighing->peso_neto_kg,
                    'importe' => $weighing->importe,
                ])->values()->all(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
