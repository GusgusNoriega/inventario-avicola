<?php

namespace App\Http\Requests\ProductDispatch;

class SaveProductDispatchCustomerAdjustmentRequest extends SaveProductDispatchCustomerPaymentRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'tipo' => ['required', 'in:PRIOR_DEBT,CREDIT'],
            'metodo_pago_id' => ['prohibited'],
            'cuenta_destino_id' => ['prohibited'],
            'referencia' => ['prohibited'],
            'observaciones' => ['nullable', 'string', $this->input('tipo') === 'PRIOR_DEBT' ? 'max:250' : 'max:2000'],
        ];
    }
}
