<?php

namespace App\Http\Middleware;

use App\Services\ModuleAvailabilityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsEnabled
{
    public function __construct(
        private readonly ModuleAvailabilityService $availability,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$modules): Response|JsonResponse
    {
        if ($this->availability->anyEnabled($modules)) {
            return $next($request);
        }

        $requiredModules = implode(',', $modules);
        $message = 'Este módulo está desactivado en el servidor.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $message,
                'code' => 'MODULE_DISABLED',
                'required_module' => $requiredModules,
            ], 403);
        }

        return response()->view('errors.403', [
            'message' => $message,
            'requiredModule' => $requiredModules,
        ], 403);
    }
}
