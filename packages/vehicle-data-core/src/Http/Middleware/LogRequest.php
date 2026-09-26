<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Http\Middleware\Concerns\AppliesToCorePaths;

/**
 * Emits the single structured `api.request` log line for
 * requests that {@see RecordUsage} never sees: the anonymous `core`-only
 * routes (`/v1/health`, `/openapi.yaml`) and `/docs`, none of which write a
 * `vd_api_requests` row. Registered GLOBALLY (bootstrap/app.php) — unlike
 * RecordUsage it must also cover `/docs`, which sits outside every route
 * group — and guarded by {@see AppliesToCorePaths} so it never touches
 * unrelated paths.
 *
 * A route that runs through the `data` middleware group (has
 * AuthenticateClient in its gathered middleware) already gets its
 * `api.request` line — with the client prefix and a DB row — from
 * RecordUsage::terminate(), so this middleware steps aside for those to
 * avoid logging the same request twice.
 */
final class LogRequest
{
    use AppliesToCorePaths;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->appliesToCorePaths($request)) {
            return;
        }

        // Raw (unexpanded) middleware names for the matched route, e.g.
        // ['data', 'scope:catalogue:read', 'etag'] — 'data' is the literal
        // group name our routes attach (routes/api.php), so this check
        // needs no knowledge of the router's group-to-class expansion.
        $middleware = $request->route()?->gatherMiddleware() ?? [];
        if (in_array('data', $middleware, true)) {
            return;
        }

        $client = $request->attributes->get('client');
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true));

        Log::info('api.request', [
            'request_id' => (string) $request->attributes->get('request_id'),
            'client_prefix' => $client instanceof ResolvedClient ? $client->keyPrefix : null,
            // An unmatched route (404) or one that was never named must never leak the raw
            // path — it can be a VIN or another caller-supplied value.
            'route' => (string) ($request->route()?->getName() ?? 'unmatched'),
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - (float) $start) * 1000),
            'bytes' => strlen((string) $response->getContent()),
            'exception_class' => $request->attributes->get('exception_class'),
        ]);
    }
}
