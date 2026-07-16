<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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
        $actor = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'email:rfc',
                'max:180',
                Rule::unique('usuarios', 'email')
                    ->where(fn ($query) => $query->where('empresa_id', $actor->empresa_id))
                    ->ignore($actor->id),
            ],
            'branch_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sucursales', 'id')
                    ->where(fn ($query) => $query->where('empresa_id', $actor->empresa_id)),
            ],
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

        $this->merge($values);
    }
}
