<?php

namespace App\Http\Requests\Finance;

class RestoreFinancialTicketRequest extends FinancialFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
