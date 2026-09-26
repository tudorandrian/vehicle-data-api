<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Auth\ApiKey;
use VehicleData\Core\Auth\Counters;
use VehicleData\Core\Contracts\ClientResolver;
use VehicleData\Core\Http\Problem\Problem;

final class AuthenticateClient
{
    public function __construct(private readonly ClientResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $maxFailedPerMinute = (int) config('core.auth_fail_per_minute', 30);

        $bearer = (string) $request->bearerToken();
        $client = $bearer === '' ? null : $this->resolver->resolve($bearer);

        if ($client === null) {
            // This runs after resolve(), so it never gates the key lookup itself - it only
            // caps how many 401 responses one IP can produce per minute. Only failures count
            // and only failures are blocked: a valid key from a shared NAT or a misconfigured
            // proxy is never locked out by someone else's garbage tokens. Volume protection
            // against a flood of requests (valid or not) belongs at the edge (web server or
            // proxy), not this middleware. Every failed attempt costs one counter upsert -
            // including the one that trips the 429 below - so the stored count can be one
            // higher than the number of 401s an IP actually received (hit-then-compare).
            [$minute, $minuteEnd] = Counters::minuteWindow();
            $failures = Counters::hit('ip:'.$ip, $minute, $minuteEnd->addDay());
            // Only ever log the prefix of a well-formed key, never a truncated slice of
            // something that merely looks like the start of one - a malformed/foreign secret
            // must never appear in the logs, even partially. The caller's IP is deliberately
            // not logged here: it is still rate-limited by $ip above, and retained only in
            // vd_api_requests (purged per core.request_ip_retention_days).
            $keyPrefix = ApiKey::looksValid($bearer) ? ApiKey::prefix($bearer) : null;
            if ($failures > $maxFailedPerMinute) {
                $retry = max(1, $minuteEnd->getTimestamp() - now('UTC')->getTimestamp() + 1);

                // Logged once per window, on the attempt that first crosses the limit - not on
                // every blocked attempt after that, which would otherwise write one log line per
                // request for the rest of the window on a sustained brute force.
                if ($failures === $maxFailedPerMinute + 1) {
                    Log::warning('api.auth.blocked', ['key_prefix' => $keyPrefix]);
                }

                return Problem::response(429, 'Too Many Requests', 'Too many failed authentication attempts.', [], '/problems/rate-limited')
                    ->withHeaders(['Retry-After' => (string) $retry]);
            }

            Log::warning('api.auth.failed', ['key_prefix' => $keyPrefix]);

            return Problem::response(401, 'Unauthorized', 'A valid bearer API key is required.', [], '/problems/unauthenticated')
                ->withHeaders(['WWW-Authenticate' => 'Bearer realm="vehicle-data-api"']);
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }
}
