<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Http\Middleware\Concerns\AppliesToCorePaths;

/**
 * Registered as GLOBAL middleware (see bootstrap/app.php) so the request id
 * attribute is available even when routing itself fails (404 for an
 * unmatched route, 405 for a wrong verb) - those never reach route-group
 * middleware because the exception is thrown while the router is still
 * matching, before the matched route's own middleware pipeline starts.
 */
final class RequestId
{
    use AppliesToCorePaths;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->appliesToCorePaths($request)) {
            return $next($request);
        }

        if (! $request->attributes->has('request_id')) {
            $incoming = (string) $request->headers->get('X-Request-Id', '');
            $id = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $incoming) === 1
                ? $incoming
                : (string) Str::uuid();

            $request->attributes->set('request_id', $id);
            Log::withContext(['request_id' => $id]);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', (string) $request->attributes->get('request_id'));

        return $response;
    }
}
