<?php

namespace App\Http\Requests\Finance;

use App\Support\FinancialMoney;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

abstract class FinancialFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function companyId(): int
    {
        return (int) $this->user()->empresa_id;
    }

    protected function normalizedMoney(string $key): mixed
    {
        if (! $this->exists($key) || $this->input($key) === null || $this->input($key) === '') {
            return $this->input($key);
        }

        try {
            return FinancialMoney::normalize($this->input($key));
        } catch (InvalidArgumentException) {
            return $this->input($key);
        }
    }

    protected function trimmedNullable(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
