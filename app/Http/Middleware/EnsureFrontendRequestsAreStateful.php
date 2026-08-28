<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumMiddleware;

class EnsureFrontendRequestsAreStateful extends SanctumMiddleware
{
    /**
     * Preserve Sanctum's configured origins and recover safe same-origin
     * browser reads when a privacy policy omits Referer and Origin.
     */
    public static function fromFrontend($request): bool
    {
        if (parent::fromFrontend($request)) {
            return true;
        }

        return ! $request->headers->has('referer')
            && ! $request->headers->has('origin')
            && in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && strtolower((string) $request->headers->get('sec-fetch-site')) === 'same-origin';
    }
}
