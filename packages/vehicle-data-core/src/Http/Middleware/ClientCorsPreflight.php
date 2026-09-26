<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a CORS preflight (`OPTIONS /v1/*`) before the request ever reaches
 * the router, and is therefore registered as GLOBAL middleware (see
 * bootstrap/app.php) rather than as an actual `Route::options('v1/{any}', …)`
 * route.
 *
 * A real wildcard route was tried first,
 * but it broke plain 404s: Laravel's router treats any URI that matches
 * *some* route - regardless of method - as a 405 (Method Not Allowed)
 * instead of a 404 (Not Found). Since the wildcard OPTIONS route matched
 * every `/v1/*` path, every unmatched GET/POST/etc. request under `/v1`
 * started returning 405 instead of 404 (see ProblemTest). Short-circuiting
 * here, before the router ever sees the request, keeps 404s intact for
 * every other verb while still answering the preflight.
 */
final class ClientCorsPreflight
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') && $request->is('v1/*')) {
            return ClientCors::preflight($request);
        }

        return $next($request);
    }
}
