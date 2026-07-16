<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Access\ResetManagedUserPasswordRequest;
use App\Http\Requests\Access\StoreManagedUserRequest;
use App\Http\Requests\Access\UpdateManagedUserRequest;
use App\Http\Requests\Access\UpdateManagedUserStatusRequest;
use App\Http\Resources\Access\AccessUserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminUserController extends AccessManagementController
{
    public function __construct(
        private readonly UserAdministrationService $users,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_INACTIVE,
            ])],
            'role_id' => ['nullable', 'integer'],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('sucursales', 'id')
                    ->where(fn ($query) => $query->where('empresa_id', $actor->empresa_id)),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->where('empresa_id', $actor->empresa_id)
            ->with('roles.permissions:id,codigo')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested
                    ->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('estado', $validated['status'])
            )
            ->when(
                isset($validated['role_id']),
                fn ($query) => $query->whereHas('roles', fn ($roles) => $roles
                    ->where('roles.empresa_id', $actor->empresa_id)
                    ->where('roles.id', $validated['role_id']))
            )
            ->when(
                isset($validated['branch_id']),
                fn ($query) => $query->where('sucursal_id', $validated['branch_id'])
            )
            ->orderBy('nombre')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return AccessUserResource::collection($users);
    }

    public function show(Request $request, int $user): AccessUserResource
    {
        $actor = $this->actor($request);

        return new AccessUserResource(
            $this->users->findForCompany((int) $actor->empresa_id, $user)
        );
    }

    public function store(StoreManagedUserRequest $request): JsonResponse
    {
        $user = $this->users->create(
            $request->user(),
            $request->validated(),
            $request->ip()
        );

        return (new AccessUserResource($user))
            ->additional(['message' => 'Usuario creado correctamente.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateManagedUserRequest $request, int $user): AccessUserResource
    {
        return (new AccessUserResource($this->users->update(
            $request->user(),
            $user,
            $request->validated(),
            $request->ip()
        )))->additional(['message' => 'Usuario actualizado correctamente.']);
    }

    public function status(
        UpdateManagedUserStatusRequest $request,
        int $user
    ): AccessUserResource {
        return (new AccessUserResource($this->users->changeStatus(
            $request->user(),
            $user,
            $request->validated('status'),
            $request->ip()
        )))->additional(['message' => 'Estado del usuario actualizado correctamente.']);
    }

    public function resetPassword(
        ResetManagedUserPasswordRequest $request,
        int $user
    ): AccessUserResource {
        return (new AccessUserResource($this->users->resetPassword(
            $request->user(),
            $user,
            $request->validated(),
            $request->ip()
        )))->additional(['message' => 'Contrasena restablecida y sesiones revocadas.']);
    }

    public function revokeSessions(Request $request, int $user): AccessUserResource
    {
        $actor = $this->actor($request);

        return (new AccessUserResource($this->users->revokeSessions(
            $actor,
            $user,
            $request->ip()
        )))->additional(['message' => 'Sesiones revocadas correctamente.']);
    }
}
