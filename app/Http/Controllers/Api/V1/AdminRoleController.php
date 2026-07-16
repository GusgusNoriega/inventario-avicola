<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Access\StoreManagedRoleRequest;
use App\Http\Requests\Access\UpdateManagedRoleRequest;
use App\Http\Resources\Access\AccessRoleResource;
use App\Models\Role;
use App\Services\RoleAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminRoleController extends AccessManagementController
{
    public function __construct(
        private readonly RoleAdministrationService $roles,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $roles = Role::query()
            ->where('empresa_id', $actor->empresa_id)
            ->with('permissions:id,codigo')
            ->withCount('users')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested
                    ->where('nombre', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%");
            }))
            ->orderByRaw("CASE WHEN codigo = 'ADMINISTRADOR' THEN 0 ELSE 1 END")
            ->orderBy('nombre')
            ->paginate((int) ($validated['per_page'] ?? 50))
            ->withQueryString();

        return AccessRoleResource::collection($roles);
    }

    public function show(Request $request, int $role): AccessRoleResource
    {
        $actor = $this->actor($request);

        return new AccessRoleResource(
            $this->roles->findForCompany((int) $actor->empresa_id, $role)
        );
    }

    public function store(StoreManagedRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create(
            $request->user(),
            $request->validated(),
            $request->ip()
        );

        return (new AccessRoleResource($role))
            ->additional(['message' => 'Rol creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateManagedRoleRequest $request, int $role): AccessRoleResource
    {
        return (new AccessRoleResource($this->roles->update(
            $request->user(),
            $role,
            $request->validated(),
            $request->ip()
        )))->additional(['message' => 'Rol actualizado correctamente.']);
    }

    public function destroy(Request $request, int $role): JsonResponse
    {
        $actor = $this->actor($request);
        $this->roles->destroy($actor, $role, $request->ip());

        return response()->json([
            'message' => 'Rol eliminado correctamente.',
        ]);
    }
}
