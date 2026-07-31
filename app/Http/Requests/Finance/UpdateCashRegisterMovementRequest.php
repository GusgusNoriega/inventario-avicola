<?php

namespace App\Http\Requests\Finance;

use App\Models\MovimientoCajaEfectivo;

class UpdateCashRegisterMovementRequest extends StoreCashRegisterMovementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->movementRules();
    }

    protected function allowsLegacyExpenseOther(): bool
    {
        $cashMovementId = (int) $this->route('movimientoCaja');
        if ($cashMovementId <= 0) {
            return false;
        }

        return MovimientoCajaEfectivo::query()
            ->where('empresa_id', $this->companyId())
            ->whereKey($cashMovementId)
            ->where('direccion', MovimientoCajaEfectivo::DIRECTION_EXPENSE)
            ->where('contraparte_tipo', MovimientoCajaEfectivo::COUNTERPART_OTHER)
            ->exists();
    }
}
