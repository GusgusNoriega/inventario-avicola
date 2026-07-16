<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->isActive();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'different:current_password', 'confirmed', Password::min(8)],
            'revoke_other_sessions' => ['sometimes', 'boolean'],
        ];
    }
}
