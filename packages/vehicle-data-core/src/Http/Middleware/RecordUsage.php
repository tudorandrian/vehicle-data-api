<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function report;

use Symfony\Component\HttpFoundation\Response;
use Throwable;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Models\ApiRequest;

final class RecordUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $client = $request->attributes->get('client');
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $requestId = (string) $request->attributes->get('request_id');
        // An unmatched route or one that was never named must never leak the raw path — it can
        // be a VIN or another caller-supplied value.
        $route = (string) ($request->route()?->getName() ?? 'unmatched');
        $method = $request->getMethod();
        $status = $response->getStatusCode();
        $durationMs = (int) round((microtime(true) - (float) $start) * 1000);
        $bytes = strlen((string) $response->getContent());

        try {
            ApiRequest::query()->create([
                'request_id' => $requestId,
                'client_id' => $client instanceof ResolvedClient ? $client->id : null,
                'route' => $route,
                'method' => $method,
                'status' => $status,
                'duration_ms' => $durationMs,
                'bytes' => $bytes,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Usage recording must never take the response down with it —
            // the caller already has their answer by the time terminate()
            // runs. Report (log) and move on.
            report($e);
        }

        // One structured `api.request` log line per request, keyed
        // by the client's key PREFIX only — never the bearer key itself,
        // and never a raw VIN (the route NAME is logged, not the path, so
        // GET /v1/vin/{vin} never leaks a VIN into the logs).
        Log::info('api.request', [
            'request_id' => $requestId,
            'client_prefix' => $client instanceof ResolvedClient ? $client->keyPrefix : null,
            'route' => $route,
            'method' => $method,
            'status' => $status,
            'duration_ms' => $durationMs,
            'bytes' => $bytes,
            'exception_class' => $request->attributes->get('exception_class'),
        ]);
    }
}
