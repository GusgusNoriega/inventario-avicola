<?php

namespace App\Http\Requests\Access;

use Illuminate\Validation\Rules\Password;

class ResetManagedUserPasswordRequest extends AccessManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }
}
