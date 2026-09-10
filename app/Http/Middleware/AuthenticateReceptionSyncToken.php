<?php

namespace App\Http\Middleware;

use App\Models\ReceptionSyncToken;
use App\Services\ReceptionSyncTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateReceptionSyncToken
{
    public function __construct(private readonly ReceptionSyncTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        // This credential is deliberately separate from Sanctum and session authentication.
        if (! preg_match('/^Bearer (rpv_[a-f0-9]{64})$/iD', (string) $request->header('Authorization'), $matches)) {
            return $this->denied();
        }

        $token = ReceptionSyncToken::query()
            ->with(['user.empresa', 'sucursal'])
            ->where('token_hash', hash('sha256', $matches[1]))
            ->first();

        if (! $token || ! $token->isUsable()) {
            return $this->denied();
        }

        $user = $token->user;
        $branch = $token->sucursal;
        if (! $user || ! $branch
            || (int) $token->empresa_id !== (int) $user->empresa_id
            || ! $this->tokens->actorCanAccessBranch($user, $branch)
            || ! hash_equals($token->password_fingerprint, $this->tokens->passwordFingerprint($user))) {
            return $this->denied(403, 'SYNC_ACCESS_DENIED', 'El dispositivo perdió acceso. Revisa el usuario, la sucursal y los permisos o genera un nuevo token.');
        }

        // A company-wide administrator still operates only inside this token's branch.
        $actor = clone $user;
        $actor->sucursal_id = $branch->id;
        $actor->setRelation('sucursal', $branch);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('reception_sync_token', $token);
        $request->attributes->set('reception_sync_branch', $branch);

        $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function denied(
        int $status = 401,
        string $code = 'SYNC_UNAUTHENTICATED',
        string $message = 'Token de sincronización inválido, vencido o revocado.',
    ): JsonResponse {
        $response = response()->json(['message' => $message, 'code' => $code], $status)
            ->header('Cache-Control', 'private, no-store');
        if ($status === 401) {
            $response->header('WWW-Authenticate', 'Bearer');
        }

        return $response;
    }
}
