<?php

namespace App\Http\Requests\Access;

use Illuminate\Validation\Rule;

class StoreManagedRoleRequest extends AccessManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z][A-Z0-9_]*$/',
                Rule::unique('roles', 'codigo')
                    ->where(fn ($query) => $query->where('empresa_id', $this->companyId())),
            ],
            'name' => ['required', 'string', 'max:100'],
            'module_codes' => ['required', 'array'],
            'module_codes.*' => ['string', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('code')) {
            $values['code'] = mb_strtoupper(trim((string) $this->input('code')), 'UTF-8');
        }

        if ($this->exists('name')) {
            $values['name'] = trim((string) $this->input('name'));
        }

        if ($this->exists('module_codes') && is_array($this->input('module_codes'))) {
            $values['module_codes'] = collect($this->input('module_codes'))
                ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
                ->all();
        }

        $this->merge($values);
    }
}
