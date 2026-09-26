<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `Deprecation` / `Sunset` / `Link` headers (RFC 8594 / draft-ietf
 * `Deprecation` header) for any request path prefixed by an entry in
 * `core.deprecations`. Registered in the `core` middleware group, so it
 * only ever runs for the API surface.
 */
final class DeprecationHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /** @var array<string, array{deprecation: string, sunset?: string, link?: string}> $deprecations */
        $deprecations = (array) config('core.deprecations', []);

        foreach ($deprecations as $prefix => $h) {
            if (str_starts_with('/'.ltrim($request->path(), '/'), (string) $prefix)) {
                $response->headers->set('Deprecation', (string) $h['deprecation']);
                if (! empty($h['sunset'])) {
                    $response->headers->set('Sunset', (string) $h['sunset']);
                }
                if (! empty($h['link'])) {
                    $response->headers->set('Link', '<'.$h['link'].'>; rel="deprecation"', false);
                }
                break;
            }
        }

        return $response;
    }
}
