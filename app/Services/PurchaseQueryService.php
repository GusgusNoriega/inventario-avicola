<?php

namespace App\Services;

use App\Support\FinancialMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseQueryService
{
    /** @return array<string, mixed> */
    public function catalog(int $companyId, bool $includeAccounts = false): array
    {
        $providers = DB::table('terceros as tercero')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
            ->where('tercero.empresa_id', $companyId)
            ->where('tercero.estado', 'ACTIVO')
            ->where('rol.rol', 'PROVEEDOR')
            ->orderBy('tercero.nombre_razon_social')
            ->get(['tercero.id', 'tercero.numero_documento', 'tercero.nombre_razon_social'])
            ->map(fn (object $provider): array => [
                'id' => (int) $provider->id,
                'numero_documento' => $provider->numero_documento,
                'nombre' => $provider->nombre_razon_social,
            ])->all();
        $accounts = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('entidad.estado', 'ACTIVO')
            ->where('cuenta.estado', 'ACTIVO')
            ->orderBy('entidad.razon_social')
            ->orderBy('cuenta.alias')
            ->get([
                'cuenta.id', 'cuenta.alias', 'cuenta.tipo', 'cuenta.banco', 'cuenta.numero_cuenta',
                'cuenta.moneda', 'entidad.id as entidad_id', 'entidad.tipo as entidad_tipo',
                'entidad.proveedor_id', 'entidad.razon_social as entidad_nombre',
            ]);

        return [
            'proveedores' => $providers,
            'tipos_pollo' => DB::table('tipos_pollo')
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre'])
                ->map(fn (object $type): array => [
                    'id' => (int) $type->id,
                    'codigo' => $type->codigo,
                    'nombre' => $type->nombre,
                ])->all(),
            'metodos_pago' => DB::table('metodos_pago')
                ->where('estado', 'ACTIVO')
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre', 'requiere_referencia'])
                ->map(fn (object $method): array => [
                    'id' => (int) $method->id,
                    'codigo' => $method->codigo,
                    'nombre' => $method->nombre,
                    'requiere_referencia' => (bool) $method->requiere_referencia,
                ])->all(),
            'cuentas_propias' => ! $includeAccounts ? [] : $accounts
                ->where('entidad_tipo', 'PROPIA')
                ->map(fn (object $account): array => $this->formatCatalogAccount($account))
                ->values()->all(),
            'cuentas_proveedores' => ! $includeAccounts ? [] : $accounts
                ->where('entidad_tipo', 'EXTERNA')
                ->map(fn (object $account): array => $this->formatCatalogAccount($account))
                ->values()->all(),
            'condiciones' => ['CONTADO', 'CREDITO', 'LEGADO'],
            'moneda' => $this->companyCurrency($companyId),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function purchases(int $companyId, array $filters): array
    {
        $filters['moneda'] ??= $this->companyCurrency($companyId);
        $query = $this->baseQuery($companyId, $filters);
        $summaryRows = (clone $query)->get([
            'compra.condicion',
            'compra.total',
            'comprobante.saldo_pendiente as comprobante_saldo_pendiente',
            'comprobante.estado as comprobante_estado',
        ]);
        $summary = [
            'moneda' => $filters['moneda'] ?? $this->companyCurrency($companyId),
            'total' => '0.00',
            'contado' => '0.00',
            'credito' => '0.00',
            'sin_clasificar' => '0.00',
            'pendiente' => '0.00',
        ];
        foreach ($summaryRows as $row) {
            if ($row->comprobante_estado === 'ANULADO') {
                continue;
            }
            $amount = FinancialMoney::normalize((string) $row->total);
            $summary['total'] = FinancialMoney::add($summary['total'], $amount);
            $conditionKey = match ($row->condicion) {
                'CONTADO' => 'contado',
                'CREDITO' => 'credito',
                default => 'sin_clasificar',
            };
            $summary[$conditionKey] = FinancialMoney::add($summary[$conditionKey], $amount);
            $summary['pendiente'] = FinancialMoney::add(
                $summary['pendiente'],
                (string) $row->comprobante_saldo_pendiente
            );
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('compra.fecha_compra')
            ->orderByDesc('compra.id')
            ->paginate((int) ($filters['per_page'] ?? 30));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $purchase): array => $this->formatPurchase($purchase))
                ->all(),
            'resumen' => $summary,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function purchase(int $companyId, int $purchaseId): array
    {
        $purchase = $this->baseQuery($companyId, [])
            ->where('compra.id', $purchaseId)
            ->first();
        abort_unless($purchase, 404, 'Compra no encontrada.');

        $details = DB::table('compra_detalles as detalle')
            ->leftJoin('tipos_pollo as tipo', 'tipo.id', '=', 'detalle.tipo_pollo_id')
            ->where('detalle.compra_id', $purchaseId)
            ->orderBy('detalle.id')
            ->get([
                'detalle.*',
                'tipo.codigo as tipo_pollo_codigo',
                'tipo.nombre as tipo_pollo_nombre',
            ])
            ->map(fn (object $detail): array => [
                'id' => (int) $detail->id,
                'tipo_pollo' => $detail->tipo_pollo_id === null ? null : [
                    'id' => (int) $detail->tipo_pollo_id,
                    'codigo' => $detail->tipo_pollo_codigo,
                    'nombre' => $detail->tipo_pollo_nombre,
                ],
                'descripcion' => $detail->descripcion,
                'cantidad_aves' => $detail->cantidad_aves === null ? null : (int) $detail->cantidad_aves,
                'peso_kg' => bcadd((string) $detail->peso_kg, '0', 3),
                'precio_kg' => bcadd((string) $detail->precio_kg, '0', 4),
                'subtotal' => FinancialMoney::normalize((string) $detail->subtotal),
            ])->all();

        return [...$this->formatPurchase($purchase), 'detalles' => $details];
    }

    /** @param array<string, mixed> $filters */
    private function baseQuery(int $companyId, array $filters): Builder
    {
        return DB::table('compras as compra')
            ->join('terceros as proveedor', 'proveedor.id', '=', 'compra.proveedor_id')
            ->join('comprobantes as comprobante', 'comprobante.id', '=', 'compra.comprobante_id')
            ->leftJoin('pagos as pago_inicial', 'pago_inicial.id', '=', 'compra.pago_inicial_id')
            ->where('compra.empresa_id', $companyId)
            ->when($filters['proveedor_id'] ?? null, fn (Builder $query, int|string $id) => $query
                ->where('compra.proveedor_id', $id))
            ->when($filters['condicion'] ?? null, fn (Builder $query, string $condition) => $query
                ->where('compra.condicion', $condition))
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query
                ->where('comprobante.estado', $status))
            ->when($filters['moneda'] ?? null, fn (Builder $query, string $currency) => $query
                ->where('compra.moneda', $currency))
            ->when($filters['desde'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('compra.fecha_compra', '>=', $date))
            ->when($filters['hasta'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('compra.fecha_compra', '<=', $date))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('compra.codigo', 'like', "%{$search}%")
                        ->orWhere('compra.numero_documento', 'like', "%{$search}%")
                        ->orWhere('comprobante.codigo', 'like', "%{$search}%")
                        ->orWhere('comprobante.contraparte_nombre_snapshot', 'like', "%{$search}%")
                        ->orWhere('comprobante.contraparte_numero_documento_snapshot', 'like', "%{$search}%")
                        ->orWhere('proveedor.nombre_razon_social', 'like', "%{$search}%")
                        ->orWhere('proveedor.numero_documento', 'like', "%{$search}%");
                });
            })
            ->select([
                'compra.*',
                'proveedor.tipo_documento as proveedor_tipo_documento',
                'proveedor.numero_documento as proveedor_numero_documento',
                'proveedor.nombre_razon_social as proveedor_nombre',
                'comprobante.codigo as comprobante_codigo',
                'comprobante.estado as comprobante_estado',
                'comprobante.saldo_pendiente as comprobante_saldo_pendiente',
                'comprobante.contraparte_tipo_documento_snapshot',
                'comprobante.contraparte_numero_documento_snapshot',
                'comprobante.contraparte_nombre_snapshot',
                'pago_inicial.codigo as pago_inicial_codigo',
                'pago_inicial.estado as pago_inicial_estado',
                'pago_inicial.importe as pago_inicial_importe',
            ]);
    }

    /** @return array<string, mixed> */
    private function formatPurchase(object $purchase): array
    {
        $effectivePending = $purchase->comprobante_estado === 'ANULADO'
            ? '0.00'
            : FinancialMoney::normalize((string) $purchase->comprobante_saldo_pendiente);

        return [
            'id' => (int) $purchase->id,
            'codigo' => $purchase->codigo,
            'idempotency_key' => $purchase->idempotency_key,
            'proveedor' => [
                'id' => (int) $purchase->proveedor_id,
                'tipo_documento' => $purchase->contraparte_tipo_documento_snapshot
                    ?: $purchase->proveedor_tipo_documento,
                'numero_documento' => $purchase->contraparte_numero_documento_snapshot
                    ?: $purchase->proveedor_numero_documento,
                'nombre' => $purchase->contraparte_nombre_snapshot ?: $purchase->proveedor_nombre,
            ],
            'tipo_documento' => $purchase->tipo_documento,
            'numero_documento' => $purchase->numero_documento,
            'fecha_compra' => $purchase->fecha_compra,
            'fecha_vencimiento' => $purchase->fecha_vencimiento,
            'condicion' => $purchase->condicion,
            'moneda' => $purchase->moneda,
            'subtotal' => FinancialMoney::normalize((string) $purchase->subtotal),
            'impuesto' => FinancialMoney::normalize((string) $purchase->impuesto),
            'total' => FinancialMoney::normalize((string) $purchase->total),
            'saldo_pendiente' => $effectivePending,
            'estado' => $purchase->comprobante_estado,
            'estado_compra' => $purchase->estado,
            'observaciones' => $purchase->observaciones,
            'comprobante' => [
                'id' => (int) $purchase->comprobante_id,
                'codigo' => $purchase->comprobante_codigo,
                'estado' => $purchase->comprobante_estado,
                'saldo_pendiente' => $effectivePending,
            ],
            'pago_inicial' => $purchase->pago_inicial_id === null ? null : [
                'id' => (int) $purchase->pago_inicial_id,
                'codigo' => $purchase->pago_inicial_codigo,
                'estado' => $purchase->pago_inicial_estado,
                'importe' => FinancialMoney::normalize((string) $purchase->pago_inicial_importe),
            ],
            'created_by' => (int) $purchase->created_by,
            'created_at' => $purchase->created_at,
            'anulada_por' => $purchase->anulada_por === null ? null : (int) $purchase->anulada_por,
            'anulada_at' => $purchase->anulada_at,
            'motivo_anulacion' => $purchase->motivo_anulacion,
        ];
    }

    /** @return array<string, mixed> */
    private function formatCatalogAccount(object $account): array
    {
        return [
            'id' => (int) $account->id,
            'alias' => $account->alias,
            'tipo' => $account->tipo,
            'banco' => $account->banco,
            'numero_cuenta' => $account->numero_cuenta,
            'moneda' => $account->moneda,
            'proveedor_id' => $account->proveedor_id === null ? null : (int) $account->proveedor_id,
            'entidad' => [
                'id' => (int) $account->entidad_id,
                'tipo' => $account->entidad_tipo,
                'nombre' => $account->entidad_nombre,
            ],
        ];
    }

    private function companyCurrency(int $companyId): string
    {
        return (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN');
    }
}
