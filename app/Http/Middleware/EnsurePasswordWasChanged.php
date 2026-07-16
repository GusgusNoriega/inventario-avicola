<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordWasChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|JsonResponse|RedirectResponse
    {
        if (! $request->user()?->debe_cambiar_password) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña antes de continuar.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 409);
        }

        return redirect()
            ->route('account')
            ->with('warning', 'Debes cambiar tu contraseña antes de continuar.');
    }
}
