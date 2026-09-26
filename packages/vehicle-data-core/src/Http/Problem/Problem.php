<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Problem;

use Illuminate\Http\JsonResponse;
use VehicleData\Core\Http\Middleware\SecurityHeaders;

final class Problem
{
    /** @param array<string, mixed> $extra */
    public static function response(int $status, string $title, ?string $detail = null, array $extra = [], string $type = 'about:blank'): JsonResponse
    {
        $request = request();
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail ?? $title,
            'instance' => '/'.ltrim($request->getPathInfo(), '/'),
            'request_id' => $request->attributes->get('request_id'),
        ] + $extra;

        // The hardening headers are set directly here from SecurityHeaders::headers()
        // (the shared source of truth), and X-Request-Id from the request attribute
        // set by RequestId, because for an exception thrown while the router is
        // still matching (404/405) or during controller execution (500), the
        // exception unwinds past every middleware's post-$next() code before this
        // response is even constructed — so the middleware never gets a chance to
        // add them itself.
        $response = new JsonResponse($body, $status, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => (string) $request->attributes->get('request_id'),
            ...SecurityHeaders::headers(),
        ]);

        return $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
