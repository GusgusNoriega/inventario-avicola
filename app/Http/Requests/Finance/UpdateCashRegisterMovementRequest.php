<?php

namespace App\Http\Requests\Finance;

class UpdateCashRegisterMovementRequest extends StoreCashRegisterMovementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->movementRules();
    }
}
