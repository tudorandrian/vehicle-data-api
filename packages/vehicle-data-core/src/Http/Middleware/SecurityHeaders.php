<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Http\Middleware\Concerns\AppliesToCorePaths;
use VehicleData\Core\Http\Problem\Problem;

/**
 * Registered as GLOBAL middleware (see bootstrap/app.php) so hardening
 * headers land on unmatched-route 404s / wrong-verb 405s too, not only on
 * responses from matched /v1 routes.
 */
final class SecurityHeaders
{
    use AppliesToCorePaths;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->appliesToCorePaths($request)) {
            return $response;
        }

        $headers = $request->is('docs') || $request->is('docs/*') ? self::docsHeaders() : self::headers();
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The single source of truth for the hardening header set, shared with
     * {@see Problem::response()}, which needs
     * these same headers on error responses that never pass back through
     * this middleware's post-$next() code (see that class for why).
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
    }

    /**
     * The same hardening set for the HTML reference page at /docs, with the one
     * CSP the page needs: scripts only from this origin (the self-hosted Scalar
     * bundle and its initialiser — no inline script, no CDN), inline styles that
     * the bundle injects at runtime, and fetches (the contract, "Try it" calls)
     * back to this origin only.
     *
     * @return array<string, string>
     */
    public static function docsHeaders(): array
    {
        return [
            ...self::headers(),
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ];
    }
}
