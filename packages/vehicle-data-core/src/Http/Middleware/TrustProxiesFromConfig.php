<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Reads `core.trusted_proxies` from config at request time (inside handle()),
 * never at bootstrap: the HTTP kernel resolves middleware before configuration
 * is loaded, so calling config() in bootstrap/app.php would 500 on every
 * real request under a real web server.
 */
final class TrustProxiesFromConfig extends TrustProxies
{
    /** @return array<int, string>|null */
    protected function proxies(): ?array
    {
        /** @var array<int, string> $configured */
        $configured = (array) config('core.trusted_proxies', []);

        return $configured !== [] ? $configured : null;
    }

    protected function headers(): int
    {
        return Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;
    }
}
