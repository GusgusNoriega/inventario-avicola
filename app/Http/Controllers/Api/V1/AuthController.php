<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $email = Str::lower($credentials['email']);
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Las credenciales son incorrectas.',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'El usuario está inactivo.',
            ], 403);
        }

        $deviceName = trim($credentials['device_name'] ?? '') ?: 'frontend-web';
        $user->tokens()->where('name', $deviceName)->delete();

        $expiresAt = now()->addMinutes(config('auth_token.expiration_minutes'));
        $token = $user->createToken($deviceName, ['api'], $expiresAt);

        $user->forceFill([
            'ultimo_acceso_at' => now(),
            'ultimo_acceso_ip' => $request->ip(),
        ])->save();

        return response()->json([
            'message' => 'Inicio de sesión correcto.',
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Todas las sesiones fueron cerradas.',
        ]);
    }
}
