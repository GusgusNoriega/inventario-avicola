<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateManagedUserRequest extends AccessManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'email:rfc',
                'max:180',
                Rule::unique('usuarios', 'email')
                    ->where(fn ($query) => $query->where('empresa_id', $this->companyId()))
                    ->ignore($this->routeModelId('user')),
            ],
            'branch_id' => ['sometimes', 'nullable', 'integer', $this->branchExistsRule()],
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', $this->roleExistsRule()],
            'status' => ['sometimes', 'string', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
            ])],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(8)],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('name')) {
            $values['name'] = trim((string) $this->input('name'));
        }

        if ($this->exists('email')) {
            $values['email'] = mb_strtolower(trim((string) $this->input('email')), 'UTF-8');
        }

        if ($this->exists('status')) {
            $values['status'] = mb_strtoupper(trim((string) $this->input('status')), 'UTF-8');
        }

        $this->merge($values);
    }
}
