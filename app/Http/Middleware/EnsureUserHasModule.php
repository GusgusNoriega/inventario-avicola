<?php

namespace App\Http\Middleware;

use App\Services\ModuleAvailabilityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasModule
{
    public function __construct(
        private readonly ModuleAvailabilityService $availability,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string ...$modules,
    ): Response|JsonResponse|RedirectResponse {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            return $this->denied($request, 'Usuario no autorizado.', 401);
        }

        if ($user->debe_cambiar_password && ! $request->routeIs('account', 'account.*')) {
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

        if (! $this->availability->anyEnabled($modules)) {
            return $this->denied(
                $request,
                'Este módulo está desactivado en el servidor.',
                403,
                implode(',', $modules),
                'MODULE_DISABLED',
            );
        }

        $hasAccess = collect($modules)
            ->contains(fn (string $module): bool => $user->hasModule($module));

        if (! $hasAccess) {
            return $this->denied(
                $request,
                'No tienes acceso a este módulo.',
                403,
                implode(',', $modules),
            );
        }

        return $next($request);
    }

    private function denied(
        Request $request,
        string $message,
        int $status,
        ?string $module = null,
        ?string $code = null,
    ): Response|JsonResponse|RedirectResponse {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(array_filter([
                'message' => $message,
                'code' => $code,
                'required_module' => $module,
            ]), $status);
        }

        if ($status === 401) {
            return redirect()->guest(route('login'));
        }

        return response()->view('errors.403', [
            'message' => $message,
            'requiredModule' => $module,
        ], $status);
    }
}
