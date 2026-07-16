<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\ChangeAccountPasswordRequest;
use App\Http\Requests\Access\UpdateAccountRequest;
use App\Http\Resources\Access\AccessUserResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
    ) {}

    public function show(Request $request): AccessUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AccessUserResource($user->load('roles.permissions:id,codigo'));
    }

    public function update(UpdateAccountRequest $request): AccessUserResource
    {
        return (new AccessUserResource($this->accounts->update(
            $request->user(),
            $request->validated(),
            $request->ip()
        )))->additional(['message' => 'Datos de la cuenta actualizados correctamente.']);
    }

    public function password(ChangeAccountPasswordRequest $request): AccessUserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $currentToken = $actor->currentAccessToken();
        $tokenId = $currentToken instanceof PersonalAccessToken
            ? (int) $currentToken->id
            : null;
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        return (new AccessUserResource($this->accounts->changePassword(
            $actor,
            $request->validated(),
            $tokenId,
            $sessionId,
            $request->ip()
        )))->additional(['message' => 'Contrasena actualizada correctamente.']);
    }
}
