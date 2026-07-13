<?php

namespace App\Services;

use App\Support\FinancialMoney;
use Illuminate\Support\Facades\DB;

class FinancialAccountBalanceService
{
    /** @return array{entradas: string, salidas: string, saldo: string} */
    public function forAccount(int $accountId): array
    {
        $totals = DB::table('pagos')
            ->where(function ($query) use ($accountId): void {
                $query->where('cuenta_destino_id', $accountId)
                    ->orWhere('cuenta_origen_id', $accountId);
            })
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cuenta_destino_id = ? THEN importe ELSE 0 END), 0) as entradas',
                [$accountId]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cuenta_origen_id = ? THEN importe ELSE 0 END), 0) as salidas',
                [$accountId]
            )
            ->first();

        $entries = FinancialMoney::normalize((string) ($totals->entradas ?? 0));
        $exits = FinancialMoney::normalize((string) ($totals->salidas ?? 0));

        return [
            'entradas' => $entries,
            'salidas' => $exits,
            'saldo' => FinancialMoney::subtract($entries, $exits),
        ];
    }
}
