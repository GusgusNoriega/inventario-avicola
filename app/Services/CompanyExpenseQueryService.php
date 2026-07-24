<?php

namespace App\Services;

use App\Models\GastoEmpresa;
use App\Support\FinancialMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyExpenseQueryService
{
    public function __construct(
        private readonly FinancialQueryService $finances,
        private readonly FinancialAccountBalanceService $balances,
    ) {}

    /** @return array<string, mixed> */
    public function catalog(int $companyId): array
    {
        $catalog = $this->finances->catalog($companyId);
        $entities = collect($catalog['entidades'])
            ->where('tipo', 'PROPIA')
            ->map(function (array $entity): array {
                $entity['cuentas'] = collect($entity['cuentas'])
                    ->map(function (array $account): array {
                        $account['saldo'] = $this->balances->forAccount((int) $account['id'])['saldo'];

                        return $account;
                    })->values()->all();

                return $entity;
            })->values()->all();

        return [
            'entidades' => $entities,
            'metodos_pago' => $catalog['metodos_pago'],
            'categorias' => GastoEmpresa::CATEGORIES,
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function expenses(int $companyId, array $filters): array
    {
        $query = $this->query($companyId, $filters);
        $summaryRows = (clone $query)
            ->where('gasto.estado', GastoEmpresa::STATUS_REGISTERED)
            ->get(['pago.importe']);
        $total = $summaryRows->reduce(
            fn (string $sum, object $row): string => FinancialMoney::add(
                $sum,
                (string) $row->importe,
            ),
            '0.00',
        );

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('pago.fecha_hora')
            ->orderByDesc('gasto.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $expense): array => $this->format($expense))
                ->all(),
            'resumen' => [
                'total_vigente' => $total,
                'cantidad_vigente' => $summaryRows->count(),
                'moneda' => 'PEN',
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function expense(int $companyId, int $expenseId): array
    {
        $expense = $this->query($companyId, [])
            ->where('gasto.id', $expenseId)
            ->first();
        abort_unless($expense, 404, 'Gasto de empresa no encontrado.');

        return $this->format($expense);
    }

    /** @param array<string, mixed> $filters */
    private function query(int $companyId, array $filters): Builder
    {
        return DB::table('gastos_empresa as gasto')
            ->join('pagos as pago', 'pago.id', '=', 'gasto.pago_id')
            ->join('cuentas_financieras as cuenta', 'cuenta.id', '=', 'pago.cuenta_origen_id')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->leftJoin('metodos_pago as metodo', 'metodo.id', '=', 'pago.metodo_pago_id')
            ->leftJoin('usuarios as creador', 'creador.id', '=', 'gasto.created_by')
            ->leftJoin('usuarios as anulador', 'anulador.id', '=', 'gasto.anulada_por')
            ->where('gasto.empresa_id', $companyId)
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query->where('gasto.estado', $status))
            ->when($filters['categoria'] ?? null, fn (Builder $query, string $category) => $query->where('gasto.categoria', $category))
            ->when($filters['desde'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pago.fecha_hora', '>=', $date))
            ->when($filters['hasta'] ?? null, fn (Builder $query, string $date) => $query->whereDate('pago.fecha_hora', '<=', $date))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('gasto.codigo', 'like', "%{$search}%")
                        ->orWhere('gasto.concepto', 'like', "%{$search}%")
                        ->orWhere('gasto.destino', 'like', "%{$search}%")
                        ->orWhere('gasto.numero_documento', 'like', "%{$search}%")
                        ->orWhere('pago.referencia', 'like', "%{$search}%");
                });
            })
            ->select([
                'gasto.*',
                'pago.fecha_hora',
                'pago.moneda',
                'pago.importe',
                'pago.referencia',
                'pago.observaciones',
                'pago.cuenta_origen_id',
                'pago.metodo_pago_id',
                'pago.codigo as movimiento_codigo',
                'cuenta.alias as cuenta_alias',
                'cuenta.tipo as cuenta_tipo',
                'entidad.razon_social as entidad_nombre',
                'metodo.codigo as metodo_codigo',
                'metodo.nombre as metodo_nombre',
                'creador.nombre as creador_nombre',
                'anulador.nombre as anulador_nombre',
            ]);
    }

    /** @return array<string, mixed> */
    private function format(object $expense): array
    {
        return [
            'id' => (int) $expense->id,
            'codigo' => $expense->codigo,
            'movimiento_codigo' => $expense->movimiento_codigo,
            'fecha_hora' => $expense->fecha_hora,
            'categoria' => $expense->categoria,
            'concepto' => $expense->concepto,
            'destino' => $expense->destino,
            'numero_documento' => $expense->numero_documento,
            'moneda' => $expense->moneda,
            'importe' => FinancialMoney::normalize((string) $expense->importe),
            'referencia' => $expense->referencia,
            'observaciones' => $expense->observaciones,
            'estado' => $expense->estado,
            'cuenta' => [
                'id' => (int) $expense->cuenta_origen_id,
                'alias' => $expense->cuenta_alias,
                'tipo' => $expense->cuenta_tipo,
                'entidad' => $expense->entidad_nombre,
            ],
            'metodo_pago' => [
                'id' => (int) $expense->metodo_pago_id,
                'codigo' => $expense->metodo_codigo,
                'nombre' => $expense->metodo_nombre,
            ],
            'creado_por' => $expense->creador_nombre,
            'anulada_por' => $expense->anulador_nombre,
            'anulada_at' => $expense->anulada_at,
            'motivo_anulacion' => $expense->motivo_anulacion,
            'puede_editar' => $expense->estado === GastoEmpresa::STATUS_REGISTERED,
            'puede_anular' => $expense->estado === GastoEmpresa::STATUS_REGISTERED,
        ];
    }
}
