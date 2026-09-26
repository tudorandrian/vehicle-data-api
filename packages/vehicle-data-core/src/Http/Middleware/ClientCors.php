<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Http\Problem\Problem;

final class ClientCors
{
    public const EXPOSE = 'ETag, X-Request-Id, Content-Language, Deprecation, Sunset, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-Data-Attribution, X-Data-Licences';

    public const ALLOW_HEADERS = 'Authorization, Accept, Accept-Language, If-None-Match, X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        // Vary: Authorization and Origin are set on every response from this
        // middleware, with or without an Origin header, because the response
        // differs by key and origin (a cache must never serve a same-URL
        // response to a key or origin it wasn't computed for).
        if ($origin === null) {
            $response = $next($request);
            self::vary($response);

            return $response;
        }

        /** @var ResolvedClient $client */
        $client = $request->attributes->get('client');
        if (! in_array($origin, $client->allowedOrigins, true)) {
            return Problem::response(403, 'Forbidden', 'This origin is not allowed for the key.', ['origin' => $origin], '/problems/origin-not-allowed')
                ->withHeaders(['Vary' => 'Authorization, Origin']);
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSE);
        self::vary($response);

        return $response;
    }

    /**
     * A keyed response differs by key (scopes, enrichment, rate-limit headers) and by origin, so
     * any cache that stores one must key it on both.
     */
    private static function vary(Response $response): void
    {
        $response->headers->set('Vary', 'Authorization', false);
        $response->headers->set('Vary', 'Origin', false);
    }

    /**
     * Preflight: no key is present yet, so this must NOT tell an unkeyed caller which
     * origins are registered to some client — that would let anyone probe domain names
     * to learn who consumes this API. Any syntactically valid Origin gets the same 204,
     * whether or not it belongs to a registered, disabled or expired client. The per-key
     * allow-list is still enforced on the real request (see handle() above: only a listed
     * origin gets Access-Control-Allow-Origin back), so a browser still blocks the real
     * response for an origin the caller's key does not allow — nothing is weakened.
     */
    public static function preflight(Request $request): Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        if (! self::validOrigin($origin)) {
            return Problem::response(403, 'Forbidden', 'This origin is not allowed.', ['origin' => $origin], '/problems/origin-not-allowed')
                ->withHeaders(['Vary' => 'Origin']);
        }

        return new HttpResponse('', 204, [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }

    /**
     * The same general shape `vehicle:client create` requires for --origins (scheme://host[:port],
     * no path), but not identical: this regex is case-insensitive and, unlike the CLI validator,
     * does not reject a scheme's default port (e.g. `https://host:443`) or lower-case the origin —
     * it only needs to recognise a syntactically valid Origin header here, not normalise one.
     */
    private static function validOrigin(string $origin): bool
    {
        return $origin !== '' && preg_match('#^https?://[a-z0-9.-]+(?::\d{1,5})?$#i', $origin) === 1;
    }
}
