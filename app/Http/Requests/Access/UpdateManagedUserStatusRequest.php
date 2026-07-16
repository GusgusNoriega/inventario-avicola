<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Validation\Rule;

class UpdateManagedUserStatusRequest extends AccessManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('status')) {
            $this->merge([
                'status' => mb_strtoupper(trim((string) $this->input('status')), 'UTF-8'),
            ]);
        }
    }
}
