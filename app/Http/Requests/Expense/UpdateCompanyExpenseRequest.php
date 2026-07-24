<?php

namespace App\Http\Requests\Expense;

class UpdateCompanyExpenseRequest extends StoreCompanyExpenseRequest
{
    public function rules(): array
    {
        return $this->expenseRules();
    }
}
