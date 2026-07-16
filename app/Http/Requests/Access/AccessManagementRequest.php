<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use App\Services\AccessModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class AccessManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->isActive()
            && ($actor->hasPermission(AccessModuleRegistry::MANAGEMENT_MODULE_CODE)
                || $actor->hasPermission('USUARIOS_GESTIONAR'));
    }

    protected function companyId(): int
    {
        return (int) $this->user()->empresa_id;
    }

    protected function branchExistsRule(): Exists
    {
        return Rule::exists('sucursales', 'id')
            ->where(fn ($query) => $query->where('empresa_id', $this->companyId()));
    }

    protected function roleExistsRule(): Exists
    {
        return Rule::exists('roles', 'id')
            ->where(fn ($query) => $query->where('empresa_id', $this->companyId()));
    }

    protected function routeModelId(string $parameter): ?int
    {
        $value = $this->route($parameter);

        if (is_object($value) && isset($value->id)) {
            return (int) $value->id;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
