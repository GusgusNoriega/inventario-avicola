<?php

namespace App\Http\Requests\ProductDispatch;

class GetProductDispatchCustomerAccountRequest extends ListProductDispatchCustomerPaymentsRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cliente_id' => ['required', 'integer', 'min:1'],
            'moneda' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
        ];
    }
}
