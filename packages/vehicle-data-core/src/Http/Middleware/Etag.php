<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Etag
{
    public function handle(Request $request, Closure $next): Response
    {
        // Deep inside route dispatch - Route::run() followed by
        // Response::prepare(), both invoked from the route-middleware
        // pipeline's terminal step - Symfony strips a HEAD response's body
        // before this (route) middleware regains control on the way back
        // out, leaving nothing to fingerprint. Disguising the request as
        // GET for the inner call keeps the body intact long enough to
        // compute the ETag below. The real method is restored in `finally`
        // so a throw inside $next() never leaves the request stuck on GET
        // for RecordUsage::terminate() or LogRequest; Router::runRoute()
        // then calls Response::prepare() again with the (now restored)
        // HEAD request once this middleware returns, which strips the body
        // for us - see Router.php:799.
        $isHead = $request->getMethod() === 'HEAD';
        if ($isHead) {
            $request->setMethod('GET');
        }
        try {
            $response = $next($request);
        } finally {
            if ($isHead) {
                $request->setMethod('HEAD');
            }
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200 || $response instanceof StreamedResponse) {
            return $response;
        }

        // The response body differs by negotiated language, so Vary must
        // include Accept-Language, merged with the Authorization and Origin
        // that ClientCors already set (`false` here means append, not replace).
        $response->headers->set('Vary', 'Accept-Language', false);

        // Weak, not strong: the fingerprint strips `meta.generated_at`, so two
        // responses with the same tag are semantically equal, not byte-equal -
        // which is exactly what RFC 9110 §8.8 reserves the W/ prefix for.
        $response->headers->set('ETag', 'W/"'.hash('sha256', self::fingerprint($response)).'"');

        // Symfony compares weakly (W/ stripped on both sides), honours `*` and
        // tag lists, and strips the body itself when it answers 304.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The weak ETag must be stable across time, so `meta.generated_at`
     * (which changes on every request) is stripped from the fingerprint before
     * hashing. Content-Language and the response's negotiated representation
     * are folded in so `?lang=ro` and `?lang=en` never collide on the same ETag.
     */
    private static function fingerprint(Response $response): string
    {
        $decoded = json_decode((string) $response->getContent(), true);
        if (is_array($decoded) && isset($decoded['meta']) && is_array($decoded['meta'])) {
            unset($decoded['meta']['generated_at']);
        }
        $body = is_array($decoded)
            ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $response->getContent();

        return $body.'|'.$response->headers->get('Content-Language', '').'|'.$response->headers->get('Content-Type', '');
    }
}
