<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware\Concerns;

use Illuminate\Http\Request;

/**
 * Shared path guard for middleware registered globally (so it still runs
 * ahead of routing and fires on unmatched-route 404s / wrong-verb 405s),
 * but that must only actually act on API-surface paths.
 */
trait AppliesToCorePaths
{
    private function appliesToCorePaths(Request $request): bool
    {
        return $request->is('v1/*') || $request->is('openapi.yaml') || $request->is('docs') || $request->is('docs/*');
    }
}
