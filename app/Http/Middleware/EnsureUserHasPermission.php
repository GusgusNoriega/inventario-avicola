<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response|JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return response()->json([
                'message' => 'Usuario no autorizado.',
            ], 403);
        }

        if (! $user->hasPermission($permission)) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta operación.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
