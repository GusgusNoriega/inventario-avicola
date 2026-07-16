<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response|JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return redirect()->guest(route('login'));
            }

            return response()->json([
                'message' => 'Usuario no autorizado.',
            ], 403);
        }

        if ($user->debe_cambiar_password) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return redirect()
                    ->route('account')
                    ->with('warning', 'Debes cambiar tu contraseña antes de continuar.');
            }

            return response()->json([
                'message' => 'Debes cambiar tu contraseña antes de continuar.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 409);
        }

        if (! $user->hasPermission($permission)) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return response()->view('errors.403', [
                    'message' => 'No tienes permiso para acceder a esta operación.',
                    'requiredPermission' => $permission,
                ], 403);
            }

            return response()->json([
                'message' => 'No tienes permiso para realizar esta operación.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
